<?php
/**
 * Safe API representation of stored analyses.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Report;

use Throwable;
use UpdateLens\Storage\ActionSchedulerDiffCodec;
use UpdateLens\Storage\CronDiffCodec;
use UpdateLens\Storage\OptionsDiffCodec;
use UpdateLens\Update\ActionSchedulerObservation;
use UpdateLens\Update\ActionSchedulerPhaseReason;
use UpdateLens\Update\AnalysisStatus;
use UpdateLens\Update\CronObservation;
use UpdateLens\Update\CronPhaseReason;
use UpdateLens\Update\ObservationPhase;
use UpdateLens\Update\PluginUpdateAnalyzer;
use UpdateLens\Update\SettleOutcome;

defined( 'ABSPATH' ) || exit;

/**
 * Builds history rows and reports from analysis rows.
 *
 * Reports are provider-aware: each phase holds an `options`, a `cron` and an
 * `action_scheduler` object with independent availability. History rows carry per-phase,
 * per-signal `recorded`/`has_changes` flags computed in SQL, never diff data.
 *
 * Whitelists fields: snapshots, fingerprints, user IDs, stored error
 * messages and raw diff JSON are never part of the output, whatever the row
 * contains. Every stored value is validated: unknown statuses and outcomes
 * become `unknown`, invalid timestamps and plugin files become null, and an
 * unreadable diff becomes an unavailable phase (`data_corrupt`).
 *
 * Output types are fixed: integers (IDs, counts, bytes, deltas) are ints,
 * flags are bools, timestamps are UTC ISO 8601 strings (`2026-10-05T17:46:23Z`) or null.
 */
final class AnalysisReadModel {

	/**
	 * Value used for an unrecognised stored status, outcome or error code.
	 */
	const UNKNOWN = 'unknown';

	/**
	 * Known statuses.
	 */
	const STATUSES = array(
		AnalysisStatus::CAPTURED,
		AnalysisStatus::AWAITING_SETTLE,
		AnalysisStatus::COMPLETED,
		AnalysisStatus::FAILED,
		AnalysisStatus::INCOMPATIBLE,
		AnalysisStatus::ABANDONED,
	);

	/**
	 * Known settle outcomes.
	 */
	const SETTLE_OUTCOMES = array(
		SettleOutcome::ADMIN_SHUTDOWN,
		SettleOutcome::NEXT_UPDATE,
		SettleOutcome::EXPIRED,
		SettleOutcome::NOT_APPLICABLE,
	);

	/**
	 * Stored Cron phase reasons passed through to the API (CronPhaseReason).
	 * Any other stored value becomes `unknown`.
	 */
	const CRON_REASONS = array(
		CronPhaseReason::MALFORMED_CRON_STATE,
		CronPhaseReason::SNAPSHOT_UNAVAILABLE,
		CronPhaseReason::FINGERPRINT_CONTEXT_CHANGED,
		CronPhaseReason::NOT_CAPTURED,
		CronPhaseReason::STORAGE_FAILED,
		CronPhaseReason::ANALYSIS_FAILED,
		CronPhaseReason::SETTLE_EXPIRED,
		CronPhaseReason::UPDATE_FAILED,
		CronPhaseReason::ANALYSIS_ABANDONED,
		CronPhaseReason::ANALYSIS_ENDED,
	);

	/**
	 * Stored Action Scheduler phase reasons passed through to the API
	 * (ActionSchedulerPhaseReason). Any other stored value becomes `unknown`.
	 */
	const ACTION_SCHEDULER_REASONS = array(
		ActionSchedulerPhaseReason::NOT_INSTALLED,
		ActionSchedulerPhaseReason::UNSUPPORTED_STORE,
		ActionSchedulerPhaseReason::UNSUPPORTED_SCHEMA,
		ActionSchedulerPhaseReason::UNSUPPORTED_SCHEDULE,
		ActionSchedulerPhaseReason::MALFORMED_STATE,
		ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE,
		ActionSchedulerPhaseReason::FINGERPRINT_CONTEXT_CHANGED,
		ActionSchedulerPhaseReason::NOT_CAPTURED,
		ActionSchedulerPhaseReason::STORAGE_FAILED,
		ActionSchedulerPhaseReason::ANALYSIS_FAILED,
		ActionSchedulerPhaseReason::SETTLE_EXPIRED,
		ActionSchedulerPhaseReason::UPDATE_FAILED,
		ActionSchedulerPhaseReason::ANALYSIS_ABANDONED,
		ActionSchedulerPhaseReason::ANALYSIS_ENDED,
	);

