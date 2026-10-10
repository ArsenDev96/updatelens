<?php
/**
 * Tests for site check sessions.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\SiteCheck;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\SiteCheck\CheckSession;
use UpdateLens\SiteCheck\RateLimiter;
use UpdateLens\SiteCheck\SessionManager;
use UpdateLens\Storage\SiteCheckSessionCodec;
use UpdateLens\Tests\Support\InMemorySessionStore;
use UpdateLens\Tests\Support\SiteCheckFixture as F;

/**
 * Issuance, lookup, single-use reports, expiry, eviction and rate limits.
 */
final class SessionManagerTest extends TestCase {

	const USER   = 7;
	const LOGIN  = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	const OTHER  = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
	const T      = 1760090000.25;
	const SECRET = 'sk_live_fake_secret_4f2a9c';

	/**
	 * Store.
	 *
	 * @var InMemorySessionStore
	 */
	private $store;

	/**
	 * Fake time.
	 *
	 * @var float
	 */
	private $time;

	/**
	 * Stored rate limit values by user.
	 *
	 * @var array<int, mixed>
	 */
	private $meta;

	/**
	 * Manager under test.
	 *
	 * @var SessionManager
	 */
	private $manager;

	/**
	 * Fresh manager with real random bytes.
	 *
	 * @before
	 */
	public function create_manager() {
		$this->store   = new InMemorySessionStore();
		$this->time    = self::T;
		$this->meta    = array();
		$this->manager = $this->manager( 'random_bytes' );
	}

	/**
	 * Manager with a random source.
	 *
	 * @param callable $random Random source.
	 * @return SessionManager
	 */
	private function manager( callable $random ) {
		return new SessionManager(
			$this->store,
			function () {
				return $this->time;
			},
			$random,
			new RateLimiter(
				function ( $user ) {
					return isset( $this->meta[ $user ] ) ? $this->meta[ $user ] : '';
				},
				function ( $user, $value ) {
					$this->meta[ $user ] = $value;
				}
			)
		);
	}

	/**
	 * Target fingerprints.
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
	 * Start for the default owner.
	 *
	 * @param string $purpose Purpose.
	 * @return array Sessions by check.
	 */
	private function start( $purpose = 'before' ) {
		$outcome = $this->manager->start( self::USER, self::LOGIN, $purpose, self::targets() );
		$this->assertSame( 'started', $outcome['code'] );

		return $outcome['sessions'];
	}

