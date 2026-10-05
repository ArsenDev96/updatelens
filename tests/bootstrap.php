<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * Unit tests cover pure logic and do not load WordPress. ABSPATH is defined so
 * plugin files pass their direct-access guard.
 *
 * @package UpdateLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Stands in for the WordPress constant.
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
