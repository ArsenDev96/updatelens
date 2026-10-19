<?php
/**
 * Tests for the per-plugin observations of multi-plugin updates (0.4.0 P2).
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use UpdateLens\Report\AnalysisReadModel;
use UpdateLens\Snapshot\AutoloadPolicy;
use UpdateLens\Snapshot\OptionNoiseFilter;
use UpdateLens\Snapshot\OptionsSnapshotBuilder;
use UpdateLens\Snapshot\OptionValueHasher;
use UpdateLens\Storage\OptionsSnapshotCodec;
use UpdateLens\Tests\Support\ActionSchedulerFixture;
use UpdateLens\Tests\Support\CronFixture;
use UpdateLens\Tests\Support\FakeSite;
use UpdateLens\Update\ActionSchedulerPhaseReason;
use UpdateLens\Update\AnalysisStatus;
use UpdateLens\Update\CronPhaseReason;
use UpdateLens\Update\FollowUpRequest;
use UpdateLens\Update\ObservationBudget;
use UpdateLens\Update\PluginUpdateAnalyzer;
use UpdateLens\Update\SettleOutcome;

/**
 * `bulk_upgrade()` with several plugins runs in one request: per plugin
 * `upgrader_pre_download` → `upgrader_install_package_result`, then one
 * `upgrader_process_complete` for the batch. Each plugin's During update
 * runs from the capture right before its download to the capture right
 * after its install; the next plugin starts from that capture (handoff).
 * Plugins stay `captured` until the batch ends and then close with outcome
 * `batch`: their After update and Net result belong to the whole batch.
 *
 * Each PluginUpdateAnalyzer is one PHP request; FakeSite's options, WP-Cron,
 * Action Scheduler, clock and repository persist across requests.
 */
final class BatchItemLifecycleTest extends TestCase {

	const A = 'alpha/alpha.php';
	const B = 'beta/beta.php';
	const C = 'gamma/gamma.php';
	const D = 'delta/delta.php';

	const BATCH = 'upgrader-1';

	/**
	 * Start of the scenario (2027-01-15 08:00:00 UTC).
	 */
	const T0 = 1800000000;

	/**
	 * Fake site.
	 *
	 * @var FakeSite
	 */
	private $site;

	/**
	 * Fresh site.
	 *
	 * @before
	 */
	public function set_up_site() {
		$this->site = new FakeSite(
			array(
				'siteurl' => array( 'https://example.test', 'on' ),
				'blog'    => array( 'Example', 'on' ),
			),
			self::T0
		);
	}

	/**
	 * A download of the batch (`upgrader_pre_download` of one plugin).
	 *
	 * @param PluginUpdateAnalyzer $request Request.
	 * @param string|null          $plugin  Plugin, or null for UpdateLens itself.
	 * @param string               $batch   Batch key.
	 * @return void
	 */
	private function download( PluginUpdateAnalyzer $request, $plugin, $batch = self::BATCH ) {
		$request->update_starting(
			$plugin,
			null === $plugin ? array() : array(
				'name'    => ucfirst( dirname( $plugin ) ),
				'version' => '1.0.0',
			),
			7,
			null,
			$batch
		);
	}

	/**
	 * A plugin's package installs: its files are replaced, and the new code's
	 * effect (an option it writes) appears while WordPress still runs the old code.
	 *
	 * @param PluginUpdateAnalyzer $request Request.
	 * @param string               $plugin  Plugin.
	 * @return bool What batch_item_installed() returned.
	 */
	private function install( PluginUpdateAnalyzer $request, $plugin ) {
		$this->site->options[ dirname( $plugin ) . '_installed' ] = array( '2.0.0', 'off' );
		$this->site->time                                        += 1;

		return $request->batch_item_installed( $plugin, null, '2.0.0' );
	}

	/**
	 * The analysis of a plugin (the newest, if several).
	 *
	 * @param string $plugin Plugin.
	 * @return array
	 */
	private function row( $plugin ) {
		$found = null;
		foreach ( $this->site->repository->rows as $row ) {
			if ( $plugin === $row['plugin_file'] ) {
				$found = $row;
			}
		}
		$this->assertNotNull( $found, "An analysis of {$plugin}." );

		return $found;
	}

