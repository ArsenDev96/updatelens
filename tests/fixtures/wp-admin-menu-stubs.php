<?php
/**
 * Minimal WordPress stand-ins for registering the admin menu. Loaded only in
 * tests that run in a separate process, so the global functions never leak
 * into other tests.
 *
 * @package UpdateLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, Generic.CodeAnalysis.UnusedFunctionParameter, Squiz.Commenting, Generic.Commenting.DocComment -- Test stand-ins for WordPress core.

$GLOBALS['updatelens_test'] = array(
	'menu_pages' => array(),
	'actions'    => array(),
);

function __( $text, $domain = 'default' ) {
	return $text;
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
	return false;
}
