<?php
/**
 * Tests for the site check result storage format.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use UpdateLens\SiteCheck\CheckComparator;
use UpdateLens\SiteCheck\ContextHasher;
use UpdateLens\SiteCheck\CheckResult;
use UpdateLens\SiteCheck\CheckContext;
use UpdateLens\SiteCheck\ReportValidator;
use UpdateLens\SiteCheck\CheckDefinition;
use UpdateLens\Storage\SiteCheckResultCodec;
use UpdateLens\Tests\Support\SiteCheckFixture as F;

/**
 * Compact versioned JSON with full validation on decode; facts only.
 */
final class SiteCheckResultCodecTest extends TestCase {

	const SECRET = 'sk_live_fake_secret_4f2a9c';

	/**
	 * Exact stored format and a lossless round trip.
	 */
	public function test_encodes_and_decodes() {
		$codec  = new SiteCheckResultCodec();
		$result = F::after(
			'home',
			array(
				F::no_response( 'timeout' ),
				F::home(
					array(
						'status'     => 500,
						'header'     => 'absent',
						'end_marker' => 'absent',
					)
				),
			)
		);
		$json   = $codec->encode( $result );

		$this->assertSame(
			'{"schema":1,"check":"home","definition":1,"target":"' . str_repeat( 'a', 64 ) . '","auth":"anonymous","user":7,"session":"' . str_repeat( 'b', 64 ) . '","started_at":1760090002.75,"completed_at":1760090004.0,"attempts":[{"outcome":"timeout","status":null,"header":"not_read","end_marker":"not_read","duration_ms":10000},{"outcome":"server_error","status":500,"header":"absent","end_marker":"absent","duration_ms":912}]}',
			$json
		);
		$decoded = $codec->decode( $json );
		$this->assertSame( $json, $codec->encode( $decoded ) );
		$this->assertSame( $result->to_report_array(), $decoded->to_report_array() );
		$this->assertSame( array( 'fail', 'server_error' ), array( $decoded->get_verdict(), $decoded->get_reason() ) );
	}

	/**
	 * REST results round-trip with their body facts; the user ID seen in
	 * the response is not stored, only whether it matched.
	 */
	public function test_rest_round_trip() {
		$codec  = new SiteCheckResultCodec();
		$result = F::before(
			'rest',
			array(
				F::rest(
					array(
						'status'     => 403,
						'user_id'    => null,
						'error_code' => 'rest_cookie_invalid_nonce',
					)
				),
				F::rest( array( 'user_id' => 4242 ) ),
			)
		);
		$json   = $codec->encode( $result );

		$this->assertStringContainsString( '"attempts":[{"outcome":"forbidden","status":403,"header":"current","body":"wp_error","error_code":"rest_cookie_invalid_nonce","duration_ms":640},{"outcome":"success","status":200,"header":"current","body":"other_user","error_code":null,"duration_ms":640}]', $json );
		$this->assertStringNotContainsString( '4242', $json );
		$this->assertSame( $json, $codec->encode( $codec->decode( $json ) ) );
	}

	/**
	 * Decoded results compare exactly like the originals.
	 */
	public function test_decoded_results_compare_alike() {
		$codec  = new SiteCheckResultCodec();
		$before = F::before( 'home', array( F::home() ) );
		$after  = F::after( 'home', array( F::no_response( 'timeout' ), F::no_response( 'timeout' ) ) );

		$this->assertSame(
			CheckComparator::compare( CheckDefinition::home(), $before, $after, F::window() )->to_array(),
			CheckComparator::compare( CheckDefinition::home(), $codec->decode( $codec->encode( $before ) ), $codec->decode( $codec->encode( $after ) ), F::window() )->to_array()
		);
	}

