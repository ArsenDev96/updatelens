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
 * Knows nothing about WordPress hooks (see PluginUpdateTracker). One instance
 * lives for one PHP request and remembers which analyses that request
 * created, so the update request's own shutdown never settles them.
 *
 * Observation rules:
 * - Settling happens at shutdown of a later wp-admin page request, or right
 *   before another update starts (whichever comes first), and only within
 *   SETTLE_WINDOW_SECONDS of the update; after that the analysis completes
 *   with outcome `expired` and no settled snapshot, so late settling never
 *   collects unrelated site activity.
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
	 * Returns the current Unix time.
	 *
	 * @var callable
	 */
	private $now;

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
	 * @param AnalysisRepository $repository Analysis storage.
	 * @param callable           $capture    Returns a fresh OptionsSnapshot.
	 * @param callable|null      $now        Returns the current Unix time. Default time().
	 */
	public function __construct( AnalysisRepository $repository, callable $capture, callable $now = null ) {
		$this->repository     = $repository;
		$this->capture        = $capture;
		$this->now            = null === $now ? 'time' : $now;
		$this->snapshot_codec = new OptionsSnapshotCodec();
		$this->diff_codec     = new OptionsDiffCodec();
		$this->diff_builder   = new OptionsDiffBuilder();
	}

	/**
	 * Analyzer wired to the running site. The snapshot provider is created on first use.
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

		return new self( new AnalysisRepository( $wpdb ), $capture );
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
	 * @return void
	 */
	public function update_starting( $plugin_file, array $plugin = array(), $user_id = 0 ) {
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

		try {
			$snapshot = call_user_func( $this->capture );
		} catch ( Throwable $e ) {
			foreach ( $to_settle as $row ) {
				$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_ANALYSIS_FAILED, SettleOutcome::NEXT_UPDATE );
			}
			return;
		}

		foreach ( $to_settle as $row ) {
			$this->settle( $row, $snapshot, SettleOutcome::NEXT_UPDATE );
		}

		if ( null !== $plugin_file ) {
			$this->begin( $plugin_file, $plugin, $user_id, $snapshot );
		}
	}

	/**
	 * WordPress reported the end of a single-plugin update.
	 *
	 * On success, stores the during-update diff (BEFORE → IMMEDIATE) and keeps
	 * both snapshots until the settle phase ends.
	 *
	 * @param string      $plugin_file   Plugin basename.
	 * @param string|null $error_code    Null if the update succeeded, else a WordPress error code.
	 * @param string|null $version_after Installed version after the update.
	 * @return void
	 */
	public function update_finished( $plugin_file, $error_code, $version_after ) {
		if ( ! isset( $this->request_analyses[ $plugin_file ] ) ) {
			return; // Not an update this request is analysing.
		}

		try {
			$row = $this->repository->find( $this->request_analyses[ $plugin_file ] );
		} catch ( Throwable $e ) {
			return;
		}
		if ( null === $row || AnalysisStatus::CAPTURED !== $row['status'] ) {
			return; // Already handled.
		}

		if ( null !== $error_code ) {
			$this->finish( $row, AnalysisStatus::FAILED, self::sanitize_error_code( $error_code ), SettleOutcome::NOT_APPLICABLE );
			return;
		}

		$before = $this->decode_snapshot( $row, 'before_snapshot', SettleOutcome::NOT_APPLICABLE );
		if ( null === $before ) {
			return;
		}

		try {
			$immediate          = call_user_func( $this->capture );
			$during_update_json = $this->diff_codec->encode( $this->diff_builder->build( $before, $immediate ) );
			$immediate_json     = $this->snapshot_codec->encode( $immediate );
		} catch ( IncompatibleSnapshotsException $e ) {
			$this->finish( $row, AnalysisStatus::INCOMPATIBLE, self::ERROR_CONTEXT_CHANGED, SettleOutcome::NOT_APPLICABLE );
			return;
		} catch ( Throwable $e ) {
			$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_ANALYSIS_FAILED, SettleOutcome::NOT_APPLICABLE );
			return;
		}

		$now = $this->now();
		$this->transition(
			$row,
			array(
				'status'             => AnalysisStatus::AWAITING_SETTLE,
				'version_after'      => null === $version_after ? null : (string) $version_after,
				'during_update_diff' => $during_update_json,
				'immediate_snapshot' => $immediate_json,
				'settle_deadline'    => self::datetime( $now + self::SETTLE_WINDOW_SECONDS ),
				'updated_at'         => self::datetime( $now ),
			)
		);
	}

	/**
	 * The request is ending (WordPress `shutdown`).
	 *
	 * Fails analyses this request started whose update never reported back.
	 * On a wp-admin page request that ran no update, also abandons stale
	 * analyses, expires those past their settle window and settles the rest.
	 *
	 * A plugin activated or deactivated during this request (e.g. the
	 * reactivation request after update.php) did not run a full request
	 * lifecycle with its new code, so its analysis waits for a later request.
	 *
	 * @param bool     $is_admin_page_request Whether this is a wp-admin page request (not Ajax, cron, CLI or REST).
	 * @param string[] $activation_changed    Plugins whose active state changed during this request.
	 * @return void
	 */
	public function request_ending( $is_admin_page_request, array $activation_changed = array() ) {
		foreach ( $this->request_analyses as $id ) {
			$this->fail_if_captured( $id );
		}

		if ( ! $is_admin_page_request || $this->update_seen ) {
			return;
		}

		try {
			$open = $this->repository->find_open();
		} catch ( Throwable $e ) {
			return;
		}

		$snapshot = null;
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

			if ( in_array( $row['plugin_file'], $activation_changed, true ) ) {
				continue;
			}

			if ( null === $snapshot ) {
				try {
					$snapshot = call_user_func( $this->capture );
				} catch ( Throwable $e ) {
					return; // Try again on a later request within the window.
				}
			}
			$this->settle( $row, $snapshot, SettleOutcome::ADMIN_SHUTDOWN );
		}
	}

	/**
	 * Create the analysis with its BEFORE snapshot.
	 *
	 * @param string          $plugin_file Plugin basename.
	 * @param array           $plugin      Plugin header data.
	 * @param int             $user_id     User ID.
	 * @param OptionsSnapshot $snapshot    BEFORE snapshot.
	 * @return void
	 */
	private function begin( $plugin_file, array $plugin, $user_id, OptionsSnapshot $snapshot ) {
		try {
			$open = $this->repository->find_open_for_plugin( $plugin_file );
			if ( null !== $open ) {
				if ( AnalysisStatus::CAPTURED === $open['status'] && ! $this->is_stale( $open ) ) {
					return; // Another request is updating this plugin right now.
				}
				$this->finish( $open, AnalysisStatus::ABANDONED, AnalysisStatus::CAPTURED === $open['status'] ? self::ERROR_STALE : self::ERROR_ANOTHER_UPDATE_STARTED, SettleOutcome::NOT_APPLICABLE );
			}

			$now = self::datetime( $this->now() );
			$id  = $this->repository->create(
				array(
					'plugin_file'     => $plugin_file,
					'plugin_name'     => isset( $plugin['name'] ) ? (string) $plugin['name'] : '',
					'version_before'  => isset( $plugin['version'] ) ? (string) $plugin['version'] : '',
					'user_id'         => (int) $user_id,
					'status'          => AnalysisStatus::CAPTURED,
					'active_plugin'   => $plugin_file,
					'started_at'      => $now,
					'updated_at'      => $now,
					'before_snapshot' => $this->snapshot_codec->encode( $snapshot ),
				)
			);
		} catch ( Throwable $e ) {
			return; // No analysis for this update; the update itself continues.
		}

		$this->request_analyses[ $plugin_file ] = $id;
	}

	/**
	 * Store the post-update (IMMEDIATE → SETTLED) and final (BEFORE → SETTLED)
	 * diffs and complete the analysis.
	 *
	 * @param array           $open     Open analysis in `awaiting_settle` (metadata columns).
	 * @param OptionsSnapshot $settled  Settled snapshot.
	 * @param string          $outcome  SettleOutcome::ADMIN_SHUTDOWN or NEXT_UPDATE.
	 * @return void
	 */
	private function settle( array $open, OptionsSnapshot $settled, $outcome ) {
		try {
			$row = $this->repository->find( (int) $open['id'] );
		} catch ( Throwable $e ) {
			return;
		}
		if ( null === $row || AnalysisStatus::AWAITING_SETTLE !== $row['status'] ) {
			return;
		}

		$before = $this->decode_snapshot( $row, 'before_snapshot', $outcome );
		if ( null === $before ) {
			return;
		}
		$immediate = $this->decode_snapshot( $row, 'immediate_snapshot', $outcome );
		if ( null === $immediate ) {
			return;
		}

		try {
			$post_update_json = $this->diff_codec->encode( $this->diff_builder->build( $immediate, $settled ) );
			$final_json       = $this->diff_codec->encode( $this->diff_builder->build( $before, $settled ) );
		} catch ( IncompatibleSnapshotsException $e ) {
			$this->finish( $row, AnalysisStatus::INCOMPATIBLE, self::ERROR_CONTEXT_CHANGED, $outcome );
			return;
		} catch ( Throwable $e ) {
			$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_ANALYSIS_FAILED, $outcome );
			return;
		}

		$this->transition(
			$row,
			array(
				'status'           => AnalysisStatus::COMPLETED,
				'post_update_diff' => $post_update_json,
				'final_diff'       => $final_json,
			) + $this->closing_changes( $outcome )
		);
	}

	/**
	 * Complete an analysis whose settle window passed without a settled snapshot.
	 *
	 * The during-update diff stays; post-update and final diffs remain NULL.
	 *
	 * @param array $row Open analysis in `awaiting_settle`.
	 * @return void
	 */
	private function expire( array $row ) {
		$this->transition(
			$row,
			array( 'status' => AnalysisStatus::COMPLETED ) + $this->closing_changes( SettleOutcome::EXPIRED )
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
			$row = $this->repository->find( $id );
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
			$row = $this->repository->find( $id );
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
		$this->transition(
			$row,
			array(
				'status'        => $status,
				'error_code'    => $error_code,
				'error_message' => array_key_exists( $error_code, self::ERROR_MESSAGES ) ? self::ERROR_MESSAGES[ $error_code ] : 'The WordPress update did not succeed.',
			) + $this->closing_changes( $outcome )
		);
	}

	/**
	 * Columns set on every final state: outcome, timestamps, and removal of the
	 * temporary snapshots and the open-analysis marker.
	 *
	 * @param string $outcome Settle outcome.
	 * @return array<string, mixed>
	 */
	private function closing_changes( $outcome ) {
		$now = self::datetime( $this->now() );

		return array(
			'settle_outcome'     => $outcome,
			'before_snapshot'    => null,
			'immediate_snapshot' => null,
			'active_plugin'      => null,
			'completed_at'       => $now,
			'updated_at'         => $now,
		);
	}

	/**
	 * Apply a transition from the row's current status; storage errors are swallowed.
	 *
	 * @param array $row     Analysis.
	 * @param array $changes Column values.
	 * @return void
	 */
	private function transition( array $row, array $changes ) {
		try {
			$this->repository->transition( (int) $row['id'], $row['status'], $changes );
		} catch ( Throwable $e ) {
			return; // Left open; a later request settles, expires or abandons it.
		}
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
	 * Current Unix time from the injected clock.
	 *
	 * @return int
	 */
	private function now() {
		return (int) call_user_func( $this->now );
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
