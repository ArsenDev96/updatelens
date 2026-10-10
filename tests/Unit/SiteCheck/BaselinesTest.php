<?php
/**
 * Tests for BEFORE baselines.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\SiteCheck;

use PHPUnit\Framework\TestCase;
use UpdateLens\SiteCheck\Baselines;
use UpdateLens\SiteCheck\CheckResult;
use UpdateLens\Storage\SiteCheckResultCodec;
use UpdateLens\Tests\Support\InMemoryBaselineStore;
use UpdateLens\Tests\Support\SiteCheckFixture as F;

/**
 * Baselines: per-check readiness, latest-wins storage, concurrency,
 * corruption and cleanup.
 */
final class BaselinesTest extends TestCase {

	const LOGIN = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

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
	private $now = 1760090000.0;

	/**
	 * Baselines.
	 *
	 * @var Baselines
	 */
	private $baselines;

	/**
	 * Build the service.
	 *
	 * @before
	 */
	public function set_up_baselines() {
		$this->store     = new InMemoryBaselineStore();
		$this->baselines = new Baselines(
			$this->store,
			function () {
				return $this->now;
			}
		);
	}

	/**
	 * Current targets of both checks (F::context() defaults).
	 *
	 * @return array<string, string>
	 */
	private static function targets() {
		return array(
			'home' => str_repeat( 'a', 64 ),
			'rest' => str_repeat( 'c', 64 ),
		);
	}

	/**
	 * A BEFORE run.
	 *
	 * @param string $check     Check.
	 * @param float  $started   Session start.
	 * @param float  $completed Report accepted.
	 * @param array  $options   Context options and `facts`.
	 * @return CheckResult
	 */
	private static function before_run( $check, $started, $completed, array $options = array() ) {
		$facts = isset( $options['facts'] ) ? $options['facts'] : array( 'home' === $check ? F::home() : F::rest() );
		unset( $options['facts'] );

		return F::result(
			$check,
			$facts,
			$options + array(
				'started_at'   => $started,
				'completed_at' => $completed,
			)
		);
	}

	/**
	 * Readiness of the default identity.
	 *
	 * @return array
	 */
	private function readiness() {
		return $this->baselines->readiness( F::USER, self::LOGIN, self::targets() );
	}

	/**
	 * Without baselines both checks are missing and due.
	 */
	public function test_missing() {
		$this->assertSame(
			array(
				'home' => array(
					'state'      => 'missing',
					'age'        => null,
					'refresh_in' => 0,
				),
				'rest' => array(
					'state'      => 'missing',
					'age'        => null,
					'refresh_in' => 0,
				),
			),
			$this->readiness()
		);
		$this->assertSame( array( 'home', 'rest' ), Baselines::due( $this->readiness() ) );
	}

	/**
	 * Readiness by age, from the server-accepted completion time: fresh,
	 * due for a refresh after five minutes, expired after ten.
	 */
	public function test_readiness_by_age() {
		$this->assertSame( 'saved', $this->baselines->save( self::before_run( 'home', $this->now - 20.5, $this->now - 0.5 ) ) );

		// Seconds since now, then [ state, age, refresh_in ]; the age is 0.5 s more.
		$cases = array(
			array( 0.0, array( 'ready', 0, 300 ) ),
			array( 0.6, array( 'ready', 1, 299 ) ),
			array( 299.4, array( 'ready', 299, 1 ) ),
			array( 299.5, array( 'ready', 300, 0 ) ),
			array( 300.0, array( 'ready', 300, 0 ) ),
			array( 599.5, array( 'ready', 600, 0 ) ),
			array( 600.0, array( 'expired', 600, 0 ) ),
			array( 3600.0, array( 'expired', 3600, 0 ) ),
		);
		$base  = $this->now;
		foreach ( $cases as list( $elapsed, $expected ) ) {
			$this->now = $base + $elapsed;
			$home      = $this->readiness()['home'];
			$this->assertSame( $expected, array( $home['state'], $home['age'], $home['refresh_in'] ), "after $elapsed s" );
			$this->assertSame( 'missing', $this->readiness()['rest']['state'], 'REST is independent.' );
		}

		$this->now = $base;
		$this->assertSame(
			array(
				'home' => 300,
				'rest' => 0,
			),
			Baselines::refresh_in( $this->readiness() )
		);
		$this->assertSame( array( 'rest' ), Baselines::due( $this->readiness() ) );
	}

