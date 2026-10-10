<?php
/**
 * Who and what a site check result was observed for.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * The context two results must share to be compared: the target, the
 * authentication context, the administrator and their WordPress login
 * session.
 *
 * The login session is the WordPress session of the auth cookie
 * (`wp_get_session_token()`): every tab and window of one login shares it,
 * so it does not identify a browser tab; logging out, logging in again or
 * another browser starts a new one. The target and the login session are
 * keyed fingerprints (ContextHasher), never a URL or a session token. The user ID identifies who ran the check (the
 * same as an analysis's `user_id`); it is used for comparability only and
 * never appears in comparison output.
 */
final class CheckContext {

	/**
	 * Target fingerprint.
	 *
	 * @var string
	 */
	private $target;

	/**
	 * Authentication context.
	 *
	 * @var string
	 */
	private $auth;

	/**
	 * User ID.
	 *
	 * @var int
	 */
	private $user;

	/**
	 * Login-session fingerprint.
	 *
	 * @var string
	 */
	private $session;

	/**
	 * Constructor.
	 *
	 * @param string $target  Target fingerprint (64 lowercase hex).
	 * @param string $auth    CheckDefinition::AUTH_ANONYMOUS or AUTH_USER.
	 * @param int    $user    User ID (positive).
	 * @param string $session Login-session fingerprint (64 lowercase hex).
	 * @throws InvalidArgumentException If a value is invalid.
	 */
	public function __construct( $target, $auth, $user, $session ) {
		if ( ! self::is_fingerprint( $target ) || ! self::is_fingerprint( $session ) ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check context.' );
		}
		if ( ! in_array( $auth, array( CheckDefinition::AUTH_ANONYMOUS, CheckDefinition::AUTH_USER ), true ) || ! is_int( $user ) || $user < 1 ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check context.' );
		}

		$this->target  = $target;
		$this->auth    = $auth;
		$this->user    = $user;
		$this->session = $session;
	}

	/**
	 * Whether a value is a 64-character lowercase hex fingerprint.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_fingerprint( $value ) {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/', $value );
	}

	/**
	 * Target fingerprint.
	 *
	 * @return string
	 */
	public function get_target() {
		return $this->target;
	}

	/**
	 * Authentication context.
	 *
	 * @return string
	 */
	public function get_auth() {
		return $this->auth;
	}

	/**
	 * User ID.
	 *
	 * @return int
	 */
	public function get_user() {
		return $this->user;
	}

	/**
	 * Login-session fingerprint.
	 *
	 * @return string
	 */
	public function get_session() {
		return $this->session;
	}
}
