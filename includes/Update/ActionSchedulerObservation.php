<?php
/**
 * Action Scheduler signal of a plugin update analysis.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

use Throwable;
use UpdateLens\Diff\ActionSchedulerDiffBuilder;
use UpdateLens\Diff\IncompatibleSnapshotsException;
use UpdateLens\Snapshot\ActionSchedulerSnapshot;
use UpdateLens\Snapshot\ActionSchedulerUnavailableException;
use UpdateLens\Snapshot\MalformedActionSchedulerStateException;
use UpdateLens\Storage\ActionSchedulerDiffCodec;
use UpdateLens\Storage\ActionSchedulerSnapshotCodec;

defined( 'ABSPATH' ) || exit;

/**
 * Captures, compares and stores the active Action Scheduler actions at the
 * analysis lifecycle moments that PluginUpdateAnalyzer drives for
 * `wp_options`, like CronObservation does for WP-Cron.
 *
 * Action Scheduler is an independent signal: nothing here throws, and every
 * failure (including Action Scheduler not being installed, which is normal)
 * is confined to the Action Scheduler phases that depend on it, recorded as
 * an ActionSchedulerPhaseReason. Capturing is read-only
 * (ActionSchedulerSnapshotProvider): it never initializes, runs or changes
 * Action Scheduler. The methods return column changes that the analyzer
 * writes together with its own transition, so a terminal state always
 * clears the temporary snapshots in the same write.
 *
 * Phase dependencies: during_update needs BEFORE + IMMEDIATE, post_update
 * needs IMMEDIATE + SETTLED, final needs BEFORE + SETTLED. A phase is
 * available only when both of its captures are readable snapshots;
 * otherwise it carries the reason of the first capture that failed. So a
 * failing capture (or an availability change) only affects the phases that
 * need it, and no diff is ever invented across an unavailable capture.
 *
 * Absence is resolved per phase from both captures, never from the first
 * one alone: a capture that finds no Action Scheduler is kept as
 * NOT_INSTALLED_MARKER in its temporary snapshot column, and the phase gets
 * `not_installed_throughout` (absent at both), `newly_detected` (readable
 * at the later capture) or the later capture's failure. Readable first and
 * absent later is `no_longer_detected`. A phase whose later capture never
 * happens gets the lifecycle reason (update failed, settle expired, …).
 */
final class ActionSchedulerObservation {

	/**
	 * Temporary BEFORE snapshot column.
	 */
	const BEFORE_SNAPSHOT = 'action_scheduler_before_snapshot';

	/**
	 * Temporary IMMEDIATE snapshot column.
	 */
	const IMMEDIATE_SNAPSHOT = 'action_scheduler_immediate_snapshot';

	/**
	 * Temporary snapshot column value for a capture that found no Action
	 * Scheduler (never valid codec JSON). Cleared with the snapshots.
	 */
	const NOT_INSTALLED_MARKER = 'not_installed';

	/**
	 * Diff and reason column per phase.
	 */
	const PHASES = array(
		ObservationPhase::DURING_UPDATE => array( 'action_scheduler_during_update_diff', 'action_scheduler_during_update_reason' ),
		ObservationPhase::POST_UPDATE   => array( 'action_scheduler_post_update_diff', 'action_scheduler_post_update_reason' ),
		ObservationPhase::FINAL         => array( 'action_scheduler_final_diff', 'action_scheduler_final_reason' ),
	);

	/**
	 * Phases that need each stored snapshot.
	 */
	const DEPENDENTS = array(
		self::BEFORE_SNAPSHOT    => array( ObservationPhase::DURING_UPDATE, ObservationPhase::FINAL ),
		self::IMMEDIATE_SNAPSHOT => array( ObservationPhase::POST_UPDATE ),
	);

	/**
	 * Returns a fresh ActionSchedulerSnapshot of the site, or null when Action Scheduler is not observed.
	 *
	 * @var callable|null
	 */
	private $capture;

	/**
	 * Snapshot persistence format.
	 *
	 * @var ActionSchedulerSnapshotCodec
	 */
	private $snapshot_codec;

	/**
	 * Diff persistence format.
	 *
	 * @var ActionSchedulerDiffCodec
	 */
	private $diff_codec;

	/**
	 * Diff builder.
	 *
	 * @var ActionSchedulerDiffBuilder
	 */
	private $diff_builder;

	/**
	 * Constructor.
	 *
	 * @param callable|null $capture Returns a fresh ActionSchedulerSnapshot (throws
	 *                               ActionSchedulerUnavailableException or
	 *                               MalformedActionSchedulerStateException). Null:
	 *                               the site has no Action Scheduler (`not_installed`).
	 */
	public function __construct( ?callable $capture = null ) {
		$this->capture        = $capture;
		$this->snapshot_codec = new ActionSchedulerSnapshotCodec();
		$this->diff_codec     = new ActionSchedulerDiffCodec();
		$this->diff_builder   = new ActionSchedulerDiffBuilder();
	}