	/**
	 * A server clock slightly behind the accepted time never gives a negative age.
	 */
	public function test_clock_skew() {
		$this->baselines->save( self::before_run( 'home', $this->now, $this->now + 2.0 ) );

		$this->assertSame( array( 'ready', 0, 300 ), array_values( $this->readiness()['home'] ) );
	}

	/**
	 * Another definition version or target is not a baseline for the current one.
	 */
	public function test_other_definition_or_target_is_missing() {
		$this->baselines->save( self::before_run( 'home', $this->now - 2, $this->now - 1, array( 'target' => str_repeat( 'd', 64 ) ) ) );
		$this->assertSame( 'missing', $this->readiness()['home']['state'], 'The site URL changed.' );

		$codec                = new SiteCheckResultCodec();
		$stored               = json_decode( $codec->encode( self::before_run( 'rest', $this->now - 2, $this->now - 1 ) ), true );
		$stored['definition'] = 2;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test fixture.
		$this->store->values[ Baselines::storage_key( F::USER, self::LOGIN, 'rest' ) ] = json_encode( $stored, JSON_PRESERVE_ZERO_FRACTION );
		$this->assertNotNull( $this->baselines->find( F::USER, self::LOGIN, 'rest' ) );
		$this->assertSame( 'missing', $this->readiness()['rest']['state'], 'Another definition version.' );
	}

	/**
	 * The latest run wins whatever the verdict; an older run never replaces
	 * a newer one (reports that arrive out of order).
	 */
	public function test_latest_run_wins() {
		$pass = self::before_run( 'home', $this->now - 30, $this->now - 29 );
		$fail = self::before_run( 'home', $this->now - 10, $this->now - 4, array( 'facts' => array( F::home( array( 'status' => 500 ) ), F::home( array( 'status' => 500 ) ) ) ) );

		$this->assertSame( 'saved', $this->baselines->save( $pass ) );
		$this->assertSame( 'saved', $this->baselines->save( $fail ), 'A newer failure replaces an older pass.' );
		$this->assertSame( 'superseded', $this->baselines->save( $pass ) );
		$this->assertSame( 'fail', $this->baselines->find( F::USER, self::LOGIN, 'home' )->get_verdict() );

		// Same start: the one accepted later wins; an identical run is not newer.
		$later = self::before_run( 'home', $this->now - 10, $this->now - 2 );
		$this->assertSame( 'superseded', $this->baselines->save( $fail ) );
		$this->assertSame( 'saved', $this->baselines->save( $later ) );
		$this->assertTrue( Baselines::is_newer( $later, $fail ) );
		$this->assertFalse( Baselines::is_newer( $fail, $later ) );
		$this->assertFalse( Baselines::is_newer( $later, $later ) );
	}

	/**
	 * The stored times are the run's own: reuse never re-stamps them.
	 */
	public function test_preserves_timestamps() {
		$run = self::before_run( 'rest', 1760089990.125, 1760089991.875 );
		$this->baselines->save( $run );
		$this->now += 200;
		$this->readiness();

		$stored = $this->baselines->find( F::USER, self::LOGIN, 'rest' );
		$this->assertSame( array( 1760089990.125, 1760089991.875 ), array( $stored->get_started_at(), $stored->get_completed_at() ) );
		$this->assertSame( ( new SiteCheckResultCodec() )->encode( $run ), $this->store->values[ Baselines::storage_key( F::USER, self::LOGIN, 'rest' ) ] );
	}

