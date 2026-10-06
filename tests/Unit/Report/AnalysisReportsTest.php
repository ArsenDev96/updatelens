<?php
/**
 * Tests for report reads.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\Report\AnalysisReports;
use UpdateLens\Report\UnavailableReason;
use UpdateLens\Snapshot\ActionSchedulerUnavailableException;
use UpdateLens\Snapshot\MalformedActionSchedulerStateException;
use UpdateLens\Storage\OptionsDiffCodec;
use UpdateLens\Tests\Support\ActionSchedulerFixture;
use UpdateLens\Tests\Support\CronFixture;
use UpdateLens\Tests\Support\FakeSite;
use UpdateLens\Update\AnalysisStatus;
use UpdateLens\Update\PluginUpdateAnalyzer;
use UpdateLens\Update\SettleOutcome;

/**
 * History and reports built from analyses produced by the real lifecycle.
 */
final class AnalysisReportsTest extends TestCase {

	const FAKE_SECRET = 'sk_test_UPDATE_LENS_REPORT_API_SECRET';

	/**
	 * Start time (2027-01-15 08:00:00 UTC).
	 */
	const T0 = 1800000000;

	/**
	 * Fake site.
	 *
	 * @var FakeSite
	 */
	private $site;

	/**
	 * Number of schema checks.
	 *
	 * @var int
	 */
	private $schema_checks;

	/**
	 * Result of the schema check.
	 *
	 * @var bool
	 */
	private $schema_ok;

	/**
	 * Fresh site.
	 *
	 * @before
	 */
	public function reset_state() {
		$this->site          = new FakeSite(
			array(
				'siteurl'         => array( 'https://example.test', 'on' ),
				'acme_settings'   => array( 'a:1:{s:7:"api_key";s:37:"' . self::FAKE_SECRET . '";}', 'auto-off' ),
				'acme_db_version' => array( '1.0.0', 'yes' ),
				'acme_legacy'     => array( str_repeat( 'x', 100 ), 'off' ),
			),
			self::T0
		);
		$this->schema_checks = 0;
		$this->schema_ok     = true;
	}

	/**
	 * Reports over the fake site, as a new request would create them.
	 *
	 * @return AnalysisReports
	 */
	private function reports() {
		return new AnalysisReports(
			$this->site->repository,
			$this->site->request(),
			function () {
				++$this->schema_checks;
				return $this->schema_ok;
			}
		);
	}

	/**
	 * Update a plugin and settle it on a later admin page, with changes in both phases.
	 *
	 * @param string $plugin Plugin file.
	 * @return void
	 */
	private function completed_update( $plugin = 'acme/acme.php' ) {
		$request = $this->site->request();
		$this->site->start( $request, $plugin );
		$this->site->options['acme_needs_migration'] = array( '1', 'off' );
		$this->site->time                           += 5;
		$request->update_finished( $plugin, null, '1.1.0' );
		$request->request_ending( true );

		unset( $this->site->options['acme_needs_migration'], $this->site->options['acme_legacy'] );
		$this->site->options['acme_settings'][0] .= ';token=' . self::FAKE_SECRET;
		$this->site->options['acme_flags']        = array( '{"new_ui":true}', 'on' );
		$this->site->time                        += 10;
		$this->site->admin_page();
	}

	/**
	 * Insert a finished analysis row directly.
	 *
	 * @param int $n Sequence number.
	 * @return int ID.
	 */
	private function insert_completed( $n ) {
		return $this->site->repository->create(
			array(
				'plugin_file'    => "plugin-{$n}/plugin-{$n}.php",
				'plugin_name'    => "Plugin {$n}",
				'version_before' => '1.0.0',
				'version_after'  => '1.0.1',
				'status'         => AnalysisStatus::COMPLETED,
				'settle_outcome' => SettleOutcome::EXPIRED,
				'started_at'     => '2027-01-15 08:00:00',
				'updated_at'     => '2027-01-15 08:10:00',
				'completed_at'   => '2027-01-15 08:10:00',
			)
		);
	}

	/**
	 * IDs of history items.
	 *
	 * @param array $history History result.
	 * @return int[]
	 */
	private static function ids( array $history ) {
		return array_column( $history['items'], 'id' );
	}

	/**
	 * JSON as the REST API would emit it.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	private static function json( $data ) {
		return (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
	}

	/**
	 * No analyses.
	 */
	public function test_empty_history() {
		$history = $this->reports()->history( 1, 20 );

		$this->assertSame(
			array(
				'items' => array(),
				'total' => 0,
			),
			$history
		);
	}

	/**
	 * Newest first; pages; a page past the end is empty; total stays the same.
	 */
	public function test_pagination_and_ordering() {
		for ( $n = 1; $n <= 5; $n++ ) {
			$this->insert_completed( $n );
		}
		$reports = $this->reports();

		$this->assertSame( array( 5, 4 ), self::ids( $reports->history( 1, 2 ) ) );
		$this->assertSame( array( 3, 2 ), self::ids( $reports->history( 2, 2 ) ) );
		$this->assertSame( array( 1 ), self::ids( $reports->history( 3, 2 ) ) );
		$this->assertSame( array(), self::ids( $reports->history( 4, 2 ) ) );
		$this->assertSame( array( 5, 4, 3, 2, 1 ), self::ids( $reports->history( 1, 20 ) ) );
		$this->assertSame( 5, $reports->history( 4, 2 )['total'] );
		$this->assertSame( self::ids( $reports->history( 1, 2 ) ), self::ids( $reports->history( 1, 2 ) ), 'Stable.' );
	}

	/**
	 * Page size is capped at MAX_PER_PAGE; invalid page and size values are normalised.
	 */
	public function test_page_limits() {
		for ( $n = 1; $n <= AnalysisReports::MAX_PER_PAGE + 5; $n++ ) {
			$this->insert_completed( $n );
		}
		$reports = $this->reports();

		$this->assertCount( 100, $reports->history( 1, 1000 )['items'] );
		$this->assertCount( 5, $reports->history( 2, 100 )['items'] );
		$this->assertSame( array( 105 ), self::ids( $reports->history( 1, 0 ) ) );
		$this->assertSame( array( 105 ), self::ids( $reports->history( -3, 1 ) ) );
		$this->assertSame( array(), $reports->history( PHP_INT_MAX, 100 )['items'] );
	}

	/**
	 * History row of a completed analysis: exact shape and JSON types.
	 */
	public function test_history_row() {
		$this->completed_update();

		$history = $this->reports()->history( 1, 20 );

		$this->assertSame( 1, $history['total'] );
		$this->assertSame(
			array(
				'id'                => 1,
				'plugin'            => array(
					'file'           => 'acme/acme.php',
					'name'           => 'Acme',
					'version_before' => '1.0.0',
					'version_after'  => '1.1.0',
				),
				'status'            => 'completed',
				'settle_outcome'    => 'admin_shutdown',
				'timestamps'        => array(
					'started_at'      => '2027-01-15T08:00:00Z',
					'settle_deadline' => '2027-01-15T08:05:05Z',
					'completed_at'    => '2027-01-15T08:00:15Z',
				),
				'error'             => null,
				'has_during_update' => true,
				'has_post_update'   => true,
				'has_final'         => true,
				'phases'            => array_fill_keys(
					array( 'during_update', 'post_update', 'final' ),
					array(
						'options'          => array(
							'recorded'    => true,
							'has_changes' => true,
						),
						'cron'             => array(
							'recorded'    => true,
							'has_changes' => false,
						),
						'action_scheduler' => array(
							'recorded'    => false,
							'has_changes' => null,
						),
					)
				),
			),
			$history['items'][0]
		);
	}

