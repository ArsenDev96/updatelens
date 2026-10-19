<?php
/**
 * Plugin update analysis lifecycle.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

use Throwable;
use UnexpectedValueException;
use UpdateLens\Diff\IncompatibleSnapshotsException;
use UpdateLens\Diff\OptionsDiffBuilder;
use UpdateLens\Snapshot\ActionSchedulerSnapshot;
use UpdateLens\Snapshot\ActionSchedulerSnapshotProvider;
use UpdateLens\Snapshot\CronSnapshot;
use UpdateLens\Snapshot\CronSnapshotProvider;
use UpdateLens\Snapshot\MemoryBudget;
use UpdateLens\Snapshot\OptionsSnapshot;
use UpdateLens\Snapshot\OptionsSnapshotProvider;
use UpdateLens\Storage\AnalysisRepository;
use UpdateLens\Storage\OptionsDiffCodec;
use UpdateLens\Storage\OptionsSnapshotCodec;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates BEFORE → update request → IMMEDIATE → first eligible admin
 * request → SETTLED, and stores the during-update, post-update and final
 * diffs (see ObservationPhase). Reports observed changes, not proven causes.
 *
 * Three signals are observed at the same moments: `wp_options` drives the
 * lifecycle and the analysis status; WP-Cron (CronObservation) and Action
 * Scheduler (ActionSchedulerObservation) are captured right after it, in
 * that order, and never change the status, block the update or fail the
 * options analysis. Their phases are available or carry their own reason,
 * and a storage failure of one of them never drops the other's data (see
 * transition()).
 *
 * When an analysis is created, the BEFORE site check baselines of the
 * updating administrator are attached to it (SiteCheckObservation), decided
 * once from what is stored and the update's activity record; they never
 * affect the status or the snapshots, and are kept on every final state.
 *
 * Knows nothing about WordPress hooks (see PluginUpdateTracker). One instance
 * lives for one PHP request and remembers which analyses that request
 * created, so the update request's own shutdown never settles them.
 *
 * Observation rules:
 * - Settling happens in the follow-up request the updating administrator's
 *   browser sends after the update (settle_follow_up()), at shutdown of a
 *   later eligible wp-admin page request (request_ending()), or right before
 *   another update starts (whichever comes first), and only within
 *   SETTLE_WINDOW_SECONDS of the update; after that the analysis completes
 *   with outcome `expired` and no settled snapshot, so late settling never
 *   collects unrelated site activity.
 * - A follow-up or page request settles an analysis only if it started after
 *   the analysis's IMMEDIATE observation was taken (immediate_captured_at, in
 *   microseconds), so it included the updated plugin files from the start.
 * - If another update starts in the request that created an analysis, that
 *   analysis is abandoned: its settled state would include the other update.
 * - A request that activates or deactivates the plugin does not settle it.
 */
final class PluginUpdateAnalyzer {

	/**
	 * A `captured` analysis older than this (update never reported back) is abandoned.
	 */
	const STALE_AFTER_SECONDS = 900;

	/**
	 * How long after a successful update a settled snapshot may still be taken.
	 * Inclusive: a request exactly at the deadline may settle.
	 */
	const SETTLE_WINDOW_SECONDS = 300;

	const ERROR_UPDATE_FAILED          = 'update_failed';
	const ERROR_UPDATE_NOT_COMPLETED   = 'update_not_completed';
	const ERROR_ANALYSIS_FAILED        = 'analysis_error';
	const ERROR_SNAPSHOT_CORRUPT       = 'snapshot_corrupt';
	const ERROR_CONTEXT_CHANGED        = 'fingerprint_context_changed';
	const ERROR_STALE                  = 'stale';
	const ERROR_ANOTHER_UPDATE_STARTED = 'another_update_started';

	/**
	 * Fixed, safe messages per error code. WordPress error messages are never
	 * stored: they can contain paths or package URLs.
	 */
	const ERROR_MESSAGES = array(
		self::ERROR_UPDATE_NOT_COMPLETED   => 'The WordPress update did not report completion.',
		self::ERROR_ANALYSIS_FAILED        => 'UpdateLens could not analyse this update.',
		self::ERROR_SNAPSHOT_CORRUPT       => 'A stored snapshot could not be read.',
		self::ERROR_CONTEXT_CHANGED        => 'Option values cannot be compared because the fingerprint context changed (for example, rotated WordPress salts).',
		self::ERROR_STALE                  => 'The update never reported completion; the analysis was abandoned.',
		self::ERROR_ANOTHER_UPDATE_STARTED => 'Another update ran in the same request, so later observations could not be separated from it.',
	);

	/**
	 * Analysis storage.
	 *
	 * @var AnalysisRepository
	 */
	private $repository;

	/**
	 * Returns a fresh OptionsSnapshot of the site.
	 *
	 * @var callable
	 */
	private $capture;

	/**
	 * WP-Cron signal.
	 *
	 * @var CronObservation
	 */
	private $cron;

	/**
	 * Action Scheduler signal.
	 *
	 * @var ActionSchedulerObservation
	 */
	private $action_scheduler;

	/**
	 * BEFORE site check baselines, or null (none attached, e.g. in tests of other signals).
	 *
	 * @var SiteCheckObservation|null
	 */
	private $site_checks;

	/**
	 * Returns the current Unix time (seconds, with or without fractions).
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Unix time (with microseconds) at which this PHP request started, or null if unknown.
	 *
	 * @var float|null
	 */
	private $request_started_at;

	/**
	 * Snapshot persistence format.
	 *
	 * @var OptionsSnapshotCodec
	 */
	private $snapshot_codec;

	/**
	 * Diff persistence format.
	 *
	 * @var OptionsDiffCodec
	 */
	private $diff_codec;