	/**
	 * Checks, users and login sessions never share or overwrite an entry.
	 */
	public function test_identity_isolation() {
		$other_login = str_repeat( 'e', 64 );
		$this->baselines->save( self::before_run( 'home', $this->now - 5, $this->now - 4 ) );
		$this->baselines->save( self::before_run( 'rest', $this->now - 5, $this->now - 1 ) );
		$this->baselines->save( self::before_run( 'home', $this->now - 3, $this->now - 2, array( 'session' => $other_login ) ) );
		$this->baselines->save( self::before_run( 'home', $this->now - 3, $this->now - 2, array( 'user' => 8 ) ) );

		$this->assertCount( 4, $this->store->values );
		$this->assertSame( array( 'ready', 'ready' ), array_column( $this->readiness(), 'state' ) );
		$this->assertSame( (float) $this->now - 4, $this->baselines->find( F::USER, self::LOGIN, 'home' )->get_completed_at() );
		$this->assertSame( 'missing', $this->baselines->readiness( 9, self::LOGIN, self::targets() )['home']['state'] );
		$this->assertSame( 'missing', $this->baselines->readiness( F::USER, str_repeat( 'f', 64 ), self::targets() )['home']['state'] );

		// An entry whose content belongs to another identity is not used.
		$key                         = Baselines::storage_key( F::USER, self::LOGIN, 'rest' );
		$this->store->values[ $key ] = ( new SiteCheckResultCodec() )->encode( self::before_run( 'rest', $this->now - 5, $this->now - 1, array( 'user' => 8 ) ) );
		$this->assertNull( $this->baselines->find( F::USER, self::LOGIN, 'rest' ) );
		$this->assertSame( 'missing', $this->readiness()['rest']['state'] );
		$this->assertSame( 'saved', $this->baselines->save( self::before_run( 'rest', $this->now - 9, $this->now - 8 ) ), 'It is replaced, even by an older run.' );
	}

	/**
	 * Malformed stored baselines are missing and are replaced by the next run.
	 */
	public function test_malformed_stored_baseline() {
		$key = Baselines::storage_key( F::USER, self::LOGIN, 'home' );
		foreach ( array( '', 'not json', '{"schema":99}', '{"schema":1}', str_repeat( 'x', 5000 ), '[]' ) as $malformed ) {
			$this->store->values[ $key ] = $malformed;
			$this->assertNull( $this->baselines->find( F::USER, self::LOGIN, 'home' ) );
			$this->assertSame( 'missing', $this->readiness()['home']['state'] );
		}
		$this->assertSame( 'saved', $this->baselines->save( self::before_run( 'home', $this->now - 2, $this->now - 1 ) ) );
		$this->assertSame( 'ready', $this->readiness()['home']['state'] );
	}

	/**
	 * Concurrent submissions: a newer run written between our read and our
	 * write is kept; an older one is replaced on the retry.
	 */
	public function test_concurrent_writes() {
		$key   = Baselines::storage_key( F::USER, self::LOGIN, 'home' );
		$codec = new SiteCheckResultCodec();
		$old   = self::before_run( 'home', $this->now - 40, $this->now - 39 );
		$mine  = self::before_run( 'home', $this->now - 20, $this->now - 19 );
		$newer = self::before_run( 'home', $this->now - 10, $this->now - 9 );

		// First write: another request creates the entry first with a newer run.
		$this->store->before_write = function () use ( $key, $codec, $newer ) {
			$this->store->values[ $key ] = $codec->encode( $newer );
		};
		$this->assertSame( 'superseded', $this->baselines->save( $mine ) );
		$this->assertSame( $codec->encode( $newer ), $this->store->values[ $key ] );

		// Replace: another request writes an older run in between; we retry and win.
		$this->store->values       = array( $key => $codec->encode( $old ) );
		$this->store->before_write = function () use ( $key, $codec ) {
			$this->store->values[ $key ] = $codec->encode( self::before_run( 'home', $this->now - 30, $this->now - 29 ) );
		};
		$this->assertSame( 'saved', $this->baselines->save( $mine ) );
		$this->assertSame( $codec->encode( $mine ), $this->store->values[ $key ] );

		// Writing one check never touches the other.
		$this->baselines->save( self::before_run( 'rest', $this->now - 5, $this->now - 4 ) );
		$this->assertSame( $codec->encode( $mine ), $this->store->values[ $key ] );
	}

