<?php
/**
 * Plugin Name:       UpdateLens
 * Description:       Observe what changes around WordPress plugin updates, including options, autoload data, WP-Cron events and Action Scheduler actions.
 * Version:           0.2.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Arsen Manukyan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       updatelens
 *
 * @package UpdateLens
 */

defined( 'ABSPATH' ) || exit;

define( 'UPDATELENS_VERSION', '0.2.0' );
define( 'UPDATELENS_FILE', __FILE__ );
define( 'UPDATELENS_DIR', plugin_dir_path( __FILE__ ) );
define( 'UPDATELENS_URL', plugin_dir_url( __FILE__ ) );

if ( ! is_readable( UPDATELENS_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'UpdateLens cannot run because some of its files are missing. Please reinstall the plugin.', 'updatelens' )
			);
		}
	);
	return;
}

require_once UPDATELENS_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \UpdateLens\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \UpdateLens\Core\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \UpdateLens\Core\Plugin::class, 'boot' ) );
