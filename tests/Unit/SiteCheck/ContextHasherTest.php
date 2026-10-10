<?php
/**
 * Tests for site check target and session fingerprints.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\SiteCheck;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UpdateLens\SiteCheck\CheckContext;
use UpdateLens\SiteCheck\CheckDefinition;
use UpdateLens\SiteCheck\ContextHasher;

/**
 * Keyed fingerprints instead of URLs and session tokens; fixed definitions.
 */
final class ContextHasherTest extends TestCase {

	/**
	 * Canonical target forms.
	 *
	 * @return array<string, array>
	 */
	public function targets() {
		return array(
			'plain'                   => array( 'https://example.com/', 'https://example.com/' ),
			'no path'                 => array( 'https://example.com', 'https://example.com/' ),
			'case of scheme and host' => array( 'HTTPS://Example.COM/Blog/', 'https://example.com/Blog/' ),
			'default https port'      => array( 'https://example.com:443/', 'https://example.com/' ),
			'default http port'       => array( 'http://example.com:80/', 'http://example.com/' ),
			'other port kept'         => array( 'http://127.0.0.1:9500/wp62/', 'http://127.0.0.1:9500/wp62/' ),
			'query kept'              => array( 'https://example.com/?rest_route=/wp/v2/users/me&_fields=id', 'https://example.com/?rest_route=/wp/v2/users/me&_fields=id' ),
			'fragment dropped'        => array( 'https://example.com/#top', 'https://example.com/' ),
			'credentials'             => array( 'https://user:pass@example.com/', null ),
			'user only'               => array( 'https://user@example.com/', null ),
			'other scheme'            => array( 'ftp://example.com/', null ),
			'relative'                => array( '/wp-json/', null ),
			'scheme-relative'         => array( '//example.com/', null ),
			'whitespace'              => array( "https://example.com/\n", null ),
			'empty'                   => array( '', null ),
			'not a string'            => array( array( 'https://example.com/' ), null ),
		);
	}

	/**
	 * Canonicalization.
	 *
	 * @dataProvider targets
	 * @param mixed       $url      URL.
	 * @param string|null $expected Canonical form.
	 */
	public function test_canonical_target( $url, $expected ) {
		$this->assertSame( $expected, ContextHasher::canonical_target( $url ) );
	}

	/**
	 * Equal canonical targets share a fingerprint; scheme, host and path
	 * differences do not; the URL never appears.
	 */
	public function test_target_fingerprints() {
		$hasher = new ContextHasher( 'test-site-secret' );
		$home   = $hasher->target( 'https://example.com/' );

		$this->assertTrue( CheckContext::is_fingerprint( $home ) );
		$this->assertSame( $home, $hasher->target( 'HTTPS://example.com:443' ) );
		$this->assertNotSame( $home, $hasher->target( 'http://example.com/' ) );
		$this->assertNotSame( $home, $hasher->target( 'https://www.example.com/' ) );
		$this->assertNotSame( $home, $hasher->target( 'https://example.com/en/' ) );
		$this->assertNotSame( $home, ( new ContextHasher( 'other-site-secret' ) )->target( 'https://example.com/' ) );
		$this->assertStringNotContainsString( 'example', $home );

		$this->expectException( InvalidArgumentException::class );
		$hasher->target( 'https://user:pass@example.com/' );
	}

	/**
	 * Session fingerprints use their own key: the same bytes fingerprint
	 * differently as a target and as a session.
	 */
	public function test_session_fingerprints() {
		$hasher = new ContextHasher( 'test-site-secret' );
		$token  = 'Zm9vYmFyLXNlc3Npb24tdG9rZW4tc2VjcmV0';

		$session = $hasher->session( $token );
		$this->assertTrue( CheckContext::is_fingerprint( $session ) );
		$this->assertSame( $session, $hasher->session( $token ) );
		$this->assertNotSame( $session, $hasher->session( $token . 'x' ) );
		$this->assertNotSame( $hasher->target( 'https://example.com/' ), $hasher->session( 'https://example.com/' ) );
		$this->assertNotSame( $session, hash( 'sha256', $token ), 'Not an unkeyed hash.' );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Asserts the key is hidden.
		$this->assertSame( "UpdateLens\\SiteCheck\\ContextHasher Object\n(\n    [algorithm] => sha256\n)\n", print_r( $hasher, true ) );

		$this->expectException( InvalidArgumentException::class );
		$hasher->session( '' );
	}

	/**
	 * Context values are validated.
	 */
	public function test_context_validation() {
		$fingerprint = str_repeat( 'a', 64 );
		$cases       = array(
			array( 'https://example.com/', 'anonymous', 1, $fingerprint ),
			array( $fingerprint, 'anonymous', 1, strtoupper( $fingerprint ) ),
			array( $fingerprint, 'cookie', 1, $fingerprint ),
			array( $fingerprint, 'user', 0, $fingerprint ),
			array( $fingerprint, 'user', '1', $fingerprint ),
		);
		foreach ( $cases as $case ) {
			try {
				new CheckContext( $case[0], $case[1], $case[2], $case[3] );
				$this->fail( 'Invalid context accepted.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Invalid UpdateLens site check context.', $e->getMessage() );
			}
		}
	}

	/**
	 * Two fixed definitions with fixed authentication contexts.
	 */
	public function test_definitions() {
		$this->assertSame(
			array(
				array( 'home', 1, 'anonymous' ),
				array( 'rest', 1, 'user' ),
			),
			array_map(
				function ( CheckDefinition $definition ) {
					return array( $definition->get_id(), $definition->get_version(), $definition->get_auth() );
				},
				CheckDefinition::all()
			)
		);
		$this->assertSame( 'rest', CheckDefinition::get( 'rest' )->get_id() );
		$this->assertTrue( CheckDefinition::is_id( 'home' ) );
		$this->assertFalse( CheckDefinition::is_id( 'theme' ) );
		$this->assertFalse( CheckDefinition::is_id( null ) );

		$this->expectException( InvalidArgumentException::class );
		CheckDefinition::get( 'theme' );
	}
}
