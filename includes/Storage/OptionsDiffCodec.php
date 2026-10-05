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
 * Encodes an OptionsDiff as versioned JSON and decodes it for reports.
 *
 * Contains exactly OptionsDiff::to_array() (added, removed, changed, summary)
 * plus the format version: no option values, fingerprints or hashing context.
 * Decoding validates every field, including the values derived from others
 * (deltas, flags, counts), and returns the OptionsDiff::to_array() shape.
 */
final class OptionsDiffCodec {

	/**
	 * Format version.
	 */
	const SCHEMA = 1;

	/**
	 * Maximum JSON nesting depth accepted when decoding.
	 */
	const MAX_DEPTH = 8;

	/**
	 * Keys of an added/removed option, in order.
	 */
	const STATE_KEYS = array( 'name', 'size', 'autoload', 'is_autoloaded' );

	/**
	 * Keys of a changed option, in order.
	 */
	const CHANGED_KEYS = array(
		'name',
		'value_changed',
		'before_size',
		'after_size',
		'size_delta',
		'before_autoload',
		'after_autoload',
		'autoload_value_changed',
		'before_is_autoloaded',
		'after_is_autoloaded',
		'autoload_behavior_changed',
	);

	/**
	 * Keys of the summary, in order.
	 */
	const SUMMARY_KEYS = array(
		'before_option_count',
		'after_option_count',
		'option_count_delta',
		'before_total_bytes',
		'after_total_bytes',
		'total_bytes_delta',
		'before_autoloaded_count',
		'after_autoloaded_count',
		'autoloaded_count_delta',
		'before_autoloaded_bytes',
		'after_autoloaded_bytes',
		'autoloaded_bytes_delta',
		'added_count',
		'removed_count',
		'changed_count',
		'value_changed_count',
		'autoload_value_changed_count',
		'autoload_behavior_changed_count',
	);

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

	/**
	 * Decode a stored diff.
	 *
	 * @param string $json JSON produced by encode().
	 * @return array{added: array<int, array>, removed: array<int, array>, changed: array<int, array>, summary: array<string, int>} Same shape as OptionsDiff::to_array().
	 * @throws UnexpectedValueException If the data is malformed, inconsistent or of an unsupported schema.
	 */
	public function decode( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			throw new UnexpectedValueException( 'Stored diff is invalid.' );
		}

		try {
			$data = json_decode( $json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Stored diff is invalid.' );
		}

		if ( ! is_array( $data ) || array( 'schema', 'added', 'removed', 'changed', 'summary' ) !== array_keys( $data ) ) {
			throw new UnexpectedValueException( 'Stored diff is invalid.' );
		}
		if ( self::SCHEMA !== $data['schema'] ) {
			throw new UnexpectedValueException( 'Stored diff has an unsupported schema.' );
		}

		$valid = self::is_sorted_list( $data['added'] )
			&& self::is_sorted_list( $data['removed'] )
			&& self::is_sorted_list( $data['changed'] )
			&& self::are_valid( $data['added'], array( self::class, 'is_valid_state' ) )
			&& self::are_valid( $data['removed'], array( self::class, 'is_valid_state' ) )
			&& self::are_valid( $data['changed'], array( self::class, 'is_valid_changed' ) )
			&& self::is_valid_summary( $data['summary'], $data['added'], $data['removed'], $data['changed'] );

		if ( ! $valid ) {
			throw new UnexpectedValueException( 'Stored diff is invalid.' );
		}

		unset( $data['schema'] );

		return $data;
	}

