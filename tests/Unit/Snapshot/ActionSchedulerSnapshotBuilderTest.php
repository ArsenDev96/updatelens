<?php
/**
 * Tests for ActionSchedulerSnapshotBuilder and the snapshot it produces.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Snapshot;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UpdateLens\Snapshot\ActionSchedulerActionRecord;
use UpdateLens\Snapshot\ActionSchedulerArgsHasher;
use UpdateLens\Snapshot\ActionSchedulerSnapshot;
use UpdateLens\Snapshot\CronArgsHasher;
use UpdateLens\Snapshot\MalformedActionSchedulerStateException;
use UpdateLens\Snapshot\OptionValueHasher;
use UpdateLens\Tests\Support\ActionSchedulerFixture as AS_Fixture;
use UpdateLens\Tests\Support\TrapSchedule;

/**
 * Active Action Scheduler rows → safe snapshot.
 */
final class ActionSchedulerSnapshotBuilderTest extends TestCase {

	/**
	 * Obviously fake credential used in privacy fixtures.
	 */
	const FAKE_SECRET = 'sk_test_UPDATE_LENS_ACTION_SCHEDULER_SECRET';

	const T = 1767225600; // 2026-01-01T00:00:00Z.

	/**
	 * Fingerprint of arguments under the fixture secret.
	 *
	 * @param array $args Arguments.
	 * @return string
	 */
	private function fp( array $args ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Same encoding as the fixture rows.
		return ( new ActionSchedulerArgsHasher( AS_Fixture::SECRET ) )->fingerprint( json_encode( $args ) );
	}

	/**
	 * Array forms of a snapshot's records.
	 *
	 * @param ActionSchedulerSnapshot $snapshot Snapshot.
	 * @return array<int, array>
	 */
	private function records( ActionSchedulerSnapshot $snapshot ) {
		return array_map(
			static function ( ActionSchedulerActionRecord $action ) {
				return $action->to_array();
			},
			$snapshot->get_actions()
		);
	}

	/**
	 * Expected malformed-state reason.
	 *
	 * @param array  $rows   Rows.
	 * @param string $reason Reason constant.
	 */
	private function assertMalformed( array $rows, $reason ) {
		try {
			AS_Fixture::snapshot( $rows );
			$this->fail( 'Expected MalformedActionSchedulerStateException (' . $reason . ').' );
		} catch ( MalformedActionSchedulerStateException $e ) {
			$this->assertSame( $reason, $e->get_reason() );
			$this->assertStringNotContainsString( self::FAKE_SECRET, $e->getMessage() );
			$this->assertStringNotContainsString( 'acme', $e->getMessage() );
		}
	}

	/**
	 * An installed store without active actions gives an empty snapshot.
	 */
	public function test_empty_store() {
		$snapshot = AS_Fixture::snapshot( array() );

		$this->assertSame( array(), $snapshot->get_actions() );
		$this->assertSame(
			array(
				'action_count'           => 0,
				'recurring_action_count' => 0,
				'single_action_count'    => 0,
				'unique_hook_count'      => 0,
				'pending_count'          => 0,
				'in_progress_count'      => 0,
			),
			$snapshot->get_summary()->to_array()
		);
		$this->assertSame( ( new ActionSchedulerArgsHasher( AS_Fixture::SECRET ) )->get_context(), $snapshot->get_fingerprint_context() );
		$this->assertStringStartsWith( 'as-args-hmac-sha256-v1:', $snapshot->get_fingerprint_context() );
	}

	/**
	 * One one-time action.
	 */
	public function test_single_action() {
		$snapshot = AS_Fixture::snapshot( array( AS_Fixture::single( 'acme_send_report', self::T + 60, array( 42 ), 'acme' ) ) );

		$this->assertSame(
			array(
				array(
					'hook'             => 'acme_send_report',
					'group'            => 'acme',
					'status'           => 'pending',
					'timestamp'        => self::T + 60,
					'schedule_type'    => 'single',
					'interval'         => null,
					'cron_expression'  => null,
					'is_recurring'     => false,
					'args_fingerprint' => $this->fp( array( 42 ) ),
				),
			),
			$this->records( $snapshot )
		);
		$this->assertSame( 1, $snapshot->get_summary()->get_single_action_count() );
	}

