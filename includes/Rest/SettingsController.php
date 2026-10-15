<?php
/**
 * REST endpoint for UpdateLens settings.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Rest;

use Throwable;
use UpdateLens\Core\Plugin;
use UpdateLens\SiteCheck\Settings;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET and POST /updatelens/v1/settings
 *
 * The one UpdateLens setting: the site check opt-in (SiteCheck\Settings).
 * Both methods need `manage_options`; running site checks still needs
 * `update_plugins` (SiteCheck\AjaxEndpoints). A save accepts exactly
 * `{"site_checks":{"enabled":<bool>}}` as JSON and nothing else, and answers
 * with the stored state read back. Responses separate the saved preference
 * (`enabled`) from whether checks may run (`effective`): the
 * `updatelens_site_checks_enabled` filter can keep them off (`restriction`).
 */
final class SettingsController extends WP_REST_Controller {

	/**
	 * Restriction code: the `updatelens_site_checks_enabled` filter keeps site checks off.
	 */
	const RESTRICTION_FILTER = 'filter';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = Plugin::REST_NAMESPACE;
		$this->rest_base = 'settings';
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
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'site_checks' => array(
							'description'       => __( 'Website check settings: exactly {"enabled": true|false}.', 'updatelens' ),
							'type'              => 'object',
							'required'          => true,
							'validate_callback' => array( self::class, 'validate_site_checks' ),
							'sanitize_callback' => array( self::class, 'sanitize_site_checks' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Reading the settings needs the same capability as the UpdateLens screen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return self::permission();
	}

	/**
	 * Changing them too (`manage_options`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		return self::permission();
	}

	/**
	 * Current settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		global $wpdb;

		$show_errors = $wpdb->hide_errors();
		try {
			return new WP_REST_Response( self::current() );
		} catch ( Throwable $e ) {
			return self::unavailable();
		} finally {
			$wpdb->show_errors( $show_errors );
		}
	}

	/**
	 * Save the settings and answer with what is stored now.
	 *
	 * Only a JSON body with exactly the `site_checks` key is accepted (query
	 * arguments such as `_locale` are not part of it). Database errors never
	 * reach the response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		global $wpdb;

		$body    = $request->get_json_params();
		$enabled = is_array( $body ) && array( 'site_checks' ) === array_keys( $body ) ? self::enabled_from( $body['site_checks'] ) : null;
		if ( null === $enabled ) {
			return new WP_Error(
				'updatelens_invalid_settings',
				__( 'Invalid UpdateLens settings.', 'updatelens' ),
				array( 'status' => 400 )
			);
		}

		$show_errors = $wpdb->hide_errors();
		try {
			if ( ! Settings::save( $enabled ) ) {
				return self::not_saved();
			}

			return new WP_REST_Response( self::current() );
		} catch ( Throwable $e ) {
			return self::not_saved();
		} finally {
			$wpdb->show_errors( $show_errors );
		}
	}

	/**
	 * Validate the `site_checks` argument: exactly `{"enabled": <bool>}`.
	 *
	 * @param mixed $value Argument value.
	 * @return bool
	 */
	public static function validate_site_checks( $value ) {
		return null !== self::enabled_from( $value );
	}

	/**
	 * Sanitize the `site_checks` argument to its only field.
	 *
	 * @param mixed $value Validated argument value.
	 * @return array{enabled: bool}
	 */
	public static function sanitize_site_checks( $value ) {
		return array( 'enabled' => true === self::enabled_from( $value ) );
	}

	/**
	 * The preference from a `site_checks` value, or null unless it is exactly
	 * `{"enabled": <bool>}` (no other keys, no strings or numbers for booleans).
	 *
	 * @param mixed $value Value.
	 * @return bool|null
	 */
	public static function enabled_from( $value ) {
		if ( ! is_array( $value ) || array( 'enabled' ) !== array_keys( $value ) || ! is_bool( $value['enabled'] ) ) {
			return null;
		}

		return $value['enabled'];
	}

	/**
	 * Stored settings: the saved preference, whether checks may run, and why not.
	 *
	 * @return array{site_checks: array{enabled: bool, effective: bool, restriction: string|null}}
	 */
	private static function current() {
		$saved   = Settings::is_saved_enabled();
		$allowed = Settings::is_allowed();

		return array(
			'site_checks' => array(
				'enabled'     => $saved,
				'effective'   => $saved && $allowed,
				'restriction' => $allowed ? null : self::RESTRICTION_FILTER,
			),
		);
	}

	/**
	 * Permission: `manage_options`.
	 *
	 * @return true|WP_Error
	 */
	private static function permission() {
		if ( current_user_can( Plugin::CAPABILITY ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to manage UpdateLens settings.', 'updatelens' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Settings could not be read.
	 *
	 * @return WP_Error
	 */
	private static function unavailable() {
		return new WP_Error(
			'updatelens_settings_unavailable',
			__( 'UpdateLens settings are currently unavailable.', 'updatelens' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Settings could not be saved.
	 *
	 * @return WP_Error
	 */
	private static function not_saved() {
		return new WP_Error(
			'updatelens_settings_not_saved',
			__( 'UpdateLens settings could not be saved.', 'updatelens' ),
			array( 'status' => 500 )
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
			'title'      => 'updatelens-settings',
			'type'       => 'object',
			'properties' => array(
				'site_checks' => array(
					'description'          => __( 'Website checks: homepage and REST API responses compared before and after supported manual plugin updates.', 'updatelens' ),
					'type'                 => 'object',
					'context'              => array( 'view', 'edit' ),
					'additionalProperties' => false,
					'properties'           => array(
						'enabled'     => array(
							'description' => __( 'The saved preference. Off by default.', 'updatelens' ),
							'type'        => 'boolean',
						),
						'effective'   => array(
							'description' => __( 'Whether website checks may run: the preference is on and the site configuration does not keep them off.', 'updatelens' ),
							'type'        => 'boolean',
							'readonly'    => true,
						),
						'restriction' => array(
							'description' => __( 'Why website checks cannot run whatever the preference: "filter" (the updatelens_site_checks_enabled filter), or null.', 'updatelens' ),
							'type'        => array( 'string', 'null' ),
							'enum'        => array( self::RESTRICTION_FILTER, null ),
							'readonly'    => true,
						),
					),
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
