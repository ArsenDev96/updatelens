<?php
/**
 * Issuing, finding and completing site check sessions.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use InvalidArgumentException;
use Throwable;
use UpdateLens\Storage\SiteCheckSessionCodec;

defined( 'ABSPATH' ) || exit;

/**
 * Session rules, independent of WordPress (storage, clock, randomness and
 * the rate limiter are injected; AjaxEndpoints and FrontendMarker are the
 * WordPress side).
 *
 * Lifecycle of one check run:
 *
 * 1. start(): for the signed-in owner, one session per requested check,
 *    each with its own marker and secret, live for CheckSession::TTL
 *    seconds. Counts against the owner's RateLimiter.
 * 2. find_live(): front-end and REST requests carrying a well-formed marker
 *    look the session up (read-only). Only a live session of that check
 *    turns on the verification marker.
 * 3. report(): the owner's browser submits the attempts once, with the
 *    secret. The session is claimed (removed) before the result is built,
 *    so replays and concurrent submissions are rejected; an invalid report
 *    does not use the session up.
 *
 * Any storage problem (eviction, unreadable record, failed write) makes a
 * session unavailable: no marker, no accepted result, never a pass.
 *
 * Outcomes are arrays with a fixed `code` (class constants); they never
 * contain the secret, the stored record or submitted values.
 */
final class SessionManager {

	/**
	 * Outcome codes.
	 */
	const STARTED             = 'started';
	const ACCEPTED            = 'accepted';
	const INVALID_REQUEST     = 'invalid_request';
	const INVALID_REPORT      = 'invalid_report';
	const RATE_LIMITED        = 'rate_limited';
	const SESSION_UNAVAILABLE = 'session_unavailable';
	const NOT_OWNER           = 'not_owner';
	const ALREADY_REPORTED    = 'already_reported';
	const UNAVAILABLE         = 'unavailable';

	/**
	 * Session storage.
	 *
	 * @var SessionStore
	 */
	private $store;

	/**
	 * Clock: function (): float (Unix seconds).
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Random source: function ( int $length ): string (bytes).
	 *
	 * @var callable
	 */
	private $random;

	/**
	 * Per-user start limit.
	 *
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * Session codec.
	 *
	 * @var SiteCheckSessionCodec
	 */
	private $codec;

	/**
	 * Constructor.
	 *
	 * @param SessionStore $store   Session storage.
	 * @param callable     $now     Clock (Unix seconds as float).
	 * @param callable     $random  Cryptographically secure random bytes (`random_bytes`).
	 * @param RateLimiter  $limiter Per-user start limit.
	 */
	public function __construct( SessionStore $store, callable $now, callable $random, RateLimiter $limiter ) {
		$this->store   = $store;
		$this->now     = $now;
		$this->random  = $random;
		$this->limiter = $limiter;
		$this->codec   = new SiteCheckSessionCodec();
	}

	/**
	 * Manager on transients, `microtime( true )`, `random_bytes()` and user meta.
	 *
	 * @return self
	 */
	public static function from_wordpress() {
		return new self(
			new TransientSessionStore(),
			static function () {
				return microtime( true );
			},
			'random_bytes',
			RateLimiter::from_wordpress()
		);
	}

	/**
	 * Open one session per requested check for the owner.
	 *
	 * @param int                   $user    Owner's user ID.
	 * @param string                $login   Owner's login-session fingerprint.
	 * @param mixed                 $purpose CheckSession purpose (request input).
	 * @param array<string, string> $targets Target fingerprint per check to open (one or both
	 *                                       checks; a BEFORE start opens only the due ones).
	 * @param string|null           $generation Updater activity generation now (UpdateActivity::quiet_generation()), or null.
	 * @return array `code` STARTED with `purpose`, `expires_in` and `sessions`
	 *               (per check, in CheckDefinition order: `definition`,
	 *               `session` = marker, `secret`); RATE_LIMITED with
	 *               `retry_after`; INVALID_REQUEST or UNAVAILABLE.
	 */
	public function start( $user, $login, $purpose, array $targets, $generation = null ) {
		if ( ! in_array( $purpose, CheckSession::PURPOSES, true ) ) {
			return array( 'code' => self::INVALID_REQUEST );
		}
		$definitions = array();
		foreach ( CheckDefinition::all() as $definition ) {
			if ( isset( $targets[ $definition->get_id() ] ) ) {
				$definitions[] = $definition;
			}
		}
		if ( array() === $definitions || count( $definitions ) !== count( $targets ) ) {
			return array( 'code' => self::INVALID_REQUEST );
		}

		$now   = (float) call_user_func( $this->now );
		$retry = $this->limiter->consume( $user, $now );
		if ( $retry > 0 ) {
			return array(
				'code'        => self::RATE_LIMITED,
				'retry_after' => $retry,
			);
		}

		$sessions = array();
		$stored   = array();
		try {
			foreach ( $definitions as $definition ) {
				$marker  = bin2hex( call_user_func( $this->random, CheckSession::MARKER_BYTES ) );
				$secret  = bin2hex( call_user_func( $this->random, CheckSession::SECRET_BYTES ) );
				$session = CheckSession::from_array(
					array(
						'check'       => $definition->get_id(),
						'definition'  => $definition->get_version(),
						'purpose'     => $purpose,
						'user'        => $user,
						'login'       => $login,
						'target'      => $targets[ $definition->get_id() ],
						'secret_hash' => CheckSession::hash_secret( $secret ),
						'created_at'  => $now,
						'expires_at'  => $now + CheckSession::TTL,
						'generation'  => $generation,
					)
				);
				$key     = CheckSession::storage_key( $marker );
				if ( ! CheckSession::is_marker( $marker ) || ! CheckSession::is_secret( $secret ) || ! $this->store->put( $key, $this->codec->encode( $session ), CheckSession::TTL ) ) {
					// A broken random source, or the session was not stored.
					$this->discard( $stored );
					return array( 'code' => self::UNAVAILABLE );
				}
				$stored[]                          = $key;
				$sessions[ $definition->get_id() ] = array(
					'definition' => $definition->get_version(),
					'session'    => $marker,
					'secret'     => $secret,
				);
			}
		} catch ( InvalidArgumentException $e ) {
			$this->discard( $stored );
			return array( 'code' => self::INVALID_REQUEST );
		} catch ( Throwable $e ) {
			// random_bytes() or encoding failures.
			$this->discard( $stored );
			return array( 'code' => self::UNAVAILABLE );
		}

		return array(
			'code'       => self::STARTED,
			'purpose'    => $purpose,
			'expires_in' => CheckSession::TTL,
			'sessions'   => $sessions,
		);
	}

