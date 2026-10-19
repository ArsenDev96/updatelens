<?php
/**
 * Why an analysis has no usable AFTER result for a site check.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed reason codes of a check's AFTER side (Update\SiteCheckAfter).
 *
 * Stored (written once per check, guarded so nothing is overwritten):
 *
 * - `overlapping_update`: updater activity happened after this update ended
 *   and before the AFTER result was accepted (another update, by anyone,
 *   began or ended, or one was running). Stored with the result when the
 *   result arrived (its facts are kept, it is never compared), or alone
 *   when the AFTER start already saw it (nothing ran).
 * - `update_order_unknown`: the updater activity ledger was missing,
 *   recreated or unreadable, so other updates cannot be ruled out. Same use.
 * - `rate_limited`, `session_failed`: the AFTER sessions could not be
 *   opened (start limit reached, storage failure). Nothing ran.
 *
 * Read-only (derived when reading, never stored, so nothing is fabricated):
 *
 * - `not_captured`: an analysis from before schema 7.
 * - `no_baseline`: no BEFORE result was attached for this check, so no
 *   AFTER run was started for it.
 * - `analysis_ended`: the update failed, or the analysis was abandoned or
 *   incompatible: there is no settled observation to check after.
 * - `settle_expired`: the observation window passed without a settled
 *   snapshot.
 * - `next_update`: another plugin update began right after this one (a
 *   second "Update now" queued behind it, or a bulk update), so the
 *   observation ended there (`settle_outcome` `next_update`) and no check
 *   could run after this update alone.
 * - `not_followed_up`: settled by a later page load, not by the updating
 *   browser's follow-up request (AFTER checks start only from there): the
 *   page was left while the update ran, the follow-up request did not
 *   arrive, or (before Dashboard → Updates' update page sent its own
 *   follow-up) the update ran from Dashboard → Updates.
 * - `not_started`: the AFTER start never arrived within its window (tab
 *   closed, page left, request failed, site checks turned off).
 * - `not_reported`: started, but no result was accepted before its session
 *   ended (tab closed while running, report rejected or lost, admin-ajax
 *   unreachable).
 * - `data_corrupt`: a stored result that can no longer be decoded.
 * - `unknown`: an unrecognised stored reason.
 */
final class AfterReason {

	const OVERLAPPING_UPDATE   = 'overlapping_update';
	const UPDATE_ORDER_UNKNOWN = 'update_order_unknown';
	const RATE_LIMITED         = 'rate_limited';
	const SESSION_FAILED       = 'session_failed';

	const NOT_CAPTURED    = 'not_captured';
	const NO_BASELINE     = 'no_baseline';
	const ANALYSIS_ENDED  = 'analysis_ended';
	const SETTLE_EXPIRED  = 'settle_expired';
	const NEXT_UPDATE     = 'next_update';
	const NOT_FOLLOWED_UP = 'not_followed_up';
	const NOT_STARTED     = 'not_started';
	const NOT_REPORTED    = 'not_reported';
	const DATA_CORRUPT    = 'data_corrupt';
	const UNKNOWN         = 'unknown';

	/**
	 * Every code that may be stored.
	 */
	const STORED = array(
		self::OVERLAPPING_UPDATE,
		self::UPDATE_ORDER_UNKNOWN,
		self::RATE_LIMITED,
		self::SESSION_FAILED,
	);

	/**
	 * Stored codes that may accompany a stored result (it is then not compared).
	 */
	const WITH_RESULT = array(
		self::OVERLAPPING_UPDATE,
		self::UPDATE_ORDER_UNKNOWN,
	);

	/**
	 * Every code a read can return.
	 */
	const ALL = array(
		self::OVERLAPPING_UPDATE,
		self::UPDATE_ORDER_UNKNOWN,
		self::RATE_LIMITED,
		self::SESSION_FAILED,
		self::NOT_CAPTURED,
		self::NO_BASELINE,
		self::ANALYSIS_ENDED,
		self::SETTLE_EXPIRED,
		self::NEXT_UPDATE,
		self::NOT_FOLLOWED_UP,
		self::NOT_STARTED,
		self::NOT_REPORTED,
		self::DATA_CORRUPT,
		self::UNKNOWN,
	);
}