	/**
	 * Option names of a stored options diff, all sections together.
	 *
	 * @param array  $row    Analysis.
	 * @param string $column Diff column.
	 * @return string[]
	 */
	private static function changed_options( array $row, $column = 'options_during_update_diff' ) {
		$diff  = json_decode( (string) $row[ $column ], true );
		$names = array();
		foreach ( array( 'added', 'removed', 'changed' ) as $section ) {
			$names = array_merge( $names, array_column( $diff[ $section ], 'name' ) );
		}
		sort( $names );

		return $names;
	}

	/**
	 * Assert a plugin closed with the batch: its During update kept, After
	 * update and Net result left to the batch, temporary columns cleared.
	 *
	 * @param string $plugin  Plugin.
	 * @param string $outcome Expected outcome.
	 * @return array The row.
	 */
	private function assert_closed_with_batch( $plugin, $outcome = SettleOutcome::BATCH ) {
		$row = $this->row( $plugin );
		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'], $plugin );
		$this->assertSame( $outcome, $row['settle_outcome'], $plugin );
		$this->assertNull( $row['error_code'], $plugin );
		$this->assertSame( '2.0.0', $row['version_after'], $plugin );
		$this->assertNull( $row['active_plugin'], $plugin );
		$this->assertIsString( $row['options_during_update_diff'], $plugin );
		$this->assertIsString( $row['cron_during_update_diff'], $plugin );
		$this->assertNotNull( $row['immediate_captured_at'], $plugin );
		foreach ( array( 'options_post_update_diff', 'options_final_diff', 'cron_post_update_diff', 'cron_final_diff', 'action_scheduler_post_update_diff', 'action_scheduler_final_diff' ) as $column ) {
			$this->assertNull( $row[ $column ], "{$plugin}: {$column} belongs to the batch." );
		}
		$this->assertSame( CronPhaseReason::BATCH_UPDATE, $row['cron_post_update_reason'], $plugin );
		$this->assertSame( CronPhaseReason::BATCH_UPDATE, $row['cron_final_reason'], $plugin );
		$this->assertSame( ActionSchedulerPhaseReason::BATCH_UPDATE, $row['action_scheduler_post_update_reason'], $plugin );
		$this->assertSame( ActionSchedulerPhaseReason::BATCH_UPDATE, $row['action_scheduler_final_reason'], $plugin );
		foreach ( array( 'options_before_snapshot', 'options_immediate_snapshot', 'cron_before_snapshot', 'cron_immediate_snapshot', 'action_scheduler_before_snapshot', 'action_scheduler_immediate_snapshot' ) as $column ) {
			$this->assertNull( $row[ $column ], "{$plugin}: {$column} is temporary." );
		}
		$this->assertNull( $row['settle_deadline'], $plugin );
		$this->assertNull( $row['site_check_update_started_at'], "{$plugin}: no website checks for a plugin of a batch." );

		return $row;
	}