	/**
	 * Every lifecycle state in the history, newest first.
	 */
	public function test_history_states() {
		// 1: completed (admin_shutdown).
		$this->completed_update( 'a/a.php' );

		// 2: failed update.
		$request = $this->site->request();
		$this->site->start( $request, 'b/b.php' );
		$request->update_finished( 'b/b.php', 'download_failed', null );
		$request->request_ending( true );

		// 3: incompatible after the update (salts rotated before settling).
		$this->site->update( 'c/c.php' );
		$this->site->salt = 'rotated';
		$this->site->admin_page();
		$this->site->salt = 'site-salt';

		// 4: awaiting settle, deadline still ahead.
		$this->site->update( 'd/d.php' );

		$items = $this->reports()->history( 1, 20 )['items'];
		$rows  = array();
		foreach ( $items as $item ) {
			$rows[ $item['plugin']['file'] ] = array( $item['status'], $item['settle_outcome'], null === $item['error'] ? null : $item['error']['code'], $item['has_during_update'], $item['has_post_update'], $item['has_final'] );
		}

		$this->assertSame( array( 4, 3, 2, 1 ), array_column( $items, 'id' ) );
		$this->assertSame(
			array(
				'd/d.php' => array( 'awaiting_settle', null, null, true, false, false ),
				'c/c.php' => array( 'incompatible', 'admin_shutdown', 'fingerprint_context_changed', true, false, false ),
				'b/b.php' => array( 'failed', 'not_applicable', 'download_failed', false, false, false ),
				'a/a.php' => array( 'completed', 'admin_shutdown', null, true, true, true ),
			),
			$rows
		);
	}

	/**
	 * Settle deadline is inclusive for reads too: at 300 s still waiting, at 301 s expired.
	 *
	 * @return array
	 */
	public function provide_deadline() {
		return array(
			'300 s: waiting' => array( 300, 'awaiting_settle', null ),
			'301 s: expired' => array( 301, 'completed', 'expired' ),
		);
	}

	/**
	 * History reads expire overdue analyses first, without a late snapshot.
	 *
	 * @dataProvider provide_deadline
	 * @param int         $elapsed Seconds after the update finished.
	 * @param string      $status  Expected status.
	 * @param string|null $outcome Expected settle outcome.
	 */
	public function test_history_expires_overdue_first( $elapsed, $status, $outcome ) {
		$this->site->update( 'acme/acme.php' );
		$this->site->options['unrelated'] = array( 'later activity', 'on' );
		$this->site->time                += $elapsed;
		$captures                         = $this->site->captures;

		$item = $this->reports()->history( 1, 20 )['items'][0];

		$this->assertSame( $status, $item['status'] );
		$this->assertSame( $outcome, $item['settle_outcome'] );
		$this->assertTrue( $item['has_during_update'] );
		$this->assertFalse( $item['has_post_update'] );
		$this->assertFalse( $item['has_final'] );
		$this->assertSame( $captures, $this->site->captures, 'No settled snapshot taken by a read.' );
	}

	/**
	 * Report reads expire overdue analyses first.
	 */
	public function test_report_expires_overdue_first() {
		$this->site->update( 'acme/acme.php' );
		$this->site->time += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS + 1;
		$captures          = $this->site->captures;

		$report = $this->reports()->report( 1 );

		$this->assertSame( 'completed', $report['status'] );
		$this->assertSame( 'expired', $report['settle_outcome'] );
		$this->assertTrue( $report['phases']['during_update']['options']['available'] );
		$this->assertSame(
			array(
				'available'   => false,
				'association' => 'observed_after_update',
				'reason'      => 'settle_expired',
			),
			$report['phases']['post_update']['options']
		);
		$this->assertSame(
			array(
				'available'   => false,
				'association' => 'net_across_phases',
				'reason'      => 'settle_expired',
			),
			$report['phases']['final']['options']
		);
		$this->assertSame( '2027-01-15T08:05:01Z', $report['timestamps']['completed_at'] );
		$this->assertSame( $captures, $this->site->captures );
		$this->assertNull( $this->site->repository->rows[1]['options_before_snapshot'] );
		$this->assertNull( $this->site->repository->rows[1]['options_immediate_snapshot'] );
	}

	/**
	 * Completed report: all three phases decoded from the stored diffs.
	 */
	public function test_completed_report() {
		$this->completed_update();
		$row    = $this->site->repository->rows[1];
		$codec  = new OptionsDiffCodec();
		$report = $this->reports()->report( 1 );

		$this->assertSame( array( 'id', 'plugin', 'status', 'settle_outcome', 'timestamps', 'observation_window_seconds', 'phases', 'error' ), array_keys( $report ) );
		$this->assertSame( 1, $report['id'] );
		$this->assertSame( 'completed', $report['status'] );
		$this->assertSame( 'admin_shutdown', $report['settle_outcome'] );
		$this->assertNull( $report['error'] );
		$this->assertSame( array( 'during_update', 'post_update', 'final' ), array_keys( $report['phases'] ) );

		$associations = array(
			'during_update' => array( 'update_request', 'options_during_update_diff' ),
			'post_update'   => array( 'observed_after_update', 'options_post_update_diff' ),
			'final'         => array( 'net_across_phases', 'options_final_diff' ),
		);
		foreach ( $associations as $phase => list( $association, $column ) ) {
			$diff = $codec->decode( $row[ $column ] );
			$this->assertSame(
				array(
					'available'   => true,
					'association' => $association,
					'summary'     => $diff['summary'],
					'added'       => $diff['added'],
					'removed'     => $diff['removed'],
					'changed'     => $diff['changed'],
				),
				$report['phases'][ $phase ]['options'],
				$phase
			);
		}

		$this->assertSame( array( 'acme_needs_migration' ), array_column( $report['phases']['during_update']['options']['added'], 'name' ) );
		$this->assertSame( array( 'acme_flags' ), array_column( $report['phases']['post_update']['options']['added'], 'name' ) );
		$this->assertSame( array( 'acme_legacy', 'acme_needs_migration' ), array_column( $report['phases']['post_update']['options']['removed'], 'name' ) );
		$this->assertSame( array( 'acme_settings' ), array_column( $report['phases']['final']['options']['changed'], 'name' ) );
		$this->assertSame( array( 'acme_legacy' ), array_column( $report['phases']['final']['options']['removed'], 'name' ) );

		$summary = $report['phases']['final']['options']['summary'];
		$this->assertSame( 0, $summary['option_count_delta'] );
		$this->assertSame( 1, $summary['changed_count'] );
		$this->assertSame( 1, $summary['value_changed_count'] );
		foreach ( $summary as $key => $value ) {
			$this->assertIsInt( $value, $key );
		}
		$changed = $report['phases']['final']['options']['changed'][0];
		$this->assertTrue( $changed['value_changed'] );
		$this->assertIsInt( $changed['size_delta'] );
		$this->assertFalse( $changed['autoload_behavior_changed'] );
	}

