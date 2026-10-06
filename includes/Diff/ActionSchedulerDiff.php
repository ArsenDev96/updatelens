<?php
/**
 * Result of comparing two Action Scheduler snapshots.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\CronEventOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Added, removed, rescheduled and changed active actions, plus a summary.
 *
 * Observed changes between two snapshots, not causes: other plugins and
 * normal queue runs change Action Scheduler state too. Contains no
 * arguments, fingerprints or database IDs. Every list is sorted by its
 * array form (hook first), so the result does not depend on input order.
 */
final class ActionSchedulerDiff {

	/**
	 * Actions present only after.
	 *
	 * @var ActionSchedulerActionState[]
	 */
	private $added;

	/**
	 * Actions present only before.
	 *
	 * @var ActionSchedulerActionState[]
	 */
	private $removed;

	/**
	 * Same action and schedule, other scheduled run.
	 *
	 * @var RescheduledAction[]
	 */
	private $rescheduled;

	/**
	 * Same action, other schedule.
	 *
	 * @var ChangedAction[]
	 */
	private $changed;

	/**
	 * Summary.
	 *
	 * @var ActionSchedulerDiffSummary
	 */
	private $summary;

	/**
	 * Constructor.
	 *
	 * @param ActionSchedulerActionState[] $added       Added actions.
	 * @param ActionSchedulerActionState[] $removed     Removed actions.
	 * @param RescheduledAction[]          $rescheduled Rescheduled actions.
	 * @param ChangedAction[]              $changed     Actions with a changed schedule.
	 * @param ActionSchedulerDiffSummary   $summary     Summary.
	 */
	public function __construct( array $added, array $removed, array $rescheduled, array $changed, ActionSchedulerDiffSummary $summary ) {
		$this->added       = self::sorted( $added );
		$this->removed     = self::sorted( $removed );
		$this->rescheduled = self::sorted( $rescheduled );
		$this->changed     = self::sorted( $changed );
		$this->summary     = $summary;
	}

	/**
	 * Entries sorted by their array form.
	 *
	 * @param array $entries Entries with to_array().
	 * @return array
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
	 * Actions present only after.
	 *
	 * @return ActionSchedulerActionState[]
	 */
	public function get_added() {
		return $this->added;
	}

	/**
	 * Actions present only before.
	 *
	 * @return ActionSchedulerActionState[]
	 */
	public function get_removed() {
		return $this->removed;
	}

	/**
	 * Same action and schedule, other scheduled run.
	 *
	 * @return RescheduledAction[]
	 */
	public function get_rescheduled() {
		return $this->rescheduled;
	}

	/**
	 * Same action, other schedule.
	 *
	 * @return ChangedAction[]
	 */
	public function get_changed() {
		return $this->changed;
	}

	/**
	 * Summary.
	 *
	 * @return ActionSchedulerDiffSummary
	 */
	public function get_summary() {
		return $this->summary;
	}

	/**
	 * Whether anything was added, removed, rescheduled or changed.
	 *
	 * @return bool
	 */
	public function has_changes() {
		return $this->added || $this->removed || $this->rescheduled || $this->changed;
	}

	/**
	 * Array form.
	 *
	 * @return array{added: array, removed: array, rescheduled: array, changed: array, summary: array}
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
