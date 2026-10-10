<?php
/**
 * A short-lived site check session.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * One check run's server-side record: which check, for which purpose,
 * owned by which user and login session, and until when it is live.
 *
 * A session has two random tokens:
 *
 * - the **marker** (MARKER_BYTES, hex): public. It is the session ID, goes
 *   into the homepage URL / REST request header, and comes back in the
 *   response's verification header and end-of-page marker. Knowing it only
 *   lets a request receive the marker and an uncached render until expiry.
 * - the **secret** (SECRET_BYTES, hex): private to the owner's browser. It
 *   is needed, together with the owner's login, to submit the one result.
 *
 * The record also keeps the updater activity generation at its start
 * (UpdateActivity), so the result can later be tied to the updates around
 * it.
 *
 * Neither token is stored: the record is kept under a key derived from the
 * marker (storage_key()) and holds only a SHA-256 of the secret. Both are
 * 128/256-bit random values, so an unkeyed hash is sufficient (as for
 * WordPress session tokens).
 */
final class CheckSession {

	/**
	 * Lifetime in seconds (from start to the last accepted report).
	 */
	const TTL = 120;

	/**
	 * Random bytes per token.
	 */
	const MARKER_BYTES = 16;
	const SECRET_BYTES = 32;

	/**
	 * Purposes: the baseline before an update, or the observation after it.
	 */
	const PURPOSE_BEFORE = 'before';
	const PURPOSE_AFTER  = 'after';
	const PURPOSES       = array( self::PURPOSE_BEFORE, self::PURPOSE_AFTER );

	/**
	 * Prefix of the transient that holds a session.
	 */
	const STORAGE_PREFIX = 'updatelens_sc_';

	/**
	 * Keys of the array form, in order.
	 */
	const KEYS = array( 'check', 'definition', 'purpose', 'user', 'login', 'target', 'secret_hash', 'created_at', 'expires_at', 'generation' );

	/**
	 * Array form (validated).
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array $data Validated array form.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Build from the array form, validating every field.
	 *
	 * @param mixed $data Array form (see KEYS).
	 * @return self
	 * @throws InvalidArgumentException If the data is not a valid session.
	 */
	public static function from_array( $data ) {
		if ( ! is_array( $data ) || self::KEYS !== array_keys( $data ) ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check session.' );
		}
		$valid = CheckDefinition::is_id( $data['check'] )
			&& is_int( $data['definition'] ) && $data['definition'] >= 1 && $data['definition'] <= CheckResult::MAX_DEFINITION_VERSION
			&& in_array( $data['purpose'], self::PURPOSES, true )
			&& is_int( $data['user'] ) && $data['user'] >= 1
			&& CheckContext::is_fingerprint( $data['login'] )
			&& CheckContext::is_fingerprint( $data['target'] )
			&& CheckContext::is_fingerprint( $data['secret_hash'] )
			&& CheckResult::is_timestamp( $data['created_at'] )
			&& CheckResult::is_timestamp( $data['expires_at'] )
			&& $data['expires_at'] > $data['created_at']
			&& $data['expires_at'] - $data['created_at'] <= self::TTL
			&& ( null === $data['generation'] || UpdateActivity::is_generation( $data['generation'] ) );
		if ( ! $valid ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check session.' );
		}

		$data['created_at'] = (float) $data['created_at'];
		$data['expires_at'] = (float) $data['expires_at'];

		return new self( $data );
	}

	/**
	 * Whether a value is a well-formed marker (lowercase hex). Checked before
	 * any lookup, so malformed values cost nothing.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_marker( $value ) {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{' . ( 2 * self::MARKER_BYTES ) . '}\z/', $value );
	}

	/**
	 * Whether a value is a well-formed secret (lowercase hex).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_secret( $value ) {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{' . ( 2 * self::SECRET_BYTES ) . '}\z/', $value );
	}

	/**
	 * Stored form of a secret.
	 *
	 * @param string $secret Secret.
	 * @return string 64 lowercase hex.
	 */
	public static function hash_secret( $secret ) {
		return hash( 'sha256', (string) $secret );
	}

	/**
	 * Transient name of the session with this marker (no raw marker stored).
	 *
	 * @param string $marker Well-formed marker.
	 * @return string
	 */
	public static function storage_key( $marker ) {
		return self::STORAGE_PREFIX . hash( 'sha256', (string) $marker );
	}

	/**
	 * Whether a secret is this session's.
	 *
	 * @param string $secret Well-formed secret.
	 * @return bool
	 */
	public function matches_secret( $secret ) {
		return hash_equals( $this->data['secret_hash'], self::hash_secret( $secret ) );
	}

	/**
	 * Whether the session is past its lifetime at a time.
	 *
	 * @param float $now Unix seconds.
	 * @return bool
	 */
	public function is_expired( $now ) {
		return $now >= $this->data['expires_at'];
	}

	/**
	 * Check identifier.
	 *
	 * @return string
	 */
	public function get_check() {
		return $this->data['check'];
	}

	/**
	 * Definition version at the start.
	 *
	 * @return int
	 */
	public function get_definition_version() {
		return $this->data['definition'];
	}

	/**
	 * Purpose.
	 *
	 * @return string
	 */
	public function get_purpose() {
		return $this->data['purpose'];
	}

	/**
	 * Owner's user ID.
	 *
	 * @return int
	 */
	public function get_user() {
		return $this->data['user'];
	}

	/**
	 * Owner's login-session fingerprint.
	 *
	 * @return string
	 */
	public function get_login() {
		return $this->data['login'];
	}

	/**
	 * Target fingerprint.
	 *
	 * @return string
	 */
	public function get_target() {
		return $this->data['target'];
	}

	/**
	 * Start (Unix seconds).
	 *
	 * @return float
	 */
	public function get_created_at() {
		return $this->data['created_at'];
	}

	/**
	 * End of life (Unix seconds).
	 *
	 * @return float
	 */
	public function get_expires_at() {
		return $this->data['expires_at'];
	}

	/**
	 * Updater activity generation when the session started (UpdateActivity),
	 * or null if an update was running or the order could not be read.
	 *
	 * @return string|null
	 */
	public function get_generation() {
		return $this->data['generation'];
	}

	/**
	 * Array form (KEYS order).
	 *
	 * @return array
	 */
	public function to_array() {
		return $this->data;
	}
}
