<?php
/**
 * Tests for ObservationBudget.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\Snapshot\MemoryBudget;
use UpdateLens\Update\ObservationBudget;

/**
 * The preflight estimate per stage, from the number of options.
 */
final class ObservationBudgetTest extends TestCase {

	const MIB = 1048576;

	/**
	 * Number of times the options were counted.
	 *
	 * @var int
	 */
	private $counts = 0;

	/**
	 * Budget for a request using $usage of $limit with $options options.
	 *
	 * @param int      $limit   Limit in bytes (0: unlimited).
	 * @param int      $usage   Usage in bytes.
	 * @param int|null $options Options, or null when counting fails.
	 * @return ObservationBudget
	 */
	private function budget( $limit, $usage, $options ) {
		return new ObservationBudget(
			new MemoryBudget(
				$limit,
				static function () use ( $usage ) {
					return $usage;
				}
			),
			function () use ( $options ) {
				++$this->counts;
				if ( null === $options ) {
					throw new RuntimeException( 'count failed' );
				}
				return $options;
			}
		);
	}

	/**
	 * The largest options count that fits a stage at 128 MiB with 40 MiB used.
	 *
	 * @param string $stage Stage.
	 * @return int
	 */
	private static function largest_fitting( $stage ) {
		$free = 128 * self::MIB - 40 * self::MIB - MemoryBudget::RESERVE_BYTES - ObservationBudget::CAPTURE_BYTES;

		return (int) floor( $free / ObservationBudget::BYTES_PER_OPTION[ $stage ] );
	}

	/**
	 * Each stage fits up to its estimate and not one option more.
	 */
	public function test_stage_boundaries() {
		foreach ( array( ObservationBudget::IMMEDIATE, ObservationBudget::SETTLED ) as $stage ) {
			$fitting = self::largest_fitting( $stage );
			$this->assertTrue( $this->budget( 128 * self::MIB, 40 * self::MIB, $fitting )->fits( $stage ), $stage );
			$this->assertFalse( $this->budget( 128 * self::MIB, 40 * self::MIB, $fitting + 1 )->fits( $stage ), $stage );
		}
	}

	/**
	 * BEFORE begins an observation only if its most expensive stage fits too.
	 */
	public function test_before_needs_the_whole_observation() {
		$settled = self::largest_fitting( ObservationBudget::SETTLED );

		$this->assertTrue( $this->budget( 128 * self::MIB, 40 * self::MIB, $settled )->fits( ObservationBudget::BEFORE ) );
		$this->assertFalse( $this->budget( 128 * self::MIB, 40 * self::MIB, $settled + 1 )->fits( ObservationBudget::BEFORE ) );
		$this->assertGreaterThan( $settled + 1, self::largest_fitting( ObservationBudget::BEFORE ), 'BEFORE alone would have fitted.' );
	}

	/**
	 * Measured sizes: an ordinary site fits at 128 MiB; 50,000 options do not
	 * (they would need about 130 MiB in SETTLED), but fit at 256 MiB.
	 */
	public function test_realistic_sites() {
		$this->assertTrue( $this->budget( 128 * self::MIB, 42 * self::MIB, 400 )->fits( ObservationBudget::BEFORE ) );
		$this->assertTrue( $this->budget( 128 * self::MIB, 42 * self::MIB, 10000 )->fits( ObservationBudget::BEFORE ) );
		$this->assertFalse( $this->budget( 128 * self::MIB, 42 * self::MIB, 50000 )->fits( ObservationBudget::BEFORE ) );
		$this->assertTrue( $this->budget( 256 * self::MIB, 42 * self::MIB, 50000 )->fits( ObservationBudget::BEFORE ) );
	}

	/**
	 * Unknown option counts never fit; unlimited memory never counts.
	 */
	public function test_unknown_count_and_unlimited_memory() {
		$this->assertFalse( $this->budget( 512 * self::MIB, 40 * self::MIB, null )->fits( ObservationBudget::SETTLED ) );

		$this->counts = 0;
		$this->assertTrue( $this->budget( 0, 40 * self::MIB, null )->fits( ObservationBudget::SETTLED ) );
		$this->assertSame( 0, $this->counts );
	}
}
