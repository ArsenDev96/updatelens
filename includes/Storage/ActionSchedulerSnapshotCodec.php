<?php
/**
 * JSON persistence format for Action Scheduler snapshots.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Storage;

use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;
use UpdateLens\Snapshot\ActionSchedulerActionRecord;
use UpdateLens\Snapshot\ActionSchedulerSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes an ActionSchedulerSnapshot as versioned JSON and decodes it back.
 *
 * Holds exactly the snapshot's safe action records (hook, group, status,
 * scheduled run, normalized schedule, keyed arguments fingerprint) and its
 * fingerprint context: never arguments, serialized schedules, logs, claims
 * or database IDs. Only temporary snapshot columns use it; terminal
 * analyses keep diffs only. The summary is derived from the records on
 * decode, as for Cron snapshots, so it is not stored. Decoding validates
 * every field and accepts only the canonical record order, so a decoded
 * snapshot re-encodes to the same bytes.
 *
 * Format (schema 1):
 *
 *     {"schema":1,"fingerprint_context":"as-args-hmac-sha256-v1:…","actions":[
 *       {"hook":"…","group":"…","status":"pending","timestamp":1767225600,
 *        "schedule_type":"interval","interval":3600,"cron_expression":null,
 *        "is_recurring":true,"args_fingerprint":"<64 hex>"}, …]}
 */
final class ActionSchedulerSnapshotCodec {

	/**
	 * Format version.
	 */
	const SCHEMA = 1;

	/**
	 * Maximum JSON nesting depth accepted when decoding.
	 */
	const MAX_DEPTH = 8;

	/**
	 * Keys of an action, in order (ActionSchedulerActionRecord::to_array()).
	 */
	const ACTION_KEYS = array( 'hook', 'group', 'status', 'timestamp', 'schedule_type', 'interval', 'cron_expression', 'is_recurring', 'args_fingerprint' );

	/**
	 * Encode a snapshot.
	 *
	 * @param ActionSchedulerSnapshot $snapshot Snapshot.
	 * @return string JSON.
	 * @throws UnexpectedValueException If the snapshot cannot be encoded.
	 */
	public function encode( ActionSchedulerSnapshot $snapshot ) {
		$data = array(
			'schema'              => self::SCHEMA,
			'fingerprint_context' => $snapshot->get_fingerprint_context(),
			'actions'             => $snapshot->to_array()['actions'],
		);

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure code, unit-tested without WordPress; errors throw.
			return json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Action Scheduler snapshot could not be encoded.' );
		}
	}

	/**
	 * Decode a snapshot.
	 *
	 * @param string $json JSON produced by encode().
	 * @return ActionSchedulerSnapshot
	 * @throws UnexpectedValueException If the data is malformed, not canonical or of an unsupported schema.
	 */
	public function decode( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			throw new UnexpectedValueException( 'Stored Action Scheduler snapshot is invalid.' );
		}

		try {
			$data = json_decode( $json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Stored Action Scheduler snapshot is invalid.' );
		}

		if ( ! is_array( $data ) || array( 'schema', 'fingerprint_context', 'actions' ) !== array_keys( $data ) ) {
			throw new UnexpectedValueException( 'Stored Action Scheduler snapshot is invalid.' );
		}
		if ( self::SCHEMA !== $data['schema'] ) {
			throw new UnexpectedValueException( 'Stored Action Scheduler snapshot has an unsupported schema.' );
		}
		if ( ! is_string( $data['fingerprint_context'] ) || 1 !== preg_match( '/^[a-z0-9-]+:[0-9a-f]{64}$/', $data['fingerprint_context'] ) ) {
			throw new UnexpectedValueException( 'Stored Action Scheduler snapshot is invalid.' );
		}
		if ( ! is_array( $data['actions'] ) || array_values( $data['actions'] ) !== $data['actions'] ) {
			throw new UnexpectedValueException( 'Stored Action Scheduler snapshot is invalid.' );
		}

		$actions = array();
		foreach ( $data['actions'] as $action ) {
			$actions[] = self::decode_action( $action );
		}

		try {
			$snapshot = new ActionSchedulerSnapshot( $actions, $data['fingerprint_context'] );
		} catch ( InvalidArgumentException $e ) {
			throw new UnexpectedValueException( 'Stored Action Scheduler snapshot is invalid.' );
		}

		// Canonical form only: same order and the same derived fields as the snapshot itself produces.
		if ( $snapshot->to_array()['actions'] !== $data['actions'] ) {
			throw new UnexpectedValueException( 'Stored Action Scheduler snapshot is invalid.' );
		}

		return $snapshot;
	}

	/**
	 * Validate and build one action record.
	 *
	 * @param mixed $action Decoded action entry.
	 * @return ActionSchedulerActionRecord
	 * @throws UnexpectedValueException If the entry is invalid.
	 */
	private static function decode_action( $action ) {
		$valid = is_array( $action )
			&& self::ACTION_KEYS === array_keys( $action )
			&& is_string( $action['hook'] )
			&& is_string( $action['group'] )
			&& ActionSchedulerDiffCodec::is_status( $action['status'] )
			&& is_int( $action['timestamp'] )
			&& $action['timestamp'] > 0
			&& ActionSchedulerDiffCodec::is_schedule( $action['schedule_type'], $action['interval'], $action['cron_expression'], $action['is_recurring'] )
			&& is_string( $action['args_fingerprint'] )
			&& 1 === preg_match( '/^[0-9a-f]{64}$/', $action['args_fingerprint'] );

		if ( ! $valid ) {
			throw new UnexpectedValueException( 'Stored Action Scheduler snapshot is invalid.' );
		}

		return new ActionSchedulerActionRecord(
			$action['hook'],
			$action['group'],
			$action['status'],
			$action['timestamp'],
			$action['schedule_type'],
			$action['interval'],
			$action['cron_expression'],
			$action['args_fingerprint']
		);
	}
}
