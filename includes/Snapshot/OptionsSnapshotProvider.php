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
 * unserializing), and never holds more than one batch of small values or one
 * large value at a time:
 *
 * - Each batch query returns BATCH_SIZE rows with their byte length, and the
 *   value only when it has at most INLINE_VALUE_BYTES; a batch therefore holds
 *   at most BATCH_SIZE × INLINE_VALUE_BYTES bytes of values.
 * - A larger value is read on its own, after the batch, and only for options
 *   the snapshot keeps (noise such as transients is never read), once the
 *   MemoryBudget allows VALUE_READ_FACTOR times its length.
 * - $wpdb's copy of every result is released right away.
 *
 * Before every batch and every large value the MemoryBudget is asked;
 * if the memory is not there, the capture stops with
 * InsufficientMemoryException and no snapshot exists: never a partial one.
 */
final class OptionsSnapshotProvider {

	/**
	 * Rows per query.
	 */
	const BATCH_SIZE = 500;

	/**
	 * Largest value read with its batch, in bytes. Larger values are read one
	 * at a time. Most options are far smaller; a batch holds at most
	 * BATCH_SIZE × INLINE_VALUE_BYTES (2 MiB) of values.
	 */
	const INLINE_VALUE_BYTES = 4096;

	/**
	 * Memory needed to read a value of n bytes through $wpdb, as a multiple
	 * of n: the driver's result buffer, $wpdb's result and the returned
	 * string exist at the same time (measured: 36 MiB for a 10 MiB value on
	 * PHP 7.4 and 8.1), rounded up.
	 */
	const VALUE_READ_FACTOR = 4;

	/**
	 * Bytes PHP may round each large allocation up to (one allocator chunk).
	 */
	const ALLOCATION_SLACK_BYTES = 2097152;

	/**
	 * Memory a kept option needs while the snapshot is built, in bytes
	 * (measured: about 570 bytes per record on PHP 7.4 and 8.1), rounded up.
	 */
	const RECORD_BYTES = 1024;

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
	 * Options the snapshot leaves out: their large values are never read.
	 *
	 * @var OptionNoiseFilter
	 */
	private $filter;

	/**
	 * Memory available to the capture.
	 *
	 * @var MemoryBudget
	 */
	private $budget;

	/**
	 * Constructor.
	 *
	 * @param wpdb                   $wpdb    Database.
	 * @param OptionsSnapshotBuilder $builder Snapshot builder.
	 * @param OptionNoiseFilter      $filter  The builder's noise filter.
	 * @param MemoryBudget           $budget  Memory available to the capture.
	 */
	public function __construct( wpdb $wpdb, OptionsSnapshotBuilder $builder, OptionNoiseFilter $filter, MemoryBudget $budget ) {
		$this->wpdb    = $wpdb;
		$this->builder = $builder;
		$this->filter  = $filter;
		$this->budget  = $budget;
	}

	/**
	 * Provider wired to the running WordPress site.
	 *
	 * @return self
	 */
	public static function create() {
		global $wpdb;

		$filter = new OptionNoiseFilter();

		return new self(
			$wpdb,
			new OptionsSnapshotBuilder(
				OptionValueHasher::from_wordpress(),
				$filter,
				AutoloadPolicy::from_wordpress()
			),
			$filter,
			MemoryBudget::from_php()
		);
	}

	/**
	 * Snapshot the current `wp_options` table.
	 *
	 * @return OptionsSnapshot
	 * @throws InsufficientMemoryException If the next batch or value would not fit in memory.
	 * @throws RuntimeException If a query fails (a partial snapshot would produce false changes).
	 */
	public function capture() {
		return $this->builder->build( $this->fetch_rows() );
	}

	/**
	 * Number of options a snapshot may keep, at most: every option except
	 * transients (most of the noise; the other noise rules match a few rows).
	 * One indexed count.
	 *
	 * @return int
	 * @throws RuntimeException If the query fails.
	 */
	public function count_options() {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must count the stored rows, not a cache.
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%' ) );
		if ( '' !== $wpdb->last_error || null === $count ) {
			throw new RuntimeException( 'UpdateLens could not read the options table.' );
		}
		$wpdb->flush();

		return (int) $count;
	}

	/**
	 * Yield all option rows the snapshot needs, paginating by option_name
	 * (keyset pagination).
	 *
	 * Batches and large values are separate queries, so the read is not one
	 * atomic view of the table (an option deleted before its large value is
	 * read is left out, as if it had been read later). The MemoryBudget
	 * throws InsufficientMemoryException before a batch or value that would
	 * not fit in memory.
	 *
	 * @return \Generator<array{option_name: string, option_value: string, autoload: string}>
	 * @throws RuntimeException If a query fails.
	 */
	private function fetch_rows() {
		$wpdb = $this->wpdb;
		$last = null;

		do {
			$this->budget->ensure( self::VALUE_READ_FACTOR * self::BATCH_SIZE * self::INLINE_VALUE_BYTES + self::BATCH_SIZE * self::RECORD_BYTES );

			if ( null === $last ) {
				$sql = $wpdb->prepare( 'SELECT option_name, autoload, LENGTH(option_value) AS value_bytes, IF(LENGTH(option_value) <= %d, option_value, NULL) AS option_value FROM %i ORDER BY option_name ASC LIMIT %d', self::INLINE_VALUE_BYTES, $wpdb->options, self::BATCH_SIZE );
			} else {
				$sql = $wpdb->prepare( 'SELECT option_name, autoload, LENGTH(option_value) AS value_bytes, IF(LENGTH(option_value) <= %d, option_value, NULL) AS option_value FROM %i WHERE option_name > %s ORDER BY option_name ASC LIMIT %d', self::INLINE_VALUE_BYTES, $wpdb->options, $last, self::BATCH_SIZE );
			}

			// Snapshots must read the stored values, not the (possibly stale) object cache.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Read-only; must not be cached. Prepared above.
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
				throw new RuntimeException( 'UpdateLens could not read the options table.' );
			}
			$wpdb->flush();

			$count = count( $rows );
			if ( $count ) {
				$last = (string) $rows[ $count - 1 ]['option_name'];
			}

			foreach ( $rows as $row ) {
				$name = (string) $row['option_name'];
				if ( null === $row['option_value'] ) {
					if ( $this->filter->is_noise( $name ) ) {
						continue; // Left out of the snapshot: never read.
					}
					$row['option_value'] = $this->large_value( $name, (int) $row['value_bytes'] );
					if ( null === $row['option_value'] ) {
						continue; // Deleted since the batch was read.
					}
				}

				yield array(
					'option_name'  => $name,
					'option_value' => $row['option_value'],
					'autoload'     => $row['autoload'],
				);
			}
		} while ( self::BATCH_SIZE === $count );
	}

	/**
	 * Read one value larger than INLINE_VALUE_BYTES, if memory allows (else
	 * the MemoryBudget throws InsufficientMemoryException before the read).
	 *
	 * @param string $name  Option name.
	 * @param int    $bytes Its length when the batch was read.
	 * @return string|null Null if the option no longer exists.
	 * @throws RuntimeException If the query fails.
	 */
	private function large_value( $name, $bytes ) {
		$this->budget->ensure( self::VALUE_READ_FACTOR * $bytes + self::ALLOCATION_SLACK_BYTES );

		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only; must not be cached.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $name ) );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'UpdateLens could not read the options table.' );
		}
		$wpdb->flush();

		return null === $value ? null : (string) $value;
	}
}
