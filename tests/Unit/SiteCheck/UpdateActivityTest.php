<?php
/**
 * Tests for the updater activity ledger.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\SiteCheck;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use UpdateLens\SiteCheck\UpdateActivity;
use UpdateLens\Storage\UpdateActivityCodec;
use UpdateLens\Tests\Support\InMemoryBaselineStore;

/**
 * UpdateActivity: strict ordering through compare-and-set, generations,
 * running entries, staleness, corruption; UpdateActivityCodec.
 */
final class UpdateActivityTest extends TestCase {

	/**
	 * Storage.
	 *
	 * @var InMemoryBaselineStore
	 */
	private $store;

	/**
	 * Fake time.
	 *
	 * @var float
	 */
	private $now = 1760090000.5;

	/**
	 * Ledger.
	 *
	 * @var UpdateActivity
	 */
	private $activity;

	/**
	 * Random bytes handed out, in order (then random_bytes()).
	 *
	 * @var string[]
	 */
	private $random = array();

	/**
	 * Build the ledger.
	 *
	 * @before
	 */
	public function set_up_ledger() {
		$this->store    = new InMemoryBaselineStore();
		$this->activity = $this->ledger();
	}

	/**
	 * A ledger on the shared store (another request).
	 *
	 * @return UpdateActivity
	 */
	private function ledger() {
		return new UpdateActivity(
			$this->store,
			function () {
				return $this->now;
			},
			function ( $length ) {
				return $this->random ? array_shift( $this->random ) : random_bytes( $length );
			}
		);
	}

	/**
	 * The stored state.
	 *
	 * @return array
	 */
	private function state() {
		return ( new UpdateActivityCodec() )->decode( $this->store->values[ UpdateActivity::STORAGE_KEY ] );
	}

	/**
	 * The first session creates the ledger with a new epoch at sequence 0.
	 */
	public function test_quiet_generation_creates_the_ledger() {
		$this->random = array( "\x01\x23\x45\x67\x89\xab\xcd\xef" );

		$this->assertSame( '0123456789abcdef:0', $this->activity->quiet_generation() );
		$this->assertSame( '0123456789abcdef:0', $this->activity->quiet_generation(), 'Reading does not change it.' );
		$this->assertSame( array( array( 'add', UpdateActivity::STORAGE_KEY ) ), $this->store->writes );
	}

	/**
	 * Begin and end move the sequence; begin reports the state before it.
	 */
	public function test_begin_and_end() {
		$this->random = array( "\x01\x23\x45\x67\x89\xab\xcd\xef" );
		$generation   = $this->activity->quiet_generation();
		$token        = $this->activity->new_token();

		$this->assertSame(
			array(
				'generation'     => $generation,
				'others_running' => false,
				'at'             => $this->now,
			),
			$this->activity->begin( $token )
		);
		$this->assertNull( $this->activity->quiet_generation(), 'No baseline generation while an update runs.' );
		$this->assertSame( array( $token => $this->now ), $this->state()['running'] );

		// Another download in the same request (bulk update, translations): no write.
		$this->now += 2.0;
		$writes     = $this->store->writes;
		$again      = $this->activity->begin( $token );
		$this->assertSame( array( '0123456789abcdef:1', false ), array( $again['generation'], $again['others_running'] ) );
		$this->assertSame( $writes, $this->store->writes );
		$this->assertSame( array( $token => $this->now - 2.0 ), $this->state()['running'], 'The first start is kept.' );
		$this->assertSame(
			array(
				'generation' => '0123456789abcdef:1',
				'running'    => true,
			),
			$this->activity->current()
		);

		$this->assertTrue( $this->activity->end( $token ) );
		$this->assertFalse( $this->activity->end( $token ), 'Ended once.' );
		$this->assertSame( '0123456789abcdef:2', $this->activity->quiet_generation() );
		$this->assertSame( UpdateActivity::advance( $generation, 2 ), $this->activity->quiet_generation(), 'One request moves the sequence exactly twice.' );
		$this->assertNotSame( $generation, $this->activity->quiet_generation(), 'Any update makes older generations unusable.' );
	}

