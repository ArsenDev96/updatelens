<?php
/**
 * Tests for MemoryBudget.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Snapshot;

use PHPUnit\Framework\TestCase;
use UpdateLens\Snapshot\InsufficientMemoryException;
use UpdateLens\Snapshot\MemoryBudget;

/**
 * Limits, the reserve and the boundaries.
 */
final class MemoryBudgetTest extends TestCase {

	const MIB = 1048576;

	/**
	 * Budget with a fixed usage.
	 *
	 * @param int $limit Limit in bytes.
	 * @param int $usage Usage in bytes.
	 * @return MemoryBudget
	 */
	private static function budget( $limit, $usage ) {
		return new MemoryBudget(
			$limit,
			static function () use ( $usage ) {
				return $usage;
			}
		);
	}

	/**
	 * Memory limit values as PHP reads them.
	 */
	public function test_bytes() {
		$this->assertSame( 128 * self::MIB, MemoryBudget::bytes( '128M' ) );
		$this->assertSame( 128 * self::MIB, MemoryBudget::bytes( '128m' ) );
		$this->assertSame( 1024 * self::MIB, MemoryBudget::bytes( '1G' ) );
		$this->assertSame( 512 * 1024, MemoryBudget::bytes( '512K' ) );
		$this->assertSame( 134217728, MemoryBudget::bytes( '134217728' ) );
		$this->assertSame( 0, MemoryBudget::bytes( '-1' ), 'Unlimited.' );
		$this->assertSame( 0, MemoryBudget::bytes( '' ) );
		$this->assertSame( 0, MemoryBudget::bytes( 'lots' ) );
		$this->assertSame( 0, MemoryBudget::bytes( false ) );
	}

	/**
	 * The reserve is 16 MiB or a tenth of the limit, whichever is larger;
	 * the boundary is inclusive.
	 */
	public function test_reserve_and_boundary() {
		// 128 MiB: reserve 16 MiB (more than 12.8 MiB).
		$budget = self::budget( 128 * self::MIB, 40 * self::MIB );
		$this->assertTrue( $budget->allows( 72 * self::MIB ) );
		$this->assertFalse( $budget->allows( 72 * self::MIB + 1 ) );

		// 512 MiB: reserve 51.2 MiB.
		$budget = self::budget( 512 * self::MIB, 40 * self::MIB );
		$this->assertTrue( $budget->allows( 512 * self::MIB - 40 * self::MIB - 0.1 * 512 * self::MIB ) );
		$this->assertFalse( $budget->allows( 512 * self::MIB - 40 * self::MIB - 0.1 * 512 * self::MIB + 1 ) );
	}

	/**
	 * Usage already past the limit allows nothing.
	 */
	public function test_nothing_left() {
		$budget = self::budget( 64 * self::MIB, 60 * self::MIB );

		$this->assertFalse( $budget->allows( 0 ) );
		$this->expectException( InsufficientMemoryException::class );
		$budget->ensure( 1 );
	}

	/**
	 * Without a limit everything is allowed.
	 */
	public function test_unlimited() {
		$budget = self::budget( 0, PHP_INT_MAX );

		$this->assertFalse( $budget->is_limited() );
		$this->assertTrue( $budget->allows( PHP_INT_MAX ) );
		$budget->ensure( PHP_INT_MAX );
	}

	/**
	 * The running request's budget reads its limit.
	 */
	public function test_from_php() {
		$previous = ini_get( 'memory_limit' );
		ini_set( 'memory_limit', '-1' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test.
		$this->assertFalse( MemoryBudget::from_php()->is_limited() );
		ini_set( 'memory_limit', '2048M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test.
		$this->assertTrue( MemoryBudget::from_php()->is_limited() );
		$this->assertTrue( MemoryBudget::from_php()->allows( self::MIB ) );
		ini_set( 'memory_limit', $previous ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test.
	}
}
