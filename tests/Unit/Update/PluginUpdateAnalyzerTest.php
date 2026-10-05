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
use UpdateLens\Tests\Support\InMemoryAnalysisRepository;
use UpdateLens\Update\AnalysisStatus;
use UpdateLens\Update\PluginUpdateAnalyzer;

/**
 * BEFORE → update → IMMEDIATE → later admin request → SETTLED.
 *
 * Each PluginUpdateAnalyzer instance stands for one PHP request; the
 * repository and the fake options table persist across "requests".
 */
final class PluginUpdateAnalyzerTest extends TestCase {

	const PLUGIN = 'acme/acme.php';

	const FAKE_SECRET = 'sk_test_UPDATE_LENS_LIFECYCLE_SECRET';

	/**
	 * Start time of the scenario.
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
	 * Fresh scenario state.
	 *
	 * @before
	 */
	public function reset_state() {
		$this->repository    = new InMemoryAnalysisRepository();
		$this->salt          = 'site-salt';
		$this->time          = self::T0;
		$this->capture_fails = false;
		$this->options       = array(
			'siteurl'          => array( 'https://example.test', 'on' ),
			'acme_settings'    => array( 'a:1:{s:7:"api_key";s:36:"' . self::FAKE_SECRET . '";}', 'auto-off' ),
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
		$this->assertIsString( $row[ $column ] );

		return json_decode( $row[ $column ], true );
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
	 * Full lifecycle: BEFORE, immediate diff, no settle in the same request or on non-admin requests, settle on the next admin page.
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
		$before = ( new OptionsSnapshotCodec() )->decode( $row['before_snapshot'] );
		$this->assertSame( array( 'acme_db_version', 'acme_legacy', 'acme_settings', 'siteurl' ), array_keys( $before->get_records() ) );

		// WordPress replaces the files; the old code reacts during the update.
		$this->options['acme_pending_migration'] = array( 'yes', 'off' );
		$this->time                             += 5;
		$update_request->update_finished( self::PLUGIN, null, '1.1.0' );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $row['status'] );
		$this->assertSame( '1.1.0', $row['version_after'] );
		$this->assertNotNull( $row['before_snapshot'], 'BEFORE is kept for the settled comparison.' );
		$immediate = $this->diff( $row, 'immediate_diff' );
		$this->assertSame( array( 'acme_pending_migration' ), array_column( $immediate['added'], 'name' ) );
		$this->assertSame( array(), $immediate['changed'] );

		// The update request's own shutdown must not settle.
		$update_request->request_ending( true );
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $this->only_row()['status'] );

