<?php
/**
 * A `wpdb` stand-in that records prepared statements and the queries run.
 * Loaded only in tests that run in a separate process, so the global class
 * never leaks into other tests.
 *
 * @package UpdateLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, Generic.CodeAnalysis.UnusedFunctionParameter, Squiz.Commenting, Generic.Commenting.DocComment, PEAR.NamingConventions.ValidClassName -- Test stand-in for WordPress core.

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

class wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';

	/**
	 * Every prepare() call as [ query template, arguments ].
	 *
	 * @var array<int, array{string, array}>
	 */
	public $prepared = array();

	/**
	 * SQL passed to query(), get_results() and get_col(): prepare() returns `prepared:<index>`.
	 *
	 * @var string[]
	 */
	public $queries = array();

	/**
	 * Return values of query(), in order (default 1).
	 *
	 * @var array
	 */
	public $query_results = array();

	public $results = array();
	public $col     = array();

	/**
	 * Return values of get_var(), in order (default null).
	 *
	 * @var array
	 */
	public $vars = array();

	/**
	 * Whether errors are shown; hide_errors()/show_errors() toggle it.
	 *
	 * @var bool
	 */
	public $show_errors = true;

	public function prepare( $query, ...$args ) {
		$this->prepared[] = array( $query, $args );

		return 'prepared:' . ( count( $this->prepared ) - 1 );
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function query( $sql ) {
		$this->queries[] = $sql;

		return $this->query_results ? array_shift( $this->query_results ) : 1;
	}

	public function get_results( $sql, $output = null ) {
		$this->queries[] = $sql;

		return $this->results;
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;

		return $this->vars ? array_shift( $this->vars ) : null;
	}

	public function hide_errors() {
		$shown             = $this->show_errors;
		$this->show_errors = false;

		return $shown;
	}

	public function show_errors( $show = true ) {
		$shown             = $this->show_errors;
		$this->show_errors = $show;

		return $shown;
	}

	public function get_col( $sql ) {
		$this->queries[] = $sql;

		return $this->col;
	}
}

$GLOBALS['wpdb'] = new wpdb();
