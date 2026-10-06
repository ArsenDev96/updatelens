<?php
/**
 * Tests for ActionSchedulerDiffBuilder.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use UpdateLens\Diff\ActionSchedulerDiff;
use UpdateLens\Diff\ActionSchedulerDiffBuilder;
use UpdateLens\Diff\IncompatibleSnapshotsException;
use UpdateLens\Tests\Support\ActionSchedulerFixture as AS_Fixture;

/**
 * Two Action Scheduler snapshots → added, removed, rescheduled and changed actions.
 */
final class ActionSchedulerDiffBuilderTest extends TestCase {

	/**
	 * Obviously fake credential used in privacy fixtures.
	 */
	const FAKE_SECRET = 'sk_test_UPDATE_LENS_ACTION_SCHEDULER_SECRET';

	const T = 1767225600; // 2026-01-01T00:00:00Z.

	/**
	 * Diff of two row lists.
	 *
	 * @param array $before Rows before.
	 * @param array $after  Rows after.
	 * @return ActionSchedulerDiff
	 */
	private function diff( array $before, array $after ) {
		return ( new ActionSchedulerDiffBuilder() )->build( AS_Fixture::snapshot( $before ), AS_Fixture::snapshot( $after ) );
	}

	/**
	 * Row with another status.
	 *
	 * @param array  $row    Row.
	 * @param string $status Status.
	 * @return array
	 */
	private function with_status( array $row, $status ) {
		$row['status'] = $status;
		return $row;
	}

	/**
	 * Identical state: no changes.
	 */
	public function test_no_change() {
		$rows = array(
			AS_Fixture::recurring( 'woocommerce_cleanup_draft_orders', self::T + 3600, 86400 ),
			AS_Fixture::cron( 'acme_sync', self::T + 60, '0 */6 * * *', array(), 'acme' ),
			AS_Fixture::async( 'acme_import', self::T, array( 1 ) ),
		);
		$diff = $this->diff( $rows, array_reverse( $rows ) );

		$this->assertFalse( $diff->has_changes() );
		$this->assertSame( array( array(), array(), array(), array() ), array( $diff->get_added(), $diff->get_removed(), $diff->get_rescheduled(), $diff->get_changed() ) );
		$this->assertSame(
			array(
				'before_action_count'      => 3,
				'after_action_count'       => 3,
				'action_count_delta'       => 0,
				'before_recurring_count'   => 2,
				'after_recurring_count'    => 2,
				'recurring_count_delta'    => 0,
				'before_single_count'      => 1,
				'after_single_count'       => 1,
				'single_count_delta'       => 0,
				'before_unique_hook_count' => 3,
				'after_unique_hook_count'  => 3,
				'unique_hook_count_delta'  => 0,
				'added_count'              => 0,
				'removed_count'            => 0,
				'rescheduled_count'        => 0,
				'changed_count'            => 0,
			),
			$diff->get_summary()->to_array()
		);
	}

	/**
	 * Empty before and after: no changes.
	 */
	public function test_empty_snapshots() {
		$this->assertFalse( $this->diff( array(), array() )->has_changes() );
	}

	/**
	 * A new action: added, with its state after.
	 */
	public function test_added() {
		$diff = $this->diff( array(), array( AS_Fixture::recurring( 'woocommerce_cleanup_draft_orders', self::T + 3600, 86400, array(), 'woocommerce' ) ) );

		$this->assertTrue( $diff->has_changes() );
		$this->assertSame(
			array(
				array(
					'hook'            => 'woocommerce_cleanup_draft_orders',
					'group'           => 'woocommerce',
					'status'          => 'pending',
					'timestamp'       => self::T + 3600,
					'schedule_type'   => 'interval',
					'interval'        => 86400,
					'cron_expression' => null,
					'is_recurring'    => true,
				),
			),
			$diff->to_array()['added']
		);
		$this->assertSame( array( 1, 1, 1 ), array( $diff->get_summary()->get_added_count(), $diff->get_summary()->get_action_count_delta(), $diff->get_summary()->get_recurring_count_delta() ) );
	}

