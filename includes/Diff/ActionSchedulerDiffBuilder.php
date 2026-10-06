<?php
/**
 * Compares two Action Scheduler snapshots.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\ActionSchedulerActionRecord;
use UpdateLens\Snapshot\ActionSchedulerSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Builds an ActionSchedulerDiff from a before and an after snapshot.
 *
 * Pure: works only on the snapshot objects, never reads WordPress.
 * Fingerprints are compared here and do not appear in the result.
 *
 * Actions are matched within their logical identity (hook + group +
 * arguments fingerprint), in three passes, each pairing instances in
 * snapshot order (timestamp ascending):
 *
 * 1. same scheduled run and schedule → unchanged (not reported);
 * 2. same schedule, other scheduled run → rescheduled;
 * 3. other schedule (type, interval or cron expression) → changed.
 *
 * Instances left over are added or removed. The status is not compared:
 * pending → in-progress is execution, not a scheduling change, and actions
 * that completed, failed or were canceled are no longer in a snapshot, so
 * they show as removed. Different arguments or groups are a different
 * identity, so such a change shows as removed + added: the arguments are
 * unknown, and UpdateLens does not guess they belong together.
 */
final class ActionSchedulerDiffBuilder {

	/**
	 * Compare two snapshots.
	 *
	 * @param ActionSchedulerSnapshot $before Snapshot before.
	 * @param ActionSchedulerSnapshot $after  Snapshot after.
	 * @return ActionSchedulerDiff
	 * @throws IncompatibleSnapshotsException If the snapshots have different fingerprint contexts.
	 */
	public function build( ActionSchedulerSnapshot $before, ActionSchedulerSnapshot $after ) {
		if ( $before->get_fingerprint_context() !== $after->get_fingerprint_context() ) {
			throw IncompatibleSnapshotsException::fingerprint_context_mismatch();
		}

		$before_groups = self::group( $before->get_actions() );
		$after_groups  = self::group( $after->get_actions() );

		$added       = array();
		$removed     = array();
		$rescheduled = array();
		$changed     = array();

		foreach ( $before_groups + $after_groups as $identity => $unused ) {
			$old = isset( $before_groups[ $identity ] ) ? $before_groups[ $identity ] : array();
			$new = isset( $after_groups[ $identity ] ) ? $after_groups[ $identity ] : array();

			// Pass 1: unchanged instances are paired off and not reported.
			self::pair( $old, $new, true, true );

			// Pass 2: same schedule, other scheduled run.
			foreach ( self::pair( $old, $new, false, true ) as $match ) {
				$rescheduled[] = new RescheduledAction( $match[0], $match[1] );
			}

			// Pass 3: other schedule.
			foreach ( self::pair( $old, $new, false, false ) as $match ) {
				$changed[] = new ChangedAction( $match[0], $match[1] );
			}

			foreach ( $old as $record ) {
				$removed[] = new ActionSchedulerActionState( $record );
			}
			foreach ( $new as $record ) {
				$added[] = new ActionSchedulerActionState( $record );
			}
		}

		return new ActionSchedulerDiff(
			$added,
			$removed,
			$rescheduled,
			$changed,
			new ActionSchedulerDiffSummary(
				$before->get_summary(),
				$after->get_summary(),
				count( $added ),
				count( $removed ),
				count( $rescheduled ),
				count( $changed )
			)
		);
	}

	/**
	 * Group records by logical identity, keeping snapshot order within a group.
	 *
	 * @param ActionSchedulerActionRecord[] $actions Records in snapshot order.
	 * @return array<string, ActionSchedulerActionRecord[]>
	 */
	private static function group( array $actions ) {
		$groups = array();
		foreach ( $actions as $action ) {
			// Fixed-length fingerprint first, then the group's byte length, keeps the key unambiguous.
			$key              = $action->get_args_fingerprint() . ':' . strlen( $action->get_group() ) . ':' . $action->get_group() . ':' . $action->get_hook();
			$groups[ $key ][] = $action;
		}

		return $groups;
	}

	/**
	 * Pair each remaining before record with the first remaining after record that matches.
	 *
	 * Paired records are removed from both lists.
	 *
	 * @param ActionSchedulerActionRecord[] $before         Remaining before records (modified).
	 * @param ActionSchedulerActionRecord[] $after          Remaining after records (modified).
	 * @param bool                          $same_timestamp Whether the scheduled runs must be equal.
	 * @param bool                          $same_schedule  Whether the schedules must be equal.
	 * @return array<int, array{ActionSchedulerActionRecord, ActionSchedulerActionRecord}>
	 */
	private static function pair( array &$before, array &$after, $same_timestamp, $same_schedule ) {
		$pairs = array();
		foreach ( $before as $i => $old ) {
			foreach ( $after as $j => $new ) {
				if (
					( ! $same_timestamp || $old->get_timestamp() === $new->get_timestamp() )
					&& ( ! $same_schedule || $old->has_same_schedule( $new ) )
				) {
					$pairs[] = array( $old, $new );
					unset( $before[ $i ], $after[ $j ] );
					break;
				}
			}
		}

		return $pairs;
	}
}
