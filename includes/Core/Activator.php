<?php
/**
 * Plugin activation handler.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation.
 */
final class Activator {

	/**
	 * Activation callback.
	 *
	 * Intentionally empty for now. Future storage setup (custom tables via
	 * dbDelta(), schema version option) belongs here.
	 *
	 * @return void
	 */
	public static function activate() {
	}
}
