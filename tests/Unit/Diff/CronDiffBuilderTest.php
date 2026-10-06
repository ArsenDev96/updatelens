<?php
/**
 * Tests for CronDiffBuilder and the diff it produces.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use UpdateLens\Diff\CronDiff;
use UpdateLens\Diff\CronDiffBuilder;
use UpdateLens\Diff\IncompatibleSnapshotsException;
use UpdateLens\Snapshot\CronEventRecord;
use UpdateLens\Snapshot\CronSnapshot;
use UpdateLens\Tests\Support\CronFixture;

/**
 * Before cron snapshot + after cron snapshot → safe diff.
 */
final class CronDiffBuilderTest extends TestCase {

	/**
	 * Obviously fake credential used in privacy fixtures.
	 */
	const FAKE_SECRET = 'sk_test_UPDATE_LENS_CRON_SECRET';

	const T = 1767225600; // 2026-01-01T00:00:00Z.

	/**
	 * Diff two event lists.
	 *
	 * @param array $before Events before (CronFixture::single()/recurring()).
	 * @param array $after  Events after.
	 * @return CronDiff
	 */
	private function diff( array $before, array $after ) {
		return ( new CronDiffBuilder() )->build(
			CronFixture::snapshot( CronFixture::cron( $before ) ),
			CronFixture::snapshot( CronFixture::cron( $after ) )
		);
	}

	/**
	 * A typical site's events.
	 *
	 * @return array
	 */
	private function baseline() {
		return array(
			CronFixture::recurring( self::T, 'wp_version_check', 'twicedaily' ),
			CronFixture::recurring( self::T + 60, 'wp_scheduled_delete', 'daily' ),
			CronFixture::recurring( self::T + 120, 'acme_sync', 'hourly', array( 'feed' => 1 ) ),
			CronFixture::single( self::T + 300, 'acme_send_digest', array( 9 ) ),
		);
	}

	/**
	 * Identical states: no changes, zero deltas, every count present.
	 */
	public function test_no_change() {
		$diff = $this->diff( $this->baseline(), $this->baseline() );

		$this->assertFalse( $diff->has_changes() );
		$this->assertSame(
			array(
				'added'       => array(),
				'removed'     => array(),
				'rescheduled' => array(),
				'changed'     => array(),
				'summary'     => array(
					'before_event_count'       => 4,
					'after_event_count'        => 4,
					'event_count_delta'        => 0,
					'before_recurring_count'   => 3,
					'after_recurring_count'    => 3,
					'recurring_count_delta'    => 0,
					'before_single_count'      => 1,
					'after_single_count'       => 1,
					'single_count_delta'       => 0,
					'before_unique_hook_count' => 4,
					'after_unique_hook_count'  => 4,
					'unique_hook_count_delta'  => 0,
					'added_count'              => 0,
					'removed_count'            => 0,
					'rescheduled_count'        => 0,
					'changed_count'            => 0,
				),
			),
			$diff->to_array()
		);
	}

	/**
	 * A new event is added.
	 */
	public function test_added() {
		$after   = $this->baseline();
		$after[] = CronFixture::recurring( self::T + 900, 'acme_cleanup', 'daily' );

		$diff = $this->diff( $this->baseline(), $after );

		$this->assertTrue( $diff->has_changes() );
		$this->assertSame(
			array(
				array(
					'hook'         => 'acme_cleanup',
					'timestamp'    => self::T + 900,
					'schedule'     => 'daily',
					'interval'     => 86400,
					'is_recurring' => true,
				),
			),
			$diff->to_array()['added']
		);
		$this->assertSame( array(), $diff->get_removed() );
		$this->assertSame( 1, $diff->get_summary()->get_added_count() );
		$this->assertSame( 1, $diff->get_summary()->get_event_count_delta() );
		$this->assertSame( 1, $diff->get_summary()->get_recurring_count_delta() );
		$this->assertSame( 0, $diff->get_summary()->get_single_count_delta() );
		$this->assertSame( 1, $diff->get_summary()->get_unique_hook_count_delta() );
	}

