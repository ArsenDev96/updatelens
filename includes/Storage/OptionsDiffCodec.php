<?php
/**
 * JSON persistence format for options diffs.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Storage;

use JsonException;
use UnexpectedValueException;
use UpdateLens\Diff\OptionsDiff;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes an OptionsDiff as versioned JSON.
 *
 * Contains exactly OptionsDiff::to_array() (added, removed, changed, summary)
 * plus the format version: no option values, fingerprints or hashing context.
 */
final class OptionsDiffCodec {

	/**
	 * Format version.
	 */
	const SCHEMA = 1;

	/**
	 * Encode a diff.
	 *
	 * @param OptionsDiff $diff Diff.
	 * @return string JSON.
	 * @throws UnexpectedValueException If the diff cannot be encoded.
	 */
	public function encode( OptionsDiff $diff ) {
		$data = array( 'schema' => self::SCHEMA ) + $diff->to_array();

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure code, unit-tested without WordPress; errors throw.
			return json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Diff could not be encoded.' );
		}
	}
}
