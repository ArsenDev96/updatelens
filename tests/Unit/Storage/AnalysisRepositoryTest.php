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
use UpdateLens\Storage\Schema;
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
	 * The SELECT list is written out: metadata, the site check result columns
	 * (for each item's site check comparison) plus, per HISTORY_DIFFS column
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
			'SELECT ' . AnalysisRepository::REPORT_METADATA_COLUMNS . ', ' . AnalysisRepository::SITE_CHECK_RESULT_COLUMNS . ', ' . implode( ', ', $flags ) . ' FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
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
	 * History never reads diff JSON, snapshots, signal reasons, user IDs or
	 * stored messages. Of the site check columns it reads only the result
	 * facts and their fixed reason codes (no fingerprints, users or URLs are
	 * stored there).
	 */
	public function test_history_query_reads_no_internals() {
		list( $template ) = $this->history_query();

		$selected = preg_replace( '/ FROM %i .*\z/', '', substr( $template, strlen( 'SELECT ' ) ) );
		$selected = str_replace( AnalysisRepository::SITE_CHECK_RESULT_COLUMNS . ', ', '', $selected );
		$template = str_replace( AnalysisRepository::SITE_CHECK_RESULT_COLUMNS, '', $template );
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
	 * Site check AFTER writes: one claim per analysis and one write per check,
	 * each a guarded UPDATE written out as a literal template.
	 */
	public function test_site_check_after_writes() {
		$repository                = new AnalysisRepository( $this->wpdb );
		$this->wpdb->query_results = array( 1, 0, 1, 1, 0 );

		$this->assertTrue( $repository->claim_site_check_after( 5, 7, 'completed', 'follow_up', 1760090000.25 ) );
		$this->assertFalse( $repository->claim_site_check_after( 5, 7, 'completed', 'follow_up', 1760090001.0 ), 'Already claimed.' );
		$this->assertTrue( $repository->record_site_check_after( 5, 7, array( 'site_check_home_after', 'site_check_home_after_reason' ), '{"schema":2}', null ) );
		$this->assertTrue( $repository->record_site_check_after( 5, 7, array( 'site_check_rest_after', 'site_check_rest_after_reason' ), '{"schema":2}', 'overlapping_update' ) );
		$this->assertFalse( $repository->record_site_check_after( 5, 7, array( 'site_check_rest_after', 'site_check_rest_after_reason' ), null, 'rate_limited' ) );

		$guard = 'WHERE id = %d AND user_id = %d AND site_check_after_started_at IS NOT NULL AND %i IS NULL AND %i IS NULL';
		$this->assertSame(
			array(
				array( 'UPDATE %i SET site_check_after_started_at = %s WHERE id = %d AND user_id = %d AND status = %s AND settle_outcome = %s AND site_check_after_started_at IS NULL', array( 'wp_updatelens_analyses', '1760090000.250000', 5, 7, 'completed', 'follow_up' ) ),
				array( 'UPDATE %i SET site_check_after_started_at = %s WHERE id = %d AND user_id = %d AND status = %s AND settle_outcome = %s AND site_check_after_started_at IS NULL', array( 'wp_updatelens_analyses', '1760090001.000000', 5, 7, 'completed', 'follow_up' ) ),
				array( "UPDATE %i SET %i = %s $guard", array( 'wp_updatelens_analyses', 'site_check_home_after', '{"schema":2}', 5, 7, 'site_check_home_after', 'site_check_home_after_reason' ) ),
				array( "UPDATE %i SET %i = %s, %i = %s $guard", array( 'wp_updatelens_analyses', 'site_check_rest_after', '{"schema":2}', 'site_check_rest_after_reason', 'overlapping_update', 5, 7, 'site_check_rest_after', 'site_check_rest_after_reason' ) ),
				array( "UPDATE %i SET %i = %s $guard", array( 'wp_updatelens_analyses', 'site_check_rest_after_reason', 'rate_limited', 5, 7, 'site_check_rest_after', 'site_check_rest_after_reason' ) ),
			),
			$this->wpdb->prepared
		);
	}

	/**
	 * Only the AFTER columns can be written, never both values NULL; a
	 * failed write throws; the site check read selects no snapshots or diffs.
	 */
	public function test_site_check_columns() {
		$repository = new AnalysisRepository( $this->wpdb );
		foreach ( array( array( array( 'status', 'site_check_home_after_reason' ), '{}', null ), array( array( 'site_check_home_after', 'site_check_rest_after_reason' ), '{}', null ), array( array( 'site_check_home_after', 'site_check_home_after_reason' ), null, null ) ) as $case ) {
			try {
				$repository->record_site_check_after( 5, 7, $case[0], $case[1], $case[2] );
				$this->fail( 'Accepted.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( array(), $this->wpdb->prepared );
			}
		}

		$this->wpdb->row = array( 'id' => '5' );
		$this->assertSame( array( 'id' => '5' ), $repository->find_site_checks( 5 ) );
		$this->assertSame( array( 'SELECT ' . AnalysisRepository::SITE_CHECK_COLUMNS . ' FROM %i WHERE id = %d', array( 'wp_updatelens_analyses', 5 ) ), $this->wpdb->prepared[0] );
		foreach ( array_map( 'trim', explode( ',', AnalysisRepository::SITE_CHECK_COLUMNS ) ) as $column ) {
			$this->assertDoesNotMatchRegularExpression( '/_(snapshot|diff)$|^options_|^cron_|^action_scheduler_/', $column );
		}

		$this->wpdb->query_results = array( false );
		$this->expectException( RuntimeException::class );
		$repository->claim_site_check_after( 5, 7, 'completed', 'follow_up', 1.0 );
	}

	/**
	 * Ending an analysis reads every column except the options snapshots and
	 * diffs; the list follows the schema. Reads release $wpdb's copy.
	 */
	public function test_signal_columns() {
		$payload = array( 'options_before_snapshot', 'options_immediate_snapshot', 'options_during_update_diff', 'options_post_update_diff', 'options_final_diff' );
		$this->assertSame( array_values( array_diff( Schema::columns(), $payload ) ), array_map( 'trim', explode( ',', AnalysisRepository::SIGNAL_COLUMNS ) ) );

		$this->wpdb->row = array( 'id' => '5' );
		$this->assertSame( array( 'id' => '5' ), ( new AnalysisRepository( $this->wpdb ) )->find_signals( 5 ) );
		$this->assertSame( array( 'SELECT ' . AnalysisRepository::SIGNAL_COLUMNS . ' FROM %i WHERE id = %d', array( 'wp_updatelens_analyses', 5 ) ), $this->wpdb->prepared[0] );
		$this->assertSame( 1, $this->wpdb->flushes );

		( new AnalysisRepository( $this->wpdb ) )->find( 5 );
		$this->assertSame( 2, $this->wpdb->flushes );
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
