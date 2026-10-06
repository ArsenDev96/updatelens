<?php
/**
 * JSON persistence format for WP-Cron snapshots.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Storage;

use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;
use UpdateLens\Snapshot\CronEventRecord;
use UpdateLens\Snapshot\CronSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes a CronSnapshot as versioned JSON and decodes it back.
 *
 * Holds exactly the snapshot's safe event records (hook, timing, recurrence,
 * keyed arguments fingerprint) and its fingerprint context, never arguments.
 * The summary is derived from the events on decode, as for options
 * snapshots, so it is not stored. Decoding validates every field and accepts
 * only the canonical event order, so a decoded snapshot re-encodes to the
 * same bytes.
 *
 * Format (schema 1):
 * {"schema":1,"fingerprint_context":"cron-args-hmac-sha256-v1:…","events":[
 *   {"hook":"…","timestamp":1767225600,"schedule":"daily","interval":86400,"is_recurring":true,"args_fingerprint":"<64 hex>"}, …]}
 */
final class CronSnapshotCodec {

	/**
	 * Format version.
	 */
	const SCHEMA = 1;

	/**
	 * Maximum JSON nesting depth accepted when decoding.
	 */
	const MAX_DEPTH = 8;

	/**
	 * Keys of an event, in order.
	 */
	const EVENT_KEYS = array( 'hook', 'timestamp', 'schedule', 'interval', 'is_recurring', 'args_fingerprint' );

	/**
	 * Encode a snapshot.
	 *
	 * @param CronSnapshot $snapshot Snapshot.
	 * @return string JSON.
	 * @throws UnexpectedValueException If the snapshot cannot be encoded.
	 */
	public function encode( CronSnapshot $snapshot ) {
		$data = array(
			'schema'              => self::SCHEMA,
			'fingerprint_context' => $snapshot->get_fingerprint_context(),
			'events'              => $snapshot->to_array()['events'],
		);

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure code, unit-tested without WordPress; errors throw.
			return json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Cron snapshot could not be encoded.' );
		}
	}

	/**
	 * Decode a snapshot.
	 *
	 * @param string $json JSON produced by encode().
	 * @return CronSnapshot
	 * @throws UnexpectedValueException If the data is malformed, not canonical or of an unsupported schema.
	 */
	public function decode( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			throw new UnexpectedValueException( 'Stored cron snapshot is invalid.' );
		}

		try {
			$data = json_decode( $json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Stored cron snapshot is invalid.' );
		}

		if ( ! is_array( $data ) || array( 'schema', 'fingerprint_context', 'events' ) !== array_keys( $data ) ) {
			throw new UnexpectedValueException( 'Stored cron snapshot is invalid.' );
		}
		if ( self::SCHEMA !== $data['schema'] ) {
			throw new UnexpectedValueException( 'Stored cron snapshot has an unsupported schema.' );
		}
		if ( ! is_string( $data['fingerprint_context'] ) || 1 !== preg_match( '/^[a-z0-9-]+:[0-9a-f]{64}$/', $data['fingerprint_context'] ) ) {
			throw new UnexpectedValueException( 'Stored cron snapshot is invalid.' );
		}
		if ( ! is_array( $data['events'] ) || array_values( $data['events'] ) !== $data['events'] ) {
			throw new UnexpectedValueException( 'Stored cron snapshot is invalid.' );
		}

		$events = array();
		foreach ( $data['events'] as $event ) {
			$events[] = self::decode_event( $event );
		}

		try {
			$snapshot = new CronSnapshot( $events, $data['fingerprint_context'] );
		} catch ( InvalidArgumentException $e ) {
			throw new UnexpectedValueException( 'Stored cron snapshot is invalid.' );
		}

		// Canonical form only: same order and the same derived fields as the snapshot itself produces.
		if ( $snapshot->to_array()['events'] !== $data['events'] ) {
			throw new UnexpectedValueException( 'Stored cron snapshot is invalid.' );
		}

		return $snapshot;
	}

	/**
	 * Validate and build one event record.
	 *
	 * @param mixed $event Decoded event entry.
	 * @return CronEventRecord
	 * @throws UnexpectedValueException If the entry is invalid.
	 */
	private static function decode_event( $event ) {
		$valid = is_array( $event )
			&& self::EVENT_KEYS === array_keys( $event )
			&& is_string( $event['hook'] )
			&& is_int( $event['timestamp'] )
			&& $event['timestamp'] > 0
			&& ( null === $event['schedule'] || ( is_string( $event['schedule'] ) && '' !== $event['schedule'] ) )
			&& ( null === $event['interval'] || ( is_int( $event['interval'] ) && $event['interval'] >= 0 ) )
			&& is_bool( $event['is_recurring'] )
			&& is_string( $event['args_fingerprint'] )
			&& 1 === preg_match( '/^[0-9a-f]{64}$/', $event['args_fingerprint'] );

		if ( ! $valid ) {
			throw new UnexpectedValueException( 'Stored cron snapshot is invalid.' );
		}

		return new CronEventRecord( $event['hook'], $event['timestamp'], $event['schedule'], $event['interval'], $event['args_fingerprint'] );
	}
}
