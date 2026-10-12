<?php
/**
 * Start and report endpoints for site checks.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use InvalidArgumentException;
use UpdateLens\Update\SiteCheckAfter;

defined( 'ABSPATH' ) || exit;

/**
 * Two admin-ajax actions for the browser check runner, registered only as
 * `wp_ajax_` (signed-in users; no public endpoint):
 *
 * - `updatelens_site_check_start` (`purpose`: before|after; after also
 *   `analysis`): opens one session per check and returns what the runner needs (per check its
 *   `session` marker, `secret` and `url`; for REST also `rest_nonce` and the
 *   expected `user`). The check URLs are
 *   built here from `home_url()` and `rest_url()`; no URL is ever taken
 *   from the request.
 * - `updatelens_site_check_report` (`session`, `secret`, `report`): accepts
 *   one run's attempts (ReportValidator format) once.
 *
 * BEFORE runs keep baselines (Baselines). A `before` start opens sessions
 * only for the checks whose baseline is due and whose target is on
 * wp-admin's origin (TargetOrigin), only while a plugin other than
 * UpdateLens has an update available and only while no update is running;
 * otherwise it succeeds with `code` `not_needed` (`reason` `fresh`,
 * `no_updates` or `update_in_progress`), opens nothing and
 * does not count against the rate limit. `before` responses carry
 * `refresh_in` (seconds per check until its baseline is due;
 * UNSUPPORTED_REFRESH_IN for a check on another origin). An accepted
 * `before` report is stored as its check's baseline (`baseline`: `saved`,
 * or `superseded` when a run that started later is already stored); if it
 * cannot be stored, the report fails with `unavailable`.
 *
 * AFTER runs belong to one analysis (`analysis`, its ID, from the updating
 * browser's follow-up response). SiteCheckAfter (Update) decides: only the
 * updating administrator, from the login session of its BEFORE baselines,
 * shortly after the follow-up settled the analysis, once, and only for the
 * checks with a BEFORE result; when updater activity happened since the
 * update ended it answers `not_needed` (`reason` `overlapping_update` or
 * `update_order_unknown`, stored for the analysis), without a baseline
 * `not_needed`/`no_baseline`. Errors: `not_eligible` 409 (not settled by
 * the follow-up, too late, not found), `already_started` 409, `not_owner`
 * 403. An accepted `after` report is stored in its analysis (`after`:
 * `saved`), once; a second one fails with `already_reported`.
 *
 * Every request needs the `updatelens_site_check` nonce, `update_plugins`,
 * the opt-in (Settings) and a WordPress login session. Errors are
 * `{"success":false,"data":{"code":…}}` with a fixed code and HTTP status:
 * `invalid_nonce` 403, `forbidden` 403, `disabled` 403, `invalid_request`
 * 400, `invalid_report` 400 (+ `detail`), `rate_limited` 429 (+
 * `retry_after`), `not_owner` 403, `session_unavailable` 410,
 * `already_reported` 409, `not_eligible` 409, `already_started` 409,
 * `unavailable` 503. Every response is marked `no-store`. Nothing is
 * logged.
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
	 * `refresh_in` of a check whose target is on another origin: it never
	 * becomes due, so this is the longest delay the browser accepts
	 * (`REFRESH_IN_BOUNDS` in `src/admin/site-check/contract.ts`).
	 */
	const UNSUPPORTED_REFRESH_IN = 3600;

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
		SiteCheckAfter::NOT_ELIGIBLE        => 409,
		SiteCheckAfter::ALREADY_STARTED     => 409,
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
	 * Baselines (created on first use).
	 *
	 * @var Baselines|null
	 */
	private $baselines;

	/**
	 * Whether a plugin other than UpdateLens has an update: function (): bool.
	 *
	 * @var callable
	 */
	private $has_updates;

	/**
	 * Updater activity ledger (created on first use).
	 *
	 * @var UpdateActivity|null
	 */
	private $activity;

	/**
	 * AFTER runs of analyses (created on first use).
	 *
	 * @var SiteCheckAfter|null
	 */
	private $after;

	/**
	 * Constructor.
	 *
	 * @param SessionManager      $manager     Sessions.
	 * @param ContextHasher|null  $hasher      Context fingerprints (default: keyed with the site's salts).
	 * @param Baselines|null      $baselines   Baselines (default: options table).
	 * @param callable|null       $has_updates Update availability (default: BaselinePage::has_relevant_updates()).
	 * @param UpdateActivity|null $activity    Updater activity ledger (default: options table).
	 * @param SiteCheckAfter|null $after       AFTER runs (default: analyses table and ledger).
	 */
	public function __construct( SessionManager $manager, ?ContextHasher $hasher = null, ?Baselines $baselines = null, ?callable $has_updates = null, ?UpdateActivity $activity = null, ?SiteCheckAfter $after = null ) {
		$this->manager     = $manager;
		$this->hasher      = $hasher;
		$this->baselines   = $baselines;
		$this->has_updates = null === $has_updates ? array( BaselinePage::class, 'has_relevant_updates' ) : $has_updates;
		$this->activity    = $activity;
		$this->after       = $after;
	}

	/**
	 * The check URLs, built from `home_url()` and `rest_url()` only.
	 *
	 * @return array<string, string> URL per check identifier.
	 */
	public static function target_urls() {
		return array(
			CheckDefinition::HOME => home_url( '/' ),
			CheckDefinition::REST => add_query_arg( '_fields', CheckDefinition::REST_FIELDS, rest_url( ltrim( CheckDefinition::REST_ROUTE, '/' ) ) ),
		);
	}

	/**
	 * Checks whose target is on another origin than wp-admin (TargetOrigin):
	 * the browser cannot run them from this admin page.
	 *
	 * @param array<string, string> $urls Target URL per check (target_urls()).
	 * @return string[] Check identifiers.
	 */
	public static function unsupported_checks( array $urls ) {
		return TargetOrigin::unsupported( $urls, admin_url( 'admin-ajax.php' ) );
	}

	/**
	 * `refresh_in` with every check that cannot run from this admin page set
	 * to UNSUPPORTED_REFRESH_IN, so the browser does not ask for it again.
	 *
	 * @param array<string, int> $refresh_in  Seconds per check.
	 * @param string[]           $unsupported Checks on another origin.
	 * @return array<string, int>
	 */
	public static function without_unsupported( array $refresh_in, array $unsupported ) {
		foreach ( $unsupported as $check ) {
			$refresh_in[ $check ] = self::UNSUPPORTED_REFRESH_IN;
		}

		return $refresh_in;
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
			$urls    = self::target_urls();
			$targets = array_map( array( $this->hasher(), 'target' ), $urls );
		} catch ( InvalidArgumentException $e ) {
			self::error( SessionManager::UNAVAILABLE );
			return;
		}

		$user       = get_current_user_id();
		$purpose    = self::input( 'purpose' );
		$refresh_in = null;
		$generation = null; // Recorded in each session: ties the result to the updates around it.
		$analysis   = null;
		if ( CheckSession::PURPOSE_AFTER === $purpose ) {
			$analysis = self::analysis_input();
			if ( null === $analysis ) {
				self::error( SessionManager::INVALID_REQUEST );
				return;
			}
			$opened = $this->after()->open( $analysis, $user, $login );
			if ( SiteCheckAfter::NOT_NEEDED === $opened['code'] ) {
				self::not_needed( $opened['reason'], null, CheckSession::PURPOSE_AFTER );
				return;
			}
			if ( SiteCheckAfter::OPEN !== $opened['code'] ) {
				self::error( self::after_error( $opened['code'] ) );
				return;
			}
			$generation = $opened['generation'];
			$targets    = array_intersect_key( $targets, array_flip( $opened['checks'] ) );
		} elseif ( CheckSession::PURPOSE_BEFORE === $purpose ) {
			$generation  = $this->activity()->quiet_generation();
			$unsupported = self::unsupported_checks( $urls );
			if ( ! call_user_func( $this->has_updates ) ) {
				self::not_needed( 'no_updates' );
				return;
			}
			if ( null === $generation ) {
				// An update is running (or the order is unknown): a baseline taken
				// now could never be attached, so none is started.
				self::not_needed( 'update_in_progress', self::without_unsupported( array_fill_keys( array_keys( CheckDefinition::VERSIONS ), Baselines::RETRY_AFTER_FAILURE_SECONDS ), $unsupported ) );
				return;
			}
			$readiness  = $this->baselines()->readiness( $user, $login, $targets, $generation );
			$refresh_in = self::without_unsupported( Baselines::refresh_in( $readiness ), $unsupported );
			// A check on another origin is never due: no session, no rate limit.
			$due = array_values( array_diff( Baselines::due( $readiness ), $unsupported ) );
			if ( array() === $due ) {
				self::not_needed( 'fresh', $refresh_in );
				return;
			}
			$targets = array_intersect_key( $targets, array_flip( $due ) );
		}

		$outcome = $this->manager->start( $user, $login, $purpose, $targets, $generation, $analysis );
		if ( SessionManager::STARTED !== $outcome['code'] ) {
			if ( null !== $analysis ) {
				// The run was claimed but cannot happen: keep why, for the checks it was for.
				$this->after()->open_failed( $analysis, $user, array_keys( $targets ), SessionManager::RATE_LIMITED === $outcome['code'] ? AfterReason::RATE_LIMITED : AfterReason::SESSION_FAILED );
			}
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
				$entry['url'] = add_query_arg( FrontendMarker::QUERY_ARG, $session['session'], $urls[ $check ] );
			} else {
				$entry['url']        = $urls[ $check ];
				$entry['rest_nonce'] = wp_create_nonce( 'wp_rest' );
				// The identity the REST response must name (the runner retries
				// otherwise; the report is still checked against the session).
				$entry['user'] = $user;
			}
			$checks[] = $entry;
		}

		$data = array(
			'code'                 => SessionManager::STARTED,
			'purpose'              => $outcome['purpose'],
			'expires_in'           => $outcome['expires_in'],
			'timeout_ms'           => CheckDefinition::TIMEOUT_MS,
			'retry_delay_ms'       => CheckDefinition::RETRY_DELAY_MS,
			'max_attempts'         => CheckDefinition::MAX_ATTEMPTS,
			'header'               => FrontendMarker::HEADER,
			'end_marker_attribute' => FrontendMarker::END_MARKER_ATTRIBUTE,
			'checks'               => $checks,
		);
		if ( null !== $refresh_in ) {
			$data['refresh_in'] = $refresh_in;
		}
		wp_send_json_success( $data );
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
		$data   = array(
			'code'    => SessionManager::ACCEPTED,
			'check'   => $outcome['check'],
			'purpose' => $outcome['purpose'],
			'verdict' => $result->get_verdict(),
			'reason'  => $result->get_reason(),
		);
		if ( CheckSession::PURPOSE_BEFORE === $outcome['purpose'] ) {
			$saved = $this->baselines()->save( $result );
			if ( Baselines::FAILED === $saved ) {
				// The session is used up: a BEFORE run that is not kept is no baseline.
				self::error( SessionManager::UNAVAILABLE );
				return;
			}
			$data['baseline'] = $saved;
		} elseif ( null !== $outcome['analysis'] ) {
			$recorded = $this->after()->record( (int) $outcome['analysis'], get_current_user_id(), $result );
			if ( SiteCheckAfter::SAVED !== $recorded ) {
				// The session is used up either way; nothing was overwritten.
				self::error( SiteCheckAfter::CONFLICT === $recorded ? SessionManager::ALREADY_REPORTED : SessionManager::UNAVAILABLE );
				return;
			}
			$data['after'] = $recorded;
		}
		wp_send_json_success( $data );
	}

	/**
	 * Answer a start that needs no run.
	 *
	 * @param string                  $reason     `before`: `fresh`, `no_updates` or `update_in_progress`;
	 *                                            `after`: `no_baseline`, `overlapping_update` or `update_order_unknown`.
	 * @param array<string, int>|null $refresh_in Seconds until each check is due (`before`, not with `no_updates`).
	 * @param string                  $purpose    CheckSession purpose.
	 * @return void
	 */
	private static function not_needed( $reason, ?array $refresh_in = null, $purpose = CheckSession::PURPOSE_BEFORE ) {
		$data = array(
			'code'    => 'not_needed',
			'purpose' => $purpose,
			'reason'  => $reason,
		);
		if ( null !== $refresh_in ) {
			$data['refresh_in'] = $refresh_in;
		}
		wp_send_json_success( $data );
	}

	/**
	 * Nonce, capability, opt-in and login session; ends the request with an
	 * error when one is missing.
	 *
	 * @return string Login-session fingerprint.
	 */
	private function authorize() {
		FrontendMarker::send_no_store();
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
	 * The `analysis` request value as a positive integer, or null.
	 *
	 * @return int|null
	 */
	private static function analysis_input() {
		$value = self::input( 'analysis' );
		if ( null === $value || 1 !== preg_match( '/\A[1-9][0-9]{0,17}\z/', $value ) ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Error code of a refused AFTER start.
	 *
	 * @param string $code SiteCheckAfter::open() code.
	 * @return string
	 */
	private static function after_error( $code ) {
		$errors = array(
			SiteCheckAfter::NOT_ELIGIBLE    => SiteCheckAfter::NOT_ELIGIBLE,
			SiteCheckAfter::ALREADY_STARTED => SiteCheckAfter::ALREADY_STARTED,
			SiteCheckAfter::NOT_OWNER       => SessionManager::NOT_OWNER,
		);

		return isset( $errors[ $code ] ) ? $errors[ $code ] : SessionManager::UNAVAILABLE;
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
	 * Updater activity ledger.
	 *
	 * @return UpdateActivity
	 */
	private function activity() {
		if ( null === $this->activity ) {
			$this->activity = UpdateActivity::from_wordpress();
		}

		return $this->activity;
	}

	/**
	 * AFTER runs.
	 *
	 * @return SiteCheckAfter
	 */
	private function after() {
		if ( null === $this->after ) {
			$this->after = SiteCheckAfter::from_wordpress();
		}

		return $this->after;
	}

	/**
	 * Baselines.
	 *
	 * @return Baselines
	 */
	private function baselines() {
		if ( null === $this->baselines ) {
			$this->baselines = Baselines::from_wordpress();
		}

		return $this->baselines;
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
