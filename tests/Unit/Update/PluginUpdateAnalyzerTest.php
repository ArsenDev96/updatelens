<?php
/**
 * Tests for the plugin update analysis lifecycle.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\Snapshot\AutoloadPolicy;
use UpdateLens\Snapshot\OptionNoiseFilter;
use UpdateLens\Snapshot\OptionsSnapshotBuilder;
use UpdateLens\Snapshot\OptionValueHasher;
use UpdateLens\Storage\OptionsSnapshotCodec;
use UpdateLens\Tests\Support\CronFixture;
use UpdateLens\Tests\Support\InMemoryAnalysisRepository;
use UpdateLens\Update\AnalysisStatus;
use UpdateLens\Update\PluginUpdateAnalyzer;
use UpdateLens\Update\SettleOutcome;

/**
 * BEFORE → update request → IMMEDIATE → first eligible admin request → SETTLED.
 *
 * Each PluginUpdateAnalyzer instance stands for one PHP request; the
 * repository, the fake options table and the clock persist across "requests".
 */
final class PluginUpdateAnalyzerTest extends TestCase {

	const PLUGIN = 'acme/acme.php';

	const FAKE_SECRET = 'sk_test_UPDATE_LENS_PHASE_SECRET';

	/**
	 * Start time of the scenario (2027-01-15 08:00:00 UTC).
	 */
	const T0 = 1800000000;

	/**
	 * Shared storage.
	 *
	 * @var InMemoryAnalysisRepository
	 */
	private $repository;

	/**
	 * Fake wp_options: name => [ raw value, raw autoload ].
	 *
	 * @var array<string, array{string, string}>
	 */
	private $options;

	/**
	 * Site secret used for fingerprints (change to simulate rotated salts).
	 *
	 * @var string
	 */
	private $salt;

	/**
	 * Current fake time.
	 *
	 * @var int
	 */
	private $time;

	/**
	 * When true, capturing a snapshot throws.
	 *
	 * @var bool
	 */
	private $capture_fails;

	/**
	 * Number of snapshots captured.
	 *
	 * @var int
	 */
	private $captures;

	/**
	 * Fresh scenario state.
	 *
	 * @before
	 */
	public function reset_state() {
		$this->repository    = new InMemoryAnalysisRepository();
		$this->salt          = 'site-salt';
		$this->time          = self::T0;
		$this->capture_fails = false;
		$this->captures      = 0;
		$this->options       = array(
			'siteurl'          => array( 'https://example.test', 'on' ),
			'acme_settings'    => array( 'a:1:{s:7:"api_key";s:32:"' . self::FAKE_SECRET . '";}', 'auto-off' ),
			'acme_db_version'  => array( '1.0.0', 'yes' ),
			'acme_legacy'      => array( str_repeat( 'x', 100 ), 'off' ),
			'_transient_noise' => array( 'temporary', 'off' ),
		);
	}

	/**
	 * A new "request".
	 *
	 * @return PluginUpdateAnalyzer
	 */
	private function request() {
		$capture = function () {
			if ( $this->capture_fails ) {
				throw new RuntimeException( 'capture failed' );
			}
			++$this->captures;

			$rows = array();
			foreach ( $this->options as $name => $option ) {
				$rows[] = array(
					'option_name'  => $name,
					'option_value' => $option[0],
					'autoload'     => $option[1],
				);
			}

			$builder = new OptionsSnapshotBuilder(
				new OptionValueHasher( $this->salt ),
				new OptionNoiseFilter(),
				new AutoloadPolicy( array( 'yes', 'on', 'auto-on', 'auto' ) )
			);

			return $builder->build( $rows );
		};

		return new PluginUpdateAnalyzer(
			$this->repository,
			$capture,
			function () {
				return CronFixture::snapshot( array( 'version' => 2 ), $this->salt );
			},
			function () {
				return $this->time;
			}
		);
	}

