<?php
/**
 * Baseline storage for tests.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Support;

use UnexpectedValueException;
use UpdateLens\SiteCheck\BaselineStore;

/**
 * Atomic in-memory BaselineStore with hooks that simulate concurrent writers
 * and storage failures.
 */
final class InMemoryBaselineStore implements BaselineStore {

	/**
	 * Values by key.
	 *
	 * @var array<string, string>
	 */
	public $values = array();

	/**
	 * Called before each conditional write (add, replace) with the key: lets
	 * a test write as "another request" between a read and a write.
	 *
	 * @var callable|null
	 */
	public $before_write = null;

	/**
	 * Whether reads fail.
	 *
	 * @var bool
	 */
	public $fail_reads = false;

	/**
	 * Whether writes fail (return false without storing).
	 *
	 * @var bool
	 */
	public $fail_writes = false;

	/**
	 * Successful writes (add, replace) in order, as [ operation, key ].
	 *
	 * @var array<int, array{string, string}>
	 */
	public $writes = array();

	/**
	 * The stored value of a key.
	 *
	 * @param string $key Storage key.
	 * @return string|null
	 * @throws UnexpectedValueException If reads fail.
	 */
	public function get( $key ) {
		if ( $this->fail_reads ) {
			throw new UnexpectedValueException( 'Read failed.' );
		}

		return isset( $this->values[ $key ] ) ? $this->values[ $key ] : null;
	}

	/**
	 * Store a value only if the key has none.
	 *
	 * @param string $key   Storage key.
	 * @param string $value Value.
	 * @return bool
	 */
	public function add( $key, $value ) {
		$this->interleave( $key );
		if ( $this->fail_writes || isset( $this->values[ $key ] ) ) {
			return false;
		}
		$this->values[ $key ] = $value;
		$this->writes[]       = array( 'add', $key );

		return true;
	}

	/**
	 * Replace a value only if it is still exactly $expected.
	 *
	 * @param string $key      Storage key.
	 * @param string $expected Value read before.
	 * @param string $value    New value.
	 * @return bool
	 */
	public function replace( $key, $expected, $value ) {
		$this->interleave( $key );
		if ( $this->fail_writes || ! isset( $this->values[ $key ] ) || $this->values[ $key ] !== $expected ) {
			return false;
		}
		$this->values[ $key ] = $value;
		$this->writes[]       = array( 'replace', $key );

		return true;
	}

	/**
	 * Remove a value only if it is still exactly $expected.
	 *
	 * @param string $key      Storage key.
	 * @param string $expected Value read before.
	 * @return bool
	 */
	public function remove( $key, $expected ) {
		if ( ! isset( $this->values[ $key ] ) || $this->values[ $key ] !== $expected ) {
			return false;
		}
		unset( $this->values[ $key ] );

		return true;
	}

	/**
	 * Every stored value, at most $limit.
	 *
	 * @param int $limit Most entries.
	 * @return array<string, string>
	 * @throws UnexpectedValueException If reads fail.
	 */
	public function all( $limit ) {
		if ( $this->fail_reads ) {
			throw new UnexpectedValueException( 'Read failed.' );
		}

		return array_slice( $this->values, 0, $limit, true );
	}

	/**
	 * Run the concurrent writer once.
	 *
	 * @param string $key Key about to be written.
	 * @return void
	 */
	private function interleave( $key ) {
		if ( null !== $this->before_write ) {
			$writer             = $this->before_write;
			$this->before_write = null;
			$writer( $key );
		}
	}
}
