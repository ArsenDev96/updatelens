<?php
/**
 * Safe state of an added or removed Action Scheduler action.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\ActionSchedulerActionRecord;

defined( 'ABSPATH' ) || exit;

/**
 * An active action in one snapshot, without its arguments fingerprint.
 *
 * Used for added actions (state after) and removed actions (state before).
 * A removed action is no longer pending or in progress: it may have run,
 * failed or been canceled, which a snapshot of active actions cannot tell
 * apart. Two actions of one hook and group with different arguments look
 * identical here: the arguments are deliberately unknown.
 */
final class ActionSchedulerActionState {

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
	 * Status (pending or in-progress).
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Scheduled run, Unix timestamp (UTC).
	 *
	 * @var int
	 */
	private $timestamp;

	/**
	 * Schedule type.
	 *
	 * @var string
	 */
	private $schedule_type;

	/**
	 * Interval in seconds, or null.
	 *
	 * @var int|null
	 */
	private $interval;

	/**
	 * Cron expression, or null.
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
	 * @param ActionSchedulerActionRecord $record Snapshot record.
	 */
	public function __construct( ActionSchedulerActionRecord $record ) {
		$this->hook            = $record->get_hook();
		$this->group           = $record->get_group();
		$this->status          = $record->get_status();
		$this->timestamp       = $record->get_timestamp();
		$this->schedule_type   = $record->get_schedule_type();
		$this->interval        = $record->get_interval();
		$this->cron_expression = $record->get_cron_expression();
		$this->is_recurring    = $record->is_recurring();
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
	 * Status (pending or in-progress).
	 *
	 * @return string
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Scheduled run, Unix timestamp (UTC).
	 *
	 * @return int
	 */
	public function get_timestamp() {
		return $this->timestamp;
	}

	/**
	 * Schedule type (single, async, interval or cron).
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
	 * @return array{hook: string, group: string, status: string, timestamp: int, schedule_type: string, interval: int|null, cron_expression: string|null, is_recurring: bool}
	 */
	public function to_array() {
		return array(
			'hook'            => $this->hook,
			'group'           => $this->group,
			'status'          => $this->status,
			'timestamp'       => $this->timestamp,
			'schedule_type'   => $this->schedule_type,
			'interval'        => $this->interval,
			'cron_expression' => $this->cron_expression,
			'is_recurring'    => $this->is_recurring,
		);
	}
}