	/**
	 * Diff builder.
	 *
	 * @var OptionsDiffBuilder
	 */
	private $diff_builder;

	/**
	 * Returns which signals an observation stage may capture
	 * (ObservationBudget::plan(): signal => bool), or one bool for all.
	 *
	 * @var callable
	 */
	private $fits;

	/**
	 * Analyses created in this request, plugin file => ID.
	 *
	 * @var array<string, int>
	 */
	private $request_analyses = array();

	/**
	 * Whether any update started in this request.
	 *
	 * @var bool
	 */
	private $update_seen = false;

	/**
	 * Constructor.
	 *
	 * @param AnalysisRepository        $repository   Analysis storage.
	 * @param callable                  $capture      Returns a fresh OptionsSnapshot.
	 * @param callable                  $capture_cron Returns a fresh CronSnapshot.
	 * @param callable|null             $now          Returns the current Unix time. Default time().
	 * @param callable|null             $capture_action_scheduler Returns a fresh ActionSchedulerSnapshot.
	 *                                                Null: no Action Scheduler (`not_installed`).
	 * @param float|null                $request_started_at Unix time (with microseconds, same clock as
	 *                                                $now) at which this request started. Null:
	 *                                                unknown, so this request never settles
	 *                                                analyses of earlier requests.
	 * @param SiteCheckObservation|null $site_checks BEFORE site check baselines attached when an
	 *                                         analysis is created. Null: the columns are not written.
	 * @param callable|null             $fits  Returns which signals an observation stage (ObservationBudget
	 *                                         constant) may capture: ObservationBudget::plan(), or one bool
	 *                                         for all signals. Null: everything fits.
	 */
	public function __construct( AnalysisRepository $repository, callable $capture, callable $capture_cron, ?callable $now = null, ?callable $capture_action_scheduler = null, ?float $request_started_at = null, ?SiteCheckObservation $site_checks = null, ?callable $fits = null ) {
		$this->fits               = null === $fits ? static function () {
			return true;
		} : $fits;
		$this->site_checks        = $site_checks;
		$this->repository         = $repository;
		$this->capture            = $capture;
		$this->cron               = new CronObservation( $capture_cron );
		$this->action_scheduler   = new ActionSchedulerObservation( $capture_action_scheduler );
		$this->now                = null === $now ? 'microtime' : $now;
		$this->request_started_at = $request_started_at;
		$this->snapshot_codec     = new OptionsSnapshotCodec();
		$this->diff_codec         = new OptionsDiffCodec();
		$this->diff_builder       = new OptionsDiffBuilder();
	}

	/**
	 * Analyzer wired to the running site. The snapshot providers are created on first use.
	 *
	 * The request start is WordPress's `$timestart` (RequestStart, taken before
	 * any drop-in or plugin file loads); the clock is `microtime( true )`, the
	 * same clock.
	 *
	 * @return self
	 */
	public static function create() {
		global $wpdb;

		$provider = null;
		$capture  = static function () use ( &$provider ) {
			if ( null === $provider ) {
				$provider = OptionsSnapshotProvider::create();
			}
			return $provider->capture();
		};

		$cron_provider = null;
		$capture_cron  = static function () use ( &$cron_provider ) {
			if ( null === $cron_provider ) {
				$cron_provider = CronSnapshotProvider::create();
			}
			return $cron_provider->capture();
		};

		$action_scheduler_provider = null;
		$capture_action_scheduler  = static function () use ( &$action_scheduler_provider ) {
			if ( null === $action_scheduler_provider ) {
				$action_scheduler_provider = ActionSchedulerSnapshotProvider::create();
			}
			return $action_scheduler_provider->capture();
		};

		$fits = static function ( $stage ) use ( &$provider, &$cron_provider, &$action_scheduler_provider ) {
			$provider                  = null === $provider ? OptionsSnapshotProvider::create() : $provider;
			$cron_provider             = null === $cron_provider ? CronSnapshotProvider::create() : $cron_provider;
			$action_scheduler_provider = null === $action_scheduler_provider ? ActionSchedulerSnapshotProvider::create() : $action_scheduler_provider;
			$budget                    = new ObservationBudget(
				MemoryBudget::from_php(),
				array( $provider, 'count_options' ),
				array( $cron_provider, 'stored_bytes' ),
				array( $action_scheduler_provider, 'measure_active' )
			);
			return $budget->plan( $stage );
		};

		return new self(
			new AnalysisRepository( $wpdb ),
			$capture,
			$capture_cron,
			static function () {
				return microtime( true );
			},
			$capture_action_scheduler,
			RequestStart::get(),
			SiteCheckObservation::from_wordpress(),
			$fits
		);
	}

	/**
	 * An update is about to modify files.
	 *
	 * Called for every update; $plugin_file is set only for supported
	 * single-plugin updates. Analyses from earlier requests that await a
	 * settled snapshot are settled first (or expired, if past their window),
	 * so this update is never part of their observations.
	 *
	 * @param string|null                            $plugin_file Plugin to analyse, or null.
	 * @param array{name?: string, version?: string} $plugin      Plugin header data before the update.
	 * @param int                                    $user_id     Current user ID (0 if none).
	 * @param array|null                             $activity    This update's SiteCheck\UpdateActivity::begin()
	 *                                                            record (the order against other updates), or null.
	 * @return void
	 */
	public function update_starting( $plugin_file, array $plugin = array(), $user_id = 0, $activity = null ) {
		if ( null !== $plugin_file && isset( $this->request_analyses[ $plugin_file ] ) ) {
			return; // The same update reported twice.
		}

		$first_update      = ! $this->update_seen;
		$this->update_seen = true;

		foreach ( $this->request_analyses as $id ) {
			$this->abandon_open( $id, self::ERROR_ANOTHER_UPDATE_STARTED );
		}

		$to_settle = array();
		if ( $first_update ) {
			foreach ( $this->awaiting_from_earlier_requests() as $row ) {
				if ( $this->is_settle_window_open( $row ) ) {
					$to_settle[] = $row;
				} else {
					$this->expire( $row );
				}
			}
		}

		if ( ! $to_settle && null === $plugin_file ) {
			return;
		}

		// The whole observation (up to SETTLED) must fit, or none is begun.
		$plan = $this->plan( ObservationBudget::BEFORE );
		try {
			$snapshot = $plan[ ObservationBudget::OPTIONS ] ? call_user_func( $this->capture ) : null;
		} catch ( Throwable $e ) {
			$snapshot = null;
		}
		if ( null === $snapshot ) {
			foreach ( $to_settle as $row ) {
				$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_ANALYSIS_FAILED, SettleOutcome::NEXT_UPDATE );
			}
			return;
		}
		list( $cron_snapshot, $action_scheduler_snapshot ) = $this->capture_signals( $plan );

