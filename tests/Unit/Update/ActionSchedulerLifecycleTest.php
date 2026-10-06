<?php
/**
 * Tests for Action Scheduler observation inside the plugin update analysis lifecycle.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use Error;
use PHPUnit\Framework\TestCase;
use UpdateLens\Report\AnalysisReports;
use UpdateLens\Snapshot\ActionSchedulerUnavailableException;
use UpdateLens\Snapshot\CronSnapshot;
use UpdateLens\Snapshot\MalformedActionSchedulerStateException;
use UpdateLens\Snapshot\OptionsSnapshot;
use UpdateLens\Storage\ActionSchedulerDiffCodec;
use UpdateLens\Tests\Support\ActionSchedulerFixture;
use UpdateLens\Tests\Support\CronFixture;
use UpdateLens\Tests\Support\FakeSite;
use UpdateLens\Tests\Support\InMemoryAnalysisRepository;
use UpdateLens\Update\ActionSchedulerObservation;
use UpdateLens\Update\ActionSchedulerPhaseReason;
use UpdateLens\Update\AnalysisStatus;
use UpdateLens\Update\CronObservation;
use UpdateLens\Update\CronPhaseReason;
use UpdateLens\Update\PluginUpdateAnalyzer;
use UpdateLens\Update\SettleOutcome;

/**
 * BEFORE → IMMEDIATE → SETTLED for Action Scheduler, independent of the
 * options and WP-Cron signals.
 */
final class ActionSchedulerLifecycleTest extends TestCase {

	const PLUGIN = 'acme/acme.php';

	/**
	 * Obviously fake credential stored in real Action Scheduler arguments.
	 */
	const FAKE_SECRET = 'sk_test_UPDATE_LENS_ACTION_SCHEDULER_LIFECYCLE_SECRET';

	const T = 1767225600; // 2026-01-01T00:00:00Z.

	const SNAPSHOT_COLUMNS = array(
		'options_before_snapshot',
		'options_immediate_snapshot',
		'cron_before_snapshot',
		'cron_immediate_snapshot',
		'action_scheduler_before_snapshot',
		'action_scheduler_immediate_snapshot',
	);

	/**
	 * Site.
	 *
	 * @var FakeSite
	 */
	private $site;

	/**
	 * Fresh site.
	 */
	protected function setUp(): void {
		$this->site = new FakeSite( array( 'blogname' => array( 'Site', 'on' ) ), self::T );
	}

	/**
	 * Secret arguments, as a plugin would pass an order or webhook token.
	 *
	 * @return array
	 */
	private static function secret_args() {
		return array(
			'token'   => self::FAKE_SECRET,
			'webhook' => 'https://hooks.example.test/' . self::FAKE_SECRET,
		);
	}

	/**
	 * Queue before the update: a recurring cleanup, a pending migration and a daily sync.
	 *
	 * @return array
	 */
	private static function as_before() {
		return array(
			ActionSchedulerFixture::recurring( 'wc_cleanup', self::T + 3600, 3600, self::secret_args(), 'woocommerce' ),
			ActionSchedulerFixture::single( 'acme_migrate', self::T + 600, self::secret_args(), 'acme' ),
			ActionSchedulerFixture::recurring( 'acme_sync', self::T + 900, 86400, array(), 'acme' ),
		);
	}

	/**
	 * Right after the update request: the update queued a database upgrade.
	 *
	 * @return array
	 */
	private static function as_immediate() {
		$rows   = self::as_before();
		$rows[] = ActionSchedulerFixture::async( 'acme_upgrade_db', self::T, array( 'version' => '1.1.0' ), 'acme' );

		return $rows;
	}

	/**
	 * At settle: the migration ran, the cleanup moved, the sync became a cron
	 * schedule and a report job appeared; the upgrade is still queued.
	 *
	 * @return array
	 */
	private static function as_settled() {
		return array(
			ActionSchedulerFixture::recurring( 'wc_cleanup', self::T + 7200, 3600, self::secret_args(), 'woocommerce' ),
			ActionSchedulerFixture::cron( 'acme_sync', self::T + 900, '0 3 * * *', array(), 'acme' ),
			ActionSchedulerFixture::async( 'acme_upgrade_db', self::T, array( 'version' => '1.1.0' ), 'acme' ),
			ActionSchedulerFixture::single( 'acme_report', self::T + 1800, self::secret_args(), 'acme' ),
		);
	}

	/**
	 * A full lifecycle: BEFORE, update request (IMMEDIATE + its shutdown), a
	 * later admin page (SETTLED). Options and Cron change at each step too.
	 *
	 * Each step's Action Scheduler state is a value (rows, null, Throwable) or
	 * a callable run on the site before the step.
	 *
	 * @param mixed $before    State before the update.
	 * @param mixed $immediate State before the update reports completion.
	 * @param mixed $settled   State before the admin page.
	 * @return array The analysis row.
	 */
	private function lifecycle( $before, $immediate, $settled ) {
		$this->site->cron = CronFixture::cron( array( CronFixture::recurring( self::T + 3600, 'acme_cleanup', 'daily' ) ) );
		$this->step( $before );
		$request = $this->site->request();
		$this->site->start( $request, self::PLUGIN );

		$this->site->options['acme_version'] = array( '1.1.0', 'on' );
		$this->site->cron                    = CronFixture::cron(
			array(
				CronFixture::recurring( self::T + 3600, 'acme_cleanup', 'daily' ),
				CronFixture::single( self::T + 120, 'acme_update_once' ),
			)
		);
		$this->step( $immediate );
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$request->request_ending( true );

		$this->site->time                    += 60;
		$this->site->options['acme_settings'] = array( 'migrated', 'off' );
		$this->site->cron                     = CronFixture::cron(
			array(
				CronFixture::recurring( self::T + 7200, 'acme_cleanup', 'daily' ),
				CronFixture::single( self::T + 120, 'acme_update_once' ),
			)
		);
		$this->step( $settled );
		$this->site->admin_page();

		return $this->site->repository->rows[1];
	}

