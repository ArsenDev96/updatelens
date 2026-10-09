<?php
/**
 * WordPress updater hooks for update analysis.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Observes the native WordPress updater and forwards events to
 * PluginUpdateAnalyzer.
 *
 * Observation only: every filter returns its input unchanged, and any
 * UpdateLens error is swallowed so it can never block or alter an update.
 *
 * Hooks:
 * - `upgrader_pre_download` (last): an update is about to touch files. It
 *   receives the upgrader, whose `bulk`/`update_count` tell a one-plugin batch
 *   from a multi-plugin one (`upgrader_package_options` does not).
 * - `upgrader_install_package_result` (last): the per-plugin result.
 * - `upgrader_process_complete` (last, after core's own handlers): the update
 *   finished; capture the immediate state.
 * - `shutdown` (last): fail unfinished analyses; settle on later admin pages
 *   that SettleEligibility allows.
 * - `wp_ajax_updatelens_settle`: the follow-up request the updating
 *   administrator's browser sends after the update (FollowUpRequest).
 */
final class PluginUpdateTracker {

	/**
	 * Lifecycle orchestration.
	 *
	 * @var PluginUpdateAnalyzer
	 */
	private $analyzer;

	/**
	 * Install results seen in this request: plugin file => true or WordPress error code.
	 *
	 * @var array<string, true|string>
	 */
	private $install_results = array();

	/**
	 * Active plugins when the tracker was created (on `plugins_loaded`).
	 *
	 * @var string[]
	 */
	private $active_at_boot;

