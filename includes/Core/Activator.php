<?php
/**
 * Plugin activation handler.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Core;

use UpdateLens\Admin\UnreadReports;
use UpdateLens\Baseline\MonitoringBaseline;
use UpdateLens\Storage\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation.
 */
final class Activator {

	/**
	 * Activation callback: create the database schema and, on a single site
	 * without one, the monitoring baseline.
	 *
	 * The baseline never fails activation (MonitoringBaseline::ensure() does
	 * not throw); if it cannot be created now, the UpdateLens screen retries.
	 * The same holds for the unread-tracking origin (retried on admin pages).
	 *
	 * @return void
	 */
	public static function activate() {
		Schema::install();

		if ( ! is_multisite() ) {
			MonitoringBaseline::create()->ensure();
			self::start_unread_tracking();
		}
	}

	/**
	 * Store the unread-tracking origin (0 on a fresh install). Never throws;
	 * database errors stay out of the activation output.
	 *
	 * @return void
	 */
	private static function start_unread_tracking() {
		global $wpdb;

		$show_errors = $wpdb->hide_errors();
		UnreadReports::create()->origin();
		$wpdb->show_errors( $show_errors );
	}
}
