<?php
/**
 * Why a report phase has no diff.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Reason codes for an unavailable phase in a report. A finite set derived
 * from the analysis status, error code and settle outcome; the UI maps them
 * to text.
 *
 * - update_in_progress:          the update has not reported back yet (`captured`).
 * - awaiting_settle:             within the settle window; not settled yet.
 * - settle_expired:              no eligible request within the settle window.
 * - update_failed:               the WordPress update failed or did not report completion.
 * - analysis_failed:             UpdateLens could not analyse the update (e.g. unreadable snapshot).
 * - fingerprint_context_changed: values could not be compared (e.g. rotated salts).
 * - analysis_abandoned:          stale, or another update ran in the same request.
 * - data_corrupt:                a diff is stored but could not be read.
 * - not_recorded:                no diff and no other explanation (inconsistent or unknown record).
 */
final class UnavailableReason {

	const UPDATE_IN_PROGRESS          = 'update_in_progress';
	const AWAITING_SETTLE             = 'awaiting_settle';
	const SETTLE_EXPIRED              = 'settle_expired';
	const UPDATE_FAILED               = 'update_failed';
	const ANALYSIS_FAILED             = 'analysis_failed';
	const FINGERPRINT_CONTEXT_CHANGED = 'fingerprint_context_changed';
	const ANALYSIS_ABANDONED          = 'analysis_abandoned';
	const DATA_CORRUPT                = 'data_corrupt';
	const NOT_RECORDED                = 'not_recorded';
}