	/**
	 * Constructor. Create on `plugins_loaded`, after active plugins are loaded.
	 *
	 * @param PluginUpdateAnalyzer $analyzer Lifecycle orchestration.
	 */
	public function __construct( PluginUpdateAnalyzer $analyzer ) {
		$this->analyzer       = $analyzer;
		$this->active_at_boot = (array) get_option( 'active_plugins', array() );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'upgrader_pre_download', array( $this, 'on_pre_download' ), PHP_INT_MAX, 4 );
		add_filter( 'upgrader_install_package_result', array( $this, 'on_install_package_result' ), PHP_INT_MAX, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'on_process_complete' ), PHP_INT_MAX, 2 );
		add_action( 'shutdown', array( $this, 'on_shutdown' ), PHP_INT_MAX );
		add_action( 'wp_ajax_' . FollowUpRequest::ACTION, array( $this, 'on_follow_up' ) );
	}

	/**
	 * An update is about to download/unpack its package.
	 *
	 * @param false|string|\WP_Error $reply      Short-circuit value; returned unchanged.
	 * @param string                 $package    Package URI (not used or stored).
	 * @param mixed                  $upgrader   WP_Upgrader instance.
	 * @param array                  $hook_extra Upgrader hook data.
	 * @return false|string|\WP_Error
	 */
	public function on_pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		try {
			// Translations are not attributed and run inside plugin updates (Language_Pack_Upgrader::async_upgrade()).
			if ( is_wp_error( $reply ) || ! $upgrader instanceof \WP_Upgrader || $upgrader instanceof \Language_Pack_Upgrader ) {
				return $reply;
			}

			$is_plugin_upgrader = $upgrader instanceof \Plugin_Upgrader;
			$plugin_file        = UpdateClassifier::plugin_to_analyze(
				array(
					'is_plugin_upgrader'   => $is_plugin_upgrader,
					'bulk'                 => $is_plugin_upgrader && $upgrader->bulk,
					'update_count'         => (int) $upgrader->update_count,
					'hook_extra'           => is_array( $hook_extra ) ? $hook_extra : array(),
					'is_multisite'         => is_multisite(),
					'is_interactive_admin' => self::is_interactive_admin(),
					'self_plugin'          => plugin_basename( UPDATELENS_FILE ),
				)
			);

			$this->analyzer->update_starting(
				$plugin_file,
				null === $plugin_file ? array() : self::plugin_data( $plugin_file ),
				get_current_user_id()
			);
		} catch ( Throwable $e ) {
			return $reply; // Never block the update.
		}

		return $reply;
	}

	/**
	 * Remember the per-plugin install result.
	 *
	 * @param array|\WP_Error $result     Install result; returned unchanged.
	 * @param array           $hook_extra Upgrader hook data.
	 * @return array|\WP_Error
	 */
	public function on_install_package_result( $result, $hook_extra = array() ) {
		if ( is_array( $hook_extra ) && ! empty( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$this->install_results[ $hook_extra['plugin'] ] = is_wp_error( $result ) ? (string) $result->get_error_code() : true;
		}

		return $result;
	}

	/**
	 * An upgrader process finished.
	 *
	 * @param mixed $upgrader   WP_Upgrader instance.
	 * @param array $hook_extra Completion data.
	 * @return void
	 */
	public function on_process_complete( $upgrader, $hook_extra = array() ) {
		try {
			$plugin_file = UpdateClassifier::completed_plugin( is_array( $hook_extra ) ? $hook_extra : array() );
			if ( null === $plugin_file ) {
				return;
			}

			// No result means the package was never installed (e.g. the download failed).
			$result = array_key_exists( $plugin_file, $this->install_results ) ? $this->install_results[ $plugin_file ] : 'update_not_installed';

			if ( true === $result ) {
				$after = self::plugin_data( $plugin_file );
				$this->analyzer->update_finished( $plugin_file, null, '' === $after['version'] ? null : $after['version'] );
			} else {
				$this->analyzer->update_finished( $plugin_file, '' === $result ? 'update_failed' : $result, null );
			}
		} catch ( Throwable $e ) {
			return; // Never interfere with the update.
		}
	}

	/**
	 * End of request.
	 *
	 * @return void
	 */
	public function on_shutdown() {
		try {
			$this->analyzer->request_ending( self::may_settle_at_shutdown(), $this->activation_changed() );
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * The follow-up request after a successful update (`admin-ajax.php`,
	 * logged-in users only). Responds with one FollowUpRequest result per
	 * listed plugin; never with snapshot or diff data.
	 *
	 * @return void
	 */
	public function on_follow_up() {
		if ( false === check_ajax_referer( FollowUpRequest::NONCE_ACTION, false, false ) ) {
			wp_send_json_error( array( 'code' => 'invalid_nonce' ), 403 );
		}
		if ( ! current_user_can( FollowUpRequest::CAPABILITY ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every entry is validated by FollowUpRequest::plugins() (strict basename pattern).
		$plugins = FollowUpRequest::plugins( isset( $_POST['plugins'] ) ? wp_unslash( $_POST['plugins'] ) : null );
		if ( null === $plugins ) {
			wp_send_json_error( array( 'code' => 'invalid_request' ), 400 );
		}

		// The settled snapshot is written even if the browser goes away meanwhile.
		ignore_user_abort( true );

		try {
			$results = $this->analyzer->settle_follow_up( $plugins, get_current_user_id(), $this->activation_changed() );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'code' => 'failed' ), 500 );
		}

		$response = array();
		foreach ( $results as $plugin => $result ) {
			$response[] = array(
				'plugin' => $plugin,
				'result' => $result,
			);
		}

		wp_send_json_success( array( 'results' => $response ) );
	}

	/**
	 * Plugins whose active state changed during this request.
	 *
	 * @return string[]
	 */
	private function activation_changed() {
		$active_now = (array) get_option( 'active_plugins', array() );

		return array_values( array_merge( array_diff( $this->active_at_boot, $active_now ), array_diff( $active_now, $this->active_at_boot ) ) );
	}

	/**
	 * Interactive wp-admin request (page or Ajax), not cron or WP-CLI.
	 *
	 * @return bool
	 */
	private static function is_interactive_admin() {
		return is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Whether this request may settle analyses at shutdown (SettleEligibility).
	 *
	 * @return bool
	 */
	private static function may_settle_at_shutdown() {
		return SettleEligibility::may_settle_at_shutdown(
			array(
				'is_admin'           => is_admin(),
				'doing_ajax'         => wp_doing_ajax(),
				'doing_cron'         => wp_doing_cron(),
				'is_cli'             => defined( 'WP_CLI' ) && WP_CLI,
				'is_rest'            => defined( 'REST_REQUEST' ) && REST_REQUEST,
				'logged_in'          => is_user_logged_in(),
				'can_update_plugins' => current_user_can( SettleEligibility::CAPABILITY ),
				'admin_init_done'    => did_action( 'admin_init' ) > 0,
			)
		);
	}

	/**
	 * Name and version from the plugin header on disk (read fresh, not cached).
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return array{name: string, version: string}
	 */
	private static function plugin_data( $plugin_file ) {
		$data = array(
			'name'    => '',
			'version' => '',
		);

		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( 0 !== validate_file( $plugin_file ) || ! is_readable( $path ) ) {
			return $data;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$header = get_plugin_data( $path, false, false );

		$data['name']    = mb_substr( wp_strip_all_tags( (string) $header['Name'] ), 0, 255 );
		$data['version'] = mb_substr( wp_strip_all_tags( (string) $header['Version'] ), 0, 64 );

		return $data;
	}
}
