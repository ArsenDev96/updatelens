<?php
/**
 * Plugin activation handler.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Core;

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
	 *
	 * @return void
	 */
	public static function activate() {
		Schema::install();

		if ( ! is_multisite() ) {
			MonitoringBaseline::create()->ensure();
		}
	}
}
