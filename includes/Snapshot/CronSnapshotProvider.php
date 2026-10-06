<?php
/**
 * Captures the current WP-Cron state.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

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
}
