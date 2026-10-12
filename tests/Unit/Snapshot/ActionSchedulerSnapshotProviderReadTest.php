<?php
/**
 * Tests for ActionSchedulerSnapshotProvider's bounded reads.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Snapshot;

use PHPUnit\Framework\TestCase;
use UpdateLens\Snapshot\ActionSchedulerArgsHasher;
use UpdateLens\Snapshot\ActionSchedulerScheduleParser;
use UpdateLens\Snapshot\ActionSchedulerSnapshotBuilder;
use UpdateLens\Snapshot\ActionSchedulerSnapshotProvider;
use UpdateLens\Snapshot\ActionSchedulerUnavailableException;
use UpdateLens\Snapshot\MalformedActionSchedulerStateException;
use UpdateLens\Tests\Support\ActionSchedulerFixture as AS_Fixture;

/**
 * With an initialized Action Scheduler and its database store: the batch
 * query never returns an over-long `schedule` (one corrupt row must not
 * exhaust memory), with the same result as reading it; the measurement for
 * ObservationBudget reads no actions.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ActionSchedulerSnapshotProviderReadTest extends TestCase {

	/**
	 * Load the stand-ins: $wpdb with Action Scheduler's tables, and Action Scheduler.
	 *
	 * @before
	 */
	public function load_stubs() {
		require dirname( __DIR__, 2 ) . '/fixtures/wpdb-recorder.php';
		require dirname( __DIR__, 2 ) . '/fixtures/action-scheduler-stubs.php';

		$wpdb       = $GLOBALS['wpdb'];
		$wpdb->vars = array( 'wp_actionscheduler_actions', 'wp_actionscheduler_groups' );
		$wpdb->col  = array_merge( ActionSchedulerSnapshotProvider::ACTION_COLUMNS, ActionSchedulerSnapshotProvider::GROUP_COLUMNS );
	}

	/**
	 * Provider.
	 *
	 * @return ActionSchedulerSnapshotProvider
	 */
	private function provider() {
		return new ActionSchedulerSnapshotProvider( new ActionSchedulerSnapshotBuilder( new ActionSchedulerArgsHasher( AS_Fixture::SECRET ) ) );
	}

	/**
	 * A database row as the batch query returns it.
	 *
	 * @param int         $id       Action ID.
	 * @param string|null $schedule Stored schedule as returned.
	 * @return array<string, mixed>
	 */
	private static function db_row( $id, $schedule ) {
		$row               = AS_Fixture::single( 'acme_sync', 1767225600, array( 'order' => $id ) );
		$row['action_id']  = (string) $id;
		$row['schedule']   = $schedule;
		$row['group_slug'] = $row['group'];
		unset( $row['group'] );

		return $row;
	}

	/**
	 * The query caps the schedule at the parser's limit in SQL.
	 */
	public function test_query_caps_the_schedule() {
		$GLOBALS['wpdb']->results = array( self::db_row( 1, AS_Fixture::single_schedule( 1767225600 ) ) );

		$this->assertCount( 1, $this->provider()->capture()->get_actions() );

		$batch = end( $GLOBALS['wpdb']->prepared );
		$this->assertStringContainsString( "IF(LENGTH(a.schedule) > %d, '', a.schedule) AS schedule", $batch[0] );
		$this->assertSame( ActionSchedulerScheduleParser::MAX_LENGTH, $batch[1][0] );
	}

	/**
	 * An over-long schedule comes back empty and fails the snapshot as
	 * `invalid_schedule`, exactly like the whole value would.
	 */
	public function test_capped_schedule_fails_like_the_whole_value() {
		$reasons = array();
		foreach ( array( '', str_repeat( 's', ActionSchedulerScheduleParser::MAX_LENGTH + 1 ) ) as $schedule ) {
			$GLOBALS['wpdb']->vars    = array( 'wp_actionscheduler_actions', 'wp_actionscheduler_groups' );
			$GLOBALS['wpdb']->results = array( self::db_row( 1, $schedule ) );
			try {
				$this->provider()->capture();
				$this->fail( 'Expected MalformedActionSchedulerStateException.' );
			} catch ( MalformedActionSchedulerStateException $e ) {
				$reasons[] = $e->get_reason();
			}
		}

		$this->assertSame( array( MalformedActionSchedulerStateException::INVALID_SCHEDULE, MalformedActionSchedulerStateException::INVALID_SCHEDULE ), $reasons );
	}

	/**
	 * One aggregate statement measures the active actions and their schedule
	 * bytes beyond SCHEDULE_BYTES (over-long schedules count as empty, like
	 * the batch query returns them) and releases $wpdb's copy.
	 */
	public function test_measure_active() {
		$GLOBALS['wpdb']->row = array(
			'actions' => '30001',
			'extra'   => '0',
			'most'    => '0',
		);

		$this->assertSame(
			array(
				'actions'                    => 30001,
				'extra_schedule_bytes'       => 0,
				'batch_extra_schedule_bytes' => 0,
			),
			$this->provider()->measure_active()
		);

		$measure = end( $GLOBALS['wpdb']->prepared );
		$this->assertStringContainsString( 'COUNT(*) AS actions, COALESCE(SUM(extra), 0) AS extra, COALESCE(MAX(extra), 0) AS most', $measure[0] );
		$this->assertStringContainsString( 'CASE WHEN LENGTH(schedule) > %d THEN 0 WHEN LENGTH(schedule) > %d THEN LENGTH(schedule) - %d ELSE 0 END AS extra', $measure[0] );
		$this->assertStringContainsString( 'FROM %i WHERE status IN ( %s, %s )', $measure[0] );
		$this->assertSame(
			array( ActionSchedulerScheduleParser::MAX_LENGTH, ActionSchedulerSnapshotProvider::SCHEDULE_BYTES, ActionSchedulerSnapshotProvider::SCHEDULE_BYTES, 'wp_actionscheduler_actions', 'pending', 'in-progress' ),
			$measure[1]
		);
		$this->assertSame( 1, $GLOBALS['wpdb']->flushes );
	}

	/**
	 * A batch holds at most BATCH_SIZE times the most extra bytes of one row,
	 * and never more than all of them.
	 */
	public function test_measure_bounds_one_batch() {
		$GLOBALS['wpdb']->row = array(
			'actions' => '2001',
			'extra'   => '127874000',
			'most'    => '63937',
		);
		$this->assertSame(
			array(
				'actions'                    => 2001,
				'extra_schedule_bytes'       => 127874000,
				'batch_extra_schedule_bytes' => ActionSchedulerSnapshotProvider::BATCH_SIZE * 63937,
			),
			$this->provider()->measure_active()
		);

		$GLOBALS['wpdb']->vars = array( 'wp_actionscheduler_actions', 'wp_actionscheduler_groups' );
		$GLOBALS['wpdb']->row  = array(
			'actions' => '600',
			'extra'   => '64000',
			'most'    => '63000',
		);
		$this->assertSame( 64000, $this->provider()->measure_active()['batch_extra_schedule_bytes'], 'One long schedule among many.' );
	}

	/**
	 * Without a row nothing is read; a database error throws instead of
	 * estimating nothing.
	 */
	public function test_measure_without_a_row_or_with_an_error() {
		$GLOBALS['wpdb']->row = null;
		$this->assertSame( 0, $this->provider()->measure_active()['actions'] );

		$GLOBALS['wpdb']->vars       = array( 'wp_actionscheduler_actions', 'wp_actionscheduler_groups' );
		$GLOBALS['wpdb']->last_error = 'Lost connection';
		$this->expectException( ActionSchedulerUnavailableException::class );
		$this->provider()->measure_active();
	}
}
