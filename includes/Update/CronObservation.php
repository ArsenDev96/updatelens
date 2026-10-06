<?php
/**
 * WP-Cron signal of a plugin update analysis.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

use Throwable;
use UpdateLens\Diff\CronDiffBuilder;
use UpdateLens\Diff\IncompatibleSnapshotsException;
use UpdateLens\Snapshot\CronSnapshot;
use UpdateLens\Snapshot\MalformedCronStateException;
use UpdateLens\Storage\CronDiffCodec;
use UpdateLens\Storage\CronSnapshotCodec;

defined( 'ABSPATH' ) || exit;

/**
 * Captures, compares and stores WP-Cron at the analysis lifecycle moments
 * that PluginUpdateAnalyzer drives for `wp_options`.
 *
 * Cron is an independent signal: nothing here throws, and every failure is
 * confined to the Cron phases that depend on it, recorded as a
 * CronPhaseReason. The methods return column changes that the analyzer
 * writes together with its own transition, so a terminal state always
 * clears the temporary Cron snapshots in the same write.
 *
 * Phase dependencies: during_update needs BEFORE + IMMEDIATE, post_update
 * needs IMMEDIATE + SETTLED, final needs BEFORE + SETTLED. A missing
 * snapshot only affects the phases that need it.
 */
final class CronObservation {

	/**
	 * Temporary BEFORE snapshot column.
	 */
	const BEFORE_SNAPSHOT = 'cron_before_snapshot';

	/**
	 * Temporary IMMEDIATE snapshot column.
	 */
	const IMMEDIATE_SNAPSHOT = 'cron_immediate_snapshot';

	/**
	 * Diff and reason column per phase.
	 */
	const PHASES = array(
		ObservationPhase::DURING_UPDATE => array( 'cron_during_update_diff', 'cron_during_update_reason' ),
		ObservationPhase::POST_UPDATE   => array( 'cron_post_update_diff', 'cron_post_update_reason' ),
		ObservationPhase::FINAL         => array( 'cron_final_diff', 'cron_final_reason' ),
	);

	/**
	 * Phases that need each stored snapshot.
	 */
	const DEPENDENTS = array(
		self::BEFORE_SNAPSHOT    => array( ObservationPhase::DURING_UPDATE, ObservationPhase::FINAL ),
		self::IMMEDIATE_SNAPSHOT => array( ObservationPhase::POST_UPDATE ),
	);

	/**
	 * Returns a fresh CronSnapshot of the site.
	 *
	 * @var callable
	 */
	private $capture;

	/**
	 * Snapshot persistence format.
	 *
	 * @var CronSnapshotCodec
	 */
	private $snapshot_codec;

	/**
	 * Diff persistence format.
	 *
	 * @var CronDiffCodec
	 */
	private $diff_codec;

	/**
	 * Diff builder.
	 *
	 * @var CronDiffBuilder
	 */
	private $diff_builder;

	/**
	 * Constructor.
	 *
	 * @param callable $capture Returns a fresh CronSnapshot.
	 */
	public function __construct( callable $capture ) {
		$this->capture        = $capture;
		$this->snapshot_codec = new CronSnapshotCodec();
		$this->diff_codec     = new CronDiffCodec();
		$this->diff_builder   = new CronDiffBuilder();
	}

	/**
	 * Capture the current Cron state.
	 *
	 * @return CronSnapshot|string Snapshot, or a CronPhaseReason if it could not be taken.
	 */
	public function capture() {
		try {
			$snapshot = call_user_func( $this->capture );
		} catch ( MalformedCronStateException $e ) {
			return CronPhaseReason::MALFORMED_CRON_STATE;
		} catch ( Throwable $e ) {
			return CronPhaseReason::SNAPSHOT_UNAVAILABLE;
		}

		return $snapshot instanceof CronSnapshot ? $snapshot : CronPhaseReason::SNAPSHOT_UNAVAILABLE;
	}

	/**
	 * Columns for a new analysis: the BEFORE snapshot, or the reasons for the phases that need it.
	 *
	 * @param CronSnapshot|string $before BEFORE capture.
	 * @return array<string, string|null>
	 */
	public function before( $before ) {
		if ( is_string( $before ) ) {
			return self::reasons( self::DEPENDENTS[ self::BEFORE_SNAPSHOT ], $before );
		}

		try {
			return array( self::BEFORE_SNAPSHOT => $this->snapshot_codec->encode( $before ) );
		} catch ( Throwable $e ) {
			return self::reasons( self::DEPENDENTS[ self::BEFORE_SNAPSHOT ], CronPhaseReason::SNAPSHOT_UNAVAILABLE );
		}
	}

