<?php
/**
 * Tests for AFTER site checks and their comparison with the BEFORE baselines.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use UpdateLens\Report\SiteCheckReport;
use UpdateLens\SiteCheck\Baselines;
use UpdateLens\SiteCheck\CheckResult;
use UpdateLens\SiteCheck\CheckSession;
use UpdateLens\SiteCheck\RateLimiter;
use UpdateLens\SiteCheck\SessionManager;
use UpdateLens\SiteCheck\UpdateActivity;
use UpdateLens\Storage\SiteCheckResultCodec;
use UpdateLens\Tests\Support\FakeSite;
use UpdateLens\Tests\Support\InMemoryBaselineStore;
use UpdateLens\Tests\Support\InMemorySessionStore;
use UpdateLens\Tests\Support\SiteCheckFixture as F;
use UpdateLens\Update\FollowUpRequest;
use UpdateLens\Update\PluginUpdateAnalyzer;
use UpdateLens\Update\SiteCheckAfter;
use UpdateLens\Update\SiteCheckObservation;

/**
 * The whole site check lifecycle around a plugin update: BEFORE baselines
 * attached at the update start, the follow-up that settles the analysis,
 * the AFTER run bound to that analysis (real sessions, the real updater
 * activity ledger), and the comparison read back from the stored row.
 */
final class SiteCheckAfterLifecycleTest extends TestCase {

	const PLUGIN = 'acme/acme.php';
	const OTHER  = 'other/other.php';
	const LOGIN  = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	const LOGIN2 = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
	const T      = 1767225600; // 2026-01-01T00:00:00Z.

	/**
	 * Site stand-in (options, analyses).
	 *
	 * @var FakeSite
	 */
	private $site;

	/**
	 * Option rows shared by baselines and the ledger.
	 *
	 * @var InMemoryBaselineStore
	 */
	private $store;

	/**
	 * Updater activity ledger.
	 *
	 * @var UpdateActivity
	 */
	private $activity;

	/**
	 * Baselines.
	 *
	 * @var Baselines
	 */
	private $baselines;

	/**
	 * Context of the update request (SiteCheckObservation).
	 *
	 * @var array
	 */
	private $context;

	/**
	 * AFTER side.
	 *
	 * @var SiteCheckAfter
	 */
	private $after;

	/**
	 * Check sessions.
	 *
	 * @var SessionManager
	 */
	private $manager;

	/**
	 * Session storage.
	 *
	 * @var InMemorySessionStore
	 */
	private $sessions;

	/**
	 * Build the site.
	 *
	 * @before
	 */
	public function set_up_site() {
		$this->site      = new FakeSite( array( 'acme_version' => array( '1.0.0', 'yes' ) ), self::T );
		$this->store     = new InMemoryBaselineStore();
		$clock           = function () {
			return (float) $this->site->time;
		};
		$this->activity  = new UpdateActivity( $this->store, $clock, 'random_bytes' );
		$this->baselines = new Baselines( $this->store, $clock );
		$this->after     = new SiteCheckAfter( $this->site->repository, $this->activity, $clock );
		$this->sessions  = new InMemorySessionStore();
		$meta            = array();
		$this->manager   = new SessionManager(
			$this->sessions,
			$clock,
			'random_bytes',
			new RateLimiter(
				function ( $user ) use ( &$meta ) {
					return isset( $meta[ $user ] ) ? $meta[ $user ] : '';
				},
				function ( $user, $value ) use ( &$meta ) {
					$meta[ $user ] = $value;
				}
			)
		);
		$this->context   = array(
			'enabled' => true,
			'login'   => self::LOGIN,
			'targets' => array(
				'home' => str_repeat( 'a', 64 ),
				'rest' => str_repeat( 'c', 64 ),
			),
		);

		$this->site->site_checks = new SiteCheckObservation(
			function () {
				return $this->context;
			},
			$this->baselines
		);
	}

	/**
	 * A BEFORE run that starts now and is stored $options['duration'] later.
	 *
	 * @param string $check   Check.
	 * @param array  $options `user`, `session`, `facts`, `duration`.
	 * @return CheckResult
	 */
	private function baseline( $check, array $options = array() ) {
		$options         += array(
			'user'     => 7,
			'session'  => self::LOGIN,
			'facts'    => array( 'home' === $check ? F::home() : F::rest() ),
			'duration' => 1.0,
		);
		$started          = (float) $this->site->time;
		$generation       = $this->activity->quiet_generation();
		$this->site->time = $started + $options['duration'];
		$result           = F::result(
			$check,
			$options['facts'],
			array(
				'user'         => $options['user'],
				'session'      => $options['session'],
				'started_at'   => $started,
				'completed_at' => $started + $options['duration'],
				'generation'   => $generation,
			)
		);
		$this->assertSame( Baselines::SAVED, $this->baselines->save( $result ) );

		return $result;
	}

