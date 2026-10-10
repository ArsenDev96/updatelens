<?php
/**
 * Before/after comparison states of a site check.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed comparison states and reasons.
 *
 * Comparable runs (both conclusive):
 *
 * - `passed_both`:   passed before and after.
 * - `newly_failing`: passed before, failure confirmed after. The only state
 *                    that is a potential failure finding.
 * - `failing_both`:  failed before and after.
 * - `recovered`:     failed before, passed after.
 * - `changed`:       a redirect appeared or disappeared (homepage), or a
 *                    redirecting baseline was followed by a failure: an
 *                    observed change, never a failure finding (the baseline
 *                    did not pass).
 *
 * Otherwise:
 *
 * - `not_run`:        a BEFORE or AFTER result is missing (never ran,
 *                     finished too late, or its report never arrived).
 * - `not_comparable`: both exist, but a comparability requirement is not
 *                     met or a run is inconclusive. The reason says which.
 */
final class ComparisonState {

	const PASSED_BOTH    = 'passed_both';
	const NEWLY_FAILING  = 'newly_failing';
	const FAILING_BOTH   = 'failing_both';
	const RECOVERED      = 'recovered';
	const CHANGED        = 'changed';
	const NOT_RUN        = 'not_run';
	const NOT_COMPARABLE = 'not_comparable';

	/**
	 * `not_run` reasons.
	 */
	const BASELINE_MISSING = 'baseline_missing';
	const AFTER_MISSING    = 'after_missing';
	const BOTH_MISSING     = 'both_missing';

	/**
	 * `not_comparable` reasons, in the order they are checked.
	 */
	const QUEUED_UPDATE               = 'queued_update';
	const OVERLAPPING_UPDATE          = 'overlapping_update';
	const DEFINITION_CHANGED          = 'definition_changed';
	const TARGET_CHANGED              = 'target_changed';
	const DIFFERENT_USER              = 'different_user';
	const DIFFERENT_LOGIN_SESSION     = 'different_login_session';
	const BASELINE_AFTER_UPDATE_START = 'baseline_after_update_start';
	const BASELINE_EXPIRED            = 'baseline_expired';
	const AFTER_DURING_UPDATE         = 'after_during_update';
	const BEFORE_INCONCLUSIVE         = 'before_inconclusive';
	const AFTER_INCONCLUSIVE          = 'after_inconclusive';
	const REDIRECTED_BOTH             = 'redirected_both';
}