	/**
	 * An action that is no longer active (ran, failed or was canceled): removed, with its state before.
	 */
	public function test_removed() {
		$diff = $this->diff( array( AS_Fixture::single( 'woocommerce_run_update_callback', self::T, array( 'update_callback' => 'x' ), 'woocommerce-db-updates' ) ), array() );

		$this->assertSame( 1, $diff->get_summary()->get_removed_count() );
		$this->assertSame( 'woocommerce_run_update_callback', $diff->get_removed()[0]->get_hook() );
		$this->assertSame( 'woocommerce-db-updates', $diff->get_removed()[0]->get_group() );
		$this->assertSame( -1, $diff->get_summary()->get_single_count_delta() );
	}

	/**
	 * A recurring action that ran: Action Scheduler stores the next run as a new row, seen as rescheduled.
	 */
	public function test_recurring_run_is_rescheduled() {
		$diff = $this->diff(
			array( AS_Fixture::recurring( 'action_scheduler/migration_hook', self::T, 3600 ) ),
			array( AS_Fixture::recurring( 'action_scheduler/migration_hook', self::T + 3600, 3600 ) )
		);

		$this->assertSame(
			array(
				array(
					'hook'             => 'action_scheduler/migration_hook',
					'group'            => '',
					'before_timestamp' => self::T,
					'after_timestamp'  => self::T + 3600,
					'timestamp_delta'  => 3600,
					'schedule_type'    => 'interval',
					'interval'         => 3600,
					'cron_expression'  => null,
					'is_recurring'     => true,
				),
			),
			$diff->to_array()['rescheduled']
		);
		$this->assertSame( array(), $diff->get_added() );
		$this->assertSame( array(), $diff->get_removed() );
	}

	/**
	 * A one-time or async action moved in time is rescheduled too.
	 */
	public function test_one_time_moved_is_rescheduled() {
		$diff = $this->diff(
			array( AS_Fixture::single( 'acme_mail', self::T, array( 1 ) ), AS_Fixture::async( 'acme_ping', self::T ) ),
			array( AS_Fixture::single( 'acme_mail', self::T - 600, array( 1 ) ), AS_Fixture::async( 'acme_ping', self::T + 30 ) )
		);

		$this->assertSame( array( -600, 30 ), array( $diff->get_rescheduled()[0]->get_timestamp_delta(), $diff->get_rescheduled()[1]->get_timestamp_delta() ) );
		$this->assertSame( array( 'single', 'async' ), array( $diff->get_rescheduled()[0]->get_schedule_type(), $diff->get_rescheduled()[1]->get_schedule_type() ) );
	}

	/**
	 * Schedule changes of the same action are changed, not removed + added.
	 *
	 * @dataProvider provide_schedule_changes
	 *
	 * @param array $before Row before.
	 * @param array $after  Row after.
	 * @param array $fields Expected changed fields.
	 */
	public function test_schedule_changed( array $before, array $after, array $fields ) {
		$diff = $this->diff( array( $before ), array( $after ) );

		$this->assertSame( array( 0, 0, 0, 1 ), array( count( $diff->get_added() ), count( $diff->get_removed() ), count( $diff->get_rescheduled() ), count( $diff->get_changed() ) ) );
		$changed = $diff->to_array()['changed'][0];
		foreach ( $fields as $key => $value ) {
			$this->assertSame( $value, $changed[ $key ], $key );
		}
	}

