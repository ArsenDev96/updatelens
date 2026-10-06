<?php
/**
 * Action Scheduler state that UpdateLens cannot read on this site.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by ActionSchedulerSnapshotProvider when there is no Action
 * Scheduler state it can read: Action Scheduler is not running, not yet
 * initialized, or uses a store or schema UpdateLens does not support.
 *
 * This is not an error in the site's data (see
 * MalformedActionSchedulerStateException), and never an empty snapshot: an
 * empty snapshot would claim "no scheduled actions" where UpdateLens simply
 * cannot look. Messages are fixed; reasons are fixed codes.
 */
final class ActionSchedulerUnavailableException extends RuntimeException {

	/**
	 * Action Scheduler is not loaded: no active plugin bundles it.
	 */
	const NOT_INSTALLED = 'not_installed';

	/**
	 * Action Scheduler is loaded, but its data store is not initialized yet (before `init`).
	 */
	const NOT_INITIALIZED = 'not_initialized';

	/**
	 * The active data store is not ActionScheduler_DBStore (custom store,
	 * subclass, or a HybridStore whose legacy post store still has active actions).
	 */
	const UNSUPPORTED_STORE = 'unsupported_store';

	/**
	 * The Action Scheduler tables or the columns UpdateLens reads are missing.
	 */
	const UNSUPPORTED_SCHEMA = 'unsupported_schema';

	/**
	 * A query on the Action Scheduler tables failed (database error).
	 */
	const READ_FAILED = 'read_failed';

	/**
	 * Fixed reason code.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Action Scheduler is not loaded.
	 *
	 * @return self
	 */
	public static function not_installed() {
		return self::because( self::NOT_INSTALLED );
	}

	/**
	 * The data store is not initialized yet.
	 *
	 * @return self
	 */
	public static function not_initialized() {
		return self::because( self::NOT_INITIALIZED );
	}

	/**
	 * The active data store is not supported.
	 *
	 * @return self
	 */
	public static function unsupported_store() {
		return self::because( self::UNSUPPORTED_STORE );
	}

	/**
	 * The tables or columns are missing.
	 *
	 * @return self
	 */
	public static function unsupported_schema() {
		return self::because( self::UNSUPPORTED_SCHEMA );
	}

	/**
	 * A query failed.
	 *
	 * @return self
	 */
	public static function read_failed() {
		return self::because( self::READ_FAILED );
	}

	/**
	 * Exception with a fixed message for a reason constant.
	 *
	 * @param string $reason Reason constant.
	 * @return self
	 */
	private static function because( $reason ) {
		$exception         = new self( 'Action Scheduler state is not available to UpdateLens on this site (' . $reason . ').' );
		$exception->reason = (string) $reason;

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
