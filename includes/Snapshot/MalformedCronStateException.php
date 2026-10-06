<?php
/**
 * WP-Cron state that cannot be snapshotted reliably.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when the `cron` option holds data that is not a list of scheduled
 * events in WordPress's format.
 *
 * Messages are fixed and reasons are fixed codes: neither ever contains the
 * offending data, hook names or arguments.
 */
final class MalformedCronStateException extends RuntimeException {

	/**
	 * The array has no `version` 2 marker (legacy or unknown format).
	 */
	const UNSUPPORTED_FORMAT = 'unsupported_format';

	/**
	 * A top-level key is not a positive integer timestamp.
	 */
	const INVALID_TIMESTAMP = 'invalid_timestamp';

	/**
	 * A hook name is not valid UTF-8.
	 */
	const INVALID_HOOK = 'invalid_hook';

	/**
	 * A timestamp, hook or event entry is not an array.
	 */
	const INVALID_EVENT = 'invalid_event';

	/**
	 * An event has no `schedule`, or it is neither false nor a non-empty string.
	 */
	const INVALID_SCHEDULE = 'invalid_schedule';

	/**
	 * A recurring event's stored `interval` is not a non-negative integer.
	 */
	const INVALID_INTERVAL = 'invalid_interval';

	/**
	 * An event has no `args` array, or its arguments cannot be fingerprinted.
	 */
	const INVALID_ARGS = 'invalid_args';

	/**
	 * Fixed reason code.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * The array has no `version` 2 marker.
	 *
	 * @return self
	 */
	public static function unsupported_format() {
		return self::because( self::UNSUPPORTED_FORMAT );
	}

	/**
	 * A top-level key is not a positive integer timestamp.
	 *
	 * @return self
	 */
	public static function invalid_timestamp() {
		return self::because( self::INVALID_TIMESTAMP );
	}

	/**
	 * A hook name is not valid UTF-8.
	 *
	 * @return self
	 */
	public static function invalid_hook() {
		return self::because( self::INVALID_HOOK );
	}

	/**
	 * A timestamp, hook or event entry is not an array.
	 *
	 * @return self
	 */
	public static function invalid_event() {
		return self::because( self::INVALID_EVENT );
	}

	/**
	 * An event's `schedule` is missing or invalid.
	 *
	 * @return self
	 */
	public static function invalid_schedule() {
		return self::because( self::INVALID_SCHEDULE );
	}

	/**
	 * A recurring event's stored `interval` is invalid.
	 *
	 * @return self
	 */
	public static function invalid_interval() {
		return self::because( self::INVALID_INTERVAL );
	}

	/**
	 * An event's `args` are missing, not an array, or cannot be fingerprinted.
	 *
	 * @return self
	 */
	public static function invalid_args() {
		return self::because( self::INVALID_ARGS );
	}

	/**
	 * Exception with a fixed message for a reason constant.
	 *
	 * @param string $reason Reason constant.
	 * @return self
	 */
	private static function because( $reason ) {
		$exception         = new self( 'UpdateLens could not read the WP-Cron state: it contains an entry that is not a valid scheduled event (' . $reason . ').' );
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
