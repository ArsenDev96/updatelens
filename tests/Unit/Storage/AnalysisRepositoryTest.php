<?php
/**
 * Tests for the History query of AnalysisRepository.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\Storage\AnalysisRepository;
use wpdb;

/**
 * The History query (find_page()) against a recording wpdb stand-in.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AnalysisRepositoryTest extends TestCase {

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
	 * The History query, its template collapsed to single spaces, and its arguments.
	 *
	 * @return array{string, array}
	 */
	private function history_query() {
		( new AnalysisRepository( $this->wpdb ) )->find_page( 20, 40 );

		$this->assertCount( 1, $this->wpdb->prepared );
		$this->assertSame( array( 'prepared:0' ), $this->wpdb->queries, 'The prepared statement is what runs.' );

		list( $template, $args ) = $this->wpdb->prepared[0];

		return array( trim( preg_replace( '/\s+/', ' ', $template ) ), $args );
	}

	/**
	 * The SELECT list is written out: metadata plus, per HISTORY_DIFFS column
	 * in order, a stored flag and a has-changes flag with a LIKE placeholder.
	 */
	public function test_history_query_selects_metadata_and_flags() {
		list( $template ) = $this->history_query();

		$flags = array();
		foreach ( array_keys( AnalysisRepository::HISTORY_DIFFS ) as $column ) {
			$flags[] = "{$column} IS NOT NULL AS has_{$column}";
			$flags[] = "{$column} NOT LIKE %s AS has_changes_{$column}";
		}

		$this->assertSame(
			'SELECT ' . AnalysisRepository::REPORT_METADATA_COLUMNS . ', ' . implode( ', ', $flags ) . ' FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
			$template
		);
	}

	/**
	 * Only values are placeholders: the codecs' escaped empty prefixes, the table and the page.
	 */
	public function test_history_query_arguments() {
		list( $template, $args ) = $this->history_query();

		$expected = array();
		foreach ( AnalysisRepository::HISTORY_DIFFS as $empty_prefix ) {
			$expected[] = addcslashes( $empty_prefix, '_%\\' ) . '%';
		}
		$expected[] = 'wp_updatelens_analyses';
		$expected[] = 20;
		$expected[] = 40;

		$this->assertSame( $expected, $args );
		$this->assertSame( count( $args ), preg_match_all( '/%[sdi]/', $template ) );
		$this->assertStringNotContainsString( "'", $template, 'No values are written into the SQL.' );
	}

	/**
	 * History never reads diff JSON, snapshots, reasons, user IDs or stored messages.
	 */
	public function test_history_query_reads_no_internals() {
		list( $template ) = $this->history_query();

		$selected = preg_replace( '/ FROM %i .*\z/', '', substr( $template, strlen( 'SELECT ' ) ) );
		foreach ( explode( ', ', $selected ) as $expression ) {
			$this->assertMatchesRegularExpression( '/\A([a-z_]+|[a-z_]+_diff (IS NOT NULL|NOT LIKE %s) AS has_(changes_)?[a-z_]+_diff)\z/', $expression );
		}
		foreach ( array( '_snapshot', 'fingerprint', 'user_id', 'error_message', 'active_plugin', '_reason' ) as $internal ) {
			$this->assertStringNotContainsString( $internal, $template );
		}
	}

	/**
	 * A failed query throws instead of returning an empty page.
	 */
	public function test_history_query_failure_throws() {
		$this->wpdb->last_error = 'Table is missing';

		$this->expectException( RuntimeException::class );
		( new AnalysisRepository( $this->wpdb ) )->find_page( 20, 0 );
	}

	/**
	 * Rows are returned as read.
	 */
	public function test_history_rows() {
		$this->wpdb->results = array( array( 'id' => '7' ) );

		$this->assertSame( array( array( 'id' => '7' ) ), ( new AnalysisRepository( $this->wpdb ) )->find_page( 20, 0 ) );
	}

	/**
	 * The menu count reads only the primary key: MAX(id) and a COUNT above a watermark.
	 */
	public function test_unread_queries_use_the_primary_key_only() {
		$this->wpdb->vars = array( '42', '3' );
		$repository       = new AnalysisRepository( $this->wpdb );

		$this->assertSame( 42, $repository->max_id() );
		$this->assertSame( 3, $repository->count_after( 40 ) );
		$this->assertSame( 0, $repository->count_after( 7 ), 'NULL from the database is 0.' );
		$repository->count_after( -5 );

		$this->assertSame(
			array(
				array( 'SELECT MAX(id) FROM %i', array( 'wp_updatelens_analyses' ) ),
				array( 'SELECT COUNT(*) FROM %i WHERE id > %d', array( 'wp_updatelens_analyses', 40 ) ),
				array( 'SELECT COUNT(*) FROM %i WHERE id > %d', array( 'wp_updatelens_analyses', 7 ) ),
				array( 'SELECT COUNT(*) FROM %i WHERE id > %d', array( 'wp_updatelens_analyses', 0 ) ),
			),
			$this->wpdb->prepared
		);
	}

	/**
	 * A failed unread query throws like every other read.
	 */
	public function test_unread_query_failure_throws() {
		$this->wpdb->last_error = 'Table does not exist';

		$this->expectException( RuntimeException::class );
		( new AnalysisRepository( $this->wpdb ) )->count_after( 1 );
	}
}
