<?php
/**
 * AFTER site checks of a plugin update analysis.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

use Throwable;
use UpdateLens\SiteCheck\AfterReason;
use UpdateLens\SiteCheck\BeforeReason;
use UpdateLens\SiteCheck\CheckResult;
use UpdateLens\SiteCheck\CheckSession;
use UpdateLens\SiteCheck\UpdateActivity;
use UpdateLens\Storage\AnalysisRepository;
use UpdateLens\Storage\SiteCheckResultCodec;

defined( 'ABSPATH' ) || exit;

/**
 * The AFTER side of an analysis's site checks: when the updating browser may
 * run them, which results are stored, and how a stored analysis reads.
 *
 * Lifecycle (one run per analysis, never repeated):
 *
 * 1. The analysis completed through the updating browser's follow-up
 *    request (`settle_outcome` `follow_up`: the settled snapshot is stored;
 *    the update succeeded). The follow-up response names the analysis
 *    (PluginUpdateTracker) and whether AFTER checks are wanted (wanted()).
 * 2. open(): the browser asks to start. Allowed only for the administrator
 *    who ran the update, from the WordPress login session of the attached
 *    BEFORE baselines, within START_WINDOW_SECONDS of the completion, and
 *    only for the checks that have a BEFORE result (others: `no_baseline`).
 *    The run is claimed once (compare-and-set on
 *    `site_check_after_started_at`), so a repeated, replayed or concurrent
 *    start gets `already_started`.
 * 3. record(): each check's accepted result (single-use session bound to
 *    this analysis, user, login session, check and target) is stored once,
 *    guarded, independently of the other check.
 *
 * **Overlap protection** uses the updater activity ledger, never the
 * browser's word. This update moved the ledger from the generation of its
 * BEFORE results (G) to G + 2 (its begin and end, UpdateActivity). The
 * start must find exactly G + 2 with nothing running, and each result's
 * report must still find it: anything else means some update began or
 * ended after this one (another plugin or administrator, cron, WP-CLI,
 * themes, core, translations), possibly while a check request was in
 * flight. Such a run is stored as `overlapping_update` (or
 * `update_order_unknown` when the ledger was recreated or unreadable): its
 * facts are kept, it is never compared. Changes outside the WordPress
 * upgrader cannot be seen.
 *
 * Unavailability is never written later and never guessed: a check
 * without a stored result or reason reads as pending while it can still
 * arrive, and as `not_started` / `not_reported` (etc., see AfterReason)
 * once it cannot.
 */
final class SiteCheckAfter {

	/**
	 * Seconds after the analysis completed (settled) within which the AFTER
	 * run may start.
	 */
	const START_WINDOW_SECONDS = 60;

	/**
	 * Seconds beyond a session's lifetime before an unreported check reads
	 * as `not_reported` (a report accepted at the last moment is stored a
	 * little after the session's end).
	 */
	const REPORT_GRACE_SECONDS = 5;

	/**
	 * Columns per check: the AFTER result and the reason.
	 */
	const COLUMNS = array(
		'home' => array(
			'result' => 'site_check_home_after',
			'reason' => 'site_check_home_after_reason',
		),
		'rest' => array(
			'result' => 'site_check_rest_after',
			'reason' => 'site_check_rest_after_reason',
		),
	);

	/**
	 * When the AFTER run was claimed.
	 */
	const STARTED_AT = 'site_check_after_started_at';

	/**
	 * Outcomes of open().
	 */
	const OPEN            = 'open';
	const NOT_NEEDED      = 'not_needed';
	const NOT_ELIGIBLE    = 'not_eligible';
	const NOT_OWNER       = 'not_owner';
	const ALREADY_STARTED = 'already_started';
	const UNAVAILABLE     = 'unavailable';

	/**
	 * Outcomes of record().
	 */
	const SAVED    = 'saved';
	const CONFLICT = 'conflict';
	const FAILED   = 'failed';

	/**
	 * Analysis storage.
	 *
	 * @var AnalysisRepository
	 */
	private $repository;

	/**
	 * Updater activity ledger.
	 *
	 * @var UpdateActivity
	 */
	private $activity;

	/**
	 * Clock: function (): float (Unix seconds).
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Codec.
	 *
	 * @var SiteCheckResultCodec
	 */
	private $codec;

	/**
	 * Constructor.
	 *
	 * @param AnalysisRepository $repository Analysis storage.
	 * @param UpdateActivity     $activity   Updater activity ledger.
	 * @param callable           $now        Clock (Unix seconds as float).
	 */
	public function __construct( AnalysisRepository $repository, UpdateActivity $activity, callable $now ) {
		$this->repository = $repository;
		$this->activity   = $activity;
		$this->now        = $now;
		$this->codec      = new SiteCheckResultCodec();
	}

