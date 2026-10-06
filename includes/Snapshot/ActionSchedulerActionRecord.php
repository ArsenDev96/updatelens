<?php
/**
 * Safe metadata for one active Action Scheduler action.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * One pending or in-progress action in an Action Scheduler snapshot.
 *
 * Holds no arguments, only their keyed fingerprint (see
 * ActionSchedulerArgsHasher), and no database identifiers (action, claim or
 * group IDs).
 *
 * Logical identity is hook + group + arguments fingerprint, the privacy-safe
 * equivalent of Action Scheduler's own notion of an existing action (it
 * matches hook, group and stored arguments when scheduling unique actions).
 * The next run, the schedule and the status are attributes of an instance:
 * a recurring action that ran and was scheduled again (a new database row)
 * is the same logical action. Several instances can share one identity.
 */
final class ActionSchedulerActionRecord {

	/**
	 * Run once at a given time (`ActionScheduler_SimpleSchedule`).
	 */
	const TYPE_SINGLE = 'single';

	/**
	 * Run as soon as possible (`ActionScheduler_NullSchedule`, from as_enqueue_async_action()).
	 */
	const TYPE_ASYNC = 'async';

	/**
	 * Recur every N seconds (`ActionScheduler_IntervalSchedule`).
	 */
	const TYPE_INTERVAL = 'interval';

	/**
	 * Recur on a cron expression (`ActionScheduler_CronSchedule`).
	 */
	const TYPE_CRON = 'cron';

	/**
	 * Waiting to run.
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * Claimed and running at capture time.
	 */
	const STATUS_IN_PROGRESS = 'in-progress';

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
	 * Schedule type (TYPE_* constant).
	 *
	 * @var string
	 */
	private $schedule_type;

	/**
	 * Interval in seconds for interval schedules, else null.
	 *
	 * @var int|null
	 */
	private $interval;

	/**
	 * Cron expression for cron schedules, else null.
	 *
	 * @var string|null
	 */
	private $cron_expression;

	/**
	 * Keyed fingerprint of the arguments.
	 *
	 * @var string
	 */
	private $args_fingerprint;

	/**
	 * Constructor.
	 *
	 * @param string      $hook             Action hook name.
	 * @param string      $group            Group slug, '' for none.
	 * @param string      $status           Status (pending or in-progress).
	 * @param int         $timestamp        Scheduled run, Unix timestamp (UTC).
	 * @param string      $schedule_type    Schedule type (TYPE_* constant).
	 * @param int|null    $interval         Interval in seconds; kept only for interval schedules.
	 * @param string|null $cron_expression  Cron expression; kept only for cron schedules.
	 * @param string      $args_fingerprint Keyed fingerprint of the arguments.
	 */
	public function __construct( $hook, $group, $status, $timestamp, $schedule_type, $interval, $cron_expression, $args_fingerprint ) {
		$this->hook             = (string) $hook;
		$this->group            = (string) $group;
		$this->status           = (string) $status;
		$this->timestamp        = (int) $timestamp;
		$this->schedule_type    = (string) $schedule_type;
		$this->interval         = self::TYPE_INTERVAL === $this->schedule_type && null !== $interval ? (int) $interval : null;
		$this->cron_expression  = self::TYPE_CRON === $this->schedule_type && null !== $cron_expression ? (string) $cron_expression : null;
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
	 * Schedule type (TYPE_* constant).
	 *
	 * @return string
	 */
	public function get_schedule_type() {
		return $this->schedule_type;
	}

	/**
	 * Interval in seconds for interval schedules, else null.
	 *
	 * @return int|null
	 */
	public function get_interval() {
		return $this->interval;
	}

	/**
	 * Cron expression for cron schedules, else null.
	 *
	 * @return string|null
	 */
	public function get_cron_expression() {
		return $this->cron_expression;
	}

	/**
	 * Whether the action recurs (interval or cron schedule).
	 *
	 * @return bool
	 */
	public function is_recurring() {
		return self::TYPE_INTERVAL === $this->schedule_type || self::TYPE_CRON === $this->schedule_type;
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
	 * Whether another instance has the same logical identity (hook + group + arguments).
	 *
	 * Both records must come from snapshots with the same fingerprint context.
	 *
	 * @param ActionSchedulerActionRecord $other Other record.
	 * @return bool
	 */
	public function is_same_action( ActionSchedulerActionRecord $other ) {
		return $this->hook === $other->hook
			&& $this->group === $other->group
			&& $this->args_fingerprint === $other->args_fingerprint;
	}

	/**
	 * Whether another instance has the same schedule (type, interval, cron expression).
	 *
	 * The next run and the status are not part of the schedule.
	 *
	 * @param ActionSchedulerActionRecord $other Other record.
	 * @return bool
	 */
	public function has_same_schedule( ActionSchedulerActionRecord $other ) {
		return $this->schedule_type === $other->schedule_type
			&& $this->interval === $other->interval
			&& $this->cron_expression === $other->cron_expression;
	}

	/**
	 * Array form.
	 *
	 * @return array{hook: string, group: string, status: string, timestamp: int, schedule_type: string, interval: int|null, cron_expression: string|null, is_recurring: bool, args_fingerprint: string}
	 */
	public function to_array() {
		return array(
			'hook'             => $this->hook,
			'group'            => $this->group,
			'status'           => $this->status,
			'timestamp'        => $this->timestamp,
			'schedule_type'    => $this->schedule_type,
			'interval'         => $this->interval,
			'cron_expression'  => $this->cron_expression,
			'is_recurring'     => $this->is_recurring(),
			'args_fingerprint' => $this->args_fingerprint,
		);
	}
}
