<?php
/**
 * Tests for the UpdateLens admin menu entry.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use UpdateLens\Admin\AdminPage;

/**
 * AdminPage::add_page() against WordPress stand-ins.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AdminPageTest extends TestCase {

	/**
	 * Load the stand-ins.
	 *
	 * @before
	 */
	public function load_wordpress_stubs() {
		require dirname( __DIR__, 2 ) . '/fixtures/wp-admin-menu-stubs.php';
	}

	/**
	 * Top-level page with the configured slug, capability, icon and position.
	 */
	public function test_registers_top_level_page_below_plugins() {
		( new AdminPage() )->add_page();

		$this->assertSame(
			array(
				array(
					'menu_title' => 'UpdateLens',
					'capability' => 'manage_options',
					'menu_slug'  => 'updatelens',
					'icon_url'   => 'dashicons-visibility',
					'position'   => 65.5,
				),
			),
			$GLOBALS['updatelens_test']['menu_pages']
		);
	}

	/**
	 * WordPress keys menu entries by position (floats cast to strings) and
	 * sorts them naturally: UpdateLens lands between Plugins (65) and Users (70).
	 */
	public function test_position_sorts_between_plugins_and_users() {
		$menu = array(
			60                                => 'Appearance',
			65                                => 'Plugins',
			70                                => 'Users',
			75                                => 'Tools',
			(string) AdminPage::MENU_POSITION => 'UpdateLens',
		);
		uksort( $menu, 'strnatcasecmp' );

		$this->assertSame( array( 'Appearance', 'Plugins', 'UpdateLens', 'Users', 'Tools' ), array_values( $menu ) );
	}
}
