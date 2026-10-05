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
use UpdateLens\Storage\OptionsDiffCodec;
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
		$this->assertTrue( $report['phases']['during_update']['available'] );
		$this->assertSame(
			array(
				'available'   => false,
				'association' => 'observed_after_update',
				'reason'      => 'settle_expired',
			),
			$report['phases']['post_update']
		);
		$this->assertSame(
			array(
				'available'   => false,
				'association' => 'net_across_phases',
				'reason'      => 'settle_expired',
			),
			$report['phases']['final']
		);
		$this->assertSame( '2027-01-15T08:05:01Z', $report['timestamps']['completed_at'] );
		$this->assertSame( $captures, $this->site->captures );
		$this->assertNull( $this->site->repository->rows[1]['before_snapshot'] );
		$this->assertNull( $this->site->repository->rows[1]['immediate_snapshot'] );
	}

	/**
	 * Completed report: all three phases decoded from the stored diffs.
	 */
	public function test_completed_report() {
		$this->completed_update();
		$row    = $this->site->repository->rows[1];
		$codec  = new OptionsDiffCodec();
		$report = $this->reports()->report( 1 );

		$this->assertSame( array( 'id', 'plugin', 'status', 'settle_outcome', 'timestamps', 'phases', 'error' ), array_keys( $report ) );
		$this->assertSame( 1, $report['id'] );
		$this->assertSame( 'completed', $report['status'] );
		$this->assertSame( 'admin_shutdown', $report['settle_outcome'] );
		$this->assertNull( $report['error'] );
		$this->assertSame( array( 'during_update', 'post_update', 'final' ), array_keys( $report['phases'] ) );

		$associations = array(
			'during_update' => array( 'update_request', 'during_update_diff' ),
			'post_update'   => array( 'observed_after_update', 'post_update_diff' ),
			'final'         => array( 'net_across_phases', 'final_diff' ),
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
				$report['phases'][ $phase ],
				$phase
			);
		}

		$this->assertSame( array( 'acme_needs_migration' ), array_column( $report['phases']['during_update']['added'], 'name' ) );
		$this->assertSame( array( 'acme_flags' ), array_column( $report['phases']['post_update']['added'], 'name' ) );
		$this->assertSame( array( 'acme_legacy', 'acme_needs_migration' ), array_column( $report['phases']['post_update']['removed'], 'name' ) );
		$this->assertSame( array( 'acme_settings' ), array_column( $report['phases']['final']['changed'], 'name' ) );
		$this->assertSame( array( 'acme_legacy' ), array_column( $report['phases']['final']['removed'], 'name' ) );

		$summary = $report['phases']['final']['summary'];
		$this->assertSame( 0, $summary['option_count_delta'] );
		$this->assertSame( 1, $summary['changed_count'] );
		$this->assertSame( 1, $summary['value_changed_count'] );
		foreach ( $summary as $key => $value ) {
			$this->assertIsInt( $value, $key );
		}
		$changed = $report['phases']['final']['changed'][0];
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
			$this->assertFalse( $phase['available'] );
			$this->assertSame( UnavailableReason::UPDATE_FAILED, $phase['reason'] );
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
		$this->assertSame( UnavailableReason::UPDATE_FAILED, $report['phases']['during_update']['reason'] );
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
			$this->assertSame( UnavailableReason::FINGERPRINT_CONTEXT_CHANGED, $phase['reason'] );
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
		$this->assertTrue( $report['phases']['during_update']['available'] );
		$this->assertSame( UnavailableReason::FINGERPRINT_CONTEXT_CHANGED, $report['phases']['post_update']['reason'] );
		$this->assertSame( UnavailableReason::FINGERPRINT_CONTEXT_CHANGED, $report['phases']['final']['reason'] );
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
		$this->assertTrue( $report['phases']['during_update']['available'] );
		$this->assertSame( UnavailableReason::AWAITING_SETTLE, $report['phases']['post_update']['reason'] );
		$this->assertSame( UnavailableReason::AWAITING_SETTLE, $report['phases']['final']['reason'] );
	}

	/**
	 * Update still running (BEFORE captured only).
	 */
	public function test_in_progress_report() {
		$this->site->start( $this->site->request(), 'acme/acme.php' );

		$report = $this->reports()->report( 1 );

		$this->assertSame( 'captured', $report['status'] );
		$this->assertSame( UnavailableReason::UPDATE_IN_PROGRESS, $report['phases']['during_update']['reason'] );
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
		$this->assertSame( UnavailableReason::ANALYSIS_ABANDONED, $report['phases']['during_update']['reason'] );
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
		$this->site->repository->rows[1]['post_update_diff'] = '{"schema":1,"added":"' . self::FAKE_SECRET . '"';

		$report = $this->reports()->report( 1 );

		$this->assertTrue( $report['phases']['during_update']['available'] );
		$this->assertTrue( $report['phases']['final']['available'] );
		$this->assertSame(
			array(
				'available'   => false,
				'association' => 'observed_after_update',
				'reason'      => 'data_corrupt',
			),
			$report['phases']['post_update']
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
			foreach ( array( 'before_snapshot', 'immediate_snapshot' ) as $column ) {
				if ( null !== $row[ $column ] ) {
					$snapshot = json_decode( $row[ $column ], true );
					foreach ( $snapshot['options'] as $option ) {
						$forbidden[] = $option['fingerprint'];
					}
				}
			}
			foreach ( array( 'during_update_diff', 'post_update_diff', 'final_diff' ) as $column ) {
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
}
