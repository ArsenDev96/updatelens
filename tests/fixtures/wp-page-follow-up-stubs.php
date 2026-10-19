<?php
/**
 * WordPress functions the update page's follow-up data needs
 * (PluginUpdateTracker::on_print_footer_scripts(),
 * AdminAssets::add_update_page_follow_up()). Loaded only in tests that run in
 * a separate process. Script state and localized data live in
 * `$GLOBALS['updatelens_test_scripts']`.
 *
 * @package UpdateLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter, Squiz.Commenting.FunctionComment.Missing -- Stand-ins for WordPress core.

$GLOBALS['updatelens_test_scripts'] = array(
	'enqueued'  => array(),
	'done'      => array(),
	'localized' => array(),
);

function wp_script_is( $handle, $status = 'enqueued' ) {
	return in_array( $handle, $GLOBALS['updatelens_test_scripts'][ $status ], true );
}

function wp_localize_script( $handle, $object_name, $l10n ) {
	$GLOBALS['updatelens_test_scripts']['localized'][] = array( $handle, $object_name, $l10n );

	return true;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