		foreach ( $to_settle as $row ) {
			$this->settle( $row, $snapshot, $cron_snapshot, $action_scheduler_snapshot, SettleOutcome::NEXT_UPDATE );
		}

		if ( null !== $plugin_file ) {
			$this->begin( $plugin_file, $plugin, $user_id, $snapshot, $cron_snapshot, $action_scheduler_snapshot, $activity );
		}
	}

	/**
	 * WordPress reported the end of a single-plugin update.
	 *
	 * On success, stores the during-update diffs (BEFORE → IMMEDIATE) and keeps
	 * the snapshots until the settle phase ends. A failed update captures no
	 * Cron or Action Scheduler state.
	 *
	 * @param string      $plugin_file   Plugin basename.
	 * @param string|null $error_code    Null if the update succeeded, else a WordPress error code.
	 * @param string|null $version_after Installed version after the update.
	 * @return bool True only if this call stored the IMMEDIATE observation: the
	 *              analysis now awaits its settled snapshot.
	 */
	public function update_finished( $plugin_file, $error_code, $version_after ) {
		if ( ! isset( $this->request_analyses[ $plugin_file ] ) ) {
			return false; // Not an update this request is analysing.
		}

		try {
			$row = $this->repository->find( $this->request_analyses[ $plugin_file ] );
		} catch ( Throwable $e ) {
			return false;
		}
		if ( null === $row || AnalysisStatus::CAPTURED !== $row['status'] ) {
			return false; // Already handled.
		}

		if ( null !== $error_code ) {
			$this->finish( $row, AnalysisStatus::FAILED, self::sanitize_error_code( $error_code ), SettleOutcome::NOT_APPLICABLE );
			return false;
		}

		$plan = $this->plan( ObservationBudget::IMMEDIATE );
		if ( ! $plan[ ObservationBudget::OPTIONS ] ) {
			// Skipped before anything large is allocated; the update continues.
			$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_ANALYSIS_FAILED, SettleOutcome::NOT_APPLICABLE );
			return false;
		}

		$before = $this->decode_snapshot( $row, 'options_before_snapshot', SettleOutcome::NOT_APPLICABLE );
		if ( null === $before ) {
			return false;
		}
		unset( $row['options_before_snapshot'] ); // Decoded; the JSON is no longer needed.

		try {
			$immediate = call_user_func( $this->capture );

			list( $cron_immediate, $action_scheduler_immediate ) = $this->capture_signals( $plan );

			$during_update_json = $this->diff_codec->encode( $this->diff_builder->build( $before, $immediate ) );
			$immediate_json     = $this->snapshot_codec->encode( $immediate );
		} catch ( IncompatibleSnapshotsException $e ) {
			$this->finish( $row, AnalysisStatus::INCOMPATIBLE, self::ERROR_CONTEXT_CHANGED, SettleOutcome::NOT_APPLICABLE );
			return false;
		} catch ( Throwable $e ) {
			$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_ANALYSIS_FAILED, SettleOutcome::NOT_APPLICABLE );
			return false;
		}

		// Taken after the IMMEDIATE captures (the files were replaced before them):
		// a request that started later included the new plugin files.
		$captured_at = $this->precise_now();
		$now         = (int) floor( $captured_at );
		$applied     = $this->transition(
			$row,
			array(
				'status'                     => AnalysisStatus::AWAITING_SETTLE,
				'version_after'              => null === $version_after ? null : (string) $version_after,
				'options_during_update_diff' => $during_update_json,
				'options_immediate_snapshot' => $immediate_json,
				'settle_deadline'            => self::datetime( $now + self::SETTLE_WINDOW_SECONDS ),
				'immediate_captured_at'      => sprintf( '%.6F', $captured_at ),
				'updated_at'                 => self::datetime( $now ),
			),
			$this->cron->immediate( $row, $cron_immediate ),
			$this->action_scheduler->immediate( $row, $action_scheduler_immediate )
		);

		return true === $applied;
	}

	/**
	 * The request is ending (WordPress `shutdown`).
	 *
	 * Fails analyses this request started whose update never reported back.
	 * On an eligible wp-admin page request that ran no update (see
	 * SettleEligibility), also abandons stale analyses, expires those past
	 * their settle window and settles the rest that this request may settle.
	 *
	 * Not settled here (they wait for a later request, or expire):
	 * - analyses whose IMMEDIATE observation was taken after this request
	 *   started: this request may have loaded the old plugin files;
	 * - analyses of a plugin activated or deactivated during this request
	 *   (e.g. the reactivation request after update.php), which did not run a
	 *   full request lifecycle with its new code.
	 *
	 * @param bool     $may_settle         Whether this request may settle (SettleEligibility::may_settle_at_shutdown()).
	 * @param string[] $activation_changed Plugins whose active state changed during this request.
	 * @return void
	 */
	public function request_ending( $may_settle, array $activation_changed = array() ) {
		foreach ( $this->request_analyses as $id ) {
			$this->fail_if_captured( $id );
		}

		if ( ! $may_settle || $this->update_seen ) {
			return;
		}

		try {
			$open = $this->repository->find_open();
		} catch ( Throwable $e ) {
			return;
		}

		$snapshot                  = null;
		$cron_snapshot             = null;
		$action_scheduler_snapshot = null;
		foreach ( $open as $row ) {
			if ( in_array( (int) $row['id'], $this->request_analyses, true ) ) {
				continue;
			}

			if ( AnalysisStatus::CAPTURED === $row['status'] ) {
				if ( $this->is_stale( $row ) ) {
					$this->finish( $row, AnalysisStatus::ABANDONED, self::ERROR_STALE, SettleOutcome::NOT_APPLICABLE );
				}
				continue;
			}

			if ( ! $this->is_settle_window_open( $row ) ) {
				$this->expire( $row );
				continue;
			}

			if ( in_array( $row['plugin_file'], $activation_changed, true ) || ! $this->started_after_immediate( $row ) ) {
				continue;
			}

			if ( null === $snapshot ) {
				$plan = $this->plan( ObservationBudget::SETTLED );
				if ( ! $plan[ ObservationBudget::OPTIONS ] ) {
					return; // Does not fit in this request: a later one may, within the window.
				}
				try {
					$snapshot = call_user_func( $this->capture );
				} catch ( Throwable $e ) {
					return; // Try again on a later request within the window.
				}
				list( $cron_snapshot, $action_scheduler_snapshot ) = $this->capture_signals( $plan );
			}
			$this->settle( $row, $snapshot, $cron_snapshot, $action_scheduler_snapshot, SettleOutcome::ADMIN_SHUTDOWN );
		}
	}

	/**
	 * Take the post-update observation the updating administrator's browser
	 * asked for after a successful update (FollowUpRequest).
	 *
	 * Only the open analyses of the listed plugins are considered, and only
	 * those started by $user_id; other pending analyses are left for their own
	 * follow-up, a later page request, the next update or expiry. An analysis is
	 * settled only if it awaits a settled snapshot, its settle window is open
	 * and this request started after its IMMEDIATE observation (so the request
	 * runs the new plugin code). One snapshot is shared by all settled analyses,
	 * as at shutdown. Compare-and-set writes make concurrent settle attempts
	 * safe: exactly one wins, the others report a conflict.
	 *
	 * @param string[]                $plugin_files       Plugin basenames (validated by FollowUpRequest::plugins()).
	 * @param int                     $user_id            Current user ID.
	 * @param string[]                $activation_changed Plugins whose active state changed during this request.
	 * @param array<string, int>|null $settled            Set to plugin file => ID of each analysis this
	 *                                                    call settled (result SETTLED), so the response
	 *                                                    names exactly those analyses.
	 * @return array<string, string> Plugin file => FollowUpRequest result code.
	 */
	public function settle_follow_up( array $plugin_files, $user_id, array $activation_changed = array(), &$settled = null ) {
		$settled  = array();
		$results  = array();
		$eligible = array();
		foreach ( $plugin_files as $plugin_file ) {
			$row                     = null;
			$results[ $plugin_file ] = $this->follow_up_candidate( $plugin_file, (int) $user_id, $activation_changed, $row );
			if ( null === $results[ $plugin_file ] ) {
				$eligible[ $plugin_file ] = $row;
			}
		}

		if ( array() === $eligible ) {
			return $results;
		}

		$plan = $this->plan( ObservationBudget::SETTLED );
		try {
			$snapshot = $plan[ ObservationBudget::OPTIONS ] ? call_user_func( $this->capture ) : null;
		} catch ( Throwable $e ) {
			$snapshot = null;
		}
		if ( null === $snapshot ) {
			// Capture failed or would not fit: still awaiting a settled snapshot.
			foreach ( array_keys( $eligible ) as $plugin_file ) {
				$results[ $plugin_file ] = FollowUpRequest::CAPTURE_FAILED;
			}
			return $results;
		}
		list( $cron_snapshot, $action_scheduler_snapshot ) = $this->capture_signals( $plan );

		foreach ( $eligible as $plugin_file => $row ) {
			$results[ $plugin_file ] = $this->settle( $row, $snapshot, $cron_snapshot, $action_scheduler_snapshot, SettleOutcome::FOLLOW_UP );
			if ( FollowUpRequest::SETTLED === $results[ $plugin_file ] ) {
				$settled[ $plugin_file ] = (int) $row['id'];
			}
		}

		return $results;
	}

	/**
	 * Which signals an observation stage may capture (ObservationBudget::plan()).
	 * Anything the callable does not allow, or a failure to ask, means no.
	 *
	 * @param string $stage ObservationBudget stage.
	 * @return array<string, bool> ObservationBudget signal => whether it fits.
	 */
	private function plan( $stage ) {
		$signals = array( ObservationBudget::OPTIONS, ObservationBudget::CRON, ObservationBudget::ACTION_SCHEDULER );
		try {
			$plan = call_user_func( $this->fits, $stage );
		} catch ( Throwable $e ) {
			$plan = false;
		}
		if ( ! is_array( $plan ) ) {
			return array_fill_keys( $signals, true === $plan );
		}

		$result = array();
		foreach ( $signals as $signal ) {
			$result[ $signal ] = isset( $plan[ $signal ] ) && true === $plan[ $signal ];
		}
		return $result;
	}

	/**
	 * Capture WP-Cron and Action Scheduler as far as the stage's plan allows.
	 * A signal that does not fit is not captured: it is unavailable
	 * (`snapshot_unavailable`, like a capture that failed), never "no changes".
	 *
	 * @param array<string, bool> $plan The stage's plan (plan()).
	 * @return array{0: CronSnapshot|string, 1: ActionSchedulerSnapshot|string}
	 */
	private function capture_signals( array $plan ) {
		return array(
			$plan[ ObservationBudget::CRON ] ? $this->cron->capture() : CronPhaseReason::SNAPSHOT_UNAVAILABLE,
			$plan[ ObservationBudget::ACTION_SCHEDULER ] ? $this->action_scheduler->capture() : ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE,
		);
	}

	/**
	 * Whether a plugin's open analysis may be settled by a follow-up request.
	 *
	 * Expires the analysis if its settle window has passed.
	 *
	 * @param string     $plugin_file        Plugin basename.
	 * @param int        $user_id            Current user ID.
	 * @param string[]   $activation_changed Plugins whose active state changed during this request.
	 * @param array|null $row                Set to the open analysis when eligible.
	 * @return string|null Null if eligible, otherwise the FollowUpRequest result code.
	 */
	private function follow_up_candidate( $plugin_file, $user_id, array $activation_changed, &$row ) {
		if ( $this->update_seen ) {
			return FollowUpRequest::NOT_READY; // Never settle around an update of this request.
		}

		try {
			$open = $this->repository->find_open_for_plugin( $plugin_file );
		} catch ( Throwable $e ) {
			return FollowUpRequest::STORAGE_FAILED;
		}

		if ( null === $open || in_array( (int) $open['id'], $this->request_analyses, true ) ) {
			return FollowUpRequest::NOT_PENDING;
		}
		if ( $user_id <= 0 || (int) $open['user_id'] !== $user_id ) {
			return FollowUpRequest::NOT_ALLOWED;
		}
		if ( AnalysisStatus::AWAITING_SETTLE !== $open['status'] ) {
			return FollowUpRequest::NOT_READY; // The update is still running.
		}
		if ( ! $this->is_settle_window_open( $open ) ) {
			return self::follow_up_result( $this->expire( $open ), FollowUpRequest::EXPIRED );
		}
		if ( in_array( $plugin_file, $activation_changed, true ) || ! $this->started_after_immediate( $open ) ) {
			return FollowUpRequest::NOT_READY;
		}

		$row = $open;

		return null;
	}

	/**
	 * Follow-up result of a transition this request attempted.
	 *
	 * @param bool|null $applied Result of transition().
	 * @param string    $result  Result code if the transition was applied.
	 * @return string FollowUpRequest result code.
	 */
	private static function follow_up_result( $applied, $result ) {
		if ( true === $applied ) {
			return $result;
		}

		return false === $applied ? FollowUpRequest::CONFLICT : FollowUpRequest::STORAGE_FAILED;
	}

	/**
	 * Whether this request started after an analysis's IMMEDIATE observation was taken.
	 *
	 * Then the request included the updated plugin files from its start. Uses
	 * `immediate_captured_at` (microseconds), so requests starting in the same
	 * second as IMMEDIATE are ordered correctly. It is stored rounded to the
	 * microsecond, so the request start is compared at that resolution, rounded
	 * down: a start within the same recorded microsecond is not later. Analyses
	 * recorded before that column existed only have a whole second
	 * (`updated_at`): there, only a request that started in a later second is
	 * certainly later.
	 *
	 * @param array $row Open analysis in `awaiting_settle`.
	 * @return bool
	 */
	private function started_after_immediate( array $row ) {
		if ( null === $this->request_started_at ) {
			return false;
		}

		if ( isset( $row['immediate_captured_at'] ) && is_numeric( $row['immediate_captured_at'] ) ) {
			// Whole microseconds as floats (exact up to 2^53, also on 32-bit PHP).
			return floor( $this->request_started_at * 1000000 ) > round( (float) $row['immediate_captured_at'] * 1000000 );
		}

		$immediate = empty( $row['updated_at'] ) ? false : strtotime( $row['updated_at'] . ' UTC' );

		return false !== $immediate && floor( $this->request_started_at ) > $immediate;
	}

	/**
	 * Expire `awaiting_settle` analyses whose settle window has passed.
	 *
	 * Lifecycle maintenance for readers (reports), which must never show an
	 * analysis as waiting after its deadline. Same rule and result as on admin
	 * page shutdown: no snapshot is taken, post-update and final diffs stay NULL.
	 *
	 * @return void
	 */
	public function expire_overdue() {
		try {
			$open = $this->repository->find_open();
		} catch ( Throwable $e ) {
			return;
		}

		foreach ( $open as $row ) {
			if ( AnalysisStatus::AWAITING_SETTLE === $row['status']
				&& ! in_array( (int) $row['id'], $this->request_analyses, true )
				&& ! $this->is_settle_window_open( $row ) ) {
				$this->expire( $row );
			}
		}
	}

	/**
	 * Create the analysis with its BEFORE snapshots.
	 *
	 * If the row cannot be written with the Cron or Action Scheduler
	 * snapshot, it is written without the failing signal's payload (its
	 * phases marked `storage_failed`, see attempts()), so neither signal's
	 * storage ever prevents the options analysis or drops the other signal.
	 *
	 * @param string                         $plugin_file   Plugin basename.
	 * @param array                          $plugin        Plugin header data.
	 * @param int                            $user_id       User ID.
	 * @param OptionsSnapshot                $snapshot      BEFORE options snapshot.
	 * @param CronSnapshot|string            $cron_snapshot BEFORE Cron snapshot or the reason it is unavailable.
	 * @param ActionSchedulerSnapshot|string $action_scheduler_snapshot BEFORE Action Scheduler snapshot or the reason it is unavailable.
	 * @param array|null                     $activity      This update's UpdateActivity::begin() record, or null.
	 * @return void
	 */
	private function begin( $plugin_file, array $plugin, $user_id, OptionsSnapshot $snapshot, $cron_snapshot, $action_scheduler_snapshot, $activity = null ) {
		try {
			$open = $this->repository->find_open_for_plugin( $plugin_file );
			if ( null !== $open ) {
				if ( AnalysisStatus::CAPTURED === $open['status'] && ! $this->is_stale( $open ) ) {
					return; // Another request is updating this plugin right now.
				}
				$this->finish( $open, AnalysisStatus::ABANDONED, AnalysisStatus::CAPTURED === $open['status'] ? self::ERROR_STALE : self::ERROR_ANOTHER_UPDATE_STARTED, SettleOutcome::NOT_APPLICABLE );
			}

			$now = self::datetime( $this->now() );
			$row = array(
				'plugin_file'             => $plugin_file,
				'plugin_name'             => isset( $plugin['name'] ) ? (string) $plugin['name'] : '',
				'version_before'          => isset( $plugin['version'] ) ? (string) $plugin['version'] : '',
				'user_id'                 => (int) $user_id,
				'status'                  => AnalysisStatus::CAPTURED,
				'active_plugin'           => $plugin_file,
				'started_at'              => $now,
				'updated_at'              => $now,
				'options_before_snapshot' => $this->snapshot_codec->encode( $snapshot ),
			);
			if ( null !== $this->site_checks ) {
				// Chosen now, from what is stored when the update starts; never changed later.
				$row += $this->site_checks->before( (int) $user_id, $activity );
			}
			$cron             = $this->cron->before( $cron_snapshot );
			$action_scheduler = $this->action_scheduler->before( $action_scheduler_snapshot );

			$id = null;
			foreach ( self::attempts( $row, $cron, $action_scheduler ) as $attempt ) {
				try {
					$id = $this->repository->create( $attempt );
					break;
				} catch ( Throwable $e ) {
					continue; // Retry without the next signal payload, if any is left.
				}
			}
			if ( null === $id ) {
				return; // No analysis for this update.
			}
		} catch ( Throwable $e ) {
			return; // No analysis for this update; the update itself continues.
		}

		$this->request_analyses[ $plugin_file ] = $id;
	}

	/**
	 * Store the post-update (IMMEDIATE → SETTLED) and final (BEFORE → SETTLED)
	 * diffs and complete the analysis.
	 *
	 * The Cron and Action Scheduler post-update and final phases are resolved
	 * independently in the same write.
	 *
	 * @param array                          $open         Open analysis in `awaiting_settle` (metadata columns).
	 * @param OptionsSnapshot                $settled      Settled options snapshot.
	 * @param CronSnapshot|string            $cron_settled Settled Cron snapshot or the reason it is unavailable.
	 * @param ActionSchedulerSnapshot|string $action_scheduler_settled Settled Action Scheduler snapshot or the reason it is unavailable.
	 * @param string                         $outcome      SettleOutcome::ADMIN_SHUTDOWN, FOLLOW_UP or NEXT_UPDATE.
	 * @return string FollowUpRequest result code: SETTLED only if this call stored the diffs.
	 */
	private function settle( array $open, OptionsSnapshot $settled, $cron_settled, $action_scheduler_settled, $outcome ) {
		try {
			$row = $this->repository->find( (int) $open['id'] );
		} catch ( Throwable $e ) {
			return FollowUpRequest::STORAGE_FAILED;
		}
		if ( null === $row || AnalysisStatus::AWAITING_SETTLE !== $row['status'] ) {
			return FollowUpRequest::CONFLICT; // Settled, expired or ended by another request meanwhile.
		}

		// One stored snapshot at a time, each released after its comparison.
		$immediate = $this->decode_snapshot( $row, 'options_immediate_snapshot', $outcome );
		if ( null === $immediate ) {
			return FollowUpRequest::ANALYSIS_FAILED;
		}
		unset( $row['options_immediate_snapshot'] );

		try {
			$post_update_json = $this->diff_codec->encode( $this->diff_builder->build( $immediate, $settled ) );
			unset( $immediate );
			$before = $this->decode_snapshot( $row, 'options_before_snapshot', $outcome );
			if ( null === $before ) {
				return FollowUpRequest::ANALYSIS_FAILED;
			}
			unset( $row['options_before_snapshot'] );
			$final_json = $this->diff_codec->encode( $this->diff_builder->build( $before, $settled ) );
			unset( $before );
		} catch ( IncompatibleSnapshotsException $e ) {
			$this->finish( $row, AnalysisStatus::INCOMPATIBLE, self::ERROR_CONTEXT_CHANGED, $outcome );
			return FollowUpRequest::ANALYSIS_FAILED;
		} catch ( Throwable $e ) {
			$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_ANALYSIS_FAILED, $outcome );
			return FollowUpRequest::ANALYSIS_FAILED;
		}

		$applied = $this->transition(
			$row,
			array(
				'status'                   => AnalysisStatus::COMPLETED,
				'options_post_update_diff' => $post_update_json,
				'options_final_diff'       => $final_json,
			) + $this->closing_changes( $outcome ),
			$this->cron->settle( $row, $cron_settled ),
			$this->action_scheduler->settle( $row, $action_scheduler_settled )
		);

		return self::follow_up_result( $applied, FollowUpRequest::SETTLED );
	}

	/**
	 * Complete an analysis whose settle window passed without a settled snapshot.
	 *
	 * The during-update diffs stay; options post-update and final diffs remain
	 * NULL and unresolved Cron and Action Scheduler phases get `settle_expired`.
	 * No late snapshot is taken.
	 *
	 * @param array $row Open analysis in `awaiting_settle`.
	 * @return bool|null Result of transition(); false if the analysis changed meanwhile.
	 */
	private function expire( array $row ) {
		$row = $this->with_signal_columns( $row );
		if ( null === $row ) {
			return false;
		}

		return $this->transition(
			$row,
			array( 'status' => AnalysisStatus::COMPLETED ) + $this->closing_changes( SettleOutcome::EXPIRED ),
			$this->cron->close( $row, CronPhaseReason::SETTLE_EXPIRED ),
			$this->action_scheduler->close( $row, ActionSchedulerPhaseReason::SETTLE_EXPIRED )
		);
	}

	/**
	 * Decode a stored snapshot, failing the analysis if it is missing or unreadable.
	 *
	 * @param array  $row     Analysis with snapshot columns.
	 * @param string $column  `before_snapshot` or `immediate_snapshot`.
	 * @param string $outcome Settle outcome to record on failure.
	 * @return OptionsSnapshot|null
	 */
	private function decode_snapshot( array $row, $column, $outcome ) {
		try {
			return $this->snapshot_codec->decode( $row[ $column ] );
		} catch ( UnexpectedValueException $e ) {
			$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_SNAPSHOT_CORRUPT, $outcome );
		} catch ( Throwable $e ) {
			$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_ANALYSIS_FAILED, $outcome );
		}

		return null;
	}

	/**
	 * Abandon an analysis if it is still open.
	 *
	 * @param int    $id         Analysis ID.
	 * @param string $error_code Reason.
	 * @return void
	 */
	private function abandon_open( $id, $error_code ) {
		try {
			$row = $this->repository->find_signals( $id );
		} catch ( Throwable $e ) {
			return;
		}
		if ( null !== $row && in_array( $row['status'], array( AnalysisStatus::CAPTURED, AnalysisStatus::AWAITING_SETTLE ), true ) ) {
			$this->finish( $row, AnalysisStatus::ABANDONED, $error_code, SettleOutcome::NOT_APPLICABLE );
		}
	}

	/**
	 * Fail an analysis whose update never reported completion.
	 *
	 * @param int $id Analysis ID.
	 * @return void
	 */
	private function fail_if_captured( $id ) {
		try {
			$row = $this->repository->find_signals( $id );
		} catch ( Throwable $e ) {
			return;
		}
		if ( null !== $row && AnalysisStatus::CAPTURED === $row['status'] ) {
			$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_UPDATE_NOT_COMPLETED, SettleOutcome::NOT_APPLICABLE );
		}
	}

	/**
	 * Move an analysis to a final non-completed state.
	 *
	 * @param array  $row        Analysis.
	 * @param string $status     Final status.
	 * @param string $error_code Error code.
	 * @param string $outcome    Settle outcome.
	 * @return void
	 */
	private function finish( array $row, $status, $error_code, $outcome ) {
		$row = $this->with_signal_columns( $row );
		if ( null === $row ) {
			return;
		}

		$this->transition(
			$row,
			array(
				'status'        => $status,
				'error_code'    => $error_code,
				'error_message' => array_key_exists( $error_code, self::ERROR_MESSAGES ) ? self::ERROR_MESSAGES[ $error_code ] : 'The WordPress update did not succeed.',
			) + $this->closing_changes( $outcome ),
			$this->cron->close( $row, self::cron_reason( $status, $error_code ) ),
			$this->action_scheduler->close( $row, self::action_scheduler_reason( $status, $error_code ) )
		);
	}

	/**
	 * Reason for Cron phases still unresolved when an analysis ends unsuccessfully.
	 *
	 * @param string $status     Final status.
	 * @param string $error_code Error code.
	 * @return string CronPhaseReason.
	 */
	private static function cron_reason( $status, $error_code ) {
		if ( AnalysisStatus::ABANDONED === $status ) {
			return CronPhaseReason::ANALYSIS_ABANDONED;
		}
		if ( AnalysisStatus::FAILED === $status && ! in_array( $error_code, array( self::ERROR_ANALYSIS_FAILED, self::ERROR_SNAPSHOT_CORRUPT ), true ) ) {
			return CronPhaseReason::UPDATE_FAILED;
		}

		return CronPhaseReason::ANALYSIS_ENDED;
	}

	/**
	 * Reason for Action Scheduler phases still unresolved when an analysis
	 * ends unsuccessfully: the same rule as for Cron.
	 *
	 * @param string $status     Final status.
	 * @param string $error_code Error code.
	 * @return string ActionSchedulerPhaseReason.
	 */
	private static function action_scheduler_reason( $status, $error_code ) {
		if ( AnalysisStatus::ABANDONED === $status ) {
			return ActionSchedulerPhaseReason::ANALYSIS_ABANDONED;
		}
		if ( AnalysisStatus::FAILED === $status && ! in_array( $error_code, array( self::ERROR_ANALYSIS_FAILED, self::ERROR_SNAPSHOT_CORRUPT ), true ) ) {
			return ActionSchedulerPhaseReason::UPDATE_FAILED;
		}

		return ActionSchedulerPhaseReason::ANALYSIS_ENDED;
	}

	/**
	 * The analysis with its Cron and Action Scheduler columns, re-read if only metadata was loaded.
	 *
	 * Null if it changed status meanwhile (the transition would not apply).
	 * If it cannot be read, the metadata row is returned: the analysis still
	 * ends, and only its Cron and Action Scheduler snapshots are cleared.
	 *
	 * @param array $row Analysis (metadata or full row).
	 * @return array|null
	 */
	private function with_signal_columns( array $row ) {
		if ( array_key_exists( CronObservation::BEFORE_SNAPSHOT, $row ) ) {
			return $row;
		}

		try {
			$full = $this->repository->find_signals( (int) $row['id'] );
		} catch ( Throwable $e ) {
			return $row;
		}

		return null !== $full && $full['status'] === $row['status'] ? $full : null;
	}

	/**
	 * Columns set on every final state: outcome, timestamps, and removal of the
	 * temporary options snapshots and the open-analysis marker. Cron and
	 * Action Scheduler snapshots are removed by their observations' changes of
	 * the same write.
	 *
	 * @param string $outcome Settle outcome.
	 * @return array<string, mixed>
	 */
	private function closing_changes( $outcome ) {
		$now = self::datetime( $this->now() );

		return array(
			'settle_outcome'             => $outcome,
			'options_before_snapshot'    => null,
			'options_immediate_snapshot' => null,
			'active_plugin'              => null,
			'completed_at'               => $now,
			'updated_at'                 => $now,
		);
	}

	/**
	 * Apply a transition from the row's current status; storage errors are swallowed.
	 *
	 * Cron and Action Scheduler changes are written in the same statement. If
	 * it fails, it is retried without signal payload, one signal at a time
	 * (attempts()), so neither signal's data can keep the options analysis from
	 * moving on, and one signal's failure never drops the other's data.
	 *
	 * @param array $row          Analysis.
	 * @param array $changes      Column values.
	 * @param array $cron_changes Cron column values.
	 * @param array $action_scheduler_changes Action Scheduler column values.
	 * @return bool|null True if applied, false if the analysis was no longer in
	 *                   the row's status (another request changed it first),
	 *                   null if it could not be stored.
	 */
	private function transition( array $row, array $changes, array $cron_changes = array(), array $action_scheduler_changes = array() ) {
		foreach ( self::attempts( $changes, $cron_changes, $action_scheduler_changes ) as $attempt ) {
			try {
				return (bool) $this->repository->transition( (int) $row['id'], $row['status'], $attempt );
			} catch ( Throwable $e ) {
				continue; // Retry without the next signal payload, if any is left.
			}
		}

		// All attempts failed: left open; a later request settles, expires or abandons it.
		return null;
	}

	/**
	 * Column sets to write, in order, until one succeeds.
	 *
	 * First everything. Then without the Action Scheduler payload (usually the
	 * largest), keeping Cron; then without the Cron payload, keeping Action
	 * Scheduler; then without both. A signal without payload in this write is
	 * never stripped, and duplicate sets are skipped, so a write that failed
	 * for another reason is not retried pointlessly. Options columns and the
	 * transition itself are the same in every attempt: a signal's
	 * `without_payload()` only marks its own phases `storage_failed`.
	 *
	 * @param array $changes                  Options and lifecycle column values.
	 * @param array $cron_changes             Cron column values.
	 * @param array $action_scheduler_changes Action Scheduler column values.
	 * @return array<int, array<string, mixed>>
	 */
	private static function attempts( array $changes, array $cron_changes, array $action_scheduler_changes ) {
		$cron_fallback             = CronObservation::without_payload( $cron_changes );
		$action_scheduler_fallback = ActionSchedulerObservation::without_payload( $action_scheduler_changes );

		$attempts = array();
		foreach (
			array(
				$changes + $cron_changes + $action_scheduler_changes,
				$changes + $cron_changes + $action_scheduler_fallback,
				$changes + $cron_fallback + $action_scheduler_changes,
				$changes + $cron_fallback + $action_scheduler_fallback,
			) as $attempt
		) {
			if ( ! in_array( $attempt, $attempts, true ) ) {
				$attempts[] = $attempt;
			}
		}

		return $attempts;
	}

	/**
	 * Analyses awaiting settlement that were created by earlier requests.
	 *
	 * @return array<int, array>
	 */
	private function awaiting_from_earlier_requests() {
		try {
			$open = $this->repository->find_open();
		} catch ( Throwable $e ) {
			return array();
		}

		$pending = array();
		foreach ( $open as $row ) {
			if ( AnalysisStatus::AWAITING_SETTLE === $row['status'] && ! in_array( (int) $row['id'], $this->request_analyses, true ) ) {
				$pending[] = $row;
			}
		}

		return $pending;
	}

	/**
	 * Whether an `awaiting_settle` analysis may still take a settled snapshot (now <= deadline).
	 *
	 * @param array $row Analysis.
	 * @return bool
	 */
	private function is_settle_window_open( array $row ) {
		$deadline = empty( $row['settle_deadline'] ) ? false : strtotime( $row['settle_deadline'] . ' UTC' );

		return false !== $deadline && $this->now() <= $deadline;
	}

	/**
	 * Whether a `captured` analysis is older than STALE_AFTER_SECONDS.
	 *
	 * @param array $row Analysis.
	 * @return bool
	 */
	private function is_stale( array $row ) {
		$started = strtotime( $row['started_at'] . ' UTC' );

		return false === $started || ( $this->now() - $started ) > self::STALE_AFTER_SECONDS;
	}

	/**
	 * Current Unix time from the injected clock, in whole seconds.
	 *
	 * @return int
	 */
	private function now() {
		return (int) floor( $this->precise_now() );
	}

	/**
	 * Current Unix time from the injected clock, with fractions if the clock has them.
	 *
	 * @return float
	 */
	private function precise_now() {
		$now = $this->now;
		if ( 'microtime' === $now ) {
			return microtime( true );
		}

		return (float) call_user_func( $now );
	}

	/**
	 * Unix time as a UTC MySQL DATETIME string.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function datetime( $time ) {
		return gmdate( 'Y-m-d H:i:s', $time );
	}

	/**
	 * Reduce a WordPress error code to a safe identifier.
	 *
	 * @param string $error_code Error code.
	 * @return string
	 */
	private static function sanitize_error_code( $error_code ) {
		$code = substr( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $error_code ) ), 0, 64 );

		return '' === $code ? self::ERROR_UPDATE_FAILED : $code;
	}
}
