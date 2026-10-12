<?php
/**
 * Uninstall handler.
 *
 * Runs when the plugin is deleted from the Plugins screen: drops the analyses
 * table and removes the schema version and monitoring baseline options,
 * the site check opt-in, the per-user site check start times and the site
 * check baselines and session claims. Site check sessions are transients
 * that expire within two minutes. Deactivation keeps all of them.
 *
 * @package UpdateLens
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
	\UpdateLens\Storage\Schema::uninstall();
	\UpdateLens\Baseline\MonitoringBaseline::uninstall();
	\UpdateLens\Admin\UnreadReports::uninstall();
	\UpdateLens\SiteCheck\Settings::uninstall();
	\UpdateLens\SiteCheck\RateLimiter::uninstall();
	\UpdateLens\SiteCheck\OptionBaselineStore::uninstall();
	\UpdateLens\SiteCheck\TransientSessionStore::uninstall();
}