	/**
	 * Capture the current Action Scheduler state.
	 *
	 * @return ActionSchedulerSnapshot|string Snapshot, or an ActionSchedulerPhaseReason if it could not be taken.
	 */
	public function capture() {
		if ( null === $this->capture ) {
			return ActionSchedulerPhaseReason::NOT_INSTALLED;
		}

		try {
			$snapshot = call_user_func( $this->capture );
		} catch ( ActionSchedulerUnavailableException $e ) {
			return ActionSchedulerPhaseReason::from_unavailable( $e->get_reason() );
		} catch ( MalformedActionSchedulerStateException $e ) {
			return ActionSchedulerPhaseReason::from_malformed( $e->get_reason() );
		} catch ( Throwable $e ) {
			return ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE;
		}

		return $snapshot instanceof ActionSchedulerSnapshot ? $snapshot : ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE;
	}

	/**
	 * Columns for a new analysis: the BEFORE snapshot, or the reasons for the phases that need it.
	 *
	 * @param ActionSchedulerSnapshot|string $before BEFORE capture.
	 * @return array<string, string|null>
	 */
	public function before( $before ) {
		if ( ActionSchedulerPhaseReason::NOT_INSTALLED === $before ) {
			// Resolved with the later captures: absence alone decides no phase.
			return array( self::BEFORE_SNAPSHOT => self::NOT_INSTALLED_MARKER );
		}
		if ( is_string( $before ) ) {
			return self::reasons( self::DEPENDENTS[ self::BEFORE_SNAPSHOT ], $before );
		}

		try {
			return array( self::BEFORE_SNAPSHOT => $this->snapshot_codec->encode( $before ) );
		} catch ( Throwable $e ) {
			return self::reasons( self::DEPENDENTS[ self::BEFORE_SNAPSHOT ], ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE );
		}
	}

	/**
	 * Columns after a successful update: the IMMEDIATE snapshot and the during-update phase.
	 *
	 * The IMMEDIATE snapshot is kept for the post-update phase even when the
	 * during-update phase is unavailable (e.g. BEFORE was malformed).
	 *
	 * @param array                          $row       Analysis with Action Scheduler columns.
	 * @param ActionSchedulerSnapshot|string $immediate IMMEDIATE capture.
	 * @return array<string, string|null>
	 */
	public function immediate( array $row, $immediate ) {
		$changes = array();

		if ( ActionSchedulerPhaseReason::NOT_INSTALLED === $immediate ) {
			$changes[ self::IMMEDIATE_SNAPSHOT ] = self::NOT_INSTALLED_MARKER;
		} elseif ( is_string( $immediate ) ) {
			$changes += self::reasons( self::DEPENDENTS[ self::IMMEDIATE_SNAPSHOT ], $immediate );
		} else {
			try {
				$changes[ self::IMMEDIATE_SNAPSHOT ] = $this->snapshot_codec->encode( $immediate );
			} catch ( Throwable $e ) {
				$changes += self::reasons( self::DEPENDENTS[ self::IMMEDIATE_SNAPSHOT ], ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE );
			}
		}

		if ( ! self::is_resolved( $row, ObservationPhase::DURING_UPDATE ) ) {
			$changes += $this->phase( ObservationPhase::DURING_UPDATE, $this->stored( $row, self::BEFORE_SNAPSHOT ), $immediate );
		}

		return $changes;
	}

	/**
	 * Columns when the analysis settles: the post-update and final phases, and
	 * removal of the temporary snapshots.
	 *
	 * @param array                          $row     Analysis with Action Scheduler columns.
	 * @param ActionSchedulerSnapshot|string $settled SETTLED capture.
	 * @return array<string, string|null>
	 */
	public function settle( array $row, $settled ) {
		$changes = self::cleared();

		if ( ! self::is_resolved( $row, ObservationPhase::POST_UPDATE ) ) {
			$changes += $this->phase( ObservationPhase::POST_UPDATE, $this->stored( $row, self::IMMEDIATE_SNAPSHOT ), $settled );
		}
		if ( ! self::is_resolved( $row, ObservationPhase::FINAL ) ) {
			$changes += $this->phase( ObservationPhase::FINAL, $this->stored( $row, self::BEFORE_SNAPSHOT ), $settled );
		}
		if ( ! self::is_resolved( $row, ObservationPhase::DURING_UPDATE ) ) {
			// Only an analysis that started before Action Scheduler observation existed.
			$changes += self::reasons( array( ObservationPhase::DURING_UPDATE ), ActionSchedulerPhaseReason::NOT_CAPTURED );
		}

		return $changes;
	}

	/**
	 * Columns for any other final state: unresolved phases get the reason,
	 * resolved ones keep their diff or earlier reason, snapshots are removed.
	 *
	 * If the row was loaded without its Action Scheduler columns (it could not
	 * be re-read), phase states are unknown: only the snapshots are removed,
	 * so an existing diff never gets a reason next to it.
	 *
	 * @param array  $row    Analysis with Action Scheduler columns.
	 * @param string $reason ActionSchedulerPhaseReason.
	 * @return array<string, string|null>
	 */
	public function close( array $row, $reason ) {
		$changes = self::cleared();
		if ( ! array_key_exists( self::BEFORE_SNAPSHOT, $row ) ) {
			return $changes;
		}

		foreach ( array_keys( self::PHASES ) as $phase ) {
			if ( ! self::is_resolved( $row, $phase ) ) {
				$changes += self::reasons( array( $phase ), $reason );
			}
		}

		return $changes;
	}