	/**
	 * Apply a step's Action Scheduler state.
	 *
	 * @param mixed $state Rows, null, Throwable or callable.
	 * @return void
	 */
	private function step( $state ) {
		if ( is_callable( $state ) ) {
			$state( $this->site );
		} else {
			$this->site->action_scheduler = $state;
		}
	}

	/**
	 * Options, Cron and lifecycle columns of a row (everything but Action Scheduler and timestamps).
	 *
	 * @param array $row Analysis.
	 * @return array
	 */
	private static function other_signals( array $row ) {
		return array_filter(
			$row,
			static function ( $column ) {
				return 0 !== strpos( $column, 'action_scheduler_' );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Options, Cron and lifecycle columns of the same lifecycle with Action Scheduler fully valid.
	 *
	 * @return array
	 */
	private function reference_other_signals() {
		$site       = $this->site;
		$this->site = new FakeSite( array( 'blogname' => array( 'Site', 'on' ) ), self::T );
		$row        = $this->lifecycle( self::as_before(), self::as_immediate(), self::as_settled() );
		$this->site = $site;

		return self::other_signals( $row );
	}

	/**
	 * Decoded Action Scheduler diff of a phase.
	 *
	 * @param array  $row   Analysis.
	 * @param string $phase ObservationPhase.
	 * @return array
	 */
	private static function as_diff( array $row, $phase ) {
		return ( new ActionSchedulerDiffCodec() )->decode( $row[ ActionSchedulerObservation::PHASES[ $phase ][0] ] );
	}

	/**
	 * Per phase: 'available' or the reason.
	 *
	 * @param array $row    Analysis.
	 * @param array $phases Column pairs (ActionSchedulerObservation::PHASES or CronObservation::PHASES).
	 * @return array<string, string|null>
	 */
	private static function availability( array $row, array $phases = ActionSchedulerObservation::PHASES ) {
		$result = array();
		foreach ( $phases as $phase => $columns ) {
			list( $diff, $reason ) = $columns;
			$result[ $phase ]      = null !== $row[ $diff ] ? 'available' : $row[ $reason ];
		}

		return $result;
	}

	/**
	 * The same value for all three phases.
	 *
	 * @param string $value 'available' or a reason.
	 * @return array<string, string>
	 */
	private static function all( $value ) {
		return array(
			'during_update' => $value,
			'post_update'   => $value,
			'final'         => $value,
		);
	}

	/**
	 * Hooks per section of a decoded diff.
	 *
	 * @param array $diff Decoded diff.
	 * @return array
	 */
	private static function hooks( array $diff ) {
		return array(
			'added'       => array_column( $diff['added'], 'hook' ),
			'removed'     => array_column( $diff['removed'], 'hook' ),
			'rescheduled' => array_column( $diff['rescheduled'], 'hook' ),
			'changed'     => array_column( $diff['changed'], 'hook' ),
		);
	}

	/**
	 * Terminal invariants: no temporary snapshot of any signal is left, every
	 * Cron and Action Scheduler phase has exactly one of a diff or a reason,
	 * and no secret was stored.
	 *
	 * @param array $row Analysis.
	 * @return void
	 */
	private function assert_terminal( array $row ) {
		$this->assertNotContains( $row['status'], array( AnalysisStatus::CAPTURED, AnalysisStatus::AWAITING_SETTLE ) );
		foreach ( self::SNAPSHOT_COLUMNS as $column ) {
			$this->assertArrayHasKey( $column, $row );
			$this->assertNull( $row[ $column ], $column );
		}
		foreach ( array( ActionSchedulerObservation::PHASES, CronObservation::PHASES ) as $phases ) {
			foreach ( $phases as $phase => $columns ) {
				$this->assertTrue( null === $row[ $columns[0] ] xor null === $row[ $columns[1] ], "{$columns[0]}: exactly one of diff or reason" );
			}
		}
		foreach ( ActionSchedulerObservation::PHASES as $columns ) {
			if ( null !== $row[ $columns[1] ] ) {
				$this->assertContains( $row[ $columns[1] ], ActionSchedulerPhaseReason::ALL );
			}
		}
		$this->assert_no_secret();
	}

	/**
	 * The fake secret and its readable parts appear in no stored column of any analysis.
	 *
	 * @return void
	 */
	private function assert_no_secret() {
		foreach ( $this->site->repository->rows as $row ) {
			foreach ( $row as $column => $value ) {
				foreach ( array( self::FAKE_SECRET, 'hooks.example.test', 'token', 'webhook', '"version"' ) as $needle ) {
					$this->assertStringNotContainsString( $needle, (string) $value, $column );
				}
			}
		}
	}

	/**
	 * Full success: all three phases with every diff category, next to unchanged options and Cron.
	 */
	public function test_full_success() {
		$row = $this->lifecycle( self::as_before(), self::as_immediate(), self::as_settled() );

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( SettleOutcome::ADMIN_SHUTDOWN, $row['settle_outcome'] );
		$this->assertSame( self::all( 'available' ), self::availability( $row ) );
		$this->assertSame( self::all( 'available' ), self::availability( $row, CronObservation::PHASES ) );

		// During update: the update request queued the database upgrade.
		$this->assertSame(
			array(
				'added'       => array( 'acme_upgrade_db' ),
				'removed'     => array(),
				'rescheduled' => array(),
				'changed'     => array(),
			),
			self::hooks( self::as_diff( $row, 'during_update' ) )
		);
		$this->assertSame( 'async', self::as_diff( $row, 'during_update' )['added'][0]['schedule_type'] );
		$this->assertSame( 'acme', self::as_diff( $row, 'during_update' )['added'][0]['group'] );

		// After update: the migration ran, the cleanup moved, the sync got a cron schedule, a report job appeared.
		$post = self::as_diff( $row, 'post_update' );
		$this->assertSame(
			array(
				'added'       => array( 'acme_report' ),
				'removed'     => array( 'acme_migrate' ),
				'rescheduled' => array( 'wc_cleanup' ),
				'changed'     => array( 'acme_sync' ),
			),
			self::hooks( $post )
		);
		$this->assertSame( 3600, $post['rescheduled'][0]['timestamp_delta'] );
		$this->assertSame( array( 'interval', 'cron' ), array( $post['changed'][0]['before_schedule_type'], $post['changed'][0]['after_schedule_type'] ) );
		$this->assertSame( '0 3 * * *', $post['changed'][0]['after_cron_expression'] );

		// Net: BEFORE → SETTLED.
		$final = self::as_diff( $row, 'final' );
		$this->assertSame(
			array(
				'added'       => array( 'acme_report', 'acme_upgrade_db' ),
				'removed'     => array( 'acme_migrate' ),
				'rescheduled' => array( 'wc_cleanup' ),
				'changed'     => array( 'acme_sync' ),
			),
			self::hooks( $final )
		);
		$this->assertSame( 3, $final['summary']['before_action_count'] );
		$this->assertSame( 4, $final['summary']['after_action_count'] );
		$this->assertSame( 1, $final['summary']['action_count_delta'] );

		$this->assert_terminal( $row );
		$this->assertSame( 3, $this->site->action_scheduler_captures, 'One Action Scheduler capture per lifecycle moment.' );
	}

	/**
	 * While awaiting settle, the snapshots are kept and the post/final phases are pending.
	 */
	public function test_awaiting_settle_state() {
		$this->site->action_scheduler = self::as_before();
		$request                      = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$row = $this->site->repository->rows[1];
		$this->assertSame( AnalysisStatus::CAPTURED, $row['status'] );
		$this->assertNotNull( $row['action_scheduler_before_snapshot'] );
		$this->assertSame(
			array(
				'during_update' => null,
				'post_update'   => null,
				'final'         => null,
			),
			self::availability( $row )
		);

		$this->site->action_scheduler = self::as_immediate();
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$request->request_ending( true );
		$row = $this->site->repository->rows[1];

		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $row['status'] );
		$this->assertNotNull( $row['action_scheduler_before_snapshot'] );
		$this->assertNotNull( $row['action_scheduler_immediate_snapshot'] );
		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => null,
				'final'         => null,
			),
			self::availability( $row )
		);
		$this->assert_no_secret();
	}

