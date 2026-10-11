<?php
/**
 * Tests for skipped observations when memory is short.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use UpdateLens\Report\AnalysisReadModel;
use UpdateLens\Report\UnavailableReason;
use UpdateLens\Snapshot\ActionSchedulerUnavailableException;
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
 * PluginUpdateAnalyzer asks the observation budget before every options
 * observation. A stage that does not fit is skipped before anything is
 * captured or decoded: never a partial snapshot, never a completed
 * comparison, and the update itself is never affected.
 */
final class ObservationMemoryTest extends TestCase {

	const PLUGIN = 'acme/acme.php';

	const T = 1767225600; // 2026-01-01T00:00:00Z.

	/**
	 * Fake site.
	 *
	 * @var FakeSite
	 */
	private $site;

	/**
	 * Fresh site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->site = new FakeSite(
			array(
				'blogname'      => array( 'Site', 'on' ),
				'acme_settings' => array( 'a:0:{}', 'off' ),
			),
			self::T
		);
	}

	/**
	 * The only analysis row.
	 *
	 * @return array
	 */
	private function only_row() {
		$this->assertCount( 1, $this->site->repository->rows );

		return reset( $this->site->repository->rows );
	}

	/**
	 * The update request: BEFORE, the update, IMMEDIATE, its shutdown.
	 *
	 * @return PluginUpdateAnalyzer
	 */
	private function update() {
		$request = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$this->site->options['acme_settings'] = array( 'a:1:{s:1:"v";i:2;}', 'off' );
		$this->site->time                    += 2;
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$request->request_ending( true );

		return $request;
	}

	/**
	 * Without memory for the whole observation, no analysis is begun and
	 * nothing is captured; the update runs on (no exception).
	 */
	public function test_before_that_does_not_fit_begins_nothing() {
		$this->site->no_memory_for = array( ObservationBudget::BEFORE );

		$this->update();

		$this->assertSame( array(), $this->site->repository->rows );
		$this->assertSame( 0, $this->site->captures );
		$this->assertSame( array( ObservationBudget::BEFORE ), $this->site->fits_asked );
	}

	/**
	 * Without memory for IMMEDIATE, the analysis ends as an UpdateLens
	 * failure (`analysis_error`, never "the update failed"), with no capture
	 * and nothing decoded; every phase is unavailable as `analysis_failed`.
	 */
	public function test_immediate_that_does_not_fit_fails_the_analysis_only() {
		$this->site->no_memory_for = array( ObservationBudget::IMMEDIATE );

		$this->update();

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::FAILED, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_ANALYSIS_FAILED, $row['error_code'] );
		$this->assertSame( SettleOutcome::NOT_APPLICABLE, $row['settle_outcome'] );
		$this->assertNull( $row['options_before_snapshot'] );
		$this->assertNull( $row['options_immediate_snapshot'] );
		$this->assertNull( $row['options_during_update_diff'] );
		$this->assertSame( 1, $this->site->captures, 'Only BEFORE was captured.' );

