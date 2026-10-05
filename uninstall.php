<?php
/**
 * Uninstall handler.
 *
 * Runs when the plugin is deleted from the Plugins screen: drops the analyses
 * table and the schema version option.
 *
 * @package UpdateLens
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
	\UpdateLens\Storage\Schema::uninstall();
}
