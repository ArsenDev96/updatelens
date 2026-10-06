<?php
/**
 * Tests for WP-Cron observation inside the plugin update analysis lifecycle.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use Error;
use PHPUnit\Framework\TestCase;
use UpdateLens\Snapshot\OptionsSnapshot;
use UpdateLens\Storage\CronDiffCodec;
use UpdateLens\Tests\Support\CronFixture;
use UpdateLens\Tests\Support\FakeSite;
use UpdateLens\Update\AnalysisStatus;
use UpdateLens\Update\CronObservation;
use UpdateLens\Update\CronPhaseReason;
use UpdateLens\Update\PluginUpdateAnalyzer;
use UpdateLens\Update\SettleOutcome;

/**
 * BEFORE → IMMEDIATE → SETTLED for WP-Cron, independent of the options signal.
 */
final class CronLifecycleTest extends TestCase {

	const PLUGIN = 'acme/acme.php';

	/**
	 * Obviously fake credential used in privacy fixtures.
	 */
	const FAKE_SECRET = 'sk_test_UPDATE_LENS_CRON_LIFECYCLE_SECRET';

	const T = 1767225600; // 2026-01-01T00:00:00Z.

	const SNAPSHOT_COLUMNS = array( 'options_before_snapshot', 'options_immediate_snapshot', 'cron_before_snapshot', 'cron_immediate_snapshot' );

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
	 * Cron before the update: a plugin cleanup job (with secret arguments) and a core job.
	 *
	 * @return array
	 */
	private static function cron_before() {
		return CronFixture::cron(
			array(
				CronFixture::recurring( self::T + 3600, 'acme_cleanup', 'daily', array( 'token' => self::FAKE_SECRET ) ),
				CronFixture::recurring( self::T + 600, 'wp_version_check', 'twicedaily' ),
			)
		);
	}

	/**
	 * Cron right after the update request: the update scheduled a one-time job.
	 *
	 * @return array
	 */
	private static function cron_immediate() {
		return CronFixture::cron(
			array(
				CronFixture::recurring( self::T + 3600, 'acme_cleanup', 'daily', array( 'token' => self::FAKE_SECRET ) ),
				CronFixture::recurring( self::T + 600, 'wp_version_check', 'twicedaily' ),
				CronFixture::single( self::T + 120, 'acme_update_once', array( 'https://hooks.example.test/' . self::FAKE_SECRET ) ),
			)
		);
	}

	/**
	 * Cron at settle: the new version moved its cleanup job and added a report job.
	 *
	 * @return array
	 */
	private static function cron_settled() {
		return CronFixture::cron(
			array(
				CronFixture::recurring( self::T + 7200, 'acme_cleanup', 'daily', array( 'token' => self::FAKE_SECRET ) ),
				CronFixture::recurring( self::T + 600, 'wp_version_check', 'twicedaily' ),
				CronFixture::single( self::T + 120, 'acme_update_once', array( 'https://hooks.example.test/' . self::FAKE_SECRET ) ),
				CronFixture::recurring( self::T + 900, 'acme_report', 'hourly' ),
			)
		);
	}

	/**
	 * Cron state with one malformed plugin entry (carrying the secret).
	 *
	 * @param array $cron Valid cron state.
	 * @return array
	 */
	private static function malformed( array $cron ) {
		$cron[ self::T + 99 ]['acme_broken'] = array( 'k' => 'token=' . self::FAKE_SECRET );

		return $cron;
	}

	/**
	 * A full lifecycle: BEFORE, update request (IMMEDIATE + its shutdown), a
	 * later admin page (SETTLED). Options change at each step too.
	 *
	 * Each step's Cron state is a value or a callable run on the site before the step.
	 *
	 * @param mixed $before    Cron state (or callable) before the update.
	 * @param mixed $immediate Cron state (or callable) before the update reports completion.
	 * @param mixed $settled   Cron state (or callable) before the admin page.
	 * @return array The analysis row.
	 */
	private function lifecycle( $before, $immediate, $settled ) {
		$this->step( $before );
		$request = $this->site->request();
		$this->site->start( $request, self::PLUGIN );

		$this->site->options['acme_version'] = array( '1.1.0', 'on' );
		$this->step( $immediate );
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$request->request_ending( true );

		$this->site->time                    += 60;
		$this->site->options['acme_settings'] = array( 'migrated', 'off' );
		$this->step( $settled );
		$this->site->admin_page();

		return $this->site->repository->rows[1];
	}

