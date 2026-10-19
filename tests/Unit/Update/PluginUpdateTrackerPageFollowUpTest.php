<?php
/**
 * Tests for the follow-up from Dashboard → Updates' update page.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use UpdateLens\Admin\AdminAssets;
use UpdateLens\SiteCheck\Baselines;
use UpdateLens\SiteCheck\UpdateActivity;
use UpdateLens\Tests\Support\FakeSite;
use UpdateLens\Tests\Support\InMemoryBaselineStore;
use UpdateLens\Tests\Support\SiteCheckFixture as F;
use UpdateLens\Update\AnalysisStatus;
use UpdateLens\Update\FollowUpRequest;
use UpdateLens\Update\PluginUpdateTracker;
use UpdateLens\Update\SettleOutcome;
use UpdateLens\Update\SiteCheckAfter;
use UpdateLens\Update\SiteCheckObservation;

/**
 * Dashboard → Updates runs `update.php?action=update-selected` in an iframe:
 * core enqueues `updates` (so UpdateLens's follow-up script is enqueued
 * too), runs `bulk_upgrade()` inside the page, and only then prints footer
 * scripts. The tracker's hooks, in core's order, with the real analyzer,
 * ledger and AFTER side: a successful one-plugin update hands that plugin
 * to the follow-up script; the page's follow-up settles the analysis
 * (`follow_up`) and its AFTER checks can start, once. Failed updates,
 * updates of several plugins (not analysed), other pages and a script that
 * is missing or already printed get nothing.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PluginUpdateTrackerPageFollowUpTest extends TestCase {

	const PLUGIN = 'acme/acme.php';
	const OTHER  = 'beta/beta.php';
	const LOGIN  = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	const T      = 1767225600; // 2026-01-01T00:00:00Z.

	/**
	 * Site stand-in (options, analyses).
	 *
	 * @var FakeSite
	 */
	private $site;

	/**
	 * Updater activity ledger.
	 *
	 * @var UpdateActivity
	 */
	private $activity;

	/**
	 * Baselines.
	 *
	 * @var Baselines
	 */
	private $baselines;

	/**
	 * AFTER side.
	 *
	 * @var SiteCheckAfter
	 */
	private $after;

	/**
	 * Load the stand-ins and build the site: the update page with the
	 * follow-up script enqueued, not yet printed.
	 *
	 * @before
	 */
	public function set_up_site() {
		require dirname( __DIR__, 2 ) . '/fixtures/wpdb-recorder.php';
		require_once dirname( __DIR__, 2 ) . '/fixtures/wp-tracker-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/fixtures/wp-upgrader-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/fixtures/wp-page-follow-up-stubs.php';

		$this->site      = new FakeSite( array( 'acme_version' => array( '1.0.0', 'yes' ) ), self::T );
		$store           = new InMemoryBaselineStore();
		$clock           = function () {
			return (float) $this->site->time;
		};
		$this->activity  = new UpdateActivity( $store, $clock, 'random_bytes' );
		$this->baselines = new Baselines( $store, $clock );
		$this->after     = new SiteCheckAfter( $this->site->repository, $this->activity, $clock );

		$this->site->site_checks = new SiteCheckObservation(
			static function () {
				return array(
					'enabled' => true,
					'login'   => self::LOGIN,
					'targets' => array(
						'home' => str_repeat( 'a', 64 ),
						'rest' => str_repeat( 'c', 64 ),
					),
				);
			},
			$this->baselines
		);
		foreach ( array( self::PLUGIN, self::OTHER ) as $plugin ) {
			$this->plugin_version( $plugin, '1.0.0' );
		}

		$GLOBALS['pagenow']                             = 'update.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stands in for WordPress's own value on the update page.
		$_GET['action']                                 = 'update-selected';
		$GLOBALS['updatelens_test_scripts']['enqueued'] = array( AdminAssets::FOLLOW_UP_HANDLE );
	}

	/**
	 * Remove the plugin files and the closures (a failure in a separate
	 * process is serialized with the test object).
	 *
	 * @after
	 */
	public function remove_plugins() {
		$this->site      = null;
		$this->activity  = null;
		$this->baselines = null;
		$this->after     = null;

		foreach ( array( self::PLUGIN, self::OTHER ) as $plugin ) {
			$file = WP_PLUGIN_DIR . '/' . $plugin;
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture file.
				rmdir( dirname( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture directory.
			}
		}
		if ( is_dir( WP_PLUGIN_DIR ) ) {
			rmdir( WP_PLUGIN_DIR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture directory.
		}
	}

	/**
	 * A plugin's header on disk (what the update installs).
	 *
	 * @param string $plugin  Plugin basename.
	 * @param string $version Version.
	 * @return void
	 */
	private function plugin_version( $plugin, $version ) {
		$file = WP_PLUGIN_DIR . '/' . $plugin;
		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture directory.
		}
		file_put_contents( $file, "<?php\n/**\n * Plugin Name: Fixture\n * Version: {$version}\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture file.
	}

	/**
	 * Both BEFORE baselines of this administrator, a few seconds before the update.
	 *
	 * @return void
	 */
	private function baselines() {
		foreach ( array( 'home', 'rest' ) as $check ) {
			$started          = (float) $this->site->time;
			$generation       = $this->activity->quiet_generation();
			$this->site->time = $started + 1;
			$result           = F::result(
				$check,
				array( 'home' === $check ? F::home() : F::rest() ),
				array(
					'user'         => 7,
					'session'      => self::LOGIN,
					'started_at'   => $started,
					'completed_at' => $started + 1,
					'generation'   => $generation,
				)
			);
			$this->assertSame( Baselines::SAVED, $this->baselines->save( $result ) );
		}
		$this->site->time += 5;
	}

	/**
	 * Dashboard → Updates' update page through the tracker's hooks, in core's
	 * order: `bulk_upgrade()` downloads and installs each plugin, fires one
	 * `upgrader_process_complete` for the batch, then `iframe_footer()` fires
	 * `admin_print_footer_scripts`, then the request's shutdown.
	 *
	 * @param array<string, string> $outcomes Plugin => `installed`, `download_failed` (no install result) or an install error code.
	 * @return PluginUpdateTracker
	 */
	private function update_page( array $outcomes ) {
		$tracker  = new PluginUpdateTracker( $this->site->request(), $this->activity, $this->after );
		$upgrader = new \Plugin_Upgrader();

		$upgrader->bulk         = true;
		$upgrader->update_count = count( $outcomes );
		foreach ( $outcomes as $plugin => $outcome ) {
			$this->assertFalse( $tracker->on_pre_download( false, 'https://downloads.example/package.zip', $upgrader, array( 'plugin' => $plugin ) ), 'The download is not short-circuited.' );
			$this->site->time += 1;
			if ( 'installed' === $outcome ) {
				$this->plugin_version( $plugin, '1.1.0' );
				$installed = array( 'destination_name' => dirname( $plugin ) );
				$this->assertSame( $installed, $tracker->on_install_package_result( $installed, array( 'plugin' => $plugin ) ) );
			} elseif ( 'download_failed' !== $outcome ) {
				$error = new \WP_Error( $outcome );
				$this->assertSame( $error, $tracker->on_install_package_result( $error, array( 'plugin' => $plugin ) ) );
			}
		}
		$tracker->on_process_complete(
			$upgrader,
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'bulk'    => true,
				'plugins' => array_keys( $outcomes ),
			)
		);
		$tracker->on_print_footer_scripts();
		$this->site->time += 1;
		$tracker->on_shutdown();

		return $tracker;
	}

	/**
	 * What the update page received: the localized page data, if any.
	 *
	 * @return array<int, array{string, string, array}>
	 */
	private static function page_data() {
		return array_values(
			array_filter(
				$GLOBALS['updatelens_test_scripts']['localized'],
				static function ( $call ) {
					return AdminAssets::FOLLOW_UP_PAGE_SETTINGS === $call[1];
				}
			)
		);
	}

	/**
	 * The page's follow-up request a second later.
	 *
	 * @param string[] $plugins Plugins listed.
	 * @return array{results: array<string, string>, settled: array<string, int>}
	 */
	private function follow_up( array $plugins ) {
		$this->site->time += 1;
		$settled           = array();
		$results           = $this->site->request()->settle_follow_up( $plugins, 7, array(), $settled );

		return array(
			'results' => $results,
			'settled' => $settled,
		);
	}

	/**
	 * A successful one-plugin update from Dashboard → Updates: the page gets
	 * exactly that plugin, its follow-up settles the analysis as `follow_up`
	 * and the AFTER checks can start. A duplicate follow-up settles nothing
	 * and the AFTER checks cannot start a second time.
	 */
	public function test_single_plugin_update_settles_from_the_update_page() {
		$this->baselines();
		$generation = $this->activity->quiet_generation();

		$this->update_page( array( self::PLUGIN => 'installed' ) );

		$this->assertSame(
			array( array( AdminAssets::FOLLOW_UP_HANDLE, AdminAssets::FOLLOW_UP_PAGE_SETTINGS, array( 'plugins' => array( self::PLUGIN ) ) ) ),
			self::page_data()
		);
		$this->assertCount( 1, $this->site->repository->rows );
		$row = array_values( $this->site->repository->rows )[0];
		$id  = (int) $row['id'];
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $row['status'] );
		$this->assertSame( UpdateActivity::advance( $generation, 2 ), $this->activity->quiet_generation(), 'One update request moves the ledger exactly twice.' );

		$follow = $this->follow_up( self::page_data()[0][2]['plugins'] );
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::SETTLED ), $follow['results'] );
		$this->assertSame( array( self::PLUGIN => $id ), $follow['settled'] );
		$row = $this->site->repository->rows[ $id ];
		$this->assertSame( array( AnalysisStatus::COMPLETED, SettleOutcome::FOLLOW_UP ), array( $row['status'], $row['settle_outcome'] ) );
		$this->assertNotNull( $row['options_post_update_diff'], 'The After update phase is recorded.' );

		$this->assertTrue( $this->after->wanted( $id ) );
		$opened = $this->after->open( $id, 7, self::LOGIN );
		$this->assertSame( SiteCheckAfter::OPEN, $opened['code'] );
		$this->assertSame( array( 'home', 'rest' ), $opened['checks'] );
		$row = $this->site->repository->rows[ $id ]; // With the AFTER start claimed.

		// The same follow-up again (a repeated request, a reloaded page).
		$again = $this->follow_up( array( self::PLUGIN ) );
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_PENDING ), $again['results'] );
		$this->assertSame( array(), $again['settled'], 'No analysis named: no second AFTER run.' );
		$this->assertSame( $row, $this->site->repository->rows[ $id ], 'The completed analysis is not touched again.' );
		$this->assertSame( SiteCheckAfter::ALREADY_STARTED, $this->after->open( $id, 7, self::LOGIN )['code'] );
	}

	/**
	 * Genuinely failed plugin updates.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function provide_failed_updates() {
		return array(
			'plugin download fails' => array( 'download_failed', 'update_not_installed' ),
			'invalid archive'       => array( 'incompatible_archive_no_plugins', 'incompatible_archive_no_plugins' ),
		);
	}

	/**
	 * A failed update gives the page nothing to send, and a follow-up sent
	 * anyway cannot turn it into a successful analysis.
	 *
	 * @dataProvider provide_failed_updates
	 *
	 * @param string $outcome Install outcome.
	 * @param string $error   Stored error code.
	 */
	public function test_failed_update_gives_the_page_nothing( $outcome, $error ) {
		$this->baselines();

		$this->update_page( array( self::PLUGIN => $outcome ) );

		$this->assertSame( array(), self::page_data() );
		$row = array_values( $this->site->repository->rows )[0];
		$this->assertSame( array( AnalysisStatus::FAILED, $error ), array( $row['status'], $row['error_code'] ) );

		$follow = $this->follow_up( array( self::PLUGIN ) );
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_PENDING ), $follow['results'] );
		$this->assertSame( AnalysisStatus::FAILED, $this->site->repository->rows[ (int) $row['id'] ]['status'] );
		$this->assertSame( SiteCheckAfter::NOT_ELIGIBLE, $this->after->open( (int) $row['id'], 7, self::LOGIN )['code'] );
	}

	/**
	 * An update of several plugins in one request is not analysed (outside
	 * P1): no analysis, nothing for the page to send.
	 */
	public function test_multi_plugin_update_gives_the_page_nothing() {
		$this->update_page(
			array(
				self::PLUGIN => 'installed',
				self::OTHER  => 'installed',
			)
		);

		$this->assertSame( array(), $this->site->repository->rows );
		$this->assertSame( array(), self::page_data() );
	}

	/**
	 * Pages other than Dashboard → Updates' update page.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function provide_other_pages() {
		return array(
			'Installed Plugins "Update now" (Ajax)' => array( 'admin-ajax.php', '' ),
			'single update without JavaScript'      => array( 'update.php', 'upgrade-plugin' ),
			'theme updates'                         => array( 'update.php', 'update-selected-themes' ),
		);
	}

	/**
	 * Other pages never get page data; their analyses stay for their own
	 * follow-up (Installed Plugins), a later page load or expiry.
	 *
	 * @dataProvider provide_other_pages
	 *
	 * @param string $screen `$pagenow`.
	 * @param string $action `action` query value.
	 */
	public function test_other_pages_get_nothing( $screen, $action ) {
		$GLOBALS['pagenow'] = $screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stands in for WordPress's own value on that page.
		$_GET['action']     = $action;

		$this->update_page( array( self::PLUGIN => 'installed' ) );

		$this->assertSame( array(), self::page_data() );
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, array_values( $this->site->repository->rows )[0]['status'] );
	}

	/**
	 * Without the follow-up script on the page (no `update_plugins`, no
	 * build), or once it was printed, nothing is added.
	 */
	public function test_missing_or_printed_script_gets_nothing() {
		$GLOBALS['updatelens_test_scripts']['enqueued'] = array();
		$this->update_page( array( self::PLUGIN => 'installed' ) );
		$this->assertSame( array(), self::page_data() );

		$GLOBALS['updatelens_test_scripts']['enqueued'] = array( AdminAssets::FOLLOW_UP_HANDLE );
		$GLOBALS['updatelens_test_scripts']['done']     = array( AdminAssets::FOLLOW_UP_HANDLE );
		$this->assertFalse( AdminAssets::add_update_page_follow_up( array( self::PLUGIN ) ) );
		$this->assertSame( array(), self::page_data() );
	}
}
