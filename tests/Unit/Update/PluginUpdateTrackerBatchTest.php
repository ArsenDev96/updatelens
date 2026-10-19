<?php
/**
 * Tests for multi-plugin updates through the tracker's hooks (0.4.0 P2).
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
use UpdateLens\Update\PluginUpdateAnalyzer;
use UpdateLens\Update\PluginUpdateTracker;
use UpdateLens\Update\SettleOutcome;
use UpdateLens\Update\SiteCheckAfter;
use UpdateLens\Update\SiteCheckObservation;

/**
 * `bulk_upgrade()` with several plugins (Dashboard → Updates with several
 * plugins selected) through the tracker's hooks in core's order, with the
 * real analyzer, ledger and AFTER side. Observed per plugin only with the
 * internal switch (PluginUpdateTracker::BATCH_ITEMS_CONSTANT); without it,
 * nothing changes for these updates.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PluginUpdateTrackerBatchTest extends TestCase {

	const A     = 'alpha/alpha.php';
	const B     = 'beta/beta.php';
	const C     = 'gamma/gamma.php';
	const SELF  = 'updatelens/updatelens.php';
	const LOGIN = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	const T     = 1767225600; // 2026-01-01T00:00:00Z.

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
	 * Load the stand-ins and build the site: Dashboard → Updates' update page
	 * with the follow-up script enqueued, and fresh BEFORE baselines.
	 *
	 * @before
	 */
	public function set_up_site() {
		require dirname( __DIR__, 2 ) . '/fixtures/wpdb-recorder.php';
		require_once dirname( __DIR__, 2 ) . '/fixtures/wp-tracker-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/fixtures/wp-upgrader-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/fixtures/wp-page-follow-up-stubs.php';

		$this->site      = new FakeSite( array( 'blog' => array( 'Example', 'yes' ) ), self::T );
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
		foreach ( array( self::A, self::B, self::C, self::SELF ) as $plugin ) {
			$this->plugin_version( $plugin, '1.0.0' );
		}

		$GLOBALS['pagenow']                             = 'update.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stands in for WordPress's own value on the update page.
		$_GET['action']                                 = 'update-selected';
		$GLOBALS['updatelens_test_scripts']['enqueued'] = array( AdminAssets::FOLLOW_UP_HANDLE );

		$this->baselines();
	}

	/**
	 * Remove the plugin files and the closures.
	 *
	 * @after
	 */
	public function remove_plugins() {
		$this->site      = null;
		$this->activity  = null;
		$this->baselines = null;
		$this->after     = null;

		foreach ( array( self::A, self::B, self::C, self::SELF ) as $plugin ) {
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
	 * A plugin's header on disk.
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
	 * Both BEFORE baselines of this administrator, shortly before the update:
	 * a single-plugin update would get them attached.
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
	 * The update page through the tracker's hooks, in core's order.
	 *
	 * @param array<string, string> $outcomes    Plugin => `installed`, `download_failed` (no install result)
	 *                                           or an install error code.
	 * @param bool|null             $batch_items Tracker switch (null: from the constant).
	 * @param bool                  $completes   Whether bulk_upgrade() reaches its end (else the request
	 *                                           ends without `upgrader_process_complete`).
	 * @return void
	 */
	private function update_page( array $outcomes, $batch_items = true, $completes = true ) {
		$tracker  = new PluginUpdateTracker( $this->site->request(), $this->activity, $this->after, $batch_items );
		$upgrader = new \Plugin_Upgrader();

		$upgrader->bulk         = true;
		$upgrader->update_count = count( $outcomes );
		foreach ( $outcomes as $plugin => $outcome ) {
			$this->assertFalse( $tracker->on_pre_download( false, 'https://downloads.example/package.zip', $upgrader, array( 'plugin' => $plugin ) ), 'The download is not short-circuited.' );
			$this->site->time += 1;
			if ( 'installed' === $outcome ) {
				$this->plugin_version( $plugin, '1.1.0' );
				$this->site->options[ dirname( $plugin ) . '_db' ] = array( '1.1.0', 'off' );
				$installed = array( 'destination_name' => dirname( $plugin ) );
				$this->assertSame( $installed, $tracker->on_install_package_result( $installed, array( 'plugin' => $plugin ) ), 'The install result is returned unchanged.' );
			} elseif ( 'download_failed' !== $outcome ) {
				$error = new \WP_Error( $outcome );
				$this->assertSame( $error, $tracker->on_install_package_result( $error, array( 'plugin' => $plugin ) ) );
			}
		}
		if ( $completes ) {
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
		}
		$this->site->time += 1;
		$tracker->on_shutdown();
	}

	/**
	 * Analyses by plugin.
	 *
	 * @return array<string, array>
	 */
	private function rows() {
		$rows = array();
		foreach ( $this->site->repository->rows as $row ) {
			$rows[ $row['plugin_file'] ] = $row;
		}

		return $rows;
	}

	/**
	 * What the update page received from UpdateLens.
	 *
	 * @return array
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
	 * As released (switch off): a multi-plugin update is not analysed at all,
	 * but still moves the ledger once for the request.
	 */
	public function test_switch_off_changes_nothing() {
		$generation = $this->activity->quiet_generation();
		$this->update_page(
			array(
				self::A => 'installed',
				self::B => 'installed',
			),
			null
		);

		$this->assertSame( array(), $this->site->repository->rows );
		$this->assertSame( 0, $this->site->captures );
		$this->assertSame( array(), self::page_data() );
		$this->assertSame( UpdateActivity::advance( $generation, 2 ), $this->activity->quiet_generation() );
	}

	/**
	 * The constant turns the switch on.
	 */
	public function test_constant_turns_the_switch_on() {
		$this->assertSame( 'UPDATELENS_INTERNAL_BATCH_ITEMS', PluginUpdateTracker::BATCH_ITEMS_CONSTANT );
		define( 'UPDATELENS_INTERNAL_BATCH_ITEMS', true );
		$this->update_page(
			array(
				self::A => 'installed',
				self::B => 'installed',
			),
			null
		);

		$this->assertCount( 2, $this->site->repository->rows );
	}

	/**
	 * Three plugins, one failing: per-plugin captures at their own install
	 * results (N + 1 captures), the failure recorded with WordPress's code,
	 * the others closed with the batch. No website checks are attached, the
	 * update page gets no follow-up (no single plugin to complete), and the
	 * request moves the ledger exactly twice.
	 */
	public function test_three_plugins_one_failing() {
		$generation = $this->activity->quiet_generation();
		$this->update_page(
			array(
				self::A => 'installed',
				self::B => 'incompatible_archive',
				self::C => 'installed',
			)
		);

		$rows = $this->rows();
		$this->assertCount( 3, $rows );
		$this->assertSame( 4, $this->site->captures, 'Before A, after A (handed on to B), a fresh one before C (B failed: nothing to hand on), after C.' );
		foreach ( array( self::A, self::C ) as $plugin ) {
			$this->assertSame( AnalysisStatus::COMPLETED, $rows[ $plugin ]['status'], $plugin );
			$this->assertSame( SettleOutcome::BATCH, $rows[ $plugin ]['settle_outcome'], $plugin );
			$this->assertSame( '1.1.0', $rows[ $plugin ]['version_after'], $plugin );
			$this->assertStringContainsString( dirname( $plugin ) . '_db', $rows[ $plugin ]['options_during_update_diff'], $plugin );
		}
		$this->assertStringNotContainsString( 'alpha_db', $rows[ self::C ]['options_during_update_diff'], 'Each plugin only its own interval.' );
		$this->assertSame( AnalysisStatus::FAILED, $rows[ self::B ]['status'] );
		$this->assertSame( 'incompatible_archive', $rows[ self::B ]['error_code'] );

		foreach ( $rows as $plugin => $row ) {
			$this->assertNull( $row['site_check_home_before'], $plugin );
			$this->assertNull( $row['site_check_home_before_reason'], "{$plugin}: no baseline attached to a plugin of a batch." );
			$this->assertNull( $row['site_check_update_started_at'], $plugin );
			$this->assertFalse( $this->after->wanted( (int) $row['id'] ), "{$plugin}: no website check after a plugin of a batch." );
		}
		$this->assertSame( array(), self::page_data(), 'Nothing for the update page to follow up.' );
		$this->assertSame( UpdateActivity::advance( $generation, 2 ), $this->activity->quiet_generation(), 'One request, however many plugins.' );
	}

	/**
	 * A download that fails (no install result) is not handed on; the plugin
	 * fails `update_not_installed` when the batch ends.
	 */
	public function test_download_failure() {
		$this->update_page(
			array(
				self::A => 'installed',
				self::B => 'download_failed',
				self::C => 'installed',
			)
		);

		$rows = $this->rows();
		$this->assertSame( AnalysisStatus::FAILED, $rows[ self::B ]['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_UPDATE_NOT_INSTALLED, $rows[ self::B ]['error_code'] );
		$this->assertSame( SettleOutcome::BATCH, $rows[ self::A ]['settle_outcome'] );
		$this->assertSame( SettleOutcome::BATCH, $rows[ self::C ]['settle_outcome'] );
	}

	/**
	 * The request ends before bulk_upgrade() reports its end: installed
	 * plugins keep their During update (`batch_incomplete`), the rest fails.
	 */
	public function test_interrupted_update() {
		$this->update_page(
			array(
				self::A => 'installed',
				self::B => 'download_failed',
			),
			true,
			false
		);

		$rows = $this->rows();
		$this->assertSame( array( AnalysisStatus::COMPLETED, SettleOutcome::BATCH_INCOMPLETE ), array( $rows[ self::A ]['status'], $rows[ self::A ]['settle_outcome'] ) );
		$this->assertSame( array( AnalysisStatus::FAILED, PluginUpdateAnalyzer::ERROR_UPDATE_NOT_COMPLETED ), array( $rows[ self::B ]['status'], $rows[ self::B ]['error_code'] ) );
	}

	/**
	 * UpdateLens itself in the batch: what came before it closes as
	 * `batch_incomplete`; nothing after it is analysed.
	 */
	public function test_updatelens_in_the_batch() {
		$this->update_page(
			array(
				self::A    => 'installed',
				self::SELF => 'installed',
				self::C    => 'installed',
			)
		);

		$rows = $this->rows();
		$this->assertSame( array( self::A ), array_keys( $rows ) );
		$this->assertSame( SettleOutcome::BATCH_INCOMPLETE, $rows[ self::A ]['settle_outcome'] );
	}

	/**
	 * With the switch on, a one-plugin update from the same page keeps P1's
	 * behavior: analysed as a single-plugin update, handed to the page's
	 * follow-up, BEFORE baselines attached.
	 */
	public function test_single_plugin_update_unchanged() {
		$this->update_page( array( self::A => 'installed' ) );

		$rows = $this->rows();
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $rows[ self::A ]['status'] );
		$this->assertNotNull( $rows[ self::A ]['site_check_update_started_at'] );
		$this->assertSame(
			array( array( AdminAssets::FOLLOW_UP_HANDLE, AdminAssets::FOLLOW_UP_PAGE_SETTINGS, array( 'plugins' => array( self::A ) ) ) ),
			self::page_data()
		);
	}
}
