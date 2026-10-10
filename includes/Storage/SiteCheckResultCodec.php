<?php
/**
 * JSON format for site check results.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Storage;

use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;
use UpdateLens\SiteCheck\Attempt;
use UpdateLens\SiteCheck\CheckContext;
use UpdateLens\SiteCheck\CheckResult;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes one check run (CheckResult) as compact versioned JSON and decodes
 * it with full validation. Used for BEFORE baselines (Baselines) and for the
 * BEFORE results attached to an analysis (Update\SiteCheckObservation).
 *
 * Contains only fixed codes, HTTP statuses, durations, server timestamps,
 * the definition version, the user ID, two keyed fingerprints (target,
 * login session) and the updater activity generation at the session start
 * (`epoch:sequence`, or null): never response bodies, headers, cookies,
 * nonces, tokens, markers, URLs, user IDs seen in responses, or error
 * messages. Origin and verdicts are derived on decode, never stored.
 * Schema 2 added the generation; schema 1 values are not decoded (they
 * cannot prove which updates came after them).
 */
final class SiteCheckResultCodec {

	/**
	 * Format version.
	 */
	const SCHEMA = 2;

	/**
	 * Largest accepted stored value, in bytes.
	 */
	const MAX_BYTES = 2048;

	/**
	 * Maximum JSON nesting depth accepted when decoding.
	 */
	const MAX_DEPTH = 4;

	/**
	 * Keys, in order.
	 */
	const KEYS = array( 'schema', 'check', 'definition', 'target', 'auth', 'user', 'session', 'started_at', 'completed_at', 'attempts', 'generation' );

	/**
	 * Encode a result.
	 *
	 * @param CheckResult $result Result.
	 * @return string JSON.
	 * @throws UnexpectedValueException If it cannot be encoded.
	 */
	public function encode( CheckResult $result ) {
		$context  = $result->get_context();
		$attempts = array();
		foreach ( $result->get_attempts() as $attempt ) {
			$attempts[] = $attempt->to_array();
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure code, unit-tested without WordPress; errors throw.
			return json_encode(
				array(
					'schema'       => self::SCHEMA,
					'check'        => $result->get_check(),
					'definition'   => $result->get_definition_version(),
					'target'       => $context->get_target(),
					'auth'         => $context->get_auth(),
					'user'         => $context->get_user(),
					'session'      => $context->get_session(),
					'started_at'   => $result->get_started_at(),
					'completed_at' => $result->get_completed_at(),
					'attempts'     => $attempts,
					'generation'   => $result->get_generation(),
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
			);
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Site check result could not be encoded.' );
		}
	}

	/**
	 * Decode a stored result.
	 *
	 * @param mixed $json JSON produced by encode().
	 * @return CheckResult
	 * @throws UnexpectedValueException If the data is malformed or of an unsupported schema.
	 */
	public function decode( $json ) {
		if ( ! is_string( $json ) || '' === $json || strlen( $json ) > self::MAX_BYTES ) {
			throw new UnexpectedValueException( 'Stored site check result is invalid.' );
		}

		try {
			$data = json_decode( $json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Stored site check result is invalid.' );
		}

		if ( ! is_array( $data ) || ! array_key_exists( 'schema', $data ) ) {
			throw new UnexpectedValueException( 'Stored site check result is invalid.' );
		}
		if ( self::SCHEMA !== $data['schema'] ) {
			throw new UnexpectedValueException( 'Stored site check result has an unsupported schema.' );
		}
		if ( self::KEYS !== array_keys( $data ) || ! is_array( $data['attempts'] ) || array_values( $data['attempts'] ) !== $data['attempts'] ) {
			throw new UnexpectedValueException( 'Stored site check result is invalid.' );
		}

		try {
			$attempts = array();
			foreach ( $data['attempts'] as $attempt ) {
				$attempts[] = Attempt::from_array( $data['check'], $attempt );
			}

			return new CheckResult(
				$data['check'],
				$data['definition'],
				new CheckContext( $data['target'], $data['auth'], $data['user'], $data['session'] ),
				$data['started_at'],
				$data['completed_at'],
				$attempts,
				$data['generation']
			);
		} catch ( InvalidArgumentException $e ) {
			throw new UnexpectedValueException( 'Stored site check result is invalid.' );
		}
	}
}
