<?php
/**
 * Session storage for tests.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Support;

use UpdateLens\SiteCheck\SessionStore;

/**
 * In-memory SessionStore with switches for failures and races.
 */
final class InMemorySessionStore implements SessionStore {

	/**
	 * Values by key.
	 *
	 * @var array<string, string>
	 */
	public $values = array();

	/**
	 * Lifetimes by key.
	 *
	 * @var array<string, int>
	 */
	public $ttls = array();

	/**
	 * Number of get() calls.
	 *
	 * @var int
	 */
	public $reads = 0;

	/**
	 * Number of successful put() calls before puts fail (null = never fail).
	 *
	 * @var int|null
	 */
	public $puts_before_failure = null;

	/**
	 * Called once at the next claim, before it takes effect (simulates a
	 * concurrent request that claims first): function (): void.
	 *
	 * @var callable|null
	 */
	public $before_claim = null;

	/**
	 * Stored value.
	 *
	 * @param string $key Key.
	 * @return string|null
	 */
	public function get( $key ) {
		++$this->reads;

		return isset( $this->values[ $key ] ) ? $this->values[ $key ] : null;
	}

	/**
	 * Store a value.
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @param int    $ttl   Lifetime.
	 * @return bool
	 */
	public function put( $key, $value, $ttl ) {
		if ( null !== $this->puts_before_failure ) {
			if ( $this->puts_before_failure <= 0 ) {
				return false;
			}
			--$this->puts_before_failure;
		}
		$this->values[ $key ] = $value;
		$this->ttls[ $key ]   = $ttl;

		return true;
	}

	/**
	 * Remove a value; true only if it existed.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function claim( $key ) {
		if ( null !== $this->before_claim ) {
			$race               = $this->before_claim;
			$this->before_claim = null;
			$race();
		}
		if ( ! isset( $this->values[ $key ] ) ) {
			return false;
		}
		unset( $this->values[ $key ] );

		return true;
	}

	/**
	 * Drop every value (eviction).
	 *
	 * @return void
	 */
	public function evict_all() {
		$this->values = array();
	}
}
