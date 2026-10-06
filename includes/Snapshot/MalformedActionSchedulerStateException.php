<?php
/**
 * Action Scheduler state that cannot be snapshotted reliably.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when an active Action Scheduler action cannot be normalized.
 *
 * Action Scheduler is installed and readable, but one of its pending or
 * in-progress actions holds data UpdateLens cannot represent safely. This is
 * different from Action Scheduler being absent or using an unsupported store
 * (ActionSchedulerUnavailableException).
 *
 * Messages are fixed and reasons are fixed codes: neither ever contains the
 * offending data, hook names, groups or arguments.
 */
final class MalformedActionSchedulerStateException extends RuntimeException {

	/**
	 * A row has an invalid hook, group, status or scheduled date.
	 */
	const INVALID_ACTION = 'invalid_action';

	/**
	 * A row's arguments are not a JSON array.
	 */
	const INVALID_ARGS = 'invalid_args';

	/**
	 * A row's schedule is not a readable serialized schedule, or its fields are invalid.
	 */
	const INVALID_SCHEDULE = 'invalid_schedule';

	/**
	 * A row's schedule is a well-formed object of a class UpdateLens does not
	 * normalize (a custom ActionScheduler_Schedule implementation).
	 */
	const UNSUPPORTED_SCHEDULE = 'unsupported_schedule';

	/**
	 * Fixed reason code.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * A row has an invalid hook, group, status or scheduled date.
	 *
	 * @return self
	 */
	public static function invalid_action() {
		return self::because( self::INVALID_ACTION );
	}

	/**
	 * A row's arguments are not a JSON array.
	 *
	 * @return self
	 */
	public static function invalid_args() {
		return self::because( self::INVALID_ARGS );
	}

	/**
	 * A row's schedule cannot be read.
	 *
	 * @return self
	 */
	public static function invalid_schedule() {
		return self::because( self::INVALID_SCHEDULE );
	}

	/**
	 * A row uses a schedule class UpdateLens does not normalize.
	 *
	 * @return self
	 */
	public static function unsupported_schedule() {
		return self::because( self::UNSUPPORTED_SCHEDULE );
	}

	/**
	 * Exception with a fixed message for a reason constant.
	 *
	 * @param string $reason Reason constant.
	 * @return self
	 */
	private static function because( $reason ) {
		$exception         = new self( 'UpdateLens could not read the Action Scheduler state: it contains an active action it cannot normalize (' . $reason . ').' );
		$exception->reason = $reason;

		return $exception;
	}

	/**
	 * Fixed reason code (one of the class constants).
	 *
	 * @return string
	 */
	public function get_reason() {
		return $this->reason;
	}
}
