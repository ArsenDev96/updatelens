<?php
/**
 * Uninstall handler.
 *
 * Runs when the plugin is deleted from the Plugins screen. UpdateLens does not
 * persist any data yet; remove its options and tables here once storage exists.
 *
 * @package UpdateLens
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
