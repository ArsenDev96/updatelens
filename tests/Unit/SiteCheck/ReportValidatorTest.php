<?php
/**
 * Tests for browser site check report validation.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\SiteCheck;

use PHPUnit\Framework\TestCase;
use UpdateLens\SiteCheck\CheckDefinition;
use UpdateLens\SiteCheck\InvalidCheckReportException;
use UpdateLens\SiteCheck\ReportValidator;
use UpdateLens\Tests\Support\SiteCheckFixture as F;

/**
 * Reports are bounded, strictly shaped and never echoed.
 */
final class ReportValidatorTest extends TestCase {

	const SECRET = 'sk_live_fake_secret_4f2a9c';

	/**
	 * JSON of a report.
	 *
	 * @param string  $check    Check identifier.
	 * @param array[] $attempts Attempt facts.
	 * @return string
	 */
	private static function report( $check, array $attempts ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test helper.
		return json_encode(
			array(
				'check'    => $check,
				'attempts' => $attempts,
			)
		);
	}

	/**
	 * Valid homepage and REST reports are classified.
	 */
	public function test_accepts_valid_reports() {
		$home = ReportValidator::parse( self::report( 'home', array( F::home() ) ), CheckDefinition::home(), F::USER );
		$this->assertCount( 1, $home );
		$this->assertSame( 'pass', $home[0]->get_verdict() );

		$rest = ReportValidator::parse(
			self::report( 'rest', array( F::no_response( 'timeout' ), F::rest() ) ),
			CheckDefinition::rest(),
			F::USER
		);
		$this->assertSame( array( 'fail', 'pass' ), array( $rest[0]->get_verdict(), $rest[1]->get_verdict() ) );

		// Key order inside objects does not matter.
		$reordered = '{"attempts":[{"duration_ms":5,"kind":"network_error"}],"check":"home"}';
		$this->assertSame( 'network_error', ReportValidator::parse( $reordered, CheckDefinition::home(), F::USER )[0]->get_outcome() );
	}