	/**
	 * One interval action.
	 */
	public function test_interval_action() {
		$snapshot = AS_Fixture::snapshot( array( AS_Fixture::recurring( 'woocommerce_cleanup_draft_orders', self::T + 3600, 86400 ) ) );
		$action   = $snapshot->get_actions()[0];

		$this->assertSame( 'interval', $action->get_schedule_type() );
		$this->assertSame( 86400, $action->get_interval() );
		$this->assertNull( $action->get_cron_expression() );
		$this->assertTrue( $action->is_recurring() );
		$this->assertSame( '', $action->get_group() );
		$this->assertSame( 1, $snapshot->get_summary()->get_recurring_action_count() );
	}

	/**
	 * One cron action: the expression is normalized from the stored CronExpression.
	 */
	public function test_cron_action() {
		$snapshot = AS_Fixture::snapshot( array( AS_Fixture::cron( 'acme_sync', self::T + 7200, '0 */6 * * *', array(), 'acme' ) ) );
		$action   = $snapshot->get_actions()[0];

		$this->assertSame( 'cron', $action->get_schedule_type() );
		$this->assertSame( '0 */6 * * *', $action->get_cron_expression() );
		$this->assertNull( $action->get_interval() );
		$this->assertTrue( $action->is_recurring() );
		$this->assertSame( self::T + 7200, $action->get_timestamp() );
	}

	/**
	 * An async action (as_enqueue_async_action) is one-time, scheduled at its enqueue time.
	 */
	public function test_async_action() {
		$snapshot = AS_Fixture::snapshot( array( AS_Fixture::async( 'woocommerce_run_product_attribute_lookup_update_callback', self::T, array( 7, 1 ) ) ) );
		$action   = $snapshot->get_actions()[0];

		$this->assertSame( 'async', $action->get_schedule_type() );
		$this->assertFalse( $action->is_recurring() );
		$this->assertSame( self::T, $action->get_timestamp() );
	}

	/**
	 * Schedules exactly as Action Scheduler 4.0.0 (WooCommerce 11.1.2) stored them.
	 *
	 * @dataProvider provide_real_schedules
	 *
	 * @param string      $schedule   Stored schedule.
	 * @param string      $type       Expected type.
	 * @param int|null    $interval   Expected interval.
	 * @param string|null $expression Expected cron expression.
	 */
	public function test_real_action_scheduler_schedules( $schedule, $type, $interval, $expression ) {
		$action = AS_Fixture::snapshot( array( AS_Fixture::row( 'acme', self::T, $schedule ) ) )->get_actions()[0];

		$this->assertSame( array( $type, $interval, $expression ), array( $action->get_schedule_type(), $action->get_interval(), $action->get_cron_expression() ) );
	}

	/**
	 * Captured `schedule` column values.
	 *
	 * @return array<string, array>
	 */
	public function provide_real_schedules() {
		return array(
			'simple'   => array( "O:30:\"ActionScheduler_SimpleSchedule\":2:{s:22:\"\0*\0scheduled_timestamp\";i:1791293017;s:41:\"\0ActionScheduler_SimpleSchedule\0timestamp\";i:1791293017;}", 'single', null, null ),
			'null'     => array( 'O:28:"ActionScheduler_NullSchedule":0:{}', 'async', null, null ),
			'interval' => array( "O:32:\"ActionScheduler_IntervalSchedule\":5:{s:22:\"\0*\0scheduled_timestamp\";i:1791342000;s:18:\"\0*\0first_timestamp\";i:1791342000;s:13:\"\0*\0recurrence\";i:86400;s:49:\"\0ActionScheduler_IntervalSchedule\0start_timestamp\";i:1791342000;s:53:\"\0ActionScheduler_IntervalSchedule\0interval_in_seconds\";i:86400;}", 'interval', 86400, null ),
			'cron'     => array( "O:28:\"ActionScheduler_CronSchedule\":5:{s:22:\"\0*\0scheduled_timestamp\";i:1791309600;s:18:\"\0*\0first_timestamp\";i:1791293100;s:13:\"\0*\0recurrence\";O:14:\"CronExpression\":2:{s:25:\"\0CronExpression\0cronParts\";a:5:{i:0;s:1:\"0\";i:1;s:3:\"*/6\";i:2;s:1:\"*\";i:3;s:1:\"*\";i:4;s:1:\"*\";}s:28:\"\0CronExpression\0fieldFactory\";O:27:\"CronExpression_FieldFactory\":1:{s:35:\"\0CronExpression_FieldFactory\0fields\";a:5:{i:0;O:27:\"CronExpression_MinutesField\":0:{}i:1;O:25:\"CronExpression_HoursField\":0:{}i:2;O:30:\"CronExpression_DayOfMonthField\":0:{}i:3;O:25:\"CronExpression_MonthField\":0:{}i:4;O:29:\"CronExpression_DayOfWeekField\":0:{}}}}s:45:\"\0ActionScheduler_CronSchedule\0start_timestamp\";i:1791309600;s:34:\"\0ActionScheduler_CronSchedule\0cron\";r:4;}", 'cron', null, '0 */6 * * *' ),
		);
	}