	/**
	 * Schedule changes.
	 *
	 * @return array<string, array>
	 */
	public function provide_schedule_changes() {
		return array(
			'daily → hourly'       => array(
				AS_Fixture::recurring( 'wc_run_batch_process', self::T + 86400, 86400 ),
				AS_Fixture::recurring( 'wc_run_batch_process', self::T + 3600, 3600 ),
				array(
					'before_interval'   => 86400,
					'after_interval'    => 3600,
					'timestamp_changed' => true,
				),
			),
			'interval, same time'  => array(
				AS_Fixture::recurring( 'acme', self::T, 60 ),
				AS_Fixture::recurring( 'acme', self::T, 300 ),
				array(
					'before_interval'   => 60,
					'after_interval'    => 300,
					'timestamp_changed' => false,
				),
			),
			'one-time → recurring' => array(
				AS_Fixture::single( 'acme', self::T ),
				AS_Fixture::recurring( 'acme', self::T, 3600 ),
				array(
					'before_schedule_type' => 'single',
					'after_schedule_type'  => 'interval',
					'before_is_recurring'  => false,
					'after_is_recurring'   => true,
				),
			),
			'interval → cron'      => array(
				AS_Fixture::recurring( 'acme', self::T, 21600 ),
				AS_Fixture::cron( 'acme', self::T, '0 */6 * * *' ),
				array(
					'before_schedule_type'  => 'interval',
					'after_schedule_type'   => 'cron',
					'before_interval'       => 21600,
					'after_interval'        => null,
					'after_cron_expression' => '0 */6 * * *',
				),
			),
			'cron expression'      => array(
				AS_Fixture::cron( 'acme', self::T, '0 */6 * * *' ),
				AS_Fixture::cron( 'acme', self::T, '0 */12 * * *' ),
				array(
					'before_cron_expression' => '0 */6 * * *',
					'after_cron_expression'  => '0 */12 * * *',
				),
			),
			'async → single'       => array(
				AS_Fixture::async( 'acme', self::T ),
				AS_Fixture::single( 'acme', self::T + 60 ),
				array(
					'before_schedule_type' => 'async',
					'after_schedule_type'  => 'single',
				),
			),
		);
	}

	/**
	 * Other arguments are another identity: removed + added, never guessed to be the same action.
	 */
	public function test_args_change_is_removed_and_added() {
		$diff = $this->diff(
			array( AS_Fixture::recurring( 'acme_sync', self::T, 3600, array( 'site' => 1 ) ) ),
			array( AS_Fixture::recurring( 'acme_sync', self::T, 3600, array( 'site' => 2 ) ) )
		);

		$this->assertSame( array( 1, 1, 0, 0 ), array( count( $diff->get_added() ), count( $diff->get_removed() ), count( $diff->get_rescheduled() ), count( $diff->get_changed() ) ) );
	}

	/**
	 * Another group is another identity, as in Action Scheduler's own uniqueness check.
	 */
	public function test_group_change_is_removed_and_added() {
		$diff = $this->diff(
			array( AS_Fixture::recurring( 'acme_sync', self::T, 3600, array(), 'acme' ) ),
			array( AS_Fixture::recurring( 'acme_sync', self::T, 3600, array(), 'acme-v2' ) )
		);

		$this->assertSame( array( 'acme-v2', 'acme' ), array( $diff->get_added()[0]->get_group(), $diff->get_removed()[0]->get_group() ) );
	}

	/**
	 * Pending → in-progress is execution, not a scheduling change.
	 */
	public function test_status_change_alone_is_not_reported() {
		$row  = AS_Fixture::single( 'acme_import', self::T, array( 1 ) );
		$diff = $this->diff( array( $row ), array( $this->with_status( $row, 'in-progress' ) ) );

		$this->assertFalse( $diff->has_changes() );
		$this->assertSame( 1, $diff->get_summary()->get_after()->get_in_progress_count() );
	}

