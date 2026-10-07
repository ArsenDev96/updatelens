<?php
/**
 * Tests for the column renames of schema upgrades.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use UpdateLens\Storage\Schema;
use wpdb;

/**
 * Schema::rename_columns() against a recording wpdb stand-in.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SchemaUpgradeTest extends TestCase {

	/**
	 * Database stand-in.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Load the stand-in.
	 *
	 * @before
	 */
	public function load_wpdb() {
		require dirname( __DIR__, 2 ) . '/fixtures/wpdb-recorder.php';

		$this->wpdb = $GLOBALS['wpdb'];
	}

	/**
	 * Prepared ALTER statements that ran, as [ template, arguments ].
	 *
	 * @return array<int, array{string, array}>
	 */
	private function alters() {
		$alters = array();
		foreach ( $this->wpdb->queries as $sql ) {
			$prepared = $this->wpdb->prepared[ (int) substr( $sql, strlen( 'prepared:' ) ) ];
			if ( 0 === strpos( $prepared[0], 'ALTER TABLE' ) ) {
				$alters[] = $prepared;
			}
		}

		return $alters;
	}

	/**
	 * Version 2 options columns are renamed one prepared statement each.
	 */
	public function test_renames_version_2_columns() {
		$this->wpdb->col = array( 'id', 'status', 'settle_outcome', 'before_snapshot', 'immediate_snapshot', 'during_update_diff', 'post_update_diff', 'final_diff', 'error_code' );

		$this->assertTrue( Schema::rename_columns( 'wp_updatelens_analyses' ) );

		$this->assertSame( array( 'DESCRIBE %i', array( 'wp_updatelens_analyses' ) ), $this->wpdb->prepared[0] );
		$statement = 'ALTER TABLE %i CHANGE COLUMN %i %i longtext DEFAULT NULL';
		$this->assertSame(
			array(
				array( $statement, array( 'wp_updatelens_analyses', 'before_snapshot', 'options_before_snapshot' ) ),
				array( $statement, array( 'wp_updatelens_analyses', 'immediate_snapshot', 'options_immediate_snapshot' ) ),
				array( $statement, array( 'wp_updatelens_analyses', 'during_update_diff', 'options_during_update_diff' ) ),
				array( $statement, array( 'wp_updatelens_analyses', 'post_update_diff', 'options_post_update_diff' ) ),
				array( $statement, array( 'wp_updatelens_analyses', 'final_diff', 'options_final_diff' ) ),
			),
			$this->alters()
		);
	}

	/**
	 * Version 1 columns, including the varchar settle column, go straight to the current names.
	 */
	public function test_renames_version_1_columns() {
		$this->wpdb->col = array( 'id', 'status', 'settle_trigger', 'before_snapshot', 'immediate_diff', 'settled_diff', 'error_code' );

		$this->assertTrue( Schema::rename_columns( 'wp_updatelens_analyses' ) );

		$longtext = 'ALTER TABLE %i CHANGE COLUMN %i %i longtext DEFAULT NULL';
		$this->assertSame(
			array(
				array( $longtext, array( 'wp_updatelens_analyses', 'before_snapshot', 'options_before_snapshot' ) ),
				array( $longtext, array( 'wp_updatelens_analyses', 'immediate_diff', 'options_during_update_diff' ) ),
				array( $longtext, array( 'wp_updatelens_analyses', 'settled_diff', 'options_final_diff' ) ),
				array( 'ALTER TABLE %i CHANGE COLUMN %i %i varchar(20) DEFAULT NULL', array( 'wp_updatelens_analyses', 'settle_trigger', 'settle_outcome' ) ),
			),
			$this->alters()
		);
	}

	/**
	 * Every RENAMES definition has a statement that keeps it.
	 */
	public function test_every_rename_definition_is_supported() {
		foreach ( Schema::RENAMES as $to => $rename ) {
			$this->wpdb->prepared = array();
			$this->wpdb->queries  = array();
			$this->wpdb->col      = array( 'id', $rename[1] );

			$this->assertTrue( Schema::rename_columns( 'wp_updatelens_analyses' ), $to );
			$alters = $this->alters();
			$this->assertCount( 1, $alters, $to );
			$this->assertSame( "ALTER TABLE %i CHANGE COLUMN %i %i {$rename[0]}", $alters[0][0], $to );
			$this->assertSame( array( 'wp_updatelens_analyses', $rename[1], $to ), $alters[0][1], $to );
		}
	}

	/**
	 * A current table needs no ALTER.
	 */
	public function test_current_table_is_not_altered() {
		$this->wpdb->col = array_merge( array( 'id', 'status' ), array_keys( Schema::RENAMES ) );

		$this->assertTrue( Schema::rename_columns( 'wp_updatelens_analyses' ) );
		$this->assertSame( array(), $this->alters() );
	}

	/**
	 * A failed rename stops the upgrade (it is retried; renamed columns are then skipped).
	 */
	public function test_failed_rename_stops() {
		$this->wpdb->col           = array( 'id', 'before_snapshot', 'immediate_snapshot', 'final_diff' );
		$this->wpdb->query_results = array( 1, false );

		$this->assertFalse( Schema::rename_columns( 'wp_updatelens_analyses' ) );
		$this->assertCount( 2, $this->alters() );

		$this->assertSame(
			array( array( 'immediate_snapshot', 'options_immediate_snapshot', 'longtext DEFAULT NULL' ), array( 'final_diff', 'options_final_diff', 'longtext DEFAULT NULL' ) ),
			Schema::renames( array( 'id', 'options_before_snapshot', 'immediate_snapshot', 'final_diff' ) ),
			'The retry renames only what is left.'
		);
	}

	/**
	 * Unreadable columns: no ALTER.
	 */
	public function test_describe_failure() {
		$this->wpdb->last_error = 'Table is missing';

		$this->assertFalse( Schema::rename_columns( 'wp_updatelens_analyses' ) );
		$this->assertSame( array(), $this->alters() );
	}
}
