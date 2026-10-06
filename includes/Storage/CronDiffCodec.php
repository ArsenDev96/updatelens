<?php
/**
 * JSON persistence format for WP-Cron diffs.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Storage;

use JsonException;
use UnexpectedValueException;
use UpdateLens\Diff\CronDiff;
use UpdateLens\Snapshot\CronEventOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes a CronDiff as versioned JSON and decodes it for reports.
 *
 * Contains exactly CronDiff::to_array() (added, removed, rescheduled,
 * changed, summary) plus the format version: no arguments, no arguments
 * fingerprints, no fingerprint context. Decoding validates every field, the
 * canonical list order and every value derived from others (deltas, flags,
 * counts), and returns the CronDiff::to_array() shape.
 */
final class CronDiffCodec {

	/**
	 * Format version.
	 */
	const SCHEMA = 1;

	/**
	 * Maximum JSON nesting depth accepted when decoding.
	 */
	const MAX_DEPTH = 8;

	/**
	 * Keys of an added/removed event, in order.
	 */
	const STATE_KEYS = array( 'hook', 'timestamp', 'schedule', 'interval', 'is_recurring' );

	/**
	 * Keys of a rescheduled event, in order.
	 */
	const RESCHEDULED_KEYS = array( 'hook', 'before_timestamp', 'after_timestamp', 'timestamp_delta', 'schedule', 'interval', 'is_recurring' );

	/**
	 * Keys of a changed event, in order.
	 */
	const CHANGED_KEYS = array(
		'hook',
		'before_timestamp',
		'after_timestamp',
		'timestamp_changed',
		'before_schedule',
		'after_schedule',
		'before_interval',
		'after_interval',
		'before_is_recurring',
		'after_is_recurring',
	);

	/**
	 * Keys of the summary, in order.
	 */
	const SUMMARY_KEYS = array(
		'before_event_count',
		'after_event_count',
		'event_count_delta',
		'before_recurring_count',
		'after_recurring_count',
		'recurring_count_delta',
		'before_single_count',
		'after_single_count',
		'single_count_delta',
		'before_unique_hook_count',
		'after_unique_hook_count',
		'unique_hook_count_delta',
		'added_count',
		'removed_count',
		'rescheduled_count',
		'changed_count',
	);

