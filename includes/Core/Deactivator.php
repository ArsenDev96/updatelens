<?php
/**
 * Plugin deactivation handler.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin deactivation.
 */
final class Deactivator {

	/**
	 * Deactivation callback.
	 *
	 * Intentionally empty for now. Unschedule any future UpdateLens cron
	 * events here. Data removal belongs in uninstall.php, not deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
	}
}