	/**
	 * Invalid stored data is rejected with fixed messages.
	 *
	 * @return array<string, array>
	 */
	public function invalid_values() {
		$valid   = ( new SiteCheckResultCodec() )->encode( F::before( 'home', array( F::home() ) ) );
		$data    = json_decode( $valid, true );
		$with    = function ( array $changes ) use ( $data ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test helper.
			return json_encode( array_merge( $data, $changes ) );
		};
		$attempt = $data['attempts'][0];

		return array(
			'not a string'           => array( array( 'schema' => 1 ) ),
			'empty'                  => array( '' ),
			'too large'              => array( str_pad( $valid, SiteCheckResultCodec::MAX_BYTES + 1 ) ),
			'not JSON'               => array( '{schema:1}' ),
			'too deep'               => array( '{"schema":1,"attempts":[{"a":{"b":{"c":1}}}]}' ),
			'no schema'              => array( '{"check":"home"}' ),
			'extra key'              => array( $with( array( 'url' => 'https://example.com/' ) ) ),
			'missing key'            => array( json_encode( array_diff_key( $data, array( 'session' => 1 ) ) ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			'key order'              => array( json_encode( array_reverse( $data ) ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			'unknown check'          => array( $with( array( 'check' => 'theme' ) ) ),
			'definition zero'        => array( $with( array( 'definition' => 0 ) ) ),
			'raw URL as target'      => array( $with( array( 'target' => 'https://example.com/' ) ) ),
			'token as session'       => array( $with( array( 'session' => self::SECRET ) ) ),
			'user auth on home'      => array( $with( array( 'auth' => 'user' ) ) ),
			'user as string'         => array( $with( array( 'user' => '7' ) ) ),
			'completed before start' => array( $with( array( 'completed_at' => 1 ) ) ),
			'timestamp as string'    => array( $with( array( 'started_at' => '1760090000' ) ) ),
			'no attempts'            => array( $with( array( 'attempts' => array() ) ) ),
			'attempts as object'     => array( $with( array( 'attempts' => array( 'a' => $attempt ) ) ) ),
			'stored verdict'         => array( $with( array( 'attempts' => array( $attempt + array( 'verdict' => 'pass' ) ) ) ) ),
			'stored body text'       => array( $with( array( 'attempts' => array( $attempt + array( 'body_text' => self::SECRET ) ) ) ) ),
			'inconsistent attempt'   => array( $with( array( 'attempts' => array( array_merge( $attempt, array( 'status' => 500 ) ) ) ) ) ),
			'retry after a pass'     => array( $with( array( 'attempts' => array( $attempt, $attempt ) ) ) ),
		);
	}

	/**
	 * Decoding validates everything and never echoes stored data.
	 *
	 * @dataProvider invalid_values
	 * @param mixed $json Stored value.
	 */
	public function test_rejects_invalid_values( $json ) {
		try {
			( new SiteCheckResultCodec() )->decode( $json );
			$this->fail( 'Invalid value accepted.' );
		} catch ( UnexpectedValueException $e ) {
			$this->assertSame( 'Stored site check result is invalid.', $e->getMessage() );
		}
	}

	/**
	 * Other schemas are reported as unsupported.
	 */
	public function test_rejects_other_schemas() {
		$this->expectException( UnexpectedValueException::class );
		$this->expectExceptionMessage( 'Stored site check result has an unsupported schema.' );
		( new SiteCheckResultCodec() )->decode( '{"schema":2}' );
	}

	/**
	 * End to end: secrets in the target URL, the session token and the
	 * report never reach a result, its stored form, a comparison or an error.
	 */
	public function test_no_secret_leaks() {
		$hasher  = new ContextHasher( 'test-site-secret' );
		$context = new CheckContext(
			$hasher->target( 'https://example.com/?key=' . self::SECRET ),
			'user',
			F::USER,
			$hasher->session( self::SECRET )
		);
		$report  = '{"check":"rest","attempts":[{"kind":"response","status":403,"duration_ms":40,"header":"current","retry_after":false,"challenge":false,"body":"json_object","user_id":null,"error_code":"' . self::SECRET . '"},{"kind":"response","status":200,"duration_ms":50,"header":"current","retry_after":false,"challenge":false,"body":"json_object","user_id":' . F::USER . ',"error_code":null}]}';

		$before = new CheckResult( 'rest', 1, $context, F::UPDATE_START - 9, F::UPDATE_START - 2, ReportValidator::parse( $report, CheckDefinition::rest(), F::USER ) );
		$after  = new CheckResult( 'rest', 1, $context, F::UPDATE_END + 1, F::UPDATE_END + 2, ReportValidator::parse( $report, CheckDefinition::rest(), F::USER ) );
		$codec  = new SiteCheckResultCodec();
		$stored = $codec->encode( $before );

		$forms = array(
			$stored,
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Asserts the output form.
			json_encode( CheckComparator::compare_all( array( 'rest' => $codec->decode( $stored ) ), array( 'rest' => $after ), F::window() )[1]->to_array() ),
			serialize( array( $before, $after ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			print_r( array( $before, $after, $hasher ), true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		);
		foreach ( $forms as $form ) {
			$this->assertStringNotContainsString( self::SECRET, $form );
			$this->assertStringNotContainsString( 'example.com', $form );
		}

		try {
			$codec->decode( str_replace( '"user":7', '"user":"' . self::SECRET . '"', $stored ) );
			$this->fail( 'Invalid value accepted.' );
		} catch ( UnexpectedValueException $e ) {
			$this->assertStringNotContainsString( self::SECRET, $e->getMessage() );
		}
	}
}
