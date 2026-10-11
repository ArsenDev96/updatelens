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
use UpdateLens\Tests\Support\FakeSite;
use UpdateLens\Update\AnalysisStatus;
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
