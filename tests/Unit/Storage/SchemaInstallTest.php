<?php
/**
 * Tests for schema installation, verification and install retries.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use UpdateLens\Storage\Schema;
use wpdb;

/**
 * Schema::install(), maybe_upgrade(), repair() and recover() against an
 * in-memory database with injected failures.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SchemaInstallTest extends TestCase {

	const TABLE = 'wp_updatelens_analyses';

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
		require dirname( __DIR__, 2 ) . '/fixtures/wpdb-schema.php';

		$this->wpdb = $GLOBALS['wpdb'];
	}

	/**
	 * Columns of an earlier schema version.
	 *
	 * @param int $version 5 or 6.
	 * @return string[]
	 */
	private static function columns_of( $version ) {
		$after  = array( 'site_check_update_started_at', 'site_check_after_started_at', 'site_check_home_after', 'site_check_home_after_reason', 'site_check_rest_after', 'site_check_rest_after_reason' );
		$before = array( 'site_check_home_before', 'site_check_home_before_reason', 'site_check_rest_before', 'site_check_rest_before_reason' );
		$gone   = 6 === $version ? $after : array_merge( $after, $before );

		return array_values( array_diff( Schema::columns(), $gone ) );
	}

	/**
	 * A site at an earlier version.
	 *
	 * @param int $version Stored version.
	 * @return void
	 */
	private function at_version( $version ) {
		$this->wpdb->create( self::TABLE, self::columns_of( $version ) );
		$GLOBALS['updatelens_options'][ Schema::VERSION_OPTION ] = $version;
	}

	/**
	 * Stored version.
	 *
	 * @return mixed
	 */
	private function version() {
		return get_option( Schema::VERSION_OPTION );
	}

	/**
	 * The schema is installed with every column and key, the version is 7
	 * and no retry marker is left.
	 *
	 * @return void
	 */
	private function assert_installed() {
		$this->assertSame( 7, $this->version() );
		$this->assertSame( array(), array_diff( Schema::columns(), $this->wpdb->tables[ self::TABLE ]['columns'] ) );
		$this->assertTrue( Schema::verify( self::TABLE ) );
		$this->assertArrayNotHasKey( Schema::RETRY_OPTION, $this->wpdb->option_rows );
	}

	/**
	 * Errors were hidden while installing and shown again afterwards.
	 *
	 * @return void
	 */
	private function assert_errors_hidden() {
		$this->assertSame( array(), $this->wpdb->printed, 'No database error is printed.' );
		$this->assertTrue( $this->wpdb->show_errors, 'Error display is restored.' );
	}

	/**
	 * The column list comes from the CREATE TABLE statement.
	 */
	public function test_columns_of_this_version() {
		$columns = Schema::columns();

		$this->assertCount( 47, $columns );
		$this->assertSame( 'id', $columns[0] );
		$this->assertContains( 'site_check_home_before', $columns );
		$this->assertContains( 'site_check_rest_after_reason', $columns );
		$this->assertNotContains( 'PRIMARY', $columns );
		$this->assertNotContains( 'KEY', $columns );
	}

	/**
	 * Fresh site: the table is created and verified, then the version recorded.
	 */
	public function test_clean_installation() {
		$this->assertTrue( Schema::maybe_upgrade() );

		$this->assert_installed();
		$this->assert_errors_hidden();
		$this->assertSame( 1, $this->wpdb->ran( 'dbDelta' ) );
	}

	/**
	 * Schema 5 and 6 sites get the site check columns; nothing is renamed and
	 * no data update runs.
	 */
	public function test_upgrades_from_schema_5_and_6() {
		foreach ( array( 5, 6 ) as $version ) {
			$this->wpdb->tables = array();
			$this->wpdb->log    = array();
			$this->at_version( $version );

			$this->assertTrue( Schema::maybe_upgrade(), "from {$version}" );
			$this->assert_installed();
			$this->assertSame( 0, $this->wpdb->ran( 'ALTER TABLE' ), "from {$version}" );
			$this->assertSame( 0, $this->wpdb->ran( 'UPDATE %i SET settle_outcome' ) + $this->wpdb->ran( 'UPDATE %i SET cron_' ) + $this->wpdb->ran( 'UPDATE %i SET action_scheduler_' ), "from {$version}" );
		}
	}

	/**
	 * A correct schema 7 costs one option read on bootstrap and two schema
	 * queries in repair(); nothing is installed again.
	 */
	public function test_correct_schema_is_left_alone() {
		$this->at_version( 7 );
		$this->wpdb->tables[ self::TABLE ]['columns'] = Schema::columns();

		$this->assertTrue( Schema::maybe_upgrade() );
		$this->assertSame( array(), $this->wpdb->log );

		$this->assertTrue( Schema::repair() );
		$this->assertTrue( Schema::recover() );
		$this->assertSame( array( 'DESCRIBE %i', 'SHOW INDEX FROM %i', 'DESCRIBE %i', 'SHOW INDEX FROM %i' ), $this->wpdb->log );
		$this->assertSame( array(), $this->wpdb->option_rows );
	}

	/**
	 * A table missing despite version 7 is created again by repair().
	 */
	public function test_missing_table_is_recreated() {
		$GLOBALS['updatelens_options'][ Schema::VERSION_OPTION ] = 7;

		$this->assertTrue( Schema::maybe_upgrade(), 'Bootstrap trusts the stored version.' );
		$this->assertTrue( Schema::repair() );

		$this->assert_installed();
		$this->assert_errors_hidden();
	}

	/**
	 * ALTER refused for one BEFORE or one AFTER column: the version is not
	 * recorded, the install is retryable, and nothing claims success.
	 */
	public function test_missing_column_is_not_recorded_as_installed() {
		foreach ( array( 'site_check_home_before', 'site_check_rest_after_reason' ) as $column ) {
			$this->wpdb->tables      = array();
			$this->wpdb->option_rows = array();
			$this->wpdb->deny_add    = array( $column );
			$this->at_version( 5 );

			$this->assertFalse( Schema::maybe_upgrade(), $column );
			$this->assertSame( 5, $this->version(), $column );
			$this->assertNotContains( $column, $this->wpdb->tables[ self::TABLE ]['columns'], $column );
			$this->assertArrayHasKey( Schema::RETRY_OPTION, $this->wpdb->option_rows, $column );
			$this->assertFalse( Schema::verify( self::TABLE ), $column );
		}
		$this->assert_errors_hidden();
	}

	/**
	 * A version recorded with a missing column (by an earlier release) is
	 * detected and repaired by repair().
	 */
	public function test_recorded_version_with_missing_column_is_repaired() {
		$this->at_version( 6 );
		$GLOBALS['updatelens_options'][ Schema::VERSION_OPTION ] = 7;

		$this->assertFalse( Schema::verify( self::TABLE ) );
		$this->assertTrue( Schema::repair() );
		$this->assert_installed();
	}

	/**
	 * A failed rename (ALTER TABLE … CHANGE COLUMN) stops before dbDelta().
	 */
	public function test_failed_alter_table() {
		$this->wpdb->create( self::TABLE, array( 'id', 'status', 'active_plugin', 'before_snapshot', 'settle_outcome' ) );
		$GLOBALS['updatelens_options'][ Schema::VERSION_OPTION ] = 2;
		$this->wpdb->fail['ALTER TABLE']                         = 'ALTER command denied to user';

		$this->assertFalse( Schema::maybe_upgrade() );

		$this->assertSame( 2, $this->version() );
		$this->assertSame( 0, $this->wpdb->ran( 'dbDelta' ), 'No empty columns are added next to the old ones.' );
		$this->assertContains( 'before_snapshot', $this->wpdb->tables[ self::TABLE ]['columns'] );
		$this->assert_errors_hidden();
	}

	/**
	 * Unreadable schema metadata never counts as installed.
	 */
	public function test_metadata_query_failures() {
		foreach ( array( 'SHOW TABLES', 'DESCRIBE', 'SHOW INDEX' ) as $statement ) {
			$this->wpdb->tables      = array();
			$this->wpdb->option_rows = array();
			$this->wpdb->log         = array();
			$this->wpdb->fail        = array( $statement => 'Lost connection to server' );
			$this->at_version( 6 );

			$this->assertFalse( Schema::maybe_upgrade(), $statement );
			$this->assertSame( 6, $this->version(), $statement );
			if ( 'SHOW TABLES' === $statement ) {
				$this->assertSame( 0, $this->wpdb->ran( 'dbDelta' ), 'Unknown table state: no DDL.' );
			}
		}
		$this->assert_errors_hidden();

		// A schema that cannot be read is not ready for report reads either.
		$this->wpdb->fail                                        = array( 'DESCRIBE' => 'Lost connection to server' );
		$GLOBALS['updatelens_options'][ Schema::VERSION_OPTION ] = 7;
		$this->assertFalse( Schema::repair() );
	}

	/**
	 * A failed one-time data update keeps the old version (retried later).
	 */
	public function test_failed_data_update() {
		$this->wpdb->create( self::TABLE, array_values( array_diff( self::columns_of( 5 ), array( 'action_scheduler_final_reason' ) ) ) );
		$GLOBALS['updatelens_options'][ Schema::VERSION_OPTION ] = 3;
		$this->wpdb->fail['UPDATE %i SET action_scheduler_']     = 'Lock wait timeout exceeded';

		$this->assertFalse( Schema::maybe_upgrade() );
		$this->assertSame( 3, $this->version() );
	}

	/**
	 * The version must be readable back after writing it.
	 */
	public function test_unwritable_version_is_not_success() {
		$GLOBALS['updatelens_option_write_fails'] = true;

		$this->assertFalse( Schema::install() );
		$this->assertFalse( $this->version() );
	}

	/**
	 * Repeated installs are idempotent.
	 */
	public function test_repeated_installation() {
		$this->at_version( 6 );

		$this->assertTrue( Schema::install() );
		$columns = $this->wpdb->tables[ self::TABLE ]['columns'];
		$this->assertTrue( Schema::install() );
		$this->assertTrue( Schema::install() );

		$this->assertSame( $columns, $this->wpdb->tables[ self::TABLE ]['columns'] );
		$this->assertSame( count( Schema::columns() ), count( array_unique( $columns ) ) );
		$this->assert_installed();
	}

	/**
	 * While installing fails, requests attempt it at most once per retry
	 * interval (no DDL on every request); report reads do not bypass it.
	 */
	public function test_failing_install_is_not_repeated_on_every_request() {
		$this->at_version( 6 );
		$this->wpdb->deny_add = true;

		for ( $request = 0; $request < 20; $request++ ) {
			$this->assertFalse( Schema::maybe_upgrade() );
		}
		$this->assertFalse( Schema::repair() );

		$this->assertSame( 1, $this->wpdb->ran( 'dbDelta' ) );
		$this->assertSame( 6, $this->version() );
		$retry_at = (int) $this->wpdb->option_rows[ Schema::RETRY_OPTION ];
		$this->assertGreaterThan( time(), $retry_at );
		$this->assertLessThanOrEqual( time() + Schema::RETRY_AFTER_SECONDS, $retry_at );
		$this->assert_errors_hidden();
	}

	/**
	 * After the cause is fixed: the next attempt after the interval succeeds,
	 * or an administrator opening UpdateLens retries at once.
	 */
	public function test_recovery_after_the_problem_is_fixed() {
		$this->at_version( 6 );
		$this->wpdb->deny_add = true;
		$this->assertFalse( Schema::maybe_upgrade() );

		$this->wpdb->deny_add = array();
		$this->assertFalse( Schema::maybe_upgrade(), 'Still waiting for the retry interval.' );
		$now = time();
		$this->wpdb->option_rows[ Schema::RETRY_OPTION ] = (string) ( $now + Schema::RETRY_AFTER_SECONDS );
		$this->assertFalse( Schema::claim_attempt( true, $now ), 'Not twice in the same second.' );
		// Ten seconds later, an administrator opens UpdateLens.
		$this->wpdb->option_rows[ Schema::RETRY_OPTION ] = (string) ( time() + Schema::RETRY_AFTER_SECONDS - 10 );
		$this->assertFalse( Schema::maybe_upgrade(), 'Other requests still wait.' );
		$this->assertTrue( Schema::recover(), 'The UpdateLens screen retries at once.' );
		$this->assert_installed();

		// Same, through the interval.
		$this->wpdb->tables      = array();
		$this->wpdb->option_rows = array();
		$this->at_version( 6 );
		$this->wpdb->deny_add = true;
		$this->assertFalse( Schema::maybe_upgrade() );
		$this->wpdb->deny_add                            = array();
		$this->wpdb->option_rows[ Schema::RETRY_OPTION ] = (string) ( time() - 1 );
		$this->assertTrue( Schema::maybe_upgrade() );
		$this->assert_installed();
	}

	/**
	 * Activation always attempts the install, whatever the retry marker.
	 */
	public function test_activation_ignores_the_retry_interval() {
		$this->at_version( 6 );
		$this->wpdb->option_rows[ Schema::RETRY_OPTION ] = (string) ( time() + 300 );

		$this->assertTrue( Schema::install() );
		$this->assert_installed();
	}

	/**
	 * A marker too far ahead (clock moved back) or unreadable never blocks
	 * attempts; a failed read claims nothing.
	 */
	public function test_stale_or_unreadable_marker() {
		foreach ( array( (string) ( time() + 86400 ), 'garbage', '' ) as $stored ) {
			$this->wpdb->option_rows[ Schema::RETRY_OPTION ] = $stored;
			$this->assertTrue( Schema::claim_attempt( false, time() ), "stored '{$stored}'" );
		}

		$this->wpdb->fail = array( 'SELECT option_value' => 'Lost connection to server' );
		unset( $this->wpdb->option_rows[ Schema::RETRY_OPTION ] );
		$this->assertFalse( Schema::claim_attempt( true, time() ) );
	}

	/**
	 * Simultaneous requests: exactly one claims the attempt, whether the
	 * marker is missing (INSERT IGNORE) or due (compare-and-set).
	 */
	public function test_simultaneous_requests_claim_one_attempt() {
		$now                     = time();
		$wpdb                    = $this->wpdb;
		$inside                  = null;
		$wpdb->after_option_read = function () use ( &$inside, $now ) {
			$inside = Schema::claim_attempt( false, $now );
		};
		$this->assertFalse( Schema::claim_attempt( false, $now ), 'Insert lost to the concurrent request.' );
		$this->assertTrue( $inside );

		$wpdb->option_rows[ Schema::RETRY_OPTION ] = (string) ( $now - 5 );
		$wpdb->after_option_read                   = function () use ( &$inside, $now ) {
			$inside = Schema::claim_attempt( true, $now );
		};
		$this->assertFalse( Schema::claim_attempt( false, $now ), 'Compare-and-set lost to the concurrent request.' );
		$this->assertTrue( $inside );
		$this->assertSame( (string) ( $now + Schema::RETRY_AFTER_SECONDS ), $wpdb->option_rows[ Schema::RETRY_OPTION ] );
	}

	/**
	 * A request that read its options before another request finished the
	 * install, and claims the attempt after that request removed the marker:
	 * it sees the stored version and the verified table, installs nothing and
	 * leaves no marker behind.
	 */
	public function test_install_finished_by_another_request() {
		$this->at_version( 7 );
		$this->wpdb->tables[ self::TABLE ]['columns'] = Schema::columns();
		$GLOBALS['updatelens_stale_options']          = array( Schema::VERSION_OPTION => 6 );

		$this->assertTrue( Schema::maybe_upgrade() );

		$this->assertSame( 0, $this->wpdb->ran( 'dbDelta' ) );
		$this->assertSame( array(), $this->wpdb->option_rows );
	}

	/**
	 * Uninstall removes the table, the version and the retry marker.
	 */
	public function test_uninstall() {
		$this->at_version( 6 );
		$this->wpdb->deny_add = true;
		Schema::maybe_upgrade();
		$GLOBALS['updatelens_options'][ Schema::RETRY_OPTION ] = 'x';

		Schema::uninstall();

		$this->assertSame( array(), $this->wpdb->tables );
		$this->assertFalse( $this->version() );
		$this->assertFalse( get_option( Schema::RETRY_OPTION ) );
	}
}
