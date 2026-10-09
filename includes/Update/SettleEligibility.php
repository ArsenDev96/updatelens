<?php
/**
 * Which requests may take a settled snapshot at shutdown.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

defined( 'ABSPATH' ) || exit;

/**
 * Rules for the page-load settle trigger. Pure: PluginUpdateTracker passes in
 * what it reads from WordPress, so the rules are unit-tested without WordPress.
 *
 * Only a wp-admin request by a user who may update plugins, which reached
 * `admin_init`, may settle at shutdown: anonymous requests to wp-admin are
 * redirected to the login before `admin_init` (and before plugins run their
 * admin upgrade routines), and Ajax, REST, cron and WP-CLI requests are not
 * page requests. Whether the request started after the update's IMMEDIATE
 * observation is checked per analysis (PluginUpdateAnalyzer).
 */
final class SettleEligibility {

	/**
	 * Capability the requesting user needs.
	 */
	const CAPABILITY = 'update_plugins';

	/**
	 * Whether this request may settle analyses at shutdown.
	 *
	 * $context keys: is_admin (bool), doing_ajax (bool), doing_cron (bool),
	 * is_cli (bool), is_rest (bool), logged_in (bool), can_update_plugins
	 * (bool), admin_init_done (bool).
	 *
	 * @param array $context Request data.
	 * @return bool
	 */
	public static function may_settle_at_shutdown( array $context ) {
		foreach ( array( 'is_admin', 'logged_in', 'can_update_plugins', 'admin_init_done' ) as $required ) {
			if ( empty( $context[ $required ] ) ) {
				return false;
			}
		}

		foreach ( array( 'doing_ajax', 'doing_cron', 'is_cli', 'is_rest' ) as $excluded ) {
			if ( ! empty( $context[ $excluded ] ) ) {
				return false;
			}
		}

		return true;
	}
}