	/**
	 * Columns after a successful update: the IMMEDIATE snapshot and the during-update phase.
	 *
	 * The IMMEDIATE snapshot is kept for the post-update phase even when the
	 * during-update phase is unavailable (e.g. BEFORE was malformed).
	 *
	 * @param array               $row       Analysis with Cron columns.
	 * @param CronSnapshot|string $immediate IMMEDIATE capture.
	 * @return array<string, string|null>
	 */
	public function immediate( array $row, $immediate ) {
		$changes = array();

		if ( is_string( $immediate ) ) {
			$changes += self::reasons( self::DEPENDENTS[ self::IMMEDIATE_SNAPSHOT ], $immediate );
		} else {
			try {
				$changes[ self::IMMEDIATE_SNAPSHOT ] = $this->snapshot_codec->encode( $immediate );
			} catch ( Throwable $e ) {
				$changes += self::reasons( self::DEPENDENTS[ self::IMMEDIATE_SNAPSHOT ], CronPhaseReason::SNAPSHOT_UNAVAILABLE );
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
	 * @param array               $row     Analysis with Cron columns.
	 * @param CronSnapshot|string $settled SETTLED capture.
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
			// Only an analysis that started before Cron observation existed.
			$changes += self::reasons( array( ObservationPhase::DURING_UPDATE ), CronPhaseReason::NOT_CAPTURED );
		}

		return $changes;
	}

	/**
	 * Columns for any other final state: unresolved phases get the reason,
	 * resolved ones keep their diff or earlier reason, snapshots are removed.
	 *
	 * If the row was loaded without its Cron columns (it could not be re-read),
	 * phase states are unknown: only the snapshots are removed, so an existing
	 * diff never gets a reason next to it.
	 *
	 * @param array  $row    Analysis with Cron columns.
	 * @param string $reason CronPhaseReason.
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
	 * The same changes without any Cron payload, for retrying a write that failed.
	 *
	 * Snapshots and diffs are dropped; every phase that loses its diff or a
	 * snapshot it needs is marked `storage_failed`. Reasons already set stay.
	 *
	 * @param array<string, string|null> $changes Cron column changes.
	 * @return array<string, string|null>
	 */
	public static function without_payload( array $changes ) {
		foreach ( self::DEPENDENTS as $column => $phases ) {
			if ( isset( $changes[ $column ] ) ) {
				$changes[ $column ] = null;
				foreach ( $phases as $phase ) {
					if ( ! isset( $changes[ self::PHASES[ $phase ][1] ] ) ) {
						$changes[ self::PHASES[ $phase ][1] ] = CronPhaseReason::STORAGE_FAILED;
					}
				}
			}
		}

		foreach ( self::PHASES as $columns ) {
			list( $diff, $reason ) = $columns;
			if ( isset( $changes[ $diff ] ) ) {
				$changes[ $diff ]   = null;
				$changes[ $reason ] = CronPhaseReason::STORAGE_FAILED;
			}
		}

		return $changes;
	}

	/**
	 * Diff or reason for one phase.
	 *
	 * @param string              $phase ObservationPhase.
	 * @param CronSnapshot|string $from  Earlier snapshot or reason.
	 * @param CronSnapshot|string $to    Later snapshot or reason.
	 * @return array<string, string>
	 */
	private function phase( $phase, $from, $to ) {
		list( $diff_column, $reason_column ) = self::PHASES[ $phase ];

		if ( is_string( $from ) ) {
			return array( $reason_column => $from );
		}
		if ( is_string( $to ) ) {
			return array( $reason_column => $to );
		}

		try {
			return array( $diff_column => $this->diff_codec->encode( $this->diff_builder->build( $from, $to ) ) );
		} catch ( IncompatibleSnapshotsException $e ) {
			return array( $reason_column => CronPhaseReason::FINGERPRINT_CONTEXT_CHANGED );
		} catch ( Throwable $e ) {
			return array( $reason_column => CronPhaseReason::ANALYSIS_FAILED );
		}
	}

	/**
	 * A stored snapshot, or the reason it is unavailable.
	 *
	 * @param array  $row    Analysis.
	 * @param string $column Snapshot column.
	 * @return CronSnapshot|string
	 */
	private function stored( array $row, $column ) {
		if ( ! isset( $row[ $column ] ) ) {
			return CronPhaseReason::NOT_CAPTURED;
		}

		try {
			return $this->snapshot_codec->decode( $row[ $column ] );
		} catch ( Throwable $e ) {
			return CronPhaseReason::SNAPSHOT_UNAVAILABLE;
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
	 * @param string   $reason CronPhaseReason.
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
