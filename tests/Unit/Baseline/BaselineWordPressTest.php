<?php
/**
 * Tests for the monitoring baseline against WordPress stand-ins.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Baseline;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\Baseline\MonitoringBaseline;
use UpdateLens\Admin\UnreadReports;
use UpdateLens\Core\Deactivator;
use UpdateLens\Storage\Schema;

/**
 * WordPress wiring (get_plugins(), is_plugin_active(), options), uninstall and deactivation.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class BaselineWordPressTest extends TestCase {

	/**
	 * Load the stand-ins and install three plugins (UpdateLens among them).
	 *
	 * @before
	 */
	public function load_wordpress_stubs() {
		require dirname( __DIR__, 2 ) . '/fixtures/wp-baseline-stubs.php';

		$GLOBALS['updatelens_test']['plugins'] = array(
			'woocommerce/woocommerce.php' => array(
				'Name'      => 'WooCommerce',
				'Version'   => '11.1.2',
				'UpdateURI' => 'https://updates.example.test/?license=SECRET',
			),
			'hello.php'                   => array(
				'Name'    => 'Hello Dolly',
				'Version' => '1.7.2',
			),
			'updatelens/updatelens.php'   => array(
				'Name'    => 'UpdateLens',
				'Version' => '0.2.0-beta.2',
			),
		);
		$GLOBALS['updatelens_test']['active']  = array( 'woocommerce/woocommerce.php', 'updatelens/updatelens.php' );
	}

	/**
	 * Stored option value.
	 *
	 * @return mixed
	 */
	private static function stored() {
		return get_option( MonitoringBaseline::OPTION, false );
	}

	/**
	 * Real wiring: installed plugins with active state, UpdateLens excluded, not autoloaded.
	 */
	public function test_creates_baseline_from_wordpress_plugins() {
		$this->assertTrue( MonitoringBaseline::create()->ensure() );

		$read = MonitoringBaseline::create()->read();
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $read['started_at'] );
		$this->assertSame(
			array(
				array(
					'file'    => 'hello.php',
					'name'    => 'Hello Dolly',
					'version' => '1.7.2',
					'active'  => false,
				),
				array(
					'file'    => 'woocommerce/woocommerce.php',
					'name'    => 'WooCommerce',
					'version' => '11.1.2',
					'active'  => true,
				),
			),
			$read['plugins']
		);
		$this->assertFalse( $GLOBALS['updatelens_test']['autoload'][ MonitoringBaseline::OPTION ] );
		$this->assertStringNotContainsString( 'SECRET', self::stored() );
	}

	/**
	 * Plugin changes after the baseline never change it.
	 */
	public function test_existing_baseline_is_kept() {
		MonitoringBaseline::create()->ensure();
		$original = self::stored();

		$GLOBALS['updatelens_test']['plugins']['new/new.php'] = array(
			'Name'    => 'Installed Later',
			'Version' => '1.0',
		);
		$GLOBALS['updatelens_test']['active']                 = array();

		$this->assertTrue( MonitoringBaseline::create()->ensure() );
		$this->assertSame( $original, self::stored() );
	}

	/**
	 * Failures while reading plugins never escape (activation cannot fail because of the baseline).
	 */
	public function test_inventory_failure_does_not_throw() {
		$GLOBALS['updatelens_test']['plugins'] = new RuntimeException( 'boom' );

		$this->assertFalse( MonitoringBaseline::create()->ensure() );
		$this->assertFalse( self::stored() );
	}

	/**
	 * Uninstall removes the baseline together with the table and schema version.
	 */
	public function test_uninstall_removes_baseline() {
		MonitoringBaseline::create()->ensure();
		add_option( Schema::VERSION_OPTION, Schema::VERSION );
		add_option( 'unrelated_option', 'kept' );
		add_option( UnreadReports::ORIGIN_OPTION, '4' );
		update_user_meta( 1, UnreadReports::USER_META, '7' );
		update_user_meta( 2, UnreadReports::USER_META, '5' );
		update_user_meta( 2, 'unrelated_meta', 'kept' );

		define( 'WP_UNINSTALL_PLUGIN', 'updatelens/updatelens.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.
		require dirname( __DIR__, 3 ) . '/uninstall.php';

		$this->assertFalse( self::stored() );
		$this->assertFalse( get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( 'kept', get_option( 'unrelated_option' ) );
		$this->assertSame( array( 'DROP TABLE IF EXISTS wp_updatelens_analyses' ), $GLOBALS['updatelens_test']['queries'] );
		// Unread tracking: the origin and every user's watermark are gone.
		$this->assertFalse( get_option( UnreadReports::ORIGIN_OPTION ) );
		$this->assertSame(
			array(
				1 => array(),
				2 => array( 'unrelated_meta' => 'kept' ),
			),
			$GLOBALS['updatelens_test']['usermeta']
		);
	}

	/**
	 * Deactivation keeps the baseline (and its original start).
	 */
	public function test_deactivation_preserves_baseline() {
		MonitoringBaseline::create()->ensure();
		$original = self::stored();
		add_option( UnreadReports::ORIGIN_OPTION, '4' );
		update_user_meta( 1, UnreadReports::USER_META, '7' );

		Deactivator::deactivate();

		$this->assertSame( $original, self::stored() );
		// Unread state is kept too.
		$this->assertSame( '4', get_option( UnreadReports::ORIGIN_OPTION ) );
		$this->assertSame( '7', get_user_meta( 1, UnreadReports::USER_META, true ) );
	}
}
