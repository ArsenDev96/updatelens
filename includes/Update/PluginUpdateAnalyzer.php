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
 * Orchestrates BEFORE → update → IMMEDIATE → later admin request → SETTLED.
 *
 * Knows nothing about WordPress hooks (see PluginUpdateTracker). One instance
 * lives for one PHP request and remembers which analyses that request
 * created, so the update request's own shutdown never settles them.
 *
 * Attribution rules:
 * - Settling happens at shutdown of a later wp-admin page request, or right
 *   before another update starts (whichever comes first), so a later update's
 *   changes are never attributed to an earlier one.
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
	 * Settled at shutdown of a later wp-admin page request.
	 */
	const SETTLED_AT_ADMIN_SHUTDOWN = 'admin_shutdown';

	/**
	 * Settled right before another update started.
	 */
	const SETTLED_BEFORE_NEXT_UPDATE = 'next_update';

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
		self::ERROR_SNAPSHOT_CORRUPT       => 'The stored BEFORE snapshot could not be read.',
		self::ERROR_CONTEXT_CHANGED        => 'Option values cannot be compared because the fingerprint context changed (for example, rotated WordPress salts).',
		self::ERROR_STALE                  => 'The update never reported completion; the analysis expired.',
		self::ERROR_ANOTHER_UPDATE_STARTED => 'Another update ran before this analysis settled, so its changes cannot be attributed reliably.',
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
	 * single-plugin updates. Settles analyses from earlier requests first, so
	 * this update is never part of their settled diff.
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

		$pending = $first_update ? $this->awaiting_from_earlier_requests() : array();
		if ( ! $pending && null === $plugin_file ) {
			return;
		}

		try {
			$snapshot = call_user_func( $this->capture );
		} catch ( Throwable $e ) {
			foreach ( $pending as $row ) {
				$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_ANALYSIS_FAILED );
			}
			return;
		}

		foreach ( $pending as $row ) {
			$this->settle( $row, $snapshot, self::SETTLED_BEFORE_NEXT_UPDATE );
		}

		if ( null !== $plugin_file ) {
			$this->begin( $plugin_file, $plugin, $user_id, $snapshot );
		}
	}

	/**
	 * WordPress reported the end of a single-plugin update.
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
			$this->finish( $row, AnalysisStatus::FAILED, self::sanitize_error_code( $error_code ) );
			return;
		}

		$before = $this->decode_before( $row );
		if ( null === $before ) {
			return;
		}

		try {
			$diff_json = $this->diff_codec->encode( $this->diff_builder->build( $before, call_user_func( $this->capture ) ) );
		} catch ( IncompatibleSnapshotsException $e ) {
			$this->finish( $row, AnalysisStatus::INCOMPATIBLE, self::ERROR_CONTEXT_CHANGED );
			return;
		} catch ( Throwable $e ) {
			$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_ANALYSIS_FAILED );
			return;
		}

		$this->transition(
			$row,
			array(
				'status'         => AnalysisStatus::AWAITING_SETTLE,
				'version_after'  => null === $version_after ? null : (string) $version_after,
				'immediate_diff' => $diff_json,
				'updated_at'     => $this->timestamp(),
			)
		);
	}

	/**
	 * The request is ending (WordPress `shutdown`).
	 *
	 * Fails analyses this request started whose update never reported back.
	 * On a wp-admin page request that ran no update, also expires stale
	 * analyses and settles those awaiting a settled capture.
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
					$this->finish( $row, AnalysisStatus::ABANDONED, self::ERROR_STALE );
				}
				continue;
			}

			if ( in_array( $row['plugin_file'], $activation_changed, true ) ) {
				continue;
			}

			if ( null === $snapshot ) {
				try {
					$snapshot = call_user_func( $this->capture );
				} catch ( Throwable $e ) {
					return; // Try again on a later request.
				}
			}
			$this->settle( $row, $snapshot, self::SETTLED_AT_ADMIN_SHUTDOWN );
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
				$this->finish( $open, AnalysisStatus::ABANDONED, AnalysisStatus::CAPTURED === $open['status'] ? self::ERROR_STALE : self::ERROR_ANOTHER_UPDATE_STARTED );
			}

			$now = $this->timestamp();
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
	 * Compare BEFORE with a settled snapshot and complete the analysis.
	 *
	 * @param array           $row      Analysis in `awaiting_settle`.
	 * @param OptionsSnapshot $snapshot Settled snapshot.
	 * @param string          $trigger  What triggered settling.
	 * @return void
	 */
	private function settle( array $row, OptionsSnapshot $snapshot, $trigger ) {
		$before = $this->decode_before( $row );
		if ( null === $before ) {
			return;
		}

		try {
			$diff_json = $this->diff_codec->encode( $this->diff_builder->build( $before, $snapshot ) );
		} catch ( IncompatibleSnapshotsException $e ) {
			$this->finish( $row, AnalysisStatus::INCOMPATIBLE, self::ERROR_CONTEXT_CHANGED );
			return;
		} catch ( Throwable $e ) {
			$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_ANALYSIS_FAILED );
			return;
		}

		$now = $this->timestamp();
		$this->transition(
			$row,
			array(
				'status'          => AnalysisStatus::COMPLETED,
				'settled_diff'    => $diff_json,
				'settle_trigger'  => $trigger,
				'before_snapshot' => null,
				'active_plugin'   => null,
				'completed_at'    => $now,
				'updated_at'      => $now,
			)
		);
	}

	/**
	 * Decode the stored BEFORE snapshot, failing the analysis if it is unreadable.
	 *
	 * @param array $row Analysis.
	 * @return OptionsSnapshot|null
	 */
	private function decode_before( array $row ) {
		try {
			return $this->snapshot_codec->decode( $row['before_snapshot'] );
		} catch ( UnexpectedValueException $e ) {
			$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_SNAPSHOT_CORRUPT );
		} catch ( Throwable $e ) {
			$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_ANALYSIS_FAILED );
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
			$this->finish( $row, AnalysisStatus::ABANDONED, $error_code );
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
			$this->finish( $row, AnalysisStatus::FAILED, self::ERROR_UPDATE_NOT_COMPLETED );
		}
	}

	/**
	 * Move an analysis to a final state, clearing its BEFORE snapshot.
	 *
	 * @param array  $row        Analysis.
	 * @param string $status     Final status.
	 * @param string $error_code Error code.
	 * @return void
	 */
	private function finish( array $row, $status, $error_code ) {
		$now = $this->timestamp();
		$this->transition(
			$row,
			array(
				'status'          => $status,
				'error_code'      => $error_code,
				'error_message'   => array_key_exists( $error_code, self::ERROR_MESSAGES ) ? self::ERROR_MESSAGES[ $error_code ] : 'The WordPress update did not succeed.',
				'before_snapshot' => null,
				'active_plugin'   => null,
				'completed_at'    => $now,
				'updated_at'      => $now,
			)
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
			return; // Left open; a later request settles it or the stale rule expires it.
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
	 * Whether a `captured` analysis is older than STALE_AFTER_SECONDS.
	 *
	 * @param array $row Analysis.
	 * @return bool
	 */
	private function is_stale( array $row ) {
		$started = strtotime( $row['started_at'] . ' UTC' );

		return false === $started || ( (int) call_user_func( $this->now ) - $started ) > self::STALE_AFTER_SECONDS;
	}

	/**
	 * Current UTC time as a MySQL DATETIME string.
	 *
	 * @return string
	 */
	private function timestamp() {
		return gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->now ) );
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
