<?php
/**
 * Tests for the SQL of baseline storage.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\SiteCheck;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use UpdateLens\SiteCheck\OptionBaselineStore;
use wpdb;

/**
 * OptionBaselineStore against a recording wpdb stand-in: literal prepared
 * templates, atomic conditional writes, errors never read as "missing".
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class OptionBaselineStoreTest extends TestCase {

	const KEY = 'updatelens_site_check_baseline_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

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
	 * Each operation is one prepared statement with fixed SQL; conditional
	 * writes compare the exact stored bytes.
	 */
	public function test_statements() {
		$store                     = new OptionBaselineStore();
		$this->wpdb->vars          = array( '{"schema":1}' );
		$this->wpdb->query_results = array( 1, 0, 1, 0, 1 );
		$this->wpdb->results       = array(
			array(
				'option_name'  => self::KEY,
				'option_value' => '{}',
			),
			array( 'option_name' => 'broken' ),
		);

		$this->assertSame( '{"schema":1}', $store->get( self::KEY ) );
		$this->assertTrue( $store->add( self::KEY, 'new' ) );
		$this->assertFalse( $store->add( self::KEY, 'new' ), 'The name exists: nothing inserted.' );
		$this->assertTrue( $store->replace( self::KEY, 'old', 'new' ) );
		$this->assertFalse( $store->replace( self::KEY, 'old', 'new' ), 'Changed meanwhile.' );
		$this->assertTrue( $store->remove( self::KEY, 'old' ) );
		$this->assertSame( array( self::KEY => '{}' ), $store->all( 100 ) );
		OptionBaselineStore::uninstall();

		$this->assertSame(
			array(
				array( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', array( 'wp_options', self::KEY ) ),
				array( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', array( 'wp_options', self::KEY, 'new', 'no' ) ),
				array( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', array( 'wp_options', self::KEY, 'new', 'no' ) ),
				array( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', array( 'wp_options', 'new', self::KEY, 'old' ) ),
				array( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', array( 'wp_options', 'new', self::KEY, 'old' ) ),
				array( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', array( 'wp_options', self::KEY, 'old' ) ),
				array( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d', array( 'wp_options', 'updatelens\_site\_check\_baseline\_%', 100 ) ),
				array( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name = %s', array( 'wp_options', 'updatelens\_site\_check\_baseline\_%', 'updatelens_update_activity' ) ),
			),
			$this->wpdb->prepared
		);
		$this->assertSame( array( 'prepared:0', 'prepared:1', 'prepared:2', 'prepared:3', 'prepared:4', 'prepared:5', 'prepared:6', 'prepared:7' ), $this->wpdb->queries );
	}

	/**
	 * A missing row is null; a failed read throws instead.
	 */
	public function test_missing_and_failed_reads() {
		$store = new OptionBaselineStore();
		$this->assertNull( $store->get( self::KEY ) );

		$this->wpdb->last_error = 'Table is marked as crashed';
		try {
			$store->get( self::KEY );
			$this->fail( 'A failed read must throw.' );
		} catch ( UnexpectedValueException $e ) {
			$this->assertStringNotContainsString( 'crashed', $e->getMessage() );
		}
		$this->expectException( UnexpectedValueException::class );
		$store->all( 10 );
	}
}
