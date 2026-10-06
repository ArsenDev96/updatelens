<?php
/**
 * JSON persistence format for Action Scheduler diffs.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Storage;

use JsonException;
use UnexpectedValueException;
use UpdateLens\Diff\ActionSchedulerDiff;
use UpdateLens\Snapshot\ActionSchedulerActionRecord;
use UpdateLens\Snapshot\ActionSchedulerScheduleParser;
use UpdateLens\Snapshot\CronEventOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes an ActionSchedulerDiff as versioned JSON and decodes it for readers.
 *
 * Contains exactly ActionSchedulerDiff::to_array() (added, removed,
 * rescheduled, changed, summary) plus the format version: no arguments,
 * arguments fingerprints, fingerprint context, serialized schedules or
 * database IDs. Decoding validates every field, the canonical list order and
 * every value derived from others (deltas, flags, counts), and returns the
 * ActionSchedulerDiff::to_array() shape.
 */
final class ActionSchedulerDiffCodec {

	/**
	 * Format version.
	 */
	const SCHEMA = 1;

	/**
	 * How encode() begins a diff without added, removed, rescheduled or changed actions.
	 *
	 * Contains no SQL LIKE wildcards, quotes or backslashes.
	 */
	const EMPTY_PREFIX = '{"schema":' . self::SCHEMA . ',"added":[],"removed":[],"rescheduled":[],"changed":[],';

	/**
	 * Maximum JSON nesting depth accepted when decoding.
	 */
	const MAX_DEPTH = 8;

	/**
	 * Keys of an added/removed action, in order.
	 */
	const STATE_KEYS = array( 'hook', 'group', 'status', 'timestamp', 'schedule_type', 'interval', 'cron_expression', 'is_recurring' );

	/**
	 * Keys of a rescheduled action, in order.
	 */
	const RESCHEDULED_KEYS = array( 'hook', 'group', 'before_timestamp', 'after_timestamp', 'timestamp_delta', 'schedule_type', 'interval', 'cron_expression', 'is_recurring' );

	/**
	 * Keys of a changed action, in order.
	 */
	const CHANGED_KEYS = array(
		'hook',
		'group',
		'before_timestamp',
		'after_timestamp',
		'timestamp_changed',
		'before_schedule_type',
		'after_schedule_type',
		'before_interval',
		'after_interval',
		'before_cron_expression',
		'after_cron_expression',
		'before_is_recurring',
		'after_is_recurring',
	);

	/**
	 * Keys of the summary, in order.
	 */
	const SUMMARY_KEYS = array(
		'before_action_count',
		'after_action_count',
		'action_count_delta',
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
	 * @param ActionSchedulerDiff $diff Diff.
	 * @return string JSON.
	 * @throws UnexpectedValueException If the diff cannot be encoded.
	 */
	public function encode( ActionSchedulerDiff $diff ) {
		$data = array( 'schema' => self::SCHEMA ) + $diff->to_array();

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure code, unit-tested without WordPress; errors throw.
			return json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Action Scheduler diff could not be encoded.' );
		}
	}

	/**
	 * Decode a stored diff.
	 *
	 * @param string $json JSON produced by encode().
	 * @return array{added: array<int, array>, removed: array<int, array>, rescheduled: array<int, array>, changed: array<int, array>, summary: array<string, int>} Same shape as ActionSchedulerDiff::to_array().
	 * @throws UnexpectedValueException If the data is malformed, inconsistent or of an unsupported schema.
	 */
	public function decode( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			throw new UnexpectedValueException( 'Stored Action Scheduler diff is invalid.' );
		}

		try {
			$data = json_decode( $json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Stored Action Scheduler diff is invalid.' );
		}

		if ( ! is_array( $data ) || array( 'schema', 'added', 'removed', 'rescheduled', 'changed', 'summary' ) !== array_keys( $data ) ) {
			throw new UnexpectedValueException( 'Stored Action Scheduler diff is invalid.' );
		}
		if ( self::SCHEMA !== $data['schema'] ) {
			throw new UnexpectedValueException( 'Stored Action Scheduler diff has an unsupported schema.' );
		}

		$valid = self::is_sorted_list( $data['added'], array( self::class, 'is_valid_state' ) )
			&& self::is_sorted_list( $data['removed'], array( self::class, 'is_valid_state' ) )
			&& self::is_sorted_list( $data['rescheduled'], array( self::class, 'is_valid_rescheduled' ) )
			&& self::is_sorted_list( $data['changed'], array( self::class, 'is_valid_changed' ) )
			&& self::is_valid_summary( $data['summary'], $data );

		if ( ! $valid ) {
			throw new UnexpectedValueException( 'Stored Action Scheduler diff is invalid.' );
		}

		unset( $data['schema'] );

		return $data;
	}

	/**
	 * Whether a value is an active-action status.
	 *
	 * @param mixed $status Value.
	 * @return bool
	 */
	public static function is_status( $status ) {
		return ActionSchedulerActionRecord::STATUS_PENDING === $status || ActionSchedulerActionRecord::STATUS_IN_PROGRESS === $status;
	}

	/**
	 * Whether schedule type, interval, cron expression and recurring flag form
	 * a schedule as ActionSchedulerScheduleParser normalizes it.
	 *
	 * @param mixed $type         Schedule type.
	 * @param mixed $interval     Interval in seconds or null.
	 * @param mixed $expression   Cron expression or null.
	 * @param mixed $is_recurring Flag.
	 * @return bool
	 */
	public static function is_schedule( $type, $interval, $expression, $is_recurring ) {
		switch ( $type ) {
			case ActionSchedulerActionRecord::TYPE_SINGLE:
			case ActionSchedulerActionRecord::TYPE_ASYNC:
				return null === $interval && null === $expression && false === $is_recurring;
			case ActionSchedulerActionRecord::TYPE_INTERVAL:
				return is_int( $interval ) && null === $expression && true === $is_recurring;
			case ActionSchedulerActionRecord::TYPE_CRON:
				return null === $interval && self::is_cron_expression( $expression ) && true === $is_recurring;
			default:
				return false;
		}
	}

