<?php
/**
 * Aggregate figures of an Action Scheduler diff.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\ActionSchedulerSummary;

defined( 'ABSPATH' ) || exit;

/**
 * Before/after totals of active actions and the number of records per diff category.
 *
 * Deltas are signed `after - before` and come from the two snapshot
 * summaries, not from the diff records.
 */
final class ActionSchedulerDiffSummary {

	/**
	 * Summary before.
	 *
	 * @var ActionSchedulerSummary
	 */
	private $before;

	/**
	 * Summary after.
	 *
	 * @var ActionSchedulerSummary
	 */
	private $after;

	/**
	 * Number of added actions.
	 *
	 * @var int
	 */
	private $added_count;

	/**
	 * Number of removed actions.
	 *
	 * @var int
	 */
	private $removed_count;

	/**
	 * Number of rescheduled actions.
	 *
	 * @var int
	 */
	private $rescheduled_count;

	/**
	 * Number of actions with a changed schedule.
	 *
	 * @var int
	 */
	private $changed_count;

	/**
	 * Constructor.
	 *
	 * @param ActionSchedulerSummary $before      Summary before.
	 * @param ActionSchedulerSummary $after       Summary after.
	 * @param int                    $added       Number of added actions.
	 * @param int                    $removed     Number of removed actions.
	 * @param int                    $rescheduled Number of rescheduled actions.
	 * @param int                    $changed     Number of actions with a changed schedule.
	 */
	public function __construct( ActionSchedulerSummary $before, ActionSchedulerSummary $after, $added, $removed, $rescheduled, $changed ) {
		$this->before            = $before;
		$this->after             = $after;
		$this->added_count       = (int) $added;
		$this->removed_count     = (int) $removed;
		$this->rescheduled_count = (int) $rescheduled;
		$this->changed_count     = (int) $changed;
	}

	/**
	 * Summary before.
	 *
	 * @return ActionSchedulerSummary
	 */
	public function get_before() {
		return $this->before;
	}

	/**
	 * Summary after.
	 *
	 * @return ActionSchedulerSummary
	 */
	public function get_after() {
		return $this->after;
	}

	/**
	 * Change in the number of active actions.
	 *
	 * @return int
	 */
	public function get_action_count_delta() {
		return $this->after->get_action_count() - $this->before->get_action_count();
	}

	/**
	 * Change in the number of recurring actions.
	 *
	 * @return int
	 */
	public function get_recurring_count_delta() {
		return $this->after->get_recurring_action_count() - $this->before->get_recurring_action_count();
	}

	/**
	 * Change in the number of one-time actions.
	 *
	 * @return int
	 */
	public function get_single_count_delta() {
		return $this->after->get_single_action_count() - $this->before->get_single_action_count();
	}

	/**
	 * Change in the number of distinct hooks.
	 *
	 * @return int
	 */
	public function get_unique_hook_count_delta() {
		return $this->after->get_unique_hook_count() - $this->before->get_unique_hook_count();
	}

	/**
	 * Number of added actions.
	 *
	 * @return int
	 */
	public function get_added_count() {
		return $this->added_count;
	}

	/**
	 * Number of removed actions.
	 *
	 * @return int
	 */
	public function get_removed_count() {
		return $this->removed_count;
	}

	/**
	 * Number of rescheduled actions.
	 *
	 * @return int
	 */
	public function get_rescheduled_count() {
		return $this->rescheduled_count;
	}

	/**
	 * Number of actions with a changed schedule.
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
			'before_action_count'      => $this->before->get_action_count(),
			'after_action_count'       => $this->after->get_action_count(),
			'action_count_delta'       => $this->get_action_count_delta(),
			'before_recurring_count'   => $this->before->get_recurring_action_count(),
			'after_recurring_count'    => $this->after->get_recurring_action_count(),
			'recurring_count_delta'    => $this->get_recurring_count_delta(),
			'before_single_count'      => $this->before->get_single_action_count(),
			'after_single_count'       => $this->after->get_single_action_count(),
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