	/**
	 * The same changes without any Action Scheduler payload, for retrying a write that failed.
	 *
	 * Snapshots and diffs are dropped; every phase that loses its diff or a
	 * snapshot it needs is marked `storage_failed`. Reasons and the
	 * not-installed marker (no payload) stay. Only Action Scheduler columns
	 * are touched.
	 *
	 * @param array<string, string|null> $changes Action Scheduler column changes.
	 * @return array<string, string|null>
	 */
	public static function without_payload( array $changes ) {
		foreach ( self::DEPENDENTS as $column => $phases ) {
			if ( isset( $changes[ $column ] ) && self::NOT_INSTALLED_MARKER !== $changes[ $column ] ) {
				$changes[ $column ] = null;
				foreach ( $phases as $phase ) {
					if ( ! isset( $changes[ self::PHASES[ $phase ][1] ] ) ) {
						$changes[ self::PHASES[ $phase ][1] ] = ActionSchedulerPhaseReason::STORAGE_FAILED;
					}
				}
			}
		}

		foreach ( self::PHASES as $columns ) {
			list( $diff, $reason ) = $columns;
			if ( isset( $changes[ $diff ] ) ) {
				$changes[ $diff ]   = null;
				$changes[ $reason ] = ActionSchedulerPhaseReason::STORAGE_FAILED;
			}
		}

		return $changes;
	}

	/**
	 * Diff or reason for one phase.
	 *
	 * @param string                         $phase ObservationPhase.
	 * @param ActionSchedulerSnapshot|string $from  Earlier snapshot or reason.
	 * @param ActionSchedulerSnapshot|string $to    Later snapshot or reason.
	 * @return array<string, string>
	 */
	private function phase( $phase, $from, $to ) {
		list( $diff_column, $reason_column ) = self::PHASES[ $phase ];

		if ( ActionSchedulerPhaseReason::NOT_INSTALLED === $from ) {
			if ( ActionSchedulerPhaseReason::NOT_INSTALLED === $to ) {
				return array( $reason_column => ActionSchedulerPhaseReason::NOT_INSTALLED_THROUGHOUT );
			}
			// Present at the later capture: readable, or the reason it was not.
			return array( $reason_column => is_string( $to ) ? $to : ActionSchedulerPhaseReason::NEWLY_DETECTED );
		}
		if ( is_string( $from ) ) {
			return array( $reason_column => $from );
		}
		if ( is_string( $to ) ) {
			return array( $reason_column => ActionSchedulerPhaseReason::NOT_INSTALLED === $to ? ActionSchedulerPhaseReason::NO_LONGER_DETECTED : $to );
		}

		try {
			return array( $diff_column => $this->diff_codec->encode( $this->diff_builder->build( $from, $to ) ) );
		} catch ( IncompatibleSnapshotsException $e ) {
			return array( $reason_column => ActionSchedulerPhaseReason::FINGERPRINT_CONTEXT_CHANGED );
		} catch ( Throwable $e ) {
			return array( $reason_column => ActionSchedulerPhaseReason::ANALYSIS_FAILED );
		}
	}

	/**
	 * A stored snapshot, or the reason it is unavailable.
	 *
	 * @param array  $row    Analysis.
	 * @param string $column Snapshot column.
	 * @return ActionSchedulerSnapshot|string
	 */
	private function stored( array $row, $column ) {
		if ( ! isset( $row[ $column ] ) ) {
			return ActionSchedulerPhaseReason::NOT_CAPTURED;
		}
		if ( self::NOT_INSTALLED_MARKER === $row[ $column ] ) {
			return ActionSchedulerPhaseReason::NOT_INSTALLED;
		}

		try {
			return $this->snapshot_codec->decode( $row[ $column ] );
		} catch ( Throwable $e ) {
			return ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE;
		}
	}

	/**
	 * Whether a phase already has a diff or a reason.
	 *
	 * @param array  $row   Analysis.
	 * @param string $phase ObservationPhase.
	 * @return bool
	 */
	private static function is_resolved( array $row, $phase ) {
		list( $diff, $reason ) = self::PHASES[ $phase ];

		return isset( $row[ $diff ] ) || isset( $row[ $reason ] );
	}

	/**
	 * Reason columns for phases.
	 *
	 * @param string[] $phases Phases.
	 * @param string   $reason ActionSchedulerPhaseReason.
	 * @return array<string, string>
	 */
	private static function reasons( array $phases, $reason ) {
		$changes = array();
		foreach ( $phases as $phase ) {
			$changes[ self::PHASES[ $phase ][1] ] = $reason;
		}

		return $changes;
	}

	/**
	 * Removal of the temporary snapshots.
	 *
	 * @return array<string, null>
	 */
	private static function cleared() {
		return array(
			self::BEFORE_SNAPSHOT    => null,
			self::IMMEDIATE_SNAPSHOT => null,
		);
	}
}
