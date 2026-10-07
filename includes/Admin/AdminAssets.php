<?php
/**
 * Loads the built UpdateLens admin app.
 *
 * Written for UpdateLens; not derived from a third-party asset loader.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Admin;

use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the production build of the admin app (assets/admin/dist/) as
 * listed in its Vite manifest.
 *
 * Production builds only: there is no development-server mode, no other origin
 * and no filter that could point the screen elsewhere. The script is an ES
 * module that uses the WordPress-provided `wp.apiFetch` and `wp.i18n`.
 */
final class AdminAssets {

	/**
	 * Script handle of the admin app. Style handles are `<handle>-<index>`.
	 */
	const HANDLE = 'updatelens-admin';

	/**
	 * Build directory, relative to the plugin root.
	 */
	const DIST_DIR = 'assets/admin/dist/';

	/**
	 * Vite manifest, relative to the build directory.
	 */
	const MANIFEST = 'manifest.json';

	/**
	 * Manifest key of the admin app: its source entry point.
	 */
	const ENTRY = 'src/admin/main.tsx';

	/**
	 * WordPress-provided script packages the build maps its imports to
	 * (WP_GLOBALS in vite.config.ts).
	 */
	const DEPENDENCIES = array( 'wp-api-fetch', 'wp-i18n' );

	/**
	 * Built files that may be enqueued: a file directly in the build's
	 * `assets/` directory (no other directory, no `..`, no URL).
	 */
	const FILE_PATTERN = '#\Aassets/[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.(js|css)\z#';

	/**
	 * Enqueue the admin app with its styles and script translations.
	 *
	 * @return bool False if the build is missing or its manifest is unusable.
	 */
	public static function enqueue() {
		$manifest = UPDATELENS_DIR . self::DIST_DIR . self::MANIFEST;
		if ( ! is_file( $manifest ) || ! is_readable( $manifest ) ) {
			return false;
		}

		$files = self::entry_files( wp_json_file_decode( $manifest, array( 'associative' => true ) ) );
		if ( null === $files ) {
			return false;
		}

		$url = UPDATELENS_URL . self::DIST_DIR;
		wp_enqueue_script( self::HANDLE, $url . $files['script'], self::DEPENDENCIES, UPDATELENS_VERSION, true );
		foreach ( $files['styles'] as $index => $style ) {
			wp_enqueue_style( self::HANDLE . '-' . $index, $url . $style, array(), UPDATELENS_VERSION );
		}
		add_filter( 'script_loader_tag', array( self::class, 'add_module_type' ), 10, 2 );
		wp_set_script_translations( self::HANDLE, 'updatelens' );

		return true;
	}

	/**
	 * Files of the admin entry in a decoded Vite manifest.
	 *
	 * @param mixed $manifest Decoded manifest.json (associative arrays).
	 * @return array{script: string, styles: string[]}|null Paths relative to the build directory, or null if unusable.
	 */
	public static function entry_files( $manifest ) {
		if ( ! is_array( $manifest ) || ! isset( $manifest[ self::ENTRY ] ) || ! is_array( $manifest[ self::ENTRY ] ) ) {
			return null;
		}

		$entry = $manifest[ self::ENTRY ];
		if ( ! isset( $entry['file'] ) || 'js' !== self::file_type( $entry['file'] ) ) {
			return null;
		}

		$styles = array();
		if ( isset( $entry['css'] ) ) {
			if ( ! is_array( $entry['css'] ) ) {
				return null;
			}
			foreach ( $entry['css'] as $style ) {
				if ( 'css' !== self::file_type( $style ) ) {
					return null;
				}
				$styles[] = $style;
			}
		}

		return array(
			'script' => $entry['file'],
			'styles' => $styles,
		);
	}

	/**
	 * Load the admin app as an ES module.
	 *
	 * Changes only the app's own `<script id="updatelens-admin-js">` tag, not
	 * the inline translations script WordPress prints before it.
	 *
	 * @param string $tag    Script HTML.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public static function add_module_type( $tag, $handle ) {
		if ( self::HANDLE !== $handle || ! is_string( $tag ) ) {
			return $tag;
		}

		$processor = new WP_HTML_Tag_Processor( $tag );
		while ( $processor->next_tag( 'script' ) ) {
			if ( self::HANDLE . '-js' === $processor->get_attribute( 'id' ) ) {
				$processor->set_attribute( 'type', 'module' );

				return $processor->get_updated_html();
			}
		}

		return $tag;
	}

	/**
	 * Extension of an allowed build file, or null.
	 *
	 * @param mixed $path Path from the manifest.
	 * @return string|null
	 */
	private static function file_type( $path ) {
		if ( ! is_string( $path ) || 1 !== preg_match( self::FILE_PATTERN, $path, $matches ) ) {
			return null;
		}

		return $matches[1];
	}
}
