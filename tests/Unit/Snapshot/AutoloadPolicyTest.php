<?php
/**
 * Tests for AutoloadPolicy.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Snapshot;

use PHPUnit\Framework\TestCase;
use UpdateLens\Snapshot\AutoloadPolicy;

/**
 * Raw `autoload` value interpretation.
 */
final class AutoloadPolicyTest extends TestCase {

	/**
	 * WordPress 6.6+ default autoloaded values (wp_autoload_values_to_autoload()).
	 */
	const MODERN_VALUES = array( 'yes', 'on', 'auto-on', 'auto' );

	/**
	 * Raw values under the modern default list.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function provide_modern_values() {
		return array(
			'yes'      => array( 'yes', true ),
			'on'       => array( 'on', true ),
			'auto-on'  => array( 'auto-on', true ),
			'auto'     => array( 'auto', true ),
			'no'       => array( 'no', false ),
			'off'      => array( 'off', false ),
			'auto-off' => array( 'auto-off', false ),
			'empty'    => array( '', false ),
			'unknown'  => array( 'sometimes', false ),
		);
	}

	/**
	 * Modern values map like WordPress core.
	 *
	 * @dataProvider provide_modern_values
	 *
	 * @param string $autoload Raw value.
	 * @param bool   $expected Expected result.
	 */
	public function test_modern_values( $autoload, $expected ) {
		$policy = new AutoloadPolicy( self::MODERN_VALUES );

		$this->assertSame( $expected, $policy->is_autoloaded( $autoload ) );
	}

	/**
	 * Without wp_autoload_values_to_autoload() (WordPress < 6.6) only `yes` is autoloaded.
	 */
	public function test_legacy_fallback_without_core_helper() {
		$this->assertFalse( function_exists( 'wp_autoload_values_to_autoload' ) );

		$policy = AutoloadPolicy::from_wordpress();

		$this->assertTrue( $policy->is_autoloaded( 'yes' ) );
		$this->assertFalse( $policy->is_autoloaded( 'no' ) );
		$this->assertFalse( $policy->is_autoloaded( 'on' ) );
		$this->assertFalse( $policy->is_autoloaded( 'auto' ) );
	}

	/**
	 * With wp_autoload_values_to_autoload() (WordPress 6.6+) its (filtered) result is used.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uses_core_helper_when_available() {
		require dirname( __DIR__, 2 ) . '/fixtures/wp-autoload-values.php';

		$policy = AutoloadPolicy::from_wordpress();

		$this->assertTrue( $policy->is_autoloaded( 'yes' ) );
		$this->assertTrue( $policy->is_autoloaded( 'on' ) );
		$this->assertTrue( $policy->is_autoloaded( 'auto-on' ) );
		$this->assertFalse( $policy->is_autoloaded( 'auto' ), 'Filtered out by the stub, so must not be autoloaded.' );
		$this->assertFalse( $policy->is_autoloaded( 'off' ) );
	}
}