	/**
	 * The only analysis row.
	 *
	 * @return array
	 */
	private function only_row() {
		$this->assertCount( 1, $this->repository->rows );

		return reset( $this->repository->rows );
	}

	/**
	 * Decoded diff column.
	 *
	 * @param array  $row    Analysis.
	 * @param string $column Column.
	 * @return array
	 */
	private function diff( array $row, $column ) {
		$this->assertIsString( $row[ $column ], $column );

		return json_decode( $row[ $column ], true );
	}

	/**
	 * Names per section of a decoded diff.
	 *
	 * @param array $diff Decoded diff.
	 * @return array{added: string[], removed: string[], changed: string[]}
	 */
	private static function names( array $diff ) {
		return array(
			'added'   => array_column( $diff['added'], 'name' ),
			'removed' => array_column( $diff['removed'], 'name' ),
			'changed' => array_column( $diff['changed'], 'name' ),
		);
	}

	/**
	 * Start a supported update in a request.
	 *
	 * @param PluginUpdateAnalyzer $request Request.
	 * @param string               $plugin  Plugin file.
	 * @return void
	 */
	private function start( PluginUpdateAnalyzer $request, $plugin = self::PLUGIN ) {
		$request->update_starting(
			$plugin,
			array(
				'name'    => 'Acme',
				'version' => '1.0.0',
			),
			7
		);
	}

	/**
	 * Run a successful update of a plugin in its own request (update + its shutdown).
	 *
	 * @param string $plugin Plugin file.
	 * @return void
	 */
	private function update( $plugin = self::PLUGIN ) {
		$request = $this->request();
		$this->start( $request, $plugin );
		$request->update_finished( $plugin, null, '1.1.0' );
		$request->request_ending( true );
	}

	/**
	 * Full lifecycle with all three phases and the request rules.
	 */
	public function test_full_lifecycle() {
		// Request 1: the update.
		$update_request = $this->request();
		$this->start( $update_request );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::CAPTURED, $row['status'] );
		$this->assertSame( self::PLUGIN, $row['plugin_file'] );
		$this->assertSame( 'Acme', $row['plugin_name'] );
		$this->assertSame( '1.0.0', $row['version_before'] );
		$this->assertSame( 7, $row['user_id'] );
		$this->assertSame( '2027-01-15 08:00:00', $row['started_at'], 'UTC DATETIME.' );
		$this->assertSame( self::PLUGIN, $row['active_plugin'] );
		$this->assertNull( $row['settle_outcome'] );
		$before = ( new OptionsSnapshotCodec() )->decode( $row['options_before_snapshot'] );
		$this->assertSame( array( 'acme_db_version', 'acme_legacy', 'acme_settings', 'siteurl' ), array_keys( $before->get_records() ) );

