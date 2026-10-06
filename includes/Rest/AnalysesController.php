<?php
/**
 * REST endpoints for analysis history and reports.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Rest;

use Throwable;
use UpdateLens\Core\Plugin;
use UpdateLens\Report\AnalysisReadModel;
use UpdateLens\Report\AnalysisReports;
use UpdateLens\Report\UnavailableReason;
use UpdateLens\Update\ObservationPhase;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /updatelens/v1/analyses       History, newest first (X-WP-Total, X-WP-TotalPages).
 * GET /updatelens/v1/analyses/{id}  One report.
 *
 * Read-only: responses come from Report\AnalysisReports read models, never
 * from database rows. Storage problems become a generic 500 error without
 * database details.
 */
final class AnalysesController extends WP_REST_Controller {

	/**
	 * Returns the AnalysisReports to read from.
	 *
	 * @var callable
	 */
	private $reports;

	/**
	 * Schema of one signal's phase object.
	 *
	 * @param string   $description Description.
	 * @param string[] $reasons     Possible unavailable reasons.
	 * @param string[] $lists       Change lists of an available phase.
	 * @return array
	 */
	private static function provider_schema( $description, array $reasons, array $lists ) {
		$properties = array(
			'available'   => array( 'type' => 'boolean' ),
			'association' => array(
				'type' => 'string',
				'enum' => array_values( ObservationPhase::ASSOCIATION ),
			),
			'reason'      => array(
				'description' => __( 'Why the phase is unavailable (only when available is false).', 'updatelens' ),
				'type'        => 'string',
				'enum'        => $reasons,
			),
			'summary'     => array( 'type' => 'object' ),
		);
		foreach ( $lists as $list ) {
			$properties[ $list ] = array( 'type' => 'array' );
		}

		return array(
			'description' => $description,
			'type'        => 'object',
			'properties'  => $properties,
		);
	}

	/**
	 * History item schema, cached.
	 *
	 * @var array|null
	 */
	private $history_schema;

