<?php
/**
 * Tests for OptionValueHasher.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Snapshot;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UpdateLens\Snapshot\OptionValueHasher;

/**
 * Keyed option value fingerprints.
 */
final class OptionValueHasherTest extends TestCase {

	const SECRET = 'test-secret-one';

	/**
	 * Same value and secret give the same fingerprint.
	 */
	public function test_same_value_and_secret_give_same_fingerprint() {
		$a = new OptionValueHasher( self::SECRET );
		$b = new OptionValueHasher( self::SECRET );

		$this->assertSame( $a->fingerprint( 'value' ), $b->fingerprint( 'value' ) );
	}

	/**
	 * A changed value gives a different fingerprint.
	 */
	public function test_changed_value_gives_different_fingerprint() {
		$hasher = new OptionValueHasher( self::SECRET );

		$this->assertNotSame( $hasher->fingerprint( 'value' ), $hasher->fingerprint( 'value ' ) );
		$this->assertNotSame( $hasher->fingerprint( 'a:1:{i:0;s:1:"a";}' ), $hasher->fingerprint( 'a:1:{i:0;s:1:"b";}' ) );
	}

	/**
	 * The same value under a different secret gives a different fingerprint.
	 */
	public function test_different_secret_gives_different_fingerprint() {
		$a = new OptionValueHasher( self::SECRET );
		$b = new OptionValueHasher( 'test-secret-two' );

		$this->assertNotSame( $a->fingerprint( 'value' ), $b->fingerprint( 'value' ) );
	}

	/**
	 * Fingerprints are keyed: not a plain hash of the value.
	 */
	public function test_fingerprint_is_not_an_unkeyed_hash() {
		$fingerprint = ( new OptionValueHasher( self::SECRET ) )->fingerprint( 'value' );

		$this->assertNotSame( hash( 'sha256', 'value' ), $fingerprint );
		$this->assertNotSame( hash_hmac( 'sha256', 'value', self::SECRET ), $fingerprint );
	}

	/**
	 * Fingerprints are 64-character lowercase hex.
	 */
	public function test_fingerprint_format() {
		$hasher = new OptionValueHasher( self::SECRET );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hasher->fingerprint( 'value' ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hasher->fingerprint( '' ) );
	}

	/**
	 * Fingerprint does not contain the raw value.
	 */
	public function test_fingerprint_does_not_contain_value() {
		$secret_value = 'sk_test_SUPER_SECRET_EXAMPLE';

		$fingerprint = ( new OptionValueHasher( self::SECRET ) )->fingerprint( $secret_value );

		$this->assertStringNotContainsString( $secret_value, $fingerprint );
	}

	/**
	 * Debug output does not reveal the secret or derived key.
	 */
	public function test_debug_output_hides_key() {
		$hasher = new OptionValueHasher( self::SECRET );

		$this->assertSame( array( 'algorithm' => 'sha256' ), $hasher->__debugInfo() );
		$this->assertStringNotContainsString( self::SECRET, print_r( $hasher, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Asserting on debug output.
	}

	/**
	 * The context is deterministic for the same secret and versioned.
	 */
	public function test_context_is_deterministic_and_versioned() {
		$context = ( new OptionValueHasher( self::SECRET ) )->get_context();

		$this->assertSame( $context, ( new OptionValueHasher( self::SECRET ) )->get_context() );
		$this->assertMatchesRegularExpression( '/^hmac-sha256-v1:[0-9a-f]{64}$/', $context );
		$this->assertStringStartsWith( OptionValueHasher::SCHEME . ':', $context );
	}

	/**
	 * A different secret (rotated salts) gives a different context.
	 */
	public function test_context_changes_with_secret() {
		$this->assertNotSame(
			( new OptionValueHasher( self::SECRET ) )->get_context(),
			( new OptionValueHasher( 'test-secret-two' ) )->get_context()
		);
	}

	/**
	 * The context reveals neither the secret nor a plain hash of it, and is not a value fingerprint.
	 */
	public function test_context_does_not_expose_key() {
		$hasher  = new OptionValueHasher( self::SECRET );
		$context = $hasher->get_context();

		$this->assertStringNotContainsString( self::SECRET, $context );
		$this->assertStringNotContainsString( hash( 'sha256', self::SECRET ), $context );
		$this->assertStringNotContainsString( bin2hex( hash_hmac( 'sha256', OptionValueHasher::KEY_CONTEXT, self::SECRET, true ) ), $context, 'Derived key must not appear.' );
		$this->assertStringNotContainsString( $hasher->fingerprint( '' ), $context );
	}

	/**
	 * An empty secret is rejected.
	 */
	public function test_empty_secret_is_rejected() {
		$this->expectException( InvalidArgumentException::class );

		new OptionValueHasher( '' );
	}
}