	/**
	 * Encode a diff.
	 *
	 * @param CronDiff $diff Diff.
	 * @return string JSON.
	 * @throws UnexpectedValueException If the diff cannot be encoded.
	 */
	public function encode( CronDiff $diff ) {
		$data = array( 'schema' => self::SCHEMA ) + $diff->to_array();

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure code, unit-tested without WordPress; errors throw.
			return json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Cron diff could not be encoded.' );
		}
	}

	/**
	 * Decode a stored diff.
	 *
	 * @param string $json JSON produced by encode().
	 * @return array{added: array<int, array>, removed: array<int, array>, rescheduled: array<int, array>, changed: array<int, array>, summary: array<string, int>} Same shape as CronDiff::to_array().
	 * @throws UnexpectedValueException If the data is malformed, inconsistent or of an unsupported schema.
	 */
	public function decode( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			throw new UnexpectedValueException( 'Stored cron diff is invalid.' );
		}

		try {
			$data = json_decode( $json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Stored cron diff is invalid.' );
		}

		if ( ! is_array( $data ) || array( 'schema', 'added', 'removed', 'rescheduled', 'changed', 'summary' ) !== array_keys( $data ) ) {
			throw new UnexpectedValueException( 'Stored cron diff is invalid.' );
		}
		if ( self::SCHEMA !== $data['schema'] ) {
			throw new UnexpectedValueException( 'Stored cron diff has an unsupported schema.' );
		}

		$valid = self::is_sorted_list( $data['added'], array( self::class, 'is_valid_state' ) )
			&& self::is_sorted_list( $data['removed'], array( self::class, 'is_valid_state' ) )
			&& self::is_sorted_list( $data['rescheduled'], array( self::class, 'is_valid_rescheduled' ) )
			&& self::is_sorted_list( $data['changed'], array( self::class, 'is_valid_changed' ) )
			&& self::is_valid_summary( $data['summary'], $data );

		if ( ! $valid ) {
			throw new UnexpectedValueException( 'Stored cron diff is invalid.' );
		}

		unset( $data['schema'] );

		return $data;
	}

	/**
	 * Whether a value is a list of valid entries in canonical (non-decreasing) order.
	 *
	 * Entries that tie are identical, so ties are allowed (repeated events).
	 *
	 * @param mixed    $entries Decoded collection.
	 * @param callable $check   Entry validator.
	 * @return bool
	 */
	private static function is_sorted_list( $entries, callable $check ) {
		if ( ! is_array( $entries ) || array_values( $entries ) !== $entries ) {
			return false;
		}

		$previous = null;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! $check( $entry ) ) {
				return false;
			}
			if ( null !== $previous && CronEventOrder::compare( $previous, $entry ) > 0 ) {
				return false;
			}
			$previous = $entry;
		}

		return true;
	}

	/**
	 * Whether an added/removed event entry is valid.
	 *
	 * @param array $entry Entry.
	 * @return bool
	 */
	private static function is_valid_state( array $entry ) {
		return self::STATE_KEYS === array_keys( $entry )
			&& is_string( $entry['hook'] )
			&& self::is_timestamp( $entry['timestamp'] )
			&& self::is_recurrence( $entry['schedule'], $entry['interval'], $entry['is_recurring'] );
	}

	/**
	 * Whether a rescheduled event entry is valid and internally consistent.
	 *
	 * @param array $entry Entry.
	 * @return bool
	 */
	private static function is_valid_rescheduled( array $entry ) {
		return self::RESCHEDULED_KEYS === array_keys( $entry )
			&& is_string( $entry['hook'] )
			&& self::is_timestamp( $entry['before_timestamp'] )
			&& self::is_timestamp( $entry['after_timestamp'] )
			&& is_int( $entry['timestamp_delta'] )
			&& $entry['after_timestamp'] - $entry['before_timestamp'] === $entry['timestamp_delta']
			&& 0 !== $entry['timestamp_delta']
			&& self::is_recurrence( $entry['schedule'], $entry['interval'], $entry['is_recurring'] );
	}

	/**
	 * Whether a changed event entry is valid and internally consistent.
	 *
	 * @param array $entry Entry.
	 * @return bool
	 */
	private static function is_valid_changed( array $entry ) {
		return self::CHANGED_KEYS === array_keys( $entry )
			&& is_string( $entry['hook'] )
			&& self::is_timestamp( $entry['before_timestamp'] )
			&& self::is_timestamp( $entry['after_timestamp'] )
			&& is_bool( $entry['timestamp_changed'] )
			&& ( $entry['before_timestamp'] !== $entry['after_timestamp'] ) === $entry['timestamp_changed']
			&& self::is_recurrence( $entry['before_schedule'], $entry['before_interval'], $entry['before_is_recurring'] )
			&& self::is_recurrence( $entry['after_schedule'], $entry['after_interval'], $entry['after_is_recurring'] )
			&& ( $entry['before_schedule'] !== $entry['after_schedule'] || $entry['before_interval'] !== $entry['after_interval'] );
	}

	/**
	 * Whether the summary is valid and matches the event lists.
	 *
	 * @param mixed $summary Decoded summary.
	 * @param array $data    Decoded diff (validated lists).
	 * @return bool
	 */
	private static function is_valid_summary( $summary, array $data ) {
		if ( ! is_array( $summary ) || self::SUMMARY_KEYS !== array_keys( $summary ) ) {
			return false;
		}
		foreach ( $summary as $key => $value ) {
			if ( ! is_int( $value ) || ( $value < 0 && '_delta' !== substr( $key, -6 ) ) ) {
				return false;
			}
		}
		foreach ( array( 'event_count', 'recurring_count', 'single_count', 'unique_hook_count' ) as $total ) {
			if ( $summary[ 'after_' . $total ] - $summary[ 'before_' . $total ] !== $summary[ $total . '_delta' ] ) {
				return false;
			}
		}
		foreach ( array( 'before', 'after' ) as $side ) {
			if ( $summary[ $side . '_recurring_count' ] + $summary[ $side . '_single_count' ] !== $summary[ $side . '_event_count' ] ) {
				return false;
			}
		}

		// Rescheduled and changed events are matched pairs, so only added/removed move the totals.
		$recurring_delta = count( array_filter( array_column( $data['added'], 'is_recurring' ) ) )
			- count( array_filter( array_column( $data['removed'], 'is_recurring' ) ) )
			+ count( array_filter( array_column( $data['changed'], 'after_is_recurring' ) ) )
			- count( array_filter( array_column( $data['changed'], 'before_is_recurring' ) ) );

		return count( $data['added'] ) === $summary['added_count']
			&& count( $data['removed'] ) === $summary['removed_count']
			&& count( $data['rescheduled'] ) === $summary['rescheduled_count']
			&& count( $data['changed'] ) === $summary['changed_count']
			&& $summary['added_count'] - $summary['removed_count'] === $summary['event_count_delta']
			&& $recurring_delta === $summary['recurring_count_delta'];
	}

	/**
	 * Whether a value is a positive Unix timestamp.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_timestamp( $value ) {
		return is_int( $value ) && $value > 0;
	}

	/**
	 * Whether schedule, interval and recurring flag form a consistent recurrence.
	 *
	 * @param mixed $schedule     Schedule name or null.
	 * @param mixed $interval     Interval or null.
	 * @param mixed $is_recurring Flag.
	 * @return bool
	 */
	private static function is_recurrence( $schedule, $interval, $is_recurring ) {
		if ( null === $schedule ) {
			return false === $is_recurring && null === $interval;
		}

		return is_string( $schedule ) && '' !== $schedule && true === $is_recurring
			&& ( null === $interval || ( is_int( $interval ) && $interval >= 0 ) );
	}
}
