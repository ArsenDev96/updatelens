<?php
/**
 * Storage of BEFORE baselines.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic key-value storage for baselines (Baselines). Every write is
 * conditional, so concurrent submissions never overwrite each other blindly:
 * add() only creates, replace() and remove() only act on the value the
 * caller read.
 */
interface BaselineStore {

	/**
	 * The stored value of a key.
	 *
	 * @param string $key Storage key.
	 * @return string|null Null if there is none.
	 * @throws UnexpectedValueException If the storage cannot be read.
	 */
	public function get( $key );

	/**
	 * Store a value only if the key has none.
	 *
	 * @param string $key   Storage key.
	 * @param string $value Value.
	 * @return bool Whether it was stored.
	 */
	public function add( $key, $value );

	/**
	 * Replace a value only if it is still exactly $expected.
	 *
	 * @param string $key      Storage key.
	 * @param string $expected Value read before.
	 * @param string $value    New value.
	 * @return bool Whether it was replaced.
	 */
	public function replace( $key, $expected, $value );

	/**
	 * Remove a value only if it is still exactly $expected.
	 *
	 * @param string $key      Storage key.
	 * @param string $expected Value read before.
	 * @return bool Whether it was removed.
	 */
	public function remove( $key, $expected );

	/**
	 * Stored baselines of every user and login session.
	 *
	 * @param int $limit Most entries returned.
	 * @return array<string, string> Values by storage key.
	 * @throws UnexpectedValueException If the storage cannot be read.
	 */
	public function all( $limit );
}