	/**
	 * An event disappears.
	 */
	public function test_removed() {
		$after = $this->baseline();
		unset( $after[3] );

		$diff = $this->diff( $this->baseline(), $after );

		$this->assertTrue( $diff->has_changes() );
		$this->assertSame(
			array(
				array(
					'hook'         => 'acme_send_digest',
					'timestamp'    => self::T + 300,
					'schedule'     => null,
					'interval'     => null,
					'is_recurring' => false,
				),
			),
			$diff->to_array()['removed']
		);
		$this->assertSame( array(), $diff->get_added() );
		$this->assertSame( -1, $diff->get_summary()->get_event_count_delta() );
		$this->assertSame( -1, $diff->get_summary()->get_single_count_delta() );
		$this->assertSame( -1, $diff->get_summary()->get_unique_hook_count_delta() );
	}

	/**
	 * Normal recurrence movement: the same recurring event at its next run time is
	 * rescheduled, never removed + added. Otherwise every cron run would be noise.
	 */
	public function test_recurring_event_moving_to_next_run_is_rescheduled() {
		$args   = array( 'store' => 3 );
		$before = array( CronFixture::recurring( self::T + 36000, 'woocommerce_cleanup_sessions', 'daily', $args ) );
		$after  = array( CronFixture::recurring( self::T + 39600, 'woocommerce_cleanup_sessions', 'daily', $args ) );

		$diff = $this->diff( $before, $after );

		$this->assertTrue( $diff->has_changes() );
		$this->assertSame( 1, $diff->get_summary()->get_rescheduled_count() );
		$this->assertSame( 0, $diff->get_summary()->get_added_count() );
		$this->assertSame( 0, $diff->get_summary()->get_removed_count() );
		$this->assertSame( 0, $diff->get_summary()->get_changed_count() );
		$this->assertSame( 0, $diff->get_summary()->get_event_count_delta() );
		$this->assertSame(
			array(
				array(
					'hook'             => 'woocommerce_cleanup_sessions',
					'before_timestamp' => self::T + 36000,
					'after_timestamp'  => self::T + 39600,
					'timestamp_delta'  => 3600,
					'schedule'         => 'daily',
					'interval'         => 86400,
					'is_recurring'     => true,
				),
			),
			$diff->to_array()['rescheduled']
		);
	}

	/**
	 * A one-time event of the same job at a new time is rescheduled too (negative delta allowed).
	 */
	public function test_single_event_moved_is_rescheduled() {
		$diff = $this->diff(
			array( CronFixture::single( self::T + 600, 'acme_send_digest', array( 9 ) ) ),
			array( CronFixture::single( self::T + 60, 'acme_send_digest', array( 9 ) ) )
		);

		$this->assertSame( 1, $diff->get_summary()->get_rescheduled_count() );
		$this->assertSame( -540, $diff->get_rescheduled()[0]->get_timestamp_delta() );
		$this->assertFalse( $diff->get_rescheduled()[0]->is_recurring() );
	}

	/**
	 * Recurrence changes (daily → hourly) are one changed event, with or without a timestamp move.
	 */
	public function test_recurrence_change_is_changed() {
		$diff = $this->diff(
			array(
				CronFixture::recurring( self::T, 'acme_sync', 'daily' ),
				CronFixture::recurring( self::T, 'acme_report', 'weekly' ),
			),
			array(
				CronFixture::recurring( self::T + 3600, 'acme_sync', 'hourly' ),
				CronFixture::recurring( self::T, 'acme_report', 'daily' ),
			)
		);

		$this->assertSame( 2, $diff->get_summary()->get_changed_count() );
		$this->assertSame( 0, $diff->get_summary()->get_added_count() + $diff->get_summary()->get_removed_count() + $diff->get_summary()->get_rescheduled_count() );
		$this->assertSame(
			array(
				array(
					'hook'                => 'acme_report',
					'before_timestamp'    => self::T,
					'after_timestamp'     => self::T,
					'timestamp_changed'   => false,
					'before_schedule'     => 'weekly',
					'after_schedule'      => 'daily',
					'before_interval'     => 604800,
					'after_interval'      => 86400,
					'before_is_recurring' => true,
					'after_is_recurring'  => true,
				),
				array(
					'hook'                => 'acme_sync',
					'before_timestamp'    => self::T,
					'after_timestamp'     => self::T + 3600,
					'timestamp_changed'   => true,
					'before_schedule'     => 'daily',
					'after_schedule'      => 'hourly',
					'before_interval'     => 86400,
					'after_interval'      => 3600,
					'before_is_recurring' => true,
					'after_is_recurring'  => true,
				),
			),
			$diff->to_array()['changed']
		);
	}

