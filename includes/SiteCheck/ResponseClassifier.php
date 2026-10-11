<?php
/**
 * Classification of browser-observed site check responses.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the facts a browser reported for one request into an Attempt, and
 * attempts into verdicts. Pure: no WordPress, no I/O.
 *
 * Observed outcome (by HTTP status, unless the browser saw a bot/firewall
 * challenge, which wins): 2xx `success`, 3xx or an opaque redirect
 * `redirect`, 401 `auth_required`, 403 `forbidden`, 404/410 `not_found`,
 * 429 `rate_limited`, 503 with Retry-After `unavailable` (maintenance
 * style), other 5xx `server_error`, other 4xx `client_error`; no response
 * `timeout` or `network_error`.
 *
 * Attempt verdicts:
 *
 * - `timeout` → fail; `server_error` and `not_found` → fail whatever the
 *   origin (an observed HTTP failure, not a claim about who produced it).
 * - `network_error`, `challenge`, `rate_limited`, `unavailable` →
 *   inconclusive.
 * - `auth_required`, `forbidden`, `client_error` → fail (`refused`,
 *   `client_error`) only when WordPress is verified as the origin; otherwise
 *   inconclusive `blocked` (firewall, Basic Auth, host rules).
 * - `redirect` → homepage `redirect`, REST fail (`redirected`).
 * - `success` without a verified origin → inconclusive (`stale_marker` when
 *   another session's marker came back, else `not_verified`). Verified:
 *   homepage passes only with the current end-of-page marker (absent →
 *   fail `incomplete`, stale → inconclusive); REST passes only with JSON
 *   naming the session's user (another user → `wrong_user`, other JSON or a
 *   WordPress error object → `unexpected_json`, JSON after other output →
 *   `extra_output`, invalid or empty → `invalid_json`).
 *
 * Result verdicts (see result_verdict()): a pass needs one passing attempt;
 * a failure or redirect needs two attempts agreeing; anything else is
 * inconclusive.
 */
final class ResponseClassifier {

	/**
	 * Browser fact values for markers: this session's, none, another one.
	 */
	const FACT_MARKERS = array( 'current', 'absent', 'other' );

	/**
	 * Browser fact values for the homepage end-of-page marker: as FACT_MARKERS,
	 * plus `unread` when the page was not inspected (too large, or its body not
	 * received completely within the time limit).
	 */
	const FACT_END_MARKERS = array( 'current', 'absent', 'other', 'unread' );

	/**
	 * Browser fact values for REST bodies.
	 */
	const FACT_BODIES = array( 'json_object', 'json_other', 'json_after_output', 'invalid_json', 'empty', 'unread' );

	/**
	 * Attempt from validated browser facts (see ReportValidator).
	 *
	 * @param string $check         Check identifier.
	 * @param array  $facts         Facts of one attempt.
	 * @param int    $expected_user ID of the user the check session belongs to.
	 * @return Attempt
	 * @throws InvalidArgumentException If the facts are invalid.
	 */
	public static function attempt( $check, array $facts, $expected_user ) {
		$home = CheckDefinition::HOME === $check;
		$kind = isset( $facts['kind'] ) ? $facts['kind'] : null;

		if ( 'response' !== $kind ) {
			$outcomes = array(
				'redirect'      => Attempt::OUTCOME_REDIRECT,
				'timeout'       => Attempt::OUTCOME_TIMEOUT,
				'network_error' => Attempt::OUTCOME_NETWORK_ERROR,
			);
			if ( ! is_string( $kind ) || ! isset( $outcomes[ $kind ] ) ) {
				throw new InvalidArgumentException( 'Invalid UpdateLens site check attempt.' );
			}
			$data = array(
				'outcome' => $outcomes[ $kind ],
				'status'  => null,
				'header'  => Attempt::NOT_READ,
			);
			$data = $home
				? $data + array( 'end_marker' => Attempt::NOT_READ )
				: $data + array(
					'body'       => Attempt::NOT_READ,
					'error_code' => null,
				);

			return Attempt::from_array( $check, $data + array( 'duration_ms' => self::value( $facts, 'duration_ms' ) ) );
		}

		$status = self::value( $facts, 'status' );
		if ( ! is_int( $status ) || ! is_bool( self::value( $facts, 'retry_after' ) ) || ! is_bool( self::value( $facts, 'challenge' ) ) ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check attempt.' );
		}
		$data = array(
			'outcome' => self::outcome( $status, $facts['retry_after'], $facts['challenge'] ),
			'status'  => $status,
			'header'  => self::marker( self::value( $facts, 'header' ) ),
		);
		if ( $home ) {
			$end                = self::value( $facts, 'end_marker' );
			$data['end_marker'] = 'unread' === $end ? Attempt::NOT_READ : self::marker( $end );
		} else {
			$data += self::rest_body( $facts, $expected_user );
		}

		return Attempt::from_array( $check, $data + array( 'duration_ms' => self::value( $facts, 'duration_ms' ) ) );
	}

