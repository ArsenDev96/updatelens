<?php
/**
 * Stand-in for wp_autoload_values_to_autoload() (WordPress 6.6+).
 *
 * Returns a filtered list (`auto` removed) so tests can tell the core helper's
 * result apart from UpdateLens's own defaults. Only loaded by tests that run in
 * a separate process.
 *
 * @package UpdateLens
 */

/**
 * Autoloaded `autoload` values.
 *
 * @return string[]
 */
function wp_autoload_values_to_autoload() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Stands in for the WordPress function.
	return array( 'yes', 'on', 'auto-on' );
}