	/**
	 * Fixture schedules match Action Scheduler's bytes.
	 */
	public function test_fixture_schedules_match_real_bytes() {
		$real = $this->provide_real_schedules();

		$this->assertSame( $real['simple'][0], AS_Fixture::single_schedule( 1791293017 ) );
		$this->assertSame( $real['null'][0], AS_Fixture::async_schedule() );
		$this->assertSame( $real['interval'][0], AS_Fixture::interval_schedule( 1791342000, 86400 ) );
	}

	/**
	 * Legacy property names and value forms that the schedules' __wakeup() still reads.
	 *
	 * @dataProvider provide_legacy_schedules
	 *
	 * @param string      $schedule   Stored schedule.
	 * @param int|null    $interval   Expected interval.
	 * @param string|null $expression Expected cron expression.
	 */
	public function test_legacy_schedules( $schedule, $interval, $expression ) {
		$action = AS_Fixture::snapshot( array( AS_Fixture::row( 'acme', self::T, $schedule ) ) )->get_actions()[0];

		$this->assertSame( array( $interval, $expression ), array( $action->get_interval(), $action->get_cron_expression() ) );
	}

	/**
	 * Legacy forms.
	 *
	 * @return array<string, array>
	 */
	public function provide_legacy_schedules() {
		$ts = 'i:' . self::T . ';';

		return array(
			'interval_in_seconds only'         => array(
				AS_Fixture::object(
					'ActionScheduler_IntervalSchedule',
					array(
						"\0ActionScheduler_IntervalSchedule\0start_timestamp"     => $ts,
						"\0ActionScheduler_IntervalSchedule\0interval_in_seconds" => 'i:3600;',
					)
				),
				3600,
				null,
			),
			'interval as digit string'         => array(
				AS_Fixture::object( 'ActionScheduler_IntervalSchedule', array( "\0*\0recurrence" => AS_Fixture::string( '600' ) ) ),
				600,
				null,
			),
			'null recurrence, legacy interval' => array(
				AS_Fixture::object(
					'ActionScheduler_IntervalSchedule',
					array(
						"\0*\0recurrence" => 'N;',
						"\0ActionScheduler_IntervalSchedule\0interval_in_seconds" => 'i:60;',
					)
				),
				60,
				null,
			),
			'legacy cron string'               => array(
				AS_Fixture::object( 'ActionScheduler_CronSchedule', array( "\0ActionScheduler_CronSchedule\0cron" => AS_Fixture::string( " 15  3 * * MON-FRI \n" ) ) ),
				null,
				'15 3 * * MON-FRI',
			),
			'six-field cron'                   => array( AS_Fixture::cron_schedule( self::T, '0 0 1 1 * 2030' ), null, '0 0 1 1 * 2030' ),
		);
	}

