<?php
/**
 * Tests for CaptureTimeBudget.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use UpdateLens\Update\CaptureTimeBudget;

/**
 * The time UpdateLens's observations may add to one update request.
 */
final class CaptureTimeBudgetTest extends TestCase {

	/**
	 * The cap: a share of `max_execution_time` when it is set, never above the ceiling.
	 *
	 * @return array<string, array{mixed, float}>
	 */
	public function provide_caps() {
		return array(
			'not set (0)'        => array( '0', CaptureTimeBudget::CEILING_SECONDS ),
			'empty'              => array( '', CaptureTimeBudget::CEILING_SECONDS ),
			'unreadable (false)' => array( false, CaptureTimeBudget::CEILING_SECONDS ),
			'negative'           => array( '-1', CaptureTimeBudget::CEILING_SECONDS ),
			'not a number'       => array( 'abc', CaptureTimeBudget::CEILING_SECONDS ),
			'30 s (php.ini)'     => array( '30', 7.5 ),
			'20 s'               => array( 20, 5.0 ),
			'300 s'              => array( '300', CaptureTimeBudget::CEILING_SECONDS ),
		);
	}

	/**
	 * Caps.
	 *
	 * @dataProvider provide_caps
	 *
	 * @param mixed $setting  `max_execution_time` as read.
	 * @param float $expected Cap.
	 */
	public function test_cap_for( $setting, $expected ) {
		$this->assertSame( $expected, CaptureTimeBudget::cap_for( $setting ) );
	}

	/**
	 * The next observations fit while the time spent plus that many of the
	 * longest one so far stays within the cap; nothing measured yet fits.
	 */
	public function test_allows() {
		$now    = 100.0;
		$budget = new CaptureTimeBudget(
			10.0,
			static function () use ( &$now ) {
				return $now;
			}
		);
		$this->assertTrue( $budget->allows( 2 ), 'Nothing measured yet.' );

		$started = $budget->start();
		$now    += 3.0;
		$this->assertSame( 3.0, $budget->stop( $started ) );
		$this->assertTrue( $budget->allows( 2 ), '3 + 2 × 3 = 9.' );
		$this->assertFalse( $budget->allows( 3 ), '3 + 3 × 3 = 12.' );

		$started = $budget->start();
		$now    += 1.0;
		$budget->stop( $started );
		$this->assertSame( 4.0, $budget->spent() );
		$this->assertTrue( $budget->allows( 2 ), '4 + 2 × 3 (the longest) = 10: within the cap.' );
		$this->assertFalse( $budget->allows( 3 ) );
		$this->assertTrue( $budget->allows( 0 ) );
	}

	/**
	 * A clock that goes backwards never gives time back.
	 */
	public function test_clock_going_backwards() {
		$now     = 50.0;
		$budget  = new CaptureTimeBudget(
			1.0,
			static function () use ( &$now ) {
				return $now;
			}
		);
		$started = $budget->start();
		$now    -= 10.0;
		$this->assertSame( 0.0, $budget->stop( $started ) );
		$this->assertSame( 0.0, $budget->spent() );
	}

	/**
	 * Unlimited allows everything; the PHP budget follows the setting.
	 */
	public function test_unlimited_and_from_php() {
		$this->assertTrue( CaptureTimeBudget::unlimited()->allows( PHP_INT_MAX ) );
		$this->assertSame( CaptureTimeBudget::cap_for( ini_get( 'max_execution_time' ) ), CaptureTimeBudget::from_php()->cap() );
	}
}
