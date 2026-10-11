<?php
/**
 * Bounded validation of browser site check reports.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use InvalidArgumentException;
use JsonException;

defined( 'ABSPATH' ) || exit;

/**
 * Validates the JSON a browser posts for one check run and classifies its
 * attempts. Nothing in a report is trusted or echoed: sizes, depth, keys,
 * types and values are bounded, unknown keys are rejected, and failures
 * carry only fixed reason codes.
 *
 * Report shape (exact keys, attempts in order):
 *
 *     {"check":"home","attempts":[ <attempt>, <attempt>? ]}
 *
 * Attempt facts are what the browser itself observed; markers are compared
 * in the browser, so no marker or token value is ever sent:
 *
 * - no readable response: `{"kind":"redirect"|"timeout"|"network_error","duration_ms":int}`
 * - homepage response: `kind` "response", `status` (200–599),
 *   `duration_ms`, `header` ("current" | "absent" | "other"),
 *   `end_marker` (the same, or "unread" when the page was not inspected:
 *   larger than the browser's limit, or its body not received completely
 *   within the attempt's time limit), `retry_after` and `challenge` (booleans).
 * - REST response: the same without `end_marker`, plus `body`
 *   ("json_object" | "json_other" | "json_after_output" | "invalid_json" |
 *   "empty" | "unread" when not inspected, as for `end_marker`), `user_id` (int, the `id` of a JSON object, else null) and
 *   `error_code` (the string `code` of a WordPress error object, else null).
 */
final class ReportValidator {

	/**
	 * Largest accepted report, in bytes.
	 */
	const MAX_BYTES = 2048;

	/**
	 * Maximum JSON nesting depth (report → attempts → attempt).
	 */
	const MAX_DEPTH = 4;

	/**
	 * Longest accepted REST error code, in bytes (never stored as is).
	 */
	const MAX_ERROR_CODE_BYTES = 128;

	/**
	 * Keys of an attempt without a readable response.
	 */
	const NO_RESPONSE_KEYS = array( 'duration_ms', 'kind' );

	/**
	 * Keys of a readable response, per check (sorted).
	 */
	const HOME_RESPONSE_KEYS = array( 'challenge', 'duration_ms', 'end_marker', 'header', 'kind', 'retry_after', 'status' );
	const REST_RESPONSE_KEYS = array( 'body', 'challenge', 'duration_ms', 'error_code', 'header', 'kind', 'retry_after', 'status', 'user_id' );

	/**
	 * Validate a report and classify its attempts.
	 *
	 * @param mixed           $json          Posted JSON.
	 * @param CheckDefinition $definition    The session's check.
	 * @param int             $expected_user ID of the user the session belongs to.
	 * @return Attempt[] Attempts in order: a valid run (see ResponseClassifier::result_verdict()).
	 * @throws InvalidCheckReportException If the report cannot be accepted.
	 */
	public static function parse( $json, CheckDefinition $definition, $expected_user ) {
		if ( ! is_string( $json ) || '' === $json ) {
			throw InvalidCheckReportException::invalid_report();
		}
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw InvalidCheckReportException::too_large();
		}

		try {
			$data = json_decode( $json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw InvalidCheckReportException::invalid_report();
		}

		if ( ! self::has_keys( $data, array( 'attempts', 'check' ) ) || ! is_array( $data['attempts'] ) || array_values( $data['attempts'] ) !== $data['attempts'] ) {
			throw InvalidCheckReportException::invalid_report();
		}
		if ( ! CheckDefinition::is_id( $data['check'] ) ) {
			throw InvalidCheckReportException::invalid_report();
		}
		if ( $definition->get_id() !== $data['check'] ) {
			throw InvalidCheckReportException::check_mismatch();
		}
		$count = count( $data['attempts'] );
		if ( $count < 1 || $count > CheckDefinition::MAX_ATTEMPTS ) {
			throw InvalidCheckReportException::invalid_attempt();
		}

		$attempts = array();
		try {
			foreach ( $data['attempts'] as $facts ) {
				if ( ! self::is_valid_facts( $definition->get_id(), $facts ) ) {
					throw InvalidCheckReportException::invalid_attempt();
				}
				$attempts[] = ResponseClassifier::attempt( $definition->get_id(), $facts, $expected_user );
			}
			ResponseClassifier::result_verdict( $attempts );
		} catch ( InvalidArgumentException $e ) {
			throw InvalidCheckReportException::invalid_attempt();
		}

		return $attempts;
	}

	/**
	 * Whether one attempt's facts have exactly the keys and types of its kind.
	 *
	 * @param string $check Check identifier.
	 * @param mixed  $facts Facts.
	 * @return bool
	 */
	private static function is_valid_facts( $check, $facts ) {
		if ( ! is_array( $facts ) || ! isset( $facts['kind'] ) || ! is_string( $facts['kind'] ) ) {
			return false;
		}
		if ( ! array_key_exists( 'duration_ms', $facts ) || ! is_int( $facts['duration_ms'] ) || $facts['duration_ms'] < 0 || $facts['duration_ms'] > Attempt::MAX_DURATION_MS ) {
			return false;
		}
		if ( 'response' !== $facts['kind'] ) {
			return in_array( $facts['kind'], array( 'redirect', 'timeout', 'network_error' ), true ) && self::has_keys( $facts, self::NO_RESPONSE_KEYS );
		}

		$home = CheckDefinition::HOME === $check;
		if ( ! self::has_keys( $facts, $home ? self::HOME_RESPONSE_KEYS : self::REST_RESPONSE_KEYS ) ) {
			return false;
		}
		if ( ! is_int( $facts['status'] ) || $facts['status'] < 200 || $facts['status'] > 599 ) {
			return false;
		}
		if ( ! is_bool( $facts['retry_after'] ) || ! is_bool( $facts['challenge'] ) || ! in_array( $facts['header'], ResponseClassifier::FACT_MARKERS, true ) ) {
			return false;
		}
		if ( $home ) {
			return in_array( $facts['end_marker'], ResponseClassifier::FACT_END_MARKERS, true );
		}

		$user = $facts['user_id'];
		$code = $facts['error_code'];
		if ( ! in_array( $facts['body'], ResponseClassifier::FACT_BODIES, true ) ) {
			return false;
		}
		if ( null !== $user && ( ! is_int( $user ) || $user < 1 ) ) {
			return false;
		}
		return null === $code || ( is_string( $code ) && '' !== $code && strlen( $code ) <= self::MAX_ERROR_CODE_BYTES );
	}

	/**
	 * Whether a value is an array with exactly these keys (any order).
	 *
	 * @param mixed    $data Value.
	 * @param string[] $keys Sorted keys.
	 * @return bool
	 */
	private static function has_keys( $data, array $keys ) {
		if ( ! is_array( $data ) ) {
			return false;
		}
		$actual = array_keys( $data );
		sort( $actual, SORT_STRING );

		return $actual === $keys;
	}
}
