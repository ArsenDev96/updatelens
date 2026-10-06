<?php
/**
 * Totals for a WP-Cron diff.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\CronSummary;

defined( 'ABSPATH' ) || exit;

/**
 * Before/after counts, signed deltas (after - before) and change counts.
 *
 * Deltas come from the snapshot summaries, not from the change lists.
 */
final class CronDiffSummary {

	/**
	 * Snapshot summary before.
	 *
	 * @var CronSummary
	 */
	private $before;

	/**
	 * Snapshot summary after.
	 *
	 * @var CronSummary
	 */
	private $after;

	/**
	 * Number of added events.
	 *
	 * @var int
	 */
	private $added_count;

	/**
	 * Number of removed events.
	 *
	 * @var int
	 */
	private $removed_count;

	/**
	 * Number of rescheduled events.
	 *
	 * @var int
	 */
	private $rescheduled_count;

	/**
	 * Number of events whose recurrence changed.
	 *
	 * @var int
	 */
	private $changed_count;

	/**
	 * Constructor.
	 *
	 * @param CronSummary $before      Snapshot summary before.
	 * @param CronSummary $after       Snapshot summary after.
	 * @param int         $added       Number of added events.
	 * @param int         $removed     Number of removed events.
	 * @param int         $rescheduled Number of rescheduled events.
	 * @param int         $changed     Number of changed events.
	 */
	public function __construct( CronSummary $before, CronSummary $after, $added, $removed, $rescheduled, $changed ) {
		$this->before            = $before;
		$this->after             = $after;
		$this->added_count       = (int) $added;
		$this->removed_count     = (int) $removed;
		$this->rescheduled_count = (int) $rescheduled;
		$this->changed_count     = (int) $changed;
	}

	/**
	 * Snapshot summary before.
	 *
	 * @return CronSummary
	 */
	public function get_before() {
		return $this->before;
	}

	/**
	 * Snapshot summary after.
	 *
	 * @return CronSummary
	 */
	public function get_after() {
		return $this->after;
	}

	/**
	 * Signed change in event count.
	 *
	 * @return int
	 */
	public function get_event_count_delta() {
		return $this->after->get_event_count() - $this->before->get_event_count();
	}

	/**
	 * Signed change in recurring event count.
	 *
	 * @return int
	 */
	public function get_recurring_count_delta() {
		return $this->after->get_recurring_event_count() - $this->before->get_recurring_event_count();
	}

	/**
	 * Signed change in one-time event count.
	 *
	 * @return int
	 */
	public function get_single_count_delta() {
		return $this->after->get_single_event_count() - $this->before->get_single_event_count();
	}

	/**
	 * Signed change in distinct hook count.
	 *
	 * @return int
	 */
	public function get_unique_hook_count_delta() {
		return $this->after->get_unique_hook_count() - $this->before->get_unique_hook_count();
	}

	/**
	 * Number of added events.
	 *
	 * @return int
	 */
	public function get_added_count() {
		return $this->added_count;
	}

	/**
	 * Number of removed events.
	 *
	 * @return int
	 */
	public function get_removed_count() {
		return $this->removed_count;
	}

	/**
	 * Number of rescheduled events.
	 *
	 * @return int
	 */
	public function get_rescheduled_count() {
		return $this->rescheduled_count;
	}

	/**
	 * Number of events whose recurrence changed.
	 *
	 * @return int
	 */
	public function get_changed_count() {
		return $this->changed_count;
	}

	/**
	 * Array form.
	 *
	 * @return array<string, int>
	 */
	public function to_array() {
		return array(
			'before_event_count'       => $this->before->get_event_count(),
			'after_event_count'        => $this->after->get_event_count(),
			'event_count_delta'        => $this->get_event_count_delta(),
			'before_recurring_count'   => $this->before->get_recurring_event_count(),
			'after_recurring_count'    => $this->after->get_recurring_event_count(),
			'recurring_count_delta'    => $this->get_recurring_count_delta(),
			'before_single_count'      => $this->before->get_single_event_count(),
			'after_single_count'       => $this->after->get_single_event_count(),
			'single_count_delta'       => $this->get_single_count_delta(),
			'before_unique_hook_count' => $this->before->get_unique_hook_count(),
			'after_unique_hook_count'  => $this->after->get_unique_hook_count(),
			'unique_hook_count_delta'  => $this->get_unique_hook_count_delta(),
			'added_count'              => $this->added_count,
			'removed_count'            => $this->removed_count,
			'rescheduled_count'        => $this->rescheduled_count,
			'changed_count'            => $this->changed_count,
		);
	}
}
