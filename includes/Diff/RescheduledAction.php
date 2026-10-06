<?php
/**
 * An Action Scheduler action whose scheduled run moved.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\ActionSchedulerActionRecord;

defined( 'ABSPATH' ) || exit;

/**
 * The same logical action (hook + group + arguments) with the same schedule
 * in both snapshots, at a different time. Holds no arguments fingerprint.
 *
 * For a recurring action this is usually a normal run: Action Scheduler
 * completes the run and stores the next occurrence as a new action. For a
 * one-time action it may equally be a run plus a new scheduling of the same
 * job; active state cannot tell these apart.
 */
final class RescheduledAction {

	/**
	 * Action hook name.
	 *
	 * @var string
	 */
	private $hook;

	/**
	 * Group slug, '' for none.
	 *
	 * @var string
	 */
	private $group;

	/**
	 * Scheduled run before.
	 *
	 * @var int
	 */
	private $before_timestamp;

	/**
	 * Scheduled run after.
	 *
	 * @var int
	 */
	private $after_timestamp;

	/**
	 * Schedule type (unchanged).
	 *
	 * @var string
	 */
	private $schedule_type;

	/**
	 * Interval in seconds (unchanged), or null.
	 *
	 * @var int|null
	 */
	private $interval;

	/**
	 * Cron expression (unchanged), or null.
	 *
	 * @var string|null
	 */
	private $cron_expression;

	/**
	 * Whether the action recurs.
	 *
	 * @var bool
	 */
	private $is_recurring;

	/**
	 * Constructor.
	 *
	 * The records must be the same logical action with the same schedule.
	 *
	 * @param ActionSchedulerActionRecord $before Record before.
	 * @param ActionSchedulerActionRecord $after  Record after.
	 */
	public function __construct( ActionSchedulerActionRecord $before, ActionSchedulerActionRecord $after ) {
		$this->hook             = $after->get_hook();
		$this->group            = $after->get_group();
		$this->before_timestamp = $before->get_timestamp();
		$this->after_timestamp  = $after->get_timestamp();
		$this->schedule_type    = $after->get_schedule_type();
		$this->interval         = $after->get_interval();
		$this->cron_expression  = $after->get_cron_expression();
		$this->is_recurring     = $after->is_recurring();
	}

	/**
	 * Action hook name.
	 *
	 * @return string
	 */
	public function get_hook() {
		return $this->hook;
	}

	/**
	 * Group slug, '' for none.
	 *
	 * @return string
	 */
	public function get_group() {
		return $this->group;
	}

	/**
	 * Scheduled run before.
	 *
	 * @return int
	 */
	public function get_before_timestamp() {
		return $this->before_timestamp;
	}

	/**
	 * Scheduled run after.
	 *
	 * @return int
	 */
	public function get_after_timestamp() {
		return $this->after_timestamp;
	}

	/**
	 * Signed move in seconds (after - before).
	 *
	 * @return int
	 */
	public function get_timestamp_delta() {
		return $this->after_timestamp - $this->before_timestamp;
	}

	/**
	 * Schedule type.
	 *
	 * @return string
	 */
	public function get_schedule_type() {
		return $this->schedule_type;
	}

	/**
	 * Interval in seconds, or null.
	 *
	 * @return int|null
	 */
	public function get_interval() {
		return $this->interval;
	}

	/**
	 * Cron expression, or null.
	 *
	 * @return string|null
	 */
	public function get_cron_expression() {
		return $this->cron_expression;
	}

	/**
	 * Whether the action recurs.
	 *
	 * @return bool
	 */
	public function is_recurring() {
		return $this->is_recurring;
	}

	/**
	 * Array form.
	 *
	 * @return array<string, string|int|bool|null>
	 */
	public function to_array() {
		return array(
			'hook'             => $this->hook,
			'group'            => $this->group,
			'before_timestamp' => $this->before_timestamp,
			'after_timestamp'  => $this->after_timestamp,
			'timestamp_delta'  => $this->get_timestamp_delta(),
			'schedule_type'    => $this->schedule_type,
			'interval'         => $this->interval,
			'cron_expression'  => $this->cron_expression,
			'is_recurring'     => $this->is_recurring,
		);
	}
}