	/**
	 * Two plugins: three captures (before the first, after each install), each
	 * plugin's During update holds only what changed in its own interval, and
	 * starting the second does not close, settle or abandon the first.
	 */
	public function test_two_plugins_in_sequence() {
		$request = $this->site->request();

		$this->download( $request, self::A );
		$this->assertSame( 1, $this->site->captures, 'One capture before the first plugin.' );
		$this->assertSame( AnalysisStatus::CAPTURED, $this->row( self::A )['status'] );
		$this->assertTrue( $this->install( $request, self::A ) );

		$a = $this->row( self::A );
		$this->assertSame( AnalysisStatus::CAPTURED, $a['status'], 'Still part of a running batch.' );
		$this->assertSame( array( 'alpha_installed' ), self::changed_options( $a ) );
		$this->assertNull( $a['options_before_snapshot'], 'Its BEFORE is no longer needed.' );
		$this->assertNull( $a['options_immediate_snapshot'], 'No IMMEDIATE snapshot is stored for a plugin of a batch.' );

		// Between the two plugins (e.g. another plugin's `upgrader_pre_download` callback for the next one).
		$this->site->options['between'] = array( 'x', 'off' );
		$this->download( $request, self::B );
		$this->assertSame( 2, $this->site->captures, 'The second plugin starts from the first one\'s IMMEDIATE capture.' );
		$this->assertSame( $a, $this->row( self::A ), 'Starting the next plugin leaves the first one untouched.' );

		$this->assertTrue( $this->install( $request, self::B ) );
		$this->assertSame( 3, $this->site->captures );
		$this->assertSame( array( 'beta_installed', 'between' ), self::changed_options( $this->row( self::B ) ), 'Observed in the interval in which it happened.' );
		$this->assertSame( $a, $this->row( self::A ), 'A later capture never changes an earlier plugin.' );

		$request->batch_finished();
		$this->assertSame( 3, $this->site->captures, 'The batch end captures nothing (no batch record yet).' );
		$this->assertSame( array( 'alpha_installed' ), self::changed_options( $this->assert_closed_with_batch( self::A ) ) );
		$this->assert_closed_with_batch( self::B );

		$request->request_ending( false );
		$this->assertSame( SettleOutcome::BATCH, $this->row( self::A )['settle_outcome'], 'Shutdown leaves closed plugins alone.' );
		$this->assertCount( 2, $this->site->repository->rows );
	}

	/**
	 * Three plugins: N + 1 captures, a chain of handoffs, and WP-Cron and
	 * Action Scheduler follow the same intervals as options.
	 */
	public function test_three_plugins_in_sequence() {
		$this->site->cron             = CronFixture::cron( array( CronFixture::recurring( self::T0 + 60, 'site_job', 'hourly' ) ) );
		$this->site->action_scheduler = array( ActionSchedulerFixture::single( 'site_action', self::T0 + 60 ) );
		$request                      = $this->site->request();

		$this->download( $request, self::A );
		$this->site->cron = CronFixture::cron(
			array(
				CronFixture::recurring( self::T0 + 60, 'site_job', 'hourly' ),
				CronFixture::single( self::T0 + 120, 'alpha_job' ),
			)
		);
		$this->install( $request, self::A );

		$this->download( $request, self::B );
		$this->site->action_scheduler[] = ActionSchedulerFixture::single( 'beta_action', self::T0 + 180 );
		$this->install( $request, self::B );

		$this->download( $request, self::C );
		$this->install( $request, self::C );
		$request->batch_finished();

		$this->assertSame( 4, $this->site->captures, 'Three plugins: four captures.' );
		$this->assertSame( 4, $this->site->cron_captures );
		$this->assertSame( 4, $this->site->action_scheduler_captures );

		$a = $this->assert_closed_with_batch( self::A );
		$b = $this->assert_closed_with_batch( self::B );
		$c = $this->assert_closed_with_batch( self::C );
		$this->assertSame( array( 'alpha_installed' ), self::changed_options( $a ) );
		$this->assertSame( array( 'beta_installed' ), self::changed_options( $b ) );
		$this->assertSame( array( 'gamma_installed' ), self::changed_options( $c ) );

		$this->assertStringContainsString( 'alpha_job', $a['cron_during_update_diff'] );
		$this->assertStringNotContainsString( 'alpha_job', $b['cron_during_update_diff'], 'The second plugin starts from the state after the first.' );
		$this->assertStringNotContainsString( 'alpha_job', $c['cron_during_update_diff'] );
		$this->assertIsString( $b['action_scheduler_during_update_diff'] );
		$this->assertStringContainsString( 'beta_action', $b['action_scheduler_during_update_diff'] );
		$this->assertStringNotContainsString( 'beta_action', $a['action_scheduler_during_update_diff'] );
		$this->assertStringNotContainsString( 'beta_action', $c['action_scheduler_during_update_diff'] );
	}

