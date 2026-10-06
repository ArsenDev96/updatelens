<?php
/**
 * Deterministic ordering for cron records.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Compares two field lists position by position.
 *
 * Cron events have no unique name to key on, so snapshots and diffs sort
 * their lists with this comparator to stay independent of input order. Per
 * position: null sorts first, strings compare byte-wise (independent of
 * locale and collation), integers and booleans numerically.
 */
final class CronEventOrder {

	/**
	 * Compare two field lists of the same shape.
	 *
	 * @param array<int, string|int|bool|null> $a First fields.
	 * @param array<int, string|int|bool|null> $b Second fields.
	 * @return int Negative, zero or positive.
	 */
	public static function compare( array $a, array $b ) {
		$a = array_values( $a );
		$b = array_values( $b );

		foreach ( $a as $index => $value ) {
			$result = self::compare_value( $value, $b[ $index ] );
			if ( 0 !== $result ) {
				return $result;
			}
		}

		return 0;
	}

	/**
	 * Compare two field values.
	 *
	 * @param string|int|bool|null $a First value.
	 * @param string|int|bool|null $b Second value.
	 * @return int -1, 0 or 1.
	 */
	private static function compare_value( $a, $b ) {
		if ( null === $a || null === $b ) {
			return ( null === $a ? 0 : 1 ) - ( null === $b ? 0 : 1 );
		}

		if ( is_string( $a ) || is_string( $b ) ) {
			return strcmp( (string) $a, (string) $b ) <=> 0;
		}

		return (int) $a <=> (int) $b;
	}
}