	/**
	 * Failed update: no phases, error code only.
	 */
	public function test_failed_report() {
		$request = $this->site->request();
		$this->site->start( $request, 'acme/acme.php' );
		$request->update_finished( 'acme/acme.php', 'incompatible_archive', null );

		$report = $this->reports()->report( 1 );

		$this->assertSame( 'failed', $report['status'] );
		$this->assertSame( 'not_applicable', $report['settle_outcome'] );
		$this->assertSame( array( 'code' => 'incompatible_archive' ), $report['error'] );
		$this->assertNull( $report['plugin']['version_after'] );
		foreach ( $report['phases'] as $phase ) {
			$this->assertFalse( $phase['options']['available'] );
			$this->assertSame( UnavailableReason::UPDATE_FAILED, $phase['options']['reason'] );
		}
	}

	/**
	 * Update that never reported completion.
	 */
	public function test_update_not_completed_report() {
		$request = $this->site->request();
		$this->site->start( $request, 'acme/acme.php' );
		$request->request_ending( true );

		$report = $this->reports()->report( 1 );

		$this->assertSame( array( 'code' => 'update_not_completed' ), $report['error'] );
		$this->assertSame( UnavailableReason::UPDATE_FAILED, $report['phases']['during_update']['options']['reason'] );
	}

	/**
	 * Incompatible during the update: no phase at all.
	 */
	public function test_incompatible_during_update_report() {
		$request = $this->site->request();
		$this->site->start( $request, 'acme/acme.php' );
		$this->site->salt = 'rotated';
		$request->update_finished( 'acme/acme.php', null, '1.1.0' );

		$report = $this->reports()->report( 1 );

		$this->assertSame( 'incompatible', $report['status'] );
		$this->assertSame( array( 'code' => 'fingerprint_context_changed' ), $report['error'] );
		foreach ( $report['phases'] as $phase ) {
			$this->assertSame( UnavailableReason::FINGERPRINT_CONTEXT_CHANGED, $phase['options']['reason'] );
		}
	}

	/**
	 * Incompatible when settling: the during-update phase stays available.
	 */
	public function test_incompatible_after_update_report() {
		$this->site->update( 'acme/acme.php' );
		$this->site->salt = 'rotated';
		$this->site->admin_page();

		$report = $this->reports()->report( 1 );

		$this->assertSame( 'incompatible', $report['status'] );
		$this->assertSame( 'admin_shutdown', $report['settle_outcome'] );
		$this->assertTrue( $report['phases']['during_update']['options']['available'] );
		$this->assertSame( UnavailableReason::FINGERPRINT_CONTEXT_CHANGED, $report['phases']['post_update']['options']['reason'] );
		$this->assertSame( UnavailableReason::FINGERPRINT_CONTEXT_CHANGED, $report['phases']['final']['options']['reason'] );
	}

	/**
	 * Still within the settle window.
	 */
	public function test_awaiting_report() {
		$this->site->update( 'acme/acme.php' );
		$this->site->time += 60;

		$report = $this->reports()->report( 1 );

		$this->assertSame( 'awaiting_settle', $report['status'] );
		$this->assertNull( $report['settle_outcome'] );
		$this->assertNull( $report['timestamps']['completed_at'] );
		$this->assertSame( '2027-01-15T08:05:00Z', $report['timestamps']['settle_deadline'] );
		$this->assertTrue( $report['phases']['during_update']['options']['available'] );
		$this->assertSame( UnavailableReason::AWAITING_SETTLE, $report['phases']['post_update']['options']['reason'] );
		$this->assertSame( UnavailableReason::AWAITING_SETTLE, $report['phases']['final']['options']['reason'] );
	}

	/**
	 * Update still running (BEFORE captured only).
	 */
	public function test_in_progress_report() {
		$this->site->start( $this->site->request(), 'acme/acme.php' );

		$report = $this->reports()->report( 1 );

		$this->assertSame( 'captured', $report['status'] );
		$this->assertSame( UnavailableReason::UPDATE_IN_PROGRESS, $report['phases']['during_update']['options']['reason'] );
	}

	/**
	 * Abandoned analysis.
	 */
	public function test_abandoned_report() {
		$request = $this->site->request();
		$this->site->start( $request, 'a/a.php' );
		$this->site->start( $request, 'b/b.php' );

		$report = $this->reports()->report( 1 );

		$this->assertSame( 'abandoned', $report['status'] );
		$this->assertSame( array( 'code' => 'another_update_started' ), $report['error'] );
		$this->assertSame( UnavailableReason::ANALYSIS_ABANDONED, $report['phases']['during_update']['options']['reason'] );
	}

	/**
	 * Missing analysis.
	 */
	public function test_missing_report() {
		$this->completed_update();

		$this->assertNull( $this->reports()->report( 2 ) );
		$this->assertNull( $this->reports()->report( 0 ) );
	}

	/**
	 * A corrupt stored diff makes only that phase unavailable, without leaking the stored data.
	 */
	public function test_corrupt_stored_diff() {
		$this->completed_update();
		$this->site->repository->rows[1]['options_post_update_diff'] = '{"schema":1,"added":"' . self::FAKE_SECRET . '"';

		$report = $this->reports()->report( 1 );

		$this->assertTrue( $report['phases']['during_update']['options']['available'] );
		$this->assertTrue( $report['phases']['final']['options']['available'] );
		$this->assertSame(
			array(
				'available'   => false,
				'association' => 'observed_after_update',
				'reason'      => 'data_corrupt',
			),
			$report['phases']['post_update']['options']
		);
		$this->assertStringNotContainsString( self::FAKE_SECRET, (string) self::json( $report ) );
	}

	/**
	 * Unknown stored status/outcome are reported as `unknown`, never as completed.
	 */
	public function test_unknown_status_and_outcome() {
		$this->completed_update();
		$this->site->repository->rows[1]['status']         = 'legacy_state';
		$this->site->repository->rows[1]['settle_outcome'] = 'legacy_outcome';

		$report = $this->reports()->report( 1 );
		$item   = $this->reports()->history( 1, 20 )['items'][0];

		$this->assertSame( 'unknown', $report['status'] );
		$this->assertSame( 'unknown', $report['settle_outcome'] );
		$this->assertSame( 'unknown', $item['status'] );
		$this->assertSame( 'unknown', $item['settle_outcome'] );
	}

	/**
	 * If the schema cannot be ensured, reads fail without touching storage.
	 */
	public function test_schema_unavailable() {
		$this->completed_update();
		$this->schema_ok = false;
		$reports         = $this->reports();

		foreach ( array( 'history', 'report' ) as $method ) {
			try {
				'history' === $method ? $reports->history( 1, 20 ) : $reports->report( 1 );
				$this->fail( 'Expected an exception.' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'UpdateLens reports are unavailable.', $e->getMessage() );
			}
		}
	}

	/**
	 * The schema is ensured before every read.
	 */
	public function test_schema_checked_before_reads() {
		$reports = $this->reports();
		$reports->history( 1, 20 );
		$reports->report( 1 );

		$this->assertSame( 2, $this->schema_checks );
	}

	/**
	 * Storage read failures surface as exceptions with fixed messages.
	 */
	public function test_storage_failure() {
		$this->completed_update();
		$this->site->repository->fail_reads = true;

		$this->expectException( RuntimeException::class );
		$this->reports()->history( 1, 20 );
	}

