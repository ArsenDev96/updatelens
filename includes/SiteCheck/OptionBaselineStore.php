<?php
/**
 * Baselines in the options table.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * One non-autoloaded `wp_options` row per baseline (`Baselines::STORAGE_PREFIX`
 * + hash, so UpdateLens's own snapshots never include it), read and written
 * with SQL only, never through the options API or the object cache:
 *
 * - add() is an `INSERT IGNORE` on the unique option name;
 * - replace() and remove() compare the exact stored bytes
 *   (`CAST( … AS BINARY )`; the column's collation alone would ignore case
 *   and trailing spaces).
 *
 * Every statement is a single atomic row operation, so two requests that
 * read the same value can never both write.
 */
final class OptionBaselineStore implements BaselineStore {

	/**
	 * Autoload value of baseline rows (never autoloaded, on every supported
	 * WordPress version).
	 */
	const AUTOLOAD = 'no';

	/**
	 * The stored value of a key.
	 *
	 * @param string $key Storage key.
	 * @return string|null
	 * @throws UnexpectedValueException If the read failed.
	 */
	public function get( $key ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic baseline rows; must never be served from a cache.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, (string) $key ) );
		self::assert_no_error();

		return null === $value ? null : (string) $value;
	}

	/**
	 * Store a value only if the key has none.
	 *
	 * @param string $key   Storage key.
	 * @param string $value Value.
	 * @return bool
	 */
	public function add( $key, $value ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic create-if-missing on the unique option name; never cached.
		return 1 === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, (string) $key, (string) $value, self::AUTOLOAD ) );
	}

	/**
	 * Replace a value only if it is still exactly $expected.
	 *
	 * @param string $key      Storage key.
	 * @param string $expected Value read before.
	 * @param string $value    New value.
	 * @return bool
	 */
	public function replace( $key, $expected, $value ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set; never cached.
		return 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, (string) $value, (string) $key, (string) $expected ) );
	}

	/**
	 * Remove a value only if it is still exactly $expected.
	 *
	 * @param string $key      Storage key.
	 * @param string $expected Value read before.
	 * @return bool
	 */
	public function remove( $key, $expected ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-delete; never cached.
		return 1 === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, (string) $key, (string) $expected ) );
	}

	/**
	 * Stored baselines of every user and login session.
	 *
	 * @param int $limit Most entries returned.
	 * @return array<string, string>
	 * @throws UnexpectedValueException If the read failed.
	 */
	public function all( $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded scan of UpdateLens's own rows (unique index range); never cached.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d', $wpdb->options, $wpdb->esc_like( Baselines::STORAGE_PREFIX ) . '%', max( 1, (int) $limit ) ), ARRAY_A );
		self::assert_no_error();

		$values = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $row['option_name'], $row['option_value'] ) ) {
				$values[ (string) $row['option_name'] ] = (string) $row['option_value'];
			}
		}

		return $values;
	}

	/**
	 * Remove every stored baseline and the updater activity ledger. Used on uninstall.
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UpdateLens's own rows, never cached.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name = %s', $wpdb->options, $wpdb->esc_like( Baselines::STORAGE_PREFIX ) . '%', UpdateActivity::STORAGE_KEY ) );
	}

	/**
	 * Throw if the last query failed (a failed read is never "no baseline").
	 *
	 * @return void
	 * @throws UnexpectedValueException If it failed.
	 */
	private static function assert_no_error() {
		global $wpdb;

		if ( '' !== $wpdb->last_error ) {
			throw new UnexpectedValueException( 'Site check baselines could not be read.' );
		}
	}
}
