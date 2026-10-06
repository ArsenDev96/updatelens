<?php
/**
 * Safe state of an added or removed cron event.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\CronEventRecord;

defined( 'ABSPATH' ) || exit;

/**
 * A cron event instance in one snapshot, without its arguments fingerprint.
 *
 * Used for added events (state after) and removed events (state before).
 * Two instances of one hook with different arguments look identical here:
 * the arguments are deliberately unknown.
 */
final class CronEventState {

	/**
	 * Action hook name.
	 *
	 * @var string
	 */
	private $hook;

	/**
	 * Next run, Unix timestamp (UTC).
	 *
	 * @var int
	 */
	private $timestamp;

	/**
	 * Recurrence name, null for a one-time event.
	 *
	 * @var string|null
	 */
	private $schedule;

	/**
	 * Stored interval in seconds, or null.
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
	 * @param CronEventRecord $record Snapshot record.
	 */
	public function __construct( CronEventRecord $record ) {
		$this->hook         = $record->get_hook();
		$this->timestamp    = $record->get_timestamp();
		$this->schedule     = $record->get_schedule();
		$this->interval     = $record->get_interval();
		$this->is_recurring = $record->is_recurring();
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
	 * Next run, Unix timestamp (UTC).
	 *
	 * @return int
	 */
	public function get_timestamp() {
		return $this->timestamp;
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
	 * @return array{hook: string, timestamp: int, schedule: string|null, interval: int|null, is_recurring: bool}
	 */
	public function to_array() {
		return array(
			'hook'         => $this->hook,
			'timestamp'    => $this->timestamp,
			'schedule'     => $this->schedule,
			'interval'     => $this->interval,
			'is_recurring' => $this->is_recurring,
		);
	}
}
