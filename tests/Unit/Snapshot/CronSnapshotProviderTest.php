<?php
/**
 * Tests for CronSnapshotProvider's size measurement.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Snapshot;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\Snapshot\CronArgsHasher;
use UpdateLens\Snapshot\CronSnapshotBuilder;
use UpdateLens\Snapshot\CronSnapshotProvider;

/**
 * ObservationBudget's WP-Cron estimate: the stored size of the `cron`
 * option, measured in SQL without reading the value.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CronSnapshotProviderTest extends TestCase {

	/**
	 * Load the stand-ins: $wpdb and has_filter() for `pre_option_cron`.
	 *
	 * @before
	 */
	public function load_stubs() {
		require dirname( __DIR__, 2 ) . '/fixtures/wpdb-recorder.php';
		require_once dirname( __DIR__, 2 ) . '/fixtures/wp-has-filter.php';
		$GLOBALS['updatelens_test_filters'] = array();
	}

	/**
	 * Provider.
	 *
	 * @return CronSnapshotProvider
	 */
	private function provider() {
		return new CronSnapshotProvider( new CronSnapshotBuilder( new CronArgsHasher( 'salt' ) ) );
	}

	/**
	 * One query for the length (never the value), and $wpdb's copy released.
	 */
	public function test_measures_the_length_only() {
		$GLOBALS['wpdb']->vars = array( '5390739' );

		$this->assertSame( 5390739, $this->provider()->stored_bytes() );
		$this->assertCount( 1, $GLOBALS['wpdb']->prepared );
		list( $query, $args ) = $GLOBALS['wpdb']->prepared[0];
		$this->assertSame( 'SELECT LENGTH(option_value) FROM %i WHERE option_name = %s', $query );
		$this->assertSame( array( 'wp_options', 'cron' ), $args );
		$this->assertSame( 1, $GLOBALS['wpdb']->flushes );
	}

	/**
	 * No row: nothing to capture.
	 */
	public function test_missing_option_is_empty() {
		$this->assertSame( 0, $this->provider()->stored_bytes() );
	}

	/**
	 * A failed query cannot be measured.
	 */
	public function test_failed_query_throws() {
		$GLOBALS['wpdb']->last_error = 'Lost connection';

		$this->expectException( RuntimeException::class );
		$this->provider()->stored_bytes();
	}

	/**
	 * Events supplied by a `pre_option_cron` backend are not in the options
	 * table: their size is unknown, so nothing is queried.
	 */
	public function test_cron_backend_cannot_be_measured() {
		$GLOBALS['updatelens_test_filters']['pre_option_cron'] = true;

		try {
			$this->provider()->stored_bytes();
			$this->fail( 'Expected RuntimeException.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( array(), $GLOBALS['wpdb']->queries );
		}
	}
}