	/**
	 * Both baselines, then a few seconds until the update.
	 *
	 * @param int    $user  Administrator.
	 * @param string $login Login session.
	 * @return void
	 */
	private function baselines( $user = 7, $login = self::LOGIN ) {
		$this->baseline(
			'home',
			array(
				'user'    => $user,
				'session' => $login,
			)
		);
		$this->baseline(
			'rest',
			array(
				'user'    => $user,
				'session' => $login,
			)
		);
		$this->site->time += 5;
	}

	/**
	 * Start an update request (PluginUpdateTracker: ledger first, then the analysis).
	 *
	 * @param string $plugin Plugin file.
	 * @param int    $user   Administrator.
	 * @param string $login  Login session.
	 * @return array{request: PluginUpdateAnalyzer, token: string, plugin: string, id: int|null}
	 */
	private function begin_update( $plugin = self::PLUGIN, $user = 7, $login = self::LOGIN ) {
		$request                = $this->site->request();
		$token                  = $this->activity->new_token();
		$this->context['login'] = $login;
		$request->update_starting(
			$plugin,
			array(
				'name'    => 'Acme',
				'version' => '1.0.0',
			),
			$user,
			$this->activity->begin( $token )
		);
		$open = $this->site->repository->find_open_for_plugin( $plugin );

		return array(
			'request' => $request,
			'token'   => $token,
			'plugin'  => $plugin,
			'id'      => null === $open ? null : (int) $open['id'],
		);
	}

	/**
	 * Finish an update request (result, shutdown, end of updater activity).
	 *
	 * @param array       $update begin_update() result.
	 * @param string|null $error  WordPress error code, or null on success.
	 * @return void
	 */
	private function finish_update( array $update, $error = null ) {
		$this->site->time += 2;
		$update['request']->update_finished( $update['plugin'], $error, null === $error ? '1.1.0' : null );
		$update['request']->request_ending( true );
		$this->activity->end( $update['token'] );
	}

	/**
	 * A whole update request.
	 *
	 * @param string $plugin Plugin file.
	 * @param int    $user   Administrator.
	 * @param string $login  Login session.
	 * @return int Analysis ID.
	 */
	private function update( $plugin = self::PLUGIN, $user = 7, $login = self::LOGIN ) {
		$update = $this->begin_update( $plugin, $user, $login );
		$this->finish_update( $update );

		return $update['id'];
	}

	/**
	 * The browser's follow-up request a second later.
	 *
	 * @param string[] $plugins Plugins.
	 * @param int      $user    User.
	 * @return array{results: array<string, string>, settled: array<string, int>}
	 */
	private function follow_up( array $plugins = array( self::PLUGIN ), $user = 7 ) {
		$this->site->time += 1;
		$settled           = array();
		$results           = $this->site->request()->settle_follow_up( $plugins, $user, array(), $settled );

		return array(
			'results' => $results,
			'settled' => $settled,
		);
	}

	/**
	 * Update + follow-up: the analysis is completed by the follow-up.
	 *
	 * @return int Analysis ID.
	 */
	private function settled_update() {
		$id     = $this->update();
		$follow = $this->follow_up();
		$this->assertSame( array( self::PLUGIN => $id ), $follow['settled'], 'The follow-up names exactly the analysis it settled.' );

		return $id;
	}

	/**
	 * Ask to start the AFTER run and open its sessions (AjaxEndpoints does both).
	 *
	 * @param int    $id    Analysis ID.
	 * @param int    $user  Administrator.
	 * @param string $login Login session.
	 * @return array SiteCheckAfter::open() result, with `sessions` when opened.
	 */
	private function open_after( $id, $user = 7, $login = self::LOGIN ) {
		$this->site->time += 0.25;
		$opened            = $this->after->open( $id, $user, $login );
		if ( SiteCheckAfter::OPEN !== $opened['code'] ) {
			return $opened;
		}
		$started = $this->manager->start( $user, $login, CheckSession::PURPOSE_AFTER, array_intersect_key( $this->context['targets'], array_flip( $opened['checks'] ) ), $opened['generation'], $id );
		$this->assertSame( SessionManager::STARTED, $started['code'] );

		return $opened + array( 'sessions' => $started['sessions'] );
	}

	/**
	 * Report one check of an opened run (AjaxEndpoints::on_report()).
	 *
	 * @param array  $opened   open_after() result.
	 * @param string $check    Check.
	 * @param array  $attempts Facts of each attempt.
	 * @param int    $user     Administrator.
	 * @param string $login    Login session.
	 * @return string SiteCheckAfter::record() outcome or the SessionManager code.
	 */
	private function report_after( array $opened, $check, array $attempts, $user = 7, $login = self::LOGIN ) {
		$session = $opened['sessions'][ $check ];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test input.
		$report  = json_encode(
			array(
				'check'    => $check,
				'attempts' => $attempts,
			)
		);
		$outcome = $this->manager->report( $user, $login, $session['session'], $session['secret'], $report );
		if ( SessionManager::ACCEPTED !== $outcome['code'] ) {
			return $outcome['code'];
		}

		return $this->after->record( $outcome['analysis'], $user, $outcome['result'] );
	}

