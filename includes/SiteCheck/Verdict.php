<?php
/**
 * Verdicts of site check attempts and results.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed verdict and reason codes.
 *
 * - `pass`:         WordPress was verified as the origin and the response
 *                   had the expected form (complete page, own user's JSON).
 * - `fail`:         an observed failure (HTTP error, timeout, incomplete
 *                   page, unexpected REST response). A result fails only when
 *                   both attempts failed. A failure is what the browser
 *                   observed, never proof of downtime or of its cause: two
 *                   `timeout`s mean no response arrived within the time
 *                   limit twice; a 5xx or 404 may come from a proxy or CDN
 *                   (the attempt's origin says whether WordPress was
 *                   verified).
 * - `redirect`:     the homepage redirected (the target is not visible to
 *                   the browser). Neither a pass nor a failure.
 * - `inconclusive`: the observation says nothing reliable about the site
 *                   (not verified, stale marker, challenge, blocked, rate
 *                   limited, maintenance, network error, unconfirmed).
 *                   Never a pass.
 */
final class Verdict {

	const PASS         = 'pass';
	const FAIL         = 'fail';
	const REDIRECT     = 'redirect';
	const INCONCLUSIVE = 'inconclusive';

	/**
	 * Failure reasons.
	 */
	const SERVER_ERROR    = 'server_error';
	const NOT_FOUND       = 'not_found';
	const REFUSED         = 'refused';
	const CLIENT_ERROR    = 'client_error';
	const TIMEOUT         = 'timeout';
	const INCOMPLETE      = 'incomplete';
	const WRONG_USER      = 'wrong_user';
	const UNEXPECTED_JSON = 'unexpected_json';
	const EXTRA_OUTPUT    = 'extra_output';
	const INVALID_JSON    = 'invalid_json';

	/**
	 * Redirect reason (homepage: `redirect` verdict; REST: a failure).
	 */
	const REDIRECTED = 'redirected';

	/**
	 * Inconclusive reasons. `unavailable_retry_after` is an HTTP 503 with a
	 * Retry-After header (maintenance style), whoever sent it.
	 */
	const NOT_VERIFIED            = 'not_verified';
	const STALE_MARKER            = 'stale_marker';
	const CHALLENGE               = 'challenge';
	const BLOCKED                 = 'blocked';
	const RATE_LIMITED            = 'rate_limited';
	const UNAVAILABLE_RETRY_AFTER = 'unavailable_retry_after';
	const NETWORK_ERROR           = 'network_error';

	/**
	 * A verified 2xx response the browser could not inspect (larger than its
	 * size limit), so completeness or the user is unknown.
	 */
	const NOT_INSPECTED = 'not_inspected';

	/**
	 * Result only: one failure or redirect without a confirming second
	 * attempt (retry missing, passed, or saw something else).
	 */
	const UNCONFIRMED = 'unconfirmed';
}