		// During the update request the old code reacts to its own update.
		$this->options['acme_pending_migration'] = array( 'yes', 'off' );
		$this->time                             += 5;
		$update_request->update_finished( self::PLUGIN, null, '1.1.0' );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $row['status'] );
		$this->assertSame( '1.1.0', $row['version_after'] );
		$this->assertSame( '2027-01-15 08:05:05', $row['settle_deadline'], 'Update finish + SETTLE_WINDOW_SECONDS.' );
		$this->assertNotNull( $row['options_before_snapshot'] );
		$this->assertNotNull( $row['options_immediate_snapshot'] );
		$this->assertNull( $row['options_post_update_diff'] );
		$this->assertNull( $row['options_final_diff'] );
		$this->assertSame(
			array(
				'added'   => array( 'acme_pending_migration' ),
				'removed' => array(),
				'changed' => array(),
			),
			self::names( $this->diff( $row, 'options_during_update_diff' ) )
		);

		// The update request's own shutdown must not settle.
		$update_request->request_ending( true );
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $this->only_row()['status'] );

		// Frontend, REST, Ajax, cron, CLI: not a wp-admin page.
		$this->request()->request_ending( false );
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $this->only_row()['status'] );

		// Next wp-admin page: the new code migrates during the request.
		$this->options['acme_db_version'] = array( '1.1.0', 'yes' );
		$this->options['acme_settings']   = array( 'a:1:{s:7:"api_key";s:32:"' . self::FAKE_SECRET . '";}', 'auto-on' );
		$this->options['acme_feature']    = array( 'on', 'auto-off' );
		unset( $this->options['acme_pending_migration'], $this->options['acme_legacy'] );
		$this->time += 60;
		$this->request()->request_ending( true );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( SettleOutcome::ADMIN_SHUTDOWN, $row['settle_outcome'] );
		$this->assertNull( $row['options_before_snapshot'], 'Temporary snapshots cleared.' );
		$this->assertNull( $row['options_immediate_snapshot'] );
		$this->assertNull( $row['active_plugin'] );
		$this->assertSame( '2027-01-15 08:01:05', $row['completed_at'] );
		$this->assertNull( $row['error_code'] );

		$this->assertSame(
			array(
				'added'   => array( 'acme_feature' ),
				'removed' => array( 'acme_legacy', 'acme_pending_migration' ),
				'changed' => array( 'acme_db_version', 'acme_settings' ),
			),
			self::names( $this->diff( $row, 'options_post_update_diff' ) )
		);
		$final = $this->diff( $row, 'options_final_diff' );
		$this->assertSame(
			array(
				'added'   => array( 'acme_feature' ),
				'removed' => array( 'acme_legacy' ),
				'changed' => array( 'acme_db_version', 'acme_settings' ),
			),
			self::names( $final )
		);
		$this->assertTrue( $final['changed'][0]['value_changed'] );
		$this->assertSame( 0, $final['changed'][0]['size_delta'] );
		$this->assertTrue( $final['changed'][1]['autoload_behavior_changed'] );
		$this->assertNotNull( $row['options_during_update_diff'], 'During-update diff kept.' );

		// Final: further admin requests change nothing.
		$this->request()->request_ending( true );
		$this->assertSame( $row, $this->only_row() );
	}

	/**
	 * BEFORE {A=1} → IMMEDIATE {A=2, B=1} → SETTLED {A=3, B=1, C=1}.
	 */
	public function test_three_phases() {
		$this->options = array( 'a' => array( '1', 'on' ) );

		$request = $this->request();
		$this->start( $request );
		$this->options = array(
			'a' => array( '2', 'on' ),
			'b' => array( '1', 'on' ),
		);
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$request->request_ending( true );

		$this->options = array(
			'a' => array( '3', 'on' ),
			'b' => array( '1', 'on' ),
			'c' => array( '1', 'on' ),
		);
		$this->request()->request_ending( true );

		$row = $this->only_row();
		$this->assertSame(
			array(
				'added'   => array( 'b' ),
				'removed' => array(),
				'changed' => array( 'a' ),
			),
			self::names( $this->diff( $row, 'options_during_update_diff' ) )
		);
		$this->assertSame(
			array(
				'added'   => array( 'c' ),
				'removed' => array(),
				'changed' => array( 'a' ),
			),
			self::names( $this->diff( $row, 'options_post_update_diff' ) )
		);
		$this->assertSame(
			array(
				'added'   => array( 'b', 'c' ),
				'removed' => array(),
				'changed' => array( 'a' ),
			),
			self::names( $this->diff( $row, 'options_final_diff' ) )
		);
	}

	/**
	 * IMMEDIATE == SETTLED: empty post-update diff, final diff still valid.
	 */
	public function test_no_post_update_changes() {
		$request = $this->request();
		$this->start( $request );
		$this->options['acme_db_version'] = array( '1.1.0', 'yes' );
		$request->update_finished( self::PLUGIN, null, '1.1.0' );

		$this->request()->request_ending( true );

		$row  = $this->only_row();
		$post = $this->diff( $row, 'options_post_update_diff' );
		$this->assertSame( array(), array_merge( $post['added'], $post['removed'], $post['changed'] ) );
		$this->assertSame( 0, $post['summary']['changed_count'] );
		$this->assertSame( array( 'acme_db_version' ), self::names( $this->diff( $row, 'options_final_diff' ) )['changed'] );
		$this->assertSame( SettleOutcome::ADMIN_SHUTDOWN, $row['settle_outcome'] );
	}

	/**
	 * Seconds after the update finished → whether an admin request may still settle.
	 *
	 * @return array<string, array{int, bool}>
	 */
	public function provide_deadline() {
		return array(
			'299 seconds' => array( 299, true ),
			'300 seconds' => array( 300, true ),
			'301 seconds' => array( 301, false ),
		);
	}

	/**
	 * The settle window is inclusive of its deadline.
	 *
	 * @dataProvider provide_deadline
	 *
	 * @param int  $elapsed  Seconds after the update finished.
	 * @param bool $eligible Whether settling is still allowed.
	 */
	public function test_settle_deadline_boundary( $elapsed, $eligible ) {
		$this->update();

		$this->time += $elapsed;
		$this->request()->request_ending( true );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( $eligible ? SettleOutcome::ADMIN_SHUTDOWN : SettleOutcome::EXPIRED, $row['settle_outcome'] );
		$this->assertSame( $eligible, null !== $row['options_final_diff'] );
	}

	/**
	 * Expiring overdue analyses for readers: same boundary, never a snapshot, never a settle.
	 *
	 * @dataProvider provide_deadline
	 *
	 * @param int  $elapsed  Seconds after the update finished.
	 * @param bool $eligible Whether the analysis is still within its window.
	 */
	public function test_expire_overdue( $elapsed, $eligible ) {
		$this->start( $this->request(), 'other/other.php' ); // A `captured` analysis is left alone.
		$this->update();
		$captures = $this->captures;

		$this->time += $elapsed;
		$this->request()->expire_overdue();

		$row = $this->repository->rows[2];
		$this->assertSame( $eligible ? AnalysisStatus::AWAITING_SETTLE : AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( $eligible ? null : SettleOutcome::EXPIRED, $row['settle_outcome'] );
		$this->assertNotNull( $row['options_during_update_diff'] );
		$this->assertNull( $row['options_post_update_diff'] );
		$this->assertNull( $row['options_final_diff'] );
		$this->assertSame( $eligible, null !== $row['options_immediate_snapshot'] );
		$this->assertSame( AnalysisStatus::CAPTURED, $this->repository->rows[1]['status'] );
		$this->assertSame( $captures, $this->captures, 'No snapshot taken.' );
	}

	/**
	 * Expired: completed without a settled snapshot; during-update diff kept.
	 */
	public function test_expired_analysis() {
		$this->options['acme_db_version'] = array( '1.0.0', 'yes' );
		$request                          = $this->request();
		$this->start( $request );
		$this->options['acme_pending_migration'] = array( 'yes', 'off' );
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$request->request_ending( true );

		// Unrelated activity long after the update must not be collected.
		$this->options['unrelated'] = array( 'x', 'on' );
		$this->time                += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS + 3600;
		$captures                   = $this->captures;
		$this->request()->request_ending( true );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'], 'Not failed, incompatible or abandoned.' );
		$this->assertSame( SettleOutcome::EXPIRED, $row['settle_outcome'] );
		$this->assertSame( array( 'acme_pending_migration' ), self::names( $this->diff( $row, 'options_during_update_diff' ) )['added'] );
		$this->assertSame( '1.0.0', $row['version_before'] );
		$this->assertSame( '1.1.0', $row['version_after'] );
		$this->assertNull( $row['options_post_update_diff'] );
		$this->assertNull( $row['options_final_diff'] );
		$this->assertNull( $row['options_before_snapshot'] );
		$this->assertNull( $row['options_immediate_snapshot'] );
		$this->assertNull( $row['active_plugin'] );
		$this->assertNull( $row['error_code'] );
		$this->assertNotNull( $row['completed_at'] );
		$this->assertSame( $captures, $this->captures, 'No late snapshot taken.' );
	}

	/**
	 * Another update within the window settles pending analyses first, with the state before that update.
	 */
	public function test_another_update_before_deadline_settles_first() {
		$this->update();

		// Request 2 (e.g. the next "Update now" click): the new code ran, then another update starts.
		$this->options['acme_db_version'] = array( '1.1.0', 'yes' );
		$this->time                      += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS;
		$second                           = $this->request();
		$second->update_starting( null ); // E.g. an ignored bulk update.
		$this->options['other_plugin_option'] = array( 'changed by the other update', 'on' );
		$second->request_ending( true );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( SettleOutcome::NEXT_UPDATE, $row['settle_outcome'] );
		$this->assertSame( array( 'acme_db_version' ), self::names( $this->diff( $row, 'options_post_update_diff' ) )['changed'] );
		$this->assertNotContains( 'other_plugin_option', self::names( $this->diff( $row, 'options_final_diff' ) )['added'], 'The other update is not included.' );
	}

	/**
	 * Another update after the window expires pending analyses instead of taking a late snapshot.
	 */
	public function test_another_update_after_deadline_expires_first() {
		$this->update();

		$this->time += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS + 1;
		$this->start( $this->request(), 'other/other.php' );

		$this->assertCount( 2, $this->repository->rows );
		$first = $this->repository->rows[1];
		$this->assertSame( AnalysisStatus::COMPLETED, $first['status'] );
		$this->assertSame( SettleOutcome::EXPIRED, $first['settle_outcome'] );
		$this->assertNull( $first['options_post_update_diff'] );
		$this->assertNull( $first['options_final_diff'] );
		$this->assertSame( AnalysisStatus::CAPTURED, $this->repository->rows[2]['status'] );
	}

	/**
	 * An ignored update after the window expires without capturing at all.
	 */
	public function test_ignored_update_after_deadline_only_expires() {
		$this->update();
		$this->time += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS + 1;
		$captures    = $this->captures;

		$this->request()->update_starting( null );

		$this->assertSame( SettleOutcome::EXPIRED, $this->only_row()['settle_outcome'] );
		$this->assertSame( $captures, $this->captures );
	}

	/**
	 * The reactivation request after update.php (plugin activated mid-request) does not settle; the next one does.
	 */
	public function test_request_that_changes_activation_does_not_settle() {
		$this->update();

		$this->request()->request_ending( true, array( self::PLUGIN ) );
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $this->only_row()['status'] );

		$this->request()->request_ending( true, array( 'other/other.php' ) );
		$this->assertSame( SettleOutcome::ADMIN_SHUTDOWN, $this->only_row()['settle_outcome'] );
	}

	/**
	 * Requests that are not wp-admin pages never settle, even repeatedly within the window.
	 */
	public function test_non_admin_requests_never_settle() {
		$this->update();

		foreach ( array( 10, 100, 300 ) as $elapsed ) {
			$this->time = self::T0 + $elapsed;
			$this->request()->request_ending( false );
			$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $this->only_row()['status'] );
		}
	}

	/**
	 * The same start event twice in one request creates one analysis.
	 */
	public function test_duplicate_start_in_same_request() {
		$request = $this->request();
		$this->start( $request );
		$this->start( $request );

		$this->assertSame( AnalysisStatus::CAPTURED, $this->only_row()['status'] );
	}

	/**
	 * A second request starting the same plugin while the first is in progress creates no second analysis.
	 */
	public function test_duplicate_start_from_concurrent_request() {
		$this->start( $this->request() );
		$this->time += 30;
		$this->start( $this->request() );

		$this->assertSame( AnalysisStatus::CAPTURED, $this->only_row()['status'] );
	}

	/**
	 * A repeated completion event changes nothing.
	 */
	public function test_duplicate_completion_is_ignored() {
		$request = $this->request();
		$this->start( $request );
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$first = $this->only_row();

		$this->options['acme_other'] = array( 'x', 'on' );
		$this->time                 += 10;
		$request->update_finished( self::PLUGIN, null, '9.9.9' );

		$this->assertSame( $first, $this->only_row() );
	}

	/**
	 * Completion for a plugin this request is not analysing is ignored.
	 */
	public function test_completion_without_analysis_is_ignored() {
		$request = $this->request();
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$this->start( $request, 'other/other.php' );
		$request->update_finished( self::PLUGIN, null, '1.1.0' );

		$this->assertSame( AnalysisStatus::CAPTURED, $this->only_row()['status'] );
	}

	/**
	 * A failed update is recorded with a safe code and no diffs.
	 */
	public function test_failed_update() {
		$request = $this->request();
		$this->start( $request );
		$request->update_finished( self::PLUGIN, 'download_failed', null );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::FAILED, $row['status'] );
		$this->assertSame( 'download_failed', $row['error_code'] );
		$this->assertSame( 'The WordPress update did not succeed.', $row['error_message'] );
		$this->assertSame( SettleOutcome::NOT_APPLICABLE, $row['settle_outcome'] );
		foreach ( array( 'options_during_update_diff', 'options_post_update_diff', 'options_final_diff', 'options_before_snapshot', 'options_immediate_snapshot', 'active_plugin', 'settle_deadline' ) as $column ) {
			$this->assertNull( $row[ $column ], $column );
		}
		$this->assertNotNull( $row['completed_at'] );

		// Final: later admin requests do not touch it.
		$this->request()->request_ending( true );
		$this->assertSame( $row, $this->only_row() );
	}

	/**
	 * WordPress error codes are reduced to safe identifiers.
	 */
	public function test_error_codes_are_sanitized() {
		$request = $this->request();
		$this->start( $request );
		$request->update_finished( self::PLUGIN, 'Bad Code/../' . str_repeat( 'x', 100 ) . '<script>', null );

		$code = $this->only_row()['error_code'];
		$this->assertMatchesRegularExpression( '/^[a-z0-9_-]{1,64}$/', $code );
		$this->assertStringStartsWith( 'badcode', $code );
	}

	/**
	 * An update that never reports completion is failed at the end of its request.
	 */
	public function test_update_without_completion_fails_at_shutdown() {
		$request = $this->request();
		$this->start( $request );
		$request->request_ending( true );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::FAILED, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_UPDATE_NOT_COMPLETED, $row['error_code'] );
		$this->assertSame( SettleOutcome::NOT_APPLICABLE, $row['settle_outcome'] );
		$this->assertNull( $row['options_before_snapshot'] );
	}

	/**
	 * BEFORE → IMMEDIATE incompatible (salts rotated during the update): incompatible, no diffs.
	 */
	public function test_fingerprint_mismatch_during_update() {
		$request = $this->request();
		$this->start( $request );
		$this->salt = 'rotated-salt';
		$request->update_finished( self::PLUGIN, null, '1.1.0' );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::INCOMPATIBLE, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_CONTEXT_CHANGED, $row['error_code'] );
		$this->assertSame( SettleOutcome::NOT_APPLICABLE, $row['settle_outcome'] );
		$this->assertNull( $row['options_during_update_diff'] );
		$this->assertNull( $row['options_before_snapshot'] );
		$this->assertNull( $row['options_immediate_snapshot'] );
	}

	/**
	 * IMMEDIATE → SETTLED and BEFORE → SETTLED incompatible (salts rotated after the update).
	 */
	public function test_fingerprint_mismatch_after_update() {
		$this->update();

		$this->salt = 'rotated-salt';
		$this->request()->request_ending( true );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::INCOMPATIBLE, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_CONTEXT_CHANGED, $row['error_code'] );
		$this->assertSame( SettleOutcome::ADMIN_SHUTDOWN, $row['settle_outcome'], 'Settled snapshot taken but not comparable.' );
		$this->assertNotNull( $row['options_during_update_diff'] );
		$this->assertNull( $row['options_post_update_diff'] );
		$this->assertNull( $row['options_final_diff'] );
		$this->assertNull( $row['options_before_snapshot'] );
		$this->assertNull( $row['options_immediate_snapshot'] );
	}

	/**
	 * Only IMMEDIATE → SETTLED incompatible (BEFORE → SETTLED would compare): still incompatible, no partial diffs.
	 */
	public function test_fingerprint_mismatch_in_post_update_phase_only() {
		$this->update();

		// Re-encode the stored IMMEDIATE snapshot under another context.
		$stored                        = json_decode( $this->repository->rows[1]['options_immediate_snapshot'], true );
		$stored['fingerprint_context'] = 'hmac-sha256-v1:' . str_repeat( 'f', 64 );
		$this->repository->rows[1]['options_immediate_snapshot'] = json_encode( $stored ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test fixture.

		$this->request()->request_ending( true );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::INCOMPATIBLE, $row['status'] );
		$this->assertNull( $row['options_post_update_diff'] );
		$this->assertNull( $row['options_final_diff'] );
		$this->assertNull( $row['options_immediate_snapshot'] );
	}

	/**
	 * A `captured` analysis whose request died is abandoned only after STALE_AFTER_SECONDS.
	 */
	public function test_stale_boundary() {
		$this->start( $this->request() ); // The request dies: no completion, no shutdown.

		$this->time = self::T0 + PluginUpdateAnalyzer::STALE_AFTER_SECONDS;
		$this->request()->request_ending( true );
		$this->assertSame( AnalysisStatus::CAPTURED, $this->only_row()['status'], 'Exactly at the threshold: still running.' );

		$this->time = self::T0 + PluginUpdateAnalyzer::STALE_AFTER_SECONDS + 1;
		$this->request()->request_ending( false );
		$this->assertSame( AnalysisStatus::CAPTURED, $this->only_row()['status'], 'Only admin page requests sweep.' );

		$this->request()->request_ending( true );
		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::ABANDONED, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_STALE, $row['error_code'] );
		$this->assertSame( SettleOutcome::NOT_APPLICABLE, $row['settle_outcome'] );
		$this->assertNull( $row['options_before_snapshot'] );
		$this->assertNull( $row['active_plugin'] );
	}

	/**
	 * A stale `captured` analysis does not block a new update of the same plugin.
	 */
	public function test_stale_analysis_is_replaced_by_new_update() {
		$this->start( $this->request() );
		$this->time += PluginUpdateAnalyzer::STALE_AFTER_SECONDS + 1;
		$this->start( $this->request() );

		$this->assertCount( 2, $this->repository->rows );
		$this->assertSame( AnalysisStatus::ABANDONED, $this->repository->rows[1]['status'] );
		$this->assertSame( AnalysisStatus::CAPTURED, $this->repository->rows[2]['status'] );
	}

	/**
	 * Updating the same plugin again within the window settles the old analysis and keeps it as history.
	 */
	public function test_reupdate_keeps_history() {
		$this->update();
		$this->start( $this->request() );

		$this->assertCount( 2, $this->repository->rows );
		$this->assertSame( AnalysisStatus::COMPLETED, $this->repository->rows[1]['status'] );
		$this->assertSame( SettleOutcome::NEXT_UPDATE, $this->repository->rows[1]['settle_outcome'] );
		$this->assertSame( AnalysisStatus::CAPTURED, $this->repository->rows[2]['status'] );
	}

	/**
	 * Another update in the same request makes this request's analysis unusable for later phases.
	 */
	public function test_another_update_in_same_request_abandons_analysis() {
		$request = $this->request();
		$this->start( $request );
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$request->update_starting( null );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::ABANDONED, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_ANOTHER_UPDATE_STARTED, $row['error_code'] );
		$this->assertSame( SettleOutcome::NOT_APPLICABLE, $row['settle_outcome'] );
		$this->assertNotNull( $row['options_during_update_diff'] );
		$this->assertNull( $row['options_before_snapshot'] );
		$this->assertNull( $row['options_immediate_snapshot'] );
	}

	/**
	 * An ignored update with nothing pending stores nothing.
	 */
	public function test_ignored_update_stores_nothing() {
		$request = $this->request();
		$request->update_starting( null );
		$request->request_ending( true );

		$this->assertSame( array(), $this->repository->rows );
	}

	/**
	 * A corrupt BEFORE snapshot fails the analysis instead of comparing.
	 */
	public function test_corrupt_before_snapshot() {
		$request = $this->request();
		$this->start( $request );
		$this->repository->rows[1]['options_before_snapshot'] = '{"schema":1,';
		$request->update_finished( self::PLUGIN, null, '1.1.0' );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::FAILED, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_SNAPSHOT_CORRUPT, $row['error_code'] );
		$this->assertNull( $row['options_during_update_diff'] );
	}

	/**
	 * A missing IMMEDIATE snapshot fails the settle phase instead of manufacturing diffs.
	 */
	public function test_missing_immediate_snapshot() {
		$this->update();
		$this->repository->rows[1]['options_immediate_snapshot'] = null;

		$this->request()->request_ending( true );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::FAILED, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_SNAPSHOT_CORRUPT, $row['error_code'] );
		$this->assertSame( SettleOutcome::ADMIN_SHUTDOWN, $row['settle_outcome'] );
		$this->assertNotNull( $row['options_during_update_diff'] );
		$this->assertNull( $row['options_post_update_diff'] );
		$this->assertNull( $row['options_before_snapshot'] );
	}

	/**
	 * UpdateLens failures never throw into the updater.
	 */
	public function test_failures_do_not_throw() {
		// Capture fails at start: no analysis, no exception.
		$this->capture_fails = true;
		$this->start( $this->request() );
		$this->assertSame( array(), $this->repository->rows );

		// Capture fails at completion: analysis fails.
		$this->capture_fails = false;
		$request             = $this->request();
		$this->start( $request );
		$this->capture_fails = true;
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$this->assertSame( AnalysisStatus::FAILED, $this->only_row()['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_ANALYSIS_FAILED, $this->only_row()['error_code'] );

		// Storage fails everywhere: still no exception.
		$this->capture_fails           = false;
		$this->repository->fail_writes = true;
		$request                       = $this->request();
		$this->start( $request, 'other/other.php' );
		$request->update_finished( 'other/other.php', null, '2.0.0' );
		$request->request_ending( true );
		$this->request()->request_ending( true );
		$this->request()->update_starting( null );
		$this->assertCount( 1, $this->repository->rows );
	}

	/**
	 * Nothing stored at any stage contains option values.
	 */
	public function test_persisted_data_contains_no_option_values() {
		$stored  = array();
		$request = $this->request();
		$this->start( $request );
		$stored[] = $this->only_row();

		$this->options['acme_token'] = array( 'token=' . self::FAKE_SECRET, 'on' );
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$stored[] = $this->only_row();

		$this->options['acme_settings'] = array( 'secret=' . self::FAKE_SECRET . '-v2', 'on' );
		$this->request()->request_ending( true );
		$stored[] = $this->only_row();

		// A failed update and an expired analysis, for the other columns.
		$failing = $this->request();
		$this->start( $failing, 'other/other.php' );
		$failing->update_finished( 'other/other.php', 'download_failed', null );
		$this->update( 'third/third.php' );
		$this->time += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS + 1;
		$this->request()->request_ending( true );
		$stored[] = $this->repository->rows;

		$encoded = json_encode( $stored ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test inspection.
		foreach ( array( self::FAKE_SECRET, 'api_key', 'token=', 'secret=', 'https://example.test', 'site-salt' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $encoded );
		}

		$row = $this->repository->rows[1];
		foreach ( array( 'options_during_update_diff', 'options_post_update_diff', 'options_final_diff' ) as $column ) {
			$this->assertStringNotContainsString( 'fingerprint', $row[ $column ], $column );
		}
	}
}