	/**
	 * Observed outcome of a readable response.
	 *
	 * @param int  $status      HTTP status (200–599).
	 * @param bool $retry_after Whether a Retry-After header was present.
	 * @param bool $challenge   Whether the browser recognised a bot/firewall challenge.
	 * @return string Outcome constant.
	 */
	public static function outcome( $status, $retry_after, $challenge ) {
		if ( $challenge ) {
			return Attempt::OUTCOME_CHALLENGE;
		}
		if ( 503 === $status && $retry_after ) {
			return Attempt::OUTCOME_UNAVAILABLE;
		}
		return self::status_outcome( $status );
	}

	/**
	 * Outcomes a readable response with this status can have.
	 *
	 * @param int $status HTTP status.
	 * @return string[] Empty for statuses a browser never exposes.
	 */
	public static function outcomes_for_status( $status ) {
		if ( ! is_int( $status ) || $status < 200 || $status > 599 ) {
			return array();
		}
		$outcomes = array( self::status_outcome( $status ), Attempt::OUTCOME_CHALLENGE );
		if ( 503 === $status ) {
			$outcomes[] = Attempt::OUTCOME_UNAVAILABLE;
		}
		return $outcomes;
	}

	/**
	 * Verdict of one attempt.
	 *
	 * @param Attempt $attempt Attempt.
	 * @return array{0: string, 1: string|null} Verdict and reason.
	 */
	public static function verdict( Attempt $attempt ) {
		$verified = Attempt::ORIGIN_VERIFIED === $attempt->get_origin();

		switch ( $attempt->get_outcome() ) {
			case Attempt::OUTCOME_SUCCESS:
				return self::success_verdict( $attempt );
			case Attempt::OUTCOME_REDIRECT:
				return CheckDefinition::HOME === $attempt->get_check()
					? array( Verdict::REDIRECT, Verdict::REDIRECTED )
					: array( Verdict::FAIL, Verdict::REDIRECTED );
			case Attempt::OUTCOME_TIMEOUT:
				return array( Verdict::FAIL, Verdict::TIMEOUT );
			case Attempt::OUTCOME_SERVER_ERROR:
				return array( Verdict::FAIL, Verdict::SERVER_ERROR );
			case Attempt::OUTCOME_NOT_FOUND:
				return array( Verdict::FAIL, Verdict::NOT_FOUND );
			case Attempt::OUTCOME_AUTH_REQUIRED:
			case Attempt::OUTCOME_FORBIDDEN:
				return $verified ? array( Verdict::FAIL, Verdict::REFUSED ) : array( Verdict::INCONCLUSIVE, Verdict::BLOCKED );
			case Attempt::OUTCOME_CLIENT_ERROR:
				return $verified ? array( Verdict::FAIL, Verdict::CLIENT_ERROR ) : array( Verdict::INCONCLUSIVE, Verdict::BLOCKED );
			case Attempt::OUTCOME_CHALLENGE:
				return array( Verdict::INCONCLUSIVE, Verdict::CHALLENGE );
			case Attempt::OUTCOME_RATE_LIMITED:
				return array( Verdict::INCONCLUSIVE, Verdict::RATE_LIMITED );
			case Attempt::OUTCOME_UNAVAILABLE:
				return array( Verdict::INCONCLUSIVE, Verdict::UNAVAILABLE_RETRY_AFTER );
			default:
				return array( Verdict::INCONCLUSIVE, Verdict::NETWORK_ERROR );
		}
	}

