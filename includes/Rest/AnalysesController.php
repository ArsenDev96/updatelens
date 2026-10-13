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
use UpdateLens\Report\PotentialImpact;
use UpdateLens\Report\ReportTooLargeException;
use UpdateLens\Report\SiteCheckReport;
use UpdateLens\Report\UnavailableReason;
use UpdateLens\SiteCheck\AfterReason;
use UpdateLens\SiteCheck\BeforeReason;
use UpdateLens\SiteCheck\ComparisonState;
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
	 * Schema of the Potential Impact evaluation of the Net result.
	 *
	 * @param string[] $cron_reasons             Possible WP-Cron reasons (superset of the Options reasons).
	 * @param string[] $action_scheduler_reasons Possible Action Scheduler reasons.
	 * @return array
	 */
	private static function potential_impact_schema( array $cron_reasons, array $action_scheduler_reasons ) {
		$statuses = array( PotentialImpact::EVALUATED, PotentialImpact::NOT_EVALUATED, PotentialImpact::NOT_APPLICABLE );
		$signals  = array();
		foreach ( PotentialImpact::RULES as $signal => $rules ) {
			$signals[ $signal ] = array(
				'type'       => 'object',
				'properties' => array(
					'status'        => array(
						'type' => 'string',
						'enum' => $statuses,
					),
					'reason'        => array(
						'description' => __( 'Why the signal was not evaluated: its Net result phase reason; null if evaluated.', 'updatelens' ),
						'type'        => array( 'string', 'null' ),
						'enum'        => array_merge( PotentialImpact::SIGNAL_ACTION_SCHEDULER === $signal ? $action_scheduler_reasons : $cron_reasons, array( null ) ),
					),
					'rules'         => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
							'enum' => $rules,
						),
					),
					'finding_count' => array( 'type' => array( 'integer', 'null' ) ),
				),
			);
		}

		return array(
			'description' => __( 'Review rules evaluated on the Net result: observations that may be worth checking, not confirmed problems or causes. Computed on every read, never stored.', 'updatelens' ),
			'type'        => 'object',
			'context'     => array( 'view' ),
			'readonly'    => true,
			'properties'  => array(
				'phase'    => array(
					'type' => 'string',
					'enum' => array( ObservationPhase::FINAL ),
				),
				'status'   => array(
					'type' => 'string',
					'enum' => array( PotentialImpact::EVALUATED, PotentialImpact::PARTIAL, PotentialImpact::NOT_EVALUATED ),
				),
				'signals'  => array(
					'type'       => 'object',
					'properties' => $signals,
				),
				'findings' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'code'     => array(
								'type' => 'string',
								'enum' => array( PotentialImpact::LARGE_AUTOLOADED_OPTION, PotentialImpact::RECURRING_CRON_EVENT_REMOVED, PotentialImpact::RECURRING_SCHEDULE_CHANGED ),
							),
							'signal'   => array(
								'type' => 'string',
								'enum' => array_keys( PotentialImpact::RULES ),
							),
							'option'   => array( 'type' => 'string' ),
							'hook'     => array( 'type' => 'string' ),
							'group'    => array( 'type' => 'string' ),
							'evidence' => array( 'type' => 'object' ),
							'before'   => array( 'type' => array( 'object', 'null' ) ),
							'after'    => array( 'type' => 'object' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Schema of a history item's site checks: the report's site check
	 * evaluation (`potential_impact.site_checks`) without findings.
	 *
	 * @return array
	 */
	private static function history_site_checks_schema() {
		$check = array(
			'type'       => 'object',
			'properties' => array(
				'status'        => array(
					'type' => 'string',
					'enum' => array( PotentialImpact::EVALUATED, PotentialImpact::PENDING, PotentialImpact::NOT_EVALUATED, PotentialImpact::NOT_APPLICABLE ),
				),
				'state'         => array(
					'type' => 'string',
					'enum' => SiteCheckReport::STATES,
				),
				'change'        => array( 'type' => array( 'string', 'null' ) ),
				'reason'        => array( 'type' => array( 'string', 'null' ) ),
				'finding_count' => array( 'type' => array( 'integer', 'null' ) ),
			),
		);

		return array(
			'description' => __( 'Homepage and REST API checks before and after the update, as evaluated in the report (without findings): browser observations, not proven causes. Computed on every read.', 'updatelens' ),
			'type'        => 'object',
			'context'     => array( 'view' ),
			'readonly'    => true,
			'properties'  => array(
				'status' => array(
					'type' => 'string',
					'enum' => array( PotentialImpact::EVALUATED, PotentialImpact::PARTIAL, PotentialImpact::PENDING, PotentialImpact::NOT_EVALUATED, PotentialImpact::NOT_APPLICABLE ),
				),
				'checks' => array(
					'type'       => 'object',
					'properties' => array(
						'home' => $check,
						'rest' => $check,
					),
				),
			),
		);
	}

	/**
	 * Schema of the site check comparison (SiteCheckReport).
	 *
	 * @return array
	 */
	private static function site_checks_schema() {
		$side    = static function ( array $reasons, $delay ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'available'    => array( 'type' => 'boolean' ),
					'reason'       => array(
						'type' => array( 'string', 'null' ),
						'enum' => array_merge( $reasons, array( null ) ),
					),
					'started_at'   => array(
						'type'   => array( 'string', 'null' ),
						'format' => 'date-time',
					),
					'completed_at' => array(
						'type'   => array( 'string', 'null' ),
						'format' => 'date-time',
					),
					'result'       => array(
						'description' => __( 'Verdict, its reason and the browser-observed facts of each attempt.', 'updatelens' ),
						'type'        => array( 'object', 'null' ),
					),
					$delay         => array( 'type' => array( 'integer', 'null' ) ),
				),
			);
		};
		$reasons = array(
			ComparisonState::BASELINE_MISSING,
			ComparisonState::AFTER_MISSING,
			ComparisonState::BOTH_MISSING,
			ComparisonState::QUEUED_UPDATE,
			ComparisonState::OVERLAPPING_UPDATE,
			ComparisonState::UPDATE_ORDER_UNKNOWN,
			ComparisonState::DEFINITION_CHANGED,
			ComparisonState::TARGET_CHANGED,
			ComparisonState::DIFFERENT_USER,
			ComparisonState::DIFFERENT_LOGIN_SESSION,
			ComparisonState::BASELINE_AFTER_UPDATE_START,
			ComparisonState::BASELINE_EXPIRED,
			ComparisonState::AFTER_DURING_UPDATE,
			ComparisonState::BEFORE_INCONCLUSIVE,
			ComparisonState::AFTER_INCONCLUSIVE,
			ComparisonState::REDIRECTED_BOTH,
		);
		$check   = array(
			'type'       => 'object',
			'properties' => array(
				'state'  => array(
					'type' => 'string',
					'enum' => SiteCheckReport::STATES,
				),
				'reason' => array(
					'description' => __( 'Why the check was not compared (not_run, not_comparable); null otherwise.', 'updatelens' ),
					'type'        => array( 'string', 'null' ),
					'enum'        => array_merge( $reasons, array( null ) ),
				),
				'before' => $side( array_merge( BeforeReason::STORED, array( BeforeReason::NOT_CAPTURED, BeforeReason::DATA_CORRUPT, BeforeReason::UNKNOWN ) ), 'age_seconds' ),
				'after'  => $side( AfterReason::ALL, 'delay_seconds' ),
			),
		);

		return array(
			'description' => __( 'Homepage and REST API checks before and after the update, compared per check: browser observations, not proven causes. Computed on every read.', 'updatelens' ),
			'type'        => 'object',
			'context'     => array( 'view' ),
			'readonly'    => true,
			'properties'  => array(
				'status' => array(
					'type' => 'string',
					'enum' => array( SiteCheckReport::PENDING, SiteCheckReport::COMPLETE, SiteCheckReport::PARTIAL, SiteCheckReport::UNAVAILABLE ),
				),
				'checks' => array(
					'type'       => 'object',
					'properties' => array(
						'home' => $check,
						'rest' => $check,
					),
				),
			),
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

		$options          = self::provider_schema(
			__( 'Observed wp_options changes.', 'updatelens' ),
			$common_reasons,
			array( 'added', 'removed', 'changed' )
		);
		$cron             = self::provider_schema(
			__( 'Observed WP-Cron changes. Arguments are never included.', 'updatelens' ),
			array_values( array_unique( array_merge( $common_reasons, AnalysisReadModel::CRON_REASONS, array( AnalysisReadModel::UNKNOWN ) ) ) ),
			array( 'added', 'removed', 'rescheduled', 'changed' )
		);
		$action_scheduler = self::provider_schema(
			__( 'Observed changes of active (pending or in-progress) Action Scheduler actions. Arguments are never included.', 'updatelens' ),
			array_values( array_unique( array_merge( $common_reasons, AnalysisReadModel::ACTION_SCHEDULER_REASONS, array( AnalysisReadModel::UNKNOWN ) ) ) ),
			array( 'added', 'removed', 'rescheduled', 'changed' )
		);
		$phase            = array(
			'type'       => 'object',
			'properties' => array(
				'options'          => $options,
				'cron'             => $cron,
				'action_scheduler' => $action_scheduler,
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
				'potential_impact'           => self::potential_impact_schema( $cron['properties']['reason']['enum'], $action_scheduler['properties']['reason']['enum'] ),
				'site_checks'                => self::site_checks_schema(),
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
						'options'          => $signal,
						'cron'             => $signal,
						'action_scheduler' => $signal,
					),
				);
			}
			$properties['phases']      = array(
				'description' => __( 'Per phase and signal: whether a diff is recorded and whether it has changes. Diffs are not decoded for the history.', 'updatelens' ),
				'type'        => 'object',
				'context'     => array( 'view' ),
				'readonly'    => true,
				'properties'  => $phases,
			);
			$properties['site_checks'] = self::history_site_checks_schema();

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
	 * response; any failure becomes a generic error. A report too large for
	 * the request's memory is refused before it is read
	 * (`updatelens_report_too_large`), never a fatal error.
	 *
	 * @param callable $read Receives AnalysisReports.
	 * @return mixed|WP_Error
	 */
	private function read( callable $read ) {
		global $wpdb;

		$show_errors = $wpdb->hide_errors();
		try {
			return $read( call_user_func( $this->reports ) );
		} catch ( ReportTooLargeException $e ) {
			return new WP_Error(
				'updatelens_report_too_large',
				__( 'This UpdateLens report is too large to load with the memory available.', 'updatelens' ),
				array( 'status' => 500 )
			);
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
