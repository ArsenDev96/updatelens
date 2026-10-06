<?php
/**
 * An Action Scheduler action whose schedule changed.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\ActionSchedulerActionRecord;

defined( 'ABSPATH' ) || exit;

/**
 * The same logical action (hook + group + arguments) in both snapshots with
 * a different schedule: type (single, async, interval, cron), interval or
 * cron expression. The scheduled run may have moved too. Holds no
 * arguments fingerprint.
 */
final class ChangedAction {

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
	 * Scheduled runs before and after.
	 *
	 * @var int[]
	 */
	private $timestamps;

	/**
	 * Schedule types before and after.
	 *
	 * @var string[]
	 */
	private $schedule_types;

	/**
	 * Intervals before and after.
	 *
	 * @var array<int, int|null>
	 */
	private $intervals;

	/**
	 * Cron expressions before and after.
	 *
	 * @var array<int, string|null>
	 */
	private $cron_expressions;

	/**
	 * Whether recurring before and after.
	 *
	 * @var bool[]
	 */
	private $recurring;

	/**
	 * Constructor.
	 *
	 * The records must be the same logical action with different schedules.
	 *
	 * @param ActionSchedulerActionRecord $before Record before.
	 * @param ActionSchedulerActionRecord $after  Record after.
	 */
	public function __construct( ActionSchedulerActionRecord $before, ActionSchedulerActionRecord $after ) {
		$this->hook             = $after->get_hook();
		$this->group            = $after->get_group();
		$this->timestamps       = array( $before->get_timestamp(), $after->get_timestamp() );
		$this->schedule_types   = array( $before->get_schedule_type(), $after->get_schedule_type() );
		$this->intervals        = array( $before->get_interval(), $after->get_interval() );
		$this->cron_expressions = array( $before->get_cron_expression(), $after->get_cron_expression() );
		$this->recurring        = array( $before->is_recurring(), $after->is_recurring() );
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
		return $this->timestamps[0];
	}

	/**
	 * Scheduled run after.
	 *
	 * @return int
	 */
	public function get_after_timestamp() {
		return $this->timestamps[1];
	}

	/**
	 * Whether the scheduled run moved as well.
	 *
	 * @return bool
	 */
	public function is_timestamp_changed() {
		return $this->timestamps[0] !== $this->timestamps[1];
	}

	/**
	 * Schedule type before.
	 *
	 * @return string
	 */
	public function get_before_schedule_type() {
		return $this->schedule_types[0];
	}

	/**
	 * Schedule type after.
	 *
	 * @return string
	 */
	public function get_after_schedule_type() {
		return $this->schedule_types[1];
	}

	/**
	 * Interval before, or null.
	 *
	 * @return int|null
	 */
	public function get_before_interval() {
		return $this->intervals[0];
	}

	/**
	 * Interval after, or null.
	 *
	 * @return int|null
	 */
	public function get_after_interval() {
		return $this->intervals[1];
	}

	/**
	 * Cron expression before, or null.
	 *
	 * @return string|null
	 */
	public function get_before_cron_expression() {
		return $this->cron_expressions[0];
	}

	/**
	 * Cron expression after, or null.
	 *
	 * @return string|null
	 */
	public function get_after_cron_expression() {
		return $this->cron_expressions[1];
	}

	/**
	 * Whether recurring before.
	 *
	 * @return bool
	 */
	public function get_before_is_recurring() {
		return $this->recurring[0];
	}

	/**
	 * Whether recurring after.
	 *
	 * @return bool
	 */
	public function get_after_is_recurring() {
		return $this->recurring[1];
	}

	/**
	 * Array form.
	 *
	 * @return array<string, string|int|bool|null>
	 */
	public function to_array() {
		return array(
			'hook'                   => $this->hook,
			'group'                  => $this->group,
			'before_timestamp'       => $this->timestamps[0],
			'after_timestamp'        => $this->timestamps[1],
			'timestamp_changed'      => $this->is_timestamp_changed(),
			'before_schedule_type'   => $this->schedule_types[0],
			'after_schedule_type'    => $this->schedule_types[1],
			'before_interval'        => $this->intervals[0],
			'after_interval'         => $this->intervals[1],
			'before_cron_expression' => $this->cron_expressions[0],
			'after_cron_expression'  => $this->cron_expressions[1],
			'before_is_recurring'    => $this->recurring[0],
			'after_is_recurring'     => $this->recurring[1],
		);
	}
}