	/**
	 * Reads do not change finished analyses.
	 */
	public function test_reads_do_not_modify_finished_analyses() {
		$this->completed_update( 'a/a.php' );
		$request = $this->site->request();
		$this->site->start( $request, 'b/b.php' );
		$request->update_finished( 'b/b.php', 'download_failed', null );
		$rows = $this->site->repository->rows;

		$this->site->time += 86400;
		$reports           = $this->reports();
		$reports->history( 1, 20 );
		$reports->report( 1 );
		$reports->report( 2 );

		$this->assertSame( $rows, $this->site->repository->rows );
	}

	/**
	 * The fake secret, fingerprints, fingerprint contexts, snapshots and raw diff JSON never appear in read models.
	 */
	public function test_privacy() {
		$this->completed_update( 'a/a.php' );
		$this->site->update( 'b/b.php' ); // Awaiting, with both snapshots stored.
		$this->site->time += 60;

		$reports = $this->reports();
		$outputs = array(
			$reports->history( 1, 20 ),
			$reports->report( 1 ),
			$reports->report( 2 ),
		);

		$forbidden = array( self::FAKE_SECRET, 'api_key', 'token=', 'fingerprint', 'hmac-sha256', 'snapshot', 'user_id', 'error_message', '"schema"', 'site-salt' );
		foreach ( $this->site->repository->rows as $row ) {
			foreach ( array( 'options_before_snapshot', 'options_immediate_snapshot' ) as $column ) {
				if ( null !== $row[ $column ] ) {
					$snapshot = json_decode( $row[ $column ], true );
					foreach ( $snapshot['options'] as $option ) {
						$forbidden[] = $option['fingerprint'];
					}
				}
			}
			foreach ( array( 'options_during_update_diff', 'options_post_update_diff', 'options_final_diff' ) as $column ) {
				if ( null !== $row[ $column ] ) {
					$forbidden[] = $row[ $column ];
				}
			}
		}

		foreach ( $outputs as $output ) {
			foreach ( array( self::json( $output ), serialize( $output ), var_export( $output, true ) ) as $serialized ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Privacy assertion.
				foreach ( $forbidden as $needle ) {
					$this->assertStringNotContainsString( $needle, $serialized );
				}
			}
		}
	}

	/**
	 * Obviously fake credential in Cron arguments.
	 */
	const CRON_SECRET = 'sk_test_UPDATE_LENS_CRON_REPORT_SECRET';

	/**
	 * Cron before the update: a plugin job with secret arguments and a core job.
	 *
	 * @return array
	 */
	private static function cron_before() {
		return CronFixture::cron(
			array(
				CronFixture::recurring( self::T0 + 3600, 'acme_cleanup', 'daily', array( 'token' => self::CRON_SECRET ) ),
				CronFixture::recurring( self::T0 + 600, 'wp_version_check', 'twicedaily' ),
			)
		);
	}

	/**
	 * Cron right after the update: the update request scheduled a one-time job.
	 *
	 * @return array
	 */
	private static function cron_immediate() {
		return CronFixture::cron(
			array(
				CronFixture::recurring( self::T0 + 3600, 'acme_cleanup', 'daily', array( 'token' => self::CRON_SECRET ) ),
				CronFixture::recurring( self::T0 + 600, 'wp_version_check', 'twicedaily' ),
				CronFixture::single( self::T0 + 900, 'acme_update_once', array( 'https://hooks.example.test/' . self::CRON_SECRET ) ),
			)
		);
	}

	/**
	 * Cron at settle: the plugin job and a core job moved, a report job was added.
	 *
	 * @return array
	 */
	private static function cron_settled() {
		return CronFixture::cron(
			array(
				CronFixture::recurring( self::T0 + 7200, 'acme_cleanup', 'daily', array( 'token' => self::CRON_SECRET ) ),
				CronFixture::recurring( self::T0 + 600 + 43200, 'wp_version_check', 'twicedaily' ),
				CronFixture::single( self::T0 + 900, 'acme_update_once', array( 'https://hooks.example.test/' . self::CRON_SECRET ) ),
				CronFixture::recurring( self::T0 + 1800, 'acme_report', 'hourly' ),
			)
		);
	}

	/**
	 * Cron state with a malformed plugin entry.
	 *
	 * @param array $cron Valid state.
	 * @return array
	 */
	private static function malformed( array $cron ) {
		$cron[ self::T0 + 99 ]['acme_broken'] = array( 'k' => 'token=' . self::CRON_SECRET );

		return $cron;
	}

	/**
	 * An update with the given Cron states; a null settled state leaves it awaiting.
	 *
	 * @param array      $before    Cron before.
	 * @param array      $immediate Cron after the update request.
	 * @param array|null $settled   Cron at the next admin page, or null.
	 * @return void
	 */
	private function cron_update( array $before, array $immediate, $settled ) {
		$this->site->cron = $before;
		$request          = $this->site->request();
		$this->site->start( $request, 'acme/acme.php' );
		$this->site->options['acme_needs_migration'] = array( '1', 'off' );
		$this->site->cron                            = $immediate;
		$this->site->time                           += 5;
		$request->update_finished( 'acme/acme.php', null, '1.1.0' );
		$request->request_ending( true );

		if ( null !== $settled ) {
			$this->site->cron  = $settled;
			$this->site->time += 10;
			$this->site->admin_page();
		}
	}

	/**
	 * Per phase and signal: true, or the unavailable reason.
	 *
	 * @param array $report Report.
	 * @return array
	 */
	private static function availability( array $report ) {
		$result = array();
		foreach ( $report['phases'] as $phase => $signals ) {
			foreach ( $signals as $signal => $data ) {
				$result[ $phase ][ $signal ] = $data['available'] ? true : $data['reason'];
			}
		}

		return $result;
	}

	/**
	 * Hooks per list of a Cron phase.
	 *
	 * @param array $cron Available Cron phase.
	 * @return array
	 */
	private static function hooks( array $cron ) {
		return array(
			'added'       => array_column( $cron['added'], 'hook' ),
			'removed'     => array_column( $cron['removed'], 'hook' ),
			'rescheduled' => array_column( $cron['rescheduled'], 'hook' ),
			'changed'     => array_column( $cron['changed'], 'hook' ),
		);
	}

	/**
	 * Full success: both signals available in all three phases, core Cron movement kept.
	 */
	public function test_cron_full_report() {
		$this->cron_update( self::cron_before(), self::cron_immediate(), self::cron_settled() );
		$report = $this->reports()->report( 1 );

		$this->assertSame( array( 'id', 'plugin', 'status', 'settle_outcome', 'timestamps', 'observation_window_seconds', 'phases', 'error' ), array_keys( $report ) );
		$this->assertSame( PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS, $report['observation_window_seconds'] );
		$this->assertSame(
			array_fill_keys(
				array( 'during_update', 'post_update', 'final' ),
				array(
					'options'          => true,
					'cron'             => true,
					'action_scheduler' => 'not_installed',
				)
			),
			self::availability( $report )
		);

		$during = $report['phases']['during_update']['cron'];
		$this->assertSame( 'update_request', $during['association'] );
		$this->assertSame( array( 'acme_update_once' ), self::hooks( $during )['added'] );
		$this->assertSame(
			array(
				'hook'         => 'acme_update_once',
				'timestamp'    => self::T0 + 900,
				'schedule'     => null,
				'interval'     => null,
				'is_recurring' => false,
			),
			$during['added'][0]
		);

		$post = $report['phases']['post_update']['cron'];
		$this->assertSame(
			array(
				'added'       => array( 'acme_report' ),
				'removed'     => array(),
				'rescheduled' => array( 'acme_cleanup', 'wp_version_check' ),
				'changed'     => array(),
			),
			self::hooks( $post )
		);
		$this->assertSame( array( 'hook', 'before_timestamp', 'after_timestamp', 'timestamp_delta', 'schedule', 'interval', 'is_recurring' ), array_keys( $post['rescheduled'][0] ) );
		$this->assertSame( 43200, $post['rescheduled'][1]['timestamp_delta'] );

		$final = $report['phases']['final']['cron'];
		$this->assertSame( array( 'acme_report', 'acme_update_once' ), self::hooks( $final )['added'] );
		$this->assertSame( 2, $final['summary']['event_count_delta'] );
		foreach ( $final['summary'] as $key => $value ) {
			$this->assertIsInt( $value, $key );
		}
	}

