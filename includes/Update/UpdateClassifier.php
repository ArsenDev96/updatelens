<?php
/**
 * Decides which WordPress updates UpdateLens analyses.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

defined( 'ABSPATH' ) || exit;

/**
 * Interprets WordPress upgrader data. Pure: callers pass in everything read
 * from WordPress, so the rules are unit-tested without WordPress.
 *
 * Supported (v0.1): a single-plugin update in an interactive wp-admin request
 * on a single site, for any plugin except UpdateLens itself. Both core paths
 * count as single:
 * - Plugin_Upgrader::upgrade()      (update.php?action=upgrade-plugin), bulk = false;
 * - Plugin_Upgrader::bulk_upgrade() with one plugin (the "Update now" Ajax
 *   button and a one-item bulk action), bulk = true, update_count = 1.
 *
 * Multi-plugin updates (`bulk_upgrade()` with several plugins, e.g. several
 * plugins selected on Dashboard → Updates) are a batch: each plugin is an item
 * of it (is_batch()). They are analysed only when `batch_items` is set, an
 * internal switch for validating the per-plugin observations before batch
 * reports exist (0.4.0 P2); otherwise they are ignored, as before.
 */
final class UpdateClassifier {

	/**
	 * Plugin whose update should be analysed, or null to ignore the update.
	 *
	 * $context keys: is_plugin_upgrader (bool), bulk (bool, Plugin_Upgrader::$bulk),
	 * update_count (int, WP_Upgrader::$update_count), hook_extra (array),
	 * is_multisite (bool), is_interactive_admin (bool: wp-admin page or Ajax,
	 * not cron or WP-CLI), self_plugin (string: UpdateLens's basename),
	 * batch_items (bool: analyse the plugins of multi-plugin updates).
	 *
	 * @param array $context Upgrader and request data.
	 * @return string|null Plugin basename.
	 */
	public static function plugin_to_analyze( array $context ) {
		if ( empty( $context['is_interactive_admin'] ) || ! empty( $context['is_multisite'] ) || empty( $context['is_plugin_upgrader'] ) ) {
			return null;
		}

		$hook_extra = isset( $context['hook_extra'] ) && is_array( $context['hook_extra'] ) ? $context['hook_extra'] : array();

		// Installs (including upload-and-replace) have no `plugin` key; bulk updates omit type/action.
		if ( empty( $hook_extra['plugin'] ) || ! is_string( $hook_extra['plugin'] ) ) {
			return null;
		}
		if ( isset( $hook_extra['action'] ) && 'update' !== $hook_extra['action'] ) {
			return null;
		}
		if ( isset( $hook_extra['type'] ) && 'plugin' !== $hook_extra['type'] ) {
			return null;
		}
		if ( ! empty( $context['bulk'] ) && 1 !== (int) $context['update_count'] && ! self::is_batch( $context ) ) {
			return null;
		}
		if ( $hook_extra['plugin'] === $context['self_plugin'] ) {
			return null;
		}

		return $hook_extra['plugin'];
	}

	/**
	 * Whether this download belongs to an analysed multi-plugin update (a
	 * batch): a plugin upgrader's `bulk_upgrade()` with several plugins, in an
	 * interactive wp-admin request on a single site, with `batch_items` set.
	 *
	 * Every plugin of such an update is an item, UpdateLens itself included
	 * (plugin_to_analyze() still returns null for it: the observation of the
	 * batch ends there).
	 *
	 * @param array $context Same keys as plugin_to_analyze().
	 * @return bool
	 */
	public static function is_batch( array $context ) {
		return ! empty( $context['batch_items'] )
			&& ! empty( $context['is_interactive_admin'] )
			&& empty( $context['is_multisite'] )
			&& ! empty( $context['is_plugin_upgrader'] )
			&& ! empty( $context['bulk'] )
			&& isset( $context['update_count'] ) && (int) $context['update_count'] > 1;
	}

	/**
	 * Whether `upgrader_process_complete` data ends a multi-plugin update:
	 * `bulk_upgrade()` fires it once, after every plugin, with all requested
	 * plugins in `plugins` (also those that were up to date or failed).
	 *
	 * @param array $hook_extra `upgrader_process_complete` data.
	 * @return bool
	 */
	public static function completes_batch( array $hook_extra ) {
		return isset( $hook_extra['type'], $hook_extra['action'], $hook_extra['plugins'] )
			&& 'plugin' === $hook_extra['type']
			&& 'update' === $hook_extra['action']
			&& ! empty( $hook_extra['bulk'] )
			&& is_array( $hook_extra['plugins'] )
			&& count( $hook_extra['plugins'] ) > 1;
	}

	/**
	 * Plugin a completed single-plugin update refers to, or null.
	 *
	 * Accepts the `upgrader_process_complete` data of both single paths:
	 * upgrade() passes the run's hook_extra (`plugin`), bulk_upgrade() passes
	 * `plugins` (only a one-plugin batch qualifies).
	 *
	 * @param array $hook_extra `upgrader_process_complete` data.
	 * @return string|null Plugin basename.
	 */
	public static function completed_plugin( array $hook_extra ) {
		if ( ! isset( $hook_extra['type'], $hook_extra['action'] ) || 'plugin' !== $hook_extra['type'] || 'update' !== $hook_extra['action'] ) {
			return null;
		}

		if ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) && '' !== $hook_extra['plugin'] ) {
			return $hook_extra['plugin'];
		}

		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) && 1 === count( $hook_extra['plugins'] ) ) {
			$plugin = reset( $hook_extra['plugins'] );
			return is_string( $plugin ) && '' !== $plugin ? $plugin : null;
		}

		return null;
	}
}
