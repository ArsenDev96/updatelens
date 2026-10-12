<?php
/**
 * Tests for attaching site check BEFORE baselines to update analyses.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use UpdateLens\SiteCheck\Baselines;
use UpdateLens\SiteCheck\CheckResult;
use UpdateLens\SiteCheck\UpdateActivity;
use UpdateLens\Storage\SiteCheckResultCodec;
use UpdateLens\Tests\Support\FakeSite;
use UpdateLens\Tests\Support\InMemoryBaselineStore;
use UpdateLens\Tests\Support\SiteCheckFixture as F;
use UpdateLens\Update\AnalysisStatus;
use UpdateLens\Update\PluginUpdateAnalyzer;
use UpdateLens\Update\SiteCheckObservation;

/**
 * The analysis lifecycle with site check baselines: which baseline each
 * update gets (selected when it starts, independently per check), how
 * other updates invalidate older baselines, and that nothing else in the
 * analysis changes.
 */
final class SiteCheckBeforeLifecycleTest extends TestCase {

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
	 * A BEFORE run that starts now (its session records the generation) and
	 * is accepted $duration seconds later, when it is stored.
	 *
	 * @param string $check    Check.
	 * @param array  $options  `user`, `session`, `facts`, `duration`, `target`, `version`.
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
			array_intersect_key( $options, array_flip( array( 'user', 'session', 'target', 'version' ) ) ) + array(
				'started_at'   => $started,
				'completed_at' => $started + $options['duration'],
				'generation'   => $generation,
			)
		);
		$this->assertSame( Baselines::SAVED, $this->baselines->save( $result ) );

		return $result;
	}

	/**
	 * Start a supported single-plugin update request (as PluginUpdateTracker does).
	 *
	 * @param string $plugin Plugin file.
	 * @param int    $user   Updating administrator.
	 * @param string $login  Their login session.
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
		$update['request']->update_finished( $update['plugin'], $error, null === $error ? '1.1.0' : null );
		$update['request']->request_ending( true );
		$this->activity->end( $update['token'] );
	}

	/**
	 * A whole update request.
	 *
	 * @param string $plugin Plugin file.
	 * @param int    $user   Updating administrator.
	 * @param string $login  Their login session.
	 * @return int Analysis ID.
	 */
	private function update( $plugin = self::PLUGIN, $user = 7, $login = self::LOGIN ) {
		$update = $this->begin_update( $plugin, $user, $login );
		$this->finish_update( $update );
		$this->site->time += 2;

		return $update['id'];
	}

	/**
	 * Per check: `attached:<verdict>` or the reason.
	 *
	 * @param int $id Analysis ID.
	 * @return array<string, string>
	 */
	private function before( $id ) {
		return array_map(
			static function ( $entry ) {
				return null === $entry['result'] ? $entry['reason'] : 'attached:' . $entry['result']->get_verdict();
			},
			SiteCheckObservation::read( $this->site->repository->rows[ $id ] )
		);
	}

	/**
	 * Both baselines valid: both attached, with their own times and context.
	 */
	public function test_two_valid_baselines() {
		$home              = $this->baseline( 'home' );
		$rest              = $this->baseline( 'rest', array( 'duration' => 2.5 ) );
		$this->site->time += 30;
		$id                = $this->update();

		$this->assertSame(
			array(
				'home' => 'attached:pass',
				'rest' => 'attached:pass',
			),
			$this->before( $id )
		);
		$read = SiteCheckObservation::read( $this->site->repository->rows[ $id ] );
		$this->assertSame( ( new SiteCheckResultCodec() )->encode( $home ), ( new SiteCheckResultCodec() )->encode( $read['home']['result'] ) );
		$this->assertSame( array( $rest->get_started_at(), $rest->get_completed_at(), 7, self::LOGIN ), array( $read['rest']['result']->get_started_at(), $read['rest']['result']->get_completed_at(), $read['rest']['result']->get_context()->get_user(), $read['rest']['result']->get_context()->get_session() ) );
	}

	/**
	 * Each check is selected on its own.
	 */
	public function test_checks_are_independent() {
		$this->baseline( 'home' );
		$this->site->time += 10;
		$this->assertSame(
			array(
				'home' => 'attached:pass',
				'rest' => 'missing',
			),
			$this->before( $this->update() )
		);

		$this->site->time += 10;
		$this->baseline( 'rest' );
		$this->site->time += 10;
		$this->assertSame(
			array(
				'home' => 'intervening_update',
				'rest' => 'attached:pass',
			),
			$this->before( $this->update() ),
			'REST valid, homepage left from before the previous update.'
		);
	}

	/**
	 * An update right away has no baseline.
	 */
	public function test_immediate_update() {
		$this->assertSame(
			array(
				'home' => 'missing',
				'rest' => 'missing',
			),
			$this->before( $this->update() )
		);
	}

	/**
	 * A baseline still running when the update starts is not used, and its
	 * report, accepted during the update, is never attached afterwards (nor
	 * to the next update).
	 */
	public function test_baseline_running_or_reported_after_the_update_began() {
		$started           = (float) $this->site->time;
		$generation        = $this->activity->quiet_generation();
		$this->site->time += 1;

		$update = $this->begin_update();
		$this->assertSame(
			array(
				'home' => 'missing',
				'rest' => 'missing',
			),
			$this->before( $update['id'] )
		);
		$row = $this->site->repository->rows[ $update['id'] ];

		// The run's report is accepted while the update is running.
		$this->site->time += 1;
		$this->baselines->save(
			F::result(
				'home',
				array( F::home() ),
				array(
					'started_at'   => $started,
					'completed_at' => (float) $this->site->time,
					'generation'   => $generation,
				)
			)
		);
		$this->finish_update( $update );
		$this->assertSame( $row['site_check_home_before'], $this->site->repository->rows[ $update['id'] ]['site_check_home_before'] );
		$this->assertSame( $row['site_check_home_before_reason'], $this->site->repository->rows[ $update['id'] ]['site_check_home_before_reason'] );

		$this->site->time += 10;
		$this->assertSame( 'intervening_update', $this->before( $this->update( self::OTHER ) )['home'] );
	}

	/**
	 * A report accepted in the same instant the update began is not "before" it.
	 */
	public function test_report_at_the_update_start() {
		$generation = $this->activity->quiet_generation();
		$request    = $this->site->request();
		$token      = $this->activity->new_token();
		$activity   = $this->activity->begin( $token );
		$this->baselines->save(
			F::result(
				'home',
				array( F::home() ),
				array(
					'started_at'   => $activity['at'] - 1,
					'completed_at' => $activity['at'],
					'generation'   => $generation,
				)
			)
		);
		$request->update_starting( self::PLUGIN, array(), 7, $activity );

		$this->assertSame( 'not_completed_before_update', $this->before( 1 )['home'] );
	}

	/**
	 * Ten minutes is the limit (inclusive).
	 */
	public function test_age_limit() {
		$this->baseline( 'home' );
		$this->baseline( 'rest' );
		$this->site->time += Baselines::MAX_AGE_SECONDS; // REST: exactly 600 s, home 601 s.
		$update            = $this->begin_update();

		$this->assertSame(
			array(
				'home' => 'expired',
				'rest' => 'attached:pass',
			),
			$this->before( $update['id'] )
		);
	}

	/**
	 * A baseline used for one update is never reused for the next one.
	 */
	public function test_previous_update_invalidates_the_baseline() {
		$this->baseline( 'home' );
		$this->baseline( 'rest' );
		$this->site->time += 5;
		$first             = $this->update();
		$this->site->time += 300;
		$second            = $this->update( self::OTHER );

		$this->assertSame( array( 'attached:pass', 'attached:pass' ), array_values( $this->before( $first ) ) );
		$this->assertSame( array( 'intervening_update', 'intervening_update' ), array_values( $this->before( $second ) ) );
	}

	/**
	 * Another administrator's update in between invalidates the baseline too.
	 */
	public function test_update_by_another_administrator() {
		$this->baseline( 'home' );
		$this->baseline( 'rest' );
		$this->site->time += 5;
		$theirs            = $this->update( self::OTHER, 8, self::LOGIN2 );
		$this->site->time += 5;
		$mine              = $this->update( self::PLUGIN, 7, self::LOGIN );

		$this->assertSame( array( 'missing', 'missing' ), array_values( $this->before( $theirs ) ), 'Never another administrator\'s baseline.' );
		$this->assertSame( array( 'intervening_update', 'intervening_update' ), array_values( $this->before( $mine ) ) );
	}

	/**
	 * Queued updates (one request after another, or two in one request):
	 * only the first can use the baseline.
	 */
	public function test_queued_updates() {
		$this->baseline( 'home' );
		$this->baseline( 'rest' );
		$this->site->time += 5;
		$first             = $this->update();
		$second            = $this->update( self::OTHER );
		$this->assertSame( array( 'attached:pass', 'attached:pass' ), array_values( $this->before( $first ) ) );
		$this->assertSame( array( 'intervening_update', 'intervening_update' ), array_values( $this->before( $second ) ) );

		// Two updates in one request: the second sees the first's activity.
		$this->site->time += 60;
		$this->baseline( 'home' );
		$this->site->time += 5;
		$request           = $this->site->request();
		$token             = $this->activity->new_token();
		$request->update_starting( 'a/a.php', array(), 7, $this->activity->begin( $token ) );
		$request->update_starting( 'b/b.php', array(), 7, $this->activity->begin( $token ) );
		$this->activity->end( $token );
		$a = $this->site->repository->rows[3];
		$b = $this->site->repository->rows[4];

		$this->assertSame( AnalysisStatus::ABANDONED, $a['status'] );
		$this->assertSame( 'attached:pass', $this->before( 3 )['home'], 'The abandoned analysis keeps its truthful baseline.' );
		$this->assertSame( 'intervening_update', $this->before( 4 )['home'] );
		$this->assertSame( 'b/b.php', $b['plugin_file'] );
	}

	/**
	 * Two administrators updating at the same time: the one ordered first
	 * may use their baseline, the other overlaps it and uses none.
	 */
	public function test_simultaneous_administrators() {
		$this->baseline( 'home' );
		$this->baseline( 'rest' );
		$this->baseline(
			'home',
			array(
				'user'    => 8,
				'session' => self::LOGIN2,
			)
		);
		$this->baseline(
			'rest',
			array(
				'user'    => 8,
				'session' => self::LOGIN2,
			)
		);
		$this->site->time += 5;

		$mine   = $this->begin_update( self::PLUGIN, 7, self::LOGIN );
		$theirs = $this->begin_update( self::OTHER, 8, self::LOGIN2 );
		$this->finish_update( $theirs );
		$this->finish_update( $mine );

		$this->assertSame( array( 'attached:pass', 'attached:pass' ), array_values( $this->before( $mine['id'] ) ) );
		$this->assertSame( array( 'overlapping_update', 'overlapping_update' ), array_values( $this->before( $theirs['id'] ) ) );
		$this->assertSame( 7, SiteCheckObservation::read( $this->site->repository->rows[ $mine['id'] ] )['home']['result']->get_context()->get_user(), 'Each analysis has only its own administrator\'s baseline.' );
	}

	/**
	 * Updates starting in the same instant are ordered by the ledger.
	 */
	public function test_same_second_updates() {
		$this->baseline( 'home' );
		$this->baseline( 'rest' );
		$this->site->time += 5;
		$first             = $this->begin_update( self::PLUGIN );
		$second            = $this->begin_update( self::OTHER );

		$this->assertSame( $this->site->repository->rows[ $first['id'] ]['started_at'], $this->site->repository->rows[ $second['id'] ]['started_at'] );
		$this->assertSame( array( 'attached:pass', 'attached:pass' ), array_values( $this->before( $first['id'] ) ) );
		$this->assertSame( array( 'overlapping_update', 'overlapping_update' ), array_values( $this->before( $second['id'] ) ) );
	}

	/**
	 * A baseline run that started while an update ran is never attached.
	 */
	public function test_baseline_taken_during_an_update() {
		$update = $this->begin_update( self::OTHER );
		$this->baseline( 'home' );
		$this->finish_update( $update );
		$this->site->time += 5;

		$this->assertSame( 'taken_during_update', $this->before( $this->update() )['home'] );
	}

	/**
	 * Unreadable or foreign stored baselines.
	 */
	public function test_corrupted_baselines() {
		$this->baseline( 'rest' );
		$this->store->values[ Baselines::storage_key( 7, self::LOGIN, 'home' ) ] = '{"schema":2,"check":"home"';
		$this->store->values[ Baselines::storage_key( 7, self::LOGIN, 'rest' ) ] = ( new SiteCheckResultCodec() )->encode(
			F::result(
				'rest',
				array( F::rest() ),
				array(
					'user'         => 8,
					'started_at'   => self::T,
					'completed_at' => self::T + 1,
				)
			)
		);
		$this->site->time += 5;

		$this->assertSame( array( 'corrupt', 'corrupt' ), array_values( $this->before( $this->update() ) ) );
	}

	/**
	 * Another login session (another browser, a new login) never uses the baseline.
	 */
	public function test_different_login_session() {
		$this->baseline( 'home' );
		$this->baseline( 'rest' );
		$this->site->time += 5;

		$this->assertSame( array( 'missing', 'missing' ), array_values( $this->before( $this->update( self::PLUGIN, 7, self::LOGIN2 ) ) ) );
	}

	/**
	 * Inconclusive and failing baselines are attached as they are, never as a pass.
	 */
	public function test_inconclusive_baseline_is_never_a_pass() {
		$this->baseline(
			'home',
			array(
				'facts' => array(
					F::home(
						array(
							'header'     => 'absent',
							'end_marker' => 'absent',
						)
					),
					F::home(
						array(
							'header'     => 'absent',
							'end_marker' => 'absent',
						)
					),
				),
			)
		);
		$this->baseline( 'rest', array( 'facts' => array( F::rest( array( 'status' => 500 ) ), F::rest( array( 'status' => 500 ) ) ) ) );
		$this->site->time += 5;

		$this->assertSame(
			array(
				'home' => 'attached:inconclusive',
				'rest' => 'attached:fail',
			),
			$this->before( $this->update() )
		);
	}

	/**
	 * Other definition versions and site addresses are not baselines.
	 */
	public function test_definition_or_target_changed() {
		$this->baseline( 'home', array( 'target' => str_repeat( 'd', 64 ) ) );
		$this->baseline( 'rest', array( 'version' => 2 ) );
		$this->site->time += 5;

		$this->assertSame(
			array(
				'home' => 'target_changed',
				'rest' => 'definition_changed',
			),
			$this->before( $this->update() )
		);
	}

	/**
	 * Requests without the opt-in, a login session or a recorded order.
	 */
	public function test_unavailable_contexts() {
		$this->baseline( 'home' );
		$this->baseline( 'rest' );
		$this->site->time += 5;

		$this->context['enabled'] = false;
		$this->assertSame( array( 'disabled', 'disabled' ), array_values( $this->before( $this->update() ) ) );
		$this->context['enabled'] = true;

		$this->site->time += 5;
		$request           = $this->site->request();
		$request->update_starting( 'n/n.php', array(), 7, null );
		$this->assertSame( array( 'update_order_unknown', 'update_order_unknown' ), array_values( $this->before( 2 ) ) );

		$this->context['login'] = null;
		$request                = $this->site->request();
		$request->update_starting( 'm/m.php', array(), 7, $this->activity->begin( $this->activity->new_token() ) );
		$this->assertSame( array( 'no_login_session', 'no_login_session' ), array_values( $this->before( 3 ) ) );
	}

	/**
	 * A check whose target is on another origin than wp-admin is recorded as
	 * `cross_origin`, whatever is stored for it; the other check is selected
	 * on its own. Turned off or without a login session, those reasons come
	 * first.
	 */
	public function test_checks_on_other_origins() {
		$this->baseline( 'home' );
		$this->baseline( 'rest' );
		$this->site->time += 5;

		$this->context['unsupported'] = array( 'home' );
		$this->assertSame(
			array(
				'home' => 'cross_origin',
				'rest' => 'attached:pass',
			),
			$this->before( $this->update() ),
			'A stored homepage baseline is never attached.'
		);

		$this->site->time += 5;
		$this->baseline( 'home' );
		$this->site->time            += 5;
		$this->context['unsupported'] = array( 'rest' );
		$this->assertSame(
			array(
				'home' => 'attached:pass',
				'rest' => 'cross_origin',
			),
			$this->before( $this->update() )
		);

		$this->site->time            += 5;
		$this->context['unsupported'] = array( 'home', 'rest' );
		$id                           = $this->update();
		$this->assertSame( array( 'cross_origin', 'cross_origin' ), array_values( $this->before( $id ) ) );
		$this->assertNull( $this->site->repository->rows[ $id ]['site_check_home_before'] );

		$this->context['enabled'] = false;
		$this->assertSame( array( 'disabled', 'disabled' ), array_values( $this->before( $this->update() ) ) );
		$this->context['enabled'] = true;
		$this->context['login']   = null;
		$request                  = $this->site->request();
		$request->update_starting( 'm/m.php', array(), 7, $this->activity->begin( $this->activity->new_token() ) );
		$this->assertSame( array( 'no_login_session', 'no_login_session' ), array_values( $this->before( 5 ) ) );
	}

	/**
	 * An unreadable ledger cannot prove the order: nothing is attached.
	 */
	public function test_unreadable_ledger() {
		$this->baseline( 'home' );
		$this->store->values[ UpdateActivity::STORAGE_KEY ] = 'garbage';
		$this->site->time                                  += 5;

		$this->assertSame( 'update_order_unknown', $this->before( $this->update() )['home'] );
	}

	/**
	 * Failed and abandoned analyses keep their BEFORE metadata.
	 */
	public function test_failed_and_abandoned_analyses_keep_it() {
		$this->baseline( 'home' );
		$this->site->time += 5;
		$failed            = $this->begin_update();
		$this->finish_update( $failed, 'download_failed' );
		$this->assertSame( AnalysisStatus::FAILED, $this->site->repository->rows[ $failed['id'] ]['status'] );
		$this->assertSame( array( 'attached:pass', 'missing' ), array_values( $this->before( $failed['id'] ) ) );

		// An update that never reports back is abandoned later.
		$this->site->time += 5;
		$this->baseline( 'rest' );
		$this->site->time += 5;
		$stale             = $this->begin_update( self::OTHER );
		$this->activity->end( $stale['token'] );
		$this->site->time += PluginUpdateAnalyzer::STALE_AFTER_SECONDS + 1;
		$this->site->admin_page();
		$this->assertSame( AnalysisStatus::ABANDONED, $this->site->repository->rows[ $stale['id'] ]['status'] );
		$this->assertSame( array( 'intervening_update', 'attached:pass' ), array_values( $this->before( $stale['id'] ) ) );
	}

	/**
	 * Analyses from before schema 6, and damaged stored values.
	 */
	public function test_historical_and_damaged_rows() {
		$this->site->site_checks = null;
		$id                      = $this->update();
		$this->assertNull( $this->site->repository->rows[ $id ]['site_check_home_before_reason'] );
		$this->assertSame( array( 'not_captured', 'not_captured' ), array_values( $this->before( $id ) ) );

		$this->assertSame(
			array(
				'home' => 'data_corrupt',
				'rest' => 'unknown',
			),
			array_map(
				static function ( $entry ) {
					return $entry['reason'];
				},
				SiteCheckObservation::read(
					array(
						'site_check_home_before'        => '{"schema":2}',
						'site_check_home_before_reason' => null,
						'site_check_rest_before'        => null,
						'site_check_rest_before_reason' => 'surprise',
					)
				)
			)
		);
		$this->assertSame(
			'data_corrupt',
			SiteCheckObservation::read(
				array(
					'site_check_home_before' => ( new SiteCheckResultCodec() )->encode(
						F::result(
							'rest',
							array( F::rest() ),
							array(
								'started_at'   => self::T,
								'completed_at' => self::T + 1,
							)
						)
					),
				)
			)['home']['reason'],
			'A REST result in the homepage column.'
		);
	}

	/**
	 * Attaching baselines changes nothing else: options, Cron and Action
	 * Scheduler observations and the lifecycle are identical.
	 */
	public function test_existing_observations_are_unchanged() {
		$this->baseline( 'home' );
		$this->baseline( 'rest' );
		$this->site->time += 5;
		$with              = $this->update();
		$this->site->time += 400;
		$this->site->admin_page();

		$this->site->site_checks = null;
		$without                 = $this->update( self::OTHER );
		$this->site->time       += 400;
		$this->site->admin_page();

		$strip = static function ( array $row ) {
			return array_diff_key( $row, array_flip( array( 'id', 'plugin_file', 'plugin_name', 'active_plugin', 'started_at', 'updated_at', 'settle_deadline', 'immediate_captured_at', 'completed_at', 'site_check_home_before', 'site_check_home_before_reason', 'site_check_rest_before', 'site_check_rest_before_reason', 'site_check_update_started_at' ) ) );
		};
		$this->assertSame( $strip( $this->site->repository->rows[ $without ] ), $strip( $this->site->repository->rows[ $with ] ) );
		$this->assertSame( AnalysisStatus::COMPLETED, $this->site->repository->rows[ $with ]['status'] );
		$this->assertNotNull( $this->site->repository->rows[ $with ]['site_check_home_before'], 'Kept after completion.' );
	}

	/**
	 * Stored attachments hold no URL, marker, token or response content.
	 */
	public function test_stored_attachments_are_facts_only() {
		$this->baseline( 'home' );
		$this->baseline( 'rest' );
		$this->site->time += 5;
		$row               = $this->site->repository->rows[ $this->update() ];

		foreach ( array( 'site_check_home_before', 'site_check_rest_before' ) as $column ) {
			$this->assertDoesNotMatchRegularExpression( '#https?:|updatelens_check|wordpress_logged_in|<|nonce|secret#i', $row[ $column ] );
			$this->assertLessThanOrEqual( SiteCheckResultCodec::MAX_BYTES, strlen( $row[ $column ] ) );
		}
	}
}