	/**
	 * Equivalent actions are matched one to one, unchanged instances first, in time order.
	 */
	public function test_duplicate_identities() {
		$diff = $this->diff(
			array(
				AS_Fixture::recurring( 'acme_poll', self::T + 600, 300 ),
				AS_Fixture::recurring( 'acme_poll', self::T, 300 ),
			),
			array(
				AS_Fixture::recurring( 'acme_poll', self::T + 900, 300 ),
				AS_Fixture::recurring( 'acme_poll', self::T + 600, 300 ),
				AS_Fixture::recurring( 'acme_poll', self::T + 1200, 300 ),
			)
		);

		// T+600 unchanged; T → T+900 rescheduled; T+1200 added.
		$this->assertCount( 1, $diff->get_rescheduled() );
		$this->assertSame( array( self::T, self::T + 900 ), array( $diff->get_rescheduled()[0]->get_before_timestamp(), $diff->get_rescheduled()[0]->get_after_timestamp() ) );
		$this->assertSame( array( self::T + 1200 ), array( $diff->get_added()[0]->get_timestamp() ) );
		$this->assertSame( array(), $diff->get_removed() );
		$this->assertSame( 2, $diff->get_summary()->get_before()->get_action_count() );
		$this->assertSame( 3, $diff->get_summary()->get_after()->get_action_count() );
	}

	/**
	 * Exact duplicates disappearing are each reported.
	 */
	public function test_duplicates_removed() {
		$row  = AS_Fixture::async( 'acme_ping', self::T );
		$diff = $this->diff( array( $row, $row, $row ), array( $row ) );

		$this->assertCount( 2, $diff->get_removed() );
		$this->assertSame( -2, $diff->get_summary()->get_action_count_delta() );
	}

	/**
	 * Several changes at once, with summary deltas from the snapshots.
	 */
	public function test_multiple_simultaneous_changes() {
		$before = array(
			AS_Fixture::recurring( 'wc_run_batch_process', self::T + 86400, 86400 ),
			AS_Fixture::recurring( 'action_scheduler_run_recurring_actions_schedule_hook', self::T, 86400, array(), 'ActionScheduler' ),
			AS_Fixture::single( 'woocommerce_run_update_callback', self::T, array( 'update_callback' => 'a' ), 'woocommerce-db-updates' ),
			AS_Fixture::single( 'acme_unchanged', self::T + 5 ),
		);
		$after  = array(
			AS_Fixture::recurring( 'wc_run_batch_process', self::T + 3600, 3600 ),
			AS_Fixture::recurring( 'action_scheduler_run_recurring_actions_schedule_hook', self::T + 86400, 86400, array(), 'ActionScheduler' ),
			AS_Fixture::recurring( 'woocommerce_cleanup_draft_orders', self::T + 3600, 86400 ),
			AS_Fixture::async( 'woocommerce_run_product_attribute_lookup_update_callback', self::T, array( 7 ) ),
			AS_Fixture::single( 'acme_unchanged', self::T + 5 ),
		);
		$diff   = $this->diff( $before, $after );

		$this->assertSame(
			array(
				'before_action_count'      => 4,
				'after_action_count'       => 5,
				'action_count_delta'       => 1,
				'before_recurring_count'   => 2,
				'after_recurring_count'    => 3,
				'recurring_count_delta'    => 1,
				'before_single_count'      => 2,
				'after_single_count'       => 2,
				'single_count_delta'       => 0,
				'before_unique_hook_count' => 4,
				'after_unique_hook_count'  => 5,
				'unique_hook_count_delta'  => 1,
				'added_count'              => 2,
				'removed_count'            => 1,
				'rescheduled_count'        => 1,
				'changed_count'            => 1,
			),
			$diff->get_summary()->to_array()
		);
		$this->assertSame( array( 'woocommerce_cleanup_draft_orders', 'woocommerce_run_product_attribute_lookup_update_callback' ), array_column( $diff->to_array()['added'], 'hook' ) );
		$this->assertSame( array( 'woocommerce_run_update_callback' ), array_column( $diff->to_array()['removed'], 'hook' ) );
		$this->assertSame( array( 'action_scheduler_run_recurring_actions_schedule_hook' ), array_column( $diff->to_array()['rescheduled'], 'hook' ) );
		$this->assertSame( array( 'wc_run_batch_process' ), array_column( $diff->to_array()['changed'], 'hook' ) );
	}