	/**
	 * Generations two changes later (advance()) and epochs (same_epoch()).
	 */
	public function test_advance_and_epochs() {
		$this->assertSame( '0123456789abcdef:7', UpdateActivity::advance( '0123456789abcdef:5', 2 ) );
		$this->assertSame( '0123456789abcdef:2', UpdateActivity::advance( '0123456789abcdef:0', 2 ) );
		$this->assertNull( UpdateActivity::advance( '0123456789abcdef:' . UpdateActivityCodec::MAX_SEQUENCE, 2 ), 'Overflow.' );
		$this->assertNull( UpdateActivity::advance( 'nope', 2 ) );
		$this->assertNull( UpdateActivity::advance( null, 2 ) );
		$this->assertTrue( UpdateActivity::same_epoch( '0123456789abcdef:1', '0123456789abcdef:9' ) );
		$this->assertFalse( UpdateActivity::same_epoch( '0123456789abcdef:1', 'fedcba9876543210:1' ) );
		$this->assertFalse( UpdateActivity::same_epoch( '0123456789abcdef:1', null ) );
	}

	/**
	 * Two requests that start together are strictly ordered: the second
	 * sees the first running and a newer generation.
	 */
	public function test_concurrent_begins_are_ordered() {
		$generation = $this->activity->quiet_generation();
		$first      = $this->ledger();
		$second     = $this->ledger();
		$token_a    = $first->new_token();
		$token_b    = $second->new_token();

		// Request B writes between request A's read and A's write.
		$this->store->before_write = function () use ( $second, $token_b, &$b ) {
			$b = $second->begin( $token_b );
		};
		$a                         = $first->begin( $token_a );

		$this->assertSame( array( $generation, false ), array( $b['generation'], $b['others_running'] ), 'B won the race.' );
		$this->assertTrue( $a['others_running'], 'A saw B running.' );
		$this->assertNotSame( $generation, $a['generation'], 'A saw a newer generation.' );
		$this->assertEqualsCanonicalizing( array( $token_a, $token_b ), array_keys( $this->state()['running'] ), 'No entry was lost.' );

		$this->assertTrue( $first->end( $token_a ) );
		$this->assertNull( $this->activity->quiet_generation(), 'B still runs.' );
		$this->assertTrue( $second->end( $token_b ) );
		$this->assertNotNull( $this->activity->quiet_generation() );
	}

	/**
	 * Same-second (even same-microsecond) starts are ordered by the ledger, not by time.
	 */
	public function test_same_time_starts() {
		$this->activity->quiet_generation();
		$first  = $this->activity->begin( 'a' . str_repeat( '0', 31 ) );
		$second = $this->ledger()->begin( 'b' . str_repeat( '0', 31 ) );

		$this->assertSame( $first['at'], $second['at'] );
		$this->assertFalse( $first['others_running'] );
		$this->assertTrue( $second['others_running'] );
		$this->assertNotSame( $first['generation'], $second['generation'] );
	}

	/**
	 * Entries of requests that never ended expire.
	 */
	public function test_stale_entries() {
		$this->activity->quiet_generation();
		$this->activity->begin( str_repeat( 'a', 32 ) );

		$this->now += UpdateActivity::STALE_AFTER_SECONDS;
		$this->assertNull( $this->activity->quiet_generation(), 'Still running at the limit.' );
		$this->now += 1;
		$this->assertNotNull( $this->activity->quiet_generation(), 'Ignored afterwards.' );
		$this->assertFalse( $this->activity->begin( str_repeat( 'b', 32 ) )['others_running'] );
		$this->assertSame( array( str_repeat( 'b', 32 ) ), array_keys( $this->state()['running'] ), 'Dropped on the next write.' );
	}

	/**
	 * A missing or unreadable ledger starts a new epoch: begin reports no
	 * generation, and no earlier generation can ever match again.
	 */
	public function test_unreadable_ledger() {
		$this->random = array( "\x01\x23\x45\x67\x89\xab\xcd\xef", "\xfe\xdc\xba\x98\x76\x54\x32\x10" );
		$old          = $this->activity->quiet_generation();
		$this->store->values[ UpdateActivity::STORAGE_KEY ] = '{"schema":1,"epoch":"x"}';

		$begin = $this->activity->begin( str_repeat( 'a', 32 ) );
		$this->assertNull( $begin['generation'], 'The order before is unknown.' );
		$this->assertFalse( $begin['others_running'] );
		$this->assertSame( 'fedcba9876543210', $this->state()['epoch'] );
		$this->activity->end( str_repeat( 'a', 32 ) );
		$this->assertStringStartsWith( 'fedcba9876543210:', $this->activity->quiet_generation() );
		$this->assertNotSame( $old, $this->activity->quiet_generation() );

		$this->assertFalse( $this->activity->end( str_repeat( 'c', 32 ) ), 'Unknown token.' );
	}

