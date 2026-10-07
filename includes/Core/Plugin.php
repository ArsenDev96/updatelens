<?php
/**
 * Main plugin class.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Core;

use UpdateLens\Admin\AdminPage;
use UpdateLens\Rest\AnalysesController;
use UpdateLens\Rest\BaselineController;
use UpdateLens\Rest\StatusController;
use UpdateLens\Storage\Schema;
use UpdateLens\Update\PluginUpdateAnalyzer;
use UpdateLens\Update\PluginUpdateTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Wires UpdateLens components into WordPress.
 *
 * Keep this class a thin composition root: it registers hooks and delegates
 * to feature classes. Business logic belongs in the feature namespaces
 * (Snapshot, Diff, Update, Storage), not here.
 */
final class Plugin {

	/**
	 * Capability required to view UpdateLens screens and call its REST API.
	 *
	 * Reports will expose `wp_options` data, so access is limited to users
	 * who can manage site options.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Namespace for all UpdateLens REST routes.
	 */
	const REST_NAMESPACE = 'updatelens/v1';

	/**
	 * Prefix for every option UpdateLens stores in `wp_options`.
	 *
	 * The `wp_options` snapshot excludes names with this prefix, so UpdateLens
	 * never reports its own internal state as a change.
	 */
	const OPTION_PREFIX = 'updatelens_';

	/**
	 * Whether the plugin has already been booted.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Boot the plugin once. Hooked to `plugins_loaded`.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		( new self() )->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		if ( is_admin() ) {
			( new AdminPage() )->register();
		}

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Update analysis is single-site only in v0.1. Sites activated before the
		// schema existed get it here, since activation does not run again.
		if ( ! is_multisite() && Schema::maybe_upgrade() ) {
			( new PluginUpdateTracker( PluginUpdateAnalyzer::create() ) )->register();
		}
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		( new StatusController() )->register_routes();

		// Analyses and the monitoring baseline exist on single sites only (see register_hooks()).
		if ( ! is_multisite() ) {
			( new AnalysesController() )->register_routes();
			( new BaselineController() )->register_routes();
		}
	}
}
