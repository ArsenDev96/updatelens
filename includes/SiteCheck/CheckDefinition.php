<?php
/**
 * The two fixed site checks.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Definition of one browser-initiated site check: what it requests, as whom,
 * and how often it may try. There are exactly two, fixed in code:
 *
 * - `home`: the homepage (`home_url( '/' )`), requested anonymously
 *   (credentials omitted, redirects not followed, no cache). It passes only
 *   when WordPress is verified as the origin and the end-of-page marker
 *   printed in `wp_footer` is present: an HTTP 200 alone never shows that the
 *   page finished rendering.
 * - `rest`: `wp/v2/users/me?_fields=id`, requested as the signed-in
 *   administrator (cookie + REST nonce). It passes only when WordPress is
 *   verified as the origin and the response is JSON naming that same user.
 *
 * The version identifies the request and the classification rules. Bump it
 * whenever either changes: results of different versions are never compared.
 * The authentication context is part of the definition, so results of one
 * definition version always share it.
 */
final class CheckDefinition {

	/**
	 * Check identifiers.
	 */
	const HOME = 'home';
	const REST = 'rest';

	/**
	 * Authentication contexts.
	 */
	const AUTH_ANONYMOUS = 'anonymous';
	const AUTH_USER      = 'user';

	/**
	 * Current version of each definition.
	 */
	const VERSIONS = array(
		self::HOME => 1,
		self::REST => 1,
	);

	/**
	 * REST route of the `rest` check and the only field it requests.
	 */
	const REST_ROUTE  = '/wp/v2/users/me';
	const REST_FIELDS = 'id';

	/**
	 * Attempts per check run: a failure counts only when a second attempt
	 * confirms it.
	 */
	const MAX_ATTEMPTS = 2;

	/**
	 * Per-attempt timeout the browser applies, in milliseconds.
	 */
	const TIMEOUT_MS = 10000;

	/**
	 * Delay before the confirming attempt, in milliseconds.
	 */
	const RETRY_DELAY_MS = 5000;

	/**
	 * Check identifier.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Definition version.
	 *
	 * @var int
	 */
	private $version;

	/**
	 * Authentication context.
	 *
	 * @var string
	 */
	private $auth;

	/**
	 * Constructor.
	 *
	 * @param string $id      Check identifier.
	 * @param int    $version Definition version.
	 * @param string $auth    Authentication context.
	 */
	private function __construct( $id, $version, $auth ) {
		$this->id      = $id;
		$this->version = $version;
		$this->auth    = $auth;
	}

	/**
	 * Both checks, in their fixed order (home, rest).
	 *
	 * @return CheckDefinition[]
	 */
	public static function all() {
		return array( self::home(), self::rest() );
	}

	/**
	 * The anonymous homepage check.
	 *
	 * @return self
	 */
	public static function home() {
		return new self( self::HOME, self::VERSIONS[ self::HOME ], self::AUTH_ANONYMOUS );
	}

	/**
	 * The authenticated REST API check.
	 *
	 * @return self
	 */
	public static function rest() {
		return new self( self::REST, self::VERSIONS[ self::REST ], self::AUTH_USER );
	}

	/**
	 * The current definition of a check.
	 *
	 * @param string $id Check identifier.
	 * @return self
	 * @throws InvalidArgumentException If the identifier is unknown.
	 */
	public static function get( $id ) {
		if ( self::HOME === $id ) {
			return self::home();
		}
		if ( self::REST === $id ) {
			return self::rest();
		}
		throw new InvalidArgumentException( 'Unknown UpdateLens site check.' );
	}

	/**
	 * Whether a value is a check identifier.
	 *
	 * @param mixed $id Value.
	 * @return bool
	 */
	public static function is_id( $id ) {
		return self::HOME === $id || self::REST === $id;
	}

	/**
	 * Check identifier.
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Definition version.
	 *
	 * @return int
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Authentication context (`anonymous` or `user`).
	 *
	 * @return string
	 */
	public function get_auth() {
		return $this->auth;
	}
}
