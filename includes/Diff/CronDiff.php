<?php
/**
 * Result of comparing two WP-Cron snapshots.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\CronEventOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Added, removed, rescheduled and changed events plus a summary.
 *
 * Collections are lists (a hook can appear several times), each sorted by
 * its array form field by field: hook (byte-wise) first, then timestamps,
 * then recurrence. Entries that tie are identical in every field, so the
 * result does not depend on input order. Contains no arguments and no
 * fingerprints.
 */
final class CronDiff {

	/**
	 * Added events (state after).
	 *
	 * @var CronEventState[]
	 */
	private $added;

	/**
	 * Removed events (state before).
	 *
	 * @var CronEventState[]
	 */
	private $removed;

	/**
	 * Rescheduled events.
	 *
	 * @var RescheduledCronEvent[]
	 */
	private $rescheduled;

	/**
	 * Events whose recurrence changed.
	 *
	 * @var ChangedCronEvent[]
	 */
	private $changed;

	/**
	 * Summary.
	 *
	 * @var CronDiffSummary
	 */
	private $summary;

	/**
	 * Constructor.
	 *
	 * @param CronEventState[]       $added       Added events.
	 * @param CronEventState[]       $removed     Removed events.
	 * @param RescheduledCronEvent[] $rescheduled Rescheduled events.
	 * @param ChangedCronEvent[]     $changed     Changed events.
	 * @param CronDiffSummary        $summary     Summary.
	 */
	public function __construct( array $added, array $removed, array $rescheduled, array $changed, CronDiffSummary $summary ) {
		$this->added       = self::sorted( $added );
		$this->removed     = self::sorted( $removed );
		$this->rescheduled = self::sorted( $rescheduled );
		$this->changed     = self::sorted( $changed );
		$this->summary     = $summary;
	}

	/**
	 * Sort entries by their array form.
	 *
	 * @param array<int, CronEventState|RescheduledCronEvent|ChangedCronEvent> $entries Entries.
	 * @return array<int, CronEventState|RescheduledCronEvent|ChangedCronEvent>
	 */
	private static function sorted( array $entries ) {
		$entries = array_values( $entries );
		usort(
			$entries,
			static function ( $a, $b ) {
				return CronEventOrder::compare( $a->to_array(), $b->to_array() );
			}
		);

		return $entries;
	}

	/**
	 * Added events.
	 *
	 * @return CronEventState[]
	 */
	public function get_added() {
		return $this->added;
	}

	/**
	 * Removed events.
	 *
	 * @return CronEventState[]
	 */
	public function get_removed() {
		return $this->removed;
	}

	/**
	 * Rescheduled events.
	 *
	 * @return RescheduledCronEvent[]
	 */
	public function get_rescheduled() {
		return $this->rescheduled;
	}

	/**
	 * Events whose recurrence changed.
	 *
	 * @return ChangedCronEvent[]
	 */
	public function get_changed() {
		return $this->changed;
	}

	/**
	 * Summary.
	 *
	 * @return CronDiffSummary
	 */
	public function get_summary() {
		return $this->summary;
	}

	/**
	 * Whether any event was added, removed, rescheduled or changed.
	 *
	 * @return bool
	 */
	public function has_changes() {
		return $this->added || $this->removed || $this->rescheduled || $this->changed;
	}

	/**
	 * Array form.
	 *
	 * @return array{added: array<int, array>, removed: array<int, array>, rescheduled: array<int, array>, changed: array<int, array>, summary: array<string, int>}
	 */
	public function to_array() {
		$to_arrays = static function ( array $entries ) {
			$result = array();
			foreach ( $entries as $entry ) {
				$result[] = $entry->to_array();
			}
			return $result;
		};

		return array(
			'added'       => $to_arrays( $this->added ),
			'removed'     => $to_arrays( $this->removed ),
			'rescheduled' => $to_arrays( $this->rescheduled ),
			'changed'     => $to_arrays( $this->changed ),
			'summary'     => $this->summary->to_array(),
		);
	}
}
