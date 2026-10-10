<?php
/**
 * One classified attempt of a site check.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * What the browser observed in one request, normalized to fixed codes.
 *
 * Two dimensions are kept apart:
 *
 * - **Observed outcome** (`outcome`, `status`, `duration_ms`, and the
 *   response form: `end_marker` for the homepage, `body` + `error_code` for
 *   REST): what the browser saw, whoever produced it.
 * - **Origin** (`origin()`, from the `header` and, for the homepage, the
 *   end-of-page marker): `verified` when this check session's marker came
 *   back, so WordPress generated the response; `unverified` when a response
 *   was readable without the current marker (absent or `stale`, e.g. from a
 *   cache); `unknown` when no response was readable (opaque redirect,
 *   timeout, network error).
 *
 * Holds no bodies, headers, URLs, tokens, user IDs or error messages: only
 * codes, the HTTP status and the duration. Immutable; build it with
 * ResponseClassifier::attempt() (browser facts) or from_array() (stored).
 */
final class Attempt {

	/**
	 * Observed outcomes.
	 */
	const OUTCOME_SUCCESS       = 'success';
	const OUTCOME_REDIRECT      = 'redirect';
	const OUTCOME_AUTH_REQUIRED = 'auth_required';
	const OUTCOME_FORBIDDEN     = 'forbidden';
	const OUTCOME_NOT_FOUND     = 'not_found';
	const OUTCOME_RATE_LIMITED  = 'rate_limited';
	const OUTCOME_UNAVAILABLE   = 'unavailable';
	const OUTCOME_SERVER_ERROR  = 'server_error';
	const OUTCOME_CLIENT_ERROR  = 'client_error';
	const OUTCOME_CHALLENGE     = 'challenge';
	const OUTCOME_TIMEOUT       = 'timeout';
	const OUTCOME_NETWORK_ERROR = 'network_error';

	const OUTCOMES = array(
		self::OUTCOME_SUCCESS,
		self::OUTCOME_REDIRECT,
		self::OUTCOME_AUTH_REQUIRED,
		self::OUTCOME_FORBIDDEN,
		self::OUTCOME_NOT_FOUND,
		self::OUTCOME_RATE_LIMITED,
		self::OUTCOME_UNAVAILABLE,
		self::OUTCOME_SERVER_ERROR,
		self::OUTCOME_CLIENT_ERROR,
		self::OUTCOME_CHALLENGE,
		self::OUTCOME_TIMEOUT,
		self::OUTCOME_NETWORK_ERROR,
	);

	/**
	 * Origin of the response.
	 */
	const ORIGIN_VERIFIED   = 'verified';
	const ORIGIN_UNVERIFIED = 'unverified';
	const ORIGIN_UNKNOWN    = 'unknown';

	/**
	 * Marker states (verification header, homepage end-of-page marker):
	 * this session's marker, none, another session's marker, or not read
	 * (no readable response; for the end marker and the REST body also a
	 * response too large to inspect).
	 */
	const MARKER_CURRENT = 'current';
	const MARKER_ABSENT  = 'absent';
	const MARKER_STALE   = 'stale';
	const NOT_READ       = 'not_read';

	const MARKERS = array( self::MARKER_CURRENT, self::MARKER_ABSENT, self::MARKER_STALE );

	/**
	 * REST response bodies.
	 */
	const BODY_EXPECTED_USER   = 'expected_user';
	const BODY_OTHER_USER      = 'other_user';
	const BODY_WP_ERROR        = 'wp_error';
	const BODY_UNEXPECTED_JSON = 'unexpected_json';
	const BODY_EXTRA_OUTPUT    = 'extra_output';
	const BODY_INVALID_JSON    = 'invalid_json';
	const BODY_EMPTY           = 'empty';

	const BODIES = array(
		self::BODY_EXPECTED_USER,
		self::BODY_OTHER_USER,
		self::BODY_WP_ERROR,
		self::BODY_UNEXPECTED_JSON,
		self::BODY_EXTRA_OUTPUT,
		self::BODY_INVALID_JSON,
		self::BODY_EMPTY,
	);

	/**
	 * WordPress REST error codes kept as evidence; any other code is
	 * stored as ERROR_CODE_OTHER (codes are developer-defined text).
	 */
	const KNOWN_ERROR_CODES = array(
		'rest_cookie_invalid_nonce',
		'rest_forbidden',
		'rest_no_route',
		'rest_not_logged_in',
	);

	const ERROR_CODE_OTHER = 'other';

	/**
	 * Upper bound of a stored duration, in milliseconds.
	 */
	const MAX_DURATION_MS = 60000;

	/**
	 * Keys of the stored form, per check, in order.
	 */
	const HOME_KEYS = array( 'outcome', 'status', 'header', 'end_marker', 'duration_ms' );
	const REST_KEYS = array( 'outcome', 'status', 'header', 'body', 'error_code', 'duration_ms' );

	/**
	 * Check identifier.
	 *
	 * @var string
	 */
	private $check;

	/**
	 * Normalized facts in stored order (HOME_KEYS or REST_KEYS).
	 *
	 * @var array
	 */
	private $facts;

	/**
	 * Constructor.
	 *
	 * @param string $check Check identifier.
	 * @param array  $facts Validated facts.
	 */
	private function __construct( $check, array $facts ) {
		$this->check = $check;
		$this->facts = $facts;
	}

