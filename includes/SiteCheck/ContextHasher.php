<?php
/**
 * Keyed fingerprints of site check targets and login sessions.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use InvalidArgumentException;
use UpdateLens\Snapshot\KeyedHasher;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-SHA256 fingerprints, so results can be matched by target and browser
 * session without storing a URL or a session token. Each domain derives its
 * own key from its own label; the fingerprints reveal neither input and
 * change when the WordPress salts rotate (which also ends every session).
 */
final class ContextHasher {

	/**
	 * Domain label of target fingerprints.
	 */
	const TARGET_KEY_CONTEXT = 'updatelens:site-check-target:v1';

	/**
	 * Domain label of login-session fingerprints.
	 */
	const SESSION_KEY_CONTEXT = 'updatelens:site-check-session:v1';

	/**
	 * Fingerprint scheme identifier.
	 */
	const SCHEME = 'site-check-hmac-sha256-v1';

	/**
	 * Target hasher.
	 *
	 * @var KeyedHasher
	 */
	private $targets;

	/**
	 * Session hasher.
	 *
	 * @var KeyedHasher
	 */
	private $sessions;

	/**
	 * Constructor.
	 *
	 * @param string $secret Site-specific secret.
	 * @throws InvalidArgumentException If the secret is empty.
	 */
	public function __construct( $secret ) {
		$this->targets  = new KeyedHasher( $secret, self::TARGET_KEY_CONTEXT, self::SCHEME );
		$this->sessions = new KeyedHasher( $secret, self::SESSION_KEY_CONTEXT, self::SCHEME );
	}

	/**
	 * Hasher keyed with the site's `auth` salt.
	 *
	 * @return self
	 */
	public static function from_wordpress() {
		return new self( wp_salt( 'auth' ) );
	}

	/**
	 * Fingerprint of a check target URL in canonical form.
	 *
	 * @param string $url Target URL (e.g. `home_url( '/' )`), without the check marker.
	 * @return string 64-character lowercase hex HMAC.
	 * @throws InvalidArgumentException If the URL is not an http(s) URL without credentials.
	 */
	public function target( $url ) {
		$canonical = self::canonical_target( $url );
		if ( null === $canonical ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check target.' );
		}
		return $this->targets->hash( $canonical );
	}

	/**
	 * Fingerprint of a WordPress login session (the session token of the auth
	 * cookie, shared by every tab of that login).
	 *
	 * @param string $token Session token (`wp_get_session_token()`).
	 * @return string 64-character lowercase hex HMAC.
	 * @throws InvalidArgumentException If the token is empty.
	 */
	public function session( $token ) {
		if ( ! is_string( $token ) || '' === $token ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check session.' );
		}
		return $this->sessions->hash( $token );
	}

	/**
	 * Canonical form of a target URL: lowercase scheme and host, default
	 * port omitted, path (`/` when empty) and query kept as given, fragment
	 * dropped. Scheme, host, port, path and query all count: http and https,
	 * or another host, are different targets.
	 *
	 * @param mixed $url URL.
	 * @return string|null Null for anything but an http(s) URL with a host and no credentials.
	 */
	public static function canonical_target( $url ) {
		if ( ! is_string( $url ) || '' === $url || 1 === preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure code, unit-tested without WordPress; PHP 7.4+ parses scheme-relative URLs itself.
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		if ( ( 'http' !== $scheme && 'https' !== $scheme ) || '' === $host ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = null;
		}

		return $scheme . '://' . $host
			. ( null === $port ? '' : ':' . $port )
			. ( isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/' )
			. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/**
	 * Keep the keys out of var_dump()/print_r() output.
	 *
	 * @return array
	 */
	public function __debugInfo() {
		return array( 'algorithm' => KeyedHasher::ALGORITHM );
	}
}