	/**
	 * Partial availability: Cron after-update lost (corrupt stored IMMEDIATE), everything else available.
	 */
	public function test_cron_partial_availability() {
		$this->cron_update( self::cron_before(), self::cron_immediate(), null );
		$this->site->repository->rows[1]['cron_immediate_snapshot'] = '{"schema":1,';
		$this->site->cron  = self::cron_settled();
		$this->site->time += 10;
		$this->site->admin_page();

		$report = $this->reports()->report( 1 );

		$this->assertSame( 'completed', $report['status'] );
		$this->assertSame(
			array(
				'during_update' => array(
					'options'          => true,
					'cron'             => true,
					'action_scheduler' => 'not_installed',
				),
				'post_update'   => array(
					'options'          => true,
					'cron'             => 'snapshot_unavailable',
					'action_scheduler' => 'not_installed',
				),
				'final'         => array(
					'options'          => true,
					'cron'             => true,
					'action_scheduler' => 'not_installed',
				),
			),
			self::availability( $report )
		);
	}

	/**
	 * Malformed Cron state: the options report is complete, Cron unavailable everywhere.
	 */
	public function test_malformed_cron_report() {
		$this->cron_update( self::malformed( self::cron_before() ), self::malformed( self::cron_immediate() ), self::malformed( self::cron_settled() ) );
		$report = $this->reports()->report( 1 );

		$this->assertSame( 'completed', $report['status'] );
		$this->assertNull( $report['error'] );
		foreach ( $report['phases'] as $phase ) {
			$this->assertTrue( $phase['options']['available'] );
			$this->assertSame( 'malformed_cron_state', $phase['cron']['reason'] );
		}
		$this->assertStringNotContainsString( self::CRON_SECRET, self::json( $report ) );
		$this->assertStringNotContainsString( 'acme_broken', self::json( $report ) );
	}

	/**
	 * Expired settle window (expired by the report read): during available, after/net expired for both signals.
	 */
	public function test_expired_cron_report() {
		$this->cron_update( self::cron_before(), self::cron_immediate(), null );
		$this->site->time += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS + 1;

		$report = $this->reports()->report( 1 );

		$this->assertSame( 'expired', $report['settle_outcome'] );
		$this->assertSame(
			array(
				'during_update' => array(
					'options'          => true,
					'cron'             => true,
					'action_scheduler' => 'not_installed',
				),
				'post_update'   => array(
					'options'          => 'settle_expired',
					'cron'             => 'settle_expired',
					'action_scheduler' => 'not_installed',
				),
				'final'         => array(
					'options'          => 'settle_expired',
					'cron'             => 'settle_expired',
					'action_scheduler' => 'not_installed',
				),
			),
			self::availability( $report )
		);
	}

	/**
	 * While awaiting, the Cron during phase is available and the rest pending.
	 */
	public function test_awaiting_cron_report() {
		$this->cron_update( self::cron_before(), self::cron_immediate(), null );
		$report = $this->reports()->report( 1 );

		$this->assertTrue( $report['phases']['during_update']['cron']['available'] );
		$this->assertSame( 'awaiting_settle', $report['phases']['post_update']['cron']['reason'] );
		$this->assertSame( 'awaiting_settle', $report['phases']['final']['cron']['reason'] );
	}

	/**
	 * A corrupt stored Cron diff makes only that Cron phase unavailable.
	 */
	public function test_corrupt_stored_cron_diff() {
		$this->cron_update( self::cron_before(), self::cron_immediate(), self::cron_settled() );
		$this->site->repository->rows[1]['cron_final_diff'] = '{"schema":1,"added":"' . self::CRON_SECRET . '"';

		$report = $this->reports()->report( 1 );

		$this->assertSame( 'data_corrupt', $report['phases']['final']['cron']['reason'] );
		$this->assertTrue( $report['phases']['final']['options']['available'] );
		$this->assertTrue( $report['phases']['during_update']['cron']['available'] );
		$this->assertTrue( $report['phases']['post_update']['cron']['available'] );
		$this->assertStringNotContainsString( self::CRON_SECRET, self::json( $report ) );
	}

	/**
	 * Reports from before Cron observation: options render; Cron is `not_captured` (migrated) or `not_recorded`.
	 */
	public function test_pre_cron_reports() {
		$this->completed_update( 'a/a.php' );
		$this->completed_update( 'b/b.php' );
		foreach ( array( 1, 2 ) as $id ) {
			foreach ( array( 'during_update', 'post_update', 'final' ) as $phase ) {
				$this->site->repository->rows[ $id ][ "cron_{$phase}_diff" ]   = null;
				$this->site->repository->rows[ $id ][ "cron_{$phase}_reason" ] = 1 === $id ? 'not_captured' : null;
			}
		}

		$reports = $this->reports();
		foreach ( array(
			1 => 'not_captured',
			2 => 'not_recorded',
		) as $id => $reason ) {
			$report = $reports->report( $id );
			foreach ( $report['phases'] as $phase ) {
				$this->assertTrue( $phase['options']['available'] );
				$this->assertSame( $reason, $phase['cron']['reason'] );
			}
		}
	}

	/**
	 * History carries only recorded/has-changes flags per signal; `has_<phase>` stays options-only.
	 */
	public function test_history_has_only_cron_flags() {
		$this->cron_update( self::malformed( self::cron_before() ), self::malformed( self::cron_immediate() ), self::malformed( self::cron_settled() ) );
		$this->cron_update( self::cron_before(), self::cron_immediate(), self::cron_settled() );
		$items = $this->reports()->history( 1, 20 )['items'];

		$this->assertSame( array( 'id', 'plugin', 'status', 'settle_outcome', 'timestamps', 'error', 'has_during_update', 'has_post_update', 'has_final', 'phases' ), array_keys( $items[1] ) );
		$this->assertTrue( $items[1]['has_final'], 'Flags describe the options signal.' );
		$this->assertSame(
			array(
				'recorded'    => false,
				'has_changes' => null,
			),
			$items[1]['phases']['final']['cron'],
			'Malformed Cron state: not recorded.'
		);
		$this->assertTrue( $items[0]['phases']['final']['cron']['has_changes'] );
		foreach ( array( 'acme_cleanup', 'wp_version_check', 'acme_update_once', self::CRON_SECRET, 'reason', 'malformed' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, self::json( $items ) );
		}
	}

