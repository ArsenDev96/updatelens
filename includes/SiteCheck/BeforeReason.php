<?php
/**
 * Why an analysis has no BEFORE result for a site check.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed reason codes stored per check when no BEFORE baseline was attached
 * to an analysis (Update\SiteCheckObservation). Decided once, when the
 * update starts, in this order (the first that applies):
 *
 * 1. `disabled`: site checks were not enabled.
 * 2. `no_login_session`: the update request had no WordPress login session.
 * 3. `update_order_unknown`: this update could not be recorded in the
 *    updater activity ledger (UpdateActivity).
 * 4. `overlapping_update`: another update was running when this one began.
 * 5. `missing`: no baseline for this administrator, login session and check
 *    (also: a baseline of another login session is never looked up).
 * 6. `corrupt`: the stored baseline could not be read.
 * 7. `definition_changed` / `target_changed`: another check definition
 *    version or site address.
 * 8. `not_completed_before_update`: the baseline report was accepted after
 *    this update began (never attached afterwards).
 * 9. `expired`: completed more than Baselines::MAX_AGE_SECONDS before.
 * 10. `taken_during_update`: the baseline run started while an update ran.
 * 11. `update_order_unknown`: the ledger was missing or unreadable before
 *     this update, so updates since the baseline cannot be ruled out.
 * 12. `intervening_update`: another update started or ended between the
 *     baseline run and this update (including one this baseline was used
 *     for, and updates by other administrators).
 *
 * `selection_failed`: an unexpected error while selecting. Read-only codes
 * (never stored): `not_captured` (analyses from before schema 6),
 * `data_corrupt` (an attached result that can no longer be decoded) and
 * `unknown` (an unrecognised stored reason).
 */
final class BeforeReason {

	const DISABLED                    = 'disabled';
	const NO_LOGIN_SESSION            = 'no_login_session';
	const UPDATE_ORDER_UNKNOWN        = 'update_order_unknown';
	const OVERLAPPING_UPDATE          = 'overlapping_update';
	const MISSING                     = 'missing';
	const CORRUPT                     = 'corrupt';
	const DEFINITION_CHANGED          = 'definition_changed';
	const TARGET_CHANGED              = 'target_changed';
	const NOT_COMPLETED_BEFORE_UPDATE = 'not_completed_before_update';
	const EXPIRED                     = 'expired';
	const TAKEN_DURING_UPDATE         = 'taken_during_update';
	const INTERVENING_UPDATE          = 'intervening_update';
	const SELECTION_FAILED            = 'selection_failed';

	const NOT_CAPTURED = 'not_captured';
	const DATA_CORRUPT = 'data_corrupt';
	const UNKNOWN      = 'unknown';

	/**
	 * Every code that may be stored.
	 */
	const STORED = array(
		self::DISABLED,
		self::NO_LOGIN_SESSION,
		self::UPDATE_ORDER_UNKNOWN,
		self::OVERLAPPING_UPDATE,
		self::MISSING,
		self::CORRUPT,
		self::DEFINITION_CHANGED,
		self::TARGET_CHANGED,
		self::NOT_COMPLETED_BEFORE_UPDATE,
		self::EXPIRED,
		self::TAKEN_DURING_UPDATE,
		self::INTERVENING_UPDATE,
		self::SELECTION_FAILED,
	);
}