	/**
	 * AFTER checks on the running site.
	 *
	 * @return self
	 */
	public static function from_wordpress() {
		global $wpdb;

		return new self(
			new AnalysisRepository( $wpdb ),
			UpdateActivity::from_wordpress(),
			static function () {
				return microtime( true );
			}
		);
	}

	/**
	 * Whether the updating browser should ask to start AFTER checks for an
	 * analysis its follow-up request just settled: completed by the
	 * follow-up, not yet started, and with at least one BEFORE result. A
	 * hint only; open() decides. Never throws.
	 *
	 * @param int $analysis_id Analysis ID.
	 * @return bool
	 */
	public function wanted( $analysis_id ) {
		try {
			$row = $this->repository->find_site_checks( (int) $analysis_id );
		} catch ( Throwable $e ) {
			return false;
		}

		return null !== $row
			&& AnalysisStatus::COMPLETED === $row['status']
			&& SettleOutcome::FOLLOW_UP === $row['settle_outcome']
			&& null === $row[ self::STARTED_AT ]
			&& is_numeric( $row[ SiteCheckObservation::UPDATE_STARTED_AT ] )
			&& array() !== self::baselines( $row );
	}

	/**
	 * Decide and claim the AFTER run of an analysis. Never throws.
	 *
	 * @param int    $analysis_id Analysis ID (validated request input).
	 * @param int    $user        Current user ID.
	 * @param string $login       Current login-session fingerprint.
	 * @return array `code` OPEN with `checks` (identifiers to open sessions
	 *               for) and `generation` (to record in the sessions);
	 *               NOT_NEEDED with `reason` (AfterReason: `no_baseline`, or
	 *               the stored `overlapping_update` / `update_order_unknown`);
	 *               NOT_ELIGIBLE, NOT_OWNER, ALREADY_STARTED or UNAVAILABLE.
	 */
	public function open( $analysis_id, $user, $login ) {
		try {
			$row = $this->repository->find_site_checks( (int) $analysis_id );
		} catch ( Throwable $e ) {
			return array( 'code' => self::UNAVAILABLE );
		}
		if ( null === $row ) {
			return array( 'code' => self::NOT_ELIGIBLE );
		}
		if ( (int) $row['user_id'] !== (int) $user || (int) $user < 1 ) {
			return array( 'code' => self::NOT_OWNER );
		}
		if ( AnalysisStatus::COMPLETED !== $row['status'] || SettleOutcome::FOLLOW_UP !== $row['settle_outcome'] || ! is_numeric( $row[ SiteCheckObservation::UPDATE_STARTED_AT ] ) ) {
			return array( 'code' => self::NOT_ELIGIBLE );
		}
		if ( null !== $row[ self::STARTED_AT ] ) {
			return array( 'code' => self::ALREADY_STARTED );
		}
		$now       = $this->now();
		$completed = self::time( $row['completed_at'] );
		if ( null === $completed || $now - $completed > self::START_WINDOW_SECONDS ) {
			return array( 'code' => self::NOT_ELIGIBLE );
		}

		$baselines = self::baselines( $row );
		if ( array() === $baselines ) {
			return array(
				'code'   => self::NOT_NEEDED,
				'reason' => AfterReason::NO_BASELINE,
			);
		}
		foreach ( $baselines as $result ) {
			// The AFTER run must come from the login that took the baselines.
			if ( $result->get_context()->get_user() !== (int) $user || ! hash_equals( $result->get_context()->get_session(), (string) $login ) ) {
				return array( 'code' => self::NOT_OWNER );
			}
		}

		$expected = self::expected_generation( $baselines );
		$reason   = $this->order_reason( $expected );
		try {
			$claimed = $this->repository->claim_site_check_after( (int) $analysis_id, (int) $user, AnalysisStatus::COMPLETED, SettleOutcome::FOLLOW_UP, $now );
		} catch ( Throwable $e ) {
			return array( 'code' => self::UNAVAILABLE );
		}
		if ( ! $claimed ) {
			return array( 'code' => self::ALREADY_STARTED );
		}

		if ( null !== $reason ) {
			// Another update since this one (or the order is unknown): nothing runs.
			$this->store_reason( (int) $analysis_id, (int) $user, array_keys( $baselines ), $reason );
			return array(
				'code'   => self::NOT_NEEDED,
				'reason' => $reason,
			);
		}

		return array(
			'code'       => self::OPEN,
			'checks'     => array_keys( $baselines ),
			'generation' => $expected,
		);
	}