	/**
	 * Verdict of a check run from its attempts (in order).
	 *
	 * - One attempt: a pass passes; any other verdict is inconclusive (an
	 *   inconclusive attempt keeps its reason, a failure or redirect is
	 *   `unconfirmed`).
	 * - Two attempts (the first never a pass): both fail → fail with the
	 *   second attempt's reason (both attempts stay as evidence); both
	 *   redirect → redirect; an inconclusive first attempt followed by a pass
	 *   → pass; two inconclusive attempts → inconclusive with the second's
	 *   reason; every other pair (a failure or redirect not repeated) →
	 *   inconclusive `unconfirmed`.
	 *
	 * @param Attempt[] $attempts One or two attempts.
	 * @return array{0: string, 1: string|null} Verdict and reason.
	 * @throws InvalidArgumentException If the attempts are not a valid run.
	 */
	public static function result_verdict( array $attempts ) {
		$count = count( $attempts );
		if ( $count < 1 || $count > CheckDefinition::MAX_ATTEMPTS || array_values( $attempts ) !== $attempts ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check attempts.' );
		}
		$first = self::verdict( $attempts[0] );
		if ( 1 === $count ) {
			if ( Verdict::PASS === $first[0] || Verdict::INCONCLUSIVE === $first[0] ) {
				return $first;
			}
			return array( Verdict::INCONCLUSIVE, Verdict::UNCONFIRMED );
		}

		$second = self::verdict( $attempts[1] );
		if ( Verdict::PASS === $first[0] ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check attempts.' );
		}
		if ( Verdict::INCONCLUSIVE === $first[0] && ( Verdict::PASS === $second[0] || Verdict::INCONCLUSIVE === $second[0] ) ) {
			return $second;
		}
		if ( $first[0] === $second[0] && Verdict::INCONCLUSIVE !== $first[0] ) {
			return $second;
		}
		return array( Verdict::INCONCLUSIVE, Verdict::UNCONFIRMED );
	}

	/**
	 * Verdict of a readable 2xx response.
	 *
	 * @param Attempt $attempt Attempt with outcome `success`.
	 * @return array{0: string, 1: string|null}
	 */
	private static function success_verdict( Attempt $attempt ) {
		if ( Attempt::ORIGIN_VERIFIED !== $attempt->get_origin() ) {
			return array( Verdict::INCONCLUSIVE, $attempt->has_stale_marker() ? Verdict::STALE_MARKER : Verdict::NOT_VERIFIED );
		}

		if ( Attempt::NOT_READ === $attempt->get_end_marker() || Attempt::NOT_READ === $attempt->get_body() ) {
			return array( Verdict::INCONCLUSIVE, Verdict::NOT_INSPECTED );
		}
		if ( CheckDefinition::HOME === $attempt->get_check() ) {
			switch ( $attempt->get_end_marker() ) {
				case Attempt::MARKER_CURRENT:
					return array( Verdict::PASS, null );
				case Attempt::MARKER_STALE:
					return array( Verdict::INCONCLUSIVE, Verdict::STALE_MARKER );
				default:
					return array( Verdict::FAIL, Verdict::INCOMPLETE );
			}
		}

		switch ( $attempt->get_body() ) {
			case Attempt::BODY_EXPECTED_USER:
				return array( Verdict::PASS, null );
			case Attempt::BODY_OTHER_USER:
				return array( Verdict::FAIL, Verdict::WRONG_USER );
			case Attempt::BODY_EXTRA_OUTPUT:
				return array( Verdict::FAIL, Verdict::EXTRA_OUTPUT );
			case Attempt::BODY_WP_ERROR:
			case Attempt::BODY_UNEXPECTED_JSON:
				return array( Verdict::FAIL, Verdict::UNEXPECTED_JSON );
			default:
				return array( Verdict::FAIL, Verdict::INVALID_JSON );
		}
	}