	/**
	 * The result does not depend on input order.
	 */
	public function test_input_order_independence() {
		$before = array(
			AS_Fixture::recurring( 'acme_poll', self::T, 300 ),
			AS_Fixture::recurring( 'acme_poll', self::T + 300, 300 ),
			AS_Fixture::single( 'b', self::T, array( 1 ) ),
			AS_Fixture::single( 'b', self::T, array( 2 ) ),
			AS_Fixture::cron( 'c', self::T, '* * * * *' ),
		);
		$after  = array(
			AS_Fixture::recurring( 'acme_poll', self::T + 600, 300 ),
			AS_Fixture::recurring( 'acme_poll', self::T + 900, 300 ),
			AS_Fixture::single( 'b', self::T, array( 3 ) ),
			AS_Fixture::cron( 'c', self::T, '*/2 * * * *' ),
			AS_Fixture::async( 'd', self::T ),
		);

		$expected = $this->diff( $before, $after )->to_array();
		$this->assertSame( $expected, $this->diff( array_reverse( $before ), array_reverse( $after ) )->to_array() );
		$this->assertSame( array( 2, 2, 2, 1 ), array( count( $expected['added'] ), count( $expected['removed'] ), count( $expected['rescheduled'] ), count( $expected['changed'] ) ) );
	}

	/**
	 * Snapshots from different fingerprint contexts are never compared (no giant false diff).
	 */
	public function test_incompatible_contexts() {
		$rows = array( AS_Fixture::single( 'acme', self::T, array( 1 ) ) );

		$this->expectException( IncompatibleSnapshotsException::class );
		( new ActionSchedulerDiffBuilder() )->build( AS_Fixture::snapshot( $rows ), AS_Fixture::snapshot( $rows, 'rotated-salts' ) );
	}

	/**
	 * No argument value or fingerprint appears in any form of the diff.
	 */
	public function test_privacy() {
		$secret = array(
			'to'    => 'person@example.test',
			'token' => self::FAKE_SECRET,
		);
		$before = AS_Fixture::snapshot(
			array(
				AS_Fixture::single( 'acme_mail', self::T, $secret ),
				AS_Fixture::recurring( 'acme_webhook', self::T, 3600, array( 'https://hooks.example.test/' . self::FAKE_SECRET ) ),
				AS_Fixture::cron( 'acme_sync', self::T, '0 * * * *', $secret ),
			)
		);
		$after  = AS_Fixture::snapshot(
			array(
				AS_Fixture::single( 'acme_mail', self::T + 1, $secret ),
				AS_Fixture::recurring( 'acme_webhook', self::T, 60, array( 'https://hooks.example.test/' . self::FAKE_SECRET ) ),
				AS_Fixture::single( 'acme_new', self::T, array( str_repeat( 'x', 200 ) . self::FAKE_SECRET ) ),
			)
		);
		$diff   = ( new ActionSchedulerDiffBuilder() )->build( $before, $after );
		$this->assertSame( array( 1, 1, 1, 1 ), array( count( $diff->get_added() ), count( $diff->get_removed() ), count( $diff->get_rescheduled() ), count( $diff->get_changed() ) ) );

		// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Inspecting every output form.
		$outputs = array(
			'serialize' => serialize( $diff ),
			'json'      => json_encode( $diff->to_array() ),
			'print_r'   => print_r( $diff, true ),
			'export'    => var_export( $diff->to_array(), true ),
		);
		// phpcs:enable

		$fingerprints = array();
		foreach ( array_merge( $before->get_actions(), $after->get_actions() ) as $action ) {
			$fingerprints[] = $action->get_args_fingerprint();
		}
		foreach ( $outputs as $label => $output ) {
			foreach ( array_merge( array( self::FAKE_SECRET, 'person@example.test', 'hooks.example.test', 'token', $before->get_fingerprint_context(), 'fingerprint' ), $fingerprints ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $output, $label );
			}
		}
	}
}