	/**
	 * One-time ↔ recurring is a changed event; counts by type move accordingly.
	 */
	public function test_single_to_recurring_is_changed() {
		$diff = $this->diff(
			array( CronFixture::single( self::T, 'acme_import', array( 1 ) ) ),
			array( CronFixture::recurring( self::T, 'acme_import', 'hourly', array( 1 ) ) )
		);

		$changed = $diff->get_changed();
		$this->assertCount( 1, $changed );
		$this->assertFalse( $changed[0]->get_before_is_recurring() );
		$this->assertTrue( $changed[0]->get_after_is_recurring() );
		$this->assertNull( $changed[0]->get_before_schedule() );
		$this->assertNull( $changed[0]->get_before_interval() );
		$this->assertSame( 'hourly', $changed[0]->get_after_schedule() );
		$this->assertSame( 1, $diff->get_summary()->get_recurring_count_delta() );
		$this->assertSame( -1, $diff->get_summary()->get_single_count_delta() );
		$this->assertSame( 0, $diff->get_summary()->get_event_count_delta() );
	}

	/**
	 * A changed stored interval for the same schedule name is a recurrence change.
	 */
	public function test_interval_change_is_changed() {
		$diff = $this->diff(
			array( CronFixture::recurring( self::T, 'acme_poll', 'every_minute', array(), 60 ) ),
			array( CronFixture::recurring( self::T, 'acme_poll', 'every_minute', array(), 120 ) )
		);

		$this->assertSame( 1, $diff->get_summary()->get_changed_count() );
		$this->assertSame( 60, $diff->get_changed()[0]->get_before_interval() );
		$this->assertSame( 120, $diff->get_changed()[0]->get_after_interval() );
	}