	/**
	 * Several hooks, statuses and schedule types; the summary counts them.
	 */
	public function test_multiple_hooks_and_summary() {
		$in_progress           = AS_Fixture::single( 'acme_import', self::T, array( 1 ) );
		$in_progress['status'] = 'in-progress';

		$snapshot = AS_Fixture::snapshot(
			array(
				AS_Fixture::recurring( 'woocommerce_cleanup_draft_orders', self::T + 100, 86400 ),
				AS_Fixture::cron( 'acme_sync', self::T + 200, '*/5 * * * *' ),
				AS_Fixture::async( 'acme_import', self::T, array( 2 ) ),
				$in_progress,
				AS_Fixture::single( 'acme_mail', self::T + 300 ),
			)
		);

		$this->assertSame(
			array(
				'action_count'           => 5,
				'recurring_action_count' => 2,
				'single_action_count'    => 3,
				'unique_hook_count'      => 4,
				'pending_count'          => 4,
				'in_progress_count'      => 1,
			),
			$snapshot->get_summary()->to_array()
		);
		$this->assertSame(
			array( 'acme_import', 'acme_import', 'acme_mail', 'acme_sync', 'woocommerce_cleanup_draft_orders' ),
			array_column( $this->records( $snapshot ), 'hook' )
		);
	}

	/**
	 * One hook with different arguments: separate records with different fingerprints.
	 */
	public function test_same_hook_different_args() {
		$records = $this->records(
			AS_Fixture::snapshot(
				array(
					AS_Fixture::single( 'wc_admin_import_order', self::T, array( 'order_id' => 1 ) ),
					AS_Fixture::single( 'wc_admin_import_order', self::T, array( 'order_id' => 2 ) ),
				)
			)
		);

		$this->assertCount( 2, $records );
		$this->assertNotSame( $records[0]['args_fingerprint'], $records[1]['args_fingerprint'] );
		$this->assertEqualsCanonicalizing(
			array( $this->fp( array( 'order_id' => 1 ) ), $this->fp( array( 'order_id' => 2 ) ) ),
			array_column( $records, 'args_fingerprint' )
		);
	}

	/**
	 * Equivalent actions (Action Scheduler allows them without $unique) all stay.
	 */
	public function test_duplicate_actions_are_preserved() {
		$row      = AS_Fixture::recurring( 'acme_poll', self::T, 300 );
		$snapshot = AS_Fixture::snapshot( array( $row, $row, $row ) );

		$this->assertCount( 3, $snapshot->get_actions() );
		$this->assertSame( 3, $snapshot->get_summary()->get_action_count() );
		$this->assertSame( 1, $snapshot->get_summary()->get_unique_hook_count() );
	}

	/**
	 * Arguments over 191 bytes are read from `extended_args`; the MD5 in `args` is ignored.
	 */
	public function test_long_args_use_extended_args() {
		$args = array( 'payload' => str_repeat( 'x', 300 ) );
		$row  = AS_Fixture::single( 'acme_long', self::T, $args );

		$this->assertNotNull( $row['extended_args'] );
		$this->assertSame( 32, strlen( $row['args'] ) );
		$this->assertSame( $this->fp( $args ), AS_Fixture::snapshot( array( $row ) )->get_actions()[0]->get_args_fingerprint() );

		// A different MD5 in `args` does not matter: it is only Action Scheduler's index value.
		$row['args'] = str_repeat( '0', 32 );
		$this->assertSame( $this->fp( $args ), AS_Fixture::snapshot( array( $row ) )->get_actions()[0]->get_args_fingerprint() );
	}

	/**
	 * Arguments are fingerprinted as stored JSON: key order and types count, like Action Scheduler's lookups.
	 */
	public function test_args_fingerprint_follows_stored_json() {
		$a = AS_Fixture::single(
			'acme',
			self::T,
			array(
				'a' => 1,
				'b' => 2,
			)
		);
		$b = AS_Fixture::single(
			'acme',
			self::T,
			array(
				'b' => 2,
				'a' => 1,
			)
		);
		$c = AS_Fixture::single(
			'acme',
			self::T,
			array(
				'a' => '1',
				'b' => 2,
			)
		);

		$fingerprints = array_column( $this->records( AS_Fixture::snapshot( array( $a, $b, $c ) ) ), 'args_fingerprint' );

		$this->assertCount( 3, array_unique( $fingerprints ) );
	}