	/**
	 * A complete AFTER run: open, both checks observed for 1.5 s, both reported.
	 *
	 * @param int   $id    Analysis ID.
	 * @param array $facts Attempts per check (default: both pass).
	 * @return array open_after() result.
	 */
	private function run_after( $id, array $facts = array() ) {
		$facts += array(
			'home' => array( F::home() ),
			'rest' => array( F::rest() ),
		);
		$opened = $this->open_after( $id );
		if ( SiteCheckAfter::OPEN === $opened['code'] ) {
			$this->site->time += 1.5;
			foreach ( $opened['checks'] as $check ) {
				$this->assertSame( SiteCheckAfter::SAVED, $this->report_after( $opened, $check, $facts[ $check ] ) );
			}
		}

		return $opened;
	}

	/**
	 * The report's site checks.
	 *
	 * @param int $id Analysis ID.
	 * @return array
	 */
	private function report( $id ) {
		return SiteCheckReport::build( $this->site->repository->find_report( $id ), (float) $this->site->time );
	}

	/**
	 * `status` and per check `state/reason`.
	 *
	 * @param int $id Analysis ID.
	 * @return array
	 */
	private function states( $id ) {
		$report = $this->report( $id );
		$states = array( 'status' => $report['status'] );
		foreach ( $report['checks'] as $check => $entry ) {
			$states[ $check ] = $entry['state'] . ( null === $entry['reason'] ? '' : '/' . $entry['reason'] );
		}

		return $states;
	}

	/**
	 * Two confirmed failing attempts.
	 *
	 * @param array $facts Facts of each attempt.
	 * @return array[]
	 */
	private static function twice( array $facts ) {
		return array( $facts, $facts );
	}

	/**
	 * A normal update with both checks passing before and after.
	 */
	public function test_both_checks_pass() {
		$this->baselines();
		$id = $this->settled_update();
		$this->assertTrue( $this->after->wanted( $id ), 'The follow-up response asks the browser to start.' );

		$this->assertSame(
			array(
				'status' => SiteCheckReport::PENDING,
				'home'   => 'pending',
				'rest'   => 'pending',
			),
			$this->states( $id ),
			'Waiting for the AFTER start.'
		);

		$opened = $this->run_after( $id );
		$this->assertSame( array( 'home', 'rest' ), $opened['checks'] );
		$this->assertFalse( $this->after->wanted( $id ), 'Started once.' );
		$this->assertSame(
			array(
				'status' => SiteCheckReport::COMPLETE,
				'home'   => 'passed_both',
				'rest'   => 'passed_both',
			),
			$this->states( $id )
		);

		$home = $this->report( $id )['checks']['home'];
		$this->assertSame( array( 'state', 'reason', 'before', 'after' ), array_keys( $home ) );
		$this->assertSame( array( 'available', 'reason', 'started_at', 'completed_at', 'result', 'age_seconds' ), array_keys( $home['before'] ) );
		$this->assertSame( array( 'available', 'reason', 'started_at', 'completed_at', 'result', 'delay_seconds' ), array_keys( $home['after'] ) );
		$this->assertSame( array( true, null, '2026-01-01T00:00:00Z', '2026-01-01T00:00:01Z', 6 ), array( $home['before']['available'], $home['before']['reason'], $home['before']['started_at'], $home['before']['completed_at'], $home['before']['age_seconds'] ) );
		$this->assertSame( array( true, null, '2026-01-01T00:00:10Z', '2026-01-01T00:00:11Z', 0 ), array( $home['after']['available'], $home['after']['reason'], $home['after']['started_at'], $home['after']['completed_at'], $home['after']['delay_seconds'] ) );
		$this->assertSame( array( 'verdict', 'reason', 'attempts' ), array_keys( $home['after']['result'] ) );
		$this->assertSame(
			array(
				'outcome'     => 'success',
				'status'      => 200,
				'header'      => 'current',
				'end_marker'  => 'current',
				'duration_ms' => 912,
				'origin'      => 'verified',
				'verdict'     => 'pass',
				'reason'      => null,
			),
			$home['after']['result']['attempts'][0]
		);
	}

	/**
	 * Browser-observed failures confirmed by both attempts after a passing
	 * baseline are newly failing; each check on its own.
	 */
	public function test_newly_failing_checks() {
		$cases = array(
			'homepage HTTP 500'   => array( 'home', self::twice( F::home( array( 'status' => 500 ) ) ), 'server_error' ),
			'incomplete homepage' => array( 'home', self::twice( F::home( array( 'end_marker' => 'absent' ) ) ), 'incomplete' ),
			'homepage timeouts'   => array( 'home', self::twice( F::no_response( 'timeout' ) ), 'timeout' ),
			'invalid REST JSON'   => array(
				'rest',
				self::twice(
					F::rest(
						array(
							'body'    => 'invalid_json',
							'user_id' => null,
						)
					)
				),
				'invalid_json',
			),
			'REST timeouts'       => array( 'rest', self::twice( F::no_response( 'timeout' ) ), 'timeout' ),
		);
		foreach ( $cases as $name => $case ) {
			$this->set_up_site();
			$this->baselines();
			$id = $this->settled_update();
			$this->run_after( $id, array( $case[0] => $case[1] ) );

			$other = 'home' === $case[0] ? 'rest' : 'home';
			$this->assertEquals(
				array(
					'status' => SiteCheckReport::COMPLETE,
					$case[0] => 'newly_failing',
					$other   => 'passed_both',
				),
				array_intersect_key( $this->states( $id ), array_flip( array( 'status', $case[0], $other ) ) ),
				$name
			);
			$after = $this->report( $id )['checks'][ $case[0] ]['after']['result'];
			$this->assertSame( array( 'fail', $case[2] ), array( $after['verdict'], $after['reason'] ), $name );
		}
	}

