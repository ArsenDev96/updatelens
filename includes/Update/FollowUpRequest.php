<?php
/**
 * The post-update follow-up request sent by the updating administrator's browser.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

defined( 'ABSPATH' ) || exit;

/**
 * Names, input rules and result codes of the `updatelens_settle` Ajax action.
 * Pure: the WordPress side (nonce, capability, response) is in
 * PluginUpdateTracker, so these rules are unit-tested without WordPress.
 *
 * After a successful plugin update from the WordPress updates UI, the browser
 * asks once (when the update queue is idle) for the post-update observation of
 * the plugins it updated. The request is a new PHP request, so it runs the
 * updated plugins' new code (`plugins_loaded`, `init`, `admin_init`) before
 * the settled snapshot is taken. Only analyses of the listed plugins that the
 * current user started are settled; other pending analyses are left alone.
 */
final class FollowUpRequest {

	/**
	 * `admin-ajax.php` action (logged-in users only: `wp_ajax_` hook).
	 */
	const ACTION = 'updatelens_settle';

	/**
	 * Nonce action.
	 */
	const NONCE_ACTION = 'updatelens_settle';

	/**
	 * Capability required to send the request (the one that allows plugin updates).
	 */
	const CAPABILITY = 'update_plugins';

	/**
	 * Most plugins one request may list (a long queue of updates in one page).
	 */
	const MAX_PLUGINS = 100;

	/**
	 * Longest accepted plugin basename (the `plugin_file` column length).
	 */
	const MAX_PLUGIN_LENGTH = 255;

	/**
	 * This request took the settled snapshot and stored the post-update and final diffs.
	 */
	const SETTLED = 'settled';

	/**
	 * No analysis of this plugin awaits a settled snapshot (never analysed,
	 * already settled or ended by another request).
	 */
	const NOT_PENDING = 'not_pending';

	/**
	 * The update is still running, or this request started before the update's
	 * IMMEDIATE observation and may not have loaded the new code.
	 */
	const NOT_READY = 'not_ready';

	/**
	 * The pending analysis was started by another user.
	 */
	const NOT_ALLOWED = 'not_allowed';

	/**
	 * The settle window had passed; the analysis was completed without a settled snapshot.
	 */
	const EXPIRED = 'expired';

	/**
	 * Taking the settled snapshot failed; the analysis still awaits one.
	 */
	const CAPTURE_FAILED = 'capture_failed';

	/**
	 * The settled snapshot could not be compared; the analysis ended without
	 * post-update and final diffs (failed or incompatible).
	 */
	const ANALYSIS_FAILED = 'analysis_failed';

	/**
	 * Another request changed the analysis at the same time (its result stands).
	 */
	const CONFLICT = 'conflict';

	/**
	 * The result could not be stored; the analysis still awaits a settled snapshot.
	 */
	const STORAGE_FAILED = 'storage_failed';

	/**
	 * Plugin basenames from the request input, or null if the input is invalid.
	 *
	 * Accepts a non-empty list of at most MAX_PLUGINS plugin basenames;
	 * duplicates are dropped, the order is kept.
	 *
	 * @param mixed $input Raw `plugins` request value (unslashed).
	 * @return string[]|null
	 */
	public static function plugins( $input ) {
		if ( ! is_array( $input ) || array() === $input || count( $input ) > self::MAX_PLUGINS ) {
			return null;
		}

		$plugins = array();
		foreach ( $input as $plugin ) {
			if ( ! self::is_plugin_file( $plugin ) ) {
				return null;
			}
			if ( ! in_array( $plugin, $plugins, true ) ) {
				$plugins[] = $plugin;
			}
		}

		return $plugins;
	}

	/**
	 * Whether a value is a safe plugin basename: `file.php` or `dir/file.php`,
	 * relative, without `..`, backslashes or control characters.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_plugin_file( $value ) {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > self::MAX_PLUGIN_LENGTH ) {
			return false;
		}

		if ( 1 !== preg_match( '#\A(?:[^/\\\\\x00-\x1F\x7F]+/)?[^/\\\\\x00-\x1F\x7F]+\.php\z#', $value ) ) {
			return false;
		}

		foreach ( explode( '/', $value ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}
}
