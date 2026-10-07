<?php
/**
 * REST endpoint for the monitoring start.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Rest;

use Throwable;
use UpdateLens\Core\Plugin;
use UpdateLens\Report\MonitoringStart;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /updatelens/v1/baseline
 *
 * When UpdateLens started monitoring and which plugins were installed then
 * (Report\MonitoringStart). Read-only. A missing or unreadable baseline is not
 * an error: its fields are null. Storage problems become a generic 500 error
 * without database details.
 */
final class BaselineController extends WP_REST_Controller {

	/**
	 * Returns the MonitoringStart to read from.
	 *
	 * @var callable
	 */
	private $monitoring_start;

	/**
	 * Constructor.
	 *
	 * @param callable|null $monitoring_start Returns a MonitoringStart instance. Default MonitoringStart::create().
	 */
	public function __construct( ?callable $monitoring_start = null ) {
		$this->namespace        = Plugin::REST_NAMESPACE;
		$this->rest_base        = 'baseline';
		$this->monitoring_start = null === $monitoring_start ? array( MonitoringStart::class, 'create' ) : $monitoring_start;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Same access rule as the UpdateLens screen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( current_user_can( Plugin::CAPABILITY ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to access UpdateLens.', 'updatelens' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Monitoring start. Database errors go to the PHP error log only, never into the response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		global $wpdb;

		$show_errors = $wpdb->hide_errors();
		try {
			return new WP_REST_Response( call_user_func( $this->monitoring_start )->read() );
		} catch ( Throwable $e ) {
			return new WP_Error(
				'updatelens_reports_unavailable',
				__( 'UpdateLens reports are currently unavailable.', 'updatelens' ),
				array( 'status' => 500 )
			);
		} finally {
			$wpdb->show_errors( $show_errors );
		}
	}

	/**
	 * Response schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$view = array(
			'context'  => array( 'view' ),
			'readonly' => true,
		);

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'updatelens-baseline',
			'type'       => 'object',
			'properties' => array(
				'started_at'   => array(
					'description' => __( 'When UpdateLens started monitoring (UTC ISO 8601), or null if unknown. Earlier updates were not observed.', 'updatelens' ),
					'type'        => array( 'string', 'null' ),
				) + $view,
				'plugin_count' => array(
					'description' => __( 'Number of other plugins installed when monitoring started, or null if not recorded.', 'updatelens' ),
					'type'        => array( 'integer', 'null' ),
				) + $view,
				'plugins'      => array(
					'description' => __( 'Plugins installed when monitoring started (UpdateLens itself excluded), or null if not recorded. Never updated afterwards.', 'updatelens' ),
					'type'        => array( 'array', 'null' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'file'    => array( 'type' => 'string' ),
							'name'    => array( 'type' => 'string' ),
							'version' => array( 'type' => 'string' ),
							'active'  => array( 'type' => 'boolean' ),
						),
					),
				) + $view,
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
