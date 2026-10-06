<?php
/**
 * A cron event whose next run moved.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\CronEventRecord;

defined( 'ABSPATH' ) || exit;

/**
 * The same logical event (hook + arguments) with the same recurrence in both
 * snapshots, at a different timestamp. Holds no arguments fingerprint.
 *
 * For a recurring event this is usually a normal run moving it to its next
 * occurrence. For a one-time event it may equally be a run plus a new
 * scheduling of the same job; cron state cannot tell these apart.
 */
final class RescheduledCronEvent {

	/**
	 * Action hook name.
	 *
	 * @var string
	 */
	private $hook;

	/**
	 * Timestamp before.
	 *
	 * @var int
	 */
	private $before_timestamp;

	/**
	 * Timestamp after.
	 *
	 * @var int
	 */
	private $after_timestamp;

	/**
	 * Recurrence name (unchanged), null for a one-time event.
	 *
	 * @var string|null
	 */
	private $schedule;

	/**
	 * Stored interval (unchanged), or null.
	 *
	 * @var int|null
	 */
	private $interval;

	/**
	 * Whether the event recurs.
	 *
	 * @var bool
	 */
	private $is_recurring;

	/**
	 * Constructor.
	 *
	 * The records must be the same logical event with the same recurrence.
	 *
	 * @param CronEventRecord $before Record before.
	 * @param CronEventRecord $after  Record after.
	 */
	public function __construct( CronEventRecord $before, CronEventRecord $after ) {
		$this->hook             = $after->get_hook();
		$this->before_timestamp = $before->get_timestamp();
		$this->after_timestamp  = $after->get_timestamp();
		$this->schedule         = $after->get_schedule();
		$this->interval         = $after->get_interval();
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
	 * Timestamp before.
	 *
	 * @return int
	 */
	public function get_before_timestamp() {
		return $this->before_timestamp;
	}

	/**
	 * Timestamp after.
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
	 * Recurrence name, null for a one-time event.
	 *
	 * @return string|null
	 */
	public function get_schedule() {
		return $this->schedule;
	}

	/**
	 * Stored interval in seconds, or null.
	 *
	 * @return int|null
	 */
	public function get_interval() {
		return $this->interval;
	}

	/**
	 * Whether the event recurs.
	 *
	 * @return bool
	 */
	public function is_recurring() {
		return $this->is_recurring;
	}

	/**
	 * Array form.
	 *
	 * @return array{hook: string, before_timestamp: int, after_timestamp: int, timestamp_delta: int, schedule: string|null, interval: int|null, is_recurring: bool}
	 */
	public function to_array() {
		return array(
			'hook'             => $this->hook,
			'before_timestamp' => $this->before_timestamp,
			'after_timestamp'  => $this->after_timestamp,
			'timestamp_delta'  => $this->get_timestamp_delta(),
			'schedule'         => $this->schedule,
			'interval'         => $this->interval,
			'is_recurring'     => $this->is_recurring,
		);
	}
}
