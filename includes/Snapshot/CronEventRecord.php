<?php
/**
 * Safe metadata for one scheduled WP-Cron event.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * One scheduled event instance in a cron snapshot.
 *
 * Holds no arguments, only their keyed fingerprint (see CronArgsHasher).
 *
 * Logical identity is hook + arguments fingerprint, the privacy-safe
 * equivalent of WordPress's own key (`hook` + `md5( serialize( $args ) )`).
 * The timestamp and the recurrence (schedule, interval) are attributes of
 * an instance, not part of its identity: a recurring event moving to its
 * next run is the same logical event. Several instances can share one
 * identity (the same job scheduled for several times).
 */
final class CronEventRecord {

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
	 * Recurrence name (e.g. `daily`), null for a one-time event.
	 *
	 * @var string|null
	 */
	private $schedule;

	/**
	 * Stored recurrence interval in seconds, null for a one-time event or when not stored.
	 *
	 * @var int|null
	 */
	private $interval;

	/**
	 * Keyed fingerprint of the event arguments.
	 *
	 * @var string
	 */
	private $args_fingerprint;

	/**
	 * Constructor.
	 *
	 * @param string      $hook             Action hook name.
	 * @param int         $timestamp        Next run, Unix timestamp (UTC).
	 * @param string|null $schedule         Recurrence name, null for a one-time event.
	 * @param int|null    $interval         Stored interval in seconds; ignored for one-time events.
	 * @param string      $args_fingerprint Keyed fingerprint of the arguments.
	 */
	public function __construct( $hook, $timestamp, $schedule, $interval, $args_fingerprint ) {
		$this->hook             = (string) $hook;
		$this->timestamp        = (int) $timestamp;
		$this->schedule         = null === $schedule ? null : (string) $schedule;
		$this->interval         = null === $this->schedule || null === $interval ? null : (int) $interval;
		$this->args_fingerprint = (string) $args_fingerprint;
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
	 * Stored interval in seconds, null for a one-time event or when not stored.
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
		return null !== $this->schedule;
	}

	/**
	 * Keyed fingerprint of the arguments.
	 *
	 * @return string
	 */
	public function get_args_fingerprint() {
		return $this->args_fingerprint;
	}

	/**
	 * Whether another instance has the same logical identity (hook + arguments).
	 *
	 * Both records must come from snapshots with the same fingerprint context.
	 *
	 * @param CronEventRecord $other Other record.
	 * @return bool
	 */
	public function is_same_event( CronEventRecord $other ) {
		return $this->hook === $other->hook && $this->args_fingerprint === $other->args_fingerprint;
	}

	/**
	 * Whether another instance has the same recurrence (schedule and interval).
	 *
	 * @param CronEventRecord $other Other record.
	 * @return bool
	 */
	public function has_same_recurrence( CronEventRecord $other ) {
		return $this->schedule === $other->schedule && $this->interval === $other->interval;
	}

	/**
	 * Array form.
	 *
	 * @return array{hook: string, timestamp: int, schedule: string|null, interval: int|null, is_recurring: bool, args_fingerprint: string}
	 */
	public function to_array() {
		return array(
			'hook'             => $this->hook,
			'timestamp'        => $this->timestamp,
			'schedule'         => $this->schedule,
			'interval'         => $this->interval,
			'is_recurring'     => $this->is_recurring(),
			'args_fingerprint' => $this->args_fingerprint,
		);
	}
}
