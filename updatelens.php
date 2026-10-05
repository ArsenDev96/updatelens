<?php
/**
 * Plugin Name:       UpdateLens
 * Description:       Understand what changes when your WordPress plugins update.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            UpdateLens
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       updatelens
 *
 * @package UpdateLens
 */

defined( 'ABSPATH' ) || exit;

define( 'UPDATELENS_VERSION', '0.1.0' );
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
				esc_html__( 'UpdateLens is missing its Composer autoloader. Run "composer install" in the plugin directory.', 'updatelens' )
			);
		}
	);
	return;
}

require_once UPDATELENS_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \UpdateLens\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \UpdateLens\Core\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \UpdateLens\Core\Plugin::class, 'boot' ) );
