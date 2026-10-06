<?php
/**
 * Aggregate figures of an Action Scheduler snapshot.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Counts over the active (pending and in-progress) actions of a snapshot.
 *
 * Historical rows (complete, failed, canceled) and logs are not part of a
 * snapshot, so they are never counted.
 */
final class ActionSchedulerSummary {

	/**
	 * Number of active actions.
	 *
	 * @var int
	 */
	private $action_count = 0;

	/**
	 * Number of interval and cron actions.
	 *
	 * @var int
	 */
	private $recurring_action_count = 0;

	/**
	 * Number of one-time actions (single and async).
	 *
	 * @var int
	 */
	private $single_action_count = 0;

	/**
	 * Number of distinct hooks.
	 *
	 * @var int
	 */
	private $unique_hook_count = 0;

	/**
	 * Number of pending actions.
	 *
	 * @var int
	 */
	private $pending_count = 0;

	/**
	 * Number of actions running at capture time.
	 *
	 * @var int
	 */
	private $in_progress_count = 0;

	/**
	 * Constructor.
	 *
	 * @param ActionSchedulerActionRecord[] $actions Records.
	 */
	public function __construct( array $actions ) {
		$hooks = array();
		foreach ( $actions as $action ) {
			++$this->action_count;
			if ( $action->is_recurring() ) {
				++$this->recurring_action_count;
			} else {
				++$this->single_action_count;
			}
			if ( ActionSchedulerActionRecord::STATUS_IN_PROGRESS === $action->get_status() ) {
				++$this->in_progress_count;
			} else {
				++$this->pending_count;
			}
			// Prefix so numeric hook names stay distinct string keys.
			$hooks[ 'h:' . $action->get_hook() ] = true;
		}
		$this->unique_hook_count = count( $hooks );
	}

	/**
	 * Number of active actions.
	 *
	 * @return int
	 */
	public function get_action_count() {
		return $this->action_count;
	}

	/**
	 * Number of interval and cron actions.
	 *
	 * @return int
	 */
	public function get_recurring_action_count() {
		return $this->recurring_action_count;
	}

	/**
	 * Number of one-time actions (single and async).
	 *
	 * @return int
	 */
	public function get_single_action_count() {
		return $this->single_action_count;
	}

	/**
	 * Number of distinct hooks.
	 *
	 * @return int
	 */
	public function get_unique_hook_count() {
		return $this->unique_hook_count;
	}

	/**
	 * Number of pending actions.
	 *
	 * @return int
	 */
	public function get_pending_count() {
		return $this->pending_count;
	}

	/**
	 * Number of actions running at capture time.
	 *
	 * @return int
	 */
	public function get_in_progress_count() {
		return $this->in_progress_count;
	}

	/**
	 * Array form.
	 *
	 * @return array<string, int>
	 */
	public function to_array() {
		return array(
			'action_count'           => $this->action_count,
			'recurring_action_count' => $this->recurring_action_count,
			'single_action_count'    => $this->single_action_count,
			'unique_hook_count'      => $this->unique_hook_count,
			'pending_count'          => $this->pending_count,
			'in_progress_count'      => $this->in_progress_count,
		);
	}
}
