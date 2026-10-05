<?php
/**
 * Plugin activation handler.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Core;

use UpdateLens\Storage\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation.
 */
final class Activator {

	/**
	 * Activation callback: create the database schema.
	 *
	 * @return void
	 */
	public static function activate() {
		Schema::install();
	}
}
