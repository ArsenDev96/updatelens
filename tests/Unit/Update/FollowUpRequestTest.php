<?php
/**
 * Tests for FollowUpRequest.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use UpdateLens\Update\FollowUpRequest;

/**
 * Input rules of the follow-up request.
 */
final class FollowUpRequestTest extends TestCase {

	/**
	 * Valid plugin basenames.
	 *
	 * @return array<string, array{string}>
	 */
	public function provide_valid() {
		return array(
			'directory plugin' => array( 'acme/acme.php' ),
			'single file'      => array( 'hello.php' ),
			'dots and dashes'  => array( 'my-plugin.v2/my_plugin.php' ),
			'space in name'    => array( 'old plugin/old plugin.php' ),
			'uppercase'        => array( 'Acme/Acme.php' ),
			'non-ASCII'        => array( 'plugïn/plugïn.php' ),
			'longest accepted' => array( str_repeat( 'a', 249 ) . '/a.php' ),
		);
	}

	/**
	 * Invalid values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function provide_invalid() {
		return array(
			'empty'             => array( '' ),
			'not a string'      => array( 42 ),
			'array'             => array( array( 'acme/acme.php' ) ),
			'no .php'           => array( 'acme/acme' ),
			'other extension'   => array( 'acme/acme.phtml' ),
			'nested directory'  => array( 'a/b/c.php' ),
			'absolute'          => array( '/acme/acme.php' ),
			'parent directory'  => array( '../acme.php' ),
			'dot directory'     => array( './acme.php' ),
			'dot-dot directory' => array( '../x/acme.php' ),
			'backslash'         => array( 'acme\\acme.php' ),
			'NUL byte'          => array( "acme/acme.php\0" ),
			'newline'           => array( "acme/acme.php\n" ),
			'URL'               => array( 'https://example.test/acme.php' ),
			'too long'          => array( str_repeat( 'a', 250 ) . '/a.php' ),
			'only .php'         => array( '.php' ),
		);
	}

	/**
	 * Valid basenames are accepted.
	 *
	 * @dataProvider provide_valid
	 *
	 * @param string $plugin Plugin basename.
	 */
	public function test_valid_plugin_file( $plugin ) {
		$this->assertTrue( FollowUpRequest::is_plugin_file( $plugin ) );
		$this->assertSame( array( $plugin ), FollowUpRequest::plugins( array( $plugin ) ) );
	}

	/**
	 * Invalid values are rejected, and reject the whole request.
	 *
	 * @dataProvider provide_invalid
	 *
	 * @param mixed $plugin Value.
	 */
	public function test_invalid_plugin_file( $plugin ) {
		$this->assertFalse( FollowUpRequest::is_plugin_file( $plugin ) );
		$this->assertNull( FollowUpRequest::plugins( array( 'acme/acme.php', $plugin ) ) );
	}

	/**
	 * The list: non-empty, bounded, duplicates dropped, order kept.
	 */
	public function test_plugins_list() {
		$this->assertSame( array( 'b/b.php', 'a/a.php' ), FollowUpRequest::plugins( array( 'b/b.php', 'a/a.php', 'b/b.php' ) ) );
		$this->assertSame( array( 'a/a.php' ), FollowUpRequest::plugins( array( 'x' => 'a/a.php' ) ), 'Keys are ignored.' );

		$this->assertNull( FollowUpRequest::plugins( null ), 'Missing.' );
		$this->assertNull( FollowUpRequest::plugins( 'a/a.php' ), 'Not a list.' );
		$this->assertNull( FollowUpRequest::plugins( array() ), 'Empty.' );

		$max = array();
		for ( $i = 0; $i < FollowUpRequest::MAX_PLUGINS; $i++ ) {
			$max[] = "p{$i}/p{$i}.php";
		}
		$this->assertCount( FollowUpRequest::MAX_PLUGINS, FollowUpRequest::plugins( $max ) );
		$max[] = 'one/more.php';
		$this->assertNull( FollowUpRequest::plugins( $max ), 'Too many.' );
	}

	/**
	 * Result codes are fixed, distinct identifiers.
	 */
	public function test_result_codes() {
		$codes = array(
			FollowUpRequest::SETTLED,
			FollowUpRequest::NOT_PENDING,
			FollowUpRequest::NOT_READY,
			FollowUpRequest::NOT_ALLOWED,
			FollowUpRequest::EXPIRED,
			FollowUpRequest::CAPTURE_FAILED,
			FollowUpRequest::ANALYSIS_FAILED,
			FollowUpRequest::CONFLICT,
			FollowUpRequest::STORAGE_FAILED,
		);

		$this->assertSame( $codes, array_unique( $codes ) );
		foreach ( $codes as $code ) {
			$this->assertMatchesRegularExpression( '/\A[a-z_]+\z/', $code );
		}
		$this->assertSame( 'updatelens_settle', FollowUpRequest::ACTION );
		$this->assertSame( 'update_plugins', FollowUpRequest::CAPABILITY );
	}
}
