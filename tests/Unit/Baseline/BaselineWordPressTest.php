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
use UpdateLens\Core\Uninstaller;
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
		$this->assertSame(
			array(
				'DROP TABLE IF EXISTS wp_updatelens_analyses',
				// Site check baselines: UpdateLens's own option rows only.
				'DELETE FROM wp_options WHERE option_name LIKE updatelens\_site\_check\_baseline\_% OR option_name = updatelens_update_activity',
				// Site check session claims and session transients left in the table.
				'DELETE FROM wp_options WHERE option_name LIKE updatelens\_site\_check\_claim\_% OR option_name LIKE \_transient\_updatelens\_sc\_% OR option_name LIKE \_transient\_timeout\_updatelens\_sc\_%',
			),
			$GLOBALS['updatelens_test']['queries']
		);
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
	 * Multisite: every site's table and options go (also sites without any
	 * UpdateLens data), other data stays, the user meta is removed once and
	 * the main site is current again afterwards.
	 */
	public function test_uninstall_cleans_every_site_of_a_network() {
		$GLOBALS['updatelens_test']['multisite']    = true;
		$GLOBALS['updatelens_test']['sites']        = array( 1, 2, 3 );
		$GLOBALS['updatelens_test']['options']      = array(
			Schema::VERSION_OPTION => '7',
			'blogname'             => 'Main',
		);
		$GLOBALS['updatelens_test']['site_options'] = array(
			2 => array(
				Schema::VERSION_OPTION       => '7',
				Schema::RETRY_OPTION         => '1',
				MonitoringBaseline::OPTION   => '{}',
				UnreadReports::ORIGIN_OPTION => '0',
				'updatelens_site_checks'     => 'enabled',
				'blogname'                   => 'Second',
			),
			// Site 3 never had UpdateLens.
			3 => array( 'blogname' => 'Third' ),
		);
		update_user_meta( 1, UnreadReports::USER_META, '7' );
		update_user_meta( 2, 'updatelens_site_check_starts', '[1]' );
		update_user_meta( 2, 'unrelated_meta', 'kept' );

		define( 'WP_UNINSTALL_PLUGIN', 'updatelens/updatelens.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.
		require dirname( __DIR__, 3 ) . '/uninstall.php';

		$this->assertSame( array( 'blogname' => 'Main' ), $GLOBALS['updatelens_test']['options'] );
		$this->assertSame( array( 'blogname' => 'Second' ), $GLOBALS['updatelens_test']['site_options'][2] );
		$this->assertSame( array( 'blogname' => 'Third' ), $GLOBALS['updatelens_test']['site_options'][3] );
		$this->assertSame( 1, $GLOBALS['updatelens_test']['current_site'] );
		$this->assertSame( 'wp_', $GLOBALS['wpdb']->prefix );
		$this->assertSame( array(), $GLOBALS['updatelens_test']['switched'] );

		// Each site: its own table, baselines and the activity record, claims and session transients.
		$queries = array();
		foreach ( array( 'wp_', 'wp_2_', 'wp_3_' ) as $prefix ) {
			$queries[] = 'DROP TABLE IF EXISTS ' . $prefix . 'updatelens_analyses';
			$queries[] = 'DELETE FROM ' . $prefix . 'options WHERE option_name LIKE updatelens\_site\_check\_baseline\_% OR option_name = updatelens_update_activity';
			$queries[] = 'DELETE FROM ' . $prefix . 'options WHERE option_name LIKE updatelens\_site\_check\_claim\_% OR option_name LIKE \_transient\_updatelens\_sc\_% OR option_name LIKE \_transient\_timeout\_updatelens\_sc\_%';
		}
		$this->assertSame( $queries, $GLOBALS['updatelens_test']['queries'] );

		// User meta is shared by the network: removed once, not per site.
		$this->assertSame( array( UnreadReports::USER_META, 'updatelens_site_check_starts' ), $GLOBALS['updatelens_test']['meta_deletes'] );
		$this->assertSame(
			array(
				1 => array(),
				2 => array( 'unrelated_meta' => 'kept' ),
			),
			$GLOBALS['updatelens_test']['usermeta']
		);
	}

	/**
	 * Large networks are read SITE_BATCH IDs at a time, with the runtime
	 * cache emptied after each batch.
	 */
	public function test_uninstall_reads_large_networks_in_batches() {
		$GLOBALS['updatelens_test']['multisite'] = true;
		$GLOBALS['updatelens_test']['sites']     = range( 1, 2 * Uninstaller::SITE_BATCH + 5 );

		define( 'WP_UNINSTALL_PLUGIN', 'updatelens/updatelens.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.
		require dirname( __DIR__, 3 ) . '/uninstall.php';

		$this->assertSame( array( 0, 100, 200 ), array_column( $GLOBALS['updatelens_test']['site_queries'], 'offset' ) );
		foreach ( $GLOBALS['updatelens_test']['site_queries'] as $query ) {
			$this->assertSame( 'ids', $query['fields'] );
			$this->assertSame( Uninstaller::SITE_BATCH, $query['number'] );
			$this->assertSame( 'id', $query['orderby'] );
		}
		$this->assertSame( array( 1, 1, 1 ), $GLOBALS['updatelens_test']['cache_flushes'] );

		$drops = preg_grep( '/^DROP TABLE/', $GLOBALS['updatelens_test']['queries'] );
		$this->assertCount( 205, $drops );
		$this->assertSame( 'DROP TABLE IF EXISTS wp_205_updatelens_analyses', end( $drops ) );
		$this->assertCount( 2, $GLOBALS['updatelens_test']['meta_deletes'] );
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