	/**
	 * Apply a step's Cron state.
	 *
	 * @param mixed $state Cron state or callable.
	 * @return void
	 */
	private function step( $state ) {
		if ( is_callable( $state ) ) {
			$state( $this->site );
		} else {
			$this->site->cron = $state;
		}
	}

	/**
	 * Options columns of a reference lifecycle with valid Cron state.
	 *
	 * @return array
	 */
	private function reference_options_columns() {
		$site       = $this->site;
		$this->site = new FakeSite( array( 'blogname' => array( 'Site', 'on' ) ), self::T );
		$row        = $this->lifecycle( self::cron_before(), self::cron_immediate(), self::cron_settled() );
		$this->site = $site;

		return self::options_columns( $row );
	}

	/**
	 * The options signal of a row.
	 *
	 * @param array $row Analysis.
	 * @return array
	 */
	private static function options_columns( array $row ) {
		return array_intersect_key(
			$row,
			array_flip( array( 'status', 'settle_outcome', 'error_code', 'options_during_update_diff', 'options_post_update_diff', 'options_final_diff' ) )
		);
	}

	/**
	 * Decoded Cron diff of a phase.
	 *
	 * @param array  $row   Analysis.
	 * @param string $phase ObservationPhase.
	 * @return array
	 */
	private static function cron_diff( array $row, $phase ) {
		return ( new CronDiffCodec() )->decode( $row[ CronObservation::PHASES[ $phase ][0] ] );
	}

	/**
	 * Per phase: 'available' or the reason.
	 *
	 * @param array $row Analysis.
	 * @return array<string, string|null>
	 */
	private static function availability( array $row ) {
		$result = array();
		foreach ( CronObservation::PHASES as $phase => $columns ) {
			list( $diff, $reason ) = $columns;
			$result[ $phase ]      = null !== $row[ $diff ] ? 'available' : $row[ $reason ];
		}

		return $result;
	}

	/**
	 * Hooks per section of a decoded Cron diff.
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
	 * Terminal invariants: no temporary snapshot is left, every Cron phase has
	 * exactly one of a diff or a reason, and no secret was stored.
	 *
	 * @param array $row Analysis.
	 * @return void
	 */
	private function assert_terminal( array $row ) {
		$this->assertNotContains( $row['status'], array( AnalysisStatus::CAPTURED, AnalysisStatus::AWAITING_SETTLE ) );
		foreach ( self::SNAPSHOT_COLUMNS as $column ) {
			$this->assertNull( $row[ $column ], $column );
		}
		foreach ( CronObservation::PHASES as $phase => $columns ) {
			$this->assertTrue( null === $row[ $columns[0] ] xor null === $row[ $columns[1] ], "{$phase}: exactly one of diff or reason" );
		}
		$this->assert_no_secret();
	}

