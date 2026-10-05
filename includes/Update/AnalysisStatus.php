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
 * - awaiting_settle: Update succeeded; during-update diff and IMMEDIATE
 *                    snapshot stored; waiting (until settle_deadline) for a
 *                    later admin request to capture the settled state.
 * - completed:       Final. With settle_outcome admin_shutdown/next_update the
 *                    post-update and final diffs are stored; with `expired`
 *                    only the during-update diff exists.
 * - failed:          The update failed or did not finish, or UpdateLens could
 *                    not analyse it (see error_code). Final.
 * - incompatible:    Fingerprint context changed between captures (e.g.
 *                    rotated salts); values cannot be compared. Final.
 * - abandoned:       Never finished (stale), or another update ran in the same
 *                    request. Final.
 *
 * Both temporary snapshots are cleared on every final state. See
 * SettleOutcome for how the settle phase ended.
 */
final class AnalysisStatus {

	const CAPTURED        = 'captured';
	const AWAITING_SETTLE = 'awaiting_settle';
	const COMPLETED       = 'completed';
	const FAILED          = 'failed';
	const INCOMPATIBLE    = 'incompatible';
	const ABANDONED       = 'abandoned';
}
