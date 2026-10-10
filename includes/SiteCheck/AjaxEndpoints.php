<?php
/**
 * Start and report endpoints for site checks.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Two admin-ajax actions for the browser check runner, registered only as
 * `wp_ajax_` (signed-in users; no public endpoint):
 *
 * - `updatelens_site_check_start` (`purpose`: before|after): opens one
 *   session per check and returns what the runner needs (per check its
 *   `session` marker, `secret` and `url`; for REST also `rest_nonce` and the
 *   expected `user`). The check URLs are
 *   built here from `home_url()` and `rest_url()`; no URL is ever taken
 *   from the request.
 * - `updatelens_site_check_report` (`session`, `secret`, `report`): accepts
 *   one run's attempts (ReportValidator format) once.
 *
 * Every request needs the `updatelens_site_check` nonce, `update_plugins`,
 * the opt-in (Settings) and a WordPress login session. Errors are
 * `{"success":false,"data":{"code":…}}` with a fixed code and HTTP status:
 * `invalid_nonce` 403, `forbidden` 403, `disabled` 403, `invalid_request`
 * 400, `invalid_report` 400 (+ `detail`), `rate_limited` 429 (+
 * `retry_after`), `not_owner` 403, `session_unavailable` 410,
 * `already_reported` 409, `unavailable` 503. Every response is marked
 * `no-store`. Nothing is logged; results are not stored yet.
 */
final class AjaxEndpoints {

	/**
	 * Actions.
	 */
	const START_ACTION  = 'updatelens_site_check_start';
	const REPORT_ACTION = 'updatelens_site_check_report';

	/**
	 * Nonce action (sent as `_ajax_nonce`).
	 */
	const NONCE_ACTION = 'updatelens_site_check';

	/**
	 * Capability required (the one that allows plugin updates).
	 */
	const CAPABILITY = 'update_plugins';

	/**
	 * HTTP status per error code.
	 */
	const STATUS = array(
		'invalid_nonce'                     => 403,
		'forbidden'                         => 403,
		'disabled'                          => 403,
		SessionManager::INVALID_REQUEST     => 400,
		SessionManager::INVALID_REPORT      => 400,
		SessionManager::RATE_LIMITED        => 429,
		SessionManager::NOT_OWNER           => 403,
		SessionManager::SESSION_UNAVAILABLE => 410,
		SessionManager::ALREADY_REPORTED    => 409,
		SessionManager::UNAVAILABLE         => 503,
	);

	/**
	 * Sessions.
	 *
	 * @var SessionManager
	 */
	private $manager;

	/**
	 * Context fingerprints (created on first use: needs the salts).
	 *
	 * @var ContextHasher|null
	 */
	private $hasher;

	/**
	 * Constructor.
	 *
	 * @param SessionManager     $manager Sessions.
	 * @param ContextHasher|null $hasher  Context fingerprints (default: keyed with the site's salts).
	 */
	public function __construct( SessionManager $manager, ?ContextHasher $hasher = null ) {
		$this->manager = $manager;
		$this->hasher  = $hasher;
	}