	/**
	 * A failure followed by a passing retry, maintenance mode, a cached page
	 * or a firewall stay inconclusive: never compared, never passing.
	 */
	public function test_inconclusive_after_runs() {
		$cases = array(
			'failure then pass' => array( 'home', array( F::home( array( 'status' => 500 ) ), F::home() ), 'unconfirmed' ),
			'maintenance mode'  => array(
				'home',
				self::twice(
					F::home(
						array(
							'status'      => 503,
							'retry_after' => true,
							'header'      => 'absent',
							'end_marker'  => 'absent',
						)
					)
				),
				'unavailable_retry_after',
			),
			'cached page'       => array(
				'home',
				array(
					F::home(
						array(
							'header'     => 'absent',
							'end_marker' => 'other',
						)
					),
				),
				'stale_marker',
			),
			'REST blocked'      => array(
				'rest',
				self::twice(
					F::rest(
						array(
							'status'  => 403,
							'header'  => 'absent',
							'body'    => 'empty',
							'user_id' => null,
						)
					)
				),
				'blocked',
			),
		);
		foreach ( $cases as $name => $case ) {
			$this->set_up_site();
			$this->baselines();
			$id = $this->settled_update();
			$this->run_after( $id, array( $case[0] => $case[1] ) );

			$this->assertSame( 'not_comparable/after_inconclusive', $this->states( $id )[ $case[0] ], $name );
			$this->assertSame( SiteCheckReport::PARTIAL, $this->states( $id )['status'], $name );
			$after = $this->report( $id )['checks'][ $case[0] ]['after']['result'];
			$this->assertSame( array( 'inconclusive', $case[2] ), array( $after['verdict'], $after['reason'] ), $name );
		}
	}

	/**
	 * Without any BEFORE baseline nothing runs after the update.
	 */
	public function test_no_baseline() {
		$id = $this->settled_update();

		$this->assertFalse( $this->after->wanted( $id ) );
		$this->assertSame(
			array(
				'code'   => SiteCheckAfter::NOT_NEEDED,
				'reason' => 'no_baseline',
			),
			$this->after->open( $id, 7, self::LOGIN )
		);
		$this->assertNull( $this->site->repository->rows[ $id ]['site_check_after_started_at'], 'Nothing claimed.' );
		$this->assertSame(
			array(
				'status' => SiteCheckReport::UNAVAILABLE,
				'home'   => 'not_run/baseline_missing',
				'rest'   => 'not_run/baseline_missing',
			),
			$this->states( $id )
		);
		$home = $this->report( $id )['checks']['home'];
		$this->assertSame( array( false, 'missing', false, 'no_baseline' ), array( $home['before']['available'], $home['before']['reason'], $home['after']['available'], $home['after']['reason'] ) );
	}

	/**
	 * A stale baseline is never attached, so its check is not run after.
	 */
	public function test_stale_baseline() {
		$this->baseline( 'home' );
		$this->site->time += 594;
		$this->baseline( 'rest' );
		$this->site->time += 10; // Home is 605 s old when the update starts.
		$id                = $this->settled_update();

		$opened = $this->run_after( $id );
		$this->assertSame( array( 'rest' ), $opened['checks'], 'Only REST has a baseline.' );
		$this->assertSame(
			array(
				'status' => SiteCheckReport::PARTIAL,
				'home'   => 'not_run/baseline_missing',
				'rest'   => 'passed_both',
			),
			$this->states( $id )
		);
		$this->assertSame( array( 'expired', 'no_baseline' ), array( $this->report( $id )['checks']['home']['before']['reason'], $this->report( $id )['checks']['home']['after']['reason'] ) );
	}

	/**
	 * An update by another administrator after this update ended, before the
	 * AFTER start: nothing runs, the overlap is stored.
	 */
	public function test_update_between_end_and_after_start() {
		$this->baselines();
		$id = $this->settled_update();
		$this->update( self::OTHER, 8, self::LOGIN2 );

		$this->assertSame(
			array(
				'code'   => SiteCheckAfter::NOT_NEEDED,
				'reason' => 'overlapping_update',
			),
			$this->open_after( $id )
		);
		$this->assertSame(
			array(
				'status' => SiteCheckReport::UNAVAILABLE,
				'home'   => 'not_comparable/overlapping_update',
				'rest'   => 'not_comparable/overlapping_update',
			),
			$this->states( $id )
		);
		$this->assertSame( array( 'site_check_home_after_reason' => 'overlapping_update' ), array_intersect_key( $this->site->repository->rows[ $id ], array( 'site_check_home_after_reason' => 1 ) ) );
		$this->assertSame( SiteCheckAfter::ALREADY_STARTED, $this->after->open( $id, 7, self::LOGIN )['code'] );
	}

