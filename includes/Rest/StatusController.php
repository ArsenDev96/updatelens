<?php
/**
 * REST endpoint reporting basic plugin status.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Rest;

use UpdateLens\Core\Plugin;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /updatelens/v1/status
 *
 * Lets the admin app verify the PHP ↔ React wiring (cookie auth, nonce,
 * permission callback). Reference pattern for future UpdateLens routes.
 */
final class StatusController extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = Plugin::REST_NAMESPACE;
		$this->rest_base = 'status';
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
	 * Permission check.
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
	 * Return plugin status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		return rest_ensure_response(
			array(
				'version' => UPDATELENS_VERSION,
			)
		);
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

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'updatelens-status',
			'type'       => 'object',
			'properties' => array(
				'version' => array(
					'description' => __( 'Installed UpdateLens version.', 'updatelens' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
