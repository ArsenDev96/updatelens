<?php
/**
 * Loads the built UpdateLens admin app.
 *
 * Written for UpdateLens; not derived from a third-party asset loader.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Admin;

use UpdateLens\Update\FollowUpRequest;
use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the production build of the admin app (assets/admin/dist/) as
 * listed in its Vite manifest.
 *
 * Production builds only: there is no development-server mode, no other origin
 * and no filter that could point the screen elsewhere. The script is an ES
 * module that uses the WordPress-provided `wp.apiFetch` and `wp.i18n`.
 *
 * The same build has a second entry, the update follow-up script, loaded on
 * the WordPress screens that update plugins through `updates.js`.
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
	 * Script handle of the update follow-up script.
	 */
	const FOLLOW_UP_HANDLE = 'updatelens-update-follow-up';

	/**
	 * Manifest key of the update follow-up script.
	 */
	const FOLLOW_UP_ENTRY = 'src/admin/update-follow-up.ts';

	/**
	 * Global object with the follow-up script's settings.
	 */
	const FOLLOW_UP_SETTINGS = 'updatelensUpdateFollowUp';

	/**
	 * Script handles loaded as ES modules.
	 */
	const MODULE_HANDLES = array( self::HANDLE, self::FOLLOW_UP_HANDLE );

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
	 * Enqueue the update follow-up script (`admin_enqueue_scripts`).
	 *
	 * Only where WordPress's own `updates` script runs (it updates plugins over
	 * Ajax and fires `wp-plugin-update-success`) and only for users who may
	 * update plugins. The script asks once, after the update queue is idle, for
	 * the post-update observation of the plugins it updated (FollowUpRequest).
	 * It has no user-facing text.
	 *
	 * @return void
	 */
	public static function enqueue_update_follow_up() {
		if ( ! wp_script_is( 'updates', 'enqueued' ) || ! current_user_can( FollowUpRequest::CAPABILITY ) ) {
			return;
		}

		$manifest = UPDATELENS_DIR . self::DIST_DIR . self::MANIFEST;
		if ( ! is_file( $manifest ) || ! is_readable( $manifest ) ) {
			return;
		}

		$files = self::entry_files( wp_json_file_decode( $manifest, array( 'associative' => true ) ), self::FOLLOW_UP_ENTRY );
		if ( null === $files ) {
			return;
		}

		wp_enqueue_script( self::FOLLOW_UP_HANDLE, UPDATELENS_URL . self::DIST_DIR . $files['script'], array( 'jquery', 'updates' ), UPDATELENS_VERSION, true );
		wp_localize_script(
			self::FOLLOW_UP_HANDLE,
			self::FOLLOW_UP_SETTINGS,
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'action'     => FollowUpRequest::ACTION,
				'nonce'      => wp_create_nonce( FollowUpRequest::NONCE_ACTION ),
				'selfPlugin' => plugin_basename( UPDATELENS_FILE ),
			)
		);
		add_filter( 'script_loader_tag', array( self::class, 'add_module_type' ), 10, 2 );
	}

	/**
	 * Files of an entry in a decoded Vite manifest.
	 *
	 * @param mixed  $manifest Decoded manifest.json (associative arrays).
	 * @param string $entry_key Manifest key (source entry point). Default: the admin app.
	 * @return array{script: string, styles: string[]}|null Paths relative to the build directory, or null if unusable.
	 */
	public static function entry_files( $manifest, $entry_key = self::ENTRY ) {
		if ( ! is_array( $manifest ) || ! isset( $manifest[ $entry_key ] ) || ! is_array( $manifest[ $entry_key ] ) ) {
			return null;
		}

		$entry = $manifest[ $entry_key ];
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
	 * Load the admin app and the update follow-up script as ES modules.
	 *
	 * Changes only the script's own `<script id="<handle>-js">` tag, not the
	 * inline translations or settings script WordPress prints before it.
	 *
	 * @param string $tag    Script HTML.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public static function add_module_type( $tag, $handle ) {
		if ( ! in_array( $handle, self::MODULE_HANDLES, true ) || ! is_string( $tag ) ) {
			return $tag;
		}

		$processor = new WP_HTML_Tag_Processor( $tag );
		while ( $processor->next_tag( 'script' ) ) {
			if ( $handle . '-js' === $processor->get_attribute( 'id' ) ) {
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
