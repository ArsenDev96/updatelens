<?php
/**
 * Captures the current `wp_options` state.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use RuntimeException;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads `wp_options` with $wpdb and hands the rows to OptionsSnapshotBuilder.
 *
 * Read-only. Reads the stored strings directly (no get_option(), no
 * unserializing) in batches, so a large options table is never held in
 * memory at once.
 */
final class OptionsSnapshotProvider {

	/**
	 * Rows per query.
	 */
	const BATCH_SIZE = 500;

	/**
	 * Database.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Snapshot builder.
	 *
	 * @var OptionsSnapshotBuilder
	 */
	private $builder;

	/**
	 * Constructor.
	 *
	 * @param wpdb                   $wpdb    Database.
	 * @param OptionsSnapshotBuilder $builder Snapshot builder.
	 */
	public function __construct( wpdb $wpdb, OptionsSnapshotBuilder $builder ) {
		$this->wpdb    = $wpdb;
		$this->builder = $builder;
	}

	/**
	 * Provider wired to the running WordPress site.
	 *
	 * @return self
	 */
	public static function create() {
		global $wpdb;

		return new self(
			$wpdb,
			new OptionsSnapshotBuilder(
				OptionValueHasher::from_wordpress(),
				new OptionNoiseFilter(),
				AutoloadPolicy::from_wordpress()
			)
		);
	}

	/**
	 * Snapshot the current `wp_options` table.
	 *
	 * @return OptionsSnapshot
	 * @throws RuntimeException If a query fails (a partial snapshot would produce false changes).
	 */
	public function capture() {
		return $this->builder->build( $this->fetch_rows() );
	}

	/**
	 * Yield all option rows, paginating by option_name (keyset pagination).
	 *
	 * Batches are separate queries, so the read is not one atomic view of the
	 * table.
	 *
	 * @return \Generator<array{option_name: string, option_value: string, autoload: string}>
	 * @throws RuntimeException If a query fails.
	 */
	private function fetch_rows() {
		$wpdb = $this->wpdb;
		$last = null;

		do {
			if ( null === $last ) {
				$sql = $wpdb->prepare(
					"SELECT option_name, option_value, autoload FROM {$wpdb->options} ORDER BY option_name ASC LIMIT %d",
					self::BATCH_SIZE
				);
			} else {
				$sql = $wpdb->prepare(
					"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name > %s ORDER BY option_name ASC LIMIT %d",
					$last,
					self::BATCH_SIZE
				);
			}

			// Snapshots must read the stored values, not the (possibly stale) object cache.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			$rows = $wpdb->get_results( $sql, ARRAY_A );

			if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
				throw new RuntimeException( 'UpdateLens could not read the options table.' );
			}

			foreach ( $rows as $row ) {
				yield $row;
			}

			$count = count( $rows );
			if ( $count ) {
				$last = $rows[ $count - 1 ]['option_name'];
			}
		} while ( self::BATCH_SIZE === $count );
	}
}
