<?php
/**
 * WordPress functions PluginUpdateTracker needs at shutdown, for an eligible
 * wp-admin page request. Loaded only in tests that run in a separate process.
 *
 * @package UpdateLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter, Squiz.Commenting.FunctionComment.Missing -- Stand-ins for WordPress core.

function get_option( $name, $default_value = false ) {
	return 'active_plugins' === $name ? array() : $default_value;
}

function is_admin() {
	return true;
}

function wp_doing_ajax() {
	return false;
}

function wp_doing_cron() {
	return false;
}

function is_user_logged_in() {
	return true;
}

function current_user_can( $capability ) {
	return true;
}

function did_action( $hook ) {
	return 1;
}
