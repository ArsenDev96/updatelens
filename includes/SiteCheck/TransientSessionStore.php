<?php
/**
 * Site check sessions in WordPress transients.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

defined( 'ABSPATH' ) || exit;

/**
 * SessionStore on the Transients API (database or persistent object cache).
 *
 * Claims use delete_transient(): without an object cache it deletes the
 * option row and reports success only when a row was deleted, so of two
 * concurrent claims exactly one wins. With a persistent object cache the
 * result is the cache's delete result, which the common drop-ins report
 * only for an existing key (not verified for every drop-in).
 */
final class TransientSessionStore implements SessionStore {

	/**
	 * Stored value.
	 *
	 * @param string $key Key.
	 * @return string|null
	 */
	public function get( $key ) {
		$value = get_transient( $key );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Store a value.
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return bool
	 */
	public function put( $key, $value, $ttl ) {
		return set_transient( $key, $value, $ttl );
	}

	/**
	 * Remove a value.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function claim( $key ) {
		return delete_transient( $key );
	}
}
