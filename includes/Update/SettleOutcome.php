<?php
/**
 * How the settle phase of an analysis concluded.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

defined( 'ABSPATH' ) || exit;

/**
 * Values of the `settle_outcome` column. NULL while the analysis is open.
 *
 * - admin_shutdown: settled snapshot taken at shutdown of the first eligible
 *                   wp-admin page request after the update.
 * - follow_up:      settled snapshot taken by the follow-up request that the
 *                   updating administrator's browser sends once the WordPress
 *                   update queue is idle (see FollowUpRequest).
 * - next_update:    settled snapshot taken right before another update started
 *                   (within the settle window), so that update is not included.
 * - expired:        no eligible request within the settle window; no settled
 *                   snapshot was taken (post-update and final diffs are NULL).
 * - not_applicable: the analysis ended before reaching the settle phase (failed
 *                   or incompatible update, abandoned analysis).
 * - batch:          a plugin of a multi-plugin update (several plugins in one
 *                   request), closed when that update ended: its After update
 *                   and Net result belong to the whole batch, so none is
 *                   stored for the plugin (post-update and final diffs NULL).
 * - batch_incomplete: the same, but the end of the multi-plugin update was not
 *                   observed (the request was interrupted, or UpdateLens itself
 *                   was updated in it, after which nothing more is observed).
 *
 * With `admin_shutdown`, `follow_up` or `next_update` and a status other than
 * `completed`, the settled snapshot was taken but could not be compared (see
 * error_code).
 */
final class SettleOutcome {

	const ADMIN_SHUTDOWN   = 'admin_shutdown';
	const FOLLOW_UP        = 'follow_up';
	const NEXT_UPDATE      = 'next_update';
	const EXPIRED          = 'expired';
	const NOT_APPLICABLE   = 'not_applicable';
	const BATCH            = 'batch';
	const BATCH_INCOMPLETE = 'batch_incomplete';
}