	/**
	 * Whether a value is a normalized cron expression: 5 or 6 fields joined by single spaces.
	 *
	 * @param mixed $expression Value.
	 * @return bool
	 */
	private static function is_cron_expression( $expression ) {
		if ( ! is_string( $expression ) ) {
			return false;
		}
		$parts = explode( ' ', $expression );
		if ( count( $parts ) < 5 || count( $parts ) > 6 ) {
			return false;
		}
		foreach ( $parts as $part ) {
			if ( 1 !== preg_match( ActionSchedulerScheduleParser::CRON_FIELD_PATTERN, $part ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a value is a list of valid entries in canonical (non-decreasing) order.
	 *
	 * Entries that tie are identical, so ties are allowed (repeated actions).
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
	 * Whether an added/removed action entry is valid.
	 *
	 * @param array $entry Entry.
	 * @return bool
	 */
	private static function is_valid_state( array $entry ) {
		return self::STATE_KEYS === array_keys( $entry )
			&& is_string( $entry['hook'] )
			&& is_string( $entry['group'] )
			&& self::is_status( $entry['status'] )
			&& self::is_timestamp( $entry['timestamp'] )
			&& self::is_schedule( $entry['schedule_type'], $entry['interval'], $entry['cron_expression'], $entry['is_recurring'] );
	}

	/**
	 * Whether a rescheduled action entry is valid: same schedule, another scheduled run.
	 *
	 * @param array $entry Entry.
	 * @return bool
	 */
	private static function is_valid_rescheduled( array $entry ) {
		return self::RESCHEDULED_KEYS === array_keys( $entry )
			&& is_string( $entry['hook'] )
			&& is_string( $entry['group'] )
			&& self::is_timestamp( $entry['before_timestamp'] )
			&& self::is_timestamp( $entry['after_timestamp'] )
			&& is_int( $entry['timestamp_delta'] )
			&& $entry['after_timestamp'] - $entry['before_timestamp'] === $entry['timestamp_delta']
			&& 0 !== $entry['timestamp_delta']
			&& self::is_schedule( $entry['schedule_type'], $entry['interval'], $entry['cron_expression'], $entry['is_recurring'] );
	}

	/**
	 * Whether a changed action entry is valid: the schedule (type, interval or expression) differs.
	 *
	 * @param array $entry Entry.
	 * @return bool
	 */
	private static function is_valid_changed( array $entry ) {
		return self::CHANGED_KEYS === array_keys( $entry )
			&& is_string( $entry['hook'] )
			&& is_string( $entry['group'] )
			&& self::is_timestamp( $entry['before_timestamp'] )
			&& self::is_timestamp( $entry['after_timestamp'] )
			&& is_bool( $entry['timestamp_changed'] )
			&& ( $entry['before_timestamp'] !== $entry['after_timestamp'] ) === $entry['timestamp_changed']
			&& self::is_schedule( $entry['before_schedule_type'], $entry['before_interval'], $entry['before_cron_expression'], $entry['before_is_recurring'] )
			&& self::is_schedule( $entry['after_schedule_type'], $entry['after_interval'], $entry['after_cron_expression'], $entry['after_is_recurring'] )
			&& (
				$entry['before_schedule_type'] !== $entry['after_schedule_type']
				|| $entry['before_interval'] !== $entry['after_interval']
				|| $entry['before_cron_expression'] !== $entry['after_cron_expression']
			);
	}

	/**
	 * Whether the summary is valid and matches the action lists.
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
		foreach ( array( 'action_count', 'recurring_count', 'single_count', 'unique_hook_count' ) as $total ) {
			if ( $summary[ 'after_' . $total ] - $summary[ 'before_' . $total ] !== $summary[ $total . '_delta' ] ) {
				return false;
			}
		}
		foreach ( array( 'before', 'after' ) as $side ) {
			$actions = $summary[ $side . '_action_count' ];
			$hooks   = $summary[ $side . '_unique_hook_count' ];
			if ( $summary[ $side . '_recurring_count' ] + $summary[ $side . '_single_count' ] !== $actions
				|| $hooks > $actions
				|| ( 0 === $actions ) !== ( 0 === $hooks ) ) {
				return false;
			}
		}

		// Rescheduled and changed actions are matched pairs: each needs an action on both sides.
		$paired = $summary['rescheduled_count'] + $summary['changed_count'];
		if ( $summary['removed_count'] + $paired > $summary['before_action_count'] || $summary['added_count'] + $paired > $summary['after_action_count'] ) {
			return false;
		}

		// Only added/removed move the totals; changed actions may switch between one-time and recurring.
		$recurring_delta = count( array_filter( array_column( $data['added'], 'is_recurring' ) ) )
			- count( array_filter( array_column( $data['removed'], 'is_recurring' ) ) )
			+ count( array_filter( array_column( $data['changed'], 'after_is_recurring' ) ) )
			- count( array_filter( array_column( $data['changed'], 'before_is_recurring' ) ) );

		return count( $data['added'] ) === $summary['added_count']
			&& count( $data['removed'] ) === $summary['removed_count']
			&& count( $data['rescheduled'] ) === $summary['rescheduled_count']
			&& count( $data['changed'] ) === $summary['changed_count']
			&& $summary['added_count'] - $summary['removed_count'] === $summary['action_count_delta']
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
}