	/**
	 * Another update still running when the AFTER run would start.
	 */
	public function test_update_running_at_after_start() {
		$this->baselines();
		$id      = $this->settled_update();
		$running = $this->activity->new_token();
		$this->activity->begin( $running ); // E.g. a theme or translation update, or WP-CLI.

		$this->assertSame( 'overlapping_update', $this->open_after( $id )['reason'] );
		$this->activity->end( $running );
		$this->assertSame( 'not_comparable/overlapping_update', $this->states( $id )['home'] );
	}

	/**
	 * Another update begins while the AFTER requests are in flight: the
	 * results are kept with the overlap and never compared, even a failure.
	 */
	public function test_update_during_after_requests() {
		$this->baselines();
		$id     = $this->settled_update();
		$opened = $this->open_after( $id );
		$this->assertSame( SiteCheckAfter::OPEN, $opened['code'] );

		$this->site->time += 0.5;
		$other             = $this->begin_update( self::OTHER, 8, self::LOGIN2 );
		$this->site->time += 1;
		$this->assertSame( SiteCheckAfter::SAVED, $this->report_after( $opened, 'home', self::twice( F::home( array( 'status' => 500 ) ) ) ) );
		$this->finish_update( $other );
		$this->assertSame( SiteCheckAfter::SAVED, $this->report_after( $opened, 'rest', array( F::rest() ) ) );

		$this->assertSame(
			array(
				'status' => SiteCheckReport::UNAVAILABLE,
				'home'   => 'not_comparable/overlapping_update',
				'rest'   => 'not_comparable/overlapping_update',
			),
			$this->states( $id ),
			'A possible effect of the other update is never a newly failing check.'
		);
		$home = $this->report( $id )['checks']['home']['after'];
		$this->assertSame( array( true, 'overlapping_update', 'fail' ), array( $home['available'], $home['reason'], $home['result']['verdict'] ), 'The observed facts are kept.' );
	}

	/**
	 * Two administrators updating at the same time: the first update's
	 * baselines are attached, but its AFTER run sees the other update.
	 */
	public function test_concurrent_administrators() {
		$this->baselines();
		$this->baselines( 8, self::LOGIN2 );
		$first             = $this->begin_update();
		$this->site->time += 0.5;
		$second            = $this->begin_update( self::OTHER, 8, self::LOGIN2 );
		$this->finish_update( $first );
		$this->finish_update( $second );
		$this->follow_up();
		$this->follow_up( array( self::OTHER ), 8 );

		$this->assertSame(
			array(
				'home' => 'attached',
				'rest' => 'attached',
			),
			$this->attached( $first['id'] )
		);
		$this->assertSame(
			array(
				'home' => 'overlapping_update',
				'rest' => 'overlapping_update',
			),
			$this->attached( $second['id'] )
		);
		$this->assertSame( 'overlapping_update', $this->open_after( $first['id'] )['reason'] );
		$this->assertSame( 'no_baseline', $this->open_after( $second['id'], 8, self::LOGIN2 )['reason'] );
		$this->assertSame( 'not_comparable/overlapping_update', $this->states( $first['id'] )['rest'] );
		$this->assertSame( 'not_run/baseline_missing', $this->states( $second['id'] )['rest'] );
	}

	/**
	 * Queued updates: only the last is settled by the follow-up, and its
	 * baselines were already used by the first.
	 */
	public function test_queued_updates() {
		$this->baselines();
		$first  = $this->update();
		$second = $this->update( self::OTHER );
		$follow = $this->follow_up( array( self::PLUGIN, self::OTHER ) );

		$this->assertSame( array( self::OTHER => $second ), $follow['settled'] );
		$this->assertSame( FollowUpRequest::NOT_PENDING, $follow['results'][ self::PLUGIN ], 'Settled by the next update.' );
		$this->assertFalse( $this->after->wanted( $first ) );
		$this->assertFalse( $this->after->wanted( $second ) );
		$this->assertSame( SiteCheckAfter::NOT_ELIGIBLE, $this->after->open( $first, 7, self::LOGIN )['code'] );
		$this->assertSame( 'no_baseline', $this->after->open( $second, 7, self::LOGIN )['reason'] );
		$this->assertSame(
			array(
				'status' => SiteCheckReport::UNAVAILABLE,
				'home'   => 'not_run/after_missing',
				'rest'   => 'not_run/after_missing',
			),
			$this->states( $first )
		);
		$this->assertSame( 'not_followed_up', $this->report( $first )['checks']['home']['after']['reason'] );
		$this->assertSame( array( 'intervening_update', 'no_baseline' ), array( $this->report( $second )['checks']['home']['before']['reason'], $this->report( $second )['checks']['home']['after']['reason'] ) );
	}