	/**
	 * For analyses from the real lifecycle, History flags agree with the decoded reports:
	 * recorded = available, has_changes = any added/removed/changed/rescheduled record.
	 */
	public function test_history_flags_match_reports() {
		$this->completed_update( 'a/a.php' );
		$this->cron_update( self::cron_before(), self::cron_immediate(), self::cron_settled() );
		$this->cron_update( self::cron_before(), self::malformed( self::cron_immediate() ), self::cron_settled() );
		// An update that changes nothing (like Rank Math 1.0.278 → 1.0.279 in dogfooding).
		$request = $this->site->request();
		$this->site->start( $request, 'quiet/quiet.php' );
		$this->site->time += 5;
		$request->update_finished( 'quiet/quiet.php', null, '1.0.1' );
		$request->request_ending( true );
		$this->site->time += 10;
		$this->site->admin_page();
		$this->cron_update( self::cron_before(), self::cron_immediate(), null ); // Still awaiting.

		$reports = $this->reports();
		$seen    = array();
		foreach ( $reports->history( 1, 20 )['items'] as $item ) {
			$report = $reports->report( $item['id'] );
			foreach ( $item['phases'] as $phase => $signals ) {
				foreach ( $signals as $signal => $flags ) {
					$data  = $report['phases'][ $phase ][ $signal ];
					$label = "#{$item['id']} {$phase} {$signal}";
					$this->assertSame( $data['available'], $flags['recorded'], $label );
					if ( ! $data['available'] ) {
						$this->assertNull( $flags['has_changes'], $label );
						$seen['not recorded'] = true;
						continue;
					}
					$records = count( $data['added'] ) + count( $data['removed'] ) + count( $data['changed'] ) + ( isset( $data['rescheduled'] ) ? count( $data['rescheduled'] ) : 0 );
					$this->assertSame( $records > 0, $flags['has_changes'], $label );
					$seen[ $records > 0 ? 'changes' : 'no changes' ] = true;
				}
			}
		}
		$this->assertCount( 3, $seen, 'Covers changes, no changes and not recorded.' );

		$quiet = $reports->history( 1, 20 )['items'][1];
		$this->assertSame( 'quiet/quiet.php', $quiet['plugin']['file'] );
		foreach ( $quiet['phases'] as $signals ) {
			foreach ( array( 'options', 'cron' ) as $signal ) {
				$this->assertSame(
					array(
						'recorded'    => true,
						'has_changes' => false,
					),
					$signals[ $signal ]
				);
			}
			$this->assertFalse( $signals['action_scheduler']['recorded'], 'No Action Scheduler on this site: not recorded, not "no changes".' );
		}
	}