	/**
	 * Interleaved reports of both checks of one run and of two tabs: each
	 * check keeps its newest run.
	 */
	public function test_interleaved_tabs() {
		$tab_a_home = self::before_run( 'home', $this->now - 12, $this->now - 11 );
		$tab_b_home = self::before_run( 'home', $this->now - 10, $this->now - 9.5 );
		$tab_b_rest = self::before_run( 'rest', $this->now - 10, $this->now - 9 );
		$tab_a_rest = self::before_run( 'rest', $this->now - 12, $this->now - 1 );

		foreach ( array( $tab_b_home, $tab_a_home, $tab_a_rest, $tab_b_rest ) as $result ) {
			$this->baselines->save( $result );
		}

		$this->assertSame( (float) $this->now - 10, $this->baselines->find( F::USER, self::LOGIN, 'home' )->get_started_at() );
		$this->assertSame( (float) $this->now - 10, $this->baselines->find( F::USER, self::LOGIN, 'rest' )->get_started_at(), 'Tab A\'s slow REST run finished last but started first.' );
	}

	/**
	 * Storage failures: never stored, never a ready baseline.
	 */
	public function test_storage_failures() {
		$this->store->fail_writes = true;
		$this->assertSame( 'failed', $this->baselines->save( self::before_run( 'home', $this->now - 2, $this->now - 1 ) ) );
		$this->assertSame( array(), $this->store->values );

		$this->store->fail_writes = false;
		$this->baselines->save( self::before_run( 'home', $this->now - 2, $this->now - 1 ) );
		$this->store->fail_reads = true;
		$this->assertSame( 'failed', $this->baselines->save( self::before_run( 'home', $this->now - 1, $this->now ) ) );
		$this->assertSame( 'missing', $this->readiness()['home']['state'], 'An unreadable store has no baseline.' );
	}

	/**
	 * Saving removes expired and unreadable entries of every user, never a
	 * usable one, and never the entry it wrote.
	 */
	public function test_cleanup() {
		$codec               = new SiteCheckResultCodec();
		$expired             = Baselines::storage_key( 8, self::LOGIN, 'home' );
		$corrupt             = Baselines::STORAGE_PREFIX . str_repeat( '0', 64 );
		$usable              = Baselines::storage_key( 9, self::LOGIN, 'rest' );
		$at_limit            = Baselines::storage_key( 10, self::LOGIN, 'rest' );
		$this->store->values = array(
			$expired  => $codec->encode( self::before_run( 'home', $this->now - 700, $this->now - 601, array( 'user' => 8 ) ) ),
			$corrupt  => 'garbage',
			$usable   => $codec->encode( self::before_run( 'rest', $this->now - 300, $this->now - 299, array( 'user' => 9 ) ) ),
			$at_limit => $codec->encode( self::before_run( 'rest', $this->now - 601, $this->now - 600, array( 'user' => 10 ) ) ),
		);

		$this->baselines->save( self::before_run( 'home', $this->now - 2, $this->now - 1 ) );

		$this->assertEqualsCanonicalizing( array( $usable, $at_limit, Baselines::storage_key( F::USER, self::LOGIN, 'home' ) ), array_keys( $this->store->values ) );
	}

	/**
	 * Keys are fixed-length, prefixed and distinct per identity; stored
	 * values hold facts and fingerprints only.
	 */
	public function test_storage_key_and_content() {
		$key = Baselines::storage_key( F::USER, self::LOGIN, 'home' );
		$this->assertMatchesRegularExpression( '/\Aupdatelens_site_check_baseline_[a-f0-9]{64}\z/', $key );
		$this->assertLessThanOrEqual( 191, strlen( $key ), 'Fits wp_options.option_name.' );
		$this->assertNotSame( $key, Baselines::storage_key( F::USER, self::LOGIN, 'rest' ) );
		$this->assertNotSame( $key, Baselines::storage_key( 8, self::LOGIN, 'home' ) );
		$this->assertNotSame( $key, Baselines::storage_key( F::USER, str_repeat( 'e', 64 ), 'home' ) );

		$this->baselines->save( self::before_run( 'home', $this->now - 2, $this->now - 1 ) );
		$this->assertSame(
			array( 'schema', 'check', 'definition', 'target', 'auth', 'user', 'session', 'started_at', 'completed_at', 'attempts' ),
			array_keys( json_decode( $this->store->values[ $key ], true ) )
		);
		$this->assertSame( 600, Baselines::MAX_AGE_SECONDS );
		$this->assertSame( 300, Baselines::REFRESH_AFTER_SECONDS );
	}
}