		$report = ( new AnalysisReadModel() )->report( $row );
		foreach ( array( 'during_update', 'post_update', 'final' ) as $phase ) {
			$this->assertFalse( $report['phases'][ $phase ]['options']['available'], $phase );
			$this->assertSame( UnavailableReason::ANALYSIS_FAILED, $report['phases'][ $phase ]['options']['reason'], $phase );
		}
		$this->assertNull( $row['cron_before_snapshot'] );
		$this->assertNotNull( $row['cron_final_reason'], 'Every Cron phase of a terminal analysis has a reason.' );
	}

	/**
	 * A follow-up without memory for SETTLED reports `capture_failed` and
	 * leaves the analysis waiting; a later admin page with memory settles it.
	 */
	public function test_settled_that_does_not_fit_waits_for_a_later_request() {
		$this->update();
		$this->site->no_memory_for = array( ObservationBudget::SETTLED );
		$this->site->time         += 2;
		$captures                  = $this->site->captures;

		$this->assertSame( array( self::PLUGIN => FollowUpRequest::CAPTURE_FAILED ), $this->site->request()->settle_follow_up( array( self::PLUGIN ), 7 ) );
		$this->site->admin_page();

		$this->assertSame( $captures, $this->site->captures, 'Nothing was captured.' );
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $this->only_row()['status'] );
		$this->assertNotNull( $this->only_row()['options_immediate_snapshot'] );

		$this->site->no_memory_for = array();
		$this->site->time         += 2;
		$this->site->admin_page();

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( SettleOutcome::ADMIN_SHUTDOWN, $row['settle_outcome'] );
		$this->assertNotNull( $row['options_final_diff'] );
	}

	/**
	 * If no request within the window has the memory, the observation
	 * expires: a partial report, never a completed comparison.
	 */
	public function test_settled_that_never_fits_expires() {
		$this->update();
		$this->site->no_memory_for = array( ObservationBudget::SETTLED );
		$this->site->admin_page();
		$this->site->time += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS + 3;
		$this->site->admin_page();

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( SettleOutcome::EXPIRED, $row['settle_outcome'] );
		$this->assertNull( $row['options_final_diff'] );
		$report = ( new AnalysisReadModel() )->report( $row );
		$this->assertFalse( $report['phases']['final']['options']['available'] );
		$this->assertSame( UnavailableReason::SETTLE_EXPIRED, $report['phases']['final']['options']['reason'] );
		$this->assertTrue( $report['phases']['during_update']['options']['available'] );
	}

	/**
	 * An earlier analysis that the next update would settle, without memory:
	 * it ends as an UpdateLens failure and the new update begins nothing.
	 */
	public function test_next_update_without_memory() {
		$this->update();
		$this->site->no_memory_for = array( ObservationBudget::BEFORE );
		$this->site->time         += 5;

		$this->site->start( $this->site->request(), 'other/other.php' );

		$this->assertCount( 1, $this->site->repository->rows );
		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::FAILED, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_ANALYSIS_FAILED, $row['error_code'] );
		$this->assertSame( SettleOutcome::NEXT_UPDATE, $row['settle_outcome'] );
	}

	/**
	 * Ending analyses never reads the options snapshots (the largest values).
	 */
	public function test_ending_reads_no_options_snapshots() {
		$request = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$request->request_ending( true ); // The update never reported back.

		$this->assertSame( AnalysisStatus::FAILED, $this->only_row()['status'] );
		$this->assertGreaterThan( 0, $this->site->repository->signal_reads );
		$read = $this->site->repository->find_signals( (int) $this->only_row()['id'] );
		$this->assertArrayNotHasKey( 'options_before_snapshot', $read );
		$this->assertArrayNotHasKey( 'options_immediate_snapshot', $read );
		$this->assertArrayNotHasKey( 'options_final_diff', $read );
		$this->assertArrayHasKey( 'cron_before_snapshot', $read );
	}

	/**
	 * Update, then the follow-up settles it.
	 *
	 * @return array The analysis row.
	 */
	private function update_and_settle() {
		$this->update();
		$this->site->cron  = CronFixture::cron( array( CronFixture::recurring( self::T + 900, 'acme_report', 'hourly' ) ) );
		$this->site->time += 2;
		$this->site->request()->settle_follow_up( array( self::PLUGIN ), 7 );

		return $this->only_row();
	}

	/**
	 * WP-Cron without memory for the whole observation is not captured at
	 * all at BEFORE: its BEFORE phases are unavailable, the options analysis
	 * and the post-update Cron phase are unaffected.
	 */
	public function test_cron_that_does_not_fit_is_not_captured() {
		$this->site->no_memory_for = array( ObservationBudget::BEFORE . ':' . ObservationBudget::CRON );

		$row = $this->update_and_settle();

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertNotNull( $row['options_final_diff'] );
		$this->assertSame( CronPhaseReason::SNAPSHOT_UNAVAILABLE, $row['cron_during_update_reason'] );
		$this->assertSame( CronPhaseReason::SNAPSHOT_UNAVAILABLE, $row['cron_final_reason'] );
		$this->assertNull( $row['cron_during_update_diff'] );
		$this->assertNull( $row['cron_final_diff'] );
		$this->assertNotNull( $row['cron_post_update_diff'], 'IMMEDIATE and SETTLED fit.' );
		$this->assertSame( 2, $this->site->cron_captures, 'Not captured at BEFORE.' );

		$report = ( new AnalysisReadModel() )->report( $row );
		$this->assertFalse( $report['phases']['final']['cron']['available'], 'Unavailable, never "no changes".' );
		$this->assertTrue( $report['phases']['final']['options']['available'] );
	}

	/**
	 * Action Scheduler without memory at IMMEDIATE: the phases that need the
	 * IMMEDIATE capture are unavailable, the Net result is still compared.
	 */
	public function test_action_scheduler_that_does_not_fit_at_immediate() {
		$this->site->action_scheduler = array();
		$this->site->no_memory_for    = array( ObservationBudget::IMMEDIATE . ':' . ObservationBudget::ACTION_SCHEDULER );

		$row = $this->update_and_settle();

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE, $row['action_scheduler_during_update_reason'] );
		$this->assertSame( ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE, $row['action_scheduler_post_update_reason'] );
		$this->assertNotNull( $row['action_scheduler_final_diff'] );
		$this->assertSame( 2, $this->site->action_scheduler_captures, 'Not captured at IMMEDIATE.' );
		$this->assertNotNull( $row['cron_during_update_diff'], 'WP-Cron is independent.' );
	}

	/**
	 * WP-Cron without memory at SETTLED: post-update and final are
	 * unavailable, the stored snapshots are cleared as always.
	 */
	public function test_cron_that_does_not_fit_at_settled() {
		$this->site->no_memory_for = array( ObservationBudget::SETTLED . ':' . ObservationBudget::CRON );

		$row = $this->update_and_settle();

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertNotNull( $row['cron_during_update_diff'] );
		$this->assertSame( CronPhaseReason::SNAPSHOT_UNAVAILABLE, $row['cron_post_update_reason'] );
		$this->assertSame( CronPhaseReason::SNAPSHOT_UNAVAILABLE, $row['cron_final_reason'] );
		$this->assertNull( $row['cron_before_snapshot'] );
		$this->assertNull( $row['cron_immediate_snapshot'] );
		$this->assertSame( 2, $this->site->cron_captures, 'Not captured at SETTLED.' );
	}

	/**
	 * A stored WP-Cron snapshot is not decoded when the later capture is
	 * unavailable (as when it was skipped for memory): decoding is the
	 * largest allocation of a phase. A corrupt stored snapshot shows it: the
	 * phase gets the later capture's reason, not the decoding failure.
	 */
	public function test_stored_cron_snapshot_not_decoded_without_a_later_snapshot() {
		$request = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$id = (int) $this->only_row()['id'];
		$this->site->repository->rows[ $id ]['cron_before_snapshot'] = '{"corrupt"';
		$this->site->cron[ self::T + 99 ]['acme_broken']             = array( 'k' => 'malformed' );

		$request->update_finished( self::PLUGIN, null, '1.1.0' );

		$this->assertSame( CronPhaseReason::MALFORMED_CRON_STATE, $this->only_row()['cron_during_update_reason'] );
	}

	/**
	 * The same for Action Scheduler; with a readable later snapshot the
	 * stored one is decoded (and the corruption reported).
	 */
	public function test_stored_action_scheduler_snapshot_decoded_only_when_compared() {
		$this->site->action_scheduler = array();
		$request                      = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$id = (int) $this->only_row()['id'];
		$this->site->repository->rows[ $id ]['action_scheduler_before_snapshot'] = '{"corrupt"';
		$this->site->action_scheduler = ActionSchedulerUnavailableException::unsupported_store();

		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$this->assertSame( ActionSchedulerPhaseReason::UNSUPPORTED_STORE, $this->only_row()['action_scheduler_during_update_reason'] );

		$this->site->action_scheduler = array();
		$this->site->time            += 2;
		$this->site->request()->settle_follow_up( array( self::PLUGIN ), 7 );
		$this->assertSame( ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE, $this->only_row()['action_scheduler_final_reason'] );
	}

	/**
	 * With memory for everything, the stages are asked in order and the
	 * observation is complete.
	 */
	public function test_every_stage_is_asked_before_its_capture() {
		$this->update();
		$this->site->time += 2;
		$this->site->request()->settle_follow_up( array( self::PLUGIN ), 7 );

		$this->assertSame( array( ObservationBudget::BEFORE, ObservationBudget::IMMEDIATE, ObservationBudget::SETTLED ), $this->site->fits_asked );
		$this->assertSame( AnalysisStatus::COMPLETED, $this->only_row()['status'] );
	}
}