	/**
	 * Build from the stored (normalized) form, validating every field and
	 * their consistency.
	 *
	 * @param string $check Check identifier.
	 * @param mixed  $data  Normalized facts (see HOME_KEYS / REST_KEYS).
	 * @return self
	 * @throws InvalidArgumentException If the data is not a valid attempt.
	 */
	public static function from_array( $check, $data ) {
		if ( ! CheckDefinition::is_id( $check ) ) {
			throw new InvalidArgumentException( 'Unknown UpdateLens site check.' );
		}
		$keys = CheckDefinition::HOME === $check ? self::HOME_KEYS : self::REST_KEYS;
		if ( ! is_array( $data ) || array_keys( $data ) !== $keys || ! self::is_valid( $check, $data ) ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check attempt.' );
		}

		return new self( $check, $data );
	}

	/**
	 * Whether normalized facts are valid and consistent.
	 *
	 * @param string $check Check identifier.
	 * @param array  $data  Facts with the right keys.
	 * @return bool
	 */
	private static function is_valid( $check, array $data ) {
		$outcome = $data['outcome'];
		$status  = $data['status'];
		if ( ! in_array( $outcome, self::OUTCOMES, true ) ) {
			return false;
		}
		if ( ! is_int( $data['duration_ms'] ) || $data['duration_ms'] < 0 || $data['duration_ms'] > self::MAX_DURATION_MS ) {
			return false;
		}

		if ( null === $status ) {
			// No readable response: an opaque redirect, a timeout or a network error.
			if ( ! in_array( $outcome, array( self::OUTCOME_REDIRECT, self::OUTCOME_TIMEOUT, self::OUTCOME_NETWORK_ERROR ), true ) || self::NOT_READ !== $data['header'] ) {
				return false;
			}
			if ( CheckDefinition::HOME === $check ) {
				return self::NOT_READ === $data['end_marker'];
			}
			return self::NOT_READ === $data['body'] && null === $data['error_code'];
		}

		if ( ! is_int( $status ) || ! in_array( $outcome, ResponseClassifier::outcomes_for_status( $status ), true ) ) {
			return false;
		}
		if ( ! in_array( $data['header'], self::MARKERS, true ) ) {
			return false;
		}
		// NOT_READ with a status: the response was too large to inspect.
		if ( CheckDefinition::HOME === $check ) {
			return self::NOT_READ === $data['end_marker'] || in_array( $data['end_marker'], self::MARKERS, true );
		}
		if ( self::NOT_READ === $data['body'] ) {
			return null === $data['error_code'];
		}
		if ( ! in_array( $data['body'], self::BODIES, true ) ) {
			return false;
		}
		if ( self::BODY_WP_ERROR === $data['body'] ) {
			return self::ERROR_CODE_OTHER === $data['error_code'] || in_array( $data['error_code'], self::KNOWN_ERROR_CODES, true );
		}
		return null === $data['error_code'];
	}

	/**
	 * Check identifier.
	 *
	 * @return string
	 */
	public function get_check() {
		return $this->check;
	}

	/**
	 * Observed outcome.
	 *
	 * @return string
	 */
	public function get_outcome() {
		return $this->facts['outcome'];
	}

	/**
	 * HTTP status, null when no response was readable.
	 *
	 * @return int|null
	 */
	public function get_status() {
		return $this->facts['status'];
	}

	/**
	 * Verification header state.
	 *
	 * @return string
	 */
	public function get_header() {
		return $this->facts['header'];
	}

	/**
	 * Homepage end-of-page marker state (null for REST).
	 *
	 * @return string|null
	 */
	public function get_end_marker() {
		return CheckDefinition::HOME === $this->check ? $this->facts['end_marker'] : null;
	}

	/**
	 * REST body (null for the homepage).
	 *
	 * @return string|null
	 */
	public function get_body() {
		return CheckDefinition::REST === $this->check ? $this->facts['body'] : null;
	}

	/**
	 * REST error code (`wp_error` bodies only).
	 *
	 * @return string|null
	 */
	public function get_error_code() {
		return CheckDefinition::REST === $this->check ? $this->facts['error_code'] : null;
	}

	/**
	 * Duration in milliseconds.
	 *
	 * @return int
	 */
	public function get_duration_ms() {
		return $this->facts['duration_ms'];
	}

	/**
	 * Origin of the response: `verified` when the current marker came back
	 * (header, or for the homepage the end-of-page marker), `unknown` when
	 * nothing was readable, else `unverified`.
	 *
	 * @return string
	 */
	public function get_origin() {
		if ( null === $this->facts['status'] ) {
			return self::ORIGIN_UNKNOWN;
		}
		if ( self::MARKER_CURRENT === $this->facts['header'] || self::MARKER_CURRENT === $this->get_end_marker() ) {
			return self::ORIGIN_VERIFIED;
		}
		return self::ORIGIN_UNVERIFIED;
	}

	/**
	 * Whether a marker of another check session came back.
	 *
	 * @return bool
	 */
	public function has_stale_marker() {
		return self::MARKER_STALE === $this->facts['header'] || self::MARKER_STALE === $this->get_end_marker();
	}

	/**
	 * Verdict of this attempt.
	 *
	 * @return string Verdict constant.
	 */
	public function get_verdict() {
		return ResponseClassifier::verdict( $this )[0];
	}

	/**
	 * Reason of the verdict (null for a pass).
	 *
	 * @return string|null Verdict reason constant.
	 */
	public function get_reason() {
		return ResponseClassifier::verdict( $this )[1];
	}

	/**
	 * Stored form (HOME_KEYS or REST_KEYS): facts only, nothing derived.
	 *
	 * @return array
	 */
	public function to_array() {
		return $this->facts;
	}

	/**
	 * Report form: the stored facts plus origin, verdict and reason.
	 *
	 * @return array
	 */
	public function to_report_array() {
		$verdict = ResponseClassifier::verdict( $this );
		return array_merge(
			$this->facts,
			array(
				'origin'  => $this->get_origin(),
				'verdict' => $verdict[0],
				'reason'  => $verdict[1],
			)
		);
	}
}