	/**
	 * Invalid reports with their fixed reason codes.
	 *
	 * @return array<string, array>
	 */
	public function invalid_reports() {
		$secret = self::SECRET;
		$home   = F::home();
		$rest   = F::rest();

		return array(
			'not a string'                         => array( array( 'check' => 'home' ), 'home', 'invalid_report' ),
			'empty'                                => array( '', 'home', 'invalid_report' ),
			'too large'                            => array( str_pad( self::report( 'home', array( $home ) ), ReportValidator::MAX_BYTES + 1 ), 'home', 'too_large' ),
			'not JSON'                             => array( "{'check':'home'}", 'home', 'invalid_report' ),
			'scalar JSON'                          => array( '"home"', 'home', 'invalid_report' ),
			'too deep'                             => array( '{"check":"home","attempts":[{"kind":{"a":{"b":1}}}]}', 'home', 'invalid_report' ),
			'missing attempts'                     => array( '{"check":"home"}', 'home', 'invalid_report' ),
			'unknown top-level key'                => array( '{"check":"home","attempts":[],"secret":"' . $secret . '"}', 'home', 'invalid_report' ),
			'attempts as object'                   => array( '{"check":"home","attempts":{"a":' . self::report( 'home', array() ) . '}}', 'home', 'invalid_report' ),
			'unknown check'                        => array( self::report( $secret, array( $home ) ), 'home', 'invalid_report' ),
			'other check than the session'         => array( self::report( 'rest', array( $rest ) ), 'home', 'check_mismatch' ),
			'no attempts'                          => array( self::report( 'home', array() ), 'home', 'invalid_attempt' ),
			'three attempts'                       => array( self::report( 'home', array( F::no_response( 'timeout' ), F::no_response( 'timeout' ), F::no_response( 'timeout' ) ) ), 'home', 'invalid_attempt' ),
			'retry after a pass'                   => array( self::report( 'home', array( $home, $home ) ), 'home', 'invalid_attempt' ),
			'unknown attempt key'                  => array( self::report( 'home', array( $home + array( 'body_text' => $secret ) ) ), 'home', 'invalid_attempt' ),
			'missing attempt key'                  => array( self::report( 'home', array( array_diff_key( $home, array( 'challenge' => 1 ) ) ) ), 'home', 'invalid_attempt' ),
			'REST keys on the homepage'            => array( self::report( 'home', array( $rest ) ), 'home', 'invalid_attempt' ),
			'homepage keys on REST'                => array( self::report( 'rest', array( $home ) ), 'rest', 'invalid_attempt' ),
			'unknown kind'                         => array(
				self::report(
					'home',
					array(
						array(
							'kind'        => 'aborted',
							'duration_ms' => 1,
						),
					)
				),
				'home',
				'invalid_attempt',
			),
			'timeout with a status'                => array(
				self::report(
					'home',
					array(
						array(
							'kind'        => 'timeout',
							'duration_ms' => 1,
							'status'      => 200,
						),
					)
				),
				'home',
				'invalid_attempt',
			),
			'status as string'                     => array( self::report( 'home', array( F::home( array( 'status' => '200' ) ) ) ), 'home', 'invalid_attempt' ),
			'status below 200'                     => array( self::report( 'home', array( F::home( array( 'status' => 199 ) ) ) ), 'home', 'invalid_attempt' ),
			'status above 599'                     => array( self::report( 'home', array( F::home( array( 'status' => 600 ) ) ) ), 'home', 'invalid_attempt' ),
			'status as float'                      => array( '{"check":"home","attempts":[{"kind":"response","status":200.0,"duration_ms":1,"header":"current","end_marker":"current","retry_after":false,"challenge":false}]}', 'home', 'invalid_attempt' ),
			'negative duration'                    => array( self::report( 'home', array( F::home( array( 'duration_ms' => -1 ) ) ) ), 'home', 'invalid_attempt' ),
			'duration too long'                    => array( self::report( 'home', array( F::home( array( 'duration_ms' => 60001 ) ) ) ), 'home', 'invalid_attempt' ),
			'marker value sent instead of a state' => array( self::report( 'home', array( F::home( array( 'header' => str_repeat( 'f', 32 ) ) ) ) ), 'home', 'invalid_attempt' ),
			'boolean as integer'                   => array( self::report( 'home', array( F::home( array( 'challenge' => 0 ) ) ) ), 'home', 'invalid_attempt' ),
			'user ID as string'                    => array( self::report( 'rest', array( F::rest( array( 'user_id' => '7' ) ) ) ), 'rest', 'invalid_attempt' ),
			'user ID zero'                         => array( self::report( 'rest', array( F::rest( array( 'user_id' => 0 ) ) ) ), 'rest', 'invalid_attempt' ),
			'user ID beyond integers'              => array( '{"check":"rest","attempts":[{"kind":"response","status":200,"duration_ms":1,"header":"current","retry_after":false,"challenge":false,"body":"json_object","user_id":99999999999999999999,"error_code":null}]}', 'rest', 'invalid_attempt' ),
			'user ID with invalid JSON body'       => array( self::report( 'rest', array( F::rest( array( 'body' => 'invalid_json' ) ) ) ), 'rest', 'invalid_attempt' ),
			'error code too long'                  => array(
				self::report(
					'rest',
					array(
						F::rest(
							array(
								'user_id'    => null,
								'error_code' => str_repeat( 'x', 129 ),
							)
						),
					)
				),
				'rest',
				'invalid_attempt',
			),
			'empty error code'                     => array(
				self::report(
					'rest',
					array(
						F::rest(
							array(
								'user_id'    => null,
								'error_code' => '',
							)
						),
					)
				),
				'rest',
				'invalid_attempt',
			),
			'unknown body'                         => array( self::report( 'rest', array( F::rest( array( 'body' => 'html' ) ) ) ), 'rest', 'invalid_attempt' ),
		);
	}

	/**
	 * Malformed, oversized or inconsistent reports are rejected with a fixed
	 * reason and a message that never contains submitted data.
	 *
	 * @dataProvider invalid_reports
	 * @param mixed  $json   Report.
	 * @param string $check  Session check.
	 * @param string $reason Expected reason.
	 */
	public function test_rejects_invalid_reports( $json, $check, $reason ) {
		try {
			ReportValidator::parse( $json, CheckDefinition::get( $check ), F::USER );
			$this->fail( 'Invalid report accepted.' );
		} catch ( InvalidCheckReportException $e ) {
			$this->assertSame( $reason, $e->get_reason() );
			$this->assertSame( 'UpdateLens could not accept the site check report (' . $reason . ').', $e->getMessage() );
			$this->assertStringNotContainsString( self::SECRET, $e->getMessage() );
		}
	}

	/**
	 * A secret-looking error code is accepted but kept only as `other`.
	 */
	public function test_unknown_error_codes_are_not_kept() {
		$attempts = ReportValidator::parse(
			self::report(
				'rest',
				array(
					F::rest(
						array(
							'status'     => 403,
							'user_id'    => null,
							'error_code' => self::SECRET,
						)
					),
				)
			),
			CheckDefinition::rest(),
			F::USER
		);

		$this->assertSame( 'other', $attempts[0]->get_error_code() );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Asserts no serialized form holds the secret.
		$this->assertStringNotContainsString( self::SECRET, serialize( $attempts ) . print_r( $attempts, true ) );
	}
}