	/**
	 * Options diff column per phase, in report order.
	 */
	const PHASE_COLUMNS = array(
		ObservationPhase::DURING_UPDATE => 'options_during_update_diff',
		ObservationPhase::POST_UPDATE   => 'options_post_update_diff',
		ObservationPhase::FINAL         => 'options_final_diff',
	);

	/**
	 * Diff decoding.
	 *
	 * @var OptionsDiffCodec
	 */
	private $diff_codec;

	/**
	 * Cron diff decoding.
	 *
	 * @var CronDiffCodec
	 */
	private $cron_diff_codec;

	/**
	 * Action Scheduler diff decoding.
	 *
	 * @var ActionSchedulerDiffCodec
	 */
	private $action_scheduler_diff_codec;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->diff_codec                  = new OptionsDiffCodec();
		$this->cron_diff_codec             = new CronDiffCodec();
		$this->action_scheduler_diff_codec = new ActionSchedulerDiffCodec();
	}

	/**
	 * History row (from AnalysisRepository::history_columns()).
	 *
	 * Built from flags computed in SQL; no diff is decoded. `has_<phase>` says
	 * whether a wp_options diff is stored. `phases.<phase>.<signal>` says
	 * whether a diff is stored (`recorded`) and whether it contains changes
	 * (`has_changes`, null if not recorded). Only the report decodes diffs,
	 * so it alone can find a stored diff unreadable.
	 *
	 * @param array<string, mixed> $row Analysis row.
	 * @return array<string, mixed>
	 */
	public function history_item( array $row ) {
		$item   = $this->metadata( $row );
		$phases = array();

		foreach ( self::PHASE_COLUMNS as $phase => $column ) {
			$item[ "has_{$phase}" ] = self::flag( $row, 'has_' . $column );
			$phases[ $phase ]       = array(
				'options'          => self::history_signal( $row, $column ),
				'cron'             => self::history_signal( $row, CronObservation::PHASES[ $phase ][0] ),
				'action_scheduler' => self::history_signal( $row, ActionSchedulerObservation::PHASES[ $phase ][0] ),
			);
		}
		$item['phases'] = $phases;

		return $item;
	}

	/**
	 * Whether one signal's diff of a phase is stored and has changes.
	 *
	 * @param array<string, mixed> $row    History row.
	 * @param string               $column Diff column.
	 * @return array{recorded: bool, has_changes: bool|null}
	 */
	private static function history_signal( array $row, $column ) {
		$recorded = self::flag( $row, 'has_' . $column );

		return array(
			'recorded'    => $recorded,
			'has_changes' => $recorded ? self::flag( $row, 'has_changes_' . $column ) : null,
		);
	}

	/**
	 * A SQL flag ("0"/"1" or NULL) as a boolean.
	 *
	 * @param array<string, mixed> $row Row.
	 * @param string               $key Column alias.
	 * @return bool
	 */
	private static function flag( array $row, $key ) {
		return isset( $row[ $key ] ) && 1 === (int) $row[ $key ];
	}

	/**
	 * Full report (from AnalysisRepository::REPORT_COLUMNS).
	 *
	 * @param array<string, mixed> $row Analysis row.
	 * @return array<string, mixed>
	 */
	public function report( array $row ) {
		$metadata = $this->metadata( $row );
		$phases   = array();

		foreach ( self::PHASE_COLUMNS as $phase => $column ) {
			list( $cron_diff, $cron_reason )                         = CronObservation::PHASES[ $phase ];
			list( $action_scheduler_diff, $action_scheduler_reason ) = ActionSchedulerObservation::PHASES[ $phase ];

			$phases[ $phase ] = array(
				'options'          => $this->phase( $phase, isset( $row[ $column ] ) ? $row[ $column ] : null, $metadata ),
				'cron'             => $this->signal_phase(
					$phase,
					self::field( $row, $cron_diff ),
					self::stored_reason( self::field( $row, $cron_reason ), self::CRON_REASONS ),
					$metadata,
					array( $this->cron_diff_codec, 'decode' )
				),
				'action_scheduler' => $this->signal_phase(
					$phase,
					self::field( $row, $action_scheduler_diff ),
					self::stored_reason( self::field( $row, $action_scheduler_reason ), self::ACTION_SCHEDULER_REASONS ),
					$metadata,
					array( $this->action_scheduler_diff_codec, 'decode' )
				),
			);
		}

		return array(
			'id'                         => $metadata['id'],
			'plugin'                     => $metadata['plugin'],
			'status'                     => $metadata['status'],
			'settle_outcome'             => $metadata['settle_outcome'],
			'timestamps'                 => $metadata['timestamps'],
			'observation_window_seconds' => PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS,
			'phases'                     => $phases,
			'error'                      => $metadata['error'],
		);
	}

	/**
	 * Fields shared by history rows and reports.
	 *
	 * @param array<string, mixed> $row Analysis row.
	 * @return array<string, mixed>
	 */
	private function metadata( array $row ) {
		$error_code = self::field( $row, 'error_code' );

		return array(
			'id'             => (int) self::field( $row, 'id' ),
			'plugin'         => array(
				'file'           => self::plugin_file( self::field( $row, 'plugin_file' ) ),
				'name'           => self::text( self::field( $row, 'plugin_name' ) ),
				'version_before' => self::text( self::field( $row, 'version_before' ) ),
				'version_after'  => null === self::field( $row, 'version_after' ) ? null : self::text( self::field( $row, 'version_after' ) ),
			),
			'status'         => self::known( self::field( $row, 'status' ), self::STATUSES ),
			'settle_outcome' => null === self::field( $row, 'settle_outcome' ) ? null : self::known( self::field( $row, 'settle_outcome' ), self::SETTLE_OUTCOMES ),
			'timestamps'     => array(
				'started_at'      => self::timestamp( self::field( $row, 'started_at' ) ),
				'settle_deadline' => self::timestamp( self::field( $row, 'settle_deadline' ) ),
				'completed_at'    => self::timestamp( self::field( $row, 'completed_at' ) ),
			),
			'error'          => null === $error_code || '' === $error_code ? null : array( 'code' => self::error_code( $error_code ) ),
		);
	}

	/**
	 * One phase: the decoded diff, or why there is none.
	 *
	 * @param string               $phase    ObservationPhase constant.
	 * @param mixed                $json     Stored diff JSON or null.
	 * @param array<string, mixed> $metadata Output of metadata().
	 * @return array<string, mixed>
	 */
	private function phase( $phase, $json, array $metadata ) {
		$association = ObservationPhase::ASSOCIATION[ $phase ];

		if ( null === $json ) {
			return self::unavailable( $association, self::unavailable_reason( $metadata ) );
		}

		try {
			$diff = $this->diff_codec->decode( $json );
		} catch ( Throwable $e ) {
			return self::unavailable( $association, UnavailableReason::DATA_CORRUPT );
		}

		return array(
			'available'   => true,
			'association' => $association,
			'summary'     => $diff['summary'],
			'added'       => $diff['added'],
			'removed'     => $diff['removed'],
			'changed'     => $diff['changed'],
		);
	}

	/**
	 * One WP-Cron or Action Scheduler phase: the decoded diff, or why there is none.
	 *
	 * Both signals store the same lists (added, removed, rescheduled,
	 * changed), each decoded only by its own codec. A stored reason is passed
	 * through (whitelisted by stored_reason()); without diff and reason the
	 * phase is still pending, which the status explains.
	 *
	 * @param string               $phase    ObservationPhase constant.
	 * @param mixed                $json     Stored diff JSON or null.
	 * @param string|null          $reason   Whitelisted stored reason, or null if none is stored.
	 * @param array<string, mixed> $metadata Output of metadata().
	 * @param callable             $decode   The signal's codec decode().
	 * @return array<string, mixed>
	 */
	private function signal_phase( $phase, $json, $reason, array $metadata, callable $decode ) {
		$association = ObservationPhase::ASSOCIATION[ $phase ];

		if ( null !== $json ) {
			try {
				$diff = $decode( $json );
			} catch ( Throwable $e ) {
				return self::unavailable( $association, UnavailableReason::DATA_CORRUPT );
			}

			return array(
				'available'   => true,
				'association' => $association,
				'summary'     => $diff['summary'],
				'added'       => $diff['added'],
				'removed'     => $diff['removed'],
				'rescheduled' => $diff['rescheduled'],
				'changed'     => $diff['changed'],
			);
		}

		if ( null !== $reason ) {
			return self::unavailable( $association, $reason );
		}

		if ( AnalysisStatus::CAPTURED === $metadata['status'] ) {
			return self::unavailable( $association, UnavailableReason::UPDATE_IN_PROGRESS );
		}
		if ( AnalysisStatus::AWAITING_SETTLE === $metadata['status'] && ObservationPhase::DURING_UPDATE !== $phase ) {
			return self::unavailable( $association, UnavailableReason::AWAITING_SETTLE );
		}

		return self::unavailable( $association, UnavailableReason::NOT_RECORDED );
	}

	/**
	 * Unavailable phase.
	 *
	 * @param string $association Phase association.
	 * @param string $reason      UnavailableReason constant.
	 * @return array{available: false, association: string, reason: string}
	 */
	private static function unavailable( $association, $reason ) {
		return array(
			'available'   => false,
			'association' => $association,
			'reason'      => $reason,
		);
	}

	/**
	 * Why a phase without a stored diff has none.
	 *
	 * @param array<string, mixed> $metadata Output of metadata().
	 * @return string UnavailableReason constant.
	 */
	private static function unavailable_reason( array $metadata ) {
		$error_code = null === $metadata['error'] ? null : $metadata['error']['code'];

		switch ( $metadata['status'] ) {
			case AnalysisStatus::CAPTURED:
				return UnavailableReason::UPDATE_IN_PROGRESS;
			case AnalysisStatus::AWAITING_SETTLE:
				return UnavailableReason::AWAITING_SETTLE;
			case AnalysisStatus::COMPLETED:
				return SettleOutcome::EXPIRED === $metadata['settle_outcome'] ? UnavailableReason::SETTLE_EXPIRED : UnavailableReason::NOT_RECORDED;
			case AnalysisStatus::FAILED:
				return in_array( $error_code, array( PluginUpdateAnalyzer::ERROR_ANALYSIS_FAILED, PluginUpdateAnalyzer::ERROR_SNAPSHOT_CORRUPT ), true )
					? UnavailableReason::ANALYSIS_FAILED
					: UnavailableReason::UPDATE_FAILED;
			case AnalysisStatus::INCOMPATIBLE:
				return UnavailableReason::FINGERPRINT_CONTEXT_CHANGED;
			case AnalysisStatus::ABANDONED:
				return UnavailableReason::ANALYSIS_ABANDONED;
			default:
				return UnavailableReason::NOT_RECORDED;
		}
	}

	/**
	 * Row value or null.
	 *
	 * @param array<string, mixed> $row Row.
	 * @param string               $key Column.
	 * @return mixed
	 */
	private static function field( array $row, $key ) {
		return array_key_exists( $key, $row ) ? $row[ $key ] : null;
	}

	/**
	 * A stored identifier if it is one of the known values, else `unknown`.
	 *
	 * @param mixed    $value Stored value.
	 * @param string[] $known Known values.
	 * @return string
	 */
	private static function known( $value, array $known ) {
		return is_string( $value ) && in_array( $value, $known, true ) ? $value : self::UNKNOWN;
	}

	/**
	 * A stored phase reason if it is a known one, `unknown` for any other
	 * stored value, or null if none is stored.
	 *
	 * @param mixed    $value Stored reason.
	 * @param string[] $known Known reasons.
	 * @return string|null
	 */
	private static function stored_reason( $value, array $known ) {
		return null === $value || '' === $value ? null : self::known( $value, $known );
	}

	/**
	 * Stored error code if it is a safe identifier, else `unknown`.
	 *
	 * @param mixed $value Stored error code.
	 * @return string
	 */
	private static function error_code( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-z0-9_-]{1,64}$/', $value ) ? $value : self::UNKNOWN;
	}

	/**
	 * Plugin basename relative to the plugins directory (`dir/file.php` or
	 * `file.php`), or null if the stored value is not one.
	 *
	 * @param mixed $value Stored plugin file.
	 * @return string|null
	 */
	private static function plugin_file( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 255 || 1 !== preg_match( '#^(?:[^/\\\\:\x00-\x1f\x7f]+/)?[^/\\\\:\x00-\x1f\x7f]+\.php$#Du', $value ) ) {
			return null;
		}
		foreach ( explode( '/', $value ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return null;
			}
		}

		return $value;
	}

	/**
	 * Stored text without control characters; empty if not valid UTF-8.
	 *
	 * @param mixed $value Stored text.
	 * @return string
	 */
	private static function text( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$text = preg_replace( '/[\x00-\x1f\x7f]/u', '', $value );

		return null === $text ? '' : $text;
	}

	/**
	 * Stored UTC DATETIME (`Y-m-d H:i:s`) as ISO 8601 UTC, or null.
	 *
	 * @param mixed $value Stored timestamp.
	 * @return string|null
	 */
	private static function timestamp( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/D', $value, $parts ) ) {
			return null;
		}
		if ( ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59 ) {
			return null;
		}

		return "{$parts[1]}-{$parts[2]}-{$parts[3]}T{$parts[4]}:{$parts[5]}:{$parts[6]}Z";
	}
}
