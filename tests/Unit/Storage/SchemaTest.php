<?php
/**
 * Tests for the analyses table definition.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use UpdateLens\Storage\AnalysisRepository;
use UpdateLens\Storage\Schema;
use UpdateLens\Update\CronObservation;

/**
 * Table definition used by dbDelta().
 */
final class SchemaTest extends TestCase {

	/**
	 * Column names in the CREATE TABLE statement.
	 *
	 * @return string[]
	 */
	private function columns() {
		preg_match_all( '/^  ([a-z_]+) [a-z]/m', Schema::analyses_table_sql( 'wp_updatelens_analyses', '' ), $matches );

		return $matches[1];
	}

	/**
	 * Version 3 columns: provider-prefixed, without obsolete v1/v2 names.
	 */
	public function test_columns() {
		$this->assertSame( 3, Schema::VERSION );
		$this->assertSame(
			array(
				'id',
				'plugin_file',
				'plugin_name',
				'version_before',
				'version_after',
				'user_id',
				'status',
				'active_plugin',
				'settle_outcome',
				'started_at',
				'updated_at',
				'settle_deadline',
				'completed_at',
				'options_before_snapshot',
				'options_immediate_snapshot',
				'options_during_update_diff',
				'options_post_update_diff',
				'options_final_diff',
				'cron_before_snapshot',
				'cron_immediate_snapshot',
				'cron_during_update_diff',
				'cron_post_update_diff',
				'cron_final_diff',
				'cron_during_update_reason',
				'cron_post_update_reason',
				'cron_final_reason',
				'error_code',
				'error_message',
			),
			$this->columns()
		);
	}

	/**
	 * Columns read for open analyses exist and exclude snapshot/diff data.
	 */
	public function test_open_columns_are_metadata_only() {
		$open = array_map( 'trim', explode( ',', AnalysisRepository::OPEN_COLUMNS ) );

		$this->assertSame( array(), array_diff( $open, $this->columns() ) );
		foreach ( $open as $column ) {
			$this->assertStringEndsNotWith( '_snapshot', $column );
			$this->assertStringEndsNotWith( '_diff', $column );
		}
	}

	/**
	 * Report queries read existing columns and never snapshots, user IDs or stored messages.
	 */
	public function test_report_columns_are_safe() {
		$report = array_map( 'trim', explode( ',', AnalysisRepository::REPORT_COLUMNS ) );

		$this->assertSame( array(), array_diff( $report, $this->columns() ) );
		foreach ( array( AnalysisRepository::REPORT_COLUMNS, AnalysisRepository::HISTORY_COLUMNS ) as $columns ) {
			foreach ( array( '_snapshot', 'cron_', 'fingerprint', 'user_id', 'error_message', 'active_plugin' ) as $internal ) {
				$this->assertStringNotContainsString( $internal, $columns );
			}
		}
	}

	/**
	 * History rows check diffs for NULL only; they never select diff JSON.
	 */
	public function test_history_columns_do_not_read_diffs() {
		$history = array_map( 'trim', explode( ',', AnalysisRepository::HISTORY_COLUMNS ) );

		foreach ( $history as $column ) {
			if ( '_diff' === substr( strtok( $column, ' ' ), -5 ) ) {
				$this->assertMatchesRegularExpression( '/^[a-z_]+_diff IS NOT NULL AS has_[a-z_]+_diff$/', $column );
			} else {
				$this->assertContains( $column, $this->columns() );
			}
		}
	}

	/**
	 * The Cron columns the lifecycle writes exist in the table.
	 */
	public function test_cron_observation_columns_exist() {
		$columns = array( CronObservation::BEFORE_SNAPSHOT, CronObservation::IMMEDIATE_SNAPSHOT );
		foreach ( CronObservation::PHASES as $phase_columns ) {
			$columns = array_merge( $columns, $phase_columns );
		}

		$this->assertSame( array(), array_diff( $columns, $this->columns() ) );
	}

	/**
	 * Upgrading from version 2 renames the generic options columns.
	 */
	public function test_renames_from_version_2() {
		$v2 = array( 'id', 'status', 'settle_outcome', 'before_snapshot', 'immediate_snapshot', 'during_update_diff', 'post_update_diff', 'final_diff', 'error_code' );

		$this->assertSame(
			array(
				array( 'before_snapshot', 'options_before_snapshot', 'longtext DEFAULT NULL' ),
				array( 'immediate_snapshot', 'options_immediate_snapshot', 'longtext DEFAULT NULL' ),
				array( 'during_update_diff', 'options_during_update_diff', 'longtext DEFAULT NULL' ),
				array( 'post_update_diff', 'options_post_update_diff', 'longtext DEFAULT NULL' ),
				array( 'final_diff', 'options_final_diff', 'longtext DEFAULT NULL' ),
			),
			Schema::renames( $v2 )
		);
	}

	/**
	 * Upgrading from version 1 renames straight to the version 3 names.
	 */
	public function test_renames_from_version_1() {
		$v1 = array( 'id', 'status', 'settle_trigger', 'before_snapshot', 'immediate_diff', 'settled_diff', 'error_code' );

		$this->assertSame(
			array(
				array( 'before_snapshot', 'options_before_snapshot', 'longtext DEFAULT NULL' ),
				array( 'immediate_diff', 'options_during_update_diff', 'longtext DEFAULT NULL' ),
				array( 'settled_diff', 'options_final_diff', 'longtext DEFAULT NULL' ),
				array( 'settle_trigger', 'settle_outcome', 'varchar(20) DEFAULT NULL' ),
			),
			Schema::renames( $v1 )
		);
	}

	/**
	 * A current table (or a repeated upgrade) needs no renames.
	 */
	public function test_no_renames_for_current_table() {
		$this->assertSame( array(), Schema::renames( $this->columns() ) );
	}

	/**
	 * Every rename target is a current column with the definition the table uses.
	 */
	public function test_rename_definitions_match_table() {
		$sql = Schema::analyses_table_sql( 'wp_updatelens_analyses', '' );

		foreach ( Schema::RENAMES as $to => $rename ) {
			$this->assertStringContainsString( "  {$to} {$rename[0]},", $sql );
		}
	}

	/**
	 * Indexes the lifecycle queries rely on.
	 */
	public function test_indexes() {
		$sql = Schema::analyses_table_sql( 'wp_updatelens_analyses', '' );

		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql, 'dbDelta needs two spaces.' );
		$this->assertStringContainsString( 'UNIQUE KEY active_plugin (active_plugin(191))', $sql );
		$this->assertStringContainsString( 'KEY status (status)', $sql );
	}
}
