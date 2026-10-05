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
 * - next_update:    settled snapshot taken right before another update started
 *                   (within the settle window), so that update is not included.
 * - expired:        no eligible request within the settle window; no settled
 *                   snapshot was taken (post-update and final diffs are NULL).
 * - not_applicable: the analysis ended before reaching the settle phase (failed
 *                   or incompatible update, abandoned analysis).
 *
 * With `admin_shutdown` or `next_update` and a status other than `completed`,
 * the settled snapshot was taken but could not be compared (see error_code).
 */
final class SettleOutcome {

	const ADMIN_SHUTDOWN = 'admin_shutdown';
	const NEXT_UPDATE    = 'next_update';
	const EXPIRED        = 'expired';
	const NOT_APPLICABLE = 'not_applicable';
}
