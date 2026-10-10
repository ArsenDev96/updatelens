<?php
/**
 * Tests for the site check session storage format.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use UpdateLens\SiteCheck\CheckSession;
use UpdateLens\Storage\SiteCheckSessionCodec;

/**
 * Versioned JSON with full validation; no marker or secret.
 */
final class SiteCheckSessionCodecTest extends TestCase {

	/**
	 * A valid record.
	 *
	 * @return array
	 */
	private static function record() {
		return array(
			'check'       => 'home',
			'definition'  => 1,
			'purpose'     => 'before',
			'user'        => 7,
			'login'       => str_repeat( 'b', 64 ),
			'target'      => str_repeat( 'a', 64 ),
			'secret_hash' => hash( 'sha256', 'secret' ),
			'created_at'  => 1760090000.25,
			'expires_at'  => 1760090120.25,
			'generation'  => '0123456789abcdef:42',
			'analysis'    => null,
		);
	}

	/**
	 * Exact format and round trip.
	 */
	public function test_encodes_and_decodes() {
		$codec = new SiteCheckSessionCodec();
		$json  = $codec->encode( CheckSession::from_array( self::record() ) );

		$this->assertSame(
			'{"schema":3,"check":"home","definition":1,"purpose":"before","user":7,"login":"' . str_repeat( 'b', 64 ) . '","target":"' . str_repeat( 'a', 64 ) . '","secret_hash":"' . hash( 'sha256', 'secret' ) . '","created_at":1760090000.25,"expires_at":1760090120.25,"generation":"0123456789abcdef:42","analysis":null}',
			$json
		);
		$this->assertSame( self::record(), $codec->decode( $json )->to_array() );
	}

	/**
	 * An AFTER session belongs to one analysis.
	 */
	public function test_after_session_analysis() {
		$record  = array_merge(
			self::record(),
			array(
				'purpose'  => 'after',
				'analysis' => 42,
			)
		);
		$codec   = new SiteCheckSessionCodec();
		$session = $codec->decode( $codec->encode( CheckSession::from_array( $record ) ) );

		$this->assertSame( 42, $session->get_analysis() );
		$this->assertSame( $record, $session->to_array() );
		$this->assertNull( CheckSession::from_array( self::record() )->get_analysis() );
	}

	/**
	 * Invalid records.
	 *
	 * @return array<string, array>
	 */
	public function invalid_records() {
		$record = self::record();
		return array(
			'unknown check'     => array( array_merge( $record, array( 'check' => 'theme' ) ) ),
			'unknown purpose'   => array( array_merge( $record, array( 'purpose' => 'probe' ) ) ),
			'user zero'         => array( array_merge( $record, array( 'user' => 0 ) ) ),
			'raw secret'        => array( array_merge( $record, array( 'secret_hash' => 'secret' ) ) ),
			'login not a hash'  => array( array_merge( $record, array( 'login' => 'token' ) ) ),
			'expires before'    => array( array_merge( $record, array( 'expires_at' => 1760090000.0 ) ) ),
			'lifetime too long' => array( array_merge( $record, array( 'expires_at' => 1760090120.5 ) ) ),
			'definition string' => array( array_merge( $record, array( 'definition' => '1' ) ) ),
			'extra key'         => array( $record + array( 'marker' => str_repeat( 'f', 32 ) ) ),
			'missing key'       => array( array_diff_key( $record, array( 'login' => 1 ) ) ),
			'other order'       => array( array_reverse( $record ) ),
			'bad generation'    => array( array_merge( $record, array( 'generation' => 'abc:1' ) ) ),
			'generation number' => array( array_merge( $record, array( 'generation' => 42 ) ) ),
			'before analysis'   => array( array_merge( $record, array( 'analysis' => 5 ) ) ),
			'after no analysis' => array( array_merge( $record, array( 'purpose' => 'after' ) ) ),
			'analysis zero'     => array(
				array_merge(
					$record,
					array(
						'purpose'  => 'after',
						'analysis' => 0,
					)
				),
			),
			'analysis string'   => array(
				array_merge(
					$record,
					array(
						'purpose'  => 'after',
						'analysis' => '5',
					)
				),
			),
		);
	}

	/**
	 * Records are validated when built and when decoded.
	 *
	 * @dataProvider invalid_records
	 * @param array $record Record.
	 */
	public function test_rejects_invalid_records( array $record ) {
		try {
			CheckSession::from_array( $record );
			$this->fail( 'Invalid record accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'Invalid UpdateLens site check session.', $e->getMessage() );
		}

		$this->expectException( UnexpectedValueException::class );
		$this->expectExceptionMessage( 'Stored site check session is invalid.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test input.
		( new SiteCheckSessionCodec() )->decode( json_encode( array( 'schema' => 3 ) + $record ) );
	}

	/**
	 * Malformed stored values.
	 */
	public function test_rejects_malformed_values() {
		$valid = ( new SiteCheckSessionCodec() )->encode( CheckSession::from_array( self::record() ) );
		foreach ( array( null, '', 'x', '[]', '{"schema":4}', str_replace( '"schema":3', '"schema":"3"', $valid ), str_replace( '"schema":3', '"schema":2', $valid ), str_pad( $valid, 1025 ), '{"schema":3,"check":{"a":1}}' ) as $value ) {
			try {
				( new SiteCheckSessionCodec() )->decode( $value );
				$this->fail( 'Malformed value accepted.' );
			} catch ( UnexpectedValueException $e ) {
				$this->assertSame( 'Stored site check session is invalid.', $e->getMessage() );
			}
		}
	}

	/**
	 * Token helpers.
	 */
	public function test_token_helpers() {
		$marker = str_repeat( 'a1', 16 );
		$secret = str_repeat( 'b2', 32 );

		$this->assertTrue( CheckSession::is_marker( $marker ) );
		$this->assertFalse( CheckSession::is_marker( $secret ) );
		$this->assertTrue( CheckSession::is_secret( $secret ) );
		$this->assertFalse( CheckSession::is_secret( $marker ) );
		$this->assertSame( 'updatelens_sc_' . hash( 'sha256', $marker ), CheckSession::storage_key( $marker ) );
		$this->assertLessThanOrEqual( 172, strlen( CheckSession::storage_key( $marker ) ), 'Transient names are limited to 172 characters.' );

		$session = CheckSession::from_array( array_merge( self::record(), array( 'secret_hash' => hash( 'sha256', $secret ) ) ) );
		$this->assertTrue( $session->matches_secret( $secret ) );
		$this->assertFalse( $session->matches_secret( str_repeat( 'b3', 32 ) ) );
		$this->assertFalse( $session->is_expired( 1760090120.24 ) );
		$this->assertTrue( $session->is_expired( 1760090120.25 ) );
	}
}
