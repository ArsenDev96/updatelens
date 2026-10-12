<?php
/**
 * Uninstall handler.
 *
 * Runs when the plugin is deleted from the Plugins screen. Removes, for the
 * site (on Multisite: for every site of every network): the analyses table;
 * the options `updatelens_db_version`, `updatelens_db_upgrade_retry_at`,
 * `updatelens_monitoring_baseline`, `updatelens_unread_origin_id`,
 * `updatelens_site_checks` and `updatelens_update_activity`; the site check
 * baselines and session claims; site check session transients left in the
 * options table. Once, for every user: the user meta
 * `updatelens_last_seen_analysis_id` and `updatelens_site_check_starts`.
 * Deactivation keeps all of them. See UpdateLens\Core\Uninstaller.
 *
 * @package UpdateLens
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
	\UpdateLens\Core\Uninstaller::uninstall();
}
