<?php
/**
 * WordPress updater hooks for update analysis.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

use Throwable;
use UpdateLens\SiteCheck\FrontendMarker;
use UpdateLens\SiteCheck\Settings;
use UpdateLens\SiteCheck\UpdateActivity;

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
 *   that SettleEligibility allows; end this request's updater activity.
 * - `wp_ajax_updatelens_settle`: the follow-up request the updating
 *   administrator's browser sends after the update (FollowUpRequest).
 *
 * Every updater download also records the request in the site-wide updater
 * activity ledger (SiteCheck\UpdateActivity), before anything else, and the
 * analysis gets that record: it decides which site check baselines may be
 * attached (an update in between makes an older baseline unusable).
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
	 * Site-wide updater activity ledger (orders site check baselines against updates).
	 *
	 * @var UpdateActivity|null
	 */
	private $activity;

	/**
	 * This request's ledger token, once it started updater activity.
	 *
	 * @var string|null
	 */
	private $activity_token = null;

	/**
	 * AFTER site checks (created on first use).
	 *
	 * @var SiteCheckAfter|null
	 */
	private $after;

	/**
	 * Constructor. Create on `plugins_loaded`, after active plugins are loaded.
	 *
	 * @param PluginUpdateAnalyzer $analyzer Lifecycle orchestration.
	 * @param UpdateActivity|null  $activity Updater activity ledger (default: options table).
	 * @param SiteCheckAfter|null  $after    AFTER site checks (default: analyses table and ledger).
	 */
	public function __construct( PluginUpdateAnalyzer $analyzer, ?UpdateActivity $activity = null, ?SiteCheckAfter $after = null ) {
		$this->analyzer       = $analyzer;
		$this->activity       = $activity;
		$this->after          = $after;
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
			if ( is_wp_error( $reply ) || ! $upgrader instanceof \WP_Upgrader ) {
				return $reply;
			}

			// Every updater download (any type, any user, also cron and WP-CLI)
			// is recorded first, so no older site check baseline can be used for
			// a later update.
			$activity = self::quietly(
				function () {
					return $this->record_activity();
				}
			);

			// Translations are not attributed and run inside plugin updates (Language_Pack_Upgrader::async_upgrade()).
			if ( $upgrader instanceof \Language_Pack_Upgrader ) {
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

			$plugin = null === $plugin_file ? array() : self::plugin_data( $plugin_file );
			self::quietly(
				function () use ( $plugin_file, $plugin, $activity ) {
					$this->analyzer->update_starting( $plugin_file, $plugin, get_current_user_id(), $activity );
				}
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
				$data  = self::plugin_data( $plugin_file );
				$error = null;
				$after = '' === $data['version'] ? null : $data['version'];
			} else {
				$error = '' === $result ? 'update_failed' : $result;
				$after = null;
			}
			self::quietly(
				function () use ( $plugin_file, $error, $after ) {
					$this->analyzer->update_finished( $plugin_file, $error, $after );
				}
			);
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
		self::quietly(
			function () {
				try {
					$this->analyzer->request_ending( self::may_settle_at_shutdown(), $this->activation_changed() );
				} catch ( Throwable $e ) {
					// Never interfere with the request; the activity still ends.
					unset( $e );
				}
				$this->end_activity();
			}
		);
	}

	/**
	 * Run UpdateLens's own storage work with database errors hidden: with
	 * `WP_DEBUG_DISPLAY` they would be printed into the update's Ajax
	 * response, the follow-up response or the page (e.g. while the analyses
	 * table is missing). WordPress still logs them. Only this work is
	 * affected: the previous setting is restored afterwards, also when it
	 * throws.
	 *
	 * @param callable $work Work.
	 * @return mixed What $work returns.
	 */
	private static function quietly( callable $work ) {
		global $wpdb;

		$shown = $wpdb->hide_errors();
		try {
			return call_user_func( $work );
		} finally {
			$wpdb->show_errors( $shown );
		}
	}

	/**
	 * End this request's updater activity, if it had any.
	 *
	 * @return void
	 */
	private function end_activity() {
		if ( null !== $this->activity_token ) {
			$this->activity()->end( $this->activity_token );
		}
	}

	/**
	 * Record this request's updater activity in the site-wide ledger.
	 *
	 * @return array|null UpdateActivity::begin() result, or null if it could not be recorded.
	 */
	private function record_activity() {
		try {
			if ( null === $this->activity_token ) {
				$this->activity_token = $this->activity()->new_token();
			}

			return $this->activity()->begin( $this->activity_token );
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Updater activity ledger.
	 *
	 * @return UpdateActivity
	 */
	private function activity() {
		if ( null === $this->activity ) {
			$this->activity = UpdateActivity::from_wordpress();
		}

		return $this->activity;
	}

	/**
	 * The follow-up request after a successful update (`admin-ajax.php`,
	 * logged-in users only). Responds with one FollowUpRequest result per
	 * listed plugin; never with snapshot or diff data. A plugin whose analysis
	 * this request settled also gets that analysis's `analysis` ID and
	 * `site_checks` (whether to start its AFTER site checks now).
	 *
	 * @return void
	 */
	public function on_follow_up() {
		// The response names this user's analyses: never kept by a cache.
		FrontendMarker::send_no_store();
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

		$settled = array();
		try {
			$results = self::quietly(
				function () use ( $plugins, &$settled ) {
					return $this->analyzer->settle_follow_up( $plugins, get_current_user_id(), $this->activation_changed(), $settled );
				}
			);
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'code' => 'failed' ), 500 );
		}

		$response = array();
		foreach ( $results as $plugin => $result ) {
			$entry = array(
				'plugin' => $plugin,
				'result' => $result,
			);
			if ( isset( $settled[ $plugin ] ) ) {
				// Exactly the analysis this request settled (never "the latest one"), and
				// whether its AFTER site checks should start now (SiteCheckAfter decides).
				$entry['analysis']    = $settled[ $plugin ];
				$entry['site_checks'] = self::quietly(
					function () use ( $settled, $plugin ) {
						return $this->wants_site_checks( $settled[ $plugin ] );
					}
				);
			}
			$response[] = $entry;
		}

		wp_send_json_success( array( 'results' => $response ) );
	}

	/**
	 * Whether the updating browser should start AFTER site checks for an
	 * analysis this request settled (opt-in on, a BEFORE baseline attached).
	 *
	 * @param int $analysis_id Analysis ID.
	 * @return bool
	 */
	private function wants_site_checks( $analysis_id ) {
		try {
			if ( ! Settings::is_enabled() ) {
				return false;
			}
			if ( null === $this->after ) {
				$this->after = SiteCheckAfter::from_wordpress();
			}

			return $this->after->wanted( $analysis_id );
		} catch ( Throwable $e ) {
			return false;
		}
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