	/**
	 * Whether a value is a list of entries with string names in strictly increasing byte-wise order.
	 *
	 * @param mixed $entries Decoded collection.
	 * @return bool
	 */
	private static function is_sorted_list( $entries ) {
		if ( ! is_array( $entries ) || array_values( $entries ) !== $entries ) {
			return false;
		}

		$previous = null;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['name'] ) || ! is_string( $entry['name'] ) ) {
				return false;
			}
			if ( null !== $previous && strcmp( $previous, $entry['name'] ) >= 0 ) {
				return false;
			}
			$previous = $entry['name'];
		}

		return true;
	}

	/**
	 * Whether every entry passes a check.
	 *
	 * @param array    $entries Entries.
	 * @param callable $check   Validator.
	 * @return bool
	 */
	private static function are_valid( array $entries, callable $check ) {
		foreach ( $entries as $entry ) {
			if ( ! $check( $entry ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether an added/removed option entry is valid.
	 *
	 * @param array $entry Entry.
	 * @return bool
	 */
	private static function is_valid_state( array $entry ) {
		return self::STATE_KEYS === array_keys( $entry )
			&& self::is_size( $entry['size'] )
			&& is_string( $entry['autoload'] )
			&& is_bool( $entry['is_autoloaded'] );
	}

	/**
	 * Whether a changed option entry is valid and internally consistent.
	 *
	 * @param array $entry Entry.
	 * @return bool
	 */
	private static function is_valid_changed( array $entry ) {
		$valid = self::CHANGED_KEYS === array_keys( $entry )
			&& is_bool( $entry['value_changed'] )
			&& self::is_size( $entry['before_size'] )
			&& self::is_size( $entry['after_size'] )
			&& is_int( $entry['size_delta'] )
			&& is_string( $entry['before_autoload'] )
			&& is_string( $entry['after_autoload'] )
			&& is_bool( $entry['autoload_value_changed'] )
			&& is_bool( $entry['before_is_autoloaded'] )
			&& is_bool( $entry['after_is_autoloaded'] )
			&& is_bool( $entry['autoload_behavior_changed'] );

		return $valid
			&& $entry['after_size'] - $entry['before_size'] === $entry['size_delta']
			&& ( $entry['before_autoload'] !== $entry['after_autoload'] ) === $entry['autoload_value_changed']
			&& ( $entry['before_is_autoloaded'] !== $entry['after_is_autoloaded'] ) === $entry['autoload_behavior_changed']
			&& ( $entry['value_changed'] || 0 !== $entry['size_delta'] || $entry['autoload_value_changed'] || $entry['autoload_behavior_changed'] );
	}

	/**
	 * Whether the summary is valid and matches the option lists.
	 *
	 * @param mixed $summary Decoded summary.
	 * @param array $added   Added entries.
	 * @param array $removed Removed entries.
	 * @param array $changed Changed entries.
	 * @return bool
	 */
	private static function is_valid_summary( $summary, array $added, array $removed, array $changed ) {
		if ( ! is_array( $summary ) || self::SUMMARY_KEYS !== array_keys( $summary ) ) {
			return false;
		}
		foreach ( $summary as $key => $value ) {
			if ( ! is_int( $value ) || ( $value < 0 && '_delta' !== substr( $key, -6 ) ) ) {
				return false;
			}
		}
		foreach ( array( 'option_count', 'total_bytes', 'autoloaded_count', 'autoloaded_bytes' ) as $total ) {
			if ( $summary[ 'after_' . $total ] - $summary[ 'before_' . $total ] !== $summary[ $total . '_delta' ] ) {
				return false;
			}
		}

		$flag_counts = array(
			'value_changed_count'             => 'value_changed',
			'autoload_value_changed_count'    => 'autoload_value_changed',
			'autoload_behavior_changed_count' => 'autoload_behavior_changed',
		);
		foreach ( $flag_counts as $count_key => $flag ) {
			if ( count( array_filter( array_column( $changed, $flag ) ) ) !== $summary[ $count_key ] ) {
				return false;
			}
		}

		return count( $added ) === $summary['added_count']
			&& count( $removed ) === $summary['removed_count']
			&& count( $changed ) === $summary['changed_count'];
	}

	/**
	 * Whether a value is a byte size.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_size( $value ) {
		return is_int( $value ) && $value >= 0;
	}
}