	/**
	 * No group row (group_id 0, or a deleted group) and an empty slug are both "no group".
	 */
	public function test_missing_and_empty_group_are_no_group() {
		$records = $this->records(
			AS_Fixture::snapshot(
				array(
					AS_Fixture::single( 'acme', self::T, array(), null ),
					AS_Fixture::single( 'acme', self::T, array(), '' ),
				)
			)
		);

		$this->assertSame( array( '', '' ), array_column( $records, 'group' ) );
		$this->assertSame( $records[0], $records[1] );
	}

	/**
	 * Same output for any input order; identical input gives identical snapshots.
	 */
	public function test_deterministic_and_order_independent() {
		$rows = array(
			AS_Fixture::single( 'b_hook', self::T + 10, array( 2 ) ),
			AS_Fixture::recurring( 'a_hook', self::T + 20, 60, array(), 'g2' ),
			AS_Fixture::recurring( 'a_hook', self::T + 20, 60, array(), 'g1' ),
			AS_Fixture::single( 'b_hook', self::T + 5, array( 2 ) ),
			AS_Fixture::async( 'Z_hook', self::T ),
			AS_Fixture::cron( 'a_hook', self::T + 20, '0 * * * *', array(), 'g1' ),
		);

		$expected = AS_Fixture::snapshot( $rows )->to_array();
		$this->assertSame( $expected, AS_Fixture::snapshot( array_reverse( $rows ) )->to_array() );
		$this->assertSame( $expected, AS_Fixture::snapshot( $rows )->to_array() );
		$this->assertSame(
			array( array( 'Z_hook', '' ), array( 'a_hook', 'g1' ), array( 'a_hook', 'g1' ), array( 'a_hook', 'g2' ), array( 'b_hook', '' ), array( 'b_hook', '' ) ),
			array_map(
				static function ( $r ) {
					return array( $r['hook'], $r['group'] );
				},
				$expected['actions']
			)
		);
		$this->assertSame( array( self::T + 5, self::T + 10 ), array( $expected['actions'][4]['timestamp'], $expected['actions'][5]['timestamp'] ) );
	}

	/**
	 * Rows can come from a generator (the provider streams batches).
	 */
	public function test_rows_from_generator() {
		$rows      = array( AS_Fixture::single( 'acme_a', self::T ), AS_Fixture::single( 'acme_b', self::T ) );
		$generator = ( static function () use ( $rows ) {
			foreach ( $rows as $row ) {
				yield $row;
			}
		} )();

		$this->assertSame( AS_Fixture::snapshot( $rows )->to_array(), AS_Fixture::snapshot( $generator )->to_array() );
	}

	/**
	 * Schedules UpdateLens does not normalize fail the snapshot instead of being guessed.
	 *
	 * @dataProvider provide_unsupported_schedules
	 *
	 * @param string $schedule Stored schedule.
	 */
	public function test_unsupported_schedule( $schedule ) {
		$this->assertMalformed( array( AS_Fixture::row( 'acme', self::T, $schedule ) ), MalformedActionSchedulerStateException::UNSUPPORTED_SCHEDULE );
	}

	/**
	 * Unsupported schedule classes.
	 *
	 * @return array<string, array>
	 */
	public function provide_unsupported_schedules() {
		return array(
			'custom class'            => array( AS_Fixture::object( 'Acme_Business_Hours_Schedule', array( "\0*\0scheduled_timestamp" => 'i:' . self::T . ';' ) ) ),
			'canceled schedule'       => array( AS_Fixture::object( 'ActionScheduler_CanceledSchedule', array() ) ),
			'subclass of a supported' => array( AS_Fixture::object( 'Acme_IntervalSchedule', array( "\0*\0recurrence" => 'i:60;' ) ) ),
		);
	}

	/**
	 * Malformed rows fail the whole snapshot with a fixed reason, never containing the data.
	 *
	 * @dataProvider provide_malformed_rows
	 *
	 * @param mixed  $row    Row.
	 * @param string $reason Expected reason.
	 */
	public function test_malformed_row_fails( $row, $reason ) {
		$this->assertMalformed( array( AS_Fixture::single( 'acme_valid', self::T ), $row ), $reason );
	}

