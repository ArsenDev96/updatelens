<?php
/**
 * A cron event whose recurrence changed.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\CronEventRecord;

defined( 'ABSPATH' ) || exit;

/**
 * The same logical event (hook + arguments) in both snapshots with a
 * different recurrence: schedule (`daily` → `hourly`), stored interval, or
 * one-time ↔ recurring. The timestamp may have moved too. Holds no arguments
 * fingerprint.
 */
final class ChangedCronEvent {

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
	 * Recurrence name before, null for one-time.
	 *
	 * @var string|null
	 */
	private $before_schedule;

	/**
	 * Recurrence name after, null for one-time.
	 *
	 * @var string|null
	 */
	private $after_schedule;

	/**
	 * Stored interval before, or null.
	 *
	 * @var int|null
	 */
	private $before_interval;

	/**
	 * Stored interval after, or null.
	 *
	 * @var int|null
	 */
	private $after_interval;

	/**
	 * Whether recurring before.
	 *
	 * @var bool
	 */
	private $before_is_recurring;

	/**
	 * Whether recurring after.
	 *
	 * @var bool
	 */
	private $after_is_recurring;

	/**
	 * Constructor.
	 *
	 * The records must be the same logical event with different recurrences.
	 *
	 * @param CronEventRecord $before Record before.
	 * @param CronEventRecord $after  Record after.
	 */
	public function __construct( CronEventRecord $before, CronEventRecord $after ) {
		$this->hook                = $after->get_hook();
		$this->before_timestamp    = $before->get_timestamp();
		$this->after_timestamp     = $after->get_timestamp();
		$this->before_schedule     = $before->get_schedule();
		$this->after_schedule      = $after->get_schedule();
		$this->before_interval     = $before->get_interval();
		$this->after_interval      = $after->get_interval();
		$this->before_is_recurring = $before->is_recurring();
		$this->after_is_recurring  = $after->is_recurring();
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
	 * Whether the timestamp moved as well.
	 *
	 * @return bool
	 */
	public function is_timestamp_changed() {
		return $this->before_timestamp !== $this->after_timestamp;
	}

	/**
	 * Recurrence name before, null for one-time.
	 *
	 * @return string|null
	 */
	public function get_before_schedule() {
		return $this->before_schedule;
	}

	/**
	 * Recurrence name after, null for one-time.
	 *
	 * @return string|null
	 */
	public function get_after_schedule() {
		return $this->after_schedule;
	}

	/**
	 * Stored interval before, or null.
	 *
	 * @return int|null
	 */
	public function get_before_interval() {
		return $this->before_interval;
	}

	/**
	 * Stored interval after, or null.
	 *
	 * @return int|null
	 */
	public function get_after_interval() {
		return $this->after_interval;
	}

	/**
	 * Whether recurring before.
	 *
	 * @return bool
	 */
	public function get_before_is_recurring() {
		return $this->before_is_recurring;
	}

	/**
	 * Whether recurring after.
	 *
	 * @return bool
	 */
	public function get_after_is_recurring() {
		return $this->after_is_recurring;
	}

	/**
	 * Array form.
	 *
	 * @return array<string, string|int|bool|null>
	 */
	public function to_array() {
		return array(
			'hook'                => $this->hook,
			'before_timestamp'    => $this->before_timestamp,
			'after_timestamp'     => $this->after_timestamp,
			'timestamp_changed'   => $this->is_timestamp_changed(),
			'before_schedule'     => $this->before_schedule,
			'after_schedule'      => $this->after_schedule,
			'before_interval'     => $this->before_interval,
			'after_interval'      => $this->after_interval,
			'before_is_recurring' => $this->before_is_recurring,
			'after_is_recurring'  => $this->after_is_recurring,
		);
	}
}