	/**
	 * Storage failures: nothing recorded, never a generation.
	 */
	public function test_storage_failures() {
		$this->store->fail_reads = true;
		$this->assertNull( $this->activity->quiet_generation() );
		$this->assertNull( $this->activity->begin( str_repeat( 'a', 32 ) ) );
		$this->assertFalse( $this->activity->end( str_repeat( 'a', 32 ) ) );

		$this->store->fail_reads  = false;
		$this->store->fail_writes = true;
		$this->assertNull( $this->activity->quiet_generation() );
		$this->assertNull( $this->activity->begin( str_repeat( 'a', 32 ) ) );
	}

	/**
	 * At most MAX_RUNNING entries are kept (the newest).
	 */
	public function test_running_is_bounded() {
		$this->activity->quiet_generation();
		for ( $i = 0; $i < UpdateActivityCodec::MAX_RUNNING + 3; $i++ ) {
			$this->now += 1;
			$this->ledger()->begin( sprintf( '%032x', $i ) );
		}

		$running = $this->state()['running'];
		$this->assertCount( UpdateActivityCodec::MAX_RUNNING, $running );
		$this->assertArrayHasKey( sprintf( '%032x', UpdateActivityCodec::MAX_RUNNING + 2 ), $running );
		$this->assertArrayNotHasKey( sprintf( '%032x', 0 ), $running );
	}

	/**
	 * Generation format.
	 */
	public function test_is_generation() {
		$this->assertTrue( UpdateActivity::is_generation( '0123456789abcdef:0' ) );
		$this->assertTrue( UpdateActivity::is_generation( '0123456789abcdef:99999999999999999' ) );
		foreach ( array( null, 7, '', '0123456789abcdef', '0123456789ABCDEF:1', '0123456789abcdef:01', '0123456789abcdef:-1', '0123456789abcde:1', '0123456789abcdef:1 ' ) as $invalid ) {
			$this->assertFalse( UpdateActivity::is_generation( $invalid ) );
		}
	}

	/**
	 * Codec: exact format, strict decoding.
	 */
	public function test_codec() {
		$codec = new UpdateActivityCodec();
		$state = array(
			'epoch'    => '0123456789abcdef',
			'sequence' => 4,
			'running'  => array( str_repeat( 'a', 32 ) => 1760090000.5 ),
		);
		$json  = $codec->encode( $state );

		$this->assertSame( '{"schema":1,"epoch":"0123456789abcdef","sequence":4,"running":{"' . str_repeat( 'a', 32 ) . '":1760090000.5}}', $json );
		$this->assertSame( $state, $codec->decode( $json ) );
		$this->assertSame(
			'{"schema":1,"epoch":"0123456789abcdef","sequence":0,"running":{}}',
			$codec->encode(
				array_merge(
					$state,
					array(
						'sequence' => 0,
						'running'  => array(),
					)
				)
			)
		);

		foreach (
			array(
				null,
				'',
				'[]',
				'{"schema":2,"epoch":"0123456789abcdef","sequence":4,"running":{}}',
				'{"schema":1,"epoch":"0123456789ABCDEF","sequence":4,"running":{}}',
				'{"schema":1,"epoch":"0123456789abcdef","sequence":-1,"running":{}}',
				'{"schema":1,"epoch":"0123456789abcdef","sequence":"4","running":{}}',
				'{"schema":1,"epoch":"0123456789abcdef","sequence":4,"running":{"plugin.php":1}}',
				'{"schema":1,"epoch":"0123456789abcdef","sequence":4,"running":{"' . str_repeat( 'a', 32 ) . '":"now"}}',
				'{"schema":1,"epoch":"0123456789abcdef","sequence":4,"running":{},"user":7}',
				'{"epoch":"0123456789abcdef","schema":1,"sequence":4,"running":{}}',
			) as $invalid
		) {
			try {
				$codec->decode( $invalid );
				$this->fail( 'Accepted: ' . var_export( $invalid, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test message.
			} catch ( UnexpectedValueException $e ) {
				$this->assertSame( 'Stored update activity is invalid.', $e->getMessage() );
			}
		}
	}
}
