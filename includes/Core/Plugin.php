<?php
/**
 * Main plugin class.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Core;

use UpdateLens\Admin\AdminPage;
use UpdateLens\Rest\StatusController;

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
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		( new StatusController() )->register_routes();
	}
}
