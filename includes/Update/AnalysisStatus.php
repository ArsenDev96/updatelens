<?php
/**
 * Analysis lifecycle states.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

defined( 'ABSPATH' ) || exit;

/**
 * States of a plugin update analysis.
 *
 * Transitions:
 *
 *   captured ──────► awaiting_settle ──────► completed
 *   captured ──┬──► failed       ◄──┬── awaiting_settle
 *              ├──► incompatible ◄──┤
 *              └──► abandoned    ◄──┘
 *
 * - captured:        BEFORE snapshot stored; the WordPress update is running.
 * - awaiting_settle: Update succeeded, immediate diff stored; waiting for a
 *                    later admin request to capture the settled state.
 * - completed:       Settled diff stored. Final.
 * - failed:          The update failed or did not finish, or UpdateLens could
 *                    not analyse it (see error_code). Final.
 * - incompatible:    Fingerprint context changed between captures (e.g.
 *                    rotated salts); values cannot be compared. Final.
 * - abandoned:       Never finished (stale) or attribution became unreliable.
 *                    Final.
 *
 * The BEFORE snapshot is cleared on every final state.
 */
final class AnalysisStatus {

	const CAPTURED        = 'captured';
	const AWAITING_SETTLE = 'awaiting_settle';
	const COMPLETED       = 'completed';
	const FAILED          = 'failed';
	const INCOMPATIBLE    = 'incompatible';
	const ABANDONED       = 'abandoned';
}
