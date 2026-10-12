<?php
/**
 * Tests for site check session claims.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\SiteCheck;

use PHPUnit\Framework\TestCase;
use UpdateLens\Core\Plugin;
use UpdateLens\SiteCheck\CheckSession;
use UpdateLens\SiteCheck\TransientSessionStore;
use wpdb;

/**
 * TransientSessionStore against a recording wpdb and Transients API whose
 * delete always reports success: only the claim row decides who claimed a
 * session.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TransientSessionStoreTest extends TestCase {

	const NOW = 1790000000;

	/**
	 * Database stand-in.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Session storage key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Load the stand-ins.
	 *
	 * @before
	 */
	public function load_stubs() {
		require dirname( __DIR__, 2 ) . '/fixtures/wpdb-recorder.php';
		require dirname( __DIR__, 2 ) . '/fixtures/wp-transient-stubs.php';

		$this->wpdb = $GLOBALS['wpdb'];
		$this->key  = CheckSession::storage_key( str_repeat( 'ab', 16 ) );
	}

	/**
	 * The store, at a fixed time.
	 *
	 * @return TransientSessionStore
	 */
	private function store() {
		return new TransientSessionStore(
			static function () {
				return self::NOW;
			}
		);
	}

	/**
	 * The call that inserts the claim row wins: it removes the session and
	 * at most CLEANUP_LIMIT expired claim rows.
	 */
	public function test_the_inserted_claim_row_wins() {
		$this->wpdb->query_results = array( 1, 3 );

		$this->assertTrue( $this->store()->claim( $this->key ) );
		$this->assertSame(
			array(
				array( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', array( 'wp_options', TransientSessionStore::claim_name( $this->key ), (string) ( self::NOW + 600 ), 'no' ) ),
				array( 'DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_name ASC LIMIT %d', array( 'wp_options', 'updatelens\_site\_check\_claim\_%', self::NOW, 10 ) ),
			),
			$this->wpdb->prepared
		);
		$this->assertSame( array( 'prepared:0', 'prepared:1' ), $this->wpdb->queries );
		$this->assertSame( array( array( 'delete', $this->key ) ), $GLOBALS['updatelens_test_transient_calls'] );
	}

	/**
	 * A claim row that exists already (a concurrent or earlier claim) loses,
	 * although deleting the transient would report success: nothing else runs.
	 */
	public function test_an_existing_claim_row_loses() {
		$this->wpdb->query_results = array( 0 );

		$this->assertFalse( $this->store()->claim( $this->key ) );
		$this->assertCount( 1, $this->wpdb->prepared );
		$this->assertSame( array(), $GLOBALS['updatelens_test_transient_calls'] );
	}

	/**
	 * A failed insert claims nothing (fail closed).
	 */
	public function test_a_failed_insert_claims_nothing() {
		$this->wpdb->query_results = array( false );
		$this->wpdb->last_error    = 'Deadlock found';

		$this->assertFalse( $this->store()->claim( $this->key ) );
		$this->assertSame( array(), $GLOBALS['updatelens_test_transient_calls'] );
	}

	/**
	 * Claim rows: UpdateLens's prefix (never in its snapshots), a fixed-length
	 * hash of the key (no marker or session data), within the option name
	 * column; kept well past the session lifetime.
	 */
	public function test_claim_rows() {
		$name = TransientSessionStore::claim_name( $this->key );

		$this->assertSame( 0, strpos( $name, Plugin::OPTION_PREFIX ) );
		$this->assertMatchesRegularExpression( '/\Aupdatelens_site_check_claim_[0-9a-f]{64}\z/', $name );
		$this->assertStringNotContainsString( substr( $this->key, strlen( CheckSession::STORAGE_PREFIX ) ), $name );
		$this->assertLessThanOrEqual( 191, strlen( $name ) );
		$this->assertNotSame( $name, TransientSessionStore::claim_name( CheckSession::storage_key( str_repeat( 'cd', 16 ) ) ) );
		$this->assertGreaterThanOrEqual( 2 * CheckSession::TTL, TransientSessionStore::CLAIM_LIFETIME );
		$this->assertGreaterThan( 1, TransientSessionStore::CLEANUP_LIMIT, 'Each claim removes more expired rows than it adds.' );
	}

	/**
	 * Sessions stay transients; uninstall removes every claim row.
	 */
	public function test_transients_and_uninstall() {
		$store = $this->store();
		$this->assertTrue( $store->put( $this->key, '{}', 120 ) );
		$this->assertNull( $store->get( $this->key ) );
		TransientSessionStore::uninstall();

		$this->assertSame(
			array(
				array( 'set', $this->key, '{}', 120 ),
				array( 'get', $this->key ),
			),
			$GLOBALS['updatelens_test_transient_calls']
		);
		$this->assertSame(
			array( array( 'DELETE FROM %i WHERE option_name LIKE %s', array( 'wp_options', 'updatelens\_site\_check\_claim\_%' ) ) ),
			$this->wpdb->prepared
		);
	}
}