	/**
	 * No Cron argument, fingerprint, context, md5 event key or snapshot reaches history or reports.
	 */
	public function test_cron_privacy() {
		$this->cron_update( self::cron_before(), self::cron_immediate(), self::cron_settled() );
		$this->cron_update( self::cron_settled(), self::malformed( self::cron_settled() ), null ); // Awaiting, with a stored Cron BEFORE.
		$this->assertNotNull( $this->site->repository->rows[2]['cron_before_snapshot'] );

		$reports = $this->reports();
		$outputs = array( $reports->history( 1, 20 ), $reports->report( 1 ), $reports->report( 2 ) );

		$forbidden = array(
			self::CRON_SECRET,
			'hooks.example.test',
			'token',
			'args',
			'fingerprint',
			'cron-args-hmac',
			'snapshot',
			'"schema"',
			md5( serialize( array( 'token' => self::CRON_SECRET ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Core's unkeyed event key.
			md5( serialize( array( 'https://hooks.example.test/' . self::CRON_SECRET ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Core's unkeyed event key.
		);
		$snapshot  = json_decode( $this->site->repository->rows[2]['cron_before_snapshot'], true );
		foreach ( $snapshot['events'] as $event ) {
			$forbidden[] = $event['args_fingerprint'];
		}
		$forbidden[] = $snapshot['fingerprint_context'];

		foreach ( $outputs as $output ) {
			foreach ( array( self::json( $output ), serialize( $output ), var_export( $output, true ) ) as $serialized ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Privacy assertion.
				foreach ( $forbidden as $needle ) {
					$this->assertStringNotContainsString( $needle, $serialized );
				}
			}
		}
	}

	/**
	 * Obviously fake credential stored in real Action Scheduler arguments.
	 */
	const AS_SECRET = 'sk_test_UPDATE_LENS_ACTION_SCHEDULER_REPORT_SECRET';

	/**
	 * Secret arguments, as a plugin would pass an order or webhook token.
	 *
	 * @return array
	 */
	private static function as_args() {
		return array(
			'token'   => self::AS_SECRET,
			'webhook' => 'https://hooks.example.test/' . self::AS_SECRET,
		);
	}

	/**
	 * Queue before the update (shaped like the WooCommerce 11.1.1 → 11.1.2 study).
	 *
	 * @return array
	 */
	private static function as_before() {
		return array(
			ActionSchedulerFixture::recurring( 'action_scheduler/migration_hook', self::T0 + 60, 60, array(), 'action-scheduler-migration' ),
			ActionSchedulerFixture::single( 'wpforms_admin_notifications_update', self::T0 + 30, self::as_args(), 'wpforms' ),
			ActionSchedulerFixture::recurring( 'wpforms_email_summaries_fetch_info_blocks', self::T0 + 3600, 604800, self::as_args(), 'wpforms' ),
		);
	}

	/**
	 * Right after the update request: WooCommerce queued its pattern fetch.
	 *
	 * @return array
	 */
	private static function as_immediate() {
		$rows   = self::as_before();
		$rows[] = ActionSchedulerFixture::recurring( 'fetch_patterns', self::T0 + 5, 86400, array(), 'woocommerce' );

		return $rows;
	}

	/**
	 * At settle: migration and pattern fetch moved, the WPForms job left the
	 * active queue, a recurring job became a cron schedule, an async job runs.
	 *
	 * @return array
	 */
	private static function as_settled() {
		$running           = ActionSchedulerFixture::async( 'woocommerce_run_on_woocommerce_admin_updated', self::T0 + 12, self::as_args(), 'woocommerce-remote-inbox-engine' );
		$running['status'] = 'in-progress';

		return array(
			ActionSchedulerFixture::recurring( 'action_scheduler/migration_hook', self::T0 + 144, 60, array(), 'action-scheduler-migration' ),
			ActionSchedulerFixture::cron( 'wpforms_email_summaries_fetch_info_blocks', self::T0 + 3600, '0 */6 * * *', self::as_args(), 'wpforms' ),
			ActionSchedulerFixture::recurring( 'fetch_patterns', self::T0 + 86419, 86400, array(), 'woocommerce' ),
			$running,
		);
	}

	/**
	 * An update with the given Action Scheduler states (rows, null = not
	 * installed, or a Throwable from the provider); a null settled state
	 * with $settle false leaves it awaiting.
	 *
	 * @param mixed $before    State before.
	 * @param mixed $immediate State after the update request.
	 * @param mixed $settled   State at the next admin page.
	 * @param bool  $settle    Whether a later admin page settles it.
	 * @return void
	 */
	private function as_update( $before, $immediate, $settled, $settle = true ) {
		$this->site->action_scheduler = $before;
		$request                      = $this->site->request();
		$this->site->start( $request, 'acme/acme.php' );
		$this->site->options['acme_needs_migration'] = array( '1', 'off' );
		$this->site->action_scheduler                = $immediate;
		$this->site->time                           += 5;
		$request->update_finished( 'acme/acme.php', null, '1.1.0' );
		$request->request_ending( true );

		if ( $settle ) {
			$this->site->action_scheduler = $settled;
			$this->site->time            += 10;
			$this->site->admin_page();
		}
	}

	/**
	 * Hooks per list of an Action Scheduler phase.
	 *
	 * @param array $phase Available Action Scheduler phase.
	 * @return array
	 */
	private static function as_hooks( array $phase ) {
		return array(
			'added'       => array_column( $phase['added'], 'hook' ),
			'removed'     => array_column( $phase['removed'], 'hook' ),
			'rescheduled' => array_column( $phase['rescheduled'], 'hook' ),
			'changed'     => array_column( $phase['changed'], 'hook' ),
		);
	}

	/**
	 * Full success: all three signals available in all phases; every category
	 * appears, cross-plugin work included; Options and Cron unchanged.
	 */
	public function test_action_scheduler_full_report() {
		$this->as_update( self::as_before(), self::as_immediate(), self::as_settled() );
		$report = $this->reports()->report( 1 );

		$this->assertSame(
			array_fill_keys(
				array( 'during_update', 'post_update', 'final' ),
				array(
					'options'          => true,
					'cron'             => true,
					'action_scheduler' => true,
				)
			),
			self::availability( $report )
		);

		$during = $report['phases']['during_update']['action_scheduler'];
		$this->assertSame( 'update_request', $during['association'] );
		$this->assertSame(
			array(
				'added'       => array( 'fetch_patterns' ),
				'removed'     => array(),
				'rescheduled' => array(),
				'changed'     => array(),
			),
			self::as_hooks( $during )
		);
		$this->assertSame(
			array(
				'hook'            => 'fetch_patterns',
				'group'           => 'woocommerce',
				'status'          => 'pending',
				'timestamp'       => self::T0 + 5,
				'schedule_type'   => 'interval',
				'interval'        => 86400,
				'cron_expression' => null,
				'is_recurring'    => true,
			),
			$during['added'][0]
		);

		$post = $report['phases']['post_update']['action_scheduler'];
		$this->assertSame(
			array(
				'added'       => array( 'woocommerce_run_on_woocommerce_admin_updated' ),
				'removed'     => array( 'wpforms_admin_notifications_update' ),
				'rescheduled' => array( 'action_scheduler/migration_hook', 'fetch_patterns' ),
				'changed'     => array( 'wpforms_email_summaries_fetch_info_blocks' ),
			),
			self::as_hooks( $post )
		);
		$this->assertSame( array( 84, 86414 ), array_column( $post['rescheduled'], 'timestamp_delta' ) );
		$this->assertSame( 'in-progress', $post['added'][0]['status'] );
		$this->assertSame( 'async', $post['added'][0]['schedule_type'] );
		$this->assertSame( '0 */6 * * *', $post['changed'][0]['after_cron_expression'] );

		$final = $report['phases']['final']['action_scheduler'];
		$this->assertSame(
			array(
				'added'       => array( 'fetch_patterns', 'woocommerce_run_on_woocommerce_admin_updated' ),
				'removed'     => array( 'wpforms_admin_notifications_update' ),
				'rescheduled' => array( 'action_scheduler/migration_hook' ),
				'changed'     => array( 'wpforms_email_summaries_fetch_info_blocks' ),
			),
			self::as_hooks( $final )
		);
		$this->assertSame( 1, $final['summary']['action_count_delta'] );
		foreach ( $final['summary'] as $key => $value ) {
			$this->assertIsInt( $value, $key );
		}
	}

	/**
	 * Partial availability: Action Scheduler appeared after BEFORE. During and
	 * Net are not_installed, After is available; no invented additions.
	 */
	public function test_action_scheduler_partial_availability() {
		$this->as_update( null, self::as_immediate(), self::as_settled() );
		$report = $this->reports()->report( 1 );

		$this->assertSame(
			array( 'not_installed', true, 'not_installed' ),
			array_values( array_column( self::availability( $report ), 'action_scheduler' ) )
		);
		$this->assertSame( 4, $report['phases']['post_update']['action_scheduler']['summary']['before_action_count'] );
		foreach ( $report['phases'] as $phase ) {
			$this->assertTrue( $phase['options']['available'] );
			$this->assertTrue( $phase['cron']['available'] );
		}
	}

	/**
	 * Not installed anywhere: a normal state, the analysis is complete.
	 */
	public function test_action_scheduler_not_installed() {
		$this->as_update( null, null, null );
		$report = $this->reports()->report( 1 );

		$this->assertSame( 'completed', $report['status'] );
		$this->assertNull( $report['error'] );
		foreach ( $report['phases'] as $phase ) {
			$this->assertSame( 'not_installed', $phase['action_scheduler']['reason'] );
			$this->assertTrue( $phase['options']['available'] );
		}
	}

	/**
	 * Provider states: each stays distinct and never affects Options or Cron.
	 *
	 * @return array
	 */
	public function provide_action_scheduler_states() {
		return array(
			'unsupported store'    => array( ActionSchedulerUnavailableException::unsupported_store(), 'unsupported_store' ),
			'unsupported schema'   => array( ActionSchedulerUnavailableException::unsupported_schema(), 'unsupported_schema' ),
			'read failed'          => array( ActionSchedulerUnavailableException::read_failed(), 'snapshot_unavailable' ),
			'malformed'            => array( MalformedActionSchedulerStateException::invalid_schedule(), 'malformed_action_scheduler_state' ),
			'unsupported schedule' => array( MalformedActionSchedulerStateException::unsupported_schedule(), 'unsupported_schedule' ),
		);
	}

	/**
	 * Provider states in reports.
	 *
	 * @dataProvider provide_action_scheduler_states
	 * @param \Throwable $state  Provider failure at every capture.
	 * @param string     $reason Expected reason.
	 */
	public function test_action_scheduler_provider_states( $state, $reason ) {
		$this->as_update( $state, $state, $state );
		$report = $this->reports()->report( 1 );

		$this->assertSame( 'completed', $report['status'] );
		foreach ( $report['phases'] as $phase ) {
			$this->assertSame( $reason, $phase['action_scheduler']['reason'] );
			$this->assertTrue( $phase['options']['available'] );
			$this->assertTrue( $phase['cron']['available'] );
		}
	}

	/**
	 * Expired settle window: during available, after/net settle_expired; awaiting before that.
	 */
	public function test_expired_and_awaiting_action_scheduler_report() {
		$this->as_update( self::as_before(), self::as_immediate(), null, false );

		$awaiting = $this->reports()->report( 1 );
		$this->assertSame( array( true, 'awaiting_settle', 'awaiting_settle' ), array_values( array_column( self::availability( $awaiting ), 'action_scheduler' ) ) );

		$this->site->time += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS + 1;
		$expired           = $this->reports()->report( 1 );
		$this->assertSame( 'expired', $expired['settle_outcome'] );
		$this->assertSame( array( true, 'settle_expired', 'settle_expired' ), array_values( array_column( self::availability( $expired ), 'action_scheduler' ) ) );
		$this->assertSame( array( 'fetch_patterns' ), array_column( $expired['phases']['during_update']['action_scheduler']['added'], 'hook' ) );
	}

	/**
	 * Failed update and in-progress analyses.
	 */
	public function test_failed_and_in_progress_action_scheduler_report() {
		$this->site->action_scheduler = self::as_before();
		$request                      = $this->site->request();
		$this->site->start( $request, 'a/a.php' );
		$this->assertSame( array( 'update_in_progress', 'update_in_progress', 'update_in_progress' ), array_values( array_column( self::availability( $this->reports()->report( 1 ) ), 'action_scheduler' ) ) );

		$request->update_finished( 'a/a.php', 'download_failed', null );
		$request->request_ending( true );
		$this->assertSame( array( 'update_failed', 'update_failed', 'update_failed' ), array_values( array_column( self::availability( $this->reports()->report( 1 ) ), 'action_scheduler' ) ) );
	}

	/**
	 * Reports from before Action Scheduler observation (schema 4 migration):
	 * Options and Cron render; Action Scheduler is not_captured, never zero changes.
	 */
	public function test_pre_action_scheduler_reports() {
		$this->as_update( self::as_before(), self::as_immediate(), self::as_settled() );
		foreach ( array( 'during_update', 'post_update', 'final' ) as $phase ) {
			$this->site->repository->rows[1][ "action_scheduler_{$phase}_diff" ]   = null;
			$this->site->repository->rows[1][ "action_scheduler_{$phase}_reason" ] = 'not_captured';
		}

		$reports = $this->reports();
		$report  = $reports->report( 1 );
		foreach ( $report['phases'] as $phase ) {
			$this->assertTrue( $phase['options']['available'] );
			$this->assertTrue( $phase['cron']['available'] );
			$this->assertSame( 'not_captured', $phase['action_scheduler']['reason'] );
		}
		foreach ( $reports->history( 1, 20 )['items'][0]['phases'] as $phase ) {
			$this->assertSame(
				array(
					'recorded'    => false,
					'has_changes' => null,
				),
				$phase['action_scheduler']
			);
		}
	}

	/**
	 * A corrupt stored Action Scheduler diff makes only that phase unavailable; the report still reads.
	 */
	public function test_corrupt_stored_action_scheduler_diff() {
		$this->as_update( self::as_before(), self::as_immediate(), self::as_settled() );
		$this->site->repository->rows[1]['action_scheduler_post_update_diff'] = '{"schema":1,"added":"' . self::AS_SECRET . '"';

		$report = $this->reports()->report( 1 );

		$this->assertSame( array( true, 'data_corrupt', true ), array_values( array_column( self::availability( $report ), 'action_scheduler' ) ) );
		$this->assertTrue( $report['phases']['post_update']['options']['available'] );
		$this->assertTrue( $report['phases']['post_update']['cron']['available'] );
		$this->assertStringNotContainsString( self::AS_SECRET, self::json( $report ) );
		$this->assertTrue( $this->reports()->history( 1, 20 )['items'][0]['phases']['post_update']['action_scheduler']['recorded'], 'History does not decode diffs.' );
	}

	/**
	 * History carries Action Scheduler flags; a phase whose only changes are
	 * Action Scheduler changes has changes for that signal alone.
	 */
	public function test_history_action_scheduler_flags() {
		// Options and Cron unchanged after the update request; only Action Scheduler moves.
		$this->site->action_scheduler = self::as_before();
		$request                      = $this->site->request();
		$this->site->start( $request, 'quiet/quiet.php' );
		$this->site->action_scheduler = self::as_immediate();
		$this->site->time            += 5;
		$request->update_finished( 'quiet/quiet.php', null, '1.0.1' );
		$request->request_ending( true );
		$this->site->action_scheduler = self::as_immediate();
		$this->site->time            += 10;
		$this->site->admin_page();

		$item = $this->reports()->history( 1, 20 )['items'][0];

		$this->assertSame(
			array(
				'options'          => array(
					'recorded'    => true,
					'has_changes' => false,
				),
				'cron'             => array(
					'recorded'    => true,
					'has_changes' => false,
				),
				'action_scheduler' => array(
					'recorded'    => true,
					'has_changes' => true,
				),
			),
			$item['phases']['during_update']
		);
		$this->assertFalse( $item['phases']['post_update']['action_scheduler']['has_changes'] );
		$this->assertTrue( $item['phases']['final']['action_scheduler']['has_changes'] );
		foreach ( array( 'fetch_patterns', 'woocommerce', self::AS_SECRET, 'reason' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, self::json( $item ) );
		}
	}

	/**
	 * History flags agree with decoded Action Scheduler reports across states.
	 */
	public function test_action_scheduler_history_flags_match_reports() {
		$this->as_update( self::as_before(), self::as_immediate(), self::as_settled() );
		$this->as_update( null, self::as_immediate(), self::as_immediate() );
		$this->as_update( self::as_before(), self::as_before(), self::as_before() );

		$reports = $this->reports();
		foreach ( $reports->history( 1, 20 )['items'] as $item ) {
			$report = $reports->report( $item['id'] );
			foreach ( $item['phases'] as $phase => $signals ) {
				$data  = $report['phases'][ $phase ]['action_scheduler'];
				$flags = $signals['action_scheduler'];
				$this->assertSame( $data['available'], $flags['recorded'] );
				if ( $data['available'] ) {
					$records = count( $data['added'] ) + count( $data['removed'] ) + count( $data['changed'] ) + count( $data['rescheduled'] );
					$this->assertSame( $records > 0, $flags['has_changes'], "#{$item['id']} {$phase}" );
				} else {
					$this->assertNull( $flags['has_changes'] );
				}
			}
		}
	}

	/**
	 * No Action Scheduler argument, fingerprint, context, snapshot or ID reaches history or reports.
	 */
	public function test_action_scheduler_privacy() {
		$this->as_update( self::as_before(), self::as_immediate(), self::as_settled() );
		$this->as_update( self::as_settled(), self::as_settled(), null, false ); // Awaiting, with a stored BEFORE snapshot.
		$stored = $this->site->repository->rows[2]['action_scheduler_before_snapshot'];
		$this->assertNotNull( $stored );
		$this->assertStringNotContainsString( self::AS_SECRET, $stored );

		$reports = $this->reports();
		$outputs = array( $reports->history( 1, 20 ), $reports->report( 1 ), $reports->report( 2 ) );

		$forbidden = array( self::AS_SECRET, 'hooks.example.test', 'token', 'webhook', 'args', 'fingerprint', 'as-args-hmac', 'snapshot', '"schema"', 'action_id', 'claim', 'O:', 'ActionScheduler_' );
		$snapshot  = json_decode( $stored, true );
		foreach ( $snapshot['actions'] as $action ) {
			$forbidden[] = $action['args_fingerprint'];
		}
		$forbidden[] = $snapshot['fingerprint_context'];
		$forbidden[] = substr( $snapshot['fingerprint_context'], strpos( $snapshot['fingerprint_context'], ':' ) + 1 );

		foreach ( $outputs as $output ) {
			foreach ( array( self::json( $output ), serialize( $output ), var_export( $output, true ) ) as $serialized ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Privacy assertion.
				foreach ( $forbidden as $needle ) {
					$this->assertStringNotContainsString( $needle, $serialized );
				}
			}
		}
	}
}
