<?php
/**
 * Why a WP-Cron observation phase is unavailable.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed reason codes stored in the `cron_*_reason` columns.
 *
 * Every Cron phase of a terminal analysis has exactly one of a diff or a
 * reason; while the analysis is open, a phase with neither is still
 * pending. The first known cause wins: a later lifecycle event never
 * overwrites a phase that is already resolved.
 */
final class CronPhaseReason {

	/**
	 * A required Cron snapshot could not be taken: the cron state contained an
	 * entry UpdateLens cannot safely normalize.
	 */
	const MALFORMED_CRON_STATE = 'malformed_cron_state';

	/**
	 * A required Cron snapshot could not be taken, encoded or read back.
	 */
	const SNAPSHOT_UNAVAILABLE = 'snapshot_unavailable';

	/**
	 * The two snapshots have different fingerprint contexts (e.g. rotated salts).
	 */
	const FINGERPRINT_CONTEXT_CHANGED = 'fingerprint_context_changed';

	/**
	 * A required Cron snapshot was never taken (the analysis predates Cron observation).
	 */
	const NOT_CAPTURED = 'not_captured';

	/**
	 * The Cron result could not be saved with the analysis.
	 */
	const STORAGE_FAILED = 'storage_failed';

	/**
	 * Comparing or encoding the Cron snapshots failed unexpectedly.
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
}
