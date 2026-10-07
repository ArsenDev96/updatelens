<?php
/**
 * JSON persistence format for the monitoring baseline.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Storage;

use JsonException;
use UnexpectedValueException;
use UpdateLens\Baseline\PluginInventory;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes the monitoring baseline (start time plus plugin inventory) as
 * versioned JSON and decodes it with full validation.
 *
 * Contains only the start time (UTC `Y-m-d H:i:s`) and, per plugin, its file,
 * name, version and active state: no settings, option values, update data,
 * URLs or user information.
 */
final class MonitoringBaselineCodec {

	/**
	 * Format version.
	 */
	const SCHEMA = 1;

	/**
	 * Maximum JSON nesting depth accepted when decoding.
	 */
	const MAX_DEPTH = 4;

	/**
	 * Keys of a plugin entry, in order.
	 */
	const PLUGIN_KEYS = array( 'file', 'name', 'version', 'active' );

	/**
	 * Encode a baseline.
	 *
	 * @param string                                                                       $started_at UTC start time (`Y-m-d H:i:s`).
	 * @param array<int, array{file: string, name: string, version: string, active: bool}> $plugins    PluginInventory::build() records.
	 * @return string JSON.
	 * @throws UnexpectedValueException If the baseline is invalid or cannot be encoded.
	 */
	public function encode( $started_at, array $plugins ) {
		$data = array(
			'schema'     => self::SCHEMA,
			'started_at' => $started_at,
			'plugins'    => $plugins,
		);
		if ( ! self::is_valid( $data ) ) {
			throw new UnexpectedValueException( 'Monitoring baseline is invalid.' );
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure code, unit-tested without WordPress; errors throw.
			return json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Monitoring baseline could not be encoded.' );
		}
	}

	/**
	 * Decode a stored baseline.
	 *
	 * @param mixed $json JSON produced by encode().
	 * @return array{started_at: string, plugins: array<int, array{file: string, name: string, version: string, active: bool}>}
	 * @throws UnexpectedValueException If the data is malformed or of an unsupported schema.
	 */
	public function decode( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			throw new UnexpectedValueException( 'Stored monitoring baseline is invalid.' );
		}

		try {
			$data = json_decode( $json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Stored monitoring baseline is invalid.' );
		}

		if ( ! is_array( $data ) || ! array_key_exists( 'schema', $data ) ) {
			throw new UnexpectedValueException( 'Stored monitoring baseline is invalid.' );
		}
		if ( self::SCHEMA !== $data['schema'] ) {
			throw new UnexpectedValueException( 'Stored monitoring baseline has an unsupported schema.' );
		}
		if ( ! self::is_valid( $data ) ) {
			throw new UnexpectedValueException( 'Stored monitoring baseline is invalid.' );
		}

		return array(
			'started_at' => $data['started_at'],
			'plugins'    => $data['plugins'],
		);
	}

	/**
	 * Whether data has exactly the baseline shape with valid values.
	 *
	 * @param mixed $data Data.
	 * @return bool
	 */
	private static function is_valid( $data ) {
		if ( ! is_array( $data ) || array( 'schema', 'started_at', 'plugins' ) !== array_keys( $data ) ) {
			return false;
		}
		if ( ! self::is_datetime( $data['started_at'] ) ) {
			return false;
		}

		$plugins = $data['plugins'];
		if ( ! is_array( $plugins ) || array_values( $plugins ) !== $plugins ) {
			return false;
		}

		$files = array();
		foreach ( $plugins as $plugin ) {
			if ( ! is_array( $plugin ) || self::PLUGIN_KEYS !== array_keys( $plugin ) ) {
				return false;
			}
			if ( ! PluginInventory::is_plugin_file( $plugin['file'] ) || isset( $files[ $plugin['file'] ] ) ) {
				return false;
			}
			if ( ! self::is_text( $plugin['name'], PluginInventory::MAX_NAME_LENGTH, false ) || ! self::is_text( $plugin['version'], PluginInventory::MAX_VERSION_LENGTH, true ) || ! is_bool( $plugin['active'] ) ) {
				return false;
			}
			$files[ $plugin['file'] ] = true;
		}

		return true;
	}

	/**
	 * Whether a value is a valid UTC DATETIME string (`Y-m-d H:i:s`).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_datetime( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/D', $value, $parts ) ) {
			return false;
		}

		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) && (int) $parts[4] <= 23 && (int) $parts[5] <= 59 && (int) $parts[6] <= 59;
	}

	/**
	 * Whether a value is UTF-8 text without control characters, within a length limit.
	 *
	 * @param mixed $value       Value.
	 * @param int   $max_length  Maximum length in characters.
	 * @param bool  $allow_empty Whether the empty string is valid.
	 * @return bool
	 */
	private static function is_text( $value, $max_length, $allow_empty ) {
		if ( ! is_string( $value ) || ( '' === $value && ! $allow_empty ) ) {
			return false;
		}

		return 1 === preg_match( '/^[^\x00-\x1f\x7f]{0,' . (int) $max_length . '}$/Du', $value );
	}
}