		// Request 2: frontend / Ajax / cron / REST — not a wp-admin page.
		$this->request()->request_ending( false );
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $this->only_row()['status'] );

		// Request 3: next wp-admin page; the new code migrates during the request.
		$this->options['acme_db_version'] = array( '1.1.0', 'yes' );
		$this->options['acme_settings']   = array( 'a:1:{s:7:"api_key";s:36:"' . self::FAKE_SECRET . '";}', 'auto-on' );
		$this->options['acme_feature']    = array( 'on', 'auto-off' );
		unset( $this->options['acme_pending_migration'], $this->options['acme_legacy'] );
		$this->time += 60;
		$this->request()->request_ending( true );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::SETTLED_AT_ADMIN_SHUTDOWN, $row['settle_trigger'] );
		$this->assertNull( $row['before_snapshot'], 'Temporary BEFORE snapshot cleared.' );
		$this->assertNull( $row['active_plugin'] );
		$this->assertSame( '2027-01-15 08:01:05', $row['completed_at'] );
		$this->assertNull( $row['error_code'] );

		$settled = $this->diff( $row, 'settled_diff' );
		$this->assertSame( array( 'acme_feature' ), array_column( $settled['added'], 'name' ) );
		$this->assertSame( array( 'acme_legacy' ), array_column( $settled['removed'], 'name' ) );
		$this->assertSame( array( 'acme_db_version', 'acme_settings' ), array_column( $settled['changed'], 'name' ) );
		$this->assertTrue( $settled['changed'][0]['value_changed'] );
		$this->assertSame( 0, $settled['changed'][0]['size_delta'] );
		$this->assertFalse( $settled['changed'][1]['value_changed'] );
		$this->assertTrue( $settled['changed'][1]['autoload_behavior_changed'] );
		$this->assertNotNull( $row['immediate_diff'], 'Immediate diff kept for history.' );

		// Further admin requests change nothing.
		$this->request()->request_ending( true );
		$this->assertSame( $row, $this->only_row() );
	}

	/**
	 * The reactivation request after update.php (plugin activated mid-request) does not settle; the next one does.
	 */
	public function test_request_that_changes_activation_does_not_settle() {
		$request = $this->request();
		$this->start( $request );
		$request->update_finished( self::PLUGIN, null, '1.1.0' );

		$this->request()->request_ending( true, array( self::PLUGIN ) );
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $this->only_row()['status'] );

		$this->request()->request_ending( true, array( 'other/other.php' ) );
		$this->assertSame( AnalysisStatus::COMPLETED, $this->only_row()['status'] );
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
	 * A failed update is recorded with a safe code and no diff.
	 */
	public function test_failed_update() {
		$request = $this->request();
		$this->start( $request );
		$request->update_finished( self::PLUGIN, 'download_failed', null );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::FAILED, $row['status'] );
		$this->assertSame( 'download_failed', $row['error_code'] );
		$this->assertSame( 'The WordPress update did not succeed.', $row['error_message'] );
		$this->assertNull( $row['immediate_diff'] );
		$this->assertNull( $row['settled_diff'] );
		$this->assertNull( $row['before_snapshot'] );
		$this->assertNull( $row['active_plugin'] );
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
		$this->assertNull( $row['before_snapshot'] );
	}

	/**
	 * Rotated salts between BEFORE and IMMEDIATE: incompatible, not a giant diff.
	 */
	public function test_fingerprint_mismatch_at_immediate() {
		$request = $this->request();
		$this->start( $request );
		$this->salt = 'rotated-salt';
		$request->update_finished( self::PLUGIN, null, '1.1.0' );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::INCOMPATIBLE, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_CONTEXT_CHANGED, $row['error_code'] );
		$this->assertNull( $row['immediate_diff'] );
		$this->assertNull( $row['before_snapshot'] );
	}

	/**
	 * Rotated salts between IMMEDIATE and SETTLED: incompatible; immediate diff kept.
	 */
	public function test_fingerprint_mismatch_at_settle() {
		$request = $this->request();
		$this->start( $request );
		$request->update_finished( self::PLUGIN, null, '1.1.0' );

		$this->salt = 'rotated-salt';
		$this->request()->request_ending( true );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::INCOMPATIBLE, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_CONTEXT_CHANGED, $row['error_code'] );
		$this->assertNotNull( $row['immediate_diff'] );
		$this->assertNull( $row['settled_diff'] );
		$this->assertNull( $row['before_snapshot'] );
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
		$this->assertNull( $row['before_snapshot'] );
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
	 * Another update in a later request settles pending analyses first, with the state before that update.
	 */
	public function test_next_update_settles_pending_analysis_first() {
		$request = $this->request();
		$this->start( $request );
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$request->request_ending( true );

		// Request 2 (e.g. the next "Update now" click): A's new code ran, then another update starts.
		$this->options['acme_db_version'] = array( '1.1.0', 'yes' );
		$second                           = $this->request();
		$second->update_starting( null ); // E.g. an ignored bulk update.
		$this->options['other_plugin_option'] = array( 'changed by the other update', 'on' );
		$second->request_ending( true );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::SETTLED_BEFORE_NEXT_UPDATE, $row['settle_trigger'] );
		$settled = $this->diff( $row, 'settled_diff' );
		$this->assertSame( array( 'acme_db_version' ), array_column( $settled['changed'], 'name' ) );
		$this->assertNotContains( 'other_plugin_option', array_column( $settled['added'], 'name' ), 'The other update is not attributed.' );
	}

	/**
	 * Updating the same plugin again settles the old analysis and keeps it as history.
	 */
	public function test_reupdate_keeps_history() {
		$request = $this->request();
		$this->start( $request );
		$request->update_finished( self::PLUGIN, null, '1.1.0' );

		$this->start( $this->request() );

		$this->assertCount( 2, $this->repository->rows );
		$this->assertSame( AnalysisStatus::COMPLETED, $this->repository->rows[1]['status'] );
		$this->assertSame( AnalysisStatus::CAPTURED, $this->repository->rows[2]['status'] );
	}

	/**
	 * Another update in the same request makes this request's analysis unattributable.
	 */
	public function test_another_update_in_same_request_abandons_analysis() {
		$request = $this->request();
		$this->start( $request );
		$request->update_finished( self::PLUGIN, null, '1.1.0' );
		$request->update_starting( null );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::ABANDONED, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_ANOTHER_UPDATE_STARTED, $row['error_code'] );
		$this->assertNotNull( $row['immediate_diff'] );
		$this->assertNull( $row['before_snapshot'] );
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
		$this->repository->rows[1]['before_snapshot'] = '{"schema":1,';
		$request->update_finished( self::PLUGIN, null, '1.1.0' );

		$row = $this->only_row();
		$this->assertSame( AnalysisStatus::FAILED, $row['status'] );
		$this->assertSame( PluginUpdateAnalyzer::ERROR_SNAPSHOT_CORRUPT, $row['error_code'] );
		$this->assertNull( $row['immediate_diff'] );
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

		// A failed update and a stale abandonment, for the error columns.
		$failing = $this->request();
		$this->start( $failing, 'other/other.php' );
		$failing->update_finished( 'other/other.php', 'download_failed', null );
		$stored[] = $this->repository->rows;

		$encoded = json_encode( $stored ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test inspection.
		foreach ( array( self::FAKE_SECRET, 'api_key', 'token=', 'secret=', 'https://example.test', 'site-salt' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $encoded );
		}

		$row = $this->repository->rows[1];
		foreach ( array( 'immediate_diff', 'settled_diff' ) as $column ) {
			$this->assertStringNotContainsString( 'fingerprint', $row[ $column ] );
		}
	}
}
