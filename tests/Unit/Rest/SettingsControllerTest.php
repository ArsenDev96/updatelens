<?php
/**
 * Tests for the settings REST endpoint and the site check opt-in.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\Core\Plugin;
use UpdateLens\Rest\SettingsController;
use UpdateLens\SiteCheck\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET/POST /updatelens/v1/settings and SiteCheck\Settings against WordPress stand-ins.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SettingsControllerTest extends TestCase {

	/**
	 * Load the stand-ins.
	 *
	 * @before
	 */
	public function load_wordpress_stubs() {
		require dirname( __DIR__, 2 ) . '/fixtures/wp-baseline-stubs.php';
	}

	/**
	 * A POST request with a raw body.
	 *
	 * @param string $body Body.
	 * @return WP_REST_Request
	 */
	private static function post( $body ) {
		$request = new WP_REST_Request();
		$request->set_body( $body );

		return $request;
	}

	/**
	 * Response data of a successful call.
	 *
	 * @param mixed $response Controller result.
	 * @return array
	 */
	private function data( $response ) {
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		return $response->get_data();
	}

	/**
	 * Keep site checks off through the filter.
	 *
	 * @return void
	 */
	private static function host_disables() {
		$GLOBALS['updatelens_test']['filters'][ Settings::FILTER ][] = static function () {
			return false;
		};
	}

	/**
	 * Expected response.
	 *
	 * @param bool        $enabled     Saved preference.
	 * @param bool        $effective   May run.
	 * @param string|null $restriction Restriction.
	 * @return array
	 */
	private static function state( $enabled, $effective, $restriction = null ) {
		return array(
			'site_checks' => array(
				'enabled'     => $enabled,
				'effective'   => $effective,
				'restriction' => $restriction,
			),
		);
	}

	/**
	 * One GET and one POST route, both with a real permission callback; the
	 * POST argument has its own validation and sanitization.
	 */
	public function test_registers_routes() {
		( new SettingsController() )->register_routes();

		$route = $GLOBALS['updatelens_test']['routes']['updatelens/v1/settings'];
		$this->assertSame( 'GET', $route[0]['methods'] );
		$this->assertSame( 'POST', $route[1]['methods'] );
		foreach ( array( $route[0], $route[1] ) as $endpoint ) {
			$this->assertIsCallable( $endpoint['permission_callback'] );
			$this->assertNotSame( '__return_true', $endpoint['permission_callback'] );
		}
		$this->assertSame( array( 'site_checks' ), array_keys( $route[1]['args'] ) );
		$this->assertTrue( $route[1]['args']['site_checks']['required'] );
		$this->assertIsCallable( $route[1]['args']['site_checks']['validate_callback'] );
		$this->assertIsCallable( $route[1]['args']['site_checks']['sanitize_callback'] );
	}

	/**
	 * The plugin registers the route on single sites only, like the
	 * other site check pieces.
	 */
	public function test_plugin_registers_route_on_single_sites() {
		( new Plugin() )->register_rest_routes();
		$this->assertArrayHasKey( 'updatelens/v1/settings', $GLOBALS['updatelens_test']['routes'] );

		$GLOBALS['updatelens_test']['routes']    = array();
		$GLOBALS['updatelens_test']['multisite'] = true;
		( new Plugin() )->register_rest_routes();
		$this->assertArrayNotHasKey( 'updatelens/v1/settings', $GLOBALS['updatelens_test']['routes'] );
	}

	/**
	 * Reading and changing both need manage_options; update_plugins alone is not enough.
	 */
	public function test_permission() {
		$controller = new SettingsController();
		$this->assertTrue( $controller->get_item_permissions_check( new WP_REST_Request() ) );
		$this->assertTrue( $controller->update_item_permissions_check( new WP_REST_Request() ) );

		foreach ( array( array( 'read', 'update_plugins', 'activate_plugins' ), array( 'edit_posts' ), array() ) as $caps ) {
			$GLOBALS['updatelens_test']['caps'] = $caps;
			foreach ( array( $controller->get_item_permissions_check( new WP_REST_Request() ), $controller->update_item_permissions_check( new WP_REST_Request() ) ) as $denied ) {
				$this->assertInstanceOf( WP_Error::class, $denied );
				$this->assertSame( 'rest_forbidden', $denied->get_error_code() );
				$this->assertSame( array( 'status' => 403 ), $denied->get_error_data() );
			}
		}
	}

	/**
	 * First installation: no option, site checks off.
	 */
	public function test_off_by_default() {
		$this->assertFalse( Settings::is_saved_enabled() );
		$this->assertFalse( Settings::is_enabled() );
		$this->assertSame( self::state( false, false ), $this->data( ( new SettingsController() )->get_item( new WP_REST_Request() ) ) );
	}

	/**
	 * Only the exact stored value `enabled` turns them on.
	 */
	public function test_reads_the_stored_value() {
		foreach ( array( 'yes', '1', 'Enabled', 'disabled', '' ) as $value ) {
			$GLOBALS['updatelens_test']['options'][ Settings::OPTION ] = $value;
			$this->assertSame( self::state( false, false ), $this->data( ( new SettingsController() )->get_item( new WP_REST_Request() ) ), $value );
		}

		$GLOBALS['updatelens_test']['options'][ Settings::OPTION ] = 'enabled';
		$this->assertSame( self::state( true, true ), $this->data( ( new SettingsController() )->get_item( new WP_REST_Request() ) ) );
		$this->assertTrue( Settings::is_enabled() );
	}

	/**
	 * Enable, then disable: stored without autoloading, read back after each save.
	 */
	public function test_save_enable_and_disable() {
		$controller = new SettingsController();

		$this->assertSame( self::state( true, true ), $this->data( $controller->update_item( self::post( '{"site_checks":{"enabled":true}}' ) ) ) );
		$this->assertSame( 'enabled', $GLOBALS['updatelens_test']['options'][ Settings::OPTION ] );
		$this->assertFalse( $GLOBALS['updatelens_test']['autoload'][ Settings::OPTION ] );
		$this->assertSame( self::state( true, true ), $this->data( $controller->get_item( new WP_REST_Request() ) ), 'Persists for the next read.' );

		$this->assertSame( self::state( false, false ), $this->data( $controller->update_item( self::post( '{"site_checks":{"enabled":false}}' ) ) ) );
		$this->assertSame( 'disabled', $GLOBALS['updatelens_test']['options'][ Settings::OPTION ] );
		$this->assertFalse( $GLOBALS['updatelens_test']['autoload'][ Settings::OPTION ] );
		$this->assertFalse( Settings::is_enabled() );
	}

	/**
	 * Saving the stored value again succeeds without writing.
	 */
	public function test_repeated_save_is_not_an_error() {
		$GLOBALS['updatelens_test']['options'][ Settings::OPTION ] = 'enabled';

		$this->assertSame( self::state( true, true ), $this->data( ( new SettingsController() )->update_item( self::post( '{"site_checks":{"enabled":true}}' ) ) ) );
		$this->assertSame( 0, $GLOBALS['updatelens_test']['writes'] );
	}

	/**
	 * Anything but exactly {"site_checks":{"enabled":<bool>}} is rejected
	 * and changes nothing.
	 *
	 * @dataProvider malformed_bodies
	 *
	 * @param string $body Request body.
	 */
	public function test_rejects_malformed_settings( $body ) {
		$GLOBALS['updatelens_test']['options'][ Settings::OPTION ] = 'enabled';

		$error = ( new SettingsController() )->update_item( self::post( $body ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'updatelens_invalid_settings', $error->get_error_code() );
		$this->assertSame( array( 'status' => 400 ), $error->get_error_data() );
		$this->assertSame( 'enabled', $GLOBALS['updatelens_test']['options'][ Settings::OPTION ] );
		$this->assertSame( 0, $GLOBALS['updatelens_test']['writes'] );
	}

	/**
	 * Malformed bodies.
	 *
	 * @return array<string, array{string}>
	 */
	public function malformed_bodies() {
		return array(
			'no body'                => array( '' ),
			'invalid JSON'           => array( '{"site_checks":' ),
			'not an object'          => array( 'true' ),
			'empty object'           => array( '{}' ),
			'list'                   => array( '[{"enabled":false}]' ),
			'flat boolean'           => array( '{"site_checks":false}' ),
			'string'                 => array( '{"site_checks":{"enabled":"false"}}' ),
			'string true'            => array( '{"site_checks":{"enabled":"true"}}' ),
			'number'                 => array( '{"site_checks":{"enabled":0}}' ),
			'null'                   => array( '{"site_checks":{"enabled":null}}' ),
			'missing enabled'        => array( '{"site_checks":{}}' ),
			'unknown nested key'     => array( '{"site_checks":{"enabled":false,"effective":true}}' ),
			'unknown top-level key'  => array( '{"site_checks":{"enabled":false},"other":1}' ),
			'unknown key only'       => array( '{"site_check_interval":60}' ),
			'readonly field instead' => array( '{"site_checks":{"restriction":null}}' ),
		);
	}

	/**
	 * The argument callbacks accept exactly one boolean field.
	 */
	public function test_argument_validation() {
		$this->assertTrue( SettingsController::validate_site_checks( array( 'enabled' => true ) ) );
		$this->assertTrue( SettingsController::validate_site_checks( array( 'enabled' => false ) ) );
		foreach ( array(
			null,
			true,
			'enabled',
			array(),
			array( 'enabled' => 'true' ),
			array( 'enabled' => 1 ),
			array(
				'enabled' => true,
				'x'       => 1,
			),
		) as $value ) {
			$this->assertFalse( SettingsController::validate_site_checks( $value ) );
		}
		$this->assertSame( array( 'enabled' => false ), SettingsController::sanitize_site_checks( array( 'enabled' => false ) ) );
		$this->assertSame( array( 'enabled' => true ), SettingsController::sanitize_site_checks( array( 'enabled' => true ) ) );
	}

	/**
	 * A failed write is a generic 500, and the stored value is unchanged.
	 */
	public function test_failed_save() {
		$GLOBALS['updatelens_test']['options'][ Settings::OPTION ] = 'disabled';
		$GLOBALS['updatelens_test']['failing'][]                   = Settings::OPTION;

		$error = ( new SettingsController() )->update_item( self::post( '{"site_checks":{"enabled":true}}' ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'updatelens_settings_not_saved', $error->get_error_code() );
		$this->assertSame( array( 'status' => 500 ), $error->get_error_data() );
		$this->assertSame( 'disabled', $GLOBALS['updatelens_test']['options'][ Settings::OPTION ] );
	}

	/**
	 * An exception while saving (e.g. from another plugin's option hook) is
	 * the same generic 500, without its message.
	 */
	public function test_save_exception() {
		$GLOBALS['updatelens_test']['filters'][ Settings::FILTER ][] = static function () {
			throw new RuntimeException( 'secret detail' );
		};

		$error = ( new SettingsController() )->update_item( self::post( '{"site_checks":{"enabled":false}}' ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'updatelens_settings_not_saved', $error->get_error_code() );
		$this->assertStringNotContainsString( 'secret', $error->message );

		$error = ( new SettingsController() )->get_item( new WP_REST_Request() );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'updatelens_settings_unavailable', $error->get_error_code() );
		$this->assertSame( array( 'status' => 500 ), $error->get_error_data() );
	}

	/**
	 * The filter keeps site checks off whatever is saved, and the API keeps
	 * the saved preference apart from the effective state.
	 */
	public function test_host_filter_keeps_site_checks_off() {
		self::host_disables();
		$controller = new SettingsController();

		$this->assertSame( self::state( false, false, 'filter' ), $this->data( $controller->get_item( new WP_REST_Request() ) ) );

		$this->assertSame( self::state( true, false, 'filter' ), $this->data( $controller->update_item( self::post( '{"site_checks":{"enabled":true}}' ) ) ) );
		$this->assertSame( 'enabled', $GLOBALS['updatelens_test']['options'][ Settings::OPTION ], 'The preference is still saved.' );
		$this->assertTrue( Settings::is_saved_enabled() );
		$this->assertFalse( Settings::is_enabled() );
	}

	/**
	 * The filter cannot turn site checks on.
	 */
	public function test_host_filter_cannot_enable() {
		$GLOBALS['updatelens_test']['filters'][ Settings::FILTER ][] = static function () {
			return true;
		};

		$this->assertFalse( Settings::is_enabled() );
		$this->assertSame( self::state( false, false ), $this->data( ( new SettingsController() )->get_item( new WP_REST_Request() ) ) );

		$GLOBALS['updatelens_test']['options'][ Settings::OPTION ] = 'enabled';
		$this->assertTrue( Settings::is_enabled() );
	}

	/**
	 * Only these three fields are returned.
	 */
	public function test_schema_matches_response() {
		$schema = ( new SettingsController() )->get_item_schema();

		$this->assertSame( array( 'site_checks' ), array_keys( $schema['properties'] ) );
		$this->assertSame( array( 'enabled', 'effective', 'restriction' ), array_keys( $schema['properties']['site_checks']['properties'] ) );
		$this->assertSame( array_keys( self::state( false, false )['site_checks'] ), array_keys( $schema['properties']['site_checks']['properties'] ) );
	}
}