	/**
	 * Report JSON.
	 *
	 * @param string  $check    Check.
	 * @param array[] $attempts Attempt facts.
	 * @return string
	 */
	private static function report( $check, array $attempts = array() ) {
		if ( array() === $attempts ) {
			$attempts = array( 'home' === $check ? F::home() : F::rest( array( 'user_id' => self::USER ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test helper.
		return json_encode(
			array(
				'check'    => $check,
				'attempts' => $attempts,
			)
		);
	}

	/**
	 * Submit the default owner's report for a session.
	 *
	 * @param array       $session Session from start().
	 * @param string      $check   Check of the report.
	 * @param string|null $report  Report JSON.
	 * @return array Outcome.
	 */
	private function submit( array $session, $check, $report = null ) {
		return $this->manager->report( self::USER, self::LOGIN, $session['session'], $session['secret'], null === $report ? self::report( $check ) : $report );
	}

	/**
	 * One session per check, random tokens of the documented sizes, stored
	 * under a derived key without the marker or the secret.
	 */
	public function test_start_issues_one_session_per_check() {
		$outcome = $this->manager->start( self::USER, self::LOGIN, 'before', self::targets() );

		$this->assertSame( array( 'code', 'purpose', 'expires_in', 'sessions' ), array_keys( $outcome ) );
		$this->assertSame( array( 'before', 120 ), array( $outcome['purpose'], $outcome['expires_in'] ) );
		$this->assertSame( array( 'home', 'rest' ), array_keys( $outcome['sessions'] ) );

		$tokens = array();
		foreach ( $outcome['sessions'] as $session ) {
			$this->assertSame( array( 'definition', 'session', 'secret' ), array_keys( $session ) );
			$this->assertMatchesRegularExpression( '/\A[a-f0-9]{32}\z/', $session['session'] );
			$this->assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', $session['secret'] );
			$tokens[] = $session['session'];
			$tokens[] = $session['secret'];
		}
		$this->assertCount( 4, array_unique( $tokens ) );

		$this->assertCount( 2, $this->store->values );
		$this->assertSame( array( 120, 120 ), array_values( $this->store->ttls ) );
		$stored = implode( ' ', array_keys( $this->store->values ) ) . implode( ' ', $this->store->values );
		foreach ( $tokens as $token ) {
			$this->assertStringNotContainsString( $token, $stored );
		}
		$this->assertArrayHasKey( 'updatelens_sc_' . hash( 'sha256', $outcome['sessions']['home']['session'] ), $this->store->values );
	}

	/**
	 * The stored record binds check, purpose, owner, login session and target.
	 */
	public function test_session_record() {
		$sessions = $this->start( 'after' );
		$session  = $this->manager->find_live( 'rest', $sessions['rest']['session'] );

		$this->assertSame(
			array(
				'check'       => 'rest',
				'definition'  => 1,
				'purpose'     => 'after',
				'user'        => self::USER,
				'login'       => self::LOGIN,
				'target'      => str_repeat( 'c', 64 ),
				'secret_hash' => hash( 'sha256', $sessions['rest']['secret'] ),
				'created_at'  => self::T,
				'expires_at'  => self::T + 120,
			),
			$session->to_array()
		);
	}

	/**
	 * Invalid purposes or targets are rejected before the rate limit counts.
	 */
	public function test_rejects_invalid_starts() {
		foreach ( array( 'probe', '', null, array( 'before' ), 'BEFORE' ) as $purpose ) {
			$this->assertSame( array( 'code' => 'invalid_request' ), $this->manager->start( self::USER, self::LOGIN, $purpose, self::targets() ) );
		}
		$this->assertSame( array( 'code' => 'invalid_request' ), $this->manager->start( self::USER, self::LOGIN, 'before', array( 'home' => str_repeat( 'a', 64 ) ) ) );
		$this->assertSame( array( 'code' => 'invalid_request' ), $this->manager->start( self::USER, 'not-a-fingerprint', 'before', self::targets() ) );
		$this->assertSame( array(), $this->store->values );
		$this->assertCount( 1, RateLimiter::recent( $this->meta[ self::USER ], (int) self::T ), 'Only the start with a valid purpose and targets was counted.' );
	}

	/**
	 * Front-end lookup: only a live session of that check, read-only;
	 * malformed markers are rejected before any storage read.
	 */
	public function test_find_live() {
		$sessions = $this->start();
		$home     = $sessions['home']['session'];

		$this->assertSame( 'home', $this->manager->find_live( 'home', $home )->get_check() );
		$this->assertNull( $this->manager->find_live( 'rest', $home ), 'Another check’s marker.' );
		$this->assertNull( $this->manager->find_live( 'home', str_repeat( '0', 32 ) ), 'Unknown marker.' );
		$this->assertNull( $this->manager->find_live( 'home', $sessions['home']['secret'] ), 'The secret is not a marker.' );

		$reads = $this->store->reads;
		foreach ( array( strtoupper( $home ), $home . 'a', substr( $home, 1 ), "$home\n", '../' . $home, array( $home ), null, 123 ) as $malformed ) {
			$this->assertNull( $this->manager->find_live( 'home', $malformed ) );
		}
		$this->assertSame( $reads, $this->store->reads, 'Malformed markers never reach storage.' );

		$this->time = self::T + 119.9;
		$this->assertNotNull( $this->manager->find_live( 'home', $home ) );
		$this->time = self::T + 120;
		$this->assertNull( $this->manager->find_live( 'home', $home ), 'Expired.' );
		$this->assertCount( 2, $this->store->values, 'Lookups never write.' );
	}

	/**
	 * Eviction or an unreadable record makes a session unavailable: no
	 * marker, no accepted result.
	 */
	public function test_eviction_and_corruption_make_sessions_unavailable() {
		$sessions = $this->start();
		$this->store->evict_all();
		$this->assertNull( $this->manager->find_live( 'home', $sessions['home']['session'] ) );
		$this->assertSame( array( 'code' => 'session_unavailable' ), $this->submit( $sessions['home'], 'home' ) );

		$sessions = $this->start();
		$key      = CheckSession::storage_key( $sessions['home']['session'] );
		foreach ( array( '', 'not json', '{"schema":1}', '{"schema":2}', str_replace( '"user":7', '"user":"7"', $this->store->values[ $key ] ) ) as $corrupt ) {
			$this->store->values[ $key ] = $corrupt;
			$this->assertNull( $this->manager->find_live( 'home', $sessions['home']['session'] ) );
			$this->assertSame( array( 'code' => 'session_unavailable' ), $this->submit( $sessions['home'], 'home' ) );
		}
	}

	/**
	 * The owner's valid report is accepted once and becomes a result with
	 * the session's context and server times.
	 */
	public function test_accepts_one_report() {
		$sessions   = $this->start();
		$this->time = self::T + 1.5;
		$outcome    = $this->submit( $sessions['rest'], 'rest' );

		$this->assertSame( array( 'code', 'check', 'purpose', 'result' ), array_keys( $outcome ) );
		$this->assertSame( array( 'accepted', 'rest', 'before' ), array( $outcome['code'], $outcome['check'], $outcome['purpose'] ) );
		$result = $outcome['result'];
		$this->assertSame( array( 'pass', null ), array( $result->get_verdict(), $result->get_reason() ) );
		$this->assertSame( array( self::T, self::T + 1.5 ), array( $result->get_started_at(), $result->get_completed_at() ) );
		$this->assertSame( array( str_repeat( 'c', 64 ), 'user', self::USER, self::LOGIN ), array( $result->get_context()->get_target(), $result->get_context()->get_auth(), $result->get_context()->get_user(), $result->get_context()->get_session() ) );

		$this->assertSame( array( 'code' => 'session_unavailable' ), $this->submit( $sessions['rest'], 'rest' ), 'Replay.' );
		$this->assertNull( $this->manager->find_live( 'rest', $sessions['rest']['session'] ), 'Reported sessions end.' );
		$this->assertSame( 'accepted', $this->submit( $sessions['home'], 'home' )['code'], 'The other check is independent.' );
	}

	/**
	 * Of two concurrent submissions, exactly one is accepted.
	 */
	public function test_concurrent_submissions() {
		$sessions = $this->start();
		$second   = null;

		$this->store->before_claim = function () use ( $sessions, &$second ) {
			$second = $this->submit( $sessions['home'], 'home' );
		};
		$first                     = $this->submit( $sessions['home'], 'home' );

		$this->assertSame( 'accepted', $second['code'], 'The submission that claimed first wins.' );
		$this->assertSame( array( 'code' => 'already_reported' ), $first );
	}

	/**
	 * Ownership: user, login session and secret must all match.
	 */
	public function test_ownership() {
		$sessions = $this->start();
		$home     = $sessions['home'];

		$this->assertSame( array( 'code' => 'not_owner' ), $this->manager->report( 8, self::LOGIN, $home['session'], $home['secret'], self::report( 'home' ) ) );
		$this->assertSame( array( 'code' => 'not_owner' ), $this->manager->report( self::USER, self::OTHER, $home['session'], $home['secret'], self::report( 'home' ) ) );
		$this->assertSame( array( 'code' => 'session_unavailable' ), $this->manager->report( self::USER, self::LOGIN, $home['session'], $sessions['rest']['secret'], self::report( 'home' ) ), 'Another session’s secret.' );
		$this->assertSame( array( 'code' => 'session_unavailable' ), $this->manager->report( self::USER, self::LOGIN, $home['session'], str_repeat( 'f', 64 ), self::report( 'home' ) ) );
		$this->assertSame( 'accepted', $this->submit( $home, 'home' )['code'], 'Rejected attempts do not use the session up.' );
	}

	/**
	 * Malformed tokens, expired sessions and other definition versions.
	 */
	public function test_invalid_and_expired_tokens() {
		$sessions = $this->start();
		$home     = $sessions['home'];

		foreach ( array( array( strtoupper( $home['session'] ), $home['secret'] ), array( $home['session'], substr( $home['secret'], 2 ) ), array( null, $home['secret'] ), array( $home['session'], array() ), array( $home['secret'], $home['session'] ) ) as $tokens ) {
			$this->assertSame( array( 'code' => 'invalid_request' ), $this->manager->report( self::USER, self::LOGIN, $tokens[0], $tokens[1], self::report( 'home' ) ) );
		}

		$this->time = self::T + 120;
		$this->assertSame( array( 'code' => 'session_unavailable' ), $this->submit( $home, 'home' ) );

		$this->time                  = self::T;
		$codec                       = new SiteCheckSessionCodec();
		$key                         = CheckSession::storage_key( $sessions['rest']['session'] );
		$record                      = $codec->decode( $this->store->values[ $key ] )->to_array();
		$record['definition']        = 2;
		$this->store->values[ $key ] = $codec->encode( CheckSession::from_array( $record ) );
		$this->assertSame( array( 'code' => 'session_unavailable' ), $this->submit( $sessions['rest'], 'rest' ), 'Another definition version.' );
	}

	/**
	 * Invalid reports are rejected with the validator's reason and keep the
	 * session usable.
	 */
	public function test_invalid_reports() {
		$sessions = $this->start();

		$this->assertSame(
			array(
				'code'   => 'invalid_report',
				'detail' => 'check_mismatch',
			),
			$this->submit( $sessions['home'], 'home', self::report( 'rest' ) )
		);
		$this->assertSame(
			array(
				'code'   => 'invalid_report',
				'detail' => 'too_large',
			),
			$this->submit( $sessions['home'], 'home', str_repeat( ' ', 2049 ) )
		);
		$this->assertSame(
			array(
				'code'   => 'invalid_report',
				'detail' => 'invalid_report',
			),
			$this->submit( $sessions['home'], 'home', '{"check":"home","attempts":[],"secret":"' . self::SECRET . '"}' )
		);
		$this->assertSame(
			array(
				'code'   => 'invalid_report',
				'detail' => 'invalid_attempt',
			),
			$this->submit( $sessions['home'], 'home', self::report( 'home', array( F::home(), F::home() ) ) )
		);
		$this->assertSame( 'accepted', $this->submit( $sessions['home'], 'home' )['code'] );
	}

	/**
	 * The REST check validates the session's own user.
	 */
	public function test_rest_report_validates_the_session_user() {
		$sessions = $this->start();
		$outcome  = $this->submit( $sessions['rest'], 'rest', self::report( 'rest', array( F::rest( array( 'user_id' => 1 ) ), F::rest( array( 'user_id' => 1 ) ) ) ) );

		$this->assertSame( array( 'fail', 'wrong_user' ), array( $outcome['result']->get_verdict(), $outcome['result']->get_reason() ) );
	}

	/**
	 * At most 12 starts per user in a sliding 10-minute window.
	 */
	public function test_rate_limit() {
		for ( $i = 0; $i < 12; $i++ ) {
			$this->time = self::T + $i * 10;
			$this->start();
		}
		$this->time = self::T + 120;
		$this->assertSame(
			array(
				'code'        => 'rate_limited',
				'retry_after' => 480,
			),
			$this->manager->start( self::USER, self::LOGIN, 'before', self::targets() )
		);
		$this->assertSame( 'started', $this->manager->start( 8, self::LOGIN, 'before', self::targets() )['code'], 'Per user.' );

		$this->time = self::T + 600;
		$this->assertSame( 'started', $this->manager->start( self::USER, self::LOGIN, 'before', self::targets() )['code'], 'The oldest start left the window.' );
		$this->assertSame( 'rate_limited', $this->manager->start( self::USER, self::LOGIN, 'before', self::targets() )['code'] );

		$this->meta[ self::USER ] = 'garbage';
		$this->assertSame( 'started', $this->manager->start( self::USER, self::LOGIN, 'before', self::targets() )['code'], 'Unreadable data counts as no starts.' );
	}

	/**
	 * Stored start lists are bounded and pruned.
	 */
	public function test_rate_limiter_parsing() {
		$now = 10000;
		$this->assertSame( array(), RateLimiter::recent( null, $now ) );
		$this->assertSame( array(), RateLimiter::recent( '{"a":1}x', $now ) );
		$this->assertSame( array( 9500, 9990 ), RateLimiter::recent( '[9990, 9400, "9600", 9500, 10001, 1.5]', $now ) );
		$this->assertSame( array(), RateLimiter::recent( '[' . str_repeat( '9999,', 200 ) . '9999]', $now ), 'Oversized values are ignored.' );
		$this->assertCount( 12, RateLimiter::recent( '[' . implode( ',', range( 9901, 9930 ) ) . ']', $now ) );
	}

	/**
	 * Failed storage or randomness makes the start unavailable and leaves no
	 * half-started run.
	 */
	public function test_start_failures() {
		$this->store->puts_before_failure = 1;
		$this->assertSame( array( 'code' => 'unavailable' ), $this->manager->start( self::USER, self::LOGIN, 'before', self::targets() ) );
		$this->assertSame( array(), $this->store->values, 'The first session was discarded.' );

		$this->store->puts_before_failure = null;
		$failing                          = $this->manager(
			function () {
				throw new RuntimeException( 'No entropy.' );
			}
		);
		$this->assertSame( array( 'code' => 'unavailable' ), $failing->start( self::USER, self::LOGIN, 'before', self::targets() ) );

		$short = $this->manager(
			function () {
				return 'abc';
			}
		);
		$this->assertSame( array( 'code' => 'unavailable' ), $short->start( self::USER, self::LOGIN, 'before', self::targets() ) );
		$this->assertSame( array(), $this->store->values );
	}

	/**
	 * Outcomes and stored records never contain secrets or submitted data.
	 */
	public function test_no_secret_leaks() {
		$sessions = $this->start();
		$outcomes = array(
			$this->manager->report( self::USER, self::LOGIN, $sessions['home']['session'], $sessions['home']['secret'], '{"check":"home","attempts":[{"kind":"' . self::SECRET . '","duration_ms":1}]}' ),
			$this->manager->report( self::USER, self::LOGIN, $sessions['home']['session'], self::SECRET, self::report( 'home' ) ),
			$this->manager->report( 8, self::LOGIN, $sessions['home']['session'], $sessions['home']['secret'], self::report( 'home' ) ),
			$this->submit(
				$sessions['rest'],
				'rest',
				self::report(
					'rest',
					array(
						F::rest(
							array(
								'status'     => 403,
								'user_id'    => null,
								'error_code' => self::SECRET,
							)
						),
					)
				)
			),
		);
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Asserts no form holds secrets.
		$dump = print_r( $outcomes, true ) . implode( ' ', $this->store->values ) . implode( ' ', array_keys( $this->store->values ) );

		$this->assertStringNotContainsString( self::SECRET, $dump );
		foreach ( $sessions as $session ) {
			$this->assertStringNotContainsString( $session['secret'], $dump );
			$this->assertStringNotContainsString( $session['session'], $dump );
		}
	}
}