	/**
	 * No Action Scheduler on the site (the common case): every phase `not_installed`,
	 * options and Cron exactly as with a working queue.
	 */
	public function test_not_installed() {
		$row = $this->lifecycle( null, null, null );

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( self::all( ActionSchedulerPhaseReason::NOT_INSTALLED ), self::availability( $row ) );
		$this->assertSame( $this->reference_other_signals(), self::other_signals( $row ) );
		$this->assert_terminal( $row );
	}

	/**
	 * Unreadable provider states.
	 *
	 * @return array<string, array{\Throwable, string}>
	 */
	public function unavailable_states() {
		return array(
			'unsupported store'  => array( ActionSchedulerUnavailableException::unsupported_store(), ActionSchedulerPhaseReason::UNSUPPORTED_STORE ),
			'unsupported schema' => array( ActionSchedulerUnavailableException::unsupported_schema(), ActionSchedulerPhaseReason::UNSUPPORTED_SCHEMA ),
			'not initialized'    => array( ActionSchedulerUnavailableException::not_initialized(), ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE ),
			'read failed'        => array( ActionSchedulerUnavailableException::read_failed(), ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE ),
			'malformed'          => array( MalformedActionSchedulerStateException::invalid_args(), ActionSchedulerPhaseReason::MALFORMED_STATE ),
			'custom schedule'    => array( MalformedActionSchedulerStateException::unsupported_schedule(), ActionSchedulerPhaseReason::UNSUPPORTED_SCHEDULE ),
			'unexpected error'   => array( new Error( 'fatal ' . self::FAKE_SECRET ), ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE ),
		);
	}

