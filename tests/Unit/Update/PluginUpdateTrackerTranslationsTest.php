<?php
/**
 * Tests for translation updates inside a plugin update request.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
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
 * "Update now" runs the plugin update and then, at `upgrader_process_complete`
 * priority 20 (Language_Pack_Upgrader::async_upgrade(), before UpdateLens's
 * PHP_INT_MAX), every pending translation update in the same request. A
 * translation download that fails (e.g. HTTP 401 from a licence server) makes
 * WordPress answer the update with `success: false` although the plugin was
 * installed. The tracker's hooks, in core's order, with the real analyzer,
 * ledger and AFTER side: the translation never creates, abandons or fails the
 * plugin's analysis, the ledger moves exactly twice, and the browser's
 * follow-up (sent for error answers too) settles the analysis and lets its
 * AFTER checks start. A genuinely failed plugin update stays failed: the
 * follow-up answers `not_pending` and no AFTER check starts.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PluginUpdateTrackerTranslationsTest extends TestCase {

	const PLUGIN = 'acme/acme.php';
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
	 * Load the stand-ins and build the site.
	 *
	 * @before
	 */
	public function set_up_site() {
		require dirname( __DIR__, 2 ) . '/fixtures/wpdb-recorder.php';
		require_once dirname( __DIR__, 2 ) . '/fixtures/wp-tracker-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/fixtures/wp-upgrader-stubs.php';

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
		$this->plugin_version( '1.0.0' );
	}

	/**
	 * Remove the plugin files and the closures (a failure in a separate
	 * process is serialized with the test object).
	 *
	 * @after
	 */
	public function remove_plugin() {
		$this->site      = null;
		$this->activity  = null;
		$this->baselines = null;
		$this->after     = null;

		$file = WP_PLUGIN_DIR . '/' . self::PLUGIN;
		if ( is_file( $file ) ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture file.
			rmdir( dirname( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture directory.
			rmdir( WP_PLUGIN_DIR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture directory.
		}
	}

	/**
	 * The plugin's header on disk (what the update installs).
	 *
	 * @param string $version Version.
	 * @return void
	 */
	private function plugin_version( $version ) {
		$file = WP_PLUGIN_DIR . '/' . self::PLUGIN;
		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture directory.
		}
		file_put_contents( $file, "<?php\n/**\n * Plugin Name: Acme\n * Version: {$version}\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture file.
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
	 * One "Update now" request through the tracker's hooks, in core's order:
	 * the plugin's download and install, then the pending translation updates
	 * (async_upgrade() at priority 20, with their own downloads and their own
	 * `upgrader_process_complete`), then UpdateLens's completion hook
	 * (PHP_INT_MAX) and the request's shutdown.
	 *
	 * @param string $plugin      `installed`, `download_failed` (no install result) or an install error code.
	 * @param string $translation `installed` or `download_failed`.
	 * @return void
	 */
	private function update_now( $plugin, $translation ) {
		$tracker  = new PluginUpdateTracker( $this->site->request(), $this->activity, $this->after );
		$upgrader = new \Plugin_Upgrader();

		$upgrader->bulk         = true;
		$upgrader->update_count = 1;
		$this->assertFalse( $tracker->on_pre_download( false, 'https://downloads.example/acme.1.1.0.zip', $upgrader, array( 'plugin' => self::PLUGIN ) ), 'The plugin download is not short-circuited.' );
		$this->site->time += 1;
		if ( 'installed' === $plugin ) {
			$this->plugin_version( '1.1.0' );
			$installed = array( 'destination_name' => 'acme' );
			$this->assertSame( $installed, $tracker->on_install_package_result( $installed, array( 'plugin' => self::PLUGIN ) ) );
		} elseif ( 'download_failed' !== $plugin ) {
			$error = new \WP_Error( $plugin );
			$this->assertSame( $error, $tracker->on_install_package_result( $error, array( 'plugin' => self::PLUGIN ) ) );
		}

		// Language_Pack_Upgrader::async_upgrade(): the same request, before UpdateLens's completion hook.
		$language       = new \Language_Pack_Upgrader();
		$language->bulk = true;
		$extra          = array(
			'language_update_type' => 'plugin',
			'language_update'      => (object) array(
				'type'     => 'plugin',
				'slug'     => 'acme',
				'language' => 'he_IL',
			),
		);
		$this->assertFalse( $tracker->on_pre_download( false, 'https://translations.example/acme-he_IL.zip', $language, $extra ), 'The translation download is not short-circuited.' );
		if ( 'installed' === $translation ) {
			$files = array( 'destination_name' => 'plugins' );
			$this->assertSame( $files, $tracker->on_install_package_result( $files, $extra ) );
		}
		$tracker->on_process_complete(
			$language,
			array(
				'action'       => 'update',
				'type'         => 'translation',
				'bulk'         => true,
				'translations' => array(
					array(
						'language' => 'he_IL',
						'type'     => 'plugin',
						'slug'     => 'acme',
						'version'  => '1.1.0',
					),
				),
			)
		);

		$tracker->on_process_complete(
			$upgrader,
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'bulk'    => true,
				'plugins' => array( self::PLUGIN ),
			)
		);
		$this->site->time += 1;
		$tracker->on_shutdown();
	}

	/**
	 * The browser's follow-up request a second later (also after an error answer).
	 *
	 * @return array{results: array<string, string>, settled: array<string, int>}
	 */
	private function follow_up() {
		$this->site->time += 1;
		$settled           = array();
		$results           = $this->site->request()->settle_follow_up( array( self::PLUGIN ), 7, array(), $settled );

		return array(
			'results' => $results,
			'settled' => $settled,
		);
	}

	/**
	 * The only analysis row.
	 *
	 * @return array
	 */
	private function only_row() {
		$this->assertCount( 1, $this->site->repository->rows, 'One analysis: translations never create one.' );

		return array_values( $this->site->repository->rows )[0];
	}

	/**
	 * Translation outcomes after an installed plugin.
	 *
	 * @return array<string, array{string}>
	 */
	public function provide_translations() {
		return array(
			'translation download fails (HTTP 401)' => array( 'download_failed' ),
			'translation installs'                  => array( 'installed' ),
		);
	}

	/**
	 * The plugin installed; whatever happened to the translation, the
	 * analysis waits for its settle, the follow-up settles it and its AFTER
	 * checks can start.
	 *
	 * @dataProvider provide_translations
	 *
	 * @param string $translation Translation outcome.
	 */
	public function test_installed_plugin_with_translation_update( $translation ) {
		$this->baselines();
		$generation = $this->activity->quiet_generation();

		$this->update_now( 'installed', $translation );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $row['status'] );
		$this->assertNull( $row['error_code'] );
		$this->assertSame( '1.1.0', $row['version_after'], 'The installed version is recorded.' );
		$this->assertSame( UpdateActivity::advance( $generation, 2 ), $this->activity->quiet_generation(), 'One update request, translations included, moves the ledger exactly twice.' );

		$follow = $this->follow_up();
		$id     = (int) $row['id'];
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::SETTLED ), $follow['results'] );
		$this->assertSame( array( self::PLUGIN => $id ), $follow['settled'] );
		$row = $this->only_row();
		$this->assertSame( array( AnalysisStatus::COMPLETED, SettleOutcome::FOLLOW_UP ), array( $row['status'], $row['settle_outcome'] ) );

		$this->assertTrue( $this->after->wanted( $id ) );
		$opened = $this->after->open( $id, 7, self::LOGIN );
		$this->assertSame( SiteCheckAfter::OPEN, $opened['code'] );
		$this->assertSame( array( 'home', 'rest' ), $opened['checks'] );
	}

	/**
	 * Genuinely failed plugin updates (download failed, invalid archive).
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
	 * A plugin update that failed stays failed: the browser lists the plugin
	 * (WordPress answered with an error), the server answers `not_pending`,
	 * and no AFTER check can start.
	 *
	 * @dataProvider provide_failed_updates
	 *
	 * @param string $plugin Plugin outcome.
	 * @param string $error  Stored error code.
	 */
	public function test_failed_plugin_update( $plugin, $error ) {
		$this->baselines();
		$generation = $this->activity->quiet_generation();

		$this->update_now( $plugin, 'download_failed' );

		$row = $this->only_row();
		$this->assertSame( array( AnalysisStatus::FAILED, $error, null ), array( $row['status'], $row['error_code'], $row['version_after'] ) );
		$this->assertSame( UpdateActivity::advance( $generation, 2 ), $this->activity->quiet_generation() );

		$follow = $this->follow_up();
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_PENDING ), $follow['results'] );
		$this->assertSame( array(), $follow['settled'], 'No analysis named: no AFTER run.' );
		$this->assertSame( AnalysisStatus::FAILED, $this->only_row()['status'], 'Unchanged.' );

		$id = (int) $row['id'];
		$this->assertFalse( $this->after->wanted( $id ) );
		$this->assertSame( SiteCheckAfter::NOT_ELIGIBLE, $this->after->open( $id, 7, self::LOGIN )['code'] );
	}
}
