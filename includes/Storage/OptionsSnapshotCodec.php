<?php
/**
 * JSON persistence format for options snapshots.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Storage;

use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;
use UpdateLens\Snapshot\OptionRecord;
use UpdateLens\Snapshot\OptionsSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes an OptionsSnapshot as versioned JSON and decodes it back.
 *
 * Holds the same safe metadata as the snapshot (names, fingerprints, sizes,
 * autoload state, fingerprint context), never option values. Decoding
 * validates every field and never unserializes objects.
 *
 * Format (schema 1):
 * {"schema":1,"fingerprint_context":"hmac-sha256-v1:…","options":[
 *   {"name":"…","fingerprint":"<64 hex>","size":123,"autoload":"auto-on","is_autoloaded":true}, …]}
 */
final class OptionsSnapshotCodec {

	/**
	 * Format version.
	 */
	const SCHEMA = 1;

	/**
	 * Maximum JSON nesting depth accepted when decoding.
	 */
	const MAX_DEPTH = 8;

	/**
	 * Encode a snapshot.
	 *
	 * @param OptionsSnapshot $snapshot Snapshot.
	 * @return string JSON.
	 * @throws UnexpectedValueException If the snapshot cannot be encoded (e.g. invalid UTF-8 in a name).
	 */
	public function encode( OptionsSnapshot $snapshot ) {
		$options = array();
		foreach ( $snapshot->get_records() as $record ) {
			$options[] = $record->to_array();
		}

		$data = array(
			'schema'              => self::SCHEMA,
			'fingerprint_context' => $snapshot->get_fingerprint_context(),
			'options'             => $options,
		);

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure code, unit-tested without WordPress; errors throw.
			return json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Snapshot could not be encoded.' );
		}
	}

	/**
	 * Decode a snapshot.
	 *
	 * @param string $json JSON produced by encode().
	 * @return OptionsSnapshot
	 * @throws UnexpectedValueException If the data is malformed, corrupt or of an unsupported schema.
	 */
	public function decode( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			throw new UnexpectedValueException( 'Stored snapshot is invalid.' );
		}

		try {
			$data = json_decode( $json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Stored snapshot is invalid.' );
		}

		if ( ! is_array( $data ) || array( 'schema', 'fingerprint_context', 'options' ) !== array_keys( $data ) ) {
			throw new UnexpectedValueException( 'Stored snapshot is invalid.' );
		}
		if ( self::SCHEMA !== $data['schema'] ) {
			throw new UnexpectedValueException( 'Stored snapshot has an unsupported schema.' );
		}
		if ( ! is_string( $data['fingerprint_context'] ) || 1 !== preg_match( '/^[a-z0-9-]+:[0-9a-f]{64}$/', $data['fingerprint_context'] ) ) {
			throw new UnexpectedValueException( 'Stored snapshot is invalid.' );
		}
		if ( ! is_array( $data['options'] ) || array_values( $data['options'] ) !== $data['options'] ) {
			throw new UnexpectedValueException( 'Stored snapshot is invalid.' );
		}

		$records = array();
		foreach ( $data['options'] as $option ) {
			$records[] = self::decode_record( $option );
		}

		try {
			return new OptionsSnapshot( $records, $data['fingerprint_context'] );
		} catch ( InvalidArgumentException $e ) {
			throw new UnexpectedValueException( 'Stored snapshot is invalid.' );
		}
	}

	/**
	 * Validate and build one record.
	 *
	 * @param mixed $option Decoded option entry.
	 * @return OptionRecord
	 * @throws UnexpectedValueException If the entry is invalid.
	 */
	private static function decode_record( $option ) {
		$valid = is_array( $option )
			&& array( 'name', 'fingerprint', 'size', 'autoload', 'is_autoloaded' ) === array_keys( $option )
			&& is_string( $option['name'] )
			&& is_string( $option['fingerprint'] )
			&& 1 === preg_match( '/^[0-9a-f]{64}$/', $option['fingerprint'] )
			&& is_int( $option['size'] )
			&& $option['size'] >= 0
			&& is_string( $option['autoload'] )
			&& is_bool( $option['is_autoloaded'] );

		if ( ! $valid ) {
			throw new UnexpectedValueException( 'Stored snapshot is invalid.' );
		}

		return new OptionRecord( $option['name'], $option['fingerprint'], $option['size'], $option['autoload'], $option['is_autoloaded'] );
	}
}
