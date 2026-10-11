<?php
/**
 * Stand-in for wp-admin/includes/upgrade.php in unit tests (ABSPATH is
 * tests/): dbDelta() is applied by the in-memory wpdb of
 * tests/fixtures/wpdb-schema.php.
 *
 * @package UpdateLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Stands in for the WordPress function.

/**
 * Apply a CREATE TABLE statement like dbDelta().
 *
 * @param string $queries CREATE TABLE statement.
 * @return array
 */
function dbDelta( $queries ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- WordPress's name.
	$GLOBALS['wpdb']->db_delta( $queries );

	return array();
}
