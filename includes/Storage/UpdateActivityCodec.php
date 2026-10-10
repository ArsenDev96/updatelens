<?php
/**
 * JSON format of the updater activity ledger.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Storage;

use JsonException;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes the updater activity ledger (SiteCheck\UpdateActivity) as
 * versioned JSON and decodes it with full validation:
 *
 *     {"schema":1,"epoch":"<16 hex>","sequence":12,"running":{"<32 hex>":1760090000.123456}}
 *
 * `running` maps a random per-request token to the Unix time its updater
 * activity started. Nothing else: no user, plugin, package, path or URL.
 */
final class UpdateActivityCodec {

	/**
	 * Format version.
	 */
	const SCHEMA = 1;

	/**
	 * Largest accepted stored value, in bytes.
	 */
	const MAX_BYTES = 4096;

	/**
	 * Maximum JSON nesting depth accepted when decoding.
	 */
	const MAX_DEPTH = 3;

	/**
	 * Largest sequence number; a ledger that reaches it starts a new epoch.
	 */
	const MAX_SEQUENCE = 99999999999999999;

	/**
	 * Most running entries kept.
	 */
	const MAX_RUNNING = 32;

	/**
	 * Keys, in order.
	 */
	const KEYS = array( 'schema', 'epoch', 'sequence', 'running' );

	/**
	 * Encode a ledger state.
	 *
	 * @param array{epoch: string, sequence: int, running: array<string, float>} $state State (valid, see is_state()).
	 * @return string JSON.
	 * @throws UnexpectedValueException If the state is invalid or cannot be encoded.
	 */
	public function encode( array $state ) {
		if ( ! self::is_state( $state ) ) {
			throw new UnexpectedValueException( 'Update activity state is invalid.' );
		}
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure code, unit-tested without WordPress; errors throw.
			return json_encode(
				array(
					'schema'   => self::SCHEMA,
					'epoch'    => $state['epoch'],
					'sequence' => $state['sequence'],
					'running'  => (object) $state['running'],
				),
				JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
			);
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Update activity state could not be encoded.' );
		}
	}

	/**
	 * Decode a stored ledger state.
	 *
	 * @param mixed $json JSON produced by encode().
	 * @return array{epoch: string, sequence: int, running: array<string, float>}
	 * @throws UnexpectedValueException If the data is malformed or of another schema.
	 */
	public function decode( $json ) {
		if ( ! is_string( $json ) || '' === $json || strlen( $json ) > self::MAX_BYTES ) {
			throw new UnexpectedValueException( 'Stored update activity is invalid.' );
		}
		try {
			$data = json_decode( $json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Stored update activity is invalid.' );
		}
		if ( ! is_array( $data ) || self::KEYS !== array_keys( $data ) || self::SCHEMA !== $data['schema'] || ! is_array( $data['running'] ) ) {
			throw new UnexpectedValueException( 'Stored update activity is invalid.' );
		}

		$running = array();
		foreach ( $data['running'] as $token => $started ) {
			$running[ (string) $token ] = is_int( $started ) ? (float) $started : $started;
		}
		$state = array(
			'epoch'    => $data['epoch'],
			'sequence' => $data['sequence'],
			'running'  => $running,
		);
		if ( ! self::is_state( $state ) ) {
			throw new UnexpectedValueException( 'Stored update activity is invalid.' );
		}

		return $state;
	}

	/**
	 * Whether a value is a valid ledger state.
	 *
	 * @param mixed $state Value.
	 * @return bool
	 */
	public static function is_state( $state ) {
		if ( ! is_array( $state ) || array( 'epoch', 'sequence', 'running' ) !== array_keys( $state ) ) {
			return false;
		}
		if ( ! is_string( $state['epoch'] ) || 1 !== preg_match( '/\A[a-f0-9]{16}\z/', $state['epoch'] ) ) {
			return false;
		}
		if ( ! is_int( $state['sequence'] ) || $state['sequence'] < 0 || $state['sequence'] > self::MAX_SEQUENCE ) {
			return false;
		}
		if ( ! is_array( $state['running'] ) || count( $state['running'] ) > self::MAX_RUNNING ) {
			return false;
		}
		foreach ( $state['running'] as $token => $started ) {
			if ( ! is_string( $token ) || 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $token ) || ! is_float( $started ) || ! is_finite( $started ) || $started <= 0 ) {
				return false;
			}
		}

		return true;
	}
}
