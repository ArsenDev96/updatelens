<?php
/**
 * Tests for the UpdateLens admin menu entry.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use UpdateLens\Admin\AdminPage;
use UpdateLens\Admin\UnreadReports;

/**
 * AdminPage::add_page() against WordPress stand-ins: position and new-report count.
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

	/**
	 * Menu title registered by add_page().
	 *
	 * @return string
	 */
	private static function menu_title() {
		( new AdminPage() )->add_page();

		return $GLOBALS['updatelens_test']['menu_pages'][0]['menu_title'];
	}

	/**
	 * Prepared query templates, whitespace collapsed, with their arguments.
	 *
	 * @return array<int, array{string, array}>
	 */
	private static function prepared() {
		return array_map(
			static function ( $prepared ) {
				return array( trim( preg_replace( '/\s+/', ' ', $prepared[0] ) ), $prepared[1] );
			},
			$GLOBALS['wpdb']->prepared
		);
	}

	/**
	 * New reports above the user's watermark appear as a WordPress-style
	 * count, from one primary-key COUNT (no diff columns). The page title
	 * stays "UpdateLens".
	 */
	public function test_menu_shows_new_report_count() {
		$GLOBALS['updatelens_test']['options'][ UnreadReports::ORIGIN_OPTION ] = '2';
		$GLOBALS['updatelens_test']['usermeta'][1][ UnreadReports::USER_META ] = '5';
		$GLOBALS['wpdb']->vars = array( '3' );

		$this->assertSame(
			'UpdateLens <span class="awaiting-mod count-3"><span class="pending-count" aria-hidden="true">3</span><span class="screen-reader-text">3 new UpdateLens reports</span></span>',
			self::menu_title()
		);
		$this->assertSame(
			array( array( 'SELECT COUNT(*) FROM %i WHERE id > %d', array( 'wp_updatelens_analyses', 5 ) ) ),
			self::prepared()
		);
		$this->assertTrue( $GLOBALS['wpdb']->show_errors, 'Database error display is restored.' );
	}

	/**
	 * No new reports: no count.
	 */
	public function test_no_count_without_new_reports() {
		$GLOBALS['updatelens_test']['options'][ UnreadReports::ORIGIN_OPTION ] = '0';
		$GLOBALS['wpdb']->vars = array( '0' );

		$this->assertSame( 'UpdateLens', self::menu_title() );
	}

	/**
	 * Opening the UpdateLens screen marks every report seen before the menu
	 * is built, so the count is gone there.
	 */
	public function test_opening_updatelens_marks_reports_seen() {
		$GLOBALS['plugin_page'] = 'updatelens'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates core's current admin page.
		$GLOBALS['updatelens_test']['options'][ UnreadReports::ORIGIN_OPTION ] = '2';
		$GLOBALS['wpdb']->vars = array( '9', '0' ); // MAX(id), then COUNT above it.

		$this->assertSame( 'UpdateLens', self::menu_title() );
		$this->assertSame( '9', $GLOBALS['updatelens_test']['usermeta'][1][ UnreadReports::USER_META ] );
		$this->assertSame(
			array(
				array( 'SELECT MAX(id) FROM %i', array( 'wp_updatelens_analyses' ) ),
				array( 'SELECT COUNT(*) FROM %i WHERE id > %d', array( 'wp_updatelens_analyses', 9 ) ),
			),
			self::prepared()
		);
	}

	/**
	 * Users who cannot open UpdateLens get no count and cause no queries.
	 */
	public function test_no_count_or_query_without_capability() {
		$GLOBALS['updatelens_test']['caps'] = array( 'edit_posts' );

		$this->assertSame( 'UpdateLens', self::menu_title() );
		$this->assertSame( array(), $GLOBALS['wpdb']->prepared );
		$this->assertSame( array(), $GLOBALS['updatelens_test']['options'] );
	}

	/**
	 * Multisite (where analyses are not recorded): no count, no queries.
	 */
	public function test_no_count_on_multisite() {
		$GLOBALS['updatelens_test']['multisite'] = true;

		$this->assertSame( 'UpdateLens', self::menu_title() );
		$this->assertSame( array(), $GLOBALS['wpdb']->prepared );
	}

	/**
	 * A database error (e.g. missing table) shows no count and stores nothing.
	 */
	public function test_database_error_shows_no_count() {
		$GLOBALS['wpdb']->last_error = 'Table does not exist';

		$this->assertSame( 'UpdateLens', self::menu_title() );
		$this->assertSame( array(), $GLOBALS['updatelens_test']['options'] );
		$this->assertTrue( $GLOBALS['wpdb']->show_errors );
	}
}
