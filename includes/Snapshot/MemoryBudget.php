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
 */
final class MemoryBudget {

	/**
	 * Smallest reserve kept free, in bytes.
	 */
	const RESERVE_BYTES = 16777216;

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
			self::bytes( ini_get( 'memory_limit' ) ),
			static function () {
				return memory_get_usage( true );
			}
		);
	}

	/**
	 * A `memory_limit` value in bytes (`128M`, `1G`, `-1`), like PHP reads it.
	 *
	 * @param mixed $value Ini value.
	 * @return int Bytes; 0 when unlimited or unreadable.
	 */
	public static function bytes( $value ) {
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