	/**
	 * Outcome by status alone.
	 *
	 * @param int $status HTTP status (200–599).
	 * @return string
	 */
	private static function status_outcome( $status ) {
		if ( $status < 300 ) {
			return Attempt::OUTCOME_SUCCESS;
		}
		if ( $status < 400 ) {
			return Attempt::OUTCOME_REDIRECT;
		}
		$by_status = array(
			401 => Attempt::OUTCOME_AUTH_REQUIRED,
			403 => Attempt::OUTCOME_FORBIDDEN,
			404 => Attempt::OUTCOME_NOT_FOUND,
			410 => Attempt::OUTCOME_NOT_FOUND,
			429 => Attempt::OUTCOME_RATE_LIMITED,
		);
		if ( isset( $by_status[ $status ] ) ) {
			return $by_status[ $status ];
		}
		return $status < 500 ? Attempt::OUTCOME_CLIENT_ERROR : Attempt::OUTCOME_SERVER_ERROR;
	}

	/**
	 * Marker state from a browser fact.
	 *
	 * @param mixed $fact `current`, `absent` or `other`.
	 * @return string
	 * @throws InvalidArgumentException If the fact is invalid.
	 */
	private static function marker( $fact ) {
		if ( ! in_array( $fact, self::FACT_MARKERS, true ) ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check attempt.' );
		}
		return 'other' === $fact ? Attempt::MARKER_STALE : $fact;
	}

	/**
	 * Normalized REST body facts. The user ID is compared, never kept; a
	 * WordPress error code is kept only when it is a known core code.
	 *
	 * @param array $facts         Facts of one attempt.
	 * @param int   $expected_user Session user ID.
	 * @return array{body: string, error_code: string|null}
	 * @throws InvalidArgumentException If the facts are invalid.
	 */
	private static function rest_body( array $facts, $expected_user ) {
		$body       = self::value( $facts, 'body' );
		$user       = self::value( $facts, 'user_id' );
		$error_code = self::value( $facts, 'error_code' );
		if ( ! is_int( $expected_user ) || $expected_user < 1 || ! in_array( $body, self::FACT_BODIES, true ) ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check attempt.' );
		}
		if ( ( null !== $user && ( ! is_int( $user ) || $user < 1 ) ) || ( null !== $error_code && ! is_string( $error_code ) ) ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check attempt.' );
		}
		if ( 'json_object' !== $body && ( null !== $user || null !== $error_code ) ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check attempt.' );
		}

		$bodies = array(
			'unread'            => Attempt::NOT_READ,
			'json_other'        => Attempt::BODY_UNEXPECTED_JSON,
			'json_after_output' => Attempt::BODY_EXTRA_OUTPUT,
			'invalid_json'      => Attempt::BODY_INVALID_JSON,
			'empty'             => Attempt::BODY_EMPTY,
		);
		if ( isset( $bodies[ $body ] ) ) {
			return array(
				'body'       => $bodies[ $body ],
				'error_code' => null,
			);
		}

		if ( null !== $error_code ) {
			return array(
				'body'       => Attempt::BODY_WP_ERROR,
				'error_code' => in_array( $error_code, Attempt::KNOWN_ERROR_CODES, true ) ? $error_code : Attempt::ERROR_CODE_OTHER,
			);
		}
		if ( null === $user ) {
			$normalized = Attempt::BODY_UNEXPECTED_JSON;
		} else {
			$normalized = $expected_user === $user ? Attempt::BODY_EXPECTED_USER : Attempt::BODY_OTHER_USER;
		}

		return array(
			'body'       => $normalized,
			'error_code' => null,
		);
	}

	/**
	 * A fact or null when absent.
	 *
	 * @param array  $facts Facts.
	 * @param string $key   Key.
	 * @return mixed
	 */
	private static function value( array $facts, $key ) {
		return array_key_exists( $key, $facts ) ? $facts[ $key ] : null;
	}
}