	/**
	 * Same hook, different arguments: a different logical event, so removed + added, never rescheduled/changed.
	 */
	public function test_same_hook_different_args_is_removed_and_added() {
		$diff = $this->diff(
			array( CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 'feed' => 1 ) ) ),
			array( CronFixture::recurring( self::T + 3600, 'acme_sync', 'hourly', array( 'feed' => 2 ) ) )
		);

		$this->assertSame( 1, $diff->get_summary()->get_added_count() );
		$this->assertSame( 1, $diff->get_summary()->get_removed_count() );
		$this->assertSame( 0, $diff->get_summary()->get_rescheduled_count() );
		$this->assertSame( 0, $diff->get_summary()->get_changed_count() );
		$this->assertSame( 0, $diff->get_summary()->get_unique_hook_count_delta() );
	}

	/**
	 * Argument order is identity (as in WordPress): reordered arguments are a different event.
	 */
	public function test_reordered_args_are_a_different_event() {
		$diff = $this->diff(
			array( CronFixture::single( self::T, 'acme_pair', array( 'a', 'b' ) ) ),
			array( CronFixture::single( self::T, 'acme_pair', array( 'b', 'a' ) ) )
		);

		$this->assertSame( 1, $diff->get_summary()->get_added_count() );
		$this->assertSame( 1, $diff->get_summary()->get_removed_count() );
	}

	/**
	 * Several instances of one logical event: unchanged ones match first, then same
	 * recurrence (rescheduled), then the rest (changed), leftovers added/removed.
	 */
	public function test_multiple_instances_of_one_event() {
		$diff = $this->diff(
			array(
				CronFixture::single( self::T, 'acme_poll' ),
				CronFixture::recurring( self::T + 100, 'acme_poll', 'hourly' ),
				CronFixture::recurring( self::T + 200, 'acme_poll', 'hourly' ),
				CronFixture::recurring( self::T + 300, 'acme_poll', 'daily' ),
			),
			array(
				CronFixture::recurring( self::T + 200, 'acme_poll', 'hourly' ), // Unchanged.
				CronFixture::recurring( self::T + 3700, 'acme_poll', 'hourly' ), // From +100.
				CronFixture::recurring( self::T + 50, 'acme_poll', 'twicedaily' ), // From the one-time event.
			)
		);

		$this->assertSame(
			array( 0, 1, 1, 1 ),
			array(
				$diff->get_summary()->get_added_count(),
				$diff->get_summary()->get_removed_count(),
				$diff->get_summary()->get_rescheduled_count(),
				$diff->get_summary()->get_changed_count(),
			)
		);
		$this->assertSame( self::T + 100, $diff->get_rescheduled()[0]->get_before_timestamp() );
		$this->assertSame( self::T + 3700, $diff->get_rescheduled()[0]->get_after_timestamp() );
		$this->assertNull( $diff->get_changed()[0]->get_before_schedule() );
		$this->assertSame( 'twicedaily', $diff->get_changed()[0]->get_after_schedule() );
		$this->assertSame( 'daily', $diff->get_removed()[0]->get_schedule() );
	}

	/**
	 * Duplicates of one recurring event all moving are paired in timestamp order.
	 */
	public function test_duplicate_instances_pair_in_order() {
		$diff = $this->diff(
			array(
				CronFixture::recurring( self::T + 300, 'acme_dupe', 'hourly' ),
				CronFixture::recurring( self::T, 'acme_dupe', 'hourly' ),
			),
			array(
				CronFixture::recurring( self::T + 3600, 'acme_dupe', 'hourly' ),
				CronFixture::recurring( self::T + 3900, 'acme_dupe', 'hourly' ),
				CronFixture::recurring( self::T + 9000, 'acme_dupe', 'hourly' ),
			)
		);

		$this->assertSame(
			array(
				array( self::T, self::T + 3600 ),
				array( self::T + 300, self::T + 3900 ),
			),
			array_map(
				static function ( $event ) {
					return array( $event->get_before_timestamp(), $event->get_after_timestamp() );
				},
				$diff->get_rescheduled()
			)
		);
		$this->assertSame( self::T + 9000, $diff->get_added()[0]->get_timestamp() );
		$this->assertSame( 0, $diff->get_summary()->get_removed_count() );
	}

	/**
	 * Several kinds of change at once: accurate counts and stable ordering by hook, then time.
	 */
	public function test_multiple_changes() {
		$before = $this->baseline();
		$after  = array(
			CronFixture::recurring( self::T + 43200, 'wp_version_check', 'twicedaily' ), // Rescheduled.
			CronFixture::recurring( self::T + 60, 'wp_scheduled_delete', 'daily' ),     // Unchanged.
			CronFixture::recurring( self::T + 120, 'acme_sync', 'daily', array( 'feed' => 1 ) ), // Changed.
			// acme_send_digest removed.
			CronFixture::single( self::T + 10, 'acme_b_new' ),                            // Added.
			CronFixture::single( self::T + 5, 'acme_a_new' ),                             // Added.
			CronFixture::single( self::T + 1, 'acme_a_new', array( 2 ) ),                 // Added.
		);

		$diff    = $this->diff( $before, $after );
		$summary = $diff->get_summary()->to_array();

		$this->assertSame( 3, $summary['added_count'] );
		$this->assertSame( 1, $summary['removed_count'] );
		$this->assertSame( 1, $summary['rescheduled_count'] );
		$this->assertSame( 1, $summary['changed_count'] );
		$this->assertSame( 2, $summary['event_count_delta'] );
		$this->assertSame( 0, $summary['recurring_count_delta'] );
		$this->assertSame( 2, $summary['single_count_delta'] );
		$this->assertSame( 1, $summary['unique_hook_count_delta'] );

		$this->assertSame(
			array( array( 'acme_a_new', self::T + 1 ), array( 'acme_a_new', self::T + 5 ), array( 'acme_b_new', self::T + 10 ) ),
			array_map(
				static function ( $event ) {
					return array( $event->get_hook(), $event->get_timestamp() );
				},
				$diff->get_added()
			)
		);
		$this->assertSame( 'acme_send_digest', $diff->get_removed()[0]->get_hook() );
		$this->assertSame( 'wp_version_check', $diff->get_rescheduled()[0]->get_hook() );
		$this->assertSame( 'acme_sync', $diff->get_changed()[0]->get_hook() );
	}

	/**
	 * Equivalent states in any input order give identical diffs.
	 */
	public function test_input_order_independence() {
		$before = $this->baseline();
		$after  = array(
			CronFixture::recurring( self::T + 43200, 'wp_version_check', 'twicedaily' ),
			CronFixture::single( self::T, 'acme_x', array( 1 ) ),
			CronFixture::single( self::T, 'acme_x', array( 2 ) ),
			CronFixture::single( self::T, 'acme_x', array( 3 ) ),
			CronFixture::recurring( self::T + 120, 'acme_sync', 'daily', array( 'feed' => 1 ) ),
		);

		$diff     = $this->diff( $before, $after );
		$reversed = $this->diff( array_reverse( $before ), array_reverse( $after ) );

		// Records given to the snapshot in reverse snapshot order as well.
		$builder  = new CronDiffBuilder();
		$snap_a   = CronFixture::snapshot( CronFixture::cron( $before ) );
		$snap_b   = CronFixture::snapshot( CronFixture::cron( $after ) );
		$shuffled = $builder->build(
			new CronSnapshot( array_reverse( $snap_a->get_events() ), $snap_a->get_fingerprint_context() ),
			new CronSnapshot( array_reverse( $snap_b->get_events() ), $snap_b->get_fingerprint_context() )
		);

		$this->assertSame( $diff->to_array(), $reversed->to_array() );
		$this->assertSame( $diff->to_array(), $shuffled->to_array() );
		$this->assertEquals( $diff, $shuffled );
		$this->assertCount( 3, $diff->get_added() );
	}

	/**
	 * Rotated salts: identical state under different contexts fails instead of reporting everything as removed + added.
	 */
	public function test_different_fingerprint_context_fails() {
		$cron = CronFixture::cron( $this->baseline() );

		$this->expectException( IncompatibleSnapshotsException::class );
		$this->expectExceptionMessage( 'different fingerprint contexts' );

		( new CronDiffBuilder() )->build( CronFixture::snapshot( $cron, 'old-salts' ), CronFixture::snapshot( $cron, 'new-salts' ) );
	}

	/**
	 * A different scheme (context string) fails even with matching records.
	 */
	public function test_different_scheme_context_fails() {
		$events = array( new CronEventRecord( 'acme', self::T, null, null, str_repeat( 'a', 64 ) ) );

		$this->expectException( IncompatibleSnapshotsException::class );

		( new CronDiffBuilder() )->build(
			new CronSnapshot( $events, 'cron-args-hmac-sha256-v1:' . str_repeat( '0', 64 ) ),
			new CronSnapshot( $events, 'cron-args-hmac-sha256-v2:' . str_repeat( '0', 64 ) )
		);
	}

	/**
	 * No argument value, fingerprint, context or secret appears in any form of the diff.
	 */
	public function test_privacy() {
		$secret_args = array(
			'https://hooks.example.test/' . self::FAKE_SECRET,
			array(
				'email' => 'person@example.test',
				'token' => self::FAKE_SECRET,
			),
		);
		$before      = CronFixture::snapshot(
			CronFixture::cron(
				array(
					CronFixture::recurring( self::T, 'acme_moved', 'hourly', $secret_args ),
					CronFixture::recurring( self::T, 'acme_changed', 'daily', $secret_args ),
					CronFixture::single( self::T, 'acme_removed', $secret_args ),
					CronFixture::single( self::T, 'acme_same', $secret_args ),
				)
			)
		);
		$after       = CronFixture::snapshot(
			CronFixture::cron(
				array(
					CronFixture::recurring( self::T + 3600, 'acme_moved', 'hourly', $secret_args ),
					CronFixture::recurring( self::T, 'acme_changed', 'hourly', $secret_args ),
					CronFixture::single( self::T, 'acme_added', $secret_args ),
					CronFixture::single( self::T, 'acme_same', $secret_args ),
				)
			)
		);
		$diff        = ( new CronDiffBuilder() )->build( $before, $after );

		// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Inspecting every output form.
		$outputs = array(
			'serialize( diff )'        => serialize( $diff ),
			'json_encode'              => json_encode( $diff->to_array() ),
			'print_r( diff )'          => print_r( $diff, true ),
			'var_export'               => var_export( $diff->to_array(), true ),
			'summary'                  => print_r( $diff->get_summary(), true ),
			'added'                    => print_r( $diff->get_added(), true ),
			'removed'                  => print_r( $diff->get_removed(), true ),
			'rescheduled'              => print_r( $diff->get_rescheduled(), true ),
			'changed'                  => print_r( $diff->get_changed(), true ),
			'serialize( rescheduled )' => serialize( $diff->get_rescheduled() ),
		);
		// phpcs:enable

		$forbidden = array( self::FAKE_SECRET, 'person@example.test', 'hooks.example.test', 'token', CronFixture::SECRET, $before->get_fingerprint_context() );
		foreach ( array_merge( $before->get_events(), $after->get_events() ) as $event ) {
			$forbidden[] = $event->get_args_fingerprint();
		}

		foreach ( $outputs as $label => $output ) {
			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString( $needle, $output, $label );
			}
		}

		$this->assertSame(
			array( 1, 1, 1, 1 ),
			array(
				$diff->get_summary()->get_added_count(),
				$diff->get_summary()->get_removed_count(),
				$diff->get_summary()->get_rescheduled_count(),
				$diff->get_summary()->get_changed_count(),
			)
		);
	}
}
