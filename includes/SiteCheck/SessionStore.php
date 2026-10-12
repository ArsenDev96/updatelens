<?php
/**
 * Storage of site check sessions.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Short-lived key/value storage for encoded sessions (TransientSessionStore
 * in WordPress). Entries may disappear at any time (expiry, eviction):
 * a missing entry always means "no session".
 */
interface SessionStore {

	/**
	 * Stored value.
	 *
	 * @param string $key Key.
	 * @return string|null Null when missing.
	 */
	public function get( $key );

	/**
	 * Store a value for at most $ttl seconds.
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return bool Whether it was stored.
	 */
	public function put( $key, $value, $ttl );

	/**
	 * Use a value up and remove it; true only for the one caller that
	 * claimed it, ever, so concurrent and repeated claims of one entry have
	 * a single winner. A failed claim is false.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function claim( $key );
}
