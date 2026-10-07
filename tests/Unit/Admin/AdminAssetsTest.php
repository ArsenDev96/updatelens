<?php
/**
 * Tests for reading the admin app's Vite manifest.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use UpdateLens\Admin\AdminAssets;

/**
 * AdminAssets::entry_files().
 */
final class AdminAssetsTest extends TestCase {

	/**
	 * Manifest as written by `npm run build`.
	 *
	 * @param array<string, mixed> $entry Entry overrides.
	 * @return array<string, mixed>
	 */
	private static function manifest( array $entry = array() ) {
		return array(
			'src/admin/main.tsx' => array_merge(
				array(
					'file'    => 'assets/main-DtmkKSKV.js',
					'name'    => 'main',
					'src'     => 'src/admin/main.tsx',
					'isEntry' => true,
					'css'     => array( 'assets/main-B_rq2DR5.css' ),
				),
				$entry
			),
		);
	}

	/**
	 * The built script and its styles, relative to the build directory.
	 */
	public function test_entry_files() {
		$this->assertSame(
			array(
				'script' => 'assets/main-DtmkKSKV.js',
				'styles' => array( 'assets/main-B_rq2DR5.css' ),
			),
			AdminAssets::entry_files( self::manifest() )
		);
	}

	/**
	 * The manifest of this checkout (when built) is readable.
	 */
	public function test_built_manifest() {
		$file = dirname( __DIR__, 3 ) . '/' . AdminAssets::DIST_DIR . AdminAssets::MANIFEST;
		if ( ! is_file( $file ) ) {
			$this->markTestSkipped( 'Admin app not built.' );
		}

		$files = AdminAssets::entry_files( json_decode( (string) file_get_contents( $file ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test file.

		$this->assertNotNull( $files );
		$this->assertFileExists( dirname( $file ) . '/' . $files['script'] );
		foreach ( $files['styles'] as $style ) {
			$this->assertFileExists( dirname( $file ) . '/' . $style );
		}
	}

	/**
	 * An entry without styles is fine.
	 */
	public function test_entry_without_styles() {
		$manifest = self::manifest();
		unset( $manifest['src/admin/main.tsx']['css'] );

		$this->assertSame(
			array(
				'script' => 'assets/main-DtmkKSKV.js',
				'styles' => array(),
			),
			AdminAssets::entry_files( $manifest )
		);
	}

	/**
	 * Anything but a file directly in the build's assets/ directory is refused.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function unusable_manifests() {
		return array(
			'not decoded'        => array( null ),
			'no entry'           => array( array( 'src/other.tsx' => self::manifest()['src/admin/main.tsx'] ) ),
			'entry not an array' => array( array( 'src/admin/main.tsx' => 'assets/main.js' ) ),
			'no file'            => array( self::manifest( array( 'file' => null ) ) ),
			'css as script'      => array( self::manifest( array( 'file' => 'assets/main.css' ) ) ),
			'parent directory'   => array( self::manifest( array( 'file' => 'assets/../../../evil.js' ) ) ),
			'other directory'    => array( self::manifest( array( 'file' => 'assets/sub/main.js' ) ) ),
			'absolute URL'       => array( self::manifest( array( 'file' => 'https://example.com/assets/main.js' ) ) ),
			'protocol-relative'  => array( self::manifest( array( 'file' => '//example.com/main.js' ) ) ),
			'leading slash'      => array( self::manifest( array( 'file' => '/assets/main.js' ) ) ),
			'query string'       => array( self::manifest( array( 'file' => 'assets/main.js?x=1' ) ) ),
			'trailing newline'   => array( self::manifest( array( 'file' => "assets/main.js\n" ) ) ),
			'css not a list'     => array( self::manifest( array( 'css' => 'assets/main.css' ) ) ),
			'script as css'      => array( self::manifest( array( 'css' => array( 'assets/main.js' ) ) ) ),
			'css outside assets' => array( self::manifest( array( 'css' => array( '../style.css' ) ) ) ),
		);
	}

	/**
	 * Unusable manifests load nothing.
	 *
	 * @dataProvider unusable_manifests
	 *
	 * @param mixed $manifest Decoded manifest.
	 */
	public function test_unusable_manifest( $manifest ) {
		$this->assertNull( AdminAssets::entry_files( $manifest ) );
	}
}
