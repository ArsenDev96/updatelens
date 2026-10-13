<?php
/**
 * Where and when BEFORE baselines are collected.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a wp-admin page collects BEFORE baselines and builds the
 * settings of its baseline script (`src/admin/site-check-baseline.ts`,
 * enqueued by AdminAssets).
 *
 * Only on the Plugins and Updates screens, only for users who may update
 * plugins, with the opt-in on (Settings), outside maintenance mode, with a
 * WordPress login session, only while at least one plugin other than
 * UpdateLens has an update available and only if at least one check's
 * target is on wp-admin's origin (TargetOrigin; a check on another origin
 * gets AjaxEndpoints::UNSUPPORTED_REFRESH_IN). Every other admin page loads
 * nothing and sends no site check request. The start endpoint repeats the
 * update, freshness and origin checks (AjaxEndpoints), so a page that stays
 * open after the last update was installed starts no further baselines.
 */
final class BaselinePage {

	/**
	 * Admin pages (`$hook_suffix`) that collect baselines: Plugins, Updates.
	 */
	const SCREENS = array( 'plugins.php', 'update-core.php' );

	/**
	 * Global object with the baseline script's settings.
	 */
	const SETTINGS = 'updatelensSiteCheckBaseline';

	/**
	 * Number of plugins with an available update that UpdateLens would
	 * analyse (every plugin except UpdateLens itself).
	 *
	 * @param mixed  $plugin_files Plugin basenames with an update.
	 * @param string $own          UpdateLens's own basename.
	 * @return int
	 */
	public static function count_relevant( $plugin_files, $own ) {
		$count = 0;
		foreach ( is_array( $plugin_files ) ? $plugin_files : array() as $file ) {
			if ( is_string( $file ) && '' !== $file && $file !== $own ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Whether a plugin other than UpdateLens has an update available, from
	 * WordPress's own update data (no request to WordPress.org).
	 *
	 * @return bool
	 */
	public static function has_relevant_updates() {
		if ( ! function_exists( 'get_plugin_updates' ) && is_readable( ABSPATH . 'wp-admin/includes/update.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'get_plugin_updates' ) || ! function_exists( 'get_plugins' ) ) {
			return false;
		}
		$updates = get_plugin_updates();

		return self::count_relevant( is_array( $updates ) ? array_keys( $updates ) : array(), plugin_basename( UPDATELENS_FILE ) ) > 0;
	}

	/**
	 * Settings of the baseline script for this page, or null if it must not
	 * load here.
	 *
	 * @param mixed               $hook_suffix Admin page (`admin_enqueue_scripts` argument).
	 * @param Baselines|null      $baselines   Baselines (default: options table).
	 * @param ContextHasher|null  $hasher      Context fingerprints (default: the site's salts).
	 * @param UpdateActivity|null $activity    Updater activity ledger (default: options table).
	 * @return array{ajaxUrl: string, nonce: string, schedule: array}|null
	 */
	public static function settings( $hook_suffix, ?Baselines $baselines = null, ?ContextHasher $hasher = null, ?UpdateActivity $activity = null ) {
		if ( ! in_array( $hook_suffix, self::SCREENS, true ) || is_multisite() || ! current_user_can( AjaxEndpoints::CAPABILITY ) || ! Settings::is_enabled() ) {
			return null;
		}
		if ( function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode() ) {
			return null;
		}
		$token = wp_get_session_token();
		if ( ! is_string( $token ) || '' === $token || ! self::has_relevant_updates() ) {
			return null;
		}

		$urls        = AjaxEndpoints::target_urls();
		$unsupported = AjaxEndpoints::unsupported_checks( $urls );
		if ( count( $unsupported ) === count( $urls ) ) {
			// No check can run from this admin page's origin: nothing to load.
			return null;
		}
		try {
			$hasher  = null === $hasher ? ContextHasher::from_wordpress() : $hasher;
			$targets = array_map( array( $hasher, 'target' ), $urls );
			$login   = $hasher->session( $token );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
		$baselines  = null === $baselines ? Baselines::from_wordpress() : $baselines;
		$activity   = null === $activity ? UpdateActivity::from_wordpress() : $activity;
		$generation = $activity->quiet_generation();
		$readiness  = $baselines->readiness( get_current_user_id(), $login, $targets, $generation );
		$checks     = Baselines::status( $readiness, null !== $generation );
		foreach ( $unsupported as $check ) {
			$checks[ $check ] = array(
				'state'      => 'unsupported',
				'verdict'    => null,
				'expires_in' => 0,
			);
		}

		return array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( AjaxEndpoints::NONCE_ACTION ),
			// Numbers stay numbers only below the top level of wp_localize_script().
			'schedule' => array(
				'refresh_after'       => Baselines::REFRESH_AFTER_SECONDS,
				'retry_after_failure' => Baselines::RETRY_AFTER_FAILURE_SECONDS,
				'refresh_in'          => AjaxEndpoints::without_unsupported( Baselines::refresh_in( $readiness ), $unsupported ),
			),
			// The readiness line near the update controls (no request of its own).
			// Dashboard → Updates gets a note instead: its updates are not compared.
			'status'   => array(
				'max_age'     => Baselines::MAX_AGE_SECONDS,
				'screen'      => 'update-core.php' === $hook_suffix ? 'updates' : 'plugins',
				'plugins_url' => admin_url( 'plugins.php?plugin_status=upgrade' ),
				'checks'      => $checks,
				'labels'      => self::status_labels(),
			),
		);
	}

	/**
	 * Texts of the readiness line, translated here so the baseline script
	 * needs no translation files of its own. "Ready" means an update started
	 * now can be compared, never that its checks will pass.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels() {
		return array(
			'prefix'         => __( 'UpdateLens:', 'updatelens' ),
			'preparing'      => __( 'Preparing site checks…', 'updatelens' ),
			'preparing_next' => __( 'Preparing site checks for another update…', 'updatelens' ),
			'ready'          => __( 'Ready to compare site responses', 'updatelens' ),
			'ready_home'     => __( 'Ready to compare the homepage only', 'updatelens' ),
			'ready_rest'     => __( 'Ready to compare the REST API only', 'updatelens' ),
			'unavailable'    => __( 'Site checks unavailable for now', 'updatelens' ),
			'other_screen'   => __( 'Site responses are compared only for updates started with “Update now” on Installed Plugins, not from this screen.', 'updatelens' ),
			'plugins_link'   => __( 'Go to Installed Plugins', 'updatelens' ),
		);
	}
}