	/**
	 * A provider state UpdateLens cannot read affects only Action Scheduler, with a safe reason.
	 *
	 * @dataProvider unavailable_states
	 * @param \Throwable $error  What the provider throws at every capture.
	 * @param string     $reason Expected reason.
	 */
	public function test_unavailable_everywhere( $error, $reason ) {
		$row = $this->lifecycle( $error, $error, $error );

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( self::all( $reason ), self::availability( $row ) );
		$this->assertSame( $this->reference_other_signals(), self::other_signals( $row ) );
		$this->assert_terminal( $row );
	}

	/**
	 * Malformed BEFORE only: during and net unavailable, after update available.
	 */
	public function test_malformed_before_only() {
		$row = $this->lifecycle( MalformedActionSchedulerStateException::invalid_schedule(), self::as_immediate(), self::as_settled() );

		$this->assertSame(
			array(
				'during_update' => ActionSchedulerPhaseReason::MALFORMED_STATE,
				'post_update'   => 'available',
				'final'         => ActionSchedulerPhaseReason::MALFORMED_STATE,
			),
			self::availability( $row )
		);
		$this->assertSame( array( 'acme_report' ), self::hooks( self::as_diff( $row, 'post_update' ) )['added'] );
		$this->assertSame( $this->reference_other_signals(), self::other_signals( $row ) );
		$this->assert_terminal( $row );
	}

	/**
	 * Malformed IMMEDIATE only: during and after unavailable, net (BEFORE → SETTLED) available.
	 */
	public function test_malformed_immediate_only() {
		$row = $this->lifecycle( self::as_before(), MalformedActionSchedulerStateException::invalid_action(), self::as_settled() );

		$this->assertSame(
			array(
				'during_update' => ActionSchedulerPhaseReason::MALFORMED_STATE,
				'post_update'   => ActionSchedulerPhaseReason::MALFORMED_STATE,
				'final'         => 'available',
			),
			self::availability( $row )
		);
		$this->assertSame( array( 'acme_report', 'acme_upgrade_db' ), self::hooks( self::as_diff( $row, 'final' ) )['added'] );
		$this->assertSame( $this->reference_other_signals(), self::other_signals( $row ) );
		$this->assert_terminal( $row );
	}