	/**
	 * Without Action Scheduler the absence is handed on like a snapshot: every
	 * plugin's During update reads "not installed throughout".
	 */
	public function test_absent_action_scheduler_is_handed_on() {
		$request = $this->site->request();
		$this->download( $request, self::A );
		$this->install( $request, self::A );
		$this->download( $request, self::B );
		$this->install( $request, self::B );
		$request->batch_finished();

		foreach ( array( self::A, self::B ) as $plugin ) {
			$row = $this->row( $plugin );
			$this->assertSame( ActionSchedulerPhaseReason::NOT_INSTALLED_THROUGHOUT, $row['action_scheduler_during_update_reason'], $plugin );
			$this->assertNull( $row['action_scheduler_during_update_diff'], $plugin );
		}
	}

	/**
	 * A plugin whose install failed ends `failed` with WordPress's code at once;
	 * the next plugin has no capture to start from, so it takes its own.
	 */
	public function test_failed_plugin_followed_by_another() {
		$request = $this->site->request();
		$this->download( $request, self::A );
		$this->assertFalse( $request->batch_item_installed( self::A, 'incompatible_archive', null ) );

		$a = $this->row( self::A );
		$this->assertSame( AnalysisStatus::FAILED, $a['status'] );
		$this->assertSame( 'incompatible_archive', $a['error_code'] );
		$this->assertNull( $a['options_during_update_diff'] );
		$this->assertSame( CronPhaseReason::UPDATE_FAILED, $a['cron_during_update_reason'] );
		$this->assertSame( 1, $this->site->captures, 'A failed install captures nothing.' );

		$this->site->options['restored'] = array( 'x', 'off' );
		$this->download( $request, self::B );
		$this->assertSame( 2, $this->site->captures, 'A fresh capture: nothing to hand on.' );
		$this->install( $request, self::B );
		$request->batch_finished();

		$this->assertSame( array( 'beta_installed' ), self::changed_options( $this->assert_closed_with_batch( self::B ) ), 'What happened before its own BEFORE is not attributed to it.' );
		$this->assertSame( 'incompatible_archive', $this->row( self::A )['error_code'], 'The batch end leaves a failed plugin alone.' );
	}

