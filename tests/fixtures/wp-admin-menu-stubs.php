<?php
/**
 * Minimal WordPress stand-ins for registering the admin menu and its count of
 * new reports. Loaded only in tests that run in a separate process, so the
 * global functions never leak into other tests.
 *
 * @package UpdateLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, Generic.CodeAnalysis.UnusedFunctionParameter, Squiz.Commenting, Generic.Commenting.DocComment -- Test stand-ins for WordPress core.

require __DIR__ . '/wpdb-recorder.php';

$GLOBALS['updatelens_test'] = array(
	'menu_pages' => array(),
	'actions'    => array(),
	'caps'       => array( 'manage_options' ),
	'multisite'  => false,
	'user_id'    => 1,
	'options'    => array(),
	'usermeta'   => array(),
);
$GLOBALS['plugin_page']     = null;

function __( $text, $domain = 'default' ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( $number, $decimals );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function absint( $value ) {
	return abs( (int) $value );
}

function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
	$GLOBALS['updatelens_test']['menu_pages'][] = array(
		'menu_title' => $menu_title,
		'capability' => $capability,
		'menu_slug'  => $menu_slug,
		'icon_url'   => $icon_url,
		'position'   => $position,
	);
	return 'toplevel_page_' . $menu_slug;
}

function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['updatelens_test']['actions'][] = $hook_name;
	return true;
}

function is_multisite() {
	return $GLOBALS['updatelens_test']['multisite'];
}

function current_user_can( $capability ) {
	return in_array( $capability, $GLOBALS['updatelens_test']['caps'], true );
}

function get_current_user_id() {
	return $GLOBALS['updatelens_test']['user_id'];
}

function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['updatelens_test']['options'] ) ? $GLOBALS['updatelens_test']['options'][ $name ] : $default_value;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['updatelens_test']['options'] ) ) {
		return false;
	}
	$GLOBALS['updatelens_test']['options'][ $name ] = $value;

	return true;
}

function get_user_meta( $user_id, $key = '', $single = false ) {
	return $GLOBALS['updatelens_test']['usermeta'][ $user_id ][ $key ] ?? '';
}

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['updatelens_test']['usermeta'][ $user_id ][ $key ] = $value;

	return true;
}