	/**
	 * Malformed SETTLED: the during diff stays, after and net unavailable.
	 */
	public function test_malformed_settled() {
		$row = $this->lifecycle( self::as_before(), self::as_immediate(), MalformedActionSchedulerStateException::unsupported_schedule() );

		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => ActionSchedulerPhaseReason::UNSUPPORTED_SCHEDULE,
				'final'         => ActionSchedulerPhaseReason::UNSUPPORTED_SCHEDULE,
			),
			self::availability( $row )
		);
		$this->assertSame( $this->reference_other_signals(), self::other_signals( $row ) );
		$this->assert_terminal( $row );
	}

	/**
	 * Action Scheduler loaded only after the update (e.g. the updated plugin
	 * started bundling it): no diff is invented across the change; only the
	 * phase with two snapshots is available.
	 */
	public function test_becomes_available_after_before() {
		$row = $this->lifecycle( null, self::as_immediate(), self::as_settled() );

		$this->assertSame(
			array(
				'during_update' => ActionSchedulerPhaseReason::NOT_INSTALLED,
				'post_update'   => 'available',
				'final'         => ActionSchedulerPhaseReason::NOT_INSTALLED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Action Scheduler gone at settle (the plugin bundling it was removed): later phases unavailable.
	 */
	public function test_becomes_unavailable_at_settle() {
		$row = $this->lifecycle( self::as_before(), self::as_immediate(), null );

		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => ActionSchedulerPhaseReason::NOT_INSTALLED,
				'final'         => ActionSchedulerPhaseReason::NOT_INSTALLED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Unsupported only at IMMEDIATE: during and after unavailable, net from the two readable snapshots.
	 */
	public function test_unsupported_store_only_at_immediate() {
		$row = $this->lifecycle( self::as_before(), ActionSchedulerUnavailableException::unsupported_store(), self::as_settled() );

		$this->assertSame(
			array(
				'during_update' => ActionSchedulerPhaseReason::UNSUPPORTED_STORE,
				'post_update'   => ActionSchedulerPhaseReason::UNSUPPORTED_STORE,
				'final'         => 'available',
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Rotated salts between BEFORE and IMMEDIATE: only the comparisons across the change are unavailable.
	 */
	public function test_fingerprint_mismatch_during_update() {
		$row = $this->lifecycle(
			self::as_before(),
			function ( FakeSite $site ) {
				$site->action_scheduler      = self::as_immediate();
				$site->action_scheduler_salt = 'rotated';
			},
			self::as_settled()
		);

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame(
			array(
				'during_update' => ActionSchedulerPhaseReason::FINGERPRINT_CONTEXT_CHANGED,
				'post_update'   => 'available',
				'final'         => ActionSchedulerPhaseReason::FINGERPRINT_CONTEXT_CHANGED,
			),
			self::availability( $row )
		);
		$this->assertSame( $this->reference_other_signals(), self::other_signals( $row ) );
		$this->assert_terminal( $row );
	}

	/**
	 * Rotated salts between IMMEDIATE and SETTLED: the stored during diff stays.
	 */
	public function test_fingerprint_mismatch_after_update() {
		$row = $this->lifecycle(
			self::as_before(),
			self::as_immediate(),
			function ( FakeSite $site ) {
				$site->action_scheduler      = self::as_settled();
				$site->action_scheduler_salt = 'rotated';
			}
		);

		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => ActionSchedulerPhaseReason::FINGERPRINT_CONTEXT_CHANGED,
				'final'         => ActionSchedulerPhaseReason::FINGERPRINT_CONTEXT_CHANGED,
			),
			self::availability( $row )
		);
		$this->assertSame( $this->reference_other_signals(), self::other_signals( $row ) );
		$this->assert_terminal( $row );
	}

	/**
	 * Salts rotated for IMMEDIATE only: BEFORE and SETTLED still compare (net available).
	 *
	 * With three captures, a context mismatch can never affect net alone
	 * (equal BEFORE/IMMEDIATE and IMMEDIATE/SETTLED contexts make BEFORE/SETTLED
	 * equal), so this is the case where net is the only available phase.
	 */
	public function test_fingerprint_mismatch_only_at_immediate() {
		$row = $this->lifecycle(
			self::as_before(),
			function ( FakeSite $site ) {
				$site->action_scheduler      = self::as_immediate();
				$site->action_scheduler_salt = 'rotated';
			},
			function ( FakeSite $site ) {
				$site->action_scheduler      = self::as_settled();
				$site->action_scheduler_salt = null;
			}
		);

		$this->assertSame(
			array(
				'during_update' => ActionSchedulerPhaseReason::FINGERPRINT_CONTEXT_CHANGED,
				'post_update'   => ActionSchedulerPhaseReason::FINGERPRINT_CONTEXT_CHANGED,
				'final'         => 'available',
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * A stored BEFORE snapshot that cannot be read back at settle: only net is lost.
	 */
	public function test_corrupt_before_snapshot_affects_net_only() {
		$row = $this->lifecycle(
			self::as_before(),
			self::as_immediate(),
			function ( FakeSite $site ) {
				$site->action_scheduler                                        = self::as_settled();
				$site->repository->rows[1]['action_scheduler_before_snapshot'] = '{"schema":1,"fingerprint_context":"x","actions":[]}';
			}
		);

		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => 'available',
				'final'         => ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Options fingerprints incompatible (global `incompatible`): Action Scheduler
	 * does not override it; its unresolved phases get `analysis_ended`.
	 */
	public function test_options_incompatibility_stays_global() {
		$row = $this->lifecycle(
			self::as_before(),
			function ( FakeSite $site ) {
				$site->action_scheduler      = self::as_immediate();
				$site->action_scheduler_salt = 'site-salt';
				$site->salt                  = 'rotated';
			},
			self::as_settled()
		);

		$this->assertSame( AnalysisStatus::INCOMPATIBLE, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_CONTEXT_CHANGED, $row['error_code'] );
		$this->assertSame( self::all( ActionSchedulerPhaseReason::ANALYSIS_ENDED ), self::availability( $row ) );
		$this->assertSame( self::all( CronPhaseReason::ANALYSIS_ENDED ), self::availability( $row, CronObservation::PHASES ) );
		$this->assert_terminal( $row );
	}

	/**
	 * Expiration: the during diff stays, after/net `settle_expired`, no late capture.
	 */
	public function test_expiration() {
		$this->site->action_scheduler = self::as_before();
		$this->site->update( self::PLUGIN );
		$this->site->action_scheduler = self::as_settled();
		$this->site->time            += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS + 1;
		$this->site->admin_page();
		$row = $this->site->repository->rows[1];

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( SettleOutcome::EXPIRED, $row['settle_outcome'] );
		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => ActionSchedulerPhaseReason::SETTLE_EXPIRED,
				'final'         => ActionSchedulerPhaseReason::SETTLE_EXPIRED,
			),
			self::availability( $row )
		);
		$this->assertSame( array(), self::hooks( self::as_diff( $row, 'during_update' ) )['added'], 'BEFORE and IMMEDIATE were equal here.' );
		$this->assertSame( 2, $this->site->action_scheduler_captures, 'No late settled capture.' );
		$this->assert_terminal( $row );
	}

	/**
	 * Expiration keeps a reason recorded earlier (first cause wins).
	 */
	public function test_expiration_keeps_earlier_reason() {
		$this->site->action_scheduler = self::as_before();
		$request                      = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$this->site->action_scheduler = ActionSchedulerUnavailableException::unsupported_schema();
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$request->request_ending( true );

		$this->site->time += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS + 1;
		$this->site->admin_page();
		$row = $this->site->repository->rows[1];

		$this->assertSame(
			array(
				'during_update' => ActionSchedulerPhaseReason::UNSUPPORTED_SCHEMA,
				'post_update'   => ActionSchedulerPhaseReason::UNSUPPORTED_SCHEMA,
				'final'         => ActionSchedulerPhaseReason::SETTLE_EXPIRED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * A failed plugin update: no Action Scheduler diff, no IMMEDIATE capture, `update_failed`.
	 */
	public function test_failed_update() {
		$this->site->action_scheduler = self::as_before();
		$request                      = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$this->site->action_scheduler = self::as_immediate();
		$request->update_finished( self::PLUGIN, 'download_failed', null );
		$request->request_ending( true );
		$row = $this->site->repository->rows[1];

		$this->assertSame( AnalysisStatus::FAILED, $row['status'] );
		$this->assertSame( 'download_failed', $row['error_code'] );
		$this->assertSame( self::all( ActionSchedulerPhaseReason::UPDATE_FAILED ), self::availability( $row ) );
		$this->assertSame( self::all( CronPhaseReason::UPDATE_FAILED ), self::availability( $row, CronObservation::PHASES ) );
		$this->assertSame( 1, $this->site->action_scheduler_captures );
		$this->assert_terminal( $row );
	}

	/**
	 * An update that never reports completion fails at its request's shutdown.
	 */
	public function test_update_not_completed() {
		$this->site->action_scheduler = self::as_before();
		$request                      = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$request->request_ending( true );
		$row = $this->site->repository->rows[1];

		$this->assertSame( AnalysisStatus::FAILED, $row['status'] );
		$this->assertSame( self::all( ActionSchedulerPhaseReason::UPDATE_FAILED ), self::availability( $row ) );
		$this->assert_terminal( $row );
	}

	/**
	 * A stale `captured` analysis is abandoned by a later admin page without a new capture.
	 */
	public function test_abandoned_stale_analysis() {
		$this->site->action_scheduler = self::as_before();
		$request                      = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		// The request died without shutdown (fatal error): no transition from it.
		$this->site->time += PluginUpdateAnalyzer::STALE_AFTER_SECONDS + 1;
		$this->site->admin_page();
		$row = $this->site->repository->rows[1];

		$this->assertSame( AnalysisStatus::ABANDONED, $row['status'] );
		$this->assertSame( self::all( ActionSchedulerPhaseReason::ANALYSIS_ABANDONED ), self::availability( $row ) );
		$this->assertSame( 1, $this->site->action_scheduler_captures, 'Abandoning reads no Action Scheduler state.' );
		$this->assert_terminal( $row );
	}

	/**
	 * An awaiting analysis abandoned by another update in the same request keeps its during diff.
	 */
	public function test_abandoned_awaiting_analysis_keeps_during_diff() {
		$this->site->action_scheduler = self::as_before();
		$request                      = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$this->site->action_scheduler = self::as_immediate();
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$this->site->start( $request, 'other/other.php', 'Other' );
		$row = $this->site->repository->rows[1];

		$this->assertSame( AnalysisStatus::ABANDONED, $row['status'] );
		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => ActionSchedulerPhaseReason::ANALYSIS_ABANDONED,
				'final'         => ActionSchedulerPhaseReason::ANALYSIS_ABANDONED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * The next update settles an awaiting analysis with the capture it takes for itself.
	 */
	public function test_next_update_settles_with_shared_capture() {
		$this->site->action_scheduler = self::as_before();
		$this->site->update( self::PLUGIN );
		$this->site->action_scheduler = self::as_settled();
		$this->site->time            += 30;
		$request                      = $this->site->request();
		$this->site->start( $request, 'other/other.php', 'Other' );
		$first = $this->site->repository->rows[1];

		$this->assertSame( SettleOutcome::NEXT_UPDATE, $first['settle_outcome'] );
		$this->assertSame( self::all( 'available' ), self::availability( $first ) );
		$this->assertSame( 3, $this->site->action_scheduler_captures, 'One capture for settling and the new BEFORE.' );
		$this->assertNotNull( $this->site->repository->rows[2]['action_scheduler_before_snapshot'] );
		$this->assert_terminal( $first );
	}

	/**
	 * Action Scheduler storage failing on every write: options, Cron and the
	 * lifecycle are identical to the reference; Action Scheduler `storage_failed`.
	 */
	public function test_storage_failure_everywhere() {
		$this->site->repository->fail_action_scheduler_writes = true;

		$row = $this->lifecycle( self::as_before(), self::as_immediate(), self::as_settled() );

		$this->assertSame( $this->reference_other_signals(), self::other_signals( $row ) );
		$this->assertSame( self::all( 'available' ), self::availability( $row, CronObservation::PHASES ) );
		$this->assertSame( self::all( ActionSchedulerPhaseReason::STORAGE_FAILED ), self::availability( $row ) );
		$this->assert_terminal( $row );
	}

	/**
	 * Action Scheduler storage failing only at IMMEDIATE: the Cron during diff
	 * survives; Action Scheduler during/after are lost, net still compares
	 * BEFORE with SETTLED.
	 */
	public function test_storage_failure_at_immediate() {
		$row = $this->lifecycle(
			self::as_before(),
			function ( FakeSite $site ) {
				$site->action_scheduler                         = self::as_immediate();
				$site->repository->fail_action_scheduler_writes = true;
			},
			function ( FakeSite $site ) {
				$site->action_scheduler                         = self::as_settled();
				$site->repository->fail_action_scheduler_writes = false;
			}
		);

		$this->assertSame( $this->reference_other_signals(), self::other_signals( $row ) );
		$this->assertNotNull( $row['cron_during_update_diff'] );
		$this->assertSame(
			array(
				'during_update' => ActionSchedulerPhaseReason::STORAGE_FAILED,
				'post_update'   => ActionSchedulerPhaseReason::STORAGE_FAILED,
				'final'         => 'available',
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Action Scheduler storage failing only at SETTLED: its during diff, options and Cron survive.
	 */
	public function test_storage_failure_at_settle() {
		$row = $this->lifecycle(
			self::as_before(),
			self::as_immediate(),
			function ( FakeSite $site ) {
				$site->action_scheduler                         = self::as_settled();
				$site->repository->fail_action_scheduler_writes = true;
			}
		);

		$this->assertSame( $this->reference_other_signals(), self::other_signals( $row ) );
		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => ActionSchedulerPhaseReason::STORAGE_FAILED,
				'final'         => ActionSchedulerPhaseReason::STORAGE_FAILED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Cron storage failing: Action Scheduler data is kept (the fallback strips only the failing signal).
	 */
	public function test_cron_storage_failure_keeps_action_scheduler() {
		$this->site->repository->fail_cron_writes = true;

		$row = $this->lifecycle( self::as_before(), self::as_immediate(), self::as_settled() );

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( self::all( CronPhaseReason::STORAGE_FAILED ), self::availability( $row, CronObservation::PHASES ) );
		$this->assertSame( self::all( 'available' ), self::availability( $row ) );
		$this->assert_terminal( $row );
	}

	/**
	 * Both signals failing: options still complete; each signal `storage_failed`.
	 */
	public function test_both_signals_storage_failure() {
		$this->site->repository->fail_cron_writes             = true;
		$this->site->repository->fail_action_scheduler_writes = true;

		$row = $this->lifecycle( self::as_before(), self::as_immediate(), self::as_settled() );

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		foreach ( array( 'options_during_update_diff', 'options_post_update_diff', 'options_final_diff' ) as $column ) {
			$this->assertNotNull( $row[ $column ] );
		}
		$this->assertSame( self::all( CronPhaseReason::STORAGE_FAILED ), self::availability( $row, CronObservation::PHASES ) );
		$this->assertSame( self::all( ActionSchedulerPhaseReason::STORAGE_FAILED ), self::availability( $row ) );
		$this->assert_terminal( $row );
	}

	/**
	 * The fallback order: everything, then without Action Scheduler payload
	 * (Cron payload still written), and stop at the first success.
	 */
	public function test_fallback_strips_action_scheduler_first() {
		$this->site->action_scheduler                         = self::as_before();
		$this->site->repository->fail_action_scheduler_writes = true;
		$this->site->start( $this->site->request(), self::PLUGIN );

		$attempts = $this->site->repository->attempted;
		$this->assertCount( 2, $attempts );
		$this->assertIsString( $attempts[0]['action_scheduler_before_snapshot'] );
		$this->assertIsString( $attempts[0]['cron_before_snapshot'] );
		$this->assertNull( $attempts[1]['action_scheduler_before_snapshot'] );
		$this->assertSame( $attempts[0]['cron_before_snapshot'], $attempts[1]['cron_before_snapshot'] );
		$this->assertSame( $attempts[0]['options_before_snapshot'], $attempts[1]['options_before_snapshot'] );
		$this->assertSame( ActionSchedulerPhaseReason::STORAGE_FAILED, $attempts[1]['action_scheduler_during_update_reason'] );
		$this->assertSame( ActionSchedulerPhaseReason::STORAGE_FAILED, $attempts[1]['action_scheduler_final_reason'] );
	}

	/**
	 * A write failing for another reason: Action Scheduler without payload adds
	 * no retry, so the attempts are the same as before Action Scheduler
	 * observation existed (everything, then without the Cron payload).
	 */
	public function test_no_extra_retry_without_action_scheduler_payload() {
		$this->site->repository->fail_writes = true;
		$this->site->start( $this->site->request(), self::PLUGIN );

		$attempts = $this->site->repository->attempted;
		$this->assertCount( 2, $attempts );
		$this->assertIsString( $attempts[0]['cron_before_snapshot'] );
		$this->assertNull( $attempts[1]['cron_before_snapshot'] );
		foreach ( $attempts as $attempt ) {
			$this->assertSame( ActionSchedulerPhaseReason::NOT_INSTALLED, $attempt['action_scheduler_during_update_reason'] );
			$this->assertArrayNotHasKey( 'action_scheduler_before_snapshot', $attempt );
		}
		$this->assertSame( array(), $this->site->repository->rows );
	}

	/**
	 * Corrupt stored IMMEDIATE snapshot: only the after-update phase is lost.
	 */
	public function test_corrupt_immediate_snapshot_affects_post_update_only() {
		$this->site->action_scheduler = self::as_before();
		$request                      = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$this->site->action_scheduler = self::as_immediate();
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$request->request_ending( true );
		$this->site->repository->rows[1]['action_scheduler_immediate_snapshot'] = '{"schema":1,"fingerprint_context":';

		$this->site->time            += 60;
		$this->site->action_scheduler = self::as_settled();
		$this->site->admin_page();
		$row = $this->site->repository->rows[1];

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE,
				'final'         => 'available',
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * An analysis awaiting settle from before Action Scheduler observation (schema
	 * upgraded meanwhile: columns NULL) settles with `not_captured`, nothing invented.
	 */
	public function test_analysis_without_action_scheduler_capture() {
		$this->site->action_scheduler = self::as_before();
		$this->site->update( self::PLUGIN );
		foreach ( array_keys( InMemoryAnalysisRepository::COLUMNS ) as $column ) {
			if ( 0 === strpos( $column, 'action_scheduler_' ) ) {
				$this->site->repository->rows[1][ $column ] = null;
			}
		}

		$this->site->time            += 60;
		$this->site->action_scheduler = self::as_settled();
		$this->site->admin_page();
		$row = $this->site->repository->rows[1];

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( self::all( ActionSchedulerPhaseReason::NOT_CAPTURED ), self::availability( $row ) );
		$this->assertSame( self::all( 'available' ), self::availability( $row, CronObservation::PHASES ) );
		$this->assert_terminal( $row );
	}

	/**
	 * Without a capture callable (older callers), Action Scheduler is `not_installed`;
	 * a capture that throws an Error never reaches the caller.
	 */
	public function test_failures_never_throw() {
		$repository = new InMemoryAnalysisRepository();
		$analyzer   = new PluginUpdateAnalyzer(
			$repository,
			static function () {
				return new OptionsSnapshot( array(), 'hmac-sha256-v1:' . str_repeat( '0', 64 ) );
			},
			static function () {
				return new CronSnapshot( array(), 'cron-args-hmac-sha256-v1:' . str_repeat( '0', 64 ) );
			},
			function () {
				return $this->site->time;
			},
			static function () {
				throw new Error( 'fatal in action scheduler capture ' . self::FAKE_SECRET ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test exception; never output.
			}
		);

		$analyzer->update_starting( self::PLUGIN, array( 'version' => '1.0.0' ), 1 );
		$analyzer->update_finished( self::PLUGIN, null, '1.1.0' );
		$analyzer->request_ending( true );
		$row = $repository->rows[1];

		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $row['status'] );
		$this->assertSame( self::all( ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE ), self::availability( $row ) );
		$this->assertNotNull( $row['cron_during_update_diff'] );

		$this->assertSame( ActionSchedulerPhaseReason::NOT_INSTALLED, ( new ActionSchedulerObservation() )->capture() );
	}

	/**
	 * Provider reasons map to a small, fixed public set.
	 */
	public function test_reason_mapping() {
		$this->assertSame( ActionSchedulerPhaseReason::NOT_INSTALLED, ActionSchedulerPhaseReason::from_unavailable( ActionSchedulerUnavailableException::NOT_INSTALLED ) );
		$this->assertSame( ActionSchedulerPhaseReason::UNSUPPORTED_STORE, ActionSchedulerPhaseReason::from_unavailable( ActionSchedulerUnavailableException::UNSUPPORTED_STORE ) );
		$this->assertSame( ActionSchedulerPhaseReason::UNSUPPORTED_SCHEMA, ActionSchedulerPhaseReason::from_unavailable( ActionSchedulerUnavailableException::UNSUPPORTED_SCHEMA ) );
		$this->assertSame( ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE, ActionSchedulerPhaseReason::from_unavailable( ActionSchedulerUnavailableException::NOT_INITIALIZED ) );
		$this->assertSame( ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE, ActionSchedulerPhaseReason::from_unavailable( ActionSchedulerUnavailableException::READ_FAILED ) );
		$this->assertSame( ActionSchedulerPhaseReason::SNAPSHOT_UNAVAILABLE, ActionSchedulerPhaseReason::from_unavailable( 'anything else' ) );
		$this->assertSame( ActionSchedulerPhaseReason::UNSUPPORTED_SCHEDULE, ActionSchedulerPhaseReason::from_malformed( MalformedActionSchedulerStateException::UNSUPPORTED_SCHEDULE ) );
		foreach ( array( MalformedActionSchedulerStateException::INVALID_ACTION, MalformedActionSchedulerStateException::INVALID_ARGS, MalformedActionSchedulerStateException::INVALID_SCHEDULE ) as $malformed ) {
			$this->assertSame( ActionSchedulerPhaseReason::MALFORMED_STATE, ActionSchedulerPhaseReason::from_malformed( $malformed ) );
		}
		$this->assertSame( ActionSchedulerPhaseReason::ALL, array_values( array_unique( ActionSchedulerPhaseReason::ALL ) ) );
		$this->assertCount( 14, ActionSchedulerPhaseReason::ALL );
	}

	/**
	 * Action Scheduler is not exposed yet: history and report output are the
	 * same with and without Action Scheduler data, and name no Action Scheduler field.
	 */
	public function test_reports_unchanged() {
		$this->lifecycle( self::as_before(), self::as_immediate(), self::as_settled() );
		$with = $this->site;

		$this->site = new FakeSite( array( 'blogname' => array( 'Site', 'on' ) ), self::T );
		$this->lifecycle( null, null, null );
		$without = $this->site;

		$outputs = array();
		foreach ( array( $with, $without ) as $site ) {
			$reports   = new AnalysisReports(
				$site->repository,
				$site->request(),
				static function () {
					return true;
				}
			);
			$outputs[] = array( $reports->history( 1, 20 ), $reports->report( 1 ) );
		}

		$this->assertSame( $outputs[1], $outputs[0] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test.
		$json = json_encode( $outputs[0] );
		foreach ( array( 'action_scheduler', 'acme_upgrade_db', 'wc_cleanup', self::FAKE_SECRET ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}
}