	/**
	 * Constructor.
	 *
	 * @param callable|null $reports Returns an AnalysisReports instance. Default AnalysisReports::create().
	 */
	public function __construct( ?callable $reports = null ) {
		$this->namespace = Plugin::REST_NAMESPACE;
		$this->rest_base = 'analyses';
		$this->reports   = null === $reports ? array( AnalysisReports::class, 'create' ) : $reports;
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
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_history_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'Analysis ID.', 'updatelens' ),
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Permission check for the history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return $this->check_permission();
	}

	/**
	 * Permission check for a report.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return $this->check_permission();
	}

	/**
	 * History page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$page     = (int) $request['page'];
		$per_page = (int) $request['per_page'];

		$history = $this->read(
			static function ( AnalysisReports $reports ) use ( $page, $per_page ) {
				return $reports->history( $page, $per_page );
			}
		);
		if ( is_wp_error( $history ) ) {
			return $history;
		}

		$response = new WP_REST_Response( $history['items'] );
		$response->header( 'X-WP-Total', (string) $history['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $history['total'] / max( 1, $per_page ) ) );

		return $response;
	}

	/**
	 * One report.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$id = (int) $request['id'];

		$report = $this->read(
			static function ( AnalysisReports $reports ) use ( $id ) {
				return $reports->report( $id );
			}
		);
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		if ( null === $report ) {
			return new WP_Error(
				'updatelens_analysis_not_found',
				__( 'Analysis not found.', 'updatelens' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $report );
	}

	/**
	 * Query parameters of the history.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'page'     => array(
				'description'       => __( 'Page of the history (1-based).', 'updatelens' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page' => array(
				'description'       => __( 'Analyses per page.', 'updatelens' ),
				'type'              => 'integer',
				'default'           => AnalysisReports::DEFAULT_PER_PAGE,
				'minimum'           => 1,
				'maximum'           => AnalysisReports::MAX_PER_PAGE,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Report schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$common_reasons = array(
			UnavailableReason::UPDATE_IN_PROGRESS,
			UnavailableReason::AWAITING_SETTLE,
			UnavailableReason::SETTLE_EXPIRED,
			UnavailableReason::UPDATE_FAILED,
			UnavailableReason::ANALYSIS_FAILED,
			UnavailableReason::FINGERPRINT_CONTEXT_CHANGED,
			UnavailableReason::ANALYSIS_ABANDONED,
			UnavailableReason::DATA_CORRUPT,
			UnavailableReason::NOT_RECORDED,
		);

		$options = self::provider_schema(
			__( 'Observed wp_options changes.', 'updatelens' ),
			$common_reasons,
			array( 'added', 'removed', 'changed' )
		);
		$cron    = self::provider_schema(
			__( 'Observed WP-Cron changes. Arguments are never included.', 'updatelens' ),
			array_values( array_unique( array_merge( $common_reasons, AnalysisReadModel::CRON_REASONS, array( AnalysisReadModel::UNKNOWN ) ) ) ),
			array( 'added', 'removed', 'rescheduled', 'changed' )
		);
		$phase   = array(
			'type'       => 'object',
			'properties' => array(
				'options' => $options,
				'cron'    => $cron,
			),
		);

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'updatelens-analysis-report',
			'type'       => 'object',
			'properties' => self::metadata_schema() + array(
				'observation_window_seconds' => array(
					'description' => __( 'Length of the post-update observation window in seconds.', 'updatelens' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'phases'                     => array(
					'description' => __( 'Observed changes per phase and signal, each with its own availability (observations, not proven causes).', 'updatelens' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
					'properties'  => array(
						ObservationPhase::DURING_UPDATE => $phase,
						ObservationPhase::POST_UPDATE   => $phase,
						ObservationPhase::FINAL         => $phase,
					),
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * History item schema, for the collection route.
	 *
	 * @return array
	 */
	public function get_public_history_item_schema() {
		if ( null === $this->history_schema ) {
			$properties = self::metadata_schema();
			$signal     = array(
				'type'       => 'object',
				'properties' => array(
					'recorded'    => array(
						'description' => __( 'Whether a diff is stored for this phase and signal.', 'updatelens' ),
						'type'        => 'boolean',
					),
					'has_changes' => array(
						'description' => __( 'Whether the stored diff contains any added, removed, changed or rescheduled records; null if not recorded.', 'updatelens' ),
						'type'        => array( 'boolean', 'null' ),
					),
				),
			);
			$phases     = array();
			foreach ( array_keys( ObservationPhase::ASSOCIATION ) as $phase ) {
				$properties[ "has_{$phase}" ] = array(
					'description' => __( 'Whether a wp_options diff is stored for this phase.', 'updatelens' ),
					'type'        => 'boolean',
					'context'     => array( 'view' ),
					'readonly'    => true,
				);
				$phases[ $phase ]             = array(
					'type'       => 'object',
					'properties' => array(
						'options' => $signal,
						'cron'    => $signal,
					),
				);
			}
			$properties['phases'] = array(
				'description' => __( 'Per phase and signal: whether a diff is recorded and whether it has changes. Diffs are not decoded for the history.', 'updatelens' ),
				'type'        => 'object',
				'context'     => array( 'view' ),
				'readonly'    => true,
				'properties'  => $phases,
			);

			$this->history_schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'updatelens-analysis',
				'type'       => 'object',
				'properties' => $properties,
			);
		}

		return $this->history_schema;
	}

	/**
	 * Schema of the fields shared by history items and reports.
	 *
	 * @return array
	 */
	private static function metadata_schema() {
		$view = array(
			'context'  => array( 'view' ),
			'readonly' => true,
		);

		return array(
			'id'             => array( 'type' => 'integer' ) + $view,
			'plugin'         => array(
				'type'       => 'object',
				'properties' => array(
					'file'           => array(
						'description' => __( 'Plugin basename (e.g. dir/file.php); null if the stored value is invalid.', 'updatelens' ),
						'type'        => array( 'string', 'null' ),
					),
					'name'           => array( 'type' => 'string' ),
					'version_before' => array( 'type' => 'string' ),
					'version_after'  => array( 'type' => array( 'string', 'null' ) ),
				),
			) + $view,
			'status'         => array(
				'type' => 'string',
				'enum' => array_merge( AnalysisReadModel::STATUSES, array( AnalysisReadModel::UNKNOWN ) ),
			) + $view,
			'settle_outcome' => array(
				'type' => array( 'string', 'null' ),
				'enum' => array_merge( AnalysisReadModel::SETTLE_OUTCOMES, array( AnalysisReadModel::UNKNOWN, null ) ),
			) + $view,
			'timestamps'     => array(
				'description' => __( 'UTC ISO 8601 timestamps (e.g. 2026-10-05T17:46:23Z) or null.', 'updatelens' ),
				'type'        => 'object',
				'properties'  => array(
					'started_at'      => array( 'type' => array( 'string', 'null' ) ),
					'settle_deadline' => array( 'type' => array( 'string', 'null' ) ),
					'completed_at'    => array( 'type' => array( 'string', 'null' ) ),
				),
			) + $view,
			'error'          => array(
				'type'       => array( 'object', 'null' ),
				'properties' => array(
					'code' => array( 'type' => 'string' ),
				),
			) + $view,
		);
	}

	/**
	 * Run a read. Database errors go to the PHP error log only, never into the
	 * response; any failure becomes a generic error.
	 *
	 * @param callable $read Receives AnalysisReports.
	 * @return mixed|WP_Error
	 */
	private function read( callable $read ) {
		global $wpdb;

		$show_errors = $wpdb->hide_errors();
		try {
			return $read( call_user_func( $this->reports ) );
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
	 * Same access rule as the UpdateLens screen.
	 *
	 * @return true|WP_Error
	 */
	private function check_permission() {
		if ( current_user_can( Plugin::CAPABILITY ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to access UpdateLens.', 'updatelens' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
