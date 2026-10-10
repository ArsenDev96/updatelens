<?php
/**
 * JSON format for site check sessions.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Storage;

use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;
use UpdateLens\SiteCheck\CheckSession;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes a CheckSession as versioned JSON (the transient value) and decodes
 * it with full validation. Contains no marker and no secret: only a SHA-256
 * of the secret, two keyed fingerprints, the user ID, codes, times and the
 * updater activity generation, and for AFTER sessions the analysis ID.
 * Schema 2 added the generation, schema 3 the analysis; sessions of older
 * schemas (live for two minutes at most) are no longer readable.
 */
final class SiteCheckSessionCodec {

	/**
	 * Format version.
	 */
	const SCHEMA = 3;

	/**
	 * Largest accepted stored value, in bytes.
	 */
	const MAX_BYTES = 1024;

	/**
	 * Maximum JSON nesting depth accepted when decoding.
	 */
	const MAX_DEPTH = 2;

	/**
	 * Encode a session.
	 *
	 * @param CheckSession $session Session.
	 * @return string JSON.
	 * @throws UnexpectedValueException If it cannot be encoded.
	 */
	public function encode( CheckSession $session ) {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure code, unit-tested without WordPress; errors throw.
			return json_encode( array( 'schema' => self::SCHEMA ) + $session->to_array(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Site check session could not be encoded.' );
		}
	}

	/**
	 * Decode a stored session.
	 *
	 * @param mixed $json JSON produced by encode().
	 * @return CheckSession
	 * @throws UnexpectedValueException If the data is malformed or of another schema.
	 */
	public function decode( $json ) {
		if ( ! is_string( $json ) || '' === $json || strlen( $json ) > self::MAX_BYTES ) {
			throw new UnexpectedValueException( 'Stored site check session is invalid.' );
		}

		try {
			$data = json_decode( $json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Stored site check session is invalid.' );
		}
		if ( ! is_array( $data ) || ! array_key_exists( 'schema', $data ) || self::SCHEMA !== $data['schema'] ) {
			throw new UnexpectedValueException( 'Stored site check session is invalid.' );
		}
		unset( $data['schema'] );

		try {
			return CheckSession::from_array( $data );
		} catch ( InvalidArgumentException $e ) {
			throw new UnexpectedValueException( 'Stored site check session is invalid.' );
		}
	}
}
