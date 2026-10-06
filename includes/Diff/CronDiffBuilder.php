<?php
/**
 * Compares two WP-Cron snapshots.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\CronEventRecord;
use UpdateLens\Snapshot\CronSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a CronDiff from a before and an after CronSnapshot.
 *
 * Pure: works only on the snapshot objects, never reads WordPress.
 * Fingerprints are compared here and do not appear in the result.
 *
 * Events are matched within their logical identity (hook + arguments
 * fingerprint), in three passes, each pairing instances in snapshot order
 * (timestamp ascending):
 *
 * 1. same timestamp and recurrence → unchanged (not reported);
 * 2. same recurrence, other timestamp → rescheduled;
 * 3. other recurrence → changed.
 *
 * Instances left over are added or removed. Different arguments are a
 * different identity, so an argument change shows as removed + added: the
 * arguments are unknown, and UpdateLens does not guess they belong together.
 */
final class CronDiffBuilder {

	/**
	 * Compare two snapshots.
	 *
	 * @param CronSnapshot $before Snapshot before.
	 * @param CronSnapshot $after  Snapshot after.
	 * @return CronDiff
	 * @throws IncompatibleSnapshotsException If the snapshots have different fingerprint contexts.
	 */
	public function build( CronSnapshot $before, CronSnapshot $after ) {
		if ( $before->get_fingerprint_context() !== $after->get_fingerprint_context() ) {
			throw IncompatibleSnapshotsException::fingerprint_context_mismatch();
		}

		$before_groups = self::group( $before->get_events() );
		$after_groups  = self::group( $after->get_events() );

		$added       = array();
		$removed     = array();
		$rescheduled = array();
		$changed     = array();

		foreach ( $before_groups + $after_groups as $identity => $unused ) {
			$old = isset( $before_groups[ $identity ] ) ? $before_groups[ $identity ] : array();
			$new = isset( $after_groups[ $identity ] ) ? $after_groups[ $identity ] : array();

			// Pass 1: unchanged instances are paired off and not reported.
			self::pair( $old, $new, true, true );

			// Pass 2: same recurrence, other timestamp.
			foreach ( self::pair( $old, $new, false, true ) as $match ) {
				$rescheduled[] = new RescheduledCronEvent( $match[0], $match[1] );
			}

			// Pass 3: other recurrence.
			foreach ( self::pair( $old, $new, false, false ) as $match ) {
				$changed[] = new ChangedCronEvent( $match[0], $match[1] );
			}

			foreach ( $old as $record ) {
				$removed[] = new CronEventState( $record );
			}
			foreach ( $new as $record ) {
				$added[] = new CronEventState( $record );
			}
		}

		return new CronDiff(
			$added,
			$removed,
			$rescheduled,
			$changed,
			new CronDiffSummary(
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
	 * @param CronEventRecord[] $events Records in snapshot order.
	 * @return array<string, CronEventRecord[]>
	 */
	private static function group( array $events ) {
		$groups = array();
		foreach ( $events as $event ) {
			// The fixed-length fingerprint first keeps the key unambiguous for any hook name.
			$groups[ $event->get_args_fingerprint() . ':' . $event->get_hook() ][] = $event;
		}

		return $groups;
	}

	/**
	 * Pair each remaining before record with the first remaining after record that matches.
	 *
	 * Paired records are removed from both lists.
	 *
	 * @param CronEventRecord[] $before          Remaining before records (modified).
	 * @param CronEventRecord[] $after           Remaining after records (modified).
	 * @param bool              $same_timestamp  Whether the timestamps must be equal.
	 * @param bool              $same_recurrence Whether schedule and interval must be equal.
	 * @return array<int, array{CronEventRecord, CronEventRecord}>
	 */
	private static function pair( array &$before, array &$after, $same_timestamp, $same_recurrence ) {
		$pairs = array();

		foreach ( $before as $i => $old ) {
			foreach ( $after as $j => $new ) {
				if (
					( ! $same_timestamp || $old->get_timestamp() === $new->get_timestamp() )
					&& ( ! $same_recurrence || $old->has_same_recurrence( $new ) )
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
