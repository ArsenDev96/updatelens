<?php
/**
 * PHP memory available to an observation.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Whether an allocation fits in the request's PHP memory limit with a
 * safety reserve to spare.
 *
 * Running out of memory is a fatal error that cannot be caught: it would end
 * the plugin update request (leaving WordPress in maintenance mode). So
 * observations ask before every large allocation and stop safely instead.
 * Usage is the memory PHP has reserved from the system
 * (`memory_get_usage( true )`), which is what the limit is checked against.
 *
 * The reserve (RESERVE_BYTES or RESERVE_RATIO of the limit, whichever is
 * larger) covers estimation error and allocator granularity: PHP reserves
 * memory in 2 MiB chunks, and estimates are averages measured on real
 * requests.
 *
 * Without a PHP memory limit (`-1`) observations are still bounded
 * (effective_limit()): by WP_MAX_MEMORY_LIMIT, WordPress's ceiling for
 * admin requests, if it is finite, else by UNLIMITED_FALLBACK_BYTES. A
 * request without a limit could otherwise allocate gigabytes, and its
 * snapshots would no longer fit in a database statement. Usage counts
 * against that ceiling like against a real limit. A lower limit outside PHP
 * (a container or process memory cap) cannot be seen from here: the budget
 * guarantees nothing beyond what PHP reports.
 */
final class MemoryBudget {

	/**
	 * Smallest reserve kept free, in bytes.
	 */
	const RESERVE_BYTES = 16777216;

	/**
	 * Ceiling when PHP has no memory limit and WP_MAX_MEMORY_LIMIT is not
	 * finite either, in bytes: WordPress's own default for WP_MAX_MEMORY_LIMIT
	 * (256 MiB).
	 */
	const UNLIMITED_FALLBACK_BYTES = 268435456;

	/**
	 * Reserve kept free as a share of the limit, if larger.
	 */
	const RESERVE_RATIO = 0.1;

	/**
	 * Memory limit in bytes; 0 when unlimited.
	 *
	 * @var int
	 */
	private $limit;

	/**
	 * Returns the current usage in bytes.
	 *
	 * @var callable
	 */
	private $usage;

	/**
	 * Constructor.
	 *
	 * @param int      $limit Memory limit in bytes; 0 or less when unlimited.
	 * @param callable $usage Returns the current usage in bytes.
	 */
	public function __construct( $limit, callable $usage ) {
		$this->limit = max( 0, (int) $limit );
		$this->usage = $usage;
	}

	/**
	 * Budget of the running request.
	 *
	 * @return self
	 */
	public static function from_php() {
		return new self(
			self::effective_limit( ini_get( 'memory_limit' ), defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : null ),
			static function () {
				return memory_get_usage( true );
			}
		);
	}

	/**
	 * The limit observations are budgeted against: PHP's `memory_limit`;
	 * without one (or an unreadable one), WP_MAX_MEMORY_LIMIT if it is a
	 * finite size, else UNLIMITED_FALLBACK_BYTES. Never unlimited.
	 *
	 * @param mixed $memory_limit        PHP's `memory_limit` ini value.
	 * @param mixed $wp_max_memory_limit WP_MAX_MEMORY_LIMIT, or null when undefined.
	 * @return int Bytes.
	 */
	public static function effective_limit( $memory_limit, $wp_max_memory_limit ) {
		$limit = self::bytes( $memory_limit );
		if ( $limit > 0 ) {
			return $limit;
		}
		$ceiling = self::bytes( $wp_max_memory_limit );

		return $ceiling > 0 ? $ceiling : self::UNLIMITED_FALLBACK_BYTES;
	}

	/**
	 * A `memory_limit` value in bytes (`128M`, `1G`, `-1`), like PHP reads it.
	 *
	 * @param mixed $value Ini value.
	 * @return int Bytes; 0 when unlimited or unreadable.
	 */
	public static function bytes( $value ) {
		if ( ! is_scalar( $value ) ) {
			return 0;
		}
		$value = trim( (string) $value );
		if ( 1 !== preg_match( '/^(\d+)\s*([kmg]?)$/i', $value, $matches ) ) {
			return 0;
		}
		$bytes = (int) $matches[1];
		switch ( strtolower( $matches[2] ) ) {
			case 'g':
				$bytes *= 1024;
				// Fall through.
			case 'm':
				$bytes *= 1024;
				// Fall through.
			case 'k':
				$bytes *= 1024;
		}

		return $bytes;
	}

	/**
	 * Whether the request has a memory limit.
	 *
	 * @return bool
	 */
	public function is_limited() {
		return $this->limit > 0;
	}

	/**
	 * Whether $bytes more can be allocated with the reserve to spare.
	 *
	 * @param int|float $bytes Bytes needed.
	 * @return bool
	 */
	public function allows( $bytes ) {
		if ( ! $this->is_limited() ) {
			return true;
		}

		return (float) call_user_func( $this->usage ) + max( 0, (float) $bytes ) + $this->reserve() <= $this->limit;
	}

	/**
	 * Throw unless $bytes more can be allocated with the reserve to spare.
	 *
	 * @param int|float $bytes Bytes needed.
	 * @return void
	 * @throws InsufficientMemoryException If they cannot.
	 */
	public function ensure( $bytes ) {
		if ( ! $this->allows( $bytes ) ) {
			throw new InsufficientMemoryException();
		}
	}

	/**
	 * Bytes kept free.
	 *
	 * @return float
	 */
	private function reserve() {
		return max( (float) self::RESERVE_BYTES, $this->limit * self::RESERVE_RATIO );
	}
}
