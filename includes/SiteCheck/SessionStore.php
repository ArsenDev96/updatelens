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
	 * Remove a value; true only for the one caller that removed it, so
	 * concurrent claims of one entry have a single winner.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function claim( $key );
}
