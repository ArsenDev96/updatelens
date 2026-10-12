<?php
/**
 * Transients API stand-ins that record calls. delete_transient() reports
 * success for every call, like persistent object caches whose delete never
 * says whether the key existed. Loaded only in tests that run in a separate
 * process.
 *
 * @package UpdateLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting, Generic.Commenting.DocComment -- Test stand-ins for WordPress core.

$GLOBALS['updatelens_test_transient_calls'] = array();

function get_transient( $transient ) {
	$GLOBALS['updatelens_test_transient_calls'][] = array( 'get', $transient );

	return false;
}

function set_transient( $transient, $value, $expiration = 0 ) {
	$GLOBALS['updatelens_test_transient_calls'][] = array( 'set', $transient, $value, $expiration );

	return true;
}

function delete_transient( $transient ) {
	$GLOBALS['updatelens_test_transient_calls'][] = array( 'delete', $transient );

	return true;
}