	/**
	 * The fake secret, its readable parts and core's md5 key appear in no stored column.
	 *
	 * @return void
	 */
	private function assert_no_secret() {
		$md5 = md5( serialize( array( 'token' => self::FAKE_SECRET ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Core's unkeyed event key.
		foreach ( $this->site->repository->rows as $row ) {
			foreach ( $row as $column => $value ) {
				foreach ( array( self::FAKE_SECRET, 'hooks.example.test', 'token', $md5 ) as $needle ) {
					$this->assertStringNotContainsString( $needle, (string) $value, $column );
				}
			}
		}
	}

	/**
	 * Full success: all three Cron phases next to the unchanged options analysis.
	 */
	public function test_full_success() {
		$row = $this->lifecycle( self::cron_before(), self::cron_immediate(), self::cron_settled() );

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( SettleOutcome::ADMIN_SHUTDOWN, $row['settle_outcome'] );
		$this->assertNull( $row['error_code'] );
		foreach ( array( 'options_during_update_diff', 'options_post_update_diff', 'options_final_diff' ) as $column ) {
			$this->assertNotNull( $row[ $column ] );
		}
		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => 'available',
				'final'         => 'available',
			),
			self::availability( $row )
		);

		// During update: the update request scheduled one job.
		$during = self::cron_diff( $row, 'during_update' );
		$this->assertSame( 1, $during['summary']['added_count'] );
		$this->assertSame(
			array(
				'added'       => array( 'acme_update_once' ),
				'removed'     => array(),
				'rescheduled' => array(),
				'changed'     => array(),
			),
			self::hooks( $during )
		);

		// After update: an existing recurring job moved, a new one appeared.
		$post = self::cron_diff( $row, 'post_update' );
		$this->assertSame( 1, $post['summary']['rescheduled_count'] );
		$this->assertSame(
			array(
				'added'       => array( 'acme_report' ),
				'removed'     => array(),
				'rescheduled' => array( 'acme_cleanup' ),
				'changed'     => array(),
			),
			self::hooks( $post )
		);
		$this->assertSame( 3600, $post['rescheduled'][0]['timestamp_delta'] );

		// Net: BEFORE → SETTLED.
		$this->assertSame(
			array(
				'added'       => array( 'acme_report', 'acme_update_once' ),
				'removed'     => array(),
				'rescheduled' => array( 'acme_cleanup' ),
				'changed'     => array(),
			),
			self::hooks( self::cron_diff( $row, 'final' ) )
		);
		$this->assertSame( 2, self::cron_diff( $row, 'final' )['summary']['event_count_delta'] );

		$this->assert_terminal( $row );
		$this->assertSame( 3, $this->site->cron_captures, 'One Cron capture per lifecycle moment.' );
	}

	/**
	 * While awaiting settle, the Cron snapshots are kept and the post/final phases are pending.
	 */
	public function test_awaiting_settle_state() {
		$this->site->cron = self::cron_before();
		$request          = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$row = $this->site->repository->rows[1];
		$this->assertNotNull( $row['cron_before_snapshot'] );
		$this->assertNull( $row['cron_during_update_reason'] );

		$this->site->cron = self::cron_immediate();
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$request->request_ending( true );

		$row = $this->site->repository->rows[1];
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $row['status'] );
		$this->assertNotNull( $row['cron_before_snapshot'] );
		$this->assertNotNull( $row['cron_immediate_snapshot'] );
		$this->assertNotNull( $row['cron_during_update_diff'] );
		foreach ( array( 'cron_post_update_diff', 'cron_final_diff', 'cron_post_update_reason', 'cron_final_reason' ) as $pending ) {
			$this->assertNull( $row[ $pending ], $pending );
		}
		$this->assert_no_secret();
	}

	/**
	 * Unrelated (core) Cron movement between IMMEDIATE and SETTLED is kept as observed, not filtered.
	 */
	public function test_unrelated_core_reschedule_is_preserved() {
		$settled = CronFixture::cron(
			array(
				CronFixture::recurring( self::T + 3600, 'acme_cleanup', 'daily', array( 'token' => self::FAKE_SECRET ) ),
				CronFixture::recurring( self::T + 600 + 43200, 'wp_version_check', 'twicedaily' ),
				CronFixture::single( self::T + 120, 'acme_update_once', array( 'https://hooks.example.test/' . self::FAKE_SECRET ) ),
			)
		);

		$row  = $this->lifecycle( self::cron_before(), self::cron_immediate(), $settled );
		$post = self::cron_diff( $row, 'post_update' );

		$this->assertSame( array( 'wp_version_check' ), array_column( $post['rescheduled'], 'hook' ) );
		$this->assertSame( 43200, $post['rescheduled'][0]['timestamp_delta'] );
		$this->assert_terminal( $row );
	}

