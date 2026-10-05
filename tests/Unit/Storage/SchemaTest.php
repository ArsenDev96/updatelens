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
	 * Version 2 columns, without obsolete v1 names.
	 */
	public function test_columns() {
		$this->assertSame( 2, Schema::VERSION );
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
				'before_snapshot',
				'immediate_snapshot',
				'during_update_diff',
				'post_update_diff',
				'final_diff',
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
	 * Indexes the lifecycle queries rely on.
	 */
	public function test_indexes() {
		$sql = Schema::analyses_table_sql( 'wp_updatelens_analyses', '' );

		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql, 'dbDelta needs two spaces.' );
		$this->assertStringContainsString( 'UNIQUE KEY active_plugin (active_plugin(191))', $sql );
		$this->assertStringContainsString( 'KEY status (status)', $sql );
	}
}
