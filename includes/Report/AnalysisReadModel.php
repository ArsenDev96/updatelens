<?php
/**
 * Safe API representation of stored analyses.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Report;

use Throwable;
use UpdateLens\Storage\OptionsDiffCodec;
use UpdateLens\Update\AnalysisStatus;
use UpdateLens\Update\ObservationPhase;
use UpdateLens\Update\PluginUpdateAnalyzer;
use UpdateLens\Update\SettleOutcome;

defined( 'ABSPATH' ) || exit;

/**
 * Builds history rows and reports from analysis rows.
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
	 * Diff column per phase, in report order.
	 */
	const PHASE_COLUMNS = array(
		ObservationPhase::DURING_UPDATE => 'during_update_diff',
		ObservationPhase::POST_UPDATE   => 'post_update_diff',
		ObservationPhase::FINAL         => 'final_diff',
	);

	/**
	 * Diff decoding.
	 *
	 * @var OptionsDiffCodec
	 */
	private $diff_codec;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->diff_codec = new OptionsDiffCodec();
	}

	/**
	 * History row (from AnalysisRepository::HISTORY_COLUMNS).
	 *
	 * The `has_*` flags say whether a phase diff is stored; they do not decode it.
	 *
	 * @param array<string, mixed> $row Analysis row.
	 * @return array<string, mixed>
	 */
	public function history_item( array $row ) {
		$item = $this->metadata( $row );

		foreach ( self::PHASE_COLUMNS as $phase => $column ) {
			$key                    = 'has_' . $column;
			$item[ "has_{$phase}" ] = isset( $row[ $key ] ) && 1 === (int) $row[ $key ];
		}

		return $item;
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
			$phases[ $phase ] = $this->phase( $phase, isset( $row[ $column ] ) ? $row[ $column ] : null, $metadata );
		}

		return array(
			'id'             => $metadata['id'],
			'plugin'         => $metadata['plugin'],
			'status'         => $metadata['status'],
			'settle_outcome' => $metadata['settle_outcome'],
			'timestamps'     => $metadata['timestamps'],
			'phases'         => $phases,
			'error'          => $metadata['error'],
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