	/**
	 * Malformed state throughout: Cron is unavailable in every phase, options complete unchanged.
	 */
	public function test_malformed_before() {
		$row = $this->lifecycle( self::malformed( self::cron_before() ), self::malformed( self::cron_immediate() ), self::malformed( self::cron_settled() ) );

		$this->assertSame( $this->reference_options_columns(), self::options_columns( $row ) );
		$this->assertSame(
			array(
				'during_update' => CronPhaseReason::MALFORMED_CRON_STATE,
				'post_update'   => CronPhaseReason::MALFORMED_CRON_STATE,
				'final'         => CronPhaseReason::MALFORMED_CRON_STATE,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Malformed only at BEFORE: no during/final comparison, but after-update still works.
	 */
	public function test_malformed_before_only() {
		$row = $this->lifecycle( self::malformed( self::cron_before() ), self::cron_immediate(), self::cron_settled() );

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame(
			array(
				'during_update' => CronPhaseReason::MALFORMED_CRON_STATE,
				'post_update'   => 'available',
				'final'         => CronPhaseReason::MALFORMED_CRON_STATE,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Malformed IMMEDIATE: during and after unavailable, net still computed from BEFORE → SETTLED.
	 */
	public function test_malformed_immediate() {
		$row = $this->lifecycle( self::cron_before(), self::malformed( self::cron_immediate() ), self::cron_settled() );

		$this->assertSame( $this->reference_options_columns(), self::options_columns( $row ) );
		$this->assertSame(
			array(
				'during_update' => CronPhaseReason::MALFORMED_CRON_STATE,
				'post_update'   => CronPhaseReason::MALFORMED_CRON_STATE,
				'final'         => 'available',
			),
			self::availability( $row )
		);
		$this->assertSame( array( 'acme_report', 'acme_update_once' ), array_column( self::cron_diff( $row, 'final' )['added'], 'hook' ) );
		$this->assert_terminal( $row );
	}

	/**
	 * Malformed SETTLED: during stays available, after and net unavailable.
	 */
	public function test_malformed_settled() {
		$row = $this->lifecycle( self::cron_before(), self::cron_immediate(), self::malformed( self::cron_settled() ) );

		$this->assertSame( $this->reference_options_columns(), self::options_columns( $row ) );
		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => CronPhaseReason::MALFORMED_CRON_STATE,
				'final'         => CronPhaseReason::MALFORMED_CRON_STATE,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Any capture error (not only malformed state) degrades the same way.
	 */
	public function test_capture_error_is_snapshot_unavailable() {
		$row = $this->lifecycle(
			self::cron_before(),
			self::cron_immediate(),
			static function ( FakeSite $site ) {
				$site->cron_salt = ''; // The hasher rejects an empty secret: a capture error that is not malformed state.
			}
		);

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( CronPhaseReason::SNAPSHOT_UNAVAILABLE, $row['cron_post_update_reason'] );
		$this->assertSame( CronPhaseReason::SNAPSHOT_UNAVAILABLE, $row['cron_final_reason'] );
		$this->assertNotNull( $row['cron_during_update_diff'] );
		$this->assert_terminal( $row );
	}

	/**
	 * Cron context change between BEFORE and IMMEDIATE: only during and net are affected.
	 */
	public function test_fingerprint_mismatch_during_update() {
		$row = $this->lifecycle(
			self::cron_before(),
			function ( FakeSite $site ) {
				$site->cron      = self::cron_immediate();
				$site->cron_salt = 'rotated-cron-salt';
			},
			self::cron_settled()
		);

		$this->assertSame( $this->reference_options_columns(), self::options_columns( $row ) );
		$this->assertSame(
			array(
				'during_update' => CronPhaseReason::FINGERPRINT_CONTEXT_CHANGED,
				'post_update'   => 'available',
				'final'         => CronPhaseReason::FINGERPRINT_CONTEXT_CHANGED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Cron context change between IMMEDIATE and SETTLED: during stays, after and net are affected.
	 */
	public function test_fingerprint_mismatch_after_update() {
		$row = $this->lifecycle(
			self::cron_before(),
			self::cron_immediate(),
			function ( FakeSite $site ) {
				$site->cron      = self::cron_settled();
				$site->cron_salt = 'rotated-cron-salt';
			}
		);

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertNull( $row['error_code'] );
		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => CronPhaseReason::FINGERPRINT_CONTEXT_CHANGED,
				'final'         => CronPhaseReason::FINGERPRINT_CONTEXT_CHANGED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Options incompatibility keeps its global behavior; Cron phases not yet observed end with it.
	 */
	public function test_options_incompatibility_stays_global() {
		$row = $this->lifecycle(
			self::cron_before(),
			function ( FakeSite $site ) {
				$site->cron = self::cron_immediate();
				$site->salt = 'rotated-salts'; // Options and Cron.
			},
			self::cron_settled()
		);

		$this->assertSame( AnalysisStatus::INCOMPATIBLE, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_CONTEXT_CHANGED, $row['error_code'] );
		$this->assertSame(
			array(
				'during_update' => CronPhaseReason::ANALYSIS_ENDED,
				'post_update'   => CronPhaseReason::ANALYSIS_ENDED,
				'final'         => CronPhaseReason::ANALYSIS_ENDED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Expired settle window: during kept, after/net `settle_expired`, no late Cron capture, snapshots cleared.
	 */
	public function test_expiration() {
		$this->site->cron = self::cron_before();
		$this->site->update( self::PLUGIN );
		$captures = $this->site->cron_captures;

		$this->site->time += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS + 1;
		$this->site->cron  = self::cron_settled();
		$this->site->admin_page();
		$row = $this->site->repository->rows[1];

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( SettleOutcome::EXPIRED, $row['settle_outcome'] );
		$this->assertSame( $captures, $this->site->cron_captures, 'No late Cron snapshot.' );
		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => CronPhaseReason::SETTLE_EXPIRED,
				'final'         => CronPhaseReason::SETTLE_EXPIRED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Expiry keeps an earlier, more specific Cron reason (first cause wins).
	 */
	public function test_expiration_keeps_earlier_reason() {
		$this->site->cron = self::cron_before();
		$request          = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$this->site->cron = self::malformed( self::cron_immediate() );
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$request->request_ending( true );

		$this->site->time += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS + 1;
		$this->site->request()->expire_overdue();
		$row = $this->site->repository->rows[1];

		$this->assertSame(
			array(
				'during_update' => CronPhaseReason::MALFORMED_CRON_STATE,
				'post_update'   => CronPhaseReason::MALFORMED_CRON_STATE,
				'final'         => CronPhaseReason::SETTLE_EXPIRED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Failed update: no IMMEDIATE Cron capture, no Cron diffs, snapshots cleared.
	 */
	public function test_failed_update() {
		$this->site->cron = self::cron_before();
		$request          = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$request->update_finished( self::PLUGIN, 'download_failed', null );
		$request->request_ending( true );
		$row = $this->site->repository->rows[1];

		$this->assertSame( AnalysisStatus::FAILED, $row['status'] );
		$this->assertSame( 'download_failed', $row['error_code'] );
		$this->assertSame( 1, $this->site->cron_captures );
		$this->assertSame(
			array(
				'during_update' => CronPhaseReason::UPDATE_FAILED,
				'post_update'   => CronPhaseReason::UPDATE_FAILED,
				'final'         => CronPhaseReason::UPDATE_FAILED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * An update that never reports completion fails at shutdown the same way.
	 */
	public function test_update_not_completed() {
		$this->site->cron = self::cron_before();
		$request          = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$request->request_ending( true );
		$row = $this->site->repository->rows[1];

		$this->assertSame( PluginUpdateAnalyzer::ERROR_UPDATE_NOT_COMPLETED, $row['error_code'] );
		$this->assertSame( CronPhaseReason::UPDATE_FAILED, $row['cron_final_reason'] );
		$this->assert_terminal( $row );
	}

	/**
	 * Stale `captured` analysis abandoned by a later admin page (loaded with metadata columns only).
	 */
	public function test_abandoned_stale_analysis() {
		$this->site->cron = self::cron_before();
		$this->site->start( $this->site->request(), self::PLUGIN );

		$this->site->time += PluginUpdateAnalyzer::STALE_AFTER_SECONDS + 1;
		$this->site->admin_page();
		$row = $this->site->repository->rows[1];

		$this->assertSame( AnalysisStatus::ABANDONED, $row['status'] );
		$this->assertSame(
			array(
				'during_update' => CronPhaseReason::ANALYSIS_ABANDONED,
				'post_update'   => CronPhaseReason::ANALYSIS_ABANDONED,
				'final'         => CronPhaseReason::ANALYSIS_ABANDONED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Abandoned while awaiting settle (another update in the same request): the during diff stays.
	 */
	public function test_abandoned_awaiting_analysis_keeps_during_diff() {
		$this->site->cron = self::cron_before();
		$request          = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$this->site->cron = self::cron_immediate();
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$this->site->start( $request, 'other/other.php' );
		$row = $this->site->repository->rows[1];

		$this->assertSame( AnalysisStatus::ABANDONED, $row['status'] );
		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => CronPhaseReason::ANALYSIS_ABANDONED,
				'final'         => CronPhaseReason::ANALYSIS_ABANDONED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Settling on the next update: the same Cron capture is SETTLED for the old analysis and BEFORE for the new one.
	 */
	public function test_next_update_settles_with_shared_capture() {
		$this->site->cron = self::cron_before();
		$this->site->update( self::PLUGIN );

		$this->site->time += 60;
		$this->site->cron  = self::cron_settled();
		$captures          = $this->site->cron_captures;
		$this->site->start( $this->site->request(), 'other/other.php' );

		$first = $this->site->repository->rows[1];
		$this->assertSame( SettleOutcome::NEXT_UPDATE, $first['settle_outcome'] );
		$this->assertSame( $captures + 1, $this->site->cron_captures );
		$this->assertNotNull( $first['cron_final_diff'] );
		$this->assertNotNull( $this->site->repository->rows[2]['cron_before_snapshot'] );
		$this->assert_terminal( $first );
	}

	/**
	 * Cron storage failing on every write: the options analysis is untouched, Cron phases `storage_failed`.
	 */
	public function test_storage_failure_everywhere() {
		$this->site->repository->fail_cron_writes = true;

		$row = $this->lifecycle( self::cron_before(), self::cron_immediate(), self::cron_settled() );

		$this->assertSame( $this->reference_options_columns(), self::options_columns( $row ) );
		$this->assertSame(
			array(
				'during_update' => CronPhaseReason::STORAGE_FAILED,
				'post_update'   => CronPhaseReason::STORAGE_FAILED,
				'final'         => CronPhaseReason::STORAGE_FAILED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Cron storage failing only at settle: the stored during diff stays.
	 */
	public function test_storage_failure_at_settle() {
		$row = $this->lifecycle(
			self::cron_before(),
			self::cron_immediate(),
			function ( FakeSite $site ) {
				$site->cron                         = self::cron_settled();
				$site->repository->fail_cron_writes = true;
			}
		);

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => CronPhaseReason::STORAGE_FAILED,
				'final'         => CronPhaseReason::STORAGE_FAILED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * Corrupt stored Cron IMMEDIATE: only the after-update phase is lost.
	 *
	 * Options available; Cron during available, after unavailable, net available.
	 */
	public function test_corrupt_immediate_snapshot_affects_post_update_only() {
		$this->site->cron = self::cron_before();
		$request          = $this->site->request();
		$this->site->start( $request, self::PLUGIN );
		$this->site->cron = self::cron_immediate();
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$request->request_ending( true );
		$this->site->repository->rows[1]['cron_immediate_snapshot'] = '{"schema":1,"fingerprint_context":';

		$this->site->time += 60;
		$this->site->cron  = self::cron_settled();
		$this->site->admin_page();
		$row = $this->site->repository->rows[1];

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertNotNull( $row['options_post_update_diff'] );
		$this->assertSame(
			array(
				'during_update' => 'available',
				'post_update'   => CronPhaseReason::SNAPSHOT_UNAVAILABLE,
				'final'         => 'available',
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * An open analysis from before Cron observation (no Cron columns written) ends with `not_captured`.
	 */
	public function test_analysis_without_cron_capture() {
		$this->site->cron = self::cron_before();
		$this->site->update( self::PLUGIN );
		foreach ( array( 'cron_before_snapshot', 'cron_immediate_snapshot', 'cron_during_update_diff' ) as $column ) {
			$this->site->repository->rows[1][ $column ] = null;
		}

		$this->site->time += 60;
		$this->site->admin_page();
		$row = $this->site->repository->rows[1];

		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame(
			array(
				'during_update' => CronPhaseReason::NOT_CAPTURED,
				'post_update'   => CronPhaseReason::NOT_CAPTURED,
				'final'         => CronPhaseReason::NOT_CAPTURED,
			),
			self::availability( $row )
		);
		$this->assert_terminal( $row );
	}

	/**
	 * A Cron capture that throws never reaches the caller.
	 */
	public function test_cron_failures_never_throw() {
		$repository = $this->site->repository;
		$analyzer   = new PluginUpdateAnalyzer(
			$repository,
			static function () {
				return new OptionsSnapshot( array(), 'hmac-sha256-v1:' . str_repeat( '0', 64 ) );
			},
			static function () {
				throw new Error( 'fatal in cron capture' );
			},
			function () {
				return $this->site->time;
			}
		);

		$analyzer->update_starting( self::PLUGIN, array( 'version' => '1.0.0' ), 1 );
		$analyzer->update_finished( self::PLUGIN, null, '1.1.0' );
		$analyzer->request_ending( true );
		$row = $repository->rows[1];

		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $row['status'] );
		$this->assertSame( CronPhaseReason::SNAPSHOT_UNAVAILABLE, $row['cron_during_update_reason'] );
		$this->assertSame( CronPhaseReason::SNAPSHOT_UNAVAILABLE, $row['cron_post_update_reason'] );
		$this->assertSame( CronPhaseReason::SNAPSHOT_UNAVAILABLE, $row['cron_final_reason'] );
	}
}
