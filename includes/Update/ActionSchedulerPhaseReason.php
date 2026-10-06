<?php
/**
 * Why an Action Scheduler observation phase is unavailable.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

use UpdateLens\Snapshot\ActionSchedulerUnavailableException;
use UpdateLens\Snapshot\MalformedActionSchedulerStateException;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed reason codes stored in the `action_scheduler_*_reason` columns.
 *
 * Every Action Scheduler phase of a terminal analysis has exactly one of a
 * diff or a reason; while the analysis is open, a phase with neither is
 * still pending. The first known cause wins: a later lifecycle event never
 * overwrites a phase that is already resolved.
 *
 * A phase compares two captures and is available only when both are
 * readable snapshots. Otherwise it gets the reason of the earlier capture
 * that was not, so a phase across an availability change (e.g. Action
 * Scheduler loaded only after the update) is unavailable, never an
 * invented diff of everything added or removed.
 */
final class ActionSchedulerPhaseReason {

	/**
	 * Action Scheduler was not loaded: no active plugin bundles it. Normal on many sites.
	 */
	const NOT_INSTALLED = 'not_installed';

	/**
	 * Action Scheduler uses a data store UpdateLens does not read (custom
	 * store, subclass, or a migration with legacy actions left).
	 */
	const UNSUPPORTED_STORE = 'unsupported_store';

	/**
	 * The Action Scheduler tables or the columns UpdateLens reads are missing.
	 */
	const UNSUPPORTED_SCHEMA = 'unsupported_schema';

	/**
	 * An active action uses a schedule class UpdateLens does not normalize.
	 */
	const UNSUPPORTED_SCHEDULE = 'unsupported_schedule';

	/**
	 * An active action holds data UpdateLens cannot safely normalize.
	 */
	const MALFORMED_STATE = 'malformed_action_scheduler_state';

	/**
	 * A required snapshot could not be taken (not initialized yet, read error),
	 * encoded or read back.
	 */
	const SNAPSHOT_UNAVAILABLE = 'snapshot_unavailable';

	/**
	 * The two snapshots have different fingerprint contexts (e.g. rotated salts).
	 */
	const FINGERPRINT_CONTEXT_CHANGED = 'fingerprint_context_changed';

	/**
	 * A required snapshot was never taken (the analysis predates Action Scheduler observation).
	 */
	const NOT_CAPTURED = 'not_captured';

	/**
	 * The Action Scheduler result could not be saved with the analysis.
	 */
	const STORAGE_FAILED = 'storage_failed';

	/**
	 * Comparing or encoding the snapshots failed unexpectedly.
	 */
	const ANALYSIS_FAILED = 'analysis_failed';

	/**
	 * The settle window passed without a settled observation.
	 */
	const SETTLE_EXPIRED = 'settle_expired';

	/**
	 * The plugin update failed or never reported completion.
	 */
	const UPDATE_FAILED = 'update_failed';

	/**
	 * The analysis was abandoned.
	 */
	const ANALYSIS_ABANDONED = 'analysis_abandoned';

	/**
	 * The overall (options-driven) analysis ended as failed or incompatible
	 * before this phase was observed.
	 */
	const ANALYSIS_ENDED = 'analysis_ended';

	/**
	 * Every reason, for validation by readers.
	 */
	const ALL = array(
		self::NOT_INSTALLED,
		self::UNSUPPORTED_STORE,
		self::UNSUPPORTED_SCHEMA,
		self::UNSUPPORTED_SCHEDULE,
		self::MALFORMED_STATE,
		self::SNAPSHOT_UNAVAILABLE,
		self::FINGERPRINT_CONTEXT_CHANGED,
		self::NOT_CAPTURED,
		self::STORAGE_FAILED,
		self::ANALYSIS_FAILED,
		self::SETTLE_EXPIRED,
		self::UPDATE_FAILED,
		self::ANALYSIS_ABANDONED,
		self::ANALYSIS_ENDED,
	);

	/**
	 * Reason for an Action Scheduler state the provider cannot read.
	 *
	 * Not installed, unsupported store and unsupported schema stay distinct;
	 * not initialized (before `init`, which the update lifecycle never
	 * captures at) and query errors are a snapshot that could not be taken.
	 *
	 * @param string $unavailable ActionSchedulerUnavailableException reason.
	 * @return string
	 */
	public static function from_unavailable( $unavailable ) {
		switch ( $unavailable ) {
			case ActionSchedulerUnavailableException::NOT_INSTALLED:
				return self::NOT_INSTALLED;
			case ActionSchedulerUnavailableException::UNSUPPORTED_STORE:
				return self::UNSUPPORTED_STORE;
			case ActionSchedulerUnavailableException::UNSUPPORTED_SCHEMA:
				return self::UNSUPPORTED_SCHEMA;
			default:
				return self::SNAPSHOT_UNAVAILABLE;
		}
	}

	/**
	 * Reason for an active action the snapshot builder cannot normalize.
	 *
	 * A custom schedule class is unsupported, not broken data; every other
	 * normalization failure is malformed state.
	 *
	 * @param string $malformed MalformedActionSchedulerStateException reason.
	 * @return string
	 */
	public static function from_malformed( $malformed ) {
		return MalformedActionSchedulerStateException::UNSUPPORTED_SCHEDULE === $malformed ? self::UNSUPPORTED_SCHEDULE : self::MALFORMED_STATE;
	}
}
