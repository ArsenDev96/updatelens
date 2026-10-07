<?php
/**
 * Tests for the monitoring baseline lifecycle.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Baseline;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\Baseline\MonitoringBaseline;
use UpdateLens\Baseline\PluginInventory;

/**
 * Created once, never overwritten, never throws.
 */
final class MonitoringBaselineTest extends TestCase {

	/**
	 * 2026-10-07 22:32:00 UTC.
	 */
	const T0 = 1791412320;

	/**
	 * Fake option: stored value, or false when absent.
	 *
	 * @var mixed
	 */
	private $stored;

	/**
	 * Number of add_option() calls.
	 *
	 * @var int
	 */
	private $adds;

	/**
	 * Fake installed plugins (get_plugins() shape), or a Throwable get_plugins() throws.
	 *
	 * @var array|RuntimeException
	 */
	private $plugins;

	/**
	 * Fake active plugins.
	 *
	 * @var string[]
	 */
	private $active;

	/**
	 * Fake clock.
	 *
	 * @var int
	 */
	private $time;

	/**
	 * When true, add_option() throws.
	 *
	 * @var bool
	 */
	private $fail_add;

	/**
	 * Fresh site.
	 *
	 * @before
	 */
	public function reset_state() {
		$this->stored   = false;
		$this->adds     = 0;
		$this->time     = self::T0;
		$this->fail_add = false;
		$this->active   = array( 'woocommerce/woocommerce.php', 'updatelens/updatelens.php' );
		$this->plugins  = array(
			'woocommerce/woocommerce.php'       => array(
				'Name'      => 'WooCommerce',
				'Version'   => '11.1.2',
				'UpdateURI' => 'https://updates.example.test/?license=SECRET',
			),
			'classic-editor/classic-editor.php' => array(
				'Name'    => 'Classic Editor',
				'Version' => '1.7.0',
			),
			'updatelens/updatelens.php'         => array(
				'Name'    => 'UpdateLens',
				'Version' => '0.2.0-beta.2',
			),
		);
	}

	/**
	 * Baseline over the fake option, as a request would create it.
	 *
	 * @return MonitoringBaseline
	 */
	private function baseline() {
		return new MonitoringBaseline(
			function () {
				return $this->stored;
			},
			function ( $value ) {
				++$this->adds;
				if ( $this->fail_add ) {
					throw new RuntimeException( 'add failed' );
				}
				if ( false !== $this->stored ) {
					return false;
				}
				$this->stored = $value;

				return true;
			},
			function () {
				$this->stored = false;
			},
			function () {
				if ( $this->plugins instanceof RuntimeException ) {
					throw $this->plugins;
				}

				return PluginInventory::build( $this->plugins, $this->active, 'updatelens/updatelens.php' );
			},
			function () {
				return $this->time;
			}
		);
	}

	/**
	 * A fresh site gets the start time and installed plugins, without UpdateLens.
	 */
	public function test_creates_baseline_with_time_and_plugins() {
		$this->assertTrue( $this->baseline()->ensure() );

		$this->assertSame(
			array(
				'started_at' => '2026-10-07 22:32:00',
				'plugins'    => array(
					array(
						'file'    => 'classic-editor/classic-editor.php',
						'name'    => 'Classic Editor',
						'version' => '1.7.0',
						'active'  => false,
					),
					array(
						'file'    => 'woocommerce/woocommerce.php',
						'name'    => 'WooCommerce',
						'version' => '11.1.2',
						'active'  => true,
					),
				),
			),
			$this->baseline()->read()
		);
		$this->assertStringNotContainsString( 'SECRET', $this->stored );
		$this->assertStringNotContainsString( 'UpdateLens', $this->stored );
	}

	/**
	 * Later requests, updates and plugin changes never rewrite it.
	 */
	public function test_is_created_once() {
		$this->baseline()->ensure();
		$original = $this->stored;

		$this->time                  += 86400;
		$this->plugins['new/new.php'] = array(
			'Name'    => 'Installed Later',
			'Version' => '1.0',
		);
		$this->plugins['woocommerce/woocommerce.php']['Version'] = '11.2.0';
		$this->active = array( 'classic-editor/classic-editor.php' );

		$this->assertTrue( $this->baseline()->ensure() );
		$this->assertTrue( $this->baseline()->ensure() );

		$this->assertSame( $original, $this->stored );
		$this->assertSame( 1, $this->adds );
		$read = $this->baseline()->read();
		$this->assertSame( '2026-10-07 22:32:00', $read['started_at'] );
		$this->assertSame( array( 'classic-editor/classic-editor.php', 'woocommerce/woocommerce.php' ), array_column( $read['plugins'], 'file' ) );
		$this->assertSame( array( '1.7.0', '11.1.2' ), array_column( $read['plugins'], 'version' ) );
		$this->assertSame( array( false, true ), array_column( $read['plugins'], 'active' ) );
	}

	/**
	 * An unreadable stored baseline is reported as missing but never replaced
	 * by today's inventory.
	 */
	public function test_corrupt_baseline_is_not_overwritten() {
		$this->stored = '{"schema":1,"started_at":';

		$this->assertTrue( $this->baseline()->ensure() );
		$this->assertSame( '{"schema":1,"started_at":', $this->stored );
		$this->assertSame( 0, $this->adds );
		$this->assertNull( $this->baseline()->read() );

		$this->stored = array( 'unexpected' );
		$this->assertNull( $this->baseline()->read() );
	}

	/**
	 * Missing baseline reads as null.
	 */
	public function test_missing_baseline_reads_null() {
		$this->assertNull( $this->baseline()->read() );
	}

	/**
	 * A failing inventory or option write never throws, stores nothing, and a later ensure() retries.
	 */
	public function test_failures_never_throw_and_are_retried() {
		$this->plugins = new RuntimeException( 'get_plugins failed: /var/www/secret/path' );
		$this->assertFalse( $this->baseline()->ensure() );
		$this->assertFalse( $this->stored );

		$this->reset_state();
		$this->fail_add = true;
		$this->assertFalse( $this->baseline()->ensure() );
		$this->assertFalse( $this->stored );

		$this->fail_add = false;
		$this->time    += 60;
		$this->assertTrue( $this->baseline()->ensure() );
		$this->assertSame( '2026-10-07 22:33:00', $this->baseline()->read()['started_at'] );
	}

	/**
	 * A concurrent request that stored a baseline first wins.
	 */
	public function test_concurrent_creation_keeps_first_value() {
		$late = $this->baseline();
		$this->baseline()->ensure();
		$first = $this->stored;

		// The late request read "absent" before; add_option() refuses because the option exists by now.
		$this->time += 1;
		$racing      = new MonitoringBaseline(
			function () {
				return false;
			},
			function () {
				return false;
			},
			function () {},
			function () {
				return array();
			}
		);

		$this->assertFalse( $racing->ensure() );
		$this->assertSame( $first, $this->stored );
		$this->assertSame( '2026-10-07 22:32:00', $late->read()['started_at'] );
	}

	/**
	 * Deleting removes the baseline (used on uninstall).
	 */
	public function test_delete_removes_baseline() {
		$this->baseline()->ensure();
		$this->baseline()->delete();

		$this->assertFalse( $this->stored );
		$this->assertNull( $this->baseline()->read() );
	}

	/**
	 * The option is UpdateLens-owned, so options snapshots never see it.
	 */
	public function test_option_name_is_prefixed() {
		$this->assertSame( 'updatelens_monitoring_baseline', MonitoringBaseline::OPTION );
	}
}
