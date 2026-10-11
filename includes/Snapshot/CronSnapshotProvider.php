<?php
/**
 * Captures the current WP-Cron state.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the `cron` option and hands it to CronSnapshotBuilder.
 *
 * Read-only: it never schedules, unschedules or saves anything. It reads
 * with get_option( 'cron' ), the same value core's cron functions work on
 * (including the object cache and `pre_option_cron` backends), and
 * deliberately not with _get_cron_array(): that private core function
 * rewrites the option when the `version` marker is missing.
 *
 * Cron events are a separate source from the `wp_options` snapshot, which
 * excludes the `cron` option.
 */
final class CronSnapshotProvider {

	/**
	 * Snapshot builder.
	 *
	 * @var CronSnapshotBuilder
	 */
	private $builder;

	/**
	 * Constructor.
	 *
	 * @param CronSnapshotBuilder $builder Snapshot builder.
	 */
	public function __construct( CronSnapshotBuilder $builder ) {
		$this->builder = $builder;
	}

	/**
	 * Provider wired to the running WordPress site.
	 *
	 * @return self
	 */
	public static function create() {
		return new self( new CronSnapshotBuilder( CronArgsHasher::from_wordpress() ) );
	}

	/**
	 * Snapshot the current WP-Cron events.
	 *
	 * @return CronSnapshot
	 * @throws MalformedCronStateException If the cron option holds malformed data.
	 */
	public function capture() {
		return $this->builder->build( get_option( 'cron' ) );
	}

	/**
	 * Stored size of the `cron` option in bytes, measured in SQL without
	 * reading the value (an estimate for ObservationBudget; 0 without a row).
	 *
	 * @return int
	 * @throws RuntimeException If it cannot be measured: the query fails, or a
	 *                          `pre_option_cron` backend supplies the events
	 *                          from elsewhere.
	 */
	public function stored_bytes() {
		global $wpdb;

		if ( has_filter( 'pre_option_cron' ) ) {
			throw new RuntimeException( 'UpdateLens cannot measure WP-Cron events kept outside the options table.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only; must reflect the stored value.
		$bytes = $wpdb->get_var( $wpdb->prepare( 'SELECT LENGTH(option_value) FROM %i WHERE option_name = %s', $wpdb->options, 'cron' ) );
		$error = (string) $wpdb->last_error;
		$wpdb->flush();
		if ( '' !== $error ) {
			throw new RuntimeException( 'UpdateLens could not measure the WP-Cron events.' );
		}

		return (int) $bytes;
	}
}
