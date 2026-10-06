<?php
/**
 * Tests for CronArgsHasher.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Snapshot;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UpdateLens\Snapshot\CronArgsHasher;
use UpdateLens\Snapshot\OptionValueHasher;

/**
 * Keyed cron argument fingerprints.
 */
final class CronArgsHasherTest extends TestCase {

	const SECRET = 'test-secret-one';

	/**
	 * Obviously fake credential used in privacy fixtures.
	 */
	const FAKE_SECRET = 'sk_test_UPDATE_LENS_CRON_SECRET';

	/**
	 * Same arguments and secret give the same fingerprint.
	 */
	public function test_same_args_and_secret_give_same_fingerprint() {
		$args = array( 42, 'https://example.test/hook', array( 'token' => self::FAKE_SECRET ) );

		$this->assertSame(
			( new CronArgsHasher( self::SECRET ) )->fingerprint( $args ),
			( new CronArgsHasher( self::SECRET ) )->fingerprint( $args )
		);
	}

	/**
	 * Changed arguments give a different fingerprint.
	 */
	public function test_changed_args_give_different_fingerprint() {
		$hasher = new CronArgsHasher( self::SECRET );

		$this->assertNotSame( $hasher->fingerprint( array( 42 ) ), $hasher->fingerprint( array( 43 ) ) );
		$this->assertNotSame( $hasher->fingerprint( array() ), $hasher->fingerprint( array( 0 ) ) );
		$this->assertNotSame( $hasher->fingerprint( array( 'a' ) ), $hasher->fingerprint( array( 'a', 'a' ) ) );
	}

	/**
	 * The same arguments under a different secret give a different fingerprint.
	 */
	public function test_different_secret_gives_different_fingerprint() {
		$this->assertNotSame(
			( new CronArgsHasher( self::SECRET ) )->fingerprint( array( 42 ) ),
			( new CronArgsHasher( 'test-secret-two' ) )->fingerprint( array( 42 ) )
		);
	}

	/**
	 * Order, keys and types matter exactly as for WordPress's own event key (md5 of serialize).
	 *
	 * Callbacks receive arguments positionally, so WordPress treats a different
	 * order as a different event.
	 *
	 * @dataProvider provide_argument_pairs
	 *
	 * @param array $a First arguments.
	 * @param array $b Second arguments.
	 */
	public function test_identity_matches_wordpress_event_key( array $a, array $b ) {
		$hasher = new CronArgsHasher( self::SECRET );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Core's event key.
		$same_for_wordpress = md5( serialize( $a ) ) === md5( serialize( $b ) );

		$this->assertSame( $same_for_wordpress, $hasher->fingerprint( $a ) === $hasher->fingerprint( $b ) );
	}

	/**
	 * Argument pairs.
	 *
	 * @return array<string, array{array, array}>
	 */
	public function provide_argument_pairs() {
		return array(
			'identical'             => array( array( 1, 'a' ), array( 1, 'a' ) ),
			'positional order'      => array( array( 'a', 'b' ), array( 'b', 'a' ) ),
			'associative order'     => array(
				array(
					'x' => 1,
					'y' => 2,
				),
				array(
					'y' => 2,
					'x' => 1,
				),
			),
			'int vs numeric string' => array( array( 1 ), array( '1' ) ),
			'list vs keyed'         => array( array( 'a' ), array( 'k' => 'a' ) ),
			'nested'                => array( array( array( 'id' => 7 ) ), array( array( 'id' => 7 ) ) ),
			'empty vs null arg'     => array( array(), array( null ) ),
			'utf-8'                 => array( array( "caf\u{00E9}" ), array( "cafe\u{0301}" ) ),
		);
	}

	/**
	 * The order-sensitive cases really differ (the data provider is not vacuous).
	 */
	public function test_argument_order_gives_different_fingerprint() {
		$hasher = new CronArgsHasher( self::SECRET );

		$this->assertNotSame( $hasher->fingerprint( array( 'a', 'b' ) ), $hasher->fingerprint( array( 'b', 'a' ) ) );
		$this->assertNotSame(
			$hasher->fingerprint(
				array(
					'x' => 1,
					'y' => 2,
				)
			),
			$hasher->fingerprint(
				array(
					'y' => 2,
					'x' => 1,
				)
			)
		);
	}

	/**
	 * Fingerprints are keyed: not WordPress's md5 key, not a plain hash, not an option fingerprint.
	 */
	public function test_fingerprint_is_keyed_and_domain_separated() {
		$args       = array( self::FAKE_SECRET );
		$serialized = serialize( $args ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Canonical form under test.

		$fingerprint = ( new CronArgsHasher( self::SECRET ) )->fingerprint( $args );

		$this->assertNotSame( md5( $serialized ), $fingerprint );
		$this->assertNotSame( hash( 'sha256', $serialized ), $fingerprint );
		$this->assertNotSame( hash_hmac( 'sha256', $serialized, self::SECRET ), $fingerprint );
		$this->assertNotSame(
			( new OptionValueHasher( self::SECRET ) )->fingerprint( $serialized ),
			$fingerprint,
			'Identical bytes must fingerprint differently in the option-value and cron-args domains.'
		);
	}

	/**
	 * Known answer: HMAC-SHA256 of serialize( args ) under a key derived from the cron-args label.
	 */
	public function test_fingerprint_known_answer() {
		$args = array( 'acme', 7 );
		$key  = hash_hmac( 'sha256', 'updatelens:cron-args:v1', self::SECRET, true );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Canonical form under test.
		$this->assertSame( hash_hmac( 'sha256', serialize( $args ), $key ), ( new CronArgsHasher( self::SECRET ) )->fingerprint( $args ) );
	}

	/**
	 * Fingerprints are 64-character lowercase hex and do not contain the arguments.
	 */
	public function test_fingerprint_format_and_privacy() {
		$fingerprint = ( new CronArgsHasher( self::SECRET ) )->fingerprint( array( self::FAKE_SECRET ) );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $fingerprint );
		$this->assertStringNotContainsString( self::FAKE_SECRET, $fingerprint );
	}

	/**
	 * Debug output does not reveal the secret or derived key.
	 */
	public function test_debug_output_hides_key() {
		$hasher = new CronArgsHasher( self::SECRET );

		$this->assertSame( array( 'algorithm' => 'sha256' ), $hasher->__debugInfo() );
		$this->assertStringNotContainsString( self::SECRET, print_r( $hasher, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Asserting on debug output.
	}

	/**
	 * The context is deterministic, versioned, and distinct from the option-value context.
	 */
	public function test_context() {
		$context = ( new CronArgsHasher( self::SECRET ) )->get_context();

		$this->assertSame( $context, ( new CronArgsHasher( self::SECRET ) )->get_context() );
		$this->assertMatchesRegularExpression( '/^cron-args-hmac-sha256-v1:[0-9a-f]{64}$/', $context );
		$this->assertStringStartsWith( CronArgsHasher::SCHEME . ':', $context );
		$this->assertNotSame( $context, ( new CronArgsHasher( 'test-secret-two' ) )->get_context() );
		$this->assertNotSame(
			substr( ( new OptionValueHasher( self::SECRET ) )->get_context(), -64 ),
			substr( $context, -64 )
		);
		$this->assertStringNotContainsString( self::SECRET, $context );
	}

	/**
	 * An empty secret is rejected.
	 */
	public function test_empty_secret_is_rejected() {
		$this->expectException( InvalidArgumentException::class );

		new CronArgsHasher( '' );
	}
}
