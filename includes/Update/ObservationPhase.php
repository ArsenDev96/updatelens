<?php
/**
 * Observation phases of a plugin update analysis.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

defined( 'ABSPATH' ) || exit;

/**
 * The diffs an analysis stores, named by when the changes were observed.
 *
 * UpdateLens reports observed changes, not proven causes. Each phase has a
 * fixed association with the update that presentation code can rely on;
 * nothing about it is stored per analysis.
 *
 * - during_update (BEFORE → IMMEDIATE, columns `options_during_update_diff`, `cron_during_update_diff`):
 *   observed while WordPress performed this plugin update request.
 *   Strongest temporal association with the update.
 * - post_update (IMMEDIATE → SETTLED, columns `options_post_update_diff`, `cron_post_update_diff`):
 *   observed after the update request, up to the end of the first eligible
 *   wp-admin request (or the start of the next update). The new plugin code
 *   ran in this window, but other site activity may also contribute.
 * - final (BEFORE → SETTLED, columns `options_final_diff`, `cron_final_diff`):
 *   net difference across both phases.
 */
final class ObservationPhase {

	const DURING_UPDATE = 'during_update';
	const POST_UPDATE   = 'post_update';
	const FINAL         = 'final';

	/**
	 * Association of each phase with the update.
	 */
	const ASSOCIATION = array(
		self::DURING_UPDATE => 'update_request',
		self::POST_UPDATE   => 'observed_after_update',
		self::FINAL         => 'net_across_phases',
	);
}