	/**
	 * The claimed run could not open its sessions: store why, for the checks
	 * that were to run. Never throws.
	 *
	 * @param int      $analysis_id Analysis ID.
	 * @param int      $user        User ID.
	 * @param string[] $checks      Checks that were to run.
	 * @param string   $reason      AfterReason::RATE_LIMITED or SESSION_FAILED.
	 * @return void
	 */
	public function open_failed( $analysis_id, $user, array $checks, $reason ) {
		$this->store_reason( (int) $analysis_id, (int) $user, $checks, AfterReason::RATE_LIMITED === $reason ? $reason : AfterReason::SESSION_FAILED );
	}

	/**
	 * Store a check's accepted AFTER result, once. If the ledger shows any
	 * updater activity since the run started (or cannot tell), the result is
	 * stored with that reason and is never compared. Never throws.
	 *
	 * @param int         $analysis_id Analysis the session belongs to.
	 * @param int         $user        Session owner.
	 * @param CheckResult $result      Accepted result (its generation: the run's start).
	 * @return string SAVED, CONFLICT (already stored) or FAILED.
	 */
	public function record( $analysis_id, $user, CheckResult $result ) {
		$check = $result->get_check();
		if ( ! isset( self::COLUMNS[ $check ] ) ) {
			return self::FAILED;
		}
		$reason = $this->order_reason( $result->get_generation() );
		try {
			$json   = $this->codec->encode( $result );
			$stored = $this->repository->record_site_check_after( (int) $analysis_id, (int) $user, array_values( self::COLUMNS[ $check ] ), $json, $reason );
		} catch ( Throwable $e ) {
			return self::FAILED;
		}

		return $stored ? self::SAVED : self::CONFLICT;
	}

	/**
	 * The AFTER side of a stored analysis.
	 *
	 * @param array $row Analysis row (missing columns read as NULL).
	 * @param float $now Current Unix time.
	 * @return array<string, array{result: CheckResult|null, reason: string|null, pending: bool}>
	 *         Per check: the stored result (with `reason` null, or
	 *         `overlapping_update` / `update_order_unknown` / `unknown`: not
	 *         comparable), or no result and a reason (AfterReason), or
	 *         `pending` while the result can still arrive (reason null).
	 */
	public static function read( array $row, $now ) {
		$codec  = new SiteCheckResultCodec();
		$before = SiteCheckObservation::read( $row );
		$read   = array();
		foreach ( self::COLUMNS as $check => $names ) {
			$json   = self::field( $row, $names['result'] );
			$reason = self::field( $row, $names['reason'] );
			if ( null !== $json ) {
				try {
					$result = $codec->decode( $json );
				} catch ( Throwable $e ) {
					$result = null;
				}
				$usable = null !== $result && $result->get_check() === $check;
				if ( null !== $reason && ! in_array( $reason, AfterReason::WITH_RESULT, true ) ) {
					$reason = AfterReason::UNKNOWN;
				}
				$read[ $check ] = array(
					'result'  => $usable ? $result : null,
					'reason'  => $usable ? $reason : AfterReason::DATA_CORRUPT,
					'pending' => false,
				);
				continue;
			}
			if ( null !== $reason ) {
				$read[ $check ] = array(
					'result'  => null,
					'reason'  => in_array( $reason, AfterReason::STORED, true ) ? $reason : AfterReason::UNKNOWN,
					'pending' => false,
				);
				continue;
			}
			$derived        = self::derived_reason( $row, $before[ $check ], (float) $now );
			$read[ $check ] = array(
				'result'  => null,
				'reason'  => $derived,
				'pending' => null === $derived,
			);
		}

		return $read;
	}

