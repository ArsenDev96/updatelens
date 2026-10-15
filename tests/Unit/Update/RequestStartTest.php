<?php
/**
 * Tests for the request start used by the settle rules.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use UpdateLens\Update\RequestStart;

/**
 * RequestStart: WordPress's `$timestart`, validated, captured once.
 */
final class RequestStartTest extends TestCase {

	const NOW = 1791660985.925354;

	/**
	 * `$timestart` before the test.
	 *
	 * @var mixed
	 */
	private $saved;

	/**
	 * Forget a capture from an earlier test and keep `$timestart`.
	 *
	 * @before
	 */
	public function reset_capture() {
		$this->saved = array_key_exists( 'timestart', $GLOBALS ) ? $GLOBALS['timestart'] : null;
		$this->set_captured( false );
	}

	/**
	 * Restore `$timestart` and forget the capture.
	 *
	 * @after
	 */
	public function restore() {
		$GLOBALS['timestart'] = $this->saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture.
		$this->set_captured( false );
	}

	/**
	 * Set the captured value.
	 *
	 * @param float|null|false $value Captured start, unknown or not captured.
	 * @return void
	 */
	private function set_captured( $value ) {
		$property = new ReflectionProperty( RequestStart::class, 'start' );
		// Needed before PHP 8.1 only; deprecated since PHP 8.5.
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, $value );
	}

	/**
	 * A `$timestart` from timer_start() (`microtime( true )`) is the start.
	 */
	public function test_valid_timestart() {
		$this->assertSame( 1791660985.612818, RequestStart::from( 1791660985.612818, self::NOW ) );
		$this->assertSame( self::NOW, RequestStart::from( self::NOW, self::NOW ), 'Read in the same microsecond.' );
	}

	/**
	 * Missing, malformed or impossible values are not trusted.
	 *
	 * @dataProvider untrusted
	 *
	 * @param mixed $timestart `$timestart`.
	 */
	public function test_untrusted_timestart( $timestart ) {
		$this->assertNull( RequestStart::from( $timestart, self::NOW ) );
	}

	/**
	 * Values that are not this request's start.
	 *
	 * @return array
	 */
	public function untrusted() {
		return array(
			'missing'           => array( null ),
			'numeric string'    => array( '1791660985.612818' ),
			'integer'           => array( 1791660985 ),
			'boolean'           => array( true ),
			'array'             => array( array( 1791660985.6 ) ),
			'zero'              => array( 0.0 ),
			'negative'          => array( -1.5 ),
			'infinite'          => array( INF ),
			'not a number'      => array( NAN ),
			'in the future'     => array( self::NOW + 0.000002 ),
			'far in the future' => array( self::NOW + 3600 ),
		);
	}

	/**
	 * The start is read once; later changes (another timer_start() call) are ignored.
	 */
	public function test_captured_once() {
		$this->assertNull( RequestStart::get(), 'Not captured yet: unknown.' );

		$start                = microtime( true ) - 0.25;
		$GLOBALS['timestart'] = $start; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture.
		RequestStart::capture();
		$this->assertSame( $start, RequestStart::get() );

		$GLOBALS['timestart'] = microtime( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture.
		RequestStart::capture();
		$this->assertSame( $start, RequestStart::get(), 'Moving $timestart later has no effect.' );
	}

	/**
	 * Without a usable `$timestart` the start stays unknown.
	 */
	public function test_capture_without_timestart() {
		unset( $GLOBALS['timestart'] );
		RequestStart::capture();
		$this->assertNull( RequestStart::get() );

		$this->set_captured( false );
		$GLOBALS['timestart'] = 'now'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture.
		RequestStart::capture();
		$this->assertNull( RequestStart::get() );

		$GLOBALS['timestart'] = microtime( true ) - 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture.
		RequestStart::capture();
		$this->assertNull( RequestStart::get(), 'Captured once, also when unknown.' );
	}
}
