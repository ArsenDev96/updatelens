<?php
/**
 * Option names left out of the `wp_options` snapshot.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use UpdateLens\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which options are noise for the `wp_options` snapshot.
 *
 * Keep this list conservative: an option that is persistent and could be
 * changed by a plugin update should be reported, even if it is large or
 * changes often (e.g. `rewrite_rules`). Every rule needs a test.
 */
final class OptionNoiseFilter {

	/**
	 * Excluded option name prefixes.
	 *
	 * - `_transient_`, `_site_transient_`: temporary cache data, including
	 *   their `_transient_timeout_` / `_site_transient_timeout_` entries.
	 * - Plugin::OPTION_PREFIX: UpdateLens's own state.
	 */
	const EXCLUDED_PREFIXES = array(
		'_transient_',
		'_site_transient_',
		Plugin::OPTION_PREFIX,
	);

	/**
	 * Excluded exact option names.
	 *
	 * - `cron`: WP-Cron events, which get their own snapshot provider.
	 * - HOSTING_UPDATE_CHECK_PREFIX + `update_core`, `update_plugins` or
	 *   `update_themes`: see HOSTING_UPDATE_CHECK_PREFIX.
	 */
	const EXCLUDED_NAMES = array(
		'cron',
		self::HOSTING_UPDATE_CHECK_PREFIX . 'update_core',
		self::HOSTING_UPDATE_CHECK_PREFIX . 'update_plugins',
		self::HOSTING_UPDATE_CHECK_PREFIX . 'update_themes',
	);

	/**
	 * Prefix of three persistent options found on WP Engine sites whose values
	 * have the fields of WordPress update-check records (like core's update site
	 * transients, excluded above). They appear to be hosting-managed update-check
	 * metadata; their exact writer is unverified.
	 *
	 * Only the three exact names in EXCLUDED_NAMES are excluded, never this
	 * prefix: other `wpe_*` options may be settings and stay in the snapshot.
	 * The names are assembled from this prefix because Plugin Check reports
	 * update-transient names written out in a file as updater code. UpdateLens
	 * never reads or changes update data; it only leaves these names out.
	 */
	const HOSTING_UPDATE_CHECK_PREFIX = 'wpe_site_transient_';

	/**
	 * Whether an option should be left out of the snapshot.
	 *
	 * @param string $option_name Option name.
	 * @return bool
	 */
	public function is_noise( $option_name ) {
		$option_name = (string) $option_name;

		if ( in_array( $option_name, self::EXCLUDED_NAMES, true ) ) {
			return true;
		}

		foreach ( self::EXCLUDED_PREFIXES as $prefix ) {
			if ( 0 === strncmp( $option_name, $prefix, strlen( $prefix ) ) ) {
				return true;
			}
		}

		return false;
	}
}