	/**
	 * Why a check has no AFTER result, from the analysis's state, or null
	 * while it can still arrive.
	 *
	 * @param array $row    Analysis row.
	 * @param array $before The check's BEFORE side (SiteCheckObservation::read()).
	 * @param float $now    Current Unix time.
	 * @return string|null
	 */
	private static function derived_reason( array $row, array $before, $now ) {
		if ( BeforeReason::NOT_CAPTURED === $before['reason'] ) {
			return AfterReason::NOT_CAPTURED;
		}
		if ( null === $before['result'] ) {
			return AfterReason::NO_BASELINE;
		}
		if ( ! is_numeric( self::field( $row, SiteCheckObservation::UPDATE_STARTED_AT ) ) ) {
			return AfterReason::NOT_CAPTURED; // Created before schema 7.
		}
		$status = self::field( $row, 'status' );
		if ( AnalysisStatus::CAPTURED === $status || AnalysisStatus::AWAITING_SETTLE === $status ) {
			return null;
		}
		if ( AnalysisStatus::COMPLETED !== $status ) {
			return AfterReason::ANALYSIS_ENDED;
		}
		$outcome = self::field( $row, 'settle_outcome' );
		if ( SettleOutcome::EXPIRED === $outcome ) {
			return AfterReason::SETTLE_EXPIRED;
		}
		if ( SettleOutcome::NEXT_UPDATE === $outcome ) {
			return AfterReason::NEXT_UPDATE; // Another update began right after this one.
		}
		if ( SettleOutcome::FOLLOW_UP !== $outcome ) {
			return AfterReason::NOT_FOLLOWED_UP;
		}
		$started = self::field( $row, self::STARTED_AT );
		if ( ! is_numeric( $started ) ) {
			$completed = self::time( self::field( $row, 'completed_at' ) );
			return null !== $completed && $now <= $completed + self::START_WINDOW_SECONDS ? null : AfterReason::NOT_STARTED;
		}

		return $now <= (float) $started + CheckSession::TTL + self::REPORT_GRACE_SECONDS ? null : AfterReason::NOT_REPORTED;
	}

	/**
	 * The generation the ledger must show for a clean AFTER run: two changes
	 * (this update's begin and end) after its BEFORE results' generation.
	 *
	 * @param CheckResult[] $baselines Attached BEFORE results.
	 * @return string|null Null if they disagree or have none.
	 */
	public static function expected_generation( array $baselines ) {
		$generations = array();
		foreach ( $baselines as $result ) {
			$generations[ (string) $result->get_generation() ] = true;
		}
		if ( 1 !== count( $generations ) ) {
			return null;
		}

		return UpdateActivity::advance( key( $generations ), 2 );
	}

	/**
	 * Null if the ledger shows exactly $expected with no update running;
	 * otherwise why a run at this moment is not clean.
	 *
	 * @param string|null $expected Expected generation.
	 * @return string|null AfterReason::OVERLAPPING_UPDATE or UPDATE_ORDER_UNKNOWN.
	 */
	private function order_reason( $expected ) {
		$current = $this->activity->current();
		if ( null === $expected || null === $current || ! UpdateActivity::same_epoch( $expected, $current['generation'] ) ) {
			return AfterReason::UPDATE_ORDER_UNKNOWN;
		}

		return $current['running'] || $current['generation'] !== $expected ? AfterReason::OVERLAPPING_UPDATE : null;
	}

	/**
	 * Store the same reason for several checks (guarded, once each). Never throws.
	 *
	 * @param int      $analysis_id Analysis ID.
	 * @param int      $user        User ID.
	 * @param string[] $checks      Checks.
	 * @param string   $reason      AfterReason (stored kind).
	 * @return void
	 */
	private function store_reason( $analysis_id, $user, array $checks, $reason ) {
		foreach ( $checks as $check ) {
			if ( ! isset( self::COLUMNS[ $check ] ) ) {
				continue;
			}
			try {
				$this->repository->record_site_check_after( $analysis_id, $user, array_values( self::COLUMNS[ $check ] ), null, $reason );
			} catch ( Throwable $e ) {
				continue; // Unresolved: reads as not_reported / not_started later.
			}
		}
	}

	/**
	 * The attached BEFORE results of an analysis, by check.
	 *
	 * @param array $row Analysis row.
	 * @return array<string, CheckResult>
	 */
	private static function baselines( array $row ) {
		$baselines = array();
		foreach ( SiteCheckObservation::read( $row ) as $check => $before ) {
			if ( null !== $before['result'] ) {
				$baselines[ $check ] = $before['result'];
			}
		}

		return $baselines;
	}

	/**
	 * Current time.
	 *
	 * @return float
	 */
	private function now() {
		return (float) call_user_func( $this->now );
	}

	/**
	 * Stored UTC DATETIME as Unix time, or null.
	 *
	 * @param mixed $value Stored value.
	 * @return float|null
	 */
	private static function time( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$time = strtotime( $value . ' UTC' );

		return false === $time ? null : (float) $time;
	}

	/**
	 * A row field, null when missing.
	 *
	 * @param array  $row Row.
	 * @param string $key Column.
	 * @return mixed
	 */
	private static function field( array $row, $key ) {
		return isset( $row[ $key ] ) ? $row[ $key ] : null;
	}
}
