<?php
/**
 * An in-memory `wpdb` for schema installation tests: tables with columns and
 * keys, raw `wp_options` rows, the options API, and injected failures
 * (failing statements, ALTER refused for some columns, unreadable schema).
 * dbDelta() comes from tests/wp-admin/includes/upgrade.php (ABSPATH is
 * tests/). Loaded only in tests that run in a separate process, so the
 * global class and functions never leak into other tests.
 *
 * @package UpdateLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, Generic.CodeAnalysis.UnusedFunctionParameter, Squiz.Commenting, Generic.Commenting.DocComment, PEAR.NamingConventions.ValidClassName, Generic.Files.OneObjectStructurePerFile, Generic.Classes.DuplicateClassName, WordPress.Security.EscapeOutput.ExceptionNotEscaped, Universal.Files.SeparateFunctionsFromOO -- Test stand-in for WordPress core.

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

class wpdb {
	public $prefix     = 'wp_';
	public $options    = 'wp_options';
	public $last_error = '';

	/**
	 * Whether errors are printed; hide_errors()/show_errors() toggle it.
	 *
	 * @var bool
	 */
	public $show_errors = true;

	/**
	 * Tables: name => [ 'columns' => string[], 'keys' => [ name => [ 'unique' => bool, 'columns' => string[] ] ] ].
	 *
	 * @var array
	 */
	public $tables = array();

	/**
	 * Raw `wp_options` rows written with SQL: name => value.
	 *
	 * @var array<string, string>
	 */
	public $option_rows = array();

	/**
	 * Failing statements: template prefix => error message (until removed).
	 *
	 * @var array<string, string>
	 */
	public $fail = array();

	/**
	 * Columns dbDelta() cannot add (an ALTER TABLE refused), or true for all.
	 *
	 * @var string[]|true
	 */
	public $deny_add = array();

	/**
	 * Templates of every statement run, in order (dbDelta() logs `dbDelta`).
	 *
	 * @var string[]
	 */
	public $log = array();

	/**
	 * Errors raised while errors were shown (would have been printed).
	 *
	 * @var string[]
	 */
	public $printed = array();

	/**
	 * Run once after the next read of a raw option row (a concurrent request).
	 *
	 * @var callable|null
	 */
	public $after_option_read = null;

	private $prepared = array();

	public function prepare( $query, ...$args ) {
		$this->prepared[] = array( $query, $args );

		return 'prepared:' . ( count( $this->prepared ) - 1 );
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate() {
		return '';
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

	/**
	 * Count of logged statements starting with a prefix.
	 */
	public function ran( $prefix ) {
		return count(
			array_filter(
				$this->log,
				function ( $template ) use ( $prefix ) {
					return 0 === strpos( $template, $prefix );
				}
			)
		);
	}

	private function start( $sql ) {
		$this->last_error        = '';
		list( $template, $args ) = $this->prepared[ (int) substr( $sql, strlen( 'prepared:' ) ) ];
		$this->log[]             = $template;
		foreach ( $this->fail as $prefix => $message ) {
			if ( 0 === strpos( $template, $prefix ) ) {
				$this->error( $message );
				return array( null, $args );
			}
		}

		return array( $template, $args );
	}

	private function error( $message ) {
		$this->last_error = $message;
		if ( $this->show_errors ) {
			$this->printed[] = $message;
		}
	}

	public function query( $sql ) {
		list( $template, $args ) = $this->start( $sql );
		if ( null === $template ) {
			return false;
		}

		if ( 0 === strpos( $template, 'INSERT IGNORE INTO %i (option_name' ) ) {
			if ( isset( $this->option_rows[ $args[1] ] ) ) {
				return 0;
			}
			$this->option_rows[ $args[1] ] = $args[2];
			return 1;
		}
		if ( 0 === strpos( $template, 'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST' ) ) {
			if ( ! isset( $this->option_rows[ $args[2] ] ) || $this->option_rows[ $args[2] ] !== $args[3] || $args[1] === $args[3] ) {
				return 0;
			}
			$this->option_rows[ $args[2] ] = $args[1];
			return 1;
		}
		if ( 0 === strpos( $template, 'DELETE FROM %i WHERE option_name = %s' ) ) {
			$found = isset( $this->option_rows[ $args[1] ] );
			unset( $this->option_rows[ $args[1] ] );
			return $found ? 1 : 0;
		}
		if ( 0 === strpos( $template, 'ALTER TABLE %i CHANGE COLUMN' ) ) {
			$columns = &$this->tables[ $args[0] ]['columns'];
			$at      = array_search( $args[1], $columns, true );
			if ( false === $at ) {
				$this->error( "Unknown column '{$args[1]}'" );
				return false;
			}
			$columns[ $at ] = $args[2];
			return 0;
		}
		if ( 0 === strpos( $template, 'DROP TABLE IF EXISTS' ) ) {
			unset( $this->tables[ $args[0] ] );
			return 0;
		}
		if ( 0 === strpos( $template, 'UPDATE %i SET ' ) ) {
			// One-time data updates of the analyses table.
			if ( ! isset( $this->tables[ $args[0] ] ) ) {
				$this->error( "Table '{$args[0]}' doesn't exist" );
				return false;
			}
			return 0;
		}

		throw new LogicException( "Unexpected statement: {$template}" );
	}

	public function get_var( $sql ) {
		list( $template, $args ) = $this->start( $sql );
		if ( null === $template ) {
			return null;
		}

		if ( 'SHOW TABLES LIKE %s' === $template ) {
			$table = stripslashes( $args[0] );
			return isset( $this->tables[ $table ] ) ? $table : null;
		}
		if ( 0 === strpos( $template, 'SELECT option_value FROM %i WHERE option_name = %s' ) ) {
			// Raw rows, else options written through the options API.
			if ( isset( $this->option_rows[ $args[1] ] ) ) {
				$value = $this->option_rows[ $args[1] ];
			} elseif ( array_key_exists( $args[1], $GLOBALS['updatelens_options'] ) ) {
				$value = (string) $GLOBALS['updatelens_options'][ $args[1] ];
			} else {
				$value = null;
			}
			if ( $this->after_option_read ) {
				$concurrent              = $this->after_option_read;
				$this->after_option_read = null;
				$concurrent();
				$this->last_error = '';
			}
			return $value;
		}

		throw new LogicException( "Unexpected statement: {$template}" );
	}

	public function get_col( $sql ) {
		list( $template, $args ) = $this->start( $sql );
		if ( null === $template ) {
			return array();
		}
		if ( 'DESCRIBE %i' !== $template ) {
			throw new LogicException( "Unexpected statement: {$template}" );
		}
		if ( ! isset( $this->tables[ $args[0] ] ) ) {
			$this->error( "Table '{$args[0]}' doesn't exist" );
			return array();
		}

		return $this->tables[ $args[0] ]['columns'];
	}

	public function get_results( $sql, $output = null ) {
		list( $template, $args ) = $this->start( $sql );
		if ( null === $template ) {
			return null;
		}
		if ( 'SHOW INDEX FROM %i' !== $template ) {
			throw new LogicException( "Unexpected statement: {$template}" );
		}
		if ( ! isset( $this->tables[ $args[0] ] ) ) {
			$this->error( "Table '{$args[0]}' doesn't exist" );
			return null;
		}
		$rows = array();
		foreach ( $this->tables[ $args[0] ]['keys'] as $name => $key ) {
			foreach ( $key['columns'] as $position => $column ) {
				$rows[] = array(
					'Table'        => $args[0],
					'Non_unique'   => $key['unique'] ? '0' : '1',
					'Key_name'     => $name,
					'Seq_in_index' => (string) ( $position + 1 ),
					'Column_name'  => $column,
				);
			}
		}

		return $rows;
	}

	/**
	 * What dbDelta() does with one CREATE TABLE statement: create the table, or
	 * add missing columns and keys. Like dbDelta(), it reports nothing; a refused
	 * ALTER (deny_add) only leaves the column missing and sets last_error.
	 */
	public function db_delta( $sql ) {
		$this->log[]      = 'dbDelta';
		$this->last_error = '';
		preg_match( '/CREATE TABLE (\S+) \(/', $sql, $table );
		preg_match_all( '/^  ([a-z0-9_]+) [a-z]/m', $sql, $columns );
		$keys = array(
			'PRIMARY'       => array(
				'unique'  => true,
				'columns' => array( 'id' ),
			),
			'active_plugin' => array(
				'unique'  => true,
				'columns' => array( 'active_plugin' ),
			),
			'status'        => array(
				'unique'  => false,
				'columns' => array( 'status' ),
			),
			'plugin_file'   => array(
				'unique'  => false,
				'columns' => array( 'plugin_file' ),
			),
		);
		$name = $table[1];
		if ( isset( $this->fail['CREATE TABLE'] ) ) {
			$this->error( $this->fail['CREATE TABLE'] );
			return;
		}
		if ( ! isset( $this->tables[ $name ] ) ) {
			$this->tables[ $name ] = array(
				'columns' => $columns[1],
				'keys'    => $keys,
			);
			return;
		}
		foreach ( $columns[1] as $column ) {
			if ( in_array( $column, $this->tables[ $name ]['columns'], true ) ) {
				continue;
			}
			if ( true === $this->deny_add || in_array( $column, $this->deny_add, true ) ) {
				$this->error( 'ALTER command denied to user' );
				continue;
			}
			$this->tables[ $name ]['columns'][] = $column;
		}
		$this->tables[ $name ]['keys'] += $keys;
	}

	/**
	 * Create a table with the given columns and every key.
	 */
	public function create( $name, array $columns ) {
		$this->tables[ $name ] = array(
			'columns' => $columns,
			'keys'    => array(
				'PRIMARY'       => array(
					'unique'  => true,
					'columns' => array( 'id' ),
				),
				'active_plugin' => array(
					'unique'  => true,
					'columns' => array( 'active_plugin' ),
				),
			),
		);
	}
}

$GLOBALS['wpdb']                          = new wpdb();
$GLOBALS['updatelens_options']            = array();
$GLOBALS['updatelens_option_write_fails'] = false;

function get_option( $name, $default_value = false ) {
	// This request's older copy of an option (another request changed it since).
	if ( isset( $GLOBALS['updatelens_stale_options'] ) && array_key_exists( $name, $GLOBALS['updatelens_stale_options'] ) ) {
		return $GLOBALS['updatelens_stale_options'][ $name ];
	}
	return array_key_exists( $name, $GLOBALS['updatelens_options'] ) ? $GLOBALS['updatelens_options'][ $name ] : $default_value;
}

function update_option( $name, $value, $autoload = null ) {
	if ( $GLOBALS['updatelens_option_write_fails'] ) {
		return false;
	}
	if ( get_option( $name ) === $value ) {
		return false;
	}
	$GLOBALS['updatelens_options'][ $name ] = $value;

	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['updatelens_options'][ $name ] );

	return true;
}
