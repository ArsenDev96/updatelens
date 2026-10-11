<?php
/**
 * Tests for OptionsSnapshotProvider against an in-memory options table.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Snapshot;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\Snapshot\AutoloadPolicy;
use UpdateLens\Snapshot\InsufficientMemoryException;
use UpdateLens\Snapshot\MemoryBudget;
use UpdateLens\Snapshot\OptionNoiseFilter;
use UpdateLens\Snapshot\OptionsSnapshotBuilder;
use UpdateLens\Snapshot\OptionsSnapshotProvider;
use UpdateLens\Snapshot\OptionValueHasher;
use UpdateLens\Storage\OptionsSnapshotCodec;
use wpdb;

/**
 * Bounded capture: small values come with their batch, larger ones are read
 * one at a time (never for noise), the memory budget is asked before every
 * batch and large value, and the snapshot equals one built from every row
 * read at once (the earlier implementation).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class OptionsSnapshotProviderTest extends TestCase {

	const MIB = 1048576;

	/**
	 * Database stand-in.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Fake memory usage in bytes.
	 *
	 * @var int
	 */
	private $usage = 0;

	/**
	 * Load the stand-in.
	 *
	 * @before
	 */
	public function load_wpdb() {
		require dirname( __DIR__, 2 ) . '/fixtures/wpdb-options.php';

		$this->wpdb  = $GLOBALS['wpdb'];
		$this->usage = 0;
	}

	/**
	 * The builder both captures use.
	 *
	 * @return OptionsSnapshotBuilder
	 */
	private static function builder() {
		return new OptionsSnapshotBuilder( new OptionValueHasher( 'site-salt' ), new OptionNoiseFilter(), new AutoloadPolicy( array( 'yes', 'on', 'auto-on', 'auto' ) ) );
	}

	/**
	 * Provider with a memory limit (0: unlimited) and the fake usage.
	 *
	 * @param int $limit Bytes.
	 * @return OptionsSnapshotProvider
	 */
	private function provider( $limit = 0 ) {
		return new OptionsSnapshotProvider(
			$this->wpdb,
			self::builder(),
			new OptionNoiseFilter(),
			new MemoryBudget(
				$limit,
				function () {
					return $this->usage;
				}
			)
		);
	}

	/**
	 * The snapshot of every row read at once, as before this change.
	 *
	 * @return string Encoded snapshot.
	 */
	private function all_at_once() {
		$rows = array();
		foreach ( $this->wpdb->rows as $name => $row ) {
			$rows[] = array(
				'option_name'  => (string) $name,
				'option_value' => $row[0],
				'autoload'     => $row[1],
			);
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( $a['option_name'], $b['option_name'] );
			}
		);

		return ( new OptionsSnapshotCodec() )->encode( self::builder()->build( $rows ) );
	}

	/**
	 * Statements of one kind that ran.
	 *
	 * @param string $prefix Template prefix.
	 * @return int
	 */
	private function ran( $prefix ) {
		return count(
			array_filter(
				$this->wpdb->log,
				function ( $template ) use ( $prefix ) {
					return 0 === strpos( $template, $prefix );
				}
			)
		);
	}

	/**
	 * A realistic table: values around the batch limit, large values, UTF-8,
	 * empty values, an empty name, noise, every autoload form and more rows
	 * than one batch.
	 *
	 * @return void
	 */
	private function seed() {
		$inline = OptionsSnapshotProvider::INLINE_VALUE_BYTES;
		$rows   = array(
			''                         => array( 'empty name', 'yes' ),
			'at_limit'                 => array( str_repeat( 'a', $inline ), 'on' ),
			'over_limit'               => array( str_repeat( 'b', $inline + 1 ), 'off' ),
			'large'                    => array( str_repeat( 'c', 300000 ), 'auto-on' ),
			'utf8_large'               => array( str_repeat( 'ü€', 2000 ), 'auto' ),
			'empty_value'              => array( '', 'no' ),
			'rewrite_rules'            => array( str_repeat( 'r', 25000 ), 'yes' ),
			'_transient_huge'          => array( str_repeat( 't', 500000 ), 'off' ),
			'_site_transient_huge'     => array( str_repeat( 's', 500000 ), 'off' ),
			'_transient_small'         => array( 'x', 'off' ),
			'updatelens_db_version'    => array( '7', 'on' ),
			'cron'                     => array( str_repeat( 'k', 9000 ), 'on' ),
			'secret_key'               => array( 'sk_live_' . str_repeat( 'z', 5000 ), 'off' ),
			'auto_off_setting'         => array( 'v', 'auto-off' ),
			'z_last_before_pagination' => array( 'z', 'yes' ),
		);
		for ( $i = 0; $i < 1234; $i++ ) {
			$rows[ sprintf( 'option_%05d', $i ) ] = array( str_repeat( 'v', $i % 7 * 1000 ), 0 === $i % 3 ? 'yes' : 'off' );
		}
		$this->wpdb->rows = $rows;
	}

	/**
	 * Fingerprints, sizes and autoload are exactly those of reading every row at
	 * once; only non-noise values above the batch limit are read alone.
	 */
	public function test_snapshot_equals_reading_every_row_at_once() {
		$this->seed();

		$snapshot = $this->provider()->capture();

		$this->assertSame( $this->all_at_once(), ( new OptionsSnapshotCodec() )->encode( $snapshot ) );
		$records = $snapshot->get_records();
		$this->assertSame( OptionsSnapshotProvider::INLINE_VALUE_BYTES + 1, $records['over_limit']->get_size() );
		$this->assertSame( 300000, $records['large']->get_size() );
		$this->assertSame( 10000, $records['utf8_large']->get_size(), 'Bytes, not characters.' );
		$this->assertArrayHasKey( '', $records, 'An empty option name is kept, as before.' );
		$this->assertArrayNotHasKey( '_transient_huge', $records );

		$expected_large = 0;
		foreach ( $this->wpdb->rows as $name => $row ) {
			if ( strlen( $row[0] ) > OptionsSnapshotProvider::INLINE_VALUE_BYTES && ! ( new OptionNoiseFilter() )->is_noise( (string) $name ) ) {
				++$expected_large;
			}
		}
		$this->assertSame( $expected_large, $this->ran( 'SELECT option_value FROM %i WHERE option_name = %s' ), 'Noise (transients, cron) is never read alone.' );
		$this->assertSame( (int) ceil( count( $this->wpdb->rows ) / OptionsSnapshotProvider::BATCH_SIZE ), $this->ran( 'SELECT option_name, autoload, LENGTH' ), 'Batches as before.' );
		$this->assertSame( count( $this->wpdb->log ), $this->wpdb->flushes, "\$wpdb's copy of every result is released." );
	}

	/**
	 * The secret value never ends up in the snapshot.
	 */
	public function test_no_raw_values_in_the_snapshot() {
		$this->seed();

		$this->assertStringNotContainsString( 'sk_live_', ( new OptionsSnapshotCodec() )->encode( $this->provider()->capture() ) );
	}

	/**
	 * A value whose read would not fit stops the capture before it is read:
	 * no snapshot at all, never one without it.
	 */
	public function test_value_too_large_for_memory_stops_before_reading_it() {
		$this->wpdb->rows = array(
			'a_small' => array( 'x', 'yes' ),
			'b_huge'  => array( str_repeat( 'h', 5 * self::MIB ), 'off' ),
			'c_small' => array( 'y', 'yes' ),
		);
		$this->usage      = 10 * self::MIB;

		try {
			$this->provider( 40 * self::MIB )->capture(); // 14 MiB free: a batch fits, 4 × 5 MiB does not.
			$this->fail( 'Expected InsufficientMemoryException.' );
		} catch ( InsufficientMemoryException $e ) {
			$this->assertSame( 0, $this->ran( 'SELECT option_value FROM %i WHERE option_name = %s' ), 'The value was not read.' );
		}

		// With more memory the same table is captured completely.
		$snapshot = $this->provider( 80 * self::MIB )->capture();
		$this->assertCount( 3, $snapshot->get_records() );
	}

	/**
	 * A large transient never needs memory: it is not read.
	 */
	public function test_large_noise_needs_no_memory() {
		$this->wpdb->rows = array(
			'_transient_feed' => array( str_repeat( 'f', 5 * self::MIB ), 'off' ),
			'blogname'        => array( 'Site', 'yes' ),
		);
		$this->usage      = 10 * self::MIB;

		$this->assertCount( 1, $this->provider( 40 * self::MIB )->capture()->get_records() );
	}

	/**
	 * Memory used up by many records stops the capture at the next batch.
	 */
	public function test_growing_usage_stops_at_the_next_batch() {
		$this->seed();
		$this->usage                   = 10 * self::MIB;
		$batches                       = 0;
		$this->wpdb->before_value_read = null;
		$provider                      = new OptionsSnapshotProvider(
			$this->wpdb,
			self::builder(),
			new OptionNoiseFilter(),
			new MemoryBudget(
				40 * self::MIB,
				function () use ( &$batches ) {
					// Each check sees the records of every earlier batch.
					return 10 * self::MIB + ( $batches++ ) * 3 * self::MIB;
				}
			)
		);

		$this->expectException( InsufficientMemoryException::class );
		$provider->capture();
	}

	/**
	 * Without a memory limit, nothing is refused.
	 */
	public function test_unlimited_memory() {
		$this->wpdb->rows = array( 'huge' => array( str_repeat( 'h', 3 * self::MIB ), 'off' ) );
		$this->usage      = PHP_INT_MAX >> 1;

		$this->assertCount( 1, $this->provider( 0 )->capture()->get_records() );
	}

	/**
	 * A failed batch or large-value query fails the whole capture.
	 */
	public function test_query_failures() {
		$this->seed();
		foreach ( array( 0, 1 ) as $index ) { // The first and the second batch.
			$this->wpdb->log     = array();
			$this->wpdb->fail_at = $index;
			try {
				$this->provider()->capture();
				$this->fail( "Expected a failure at statement {$index}." );
			} catch ( RuntimeException $e ) {
				$this->assertNotInstanceOf( InsufficientMemoryException::class, $e );
			}
		}

		// A large value's read fails.
		$this->wpdb->rows    = array(
			'a' => array( 'x', 'yes' ),
			'b' => array( str_repeat( 'b', 10000 ), 'off' ),
		);
		$this->wpdb->log     = array();
		$this->wpdb->fail_at = 1;
		$this->expectException( RuntimeException::class );
		$this->provider()->capture();
	}

	/**
	 * An option deleted between its batch and its large-value read is left
	 * out, as if it had been read later.
	 */
	public function test_option_deleted_before_its_value_is_read() {
		$this->wpdb->rows              = array(
			'a' => array( 'x', 'yes' ),
			'b' => array( str_repeat( 'b', 10000 ), 'off' ),
		);
		$this->wpdb->before_value_read = function ( $wpdb ) {
			unset( $wpdb->rows['b'] );
		};

		$this->assertSame( array( 'a' ), array_keys( $this->provider()->capture()->get_records() ) );
	}

	/**
	 * The count excludes transients (an upper bound of the kept options).
	 */
	public function test_count_options() {
		$this->seed();

		$this->assertSame( count( $this->wpdb->rows ) - 3, $this->provider()->count_options() );

		$this->wpdb->log     = array();
		$this->wpdb->fail_at = 0;
		$this->expectException( RuntimeException::class );
		$this->provider()->count_options();
	}
}