	/**
	 * Malformed rows.
	 *
	 * @return array<string, array>
	 */
	public function provide_malformed_rows() {
		$secret = array( 'token' => self::FAKE_SECRET );
		$valid  = AS_Fixture::single( 'acme', self::T, $secret );
		$with   = static function ( array $changes ) use ( $valid ) {
			return array_merge( $valid, $changes );
		};
		$cron   = static function ( array $parts ) {
			$items = '';
			foreach ( $parts as $index => $part ) {
				$items .= 'i:' . $index . ';' . ( is_string( $part ) ? AS_Fixture::string( $part ) : 'i:' . $part . ';' );
			}
			return AS_Fixture::object(
				'ActionScheduler_CronSchedule',
				array( "\0*\0recurrence" => AS_Fixture::object( 'CronExpression', array( "\0CronExpression\0cronParts" => 'a:' . count( $parts ) . ':{' . $items . '}' ) ) )
			);
		};

		return array(
			'row not an array'            => array( self::FAKE_SECRET, MalformedActionSchedulerStateException::INVALID_ACTION ),
			'hook missing'                => array( $with( array( 'hook' => null ) ), MalformedActionSchedulerStateException::INVALID_ACTION ),
			'hook not UTF-8'              => array( $with( array( 'hook' => "acme\xff" ) ), MalformedActionSchedulerStateException::INVALID_ACTION ),
			'group not UTF-8'             => array( $with( array( 'group' => "acme\xc3" ) ), MalformedActionSchedulerStateException::INVALID_ACTION ),
			'group not a string'          => array( $with( array( 'group' => array( self::FAKE_SECRET ) ) ), MalformedActionSchedulerStateException::INVALID_ACTION ),
			'status complete'             => array( $with( array( 'status' => 'complete' ) ), MalformedActionSchedulerStateException::INVALID_ACTION ),
			'status unknown'              => array( $with( array( 'status' => self::FAKE_SECRET ) ), MalformedActionSchedulerStateException::INVALID_ACTION ),
			'zero date'                   => array( $with( array( 'scheduled_date_gmt' => '0000-00-00 00:00:00' ) ), MalformedActionSchedulerStateException::INVALID_ACTION ),
			'null date'                   => array( $with( array( 'scheduled_date_gmt' => null ) ), MalformedActionSchedulerStateException::INVALID_ACTION ),
			'impossible date'             => array( $with( array( 'scheduled_date_gmt' => '2026-02-30 00:00:00' ) ), MalformedActionSchedulerStateException::INVALID_ACTION ),
			'date with zone'              => array( $with( array( 'scheduled_date_gmt' => '2026-01-01T00:00:00Z' ) ), MalformedActionSchedulerStateException::INVALID_ACTION ),
			'args not JSON'               => array( $with( array( 'args' => 'token=' . self::FAKE_SECRET ) ), MalformedActionSchedulerStateException::INVALID_ARGS ),
			'args JSON scalar'            => array( $with( array( 'args' => '"' . self::FAKE_SECRET . '"' ) ), MalformedActionSchedulerStateException::INVALID_ARGS ),
			'args missing'                => array( $with( array( 'args' => null ) ), MalformedActionSchedulerStateException::INVALID_ARGS ),
			'MD5 without extended_args'   => array( $with( array( 'args' => md5( self::FAKE_SECRET ) ) ), MalformedActionSchedulerStateException::INVALID_ARGS ),
			'extended_args not JSON'      => array( $with( array( 'extended_args' => '{' . self::FAKE_SECRET ) ), MalformedActionSchedulerStateException::INVALID_ARGS ),
			'schedule missing'            => array( $with( array( 'schedule' => null ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'schedule not serialized'     => array( $with( array( 'schedule' => self::FAKE_SECRET ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'schedule serialized array'   => array( $with( array( 'schedule' => 'a:1:{i:0;s:3:"abc";}' ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'schedule truncated'          => array( $with( array( 'schedule' => substr( AS_Fixture::interval_schedule( self::T, 60 ), 0, -3 ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'schedule too long'           => array( $with( array( 'schedule' => AS_Fixture::object( 'ActionScheduler_SimpleSchedule', array( 'pad' => AS_Fixture::string( str_repeat( 'x', 70000 ) ) ) ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'interval missing'            => array( $with( array( 'schedule' => AS_Fixture::object( 'ActionScheduler_IntervalSchedule', array() ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'interval float'              => array( $with( array( 'schedule' => AS_Fixture::object( 'ActionScheduler_IntervalSchedule', array( "\0*\0recurrence" => 'd:1.5;' ) ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'interval text'               => array( $with( array( 'schedule' => AS_Fixture::object( 'ActionScheduler_IntervalSchedule', array( "\0*\0recurrence" => AS_Fixture::string( self::FAKE_SECRET ) ) ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'cron missing'                => array( $with( array( 'schedule' => AS_Fixture::object( 'ActionScheduler_CronSchedule', array() ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'cron too few fields'         => array( $with( array( 'schedule' => $cron( array( '0', '*', '*', '*' ) ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'cron too many fields'        => array( $with( array( 'schedule' => $cron( array( '0', '*', '*', '*', '*', '*', '*' ) ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'cron field with text'        => array( $with( array( 'schedule' => $cron( array( '0', '*', '*', '*', self::FAKE_SECRET . '!' ) ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'cron field not a string'     => array( $with( array( 'schedule' => $cron( array( '0', '*', '*', '*', 5 ) ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'cron recurrence other class' => array( $with( array( 'schedule' => AS_Fixture::object( 'ActionScheduler_CronSchedule', array( "\0*\0recurrence" => AS_Fixture::object( 'Acme_Expression', array() ) ) ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'cron string with text'       => array( $with( array( 'schedule' => AS_Fixture::object( 'ActionScheduler_CronSchedule', array( "\0*\0recurrence" => AS_Fixture::string( 'token ' . self::FAKE_SECRET . ' * * * *' ) ) ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
			'too deeply nested'           => array( $with( array( 'schedule' => AS_Fixture::object( 'ActionScheduler_IntervalSchedule', array( "\0*\0recurrence" => str_repeat( 'a:1:{i:0;', 20 ) . 'i:1;' . str_repeat( '}', 20 ) ) ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE ),
		);
	}

	/**
	 * Stored schedules are never instantiated: classes in them are not woken up.
	 */
	public function test_schedules_are_never_instantiated() {
		TrapSchedule::$woken = false;
		$trap                = AS_Fixture::object( TrapSchedule::class, array() );

		$this->assertMalformed( array( AS_Fixture::row( 'acme', self::T, AS_Fixture::object( 'ActionScheduler_IntervalSchedule', array( "\0*\0recurrence" => $trap ) ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE );
		$this->assertMalformed( array( AS_Fixture::row( 'acme', self::T, AS_Fixture::object( 'ActionScheduler_CronSchedule', array( "\0*\0recurrence" => $trap ) ) ) ), MalformedActionSchedulerStateException::INVALID_SCHEDULE );
		$this->assertMalformed( array( AS_Fixture::row( 'acme', self::T, $trap ) ), MalformedActionSchedulerStateException::UNSUPPORTED_SCHEDULE );

		// A supported schedule with an extra nested object is read without instantiating it either.
		$action = AS_Fixture::snapshot( array( AS_Fixture::row( 'acme', self::T, AS_Fixture::object( 'ActionScheduler_SimpleSchedule', array( 'extra' => $trap ) ) ) ) )->get_actions()[0];
		$this->assertSame( 'single', $action->get_schedule_type() );
		$this->assertFalse( TrapSchedule::$woken );
	}

	/**
	 * No argument value appears in any form of the snapshot.
	 */
	public function test_privacy() {
		$snapshot = AS_Fixture::snapshot(
			array(
				AS_Fixture::single( 'acme_webhook', self::T, array( 'https://hooks.example.test/' . self::FAKE_SECRET ), 'acme' ),
				AS_Fixture::recurring(
					'acme_mail',
					self::T + 1,
					3600,
					array(
						'to'      => 'person@example.test',
						'token'   => self::FAKE_SECRET,
						'payload' => str_repeat( 'x', 300 ), // Long: stored in extended_args.
					)
				),
			)
		);

		// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Inspecting every output form.
		$outputs = array(
			'serialize( snapshot )' => serialize( $snapshot ),
			'json_encode'           => json_encode( $snapshot->to_array() ),
			'print_r( snapshot )'   => print_r( $snapshot, true ),
			'var_export'            => var_export( $snapshot->to_array(), true ),
			'serialize( summary )'  => serialize( $snapshot->get_summary() ),
			'serialize( actions )'  => serialize( $snapshot->get_actions() ),
			'print_r( actions )'    => print_r( $snapshot->get_actions(), true ),
			'print_r( hasher )'     => print_r( new ActionSchedulerArgsHasher( AS_Fixture::SECRET ), true ),
		);
		// phpcs:enable

		foreach ( $outputs as $label => $output ) {
			foreach ( array( self::FAKE_SECRET, 'person@example.test', 'hooks.example.test', 'token', 'xxxxxxxx', AS_Fixture::SECRET ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $output, $label );
			}
		}
	}

	/**
	 * Fingerprint context: same secret and scheme → same; other secret → different.
	 */
	public function test_fingerprint_context() {
		$rows = array( AS_Fixture::single( 'acme', self::T, array( 1 ) ) );
		$a    = AS_Fixture::snapshot( $rows );
		$b    = AS_Fixture::snapshot( $rows );
		$c    = AS_Fixture::snapshot( $rows, 'rotated-salts' );

		$this->assertSame( $a->get_fingerprint_context(), $b->get_fingerprint_context() );
		$this->assertNotSame( $a->get_fingerprint_context(), $c->get_fingerprint_context() );
		$this->assertNotSame( $a->get_actions()[0]->get_args_fingerprint(), $c->get_actions()[0]->get_args_fingerprint() );
		$this->assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', $a->get_actions()[0]->get_args_fingerprint() );
	}

	/**
	 * Action arguments have their own fingerprint domain.
	 */
	public function test_domain_separation() {
		$labels = array( ActionSchedulerArgsHasher::KEY_CONTEXT, CronArgsHasher::KEY_CONTEXT, OptionValueHasher::KEY_CONTEXT );
		$this->assertCount( 3, array_unique( $labels ) );
		$this->assertSame( 'updatelens:action-scheduler-args:v1', ActionSchedulerArgsHasher::KEY_CONTEXT );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Same bytes in both domains.
		$bytes = serialize( array( 1 ) );
		$this->assertNotSame(
			( new CronArgsHasher( AS_Fixture::SECRET ) )->fingerprint( array( 1 ) ),
			( new ActionSchedulerArgsHasher( AS_Fixture::SECRET ) )->fingerprint( $bytes )
		);
		$this->assertNotSame(
			( new CronArgsHasher( AS_Fixture::SECRET ) )->get_context(),
			( new ActionSchedulerArgsHasher( AS_Fixture::SECRET ) )->get_context()
		);
	}

	/**
	 * A large active population: all rows kept, deterministic.
	 */
	public function test_large_synthetic_population() {
		$rows = array();
		for ( $i = 0; $i < 10000; $i++ ) {
			$rows[] = 0 === $i % 10
				? AS_Fixture::recurring( 'acme_poll_' . ( $i % 7 ), self::T + $i, 3600, array( 'n' => $i ), 'acme' )
				: AS_Fixture::single(
					'wc_admin_import_' . ( $i % 13 ),
					self::T + $i,
					array(
						'order_id' => $i,
						'token'    => self::FAKE_SECRET,
					)
				);
		}

		$snapshot = AS_Fixture::snapshot( $rows );
		$summary  = $snapshot->get_summary();

		$this->assertSame( array( 10000, 1000, 9000, 20 ), array( $summary->get_action_count(), $summary->get_recurring_action_count(), $summary->get_single_action_count(), $summary->get_unique_hook_count() ) );
		shuffle( $rows );
		$this->assertSame( $snapshot->to_array(), AS_Fixture::snapshot( $rows )->to_array() );
	}

	/**
	 * A snapshot requires a fingerprint context.
	 */
	public function test_snapshot_requires_context() {
		$this->expectException( InvalidArgumentException::class );

		new ActionSchedulerSnapshot( array(), '' );
	}
}