	/**
	 * The live session of a check for a marker, for front-end and REST
	 * requests. Read-only and never throws: a malformed marker returns null
	 * before any lookup; a missing, unreadable, expired or other-check
	 * session is null.
	 *
	 * @param string $check  Check identifier.
	 * @param mixed  $marker Marker from the request.
	 * @return CheckSession|null
	 */
	public function find_live( $check, $marker ) {
		if ( ! CheckSession::is_marker( $marker ) ) {
			return null;
		}
		$session = $this->load( $marker );
		if ( null === $session || $session->get_check() !== $check || $session->is_expired( (float) call_user_func( $this->now ) ) ) {
			return null;
		}

		return $session;
	}

	/**
	 * Accept the owner's one report of a session.
	 *
	 * Order: token format → session found and secret matches (else
	 * SESSION_UNAVAILABLE, without telling which) → owner and login session
	 * (NOT_OWNER) → not expired, same definition version → report valid
	 * (INVALID_REPORT, session kept) → claim (ALREADY_REPORTED if another
	 * submission won) → result.
	 *
	 * @param int    $user   Current user ID.
	 * @param string $login  Current login-session fingerprint.
	 * @param mixed  $marker Session marker (request input).
	 * @param mixed  $secret Session secret (request input).
	 * @param mixed  $report Report JSON (request input, see ReportValidator).
	 * @return array `code` ACCEPTED with `check`, `purpose` and `result`
	 *               (CheckResult, not for output); INVALID_REPORT with
	 *               `detail` (InvalidCheckReportException reason);
	 *               INVALID_REQUEST, SESSION_UNAVAILABLE, NOT_OWNER or
	 *               ALREADY_REPORTED.
	 */
	public function report( $user, $login, $marker, $secret, $report ) {
		if ( ! CheckSession::is_marker( $marker ) || ! CheckSession::is_secret( $secret ) ) {
			return array( 'code' => self::INVALID_REQUEST );
		}
		$session = $this->load( $marker );
		if ( null === $session || ! $session->matches_secret( $secret ) ) {
			return array( 'code' => self::SESSION_UNAVAILABLE );
		}
		if ( $session->get_user() !== $user || ! hash_equals( $session->get_login(), (string) $login ) ) {
			return array( 'code' => self::NOT_OWNER );
		}
		$now        = (float) call_user_func( $this->now );
		$definition = CheckDefinition::get( $session->get_check() );
		if ( $session->is_expired( $now ) || $session->get_definition_version() !== $definition->get_version() ) {
			return array( 'code' => self::SESSION_UNAVAILABLE );
		}

		try {
			$attempts = ReportValidator::parse( $report, $definition, $user );
		} catch ( InvalidCheckReportException $e ) {
			return array(
				'code'   => self::INVALID_REPORT,
				'detail' => $e->get_reason(),
			);
		}

		if ( ! $this->store->claim( CheckSession::storage_key( $marker ) ) ) {
			return array( 'code' => self::ALREADY_REPORTED );
		}

		return array(
			'code'    => self::ACCEPTED,
			'check'   => $session->get_check(),
			'purpose' => $session->get_purpose(),
			'result'  => new CheckResult(
				$session->get_check(),
				$session->get_definition_version(),
				new CheckContext( $session->get_target(), $definition->get_auth(), $session->get_user(), $session->get_login() ),
				$session->get_created_at(),
				max( $now, $session->get_created_at() ),
				$attempts,
				$session->get_generation()
			),
		);
	}

	/**
	 * The stored session for a marker, or null if missing or unreadable.
	 *
	 * @param string $marker Well-formed marker.
	 * @return CheckSession|null
	 */
	private function load( $marker ) {
		try {
			$stored = $this->store->get( CheckSession::storage_key( $marker ) );
			return null === $stored ? null : $this->codec->decode( $stored );
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Remove sessions stored by a start that failed.
	 *
	 * @param string[] $keys Storage keys.
	 * @return void
	 */
	private function discard( array $keys ) {
		foreach ( $keys as $key ) {
			$this->store->claim( $key );
		}
	}
}