	/**
	 * Duplicate starts and reports, and concurrent writes, store one result.
	 */
	public function test_duplicates_and_conflicts() {
		$this->baselines();
		$id     = $this->settled_update();
		$opened = $this->open_after( $id );
		$this->assertSame( SiteCheckAfter::ALREADY_STARTED, $this->after->open( $id, 7, self::LOGIN )['code'], 'A repeated start.' );
		$second = new SiteCheckAfter(
			$this->site->repository,
			$this->activity,
			function () {
				return (float) $this->site->time;
			}
		);
		$this->assertSame( SiteCheckAfter::ALREADY_STARTED, $second->open( $id, 7, self::LOGIN )['code'], 'A concurrent request.' );

		$this->assertSame( SiteCheckAfter::SAVED, $this->report_after( $opened, 'home', array( F::home() ) ) );
		$this->assertSame( SessionManager::SESSION_UNAVAILABLE, $this->report_after( $opened, 'home', self::twice( F::home( array( 'status' => 500 ) ) ) ), 'Replayed session.' );
		$stored = $this->site->repository->rows[ $id ]['site_check_home_after'];

		// Even a second accepted result for the same check changes nothing.
		$result = ( new SiteCheckResultCodec() )->decode( $stored );
		$this->assertSame( SiteCheckAfter::CONFLICT, $this->after->record( $id, 7, $result ) );
		$this->assertSame( $stored, $this->site->repository->rows[ $id ]['site_check_home_after'] );

		// A concurrent write for REST wins between the read and this write.
		$this->site->repository->before_transition = function () use ( $id ) {
			$this->site->repository->rows[ $id ]['site_check_rest_after_reason'] = 'session_failed';
		};
		$this->assertSame( SiteCheckAfter::CONFLICT, $this->report_after( $opened, 'rest', array( F::rest() ) ) );
		$this->assertNull( $this->site->repository->rows[ $id ]['site_check_rest_after'] );

		// Another user's or another analysis's write never applies.
		$this->assertSame( SiteCheckAfter::CONFLICT, $this->after->record( $id, 8, $result ) );
		$this->assertSame( SiteCheckAfter::CONFLICT, $this->after->record( 99, 7, $result ) );
	}

	/**
	 * The tab is closed before the AFTER run begins.
	 */
	public function test_tab_closed_before_after_run() {
		$this->baselines();
		$id = $this->settled_update();

		$this->site->time += SiteCheckAfter::START_WINDOW_SECONDS - 2;
		$this->assertSame( SiteCheckReport::PENDING, $this->states( $id )['status'] );
		$this->site->time += 3;
		$this->assertSame(
			array(
				'status' => SiteCheckReport::UNAVAILABLE,
				'home'   => 'not_run/after_missing',
				'rest'   => 'not_run/after_missing',
			),
			$this->states( $id )
		);
		$this->assertSame( 'not_started', $this->report( $id )['checks']['rest']['after']['reason'] );
		$this->assertSame( SiteCheckAfter::NOT_ELIGIBLE, $this->after->open( $id, 7, self::LOGIN )['code'], 'Too late to start.' );
	}

	/**
	 * The tab is closed while REST runs (or the site stops answering
	 * admin-ajax): the homepage result stands, REST is not reported.
	 */
	public function test_tab_closed_during_after_run() {
		$this->baselines();
		$id                = $this->settled_update();
		$opened            = $this->open_after( $id );
		$this->site->time += 1;
		$this->assertSame( SiteCheckAfter::SAVED, $this->report_after( $opened, 'home', array( F::home() ) ) );

		$this->assertSame(
			array(
				'status' => SiteCheckReport::PENDING,
				'home'   => 'passed_both',
				'rest'   => 'pending',
			),
			$this->states( $id )
		);
		$this->site->time += CheckSession::TTL + SiteCheckAfter::REPORT_GRACE_SECONDS;
		$this->assertSame(
			array(
				'status' => SiteCheckReport::PARTIAL,
				'home'   => 'passed_both',
				'rest'   => 'not_run/after_missing',
			),
			$this->states( $id )
		);
		$this->assertSame( 'not_reported', $this->report( $id )['checks']['rest']['after']['reason'] );
	}

	/**
	 * A report after its session ended is refused and stores nothing.
	 */
	public function test_expired_after_session() {
		$this->baselines();
		$id                = $this->settled_update();
		$opened            = $this->open_after( $id );
		$this->site->time += CheckSession::TTL;

		$this->assertSame( SessionManager::SESSION_UNAVAILABLE, $this->report_after( $opened, 'home', array( F::home() ) ) );
		$this->assertNull( $this->site->repository->rows[ $id ]['site_check_home_after'] );
		$this->site->time += SiteCheckAfter::REPORT_GRACE_SECONDS + 1;
		$this->assertSame( 'not_reported', $this->report( $id )['checks']['home']['after']['reason'] );
	}

