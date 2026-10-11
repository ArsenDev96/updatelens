<?php
/**
 * Stand-in for has_filter(): hooks listed in $GLOBALS['updatelens_test_filters'] have callbacks.
 *
 * @package UpdateLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting -- Test stand-in for WordPress core.

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook ) {
		return ! empty( $GLOBALS['updatelens_test_filters'][ $hook ] );
	}
}
