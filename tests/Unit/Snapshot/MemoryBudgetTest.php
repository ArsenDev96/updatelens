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
		$this->assertSame( 0, MemoryBudget::bytes( array( '128M' ) ) );
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
	 * An unlimited budget (only ever constructed explicitly) allows everything.
	 */
	public function test_unlimited() {
		$budget = self::budget( 0, PHP_INT_MAX );

		$this->assertFalse( $budget->is_limited() );
		$this->assertTrue( $budget->allows( PHP_INT_MAX ) );
		$budget->ensure( PHP_INT_MAX );
	}

	/**
	 * A finite PHP limit is used as it is, whatever WP_MAX_MEMORY_LIMIT says.
	 */
	public function test_effective_limit_of_a_finite_php_limit() {
		$this->assertSame( 128 * self::MIB, MemoryBudget::effective_limit( '128M', '256M' ) );
		$this->assertSame( 128 * self::MIB, MemoryBudget::effective_limit( '128M', null ) );
		$this->assertSame( 2048 * self::MIB, MemoryBudget::effective_limit( '2G', '256M' ) );
		$this->assertSame( 64 * self::MIB, MemoryBudget::effective_limit( '64M', '-1' ) );
	}

	/**
	 * Without a PHP limit, a finite WP_MAX_MEMORY_LIMIT is the ceiling; an
	 * undefined, invalid or unlimited one gives the 256 MiB fallback. An
	 * unreadable PHP limit is treated like none. Never unlimited.
	 */
	public function test_effective_limit_without_a_php_limit() {
		$this->assertSame( 256 * self::MIB, MemoryBudget::UNLIMITED_FALLBACK_BYTES );

		$this->assertSame( 512 * self::MIB, MemoryBudget::effective_limit( '-1', '512M' ) );
		$this->assertSame( 256 * self::MIB, MemoryBudget::effective_limit( '-1', '256M' ) );
		$this->assertSame( 96 * self::MIB, MemoryBudget::effective_limit( '-1', '96M' ) );
		$this->assertSame( 134217728, MemoryBudget::effective_limit( '-1', 134217728 ), 'An integer constant.' );

		foreach ( array( null, '-1', -1, '', 'lots', '0', '1.5G', false, array() ) as $i => $wp_max ) {
			$this->assertSame( MemoryBudget::UNLIMITED_FALLBACK_BYTES, MemoryBudget::effective_limit( '-1', $wp_max ), "WP_MAX_MEMORY_LIMIT case $i" );
		}
		foreach ( array( '', 'lots', false, '0', '-2' ) as $i => $php ) {
			$this->assertSame( MemoryBudget::UNLIMITED_FALLBACK_BYTES, MemoryBudget::effective_limit( $php, null ), "memory_limit case $i" );
		}
	}

	/**
	 * The fallback ceiling counts the request's own usage and keeps the
	 * reserve, like a real limit: a request that already uses 143 MiB has
	 * about 87 MiB left, not gigabytes.
	 */
	public function test_ceiling_counts_the_usage() {
		$budget = self::budget( MemoryBudget::effective_limit( '-1', null ), 143 * self::MIB );
		$left   = 256 * self::MIB - 143 * self::MIB - 0.1 * 256 * self::MIB;

		$this->assertTrue( $budget->is_limited() );
		$this->assertTrue( $budget->allows( $left ) );
		$this->assertFalse( $budget->allows( $left + 1 ) );
		$this->assertFalse( $budget->allows( 1024 * self::MIB ) );
		$this->assertFalse( self::budget( MemoryBudget::effective_limit( '-1', null ), 300 * self::MIB )->allows( 0 ), 'Usage already past the ceiling.' );
	}

	/**
	 * The running request's budget reads its limit; without one it is still
	 * limited (WP_MAX_MEMORY_LIMIT is not defined in unit tests: the fallback).
	 */
	public function test_from_php() {
		$previous = ini_get( 'memory_limit' );
		ini_set( 'memory_limit', '-1' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test.
		$this->assertFalse( defined( 'WP_MAX_MEMORY_LIMIT' ) );
		$this->assertTrue( MemoryBudget::from_php()->is_limited() );
		$this->assertTrue( MemoryBudget::from_php()->allows( self::MIB ) );
		$this->assertFalse( MemoryBudget::from_php()->allows( MemoryBudget::UNLIMITED_FALLBACK_BYTES ) );
		ini_set( 'memory_limit', '2048M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test.
		$this->assertTrue( MemoryBudget::from_php()->is_limited() );
		$this->assertTrue( MemoryBudget::from_php()->allows( 1024 * self::MIB ) );
		ini_set( 'memory_limit', $previous ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test.
	}
}
