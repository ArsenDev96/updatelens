<?php
/**
 * Tests for the baseline REST endpoint.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use UpdateLens\Baseline\MonitoringBaseline;
use UpdateLens\Report\MonitoringStart;
use UpdateLens\Rest\BaselineController;
use UpdateLens\Storage\MonitoringBaselineCodec;
use UpdateLens\Tests\Support\InMemoryAnalysisRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /updatelens/v1/baseline against WordPress stand-ins.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class BaselineControllerTest extends TestCase {

	/**
	 * Stored baseline option, or false.
	 *
	 * @var mixed
	 */
	private $stored;

	/**
	 * Analyses.
	 *
	 * @var InMemoryAnalysisRepository
	 */
	private $repository;

	/**
	 * Load the stand-ins.
	 *
	 * @before
	 */
	public function load_wordpress_stubs() {
		require dirname( __DIR__, 2 ) . '/fixtures/wp-baseline-stubs.php';

		$this->repository = new InMemoryAnalysisRepository();
		$this->stored     = ( new MonitoringBaselineCodec() )->encode(
			'2026-10-07 22:32:00',
			array(
				array(
					'file'    => 'woocommerce/woocommerce.php',
					'name'    => 'WooCommerce',
					'version' => '11.1.2',
					'active'  => true,
				),
			)
		);
	}

	/**
	 * Controller over the fake baseline and repository.
	 *
	 * @return BaselineController
	 */
	private function controller() {
		return new BaselineController(
			function () {
				$baseline = new MonitoringBaseline(
					function () {
						return $this->stored;
					},
					function () {
						return false;
					},
					function () {},
					function () {
						return array();
					}
				);

				return new MonitoringStart( $baseline, $this->repository );
			}
		);
	}

	/**
	 * The route is read-only and has a real permission callback.
	 */
	public function test_registers_readable_route() {
		$this->controller()->register_routes();

		$route = $GLOBALS['updatelens_test']['routes']['updatelens/v1/baseline'];
		$this->assertSame( 'GET', $route[0]['methods'] );
		$this->assertIsCallable( $route[0]['permission_callback'] );
		$this->assertNotSame( '__return_true', $route[0]['permission_callback'] );
	}

	/**
	 * Requires manage_options.
	 */
	public function test_permission() {
		$this->assertTrue( $this->controller()->get_item_permissions_check( new WP_REST_Request() ) );

		$GLOBALS['updatelens_test']['caps'] = array( 'read', 'activate_plugins' );
		$denied                             = $this->controller()->get_item_permissions_check( new WP_REST_Request() );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'rest_forbidden', $denied->get_error_code() );
		$this->assertSame( array( 'status' => 403 ), $denied->get_error_data() );
	}

	/**
	 * Safe shape: start time, count and minimal plugin records only.
	 */
	public function test_safe_shape() {
		$response = $this->controller()->get_item( new WP_REST_Request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame(
			array(
				'started_at'   => '2026-10-07T22:32:00Z',
				'plugin_count' => 1,
				'plugins'      => array(
					array(
						'file'    => 'woocommerce/woocommerce.php',
						'name'    => 'WooCommerce',
						'version' => '11.1.2',
						'active'  => true,
					),
				),
			),
			$response->get_data()
		);
	}

	/**
	 * A corrupt baseline is not an error.
	 */
	public function test_corrupt_baseline_is_not_an_error() {
		$this->stored = '{"schema":1,"started_at":"2026-10-07 22:32:00","plugins":[{"file":"../x.php"}]}';

		$response = $this->controller()->get_item( new WP_REST_Request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame(
			array(
				'started_at'   => null,
				'plugin_count' => null,
				'plugins'      => null,
			),
			$response->get_data()
		);
	}

	/**
	 * Storage failures become a generic error without details.
	 */
	public function test_read_failure_is_generic() {
		$this->repository->fail_reads = true;

		$error = $this->controller()->get_item( new WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'updatelens_reports_unavailable', $error->get_error_code() );
		$this->assertSame( array( 'status' => 500 ), $error->get_error_data() );
		$this->assertStringNotContainsString( 'read failed', $error->message );
	}

	/**
	 * The schema documents exactly the returned fields.
	 */
	public function test_schema_fields() {
		$schema = $this->controller()->get_item_schema();

		$this->assertSame( array( 'started_at', 'plugin_count', 'plugins' ), array_keys( $schema['properties'] ) );
		$this->assertSame( array( 'file', 'name', 'version', 'active' ), array_keys( $schema['properties']['plugins']['items']['properties'] ) );
	}
}