	/**
	 * Register the actions (logged-in only).
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_' . self::START_ACTION, array( $this, 'on_start' ) );
		add_action( 'wp_ajax_' . self::REPORT_ACTION, array( $this, 'on_report' ) );
	}

	/**
	 * Start a check run.
	 *
	 * @return void
	 */
	public function on_start() {
		$login = $this->authorize();

		try {
			$home    = home_url( '/' );
			$rest    = add_query_arg( '_fields', CheckDefinition::REST_FIELDS, rest_url( ltrim( CheckDefinition::REST_ROUTE, '/' ) ) );
			$targets = array(
				CheckDefinition::HOME => $this->hasher()->target( $home ),
				CheckDefinition::REST => $this->hasher()->target( $rest ),
			);
		} catch ( InvalidArgumentException $e ) {
			self::error( SessionManager::UNAVAILABLE );
			return;
		}

		$outcome = $this->manager->start( get_current_user_id(), $login, self::input( 'purpose' ), $targets );
		if ( SessionManager::STARTED !== $outcome['code'] ) {
			self::error( $outcome['code'], isset( $outcome['retry_after'] ) ? array( 'retry_after' => $outcome['retry_after'] ) : array() );
			return;
		}

		$checks = array();
		foreach ( $outcome['sessions'] as $check => $session ) {
			$entry = array(
				'check'      => $check,
				'definition' => $session['definition'],
				'session'    => $session['session'],
				'secret'     => $session['secret'],
			);
			if ( CheckDefinition::HOME === $check ) {
				$entry['url'] = add_query_arg( FrontendMarker::QUERY_ARG, $session['session'], $home );
			} else {
				$entry['url']        = $rest;
				$entry['rest_nonce'] = wp_create_nonce( 'wp_rest' );
				// The identity the REST response must name (the runner retries
				// otherwise; the report is still checked against the session).
				$entry['user'] = get_current_user_id();
			}
			$checks[] = $entry;
		}

		wp_send_json_success(
			array(
				'code'                 => SessionManager::STARTED,
				'purpose'              => $outcome['purpose'],
				'expires_in'           => $outcome['expires_in'],
				'timeout_ms'           => CheckDefinition::TIMEOUT_MS,
				'retry_delay_ms'       => CheckDefinition::RETRY_DELAY_MS,
				'max_attempts'         => CheckDefinition::MAX_ATTEMPTS,
				'header'               => FrontendMarker::HEADER,
				'end_marker_attribute' => FrontendMarker::END_MARKER_ATTRIBUTE,
				'checks'               => $checks,
			)
		);
	}

	/**
	 * Accept a check run's report.
	 *
	 * @return void
	 */
	public function on_report() {
		$login   = $this->authorize();
		$outcome = $this->manager->report( get_current_user_id(), $login, self::input( 'session' ), self::input( 'secret' ), self::input( 'report' ) );

		if ( SessionManager::ACCEPTED !== $outcome['code'] ) {
			self::error( $outcome['code'], isset( $outcome['detail'] ) ? array( 'detail' => $outcome['detail'] ) : array() );
			return;
		}

		$result = $outcome['result'];
		wp_send_json_success(
			array(
				'code'    => SessionManager::ACCEPTED,
				'check'   => $outcome['check'],
				'purpose' => $outcome['purpose'],
				'verdict' => $result->get_verdict(),
				'reason'  => $result->get_reason(),
			)
		);
	}

	/**
	 * Nonce, capability, opt-in and login session; ends the request with an
	 * error when one is missing.
	 *
	 * @return string Login-session fingerprint.
	 */
	private function authorize() {
		// admin-ajax sends no-cache headers again when the response ends; the
		// filter keeps no-store, private there (WordPress 6.2 omits both).
		add_filter( 'nocache_headers', array( FrontendMarker::class, 'no_store_headers' ) );
		if ( ! headers_sent() ) {
			header( 'Cache-Control: ' . FrontendMarker::CACHE_CONTROL );
		}
		if ( false === check_ajax_referer( self::NONCE_ACTION, false, false ) ) {
			self::error( 'invalid_nonce' );
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			self::error( 'forbidden' );
		}
		if ( ! Settings::is_enabled() ) {
			self::error( 'disabled' );
		}
		$token = wp_get_session_token();
		if ( ! is_string( $token ) || '' === $token ) {
			self::error( 'forbidden' );
		}

		return $this->hasher()->session( $token );
	}

	/**
	 * A request value as an unslashed string, or null.
	 *
	 * Values are validated strictly by SessionManager / ReportValidator
	 * (formats, sizes, enums), never echoed and never stored.
	 *
	 * @param string $key Key.
	 * @return string|null
	 */
	private static function input( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorize() before any input is read.
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in authorize(); validated strictly, never output or stored.
		return wp_unslash( $_POST[ $key ] );
	}

	/**
	 * Send a fixed error code and end the request.
	 *
	 * @param string $code  Error code (key of STATUS).
	 * @param array  $extra Fixed extra fields (`retry_after`, `detail`).
	 * @return void
	 */
	private static function error( $code, array $extra = array() ) {
		wp_send_json_error( array( 'code' => $code ) + $extra, isset( self::STATUS[ $code ] ) ? self::STATUS[ $code ] : 500 );
	}

	/**
	 * Context fingerprints.
	 *
	 * @return ContextHasher
	 */
	private function hasher() {
		if ( null === $this->hasher ) {
			$this->hasher = ContextHasher::from_wordpress();
		}

		return $this->hasher;
	}
}