	/**
	 * Sessions of another analysis, user or login session are never usable.
	 */
	public function test_after_binding() {
		$this->baselines();
		$id = $this->settled_update();

		$this->assertSame( SiteCheckAfter::NOT_OWNER, $this->after->open( $id, 8, self::LOGIN )['code'], 'Another administrator.' );
		$this->assertSame( SiteCheckAfter::NOT_OWNER, $this->after->open( $id, 7, self::LOGIN2 )['code'], 'Another login session of the same administrator.' );
		$this->assertSame( SiteCheckAfter::NOT_ELIGIBLE, $this->after->open( 99, 7, self::LOGIN )['code'] );

		$opened = $this->open_after( $id );
		$this->assertSame( SessionManager::NOT_OWNER, $this->report_after( $opened, 'home', array( F::home() ), 8 ) );
		$this->assertSame( SessionManager::NOT_OWNER, $this->report_after( $opened, 'home', array( F::home() ), 7, self::LOGIN2 ) );
		$this->assertSame( SessionManager::INVALID_REPORT, $this->report_after( $opened, 'home', array( F::rest() ) ), 'Another check\'s facts.' );
		$this->assertSame( SiteCheckAfter::SAVED, $this->report_after( $opened, 'home', array( F::home() ) ) );
	}

	/**
	 * Without the follow-up (request failed, browser gone), a later page
	 * settles the analysis: no AFTER run. An expired observation and a
	 * failed update have none either.
	 */
	public function test_no_after_run_without_follow_up() {
		$this->baselines();
		$id                = $this->update();
		$this->site->time += 5;
		$this->site->admin_page();
		$this->assertFalse( $this->after->wanted( $id ) );
		$this->assertSame( SiteCheckAfter::NOT_ELIGIBLE, $this->after->open( $id, 7, self::LOGIN )['code'] );
		$this->assertSame( 'not_followed_up', $this->report( $id )['checks']['home']['after']['reason'] );

		$this->baselines();
		$expired           = $this->update();
		$this->site->time += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS + 1;
		$this->site->admin_page();
		$this->assertSame( SiteCheckAfter::NOT_ELIGIBLE, $this->after->open( $expired, 7, self::LOGIN )['code'] );
		$this->assertSame( 'settle_expired', $this->report( $expired )['checks']['home']['after']['reason'] );

		$this->baselines();
		$update = $this->begin_update();
		$this->finish_update( $update, 'download_failed' );
		$this->assertSame( array( FollowUpRequest::NOT_PENDING ), array_values( $this->follow_up()['results'] ) );
		$this->assertSame( SiteCheckAfter::NOT_ELIGIBLE, $this->after->open( $update['id'], 7, self::LOGIN )['code'] );
		$this->assertSame(
			array(
				'status' => SiteCheckReport::UNAVAILABLE,
				'home'   => 'not_run/after_missing',
				'rest'   => 'not_run/after_missing',
			),
			$this->states( $update['id'] )
		);
		$this->assertSame( 'analysis_ended', $this->report( $update['id'] )['checks']['home']['after']['reason'] );
	}

