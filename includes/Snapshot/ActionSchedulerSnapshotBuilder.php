<?php
/**
 * Builds an Action Scheduler snapshot from raw action rows.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use DateTime;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Turns active Action Scheduler rows into an ActionSchedulerSnapshot.
 *
 * Pure: no WordPress or database access, so it is unit-tested directly.
 * Arguments are only fingerprinted here; they never leave this class.
 *
 * Expected input, one array per pending or in-progress action, as
 * ActionSchedulerSnapshotProvider reads it from ActionScheduler_DBStore:
 *
 *     [ 'hook' => string, 'status' => 'pending'|'in-progress',
 *       'scheduled_date_gmt' => 'Y-m-d H:i:s' (UTC), 'args' => string,
 *       'extended_args' => string|null, 'schedule' => serialized schedule,
 *       'group' => string|null (group slug; null or '' for none) ]
 *
 * Arguments are the JSON text in `extended_args` when it is set (arguments
 * longer than 191 bytes; `args` then holds only their MD5) and in `args`
 * otherwise, as in ActionScheduler_DBStore::fetch_action(). Database IDs
 * are not read.
 *
 * Malformed rows fail the whole snapshot with
 * MalformedActionSchedulerStateException rather than being skipped: a
 * snapshot that silently leaves actions out would report them as added or
 * removed when it changes.
 */
final class ActionSchedulerSnapshotBuilder {

	/**
	 * Statuses of a snapshot: the active actions Action Scheduler itself
	 * treats as existing (as_has_scheduled_action(), unique scheduling).
	 */
	const STATUSES = array(
		ActionSchedulerActionRecord::STATUS_PENDING,
		ActionSchedulerActionRecord::STATUS_IN_PROGRESS,
	);

	/**
	 * Argument fingerprinting.
	 *
	 * @var ActionSchedulerArgsHasher
	 */
	private $hasher;

	/**
	 * Constructor.
	 *
	 * @param ActionSchedulerArgsHasher $hasher Argument fingerprinting.
	 */
	public function __construct( ActionSchedulerArgsHasher $hasher ) {
		$this->hasher = $hasher;
	}

	/**
	 * Build a snapshot.
	 *
	 * Rows may come from a generator, so only one batch of raw rows needs to
	 * be in memory at a time.
	 *
	 * @param iterable $rows Active action rows (arrays).
	 * @return ActionSchedulerSnapshot
	 * @throws MalformedActionSchedulerStateException If a row is not a valid active action.
	 */
	public function build( iterable $rows ) {
		$actions = array();
		foreach ( $rows as $row ) {
			$actions[] = $this->record( $row );
		}

		return new ActionSchedulerSnapshot( $actions, $this->hasher->get_context() );
	}

	/**
	 * Record for one row.
	 *
	 * @param mixed $row Action row.
	 * @return ActionSchedulerActionRecord
	 * @throws MalformedActionSchedulerStateException If the row is not a valid active action.
	 */
	private function record( $row ) {
		if ( ! is_array( $row ) ) {
			throw MalformedActionSchedulerStateException::invalid_action();
		}

		$hook = isset( $row['hook'] ) ? $row['hook'] : null;
		if ( ! self::is_text( $hook ) ) {
			throw MalformedActionSchedulerStateException::invalid_action();
		}

		// A missing group row (LEFT JOIN) or group 0 is no group, as in Action Scheduler.
		$group = isset( $row['group'] ) ? $row['group'] : '';
		if ( ! self::is_text( $group ) ) {
			throw MalformedActionSchedulerStateException::invalid_action();
		}

		$status = isset( $row['status'] ) ? $row['status'] : null;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw MalformedActionSchedulerStateException::invalid_action();
		}

		$timestamp = self::timestamp( isset( $row['scheduled_date_gmt'] ) ? $row['scheduled_date_gmt'] : null );
		$schedule  = ActionSchedulerScheduleParser::parse( isset( $row['schedule'] ) ? $row['schedule'] : null );

		return new ActionSchedulerActionRecord(
			$hook,
			$group,
			$status,
			$timestamp,
			$schedule['type'],
			$schedule['interval'],
			$schedule['cron_expression'],
			$this->hasher->fingerprint( self::args_json( $row ) )
		);
	}

	/**
	 * Whether a value is a string of valid UTF-8 (hook names and group slugs).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_text( $value ) {
		return is_string( $value ) && 1 === preg_match( '//u', $value );
	}

	/**
	 * Unix timestamp of a stored UTC date.
	 *
	 * @param mixed $date `Y-m-d H:i:s` in UTC.
	 * @return int
	 * @throws MalformedActionSchedulerStateException If it is not a real date (e.g. the zero date).
	 */
	private static function timestamp( $date ) {
		if ( ! is_string( $date ) || 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $date ) ) {
			throw MalformedActionSchedulerStateException::invalid_action();
		}
		$parsed = DateTime::createFromFormat( '!Y-m-d H:i:s', $date, new DateTimeZone( 'UTC' ) );
		// Round-tripping rejects overflowing values PHP would otherwise roll over (2026-02-30, 0000-00-00).
		if ( false === $parsed || $parsed->format( 'Y-m-d H:i:s' ) !== $date || $parsed->getTimestamp() <= 0 ) {
			throw MalformedActionSchedulerStateException::invalid_action();
		}

		return $parsed->getTimestamp();
	}

	/**
	 * Stored JSON text of the arguments, validated to be a JSON array.
	 *
	 * @param array<string, mixed> $row Action row.
	 * @return string
	 * @throws MalformedActionSchedulerStateException If the arguments are not a JSON array.
	 */
	private static function args_json( array $row ) {
		$json = isset( $row['extended_args'] ) && '' !== $row['extended_args'] ? $row['extended_args'] : ( isset( $row['args'] ) ? $row['args'] : null );
		if ( ! is_string( $json ) || ! is_array( json_decode( $json, true ) ) ) {
			throw MalformedActionSchedulerStateException::invalid_args();
		}

		return $json;
	}
}