	/**
	 * A download or unpack error has no install result: that plugin fails
	 * `update_not_installed` when the batch ends; the next one takes its own capture.
	 */
	public function test_plugin_without_install_result() {
		$request = $this->site->request();
		$this->download( $request, self::A );
		$this->download( $request, self::B ); // A's download failed: no install result.
		$this->assertSame( 2, $this->site->captures );
		$this->assertSame( AnalysisStatus::CAPTURED, $this->row( self::A )['status'], 'Starting the next plugin does not end it.' );

		$this->install( $request, self::B );
		$request->batch_finished();

		$a = $this->row( self::A );
		$this->assertSame( AnalysisStatus::FAILED, $a['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_UPDATE_NOT_INSTALLED, $a['error_code'] );
		$this->assertNull( $a['active_plugin'] );
		$this->assertNull( $a['options_before_snapshot'] );
		$this->assert_closed_with_batch( self::B );
	}

	/**
	 * A batch whose end is never reported (fatal error, timeout): at shutdown
	 * installed plugins close as `batch_incomplete` with their During update,
	 * the one still downloading fails, and nothing is captured.
	 */
	public function test_interrupted_batch() {
		$request = $this->site->request();
		$this->download( $request, self::A );
		$this->install( $request, self::A );
		$this->download( $request, self::B );
		$captures = $this->site->captures;

		$request->request_ending( false );

		$this->assertSame( $captures, $this->site->captures, 'Nothing is captured at shutdown.' );
		$this->assertSame( array( 'alpha_installed' ), self::changed_options( $this->assert_closed_with_batch( self::A, SettleOutcome::BATCH_INCOMPLETE ) ) );
		$b = $this->row( self::B );
		$this->assertSame( AnalysisStatus::FAILED, $b['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_UPDATE_NOT_COMPLETED, $b['error_code'] );
		$this->assertNull( $b['options_before_snapshot'] );
		$this->assertNull( $b['active_plugin'] );
	}

	/**
	 * Interrupted between two plugins, a later admin page cannot settle the
	 * installed plugin as if it had been updated alone.
	 */
	public function test_interrupted_batch_is_not_settled_later() {
		$request = $this->site->request();
		$this->download( $request, self::A );
		$this->install( $request, self::A );
		$request->request_ending( false );

		$this->site->time += 10;
		$this->site->admin_page();
		$this->site->request()->expire_overdue();

		$a = $this->row( self::A );
		$this->assertSame( SettleOutcome::BATCH_INCOMPLETE, $a['settle_outcome'] );
		$this->assertNull( $a['options_post_update_diff'] );
	}

	/**
	 * While the batch runs, other requests (another tab's admin page, a
	 * follow-up, a report read) leave its installed plugins alone: they are
	 * still `captured`, never awaiting a settled snapshot.
	 */
	public function test_other_requests_cannot_settle_plugins_of_a_running_batch() {
		$request = $this->site->request();
		$this->download( $request, self::A );
		$this->install( $request, self::A );
		$a = $this->row( self::A );

		$this->site->time += 2;
		$this->site->admin_page();
		$other = $this->site->request();
		$this->assertSame( array( self::A => FollowUpRequest::NOT_READY ), $other->settle_follow_up( array( self::A ), 7 ) );
		$other->expire_overdue();
		$this->assertSame( $a, $this->row( self::A ), 'Untouched by other requests.' );

		$this->download( $request, self::B );
		$this->install( $request, self::B );
		$request->batch_finished();
		$this->assert_closed_with_batch( self::A );
	}

	/**
	 * The first download settles analyses of earlier requests first
	 * (`next_update`), from the same capture that becomes the first plugin's BEFORE.
	 */
	public function test_earlier_analysis_settles_before_the_batch() {
		$this->site->update( self::D );
		$this->site->time += 5;

		$captures = $this->site->captures;
		$request  = $this->site->request();
		$this->download( $request, self::A );
		$this->assertSame( $captures + 1, $this->site->captures, 'One capture settles D and begins A.' );

		$d = $this->row( self::D );
		$this->assertSame( AnalysisStatus::COMPLETED, $d['status'] );
		$this->assertSame( SettleOutcome::NEXT_UPDATE, $d['settle_outcome'] );
	}

	/**
	 * The handoff is an encoded copy of the capture: changing the site after
	 * it was taken never changes what the next plugin starts from, and it is
	 * stored as the next plugin's BEFORE byte for byte.
	 */
	public function test_handoff_is_a_fixed_copy() {
		$request = $this->site->request();
		$this->download( $request, self::A );
		$this->install( $request, self::A );
		$expected = ( new OptionsSnapshotCodec() )->encode( $this->snapshot_now() );

		// Held between the two downloads as encoded text only, never as decoded snapshots.
		$batch = new \ReflectionProperty( PluginUpdateAnalyzer::class, 'batch' );
		$batch->setAccessible( true );
		$handoff = $batch->getValue( $request )['handoff'];
		$this->assertSame( $expected, $handoff['options'] );
		foreach ( array( 'cron', 'action_scheduler' ) as $signal ) {
			$this->assertNotSame( array(), $handoff[ $signal ], $signal );
			foreach ( $handoff[ $signal ] as $column => $value ) {
				$this->assertIsString( $value, "{$signal}: {$column}" );
			}
		}

		$this->site->options['later'] = array( 'y', 'off' );
		$this->download( $request, self::B );
		$this->assertSame( $expected, $this->row( self::B )['options_before_snapshot'], 'BEFORE of B = the IMMEDIATE capture of A, unchanged by later activity.' );

		$this->install( $request, self::B );
		$this->assertSame( array( 'beta_installed', 'later' ), self::changed_options( $this->row( self::B ) ) );
	}

	/**
	 * The options snapshot of the fake site now, built like FakeSite's capture
	 * (without counting a capture).
	 *
	 * @return \UpdateLens\Snapshot\OptionsSnapshot
	 */
	private function snapshot_now() {
		$rows = array();
		foreach ( $this->site->options as $name => $option ) {
			$rows[] = array(
				'option_name'  => $name,
				'option_value' => $option[0],
				'autoload'     => $option[1],
			);
		}

		return ( new OptionsSnapshotBuilder( new OptionValueHasher( $this->site->salt ), new OptionNoiseFilter(), new AutoloadPolicy( array( 'yes', 'on', 'auto-on', 'auto' ) ) ) )->build( $rows );
	}

	/**
	 * The time budget: each plugin needs its remaining captures to fit, each
	 * assumed as long as the longest so far. What does not fit is recorded
	 * as skipped (`capture_time_budget`), never as a comparison, and the
	 * update continues.
	 */
	public function test_time_budget_exhausted() {
		$this->site->time_cap        = 10.0;
		$this->site->capture_seconds = 3.0;
		$request                     = $this->site->request();

		$this->download( $request, self::A ); // 3 s.
		$this->assertTrue( $this->install( $request, self::A ) ); // 6 s.
		$this->download( $request, self::B ); // Handoff: no capture.
		$this->assertTrue( $this->install( $request, self::B ) ); // 9 s.
		$this->download( $request, self::C ); // 9 + 3 > 10: skipped.
		$this->assertFalse( $this->install( $request, self::C ) );
		$this->download( $request, self::D ); // No handoff left: skipped.
		$this->assertFalse( $this->install( $request, self::D ) );
		$request->batch_finished();
		$request->request_ending( false );

		$this->assertSame( 3, $this->site->captures, 'Nothing is captured once the budget is used up.' );
		$this->assert_closed_with_batch( self::A );
		$this->assert_closed_with_batch( self::B );
		foreach ( array( self::C, self::D ) as $plugin ) {
			$row = $this->row( $plugin );
			$this->assertSame( AnalysisStatus::FAILED, $row['status'], $plugin );
			$this->assertSame( PluginUpdateAnalyzer::ERROR_TIME_BUDGET, $row['error_code'], $plugin );
			$this->assertSame( SettleOutcome::NOT_APPLICABLE, $row['settle_outcome'], $plugin );
			$this->assertNull( $row['active_plugin'], $plugin );
			$this->assertNull( $row['options_during_update_diff'], $plugin );
			$this->assertNull( $row['options_before_snapshot'], $plugin );
			$this->assertSame( ucfirst( dirname( $plugin ) ), $row['plugin_name'], 'Recorded with its name and version.' );
			$this->assertSame( '1.0.0', $row['version_before'], $plugin );
			foreach ( array( 'during_update', 'post_update', 'final' ) as $phase ) {
				$this->assertSame( CronPhaseReason::ANALYSIS_ENDED, $row[ "cron_{$phase}_reason" ], "{$plugin} {$phase}" );
				$this->assertSame( ActionSchedulerPhaseReason::ANALYSIS_ENDED, $row[ "action_scheduler_{$phase}_reason" ], "{$plugin} {$phase}" );
			}
		}
	}

	/**
	 * A capture that takes longer than expected: the IMMEDIATE of that plugin
	 * no longer fits, so it fails `capture_time_budget` with no During update,
	 * and the next plugin is skipped.
	 */
	public function test_time_budget_exhausted_before_immediate() {
		$this->site->time_cap        = 10.0;
		$this->site->capture_seconds = 6.0;
		$request                     = $this->site->request();

		$this->download( $request, self::A ); // 6 s; 6 + 6 > 10.
		$this->assertFalse( $this->install( $request, self::A ) );
		$this->download( $request, self::B );
		$request->batch_finished();

		$this->assertSame( 1, $this->site->captures );
		$a = $this->row( self::A );
		$this->assertSame( AnalysisStatus::FAILED, $a['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_TIME_BUDGET, $a['error_code'] );
		$this->assertNull( $a['options_during_update_diff'] );
		$this->assertNull( $a['options_before_snapshot'] );
		$this->assertSame( CronPhaseReason::ANALYSIS_ENDED, $a['cron_during_update_reason'], 'An UpdateLens limit, not a failed update.' );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_TIME_BUDGET, $this->row( self::B )['error_code'] );
	}

	/**
	 * Single-plugin updates are measured but never limited by the time budget.
	 */
	public function test_time_budget_never_limits_single_plugin_updates() {
		$this->site->time_cap        = 1.0;
		$this->site->capture_seconds = 5.0;
		$request                     = $this->site->request();
		$this->site->start( $request, self::A );
		$this->assertTrue( $request->update_finished( self::A, null, '2.0.0' ) );
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $this->row( self::A )['status'] );
	}

	/**
	 * Memory: a plugin whose observation does not fit is recorded as skipped
	 * (`analysis_error`); an IMMEDIATE that does not fit ends that plugin
	 * `analysis_error`. Neither captures anything.
	 */
	public function test_memory_budget() {
		$request = $this->site->request();
		$this->download( $request, self::A );
		$this->site->no_memory_for = array( ObservationBudget::IMMEDIATE );
		$this->assertFalse( $this->install( $request, self::A ) );

		$this->site->no_memory_for = array( ObservationBudget::BEFORE );
		$this->download( $request, self::B );
		$this->site->no_memory_for = array();
		$request->batch_finished();

		$this->assertSame( 1, $this->site->captures );
		foreach ( array( self::A, self::B ) as $plugin ) {
			$row = $this->row( $plugin );
			$this->assertSame( AnalysisStatus::FAILED, $row['status'], $plugin );
			$this->assertSame( PluginUpdateAnalyzer::ERROR_ANALYSIS_FAILED, $row['error_code'], $plugin );
			$this->assertNull( $row['options_during_update_diff'], $plugin );
		}
	}

	/**
	 * UpdateLens itself in the batch: the plugins before it keep their During
	 * update and close as `batch_incomplete`; nothing after it is observed.
	 */
	public function test_updatelens_in_the_batch() {
		$request = $this->site->request();
		$this->download( $request, self::A );
		$this->install( $request, self::A );
		$this->download( $request, null );
		$captures = $this->site->captures;

		$this->download( $request, self::B );
		$this->install( $request, self::B );
		$request->batch_finished();
		$request->request_ending( false );

		$this->assertSame( $captures, $this->site->captures, 'Nothing is captured after UpdateLens was updated.' );
		$this->assert_closed_with_batch( self::A, SettleOutcome::BATCH_INCOMPLETE );
		$this->assertCount( 1, $this->site->repository->rows, 'No analysis after UpdateLens.' );
	}

	/**
	 * The same plugin twice in one batch: its first observation no longer
	 * describes the final state (abandoned), and the capture after it is not
	 * handed on.
	 */
	public function test_same_plugin_twice() {
		$request = $this->site->request();
		$this->download( $request, self::A );
		$this->install( $request, self::A );
		$this->download( $request, self::A );
		$this->assertSame( 2, $this->site->captures );
		$this->assertFalse( $this->install( $request, self::A ) );

		$this->download( $request, self::B );
		$this->assertSame( 3, $this->site->captures, 'No handoff across the repeated download.' );
		$this->install( $request, self::B );
		$request->batch_finished();

		$a = $this->row( self::A );
		$this->assertSame( AnalysisStatus::ABANDONED, $a['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_ANOTHER_UPDATE_STARTED, $a['error_code'] );
		$this->assertCount( 2, $this->site->repository->rows );
		$this->assertSame( array( 'beta_installed' ), self::changed_options( $this->assert_closed_with_batch( self::B ) ) );
	}

	/**
	 * Another update in the same request (not this batch) still abandons the
	 * batch's open plugins, and a second batch starts on its own.
	 */
	public function test_other_update_in_the_request() {
		$request = $this->site->request();
		$this->download( $request, self::A );
		$this->install( $request, self::A );
		$request->update_starting( null ); // E.g. a theme or translation package.

		$a = $this->row( self::A );
		$this->assertSame( AnalysisStatus::ABANDONED, $a['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_ANOTHER_UPDATE_STARTED, $a['error_code'] );
		$this->assertIsString( $a['options_during_update_diff'], 'Its During update stays.' );

		$this->download( $request, self::B, 'upgrader-2' );
		$this->assertSame( 3, $this->site->captures, 'A new batch takes its own capture.' );
		$this->install( $request, self::B );
		$request->batch_finished();
		$this->assert_closed_with_batch( self::B );
	}

	/**
	 * A later multi-plugin update in the same request is its own batch, even
	 * with the same key (PHP reuses the object hash of a freed upgrader):
	 * it takes its own capture and leaves the closed plugins alone.
	 */
	public function test_later_batch_with_the_same_key() {
		$request = $this->site->request();
		$this->download( $request, self::A );
		$this->install( $request, self::A );
		$request->batch_finished();

		$this->download( $request, self::B );
		$this->assertSame( 3, $this->site->captures, 'Nothing is handed on across batches.' );
		$this->install( $request, self::B );
		$request->batch_finished();

		$this->assert_closed_with_batch( self::A );
		$this->assertSame( array( 'beta_installed' ), self::changed_options( $this->assert_closed_with_batch( self::B ) ) );
	}

	/**
	 * A duplicate install result or batch end changes nothing.
	 */
	public function test_duplicates_change_nothing() {
		$request = $this->site->request();
		$this->download( $request, self::A );
		$this->assertTrue( $this->install( $request, self::A ) );
		$this->assertFalse( $request->batch_item_installed( self::A, null, '2.0.0' ) );
		$this->assertFalse( $request->batch_item_installed( self::A, 'some_error', null ) );
		$this->download( $request, self::B );
		$this->install( $request, self::B );
		$request->batch_finished();
		$rows   = $this->site->repository->rows;
		$writes = $this->site->repository->writes;

		$request->batch_finished();
		$request->request_ending( false );
		$this->assertSame( $rows, $this->site->repository->rows );
		$this->assertSame( $writes, $this->site->repository->writes );
		$this->assertSame( 3, $this->site->captures );
	}

	/**
	 * Install results for plugins that are not items of the batch are ignored.
	 */
	public function test_install_result_of_unknown_plugin() {
		$request = $this->site->request();
		$this->assertFalse( $request->batch_item_installed( self::A, null, '2.0.0' ), 'No batch.' );
		$this->download( $request, self::A );
		$this->assertFalse( $request->batch_item_installed( self::B, null, '2.0.0' ), 'Not an item.' );
		$this->assertSame( 1, $this->site->captures );
	}

	/**
	 * A plugin another request is updating right now is not begun again
	 * (existing rule), and its capture is not handed on to anyone.
	 */
	public function test_plugin_already_being_updated_elsewhere() {
		$this->site->start( $this->site->request(), self::B );
		$open = $this->row( self::B );

		$request = $this->site->request();
		$this->download( $request, self::A );
		$this->install( $request, self::A );
		$this->download( $request, self::B );
		$request->batch_finished();

		$this->assertSame( $open, $this->row( self::B ), 'The other request\'s analysis is untouched.' );
		$this->assertCount( 2, $this->site->repository->rows );
		$this->assert_closed_with_batch( self::A );
	}

	/**
	 * The read model passes the batch codes through (never `unknown`), and a
	 * skipped plugin is an UpdateLens failure, never "the update failed".
	 */
	public function test_read_model_codes() {
		$this->site->time_cap        = 4.0;
		$this->site->capture_seconds = 3.0;
		$request                     = $this->site->request();
		$this->download( $request, self::A );
		$this->assertFalse( $this->install( $request, self::A ) );
		$this->site->time_cap = null;

		$request = $this->site->request();
		$this->download( $request, self::B, 'upgrader-2' );
		$this->install( $request, self::B );
		$request->batch_finished();

		$model   = new AnalysisReadModel();
		$skipped = $model->report( $this->row( self::A ) );
		$this->assertSame( 'failed', $skipped['status'] );
		$this->assertSame( array( 'code' => PluginUpdateAnalyzer::ERROR_TIME_BUDGET ), $skipped['error'] );
		$this->assertSame( 'analysis_failed', $skipped['phases']['during_update']['options']['reason'] );

		$item = $model->report( $this->row( self::B ) );
		$this->assertSame( SettleOutcome::BATCH, $item['settle_outcome'] );
		$this->assertTrue( $item['phases']['during_update']['options']['available'] );
		$this->assertFalse( $item['phases']['final']['options']['available'] );
		$this->assertSame( CronPhaseReason::BATCH_UPDATE, $item['phases']['final']['cron']['reason'] );
		$this->assertSame( ActionSchedulerPhaseReason::BATCH_UPDATE, $item['phases']['final']['action_scheduler']['reason'] );
	}
}
