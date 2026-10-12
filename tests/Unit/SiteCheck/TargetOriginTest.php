<?php
/**
 * Tests for site check target origins.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\SiteCheck;

use PHPUnit\Framework\TestCase;
use UpdateLens\SiteCheck\TargetOrigin;

/**
 * Origins compared like a browser: scheme, host and effective port; never
 * the path.
 */
final class TargetOriginTest extends TestCase {

	const ADMIN_AJAX = 'https://example.com/wp-admin/admin-ajax.php';

	/**
	 * Origins of URLs, normalized like the browser's `URL.origin`.
	 *
	 * @dataProvider origins
	 *
	 * @param mixed       $url    URL.
	 * @param string|null $origin Expected origin.
	 */
	public function test_origin( $url, $origin ) {
		$this->assertSame( $origin, TargetOrigin::of( $url ) );
	}

	/**
	 * URLs and their origins.
	 *
	 * @return array
	 */
	public function origins() {
		return array(
			'homepage'                  => array( 'https://example.com/', 'https://example.com' ),
			'no path'                   => array( 'https://example.com', 'https://example.com' ),
			'case-insensitive'          => array( 'HTTPS://Example.COM/Blog/', 'https://example.com' ),
			'default https port'        => array( 'https://example.com:443/', 'https://example.com' ),
			'default http port'         => array( 'http://example.com:80/', 'http://example.com' ),
			'https on port 80'          => array( 'https://example.com:80/', 'https://example.com:80' ),
			'custom port'               => array( 'http://localhost:8080/wp-json/', 'http://localhost:8080' ),
			'query and plain permalink' => array( 'https://example.com/?rest_route=/wp/v2/users/me&_fields=id', 'https://example.com' ),
			'IPv6'                      => array( 'http://[::1]:8080/', 'http://[::1]:8080' ),
			'trailing dot is kept'      => array( 'https://example.com./', 'https://example.com.' ),
			'other scheme'              => array( 'ftp://example.com/', null ),
			'credentials'               => array( 'https://user:pass@example.com/', null ),
			'relative'                  => array( '/wp-json/', null ),
			'empty'                     => array( '', null ),
			'not a string'              => array( null, null ),
		);
	}

	/**
	 * Which checks cannot run from an admin page on ADMIN_AJAX's origin.
	 *
	 * @dataProvider sites
	 *
	 * @param string   $home        Homepage URL.
	 * @param string   $rest        REST URL.
	 * @param string   $admin       admin-ajax URL.
	 * @param string[] $unsupported Expected checks on another origin.
	 */
	public function test_unsupported_checks( $home, $rest, $admin, array $unsupported ) {
		$this->assertSame(
			$unsupported,
			TargetOrigin::unsupported(
				array(
					'home' => $home,
					'rest' => $rest,
				),
				$admin
			)
		);
	}

	/**
	 * Site configurations.
	 *
	 * @return array
	 */
	public function sites() {
		$rest = '/wp-json/wp/v2/users/me?_fields=id';
		return array(
			'same origin'                     => array( 'https://example.com/', 'https://example.com' . $rest, self::ADMIN_AJAX, array() ),
			'WordPress in a subdirectory'     => array( 'https://example.com/', 'https://example.com' . $rest, 'https://example.com/wp/wp-admin/admin-ajax.php', array() ),
			'site in a subdirectory'          => array( 'https://example.com/blog/', 'https://example.com/blog' . $rest, 'https://example.com/blog/wp-admin/admin-ajax.php', array() ),
			'explicit default port'           => array( 'https://example.com:443/', 'https://example.com' . $rest, self::ADMIN_AJAX, array() ),
			'www homepage, bare admin'        => array( 'https://www.example.com/', 'https://www.example.com' . $rest, self::ADMIN_AJAX, array( 'home', 'rest' ) ),
			'bare homepage, www admin'        => array( 'https://example.com/', 'https://example.com' . $rest, 'https://www.example.com/wp-admin/admin-ajax.php', array( 'home', 'rest' ) ),
			'http homepage, https admin'      => array( 'http://example.com/', 'http://example.com' . $rest, self::ADMIN_AJAX, array( 'home', 'rest' ) ),
			'https homepage, http admin'      => array( 'https://example.com/', 'https://example.com' . $rest, 'http://example.com/wp-admin/admin-ajax.php', array( 'home', 'rest' ) ),
			'other port'                      => array( 'http://localhost:3000/', 'http://localhost:3000' . $rest, 'http://localhost:8080/wp-admin/admin-ajax.php', array( 'home', 'rest' ) ),
			'homepage elsewhere, REST here'   => array( 'https://www.example.com/', 'https://example.com' . $rest, self::ADMIN_AJAX, array( 'home' ) ),
			'REST elsewhere, homepage here'   => array( 'https://example.com/', 'https://api.example.com' . $rest, self::ADMIN_AJAX, array( 'rest' ) ),
			'unreadable target'               => array( 'not a url', 'https://example.com' . $rest, self::ADMIN_AJAX, array( 'home' ) ),
			'unreadable admin URL: none runs' => array( 'https://example.com/', 'https://example.com' . $rest, '', array( 'home', 'rest' ) ),
		);
	}
}