	/**
	 * A premature follow-up (its request started while the update still ran)
	 * is answered `not_ready` and names no analysis: no AFTER run can start.
	 * The genuine follow-up afterwards settles the analysis once; only then
	 * does the AFTER run start and compare.
	 */
	public function test_premature_follow_up_starts_no_after_run() {
		$this->baselines();
		$update = $this->begin_update();
		$early  = $this->site->request( $this->site->time + 0.5 );
		$this->finish_update( $update );

		$this->site->time += 1;
		$settled           = array();
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_READY ), $early->settle_follow_up( array( self::PLUGIN ), 7, array(), $settled ) );
		$this->assertSame( array(), $settled, 'No analysis is named.' );
		$this->assertFalse( $this->after->wanted( $update['id'] ) );
		$this->assertSame( SiteCheckAfter::NOT_ELIGIBLE, $this->after->open( $update['id'], 7, self::LOGIN )['code'] );
		$this->assertSame( SiteCheckReport::PENDING, $this->report( $update['id'] )['status'], 'Still waiting for the settled observation.' );

		$follow = $this->follow_up();
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::SETTLED ), $follow['results'] );
		$this->assertSame( array( self::PLUGIN => $update['id'] ), $follow['settled'] );
		$this->run_after( $update['id'] );
		$this->assertSame(
			array(
				'status' => SiteCheckReport::COMPLETE,
				'home'   => 'passed_both',
				'rest'   => 'passed_both',
			),
			$this->states( $update['id'] )
		);

		$again = $this->follow_up();
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_PENDING ), $again['results'] );
		$this->assertSame( array(), $again['settled'], 'Settled exactly once.' );
	}

	/**
	 * A recreated or unreadable ledger cannot rule other updates out.
	 */
	public function test_unknown_update_order() {
		$this->baselines();
		$id = $this->settled_update();
		$this->store->values[ UpdateActivity::STORAGE_KEY ] = '{"broken"';

		$this->assertSame( 'update_order_unknown', $this->open_after( $id )['reason'] );
		$this->assertSame( 'not_comparable/update_order_unknown', $this->states( $id )['home'] );

		// The ledger breaks while the AFTER requests run.
		$this->baselines();
		$second = $this->settled_update();
		$opened = $this->open_after( $second );
		$this->store->values[ UpdateActivity::STORAGE_KEY ] = '{"broken"';
		$this->assertSame( SiteCheckAfter::SAVED, $this->report_after( $opened, 'home', array( F::home() ) ) );
		$this->assertSame( 'not_comparable/update_order_unknown', $this->states( $second )['home'] );
		$this->assertSame( 'update_order_unknown', $this->report( $second )['checks']['home']['after']['reason'] );
	}

	/**
	 * Site checks turned off: no baselines are attached, nothing runs after.
	 */
	public function test_feature_disabled() {
		$this->baselines();
		$this->context['enabled'] = false;
		$id                       = $this->settled_update();

		$this->assertFalse( $this->after->wanted( $id ) );
		$this->assertSame(
			array( 'disabled', 'no_baseline' ),
			array( $this->report( $id )['checks']['rest']['before']['reason'], $this->report( $id )['checks']['rest']['after']['reason'] )
		);
		$this->assertSame( SiteCheckReport::UNAVAILABLE, $this->states( $id )['status'] );
	}

	/**
	 * Analyses from before schema 7 and before schema 6 read as not captured.
	 */
	public function test_historical_analyses() {
		$this->baselines();
		$id  = $this->settled_update();
		$row = $this->site->repository->find_report( $id );

		$schema_6 = $row;
		unset( $schema_6['site_check_update_started_at'], $schema_6['site_check_after_started_at'], $schema_6['site_check_home_after'], $schema_6['site_check_home_after_reason'], $schema_6['site_check_rest_after'], $schema_6['site_check_rest_after_reason'] );
		$report = SiteCheckReport::build( $schema_6, (float) $this->site->time );
		$this->assertSame( array( true, 'not_captured', 'not_run', 'after_missing' ), array( $report['checks']['home']['before']['available'], $report['checks']['home']['after']['reason'], $report['checks']['home']['state'], $report['checks']['home']['reason'] ) );
		$this->assertSame( SiteCheckReport::UNAVAILABLE, $report['status'] );

		$schema_5 = array_diff_key(
			$row,
			array_flip(
				array_filter(
					array_keys( $row ),
					static function ( $key ) {
						return 0 === strpos( $key, 'site_check_' );
					}
				)
			)
		);
		$report   = SiteCheckReport::build( $schema_5, (float) $this->site->time );
		$this->assertSame( array( 'not_captured', 'not_captured', 'not_run', 'baseline_missing' ), array( $report['checks']['rest']['before']['reason'], $report['checks']['rest']['after']['reason'], $report['checks']['rest']['state'], $report['checks']['rest']['reason'] ) );
	}

	/**
	 * Unreadable or unexpected stored AFTER data is never compared.
	 */
	public function test_corrupt_after_data() {
		$this->baselines();
		$id = $this->settled_update();
		$this->run_after( $id );
		$this->site->repository->rows[ $id ]['site_check_home_after']        = '{"schema":2';
		$this->site->repository->rows[ $id ]['site_check_rest_after_reason'] = 'made_up';

		$this->assertSame(
			array(
				'status' => SiteCheckReport::UNAVAILABLE,
				'home'   => 'not_run/after_missing',
				'rest'   => 'not_comparable/update_order_unknown',
			),
			$this->states( $id )
		);
		$this->assertSame( array( 'data_corrupt', 'unknown' ), array( $this->report( $id )['checks']['home']['after']['reason'], $this->report( $id )['checks']['rest']['after']['reason'] ) );
	}

	/**
	 * The AFTER run writes only its own columns: the completed analysis and
	 * its Net result stay exactly as the follow-up stored them.
	 */
	public function test_completed_analysis_unchanged() {
		$this->baselines();
		$id     = $this->settled_update();
		$before = $this->site->repository->rows[ $id ];
		$this->run_after( $id );
		$after = $this->site->repository->rows[ $id ];

		$own = array( 'site_check_after_started_at', 'site_check_home_after', 'site_check_rest_after' );
		$this->assertSame( array_diff_key( $before, array_flip( $own ) ), array_diff_key( $after, array_flip( $own ) ) );
	}

	/**
	 * The report holds no login sessions, targets, users, generations,
	 * markers or secrets.
	 */
	public function test_report_privacy() {
		$this->baselines();
		$id     = $this->settled_update();
		$opened = $this->run_after( $id );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Inspects the output.
		$json = json_encode( $this->report( $id ) );

		foreach ( array( self::LOGIN, str_repeat( 'a', 64 ), str_repeat( 'c', 64 ), $opened['generation'], explode( ':', $opened['generation'] )[0], $opened['sessions']['home']['session'], $opened['sessions']['home']['secret'], '"user', '"generation', '"session', '"target' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
	}

	/**
	 * Per check whether a BEFORE result is attached, else the reason.
	 *
	 * @param int $id Analysis ID.
	 * @return array<string, string>
	 */
	private function attached( $id ) {
		return array_map(
			static function ( $entry ) {
				return null === $entry['result'] ? $entry['reason'] : 'attached';
			},
			SiteCheckObservation::read( $this->site->repository->rows[ $id ] )
		);
	}
}
