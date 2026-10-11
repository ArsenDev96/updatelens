<?php
/**
 * A `wpdb` stand-in with an in-memory `wp_options` table for
 * OptionsSnapshotProvider tests: the batch, large-value and count queries,
 * the statements run, flushes, and injected failures. Loaded only in tests
 * that run in a separate process, so the global class never leaks.
 *
 * @package UpdateLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, Generic.CodeAnalysis.UnusedFunctionParameter, Squiz.Commenting, Generic.Commenting.DocComment, PEAR.NamingConventions.ValidClassName, Generic.Classes.DuplicateClassName, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test stand-in for WordPress core.

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

class wpdb {
	public $options    = 'wp_options';
	public $last_error = '';

	/**
	 * Rows: name => [ value, autoload ].
	 *
	 * @var array<string, array{string, string}>
	 */
	public $rows = array();

	/**
	 * Templates of the statements run, in order.
	 *
	 * @var string[]
	 */
	public $log = array();

	/**
	 * Number of flush() calls.
	 *
	 * @var int
	 */
	public $flushes = 0;

	/**
	 * Fail the statement with this 0-based index in $log.
	 *
	 * @var int|null
	 */
	public $fail_at = null;

	/**
	 * Run before the next large-value read (e.g. delete the option meanwhile).
	 *
	 * @var callable|null
	 */
	public $before_value_read = null;

	private $prepared = array();

	public function prepare( $query, ...$args ) {
		$this->prepared[] = array( $query, $args );

		return 'prepared:' . ( count( $this->prepared ) - 1 );
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function flush() {
		++$this->flushes;
		$this->last_error = '';
	}

	private function start( $sql ) {
		$this->last_error        = '';
		list( $template, $args ) = $this->prepared[ (int) substr( $sql, strlen( 'prepared:' ) ) ];
		$this->log[]             = $template;
		if ( count( $this->log ) - 1 === $this->fail_at ) {
			$this->last_error = 'Lost connection to server';
			return array( null, $args );
		}

		return array( $template, $args );
	}

	private function sorted() {
		$names = array_keys( $this->rows );
		usort( $names, 'strcmp' );

		return $names;
	}

	public function get_results( $sql, $output = null ) {
		list( $template, $args ) = $this->start( $sql );
		if ( null === $template ) {
			return null;
		}
		if ( false === strpos( $template, 'IF(LENGTH(option_value) <= %d, option_value, NULL)' ) ) {
			throw new LogicException( "Unexpected statement: {$template}" );
		}
		$cap   = $args[0];
		$after = false !== strpos( $template, 'WHERE option_name > %s' ) ? (string) $args[2] : null;
		$limit = (int) end( $args );
		$rows  = array();
		foreach ( $this->sorted() as $name ) {
			$name = (string) $name;
			if ( null !== $after && strcmp( $name, $after ) <= 0 ) {
				continue;
			}
			list( $value, $autoload ) = $this->rows[ $name ];
			$rows[]                   = array(
				'option_name'  => $name,
				'autoload'     => $autoload,
				'value_bytes'  => (string) strlen( $value ),
				'option_value' => strlen( $value ) <= $cap ? $value : null,
			);
			if ( count( $rows ) === $limit ) {
				break;
			}
		}

		return $rows;
	}

	public function get_var( $sql ) {
		list( $template, $args ) = $this->start( $sql );
		if ( null === $template ) {
			return null;
		}
		if ( 0 === strpos( $template, 'SELECT option_value FROM %i WHERE option_name = %s' ) ) {
			if ( $this->before_value_read ) {
				$hook                    = $this->before_value_read;
				$this->before_value_read = null;
				$hook( $this );
			}
			return isset( $this->rows[ $args[1] ] ) ? $this->rows[ $args[1] ][0] : null;
		}
		if ( 0 === strpos( $template, 'SELECT COUNT(*) FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s' ) ) {
			$count = 0;
			foreach ( array_keys( $this->rows ) as $name ) {
				$name = (string) $name;
				if ( 0 !== strpos( $name, '_transient_' ) && 0 !== strpos( $name, '_site_transient_' ) ) {
					++$count;
				}
			}
			return (string) $count;
		}

		throw new LogicException( "Unexpected statement: {$template}" );
	}
}

$GLOBALS['wpdb'] = new wpdb();
