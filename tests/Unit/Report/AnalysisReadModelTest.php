<?php
/**
 * Tests for the analysis read model.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UpdateLens\Diff\ActionSchedulerDiffBuilder;
use UpdateLens\Diff\CronDiffBuilder;
use UpdateLens\Report\AnalysisReadModel;
use UpdateLens\Report\UnavailableReason;
use UpdateLens\Storage\ActionSchedulerDiffCodec;
use UpdateLens\Storage\CronDiffCodec;
use UpdateLens\Tests\Support\ActionSchedulerFixture;
use UpdateLens\Tests\Support\CronFixture;
use UpdateLens\Update\ActionSchedulerPhaseReason;
use UpdateLens\Update\CronPhaseReason;

/**
 * Stored rows → safe API arrays.
 */
final class AnalysisReadModelTest extends TestCase {

	/**
	 * A completed row as MySQL returns it (every value a string), with columns the API must never expose.
	 *
	 * @param array $overrides Column overrides.
	 * @return array
	 */
	private static function row( array $overrides = array() ) {
		return array_merge(
			array(
				'id'                             => '42',
				'plugin_file'                    => 'acme/acme.php',
				'plugin_name'                    => 'Acme',
				'version_before'                 => '1.0.0',
				'version_after'                  => '1.1.0',
				'user_id'                        => '7',
				'status'                         => 'completed',
				'active_plugin'                  => null,
				'settle_outcome'                 => 'expired',
				'started_at'                     => '2026-10-05 17:46:18',
				'updated_at'                     => '2026-10-05 17:51:21',
				'settle_deadline'                => '2026-10-05 17:51:20',
				'completed_at'                   => '2026-10-05 17:51:21',
				'options_before_snapshot'        => '{"schema":1,"fingerprint_context":"hmac-sha256-v1:x"}',
				'options_immediate_snapshot'     => '{"schema":1}',
				'options_during_update_diff'     => null,
				'options_post_update_diff'       => null,
				'options_final_diff'             => null,
				'error_code'                     => null,
				'error_message'                  => 'Stored message',
				'has_options_during_update_diff' => '0',
				'has_options_post_update_diff'   => '0',
				'has_options_final_diff'         => '0',
			),
			$overrides
		);
	}

	/**
	 * Obviously fake credential used in Cron argument fixtures.
	 */
	const FAKE_SECRET = 'sk_test_UPDATE_LENS_CRON_REPORT_SECRET';

	/**
	 * Options part of every phase.
	 *
	 * @param array $report Report.
	 * @return array<string, array>
	 */
	private static function options( array $report ) {
		return array_map(
			static function ( $phase ) {
				return $phase['options'];
			},
			$report['phases']
		);
	}

	/**
	 * Cron part of every phase.
	 *
	 * @param array $report Report.
	 * @return array<string, array>
	 */
	private static function cron( array $report ) {
		return array_map(
			static function ( $phase ) {
				return $phase['cron'];
			},
			$report['phases']
		);
	}

	/**
	 * Stored Cron diff JSON for a fixture with secret arguments: one added, one rescheduled event.
	 *
	 * @return string
	 */
	private static function cron_diff_json() {
		$args   = array( 'token' => self::FAKE_SECRET );
		$before = CronFixture::snapshot( CronFixture::cron( array( CronFixture::recurring( 1767225600, 'acme_cleanup', 'daily', $args ) ) ) );
		$after  = CronFixture::snapshot(
			CronFixture::cron(
				array(
					CronFixture::recurring( 1767229200, 'acme_cleanup', 'daily', $args ),
					CronFixture::single( 1767225900, 'acme_once', array( 'https://hooks.example.test/' . self::FAKE_SECRET ) ),
				)
			)
		);

		return ( new CronDiffCodec() )->encode( ( new CronDiffBuilder() )->build( $before, $after ) );
	}

	/**
	 * Obviously fake credential used in Action Scheduler argument fixtures.
	 */
	const AS_SECRET = 'sk_test_UPDATE_LENS_ACTION_SCHEDULER_REPORT_SECRET';

	/**
	 * Action Scheduler part of every phase.
	 *
	 * @param array $report Report.
	 * @return array<string, array>
	 */
	private static function action_scheduler( array $report ) {
		return array_column( $report['phases'], 'action_scheduler' );
	}

	/**
	 * Stored Action Scheduler diff JSON for a fixture with secret arguments:
	 * one of each category, an in-progress action and a cron schedule.
	 *
	 * @return string
	 */
	private static function action_scheduler_diff_json() {
		$args              = array(
			'order' => 1234,
			'token' => self::AS_SECRET,
		);
		$t                 = 1767225600;
		$running           = ActionSchedulerFixture::async( 'wc_run_on_admin_updated', $t + 30, array( 'hook' => 'https://hooks.example.test/' . self::AS_SECRET ), 'woocommerce-remote-inbox-engine' );
		$running['status'] = 'in-progress';
		$before            = ActionSchedulerFixture::snapshot(
			array(
				ActionSchedulerFixture::recurring( 'action_scheduler/migration_hook', $t + 60, 60, array(), 'action-scheduler-migration' ),
				ActionSchedulerFixture::single( 'wpforms_admin_notifications_update', $t + 10, $args, 'wpforms' ),
				ActionSchedulerFixture::recurring( 'acme_sync', $t + 900, 86400, $args, 'acme' ),
			)
		);
		$after             = ActionSchedulerFixture::snapshot(
			array(
				ActionSchedulerFixture::recurring( 'action_scheduler/migration_hook', $t + 144, 60, array(), 'action-scheduler-migration' ),
				ActionSchedulerFixture::cron( 'acme_sync', $t + 900, '0 */6 * * *', $args, 'acme' ),
				ActionSchedulerFixture::recurring( 'fetch_patterns', $t + 86400, 86400, array(), 'woocommerce' ),
				$running,
			)
		);

		return ( new ActionSchedulerDiffCodec() )->encode( ( new ActionSchedulerDiffBuilder() )->build( $before, $after ) );
	}

	/**
	 * Only whitelisted fields, with JSON types; database strings become ints/bools.
	 */
	public function test_history_item_fields_and_types() {
		$item = ( new AnalysisReadModel() )->history_item(
			self::row(
				array(
					'has_options_during_update_diff' => '1',
					'has_changes_options_during_update_diff' => '1',
				)
			)
		);
		$none = array(
			'recorded'    => false,
			'has_changes' => null,
		);

		$this->assertSame(
			array(
				'id'                => 42,
				'plugin'            => array(
					'file'           => 'acme/acme.php',
					'name'           => 'Acme',
					'version_before' => '1.0.0',
					'version_after'  => '1.1.0',
				),
				'status'            => 'completed',
				'settle_outcome'    => 'expired',
				'timestamps'        => array(
					'started_at'      => '2026-10-05T17:46:18Z',
					'settle_deadline' => '2026-10-05T17:51:20Z',
					'completed_at'    => '2026-10-05T17:51:21Z',
				),
				'error'             => null,
				'has_during_update' => true,
				'has_post_update'   => false,
				'has_final'         => false,
				'phases'            => array(
					'during_update' => array(
						'options'          => array(
							'recorded'    => true,
							'has_changes' => true,
						),
						'cron'             => $none,
						'action_scheduler' => $none,
					),
					'post_update'   => array(
						'options'          => $none,
						'cron'             => $none,
						'action_scheduler' => $none,
					),
					'final'         => array(
						'options'          => $none,
						'cron'             => $none,
						'action_scheduler' => $none,
					),
				),
			),
			$item
		);
	}

	/**
	 * Each signal says independently whether it was recorded and whether it has changes.
	 */
	public function test_history_change_flags_per_signal() {
		$item = ( new AnalysisReadModel() )->history_item(
			self::row(
				array(
					'has_options_final_diff'            => '1',
					'has_changes_options_final_diff'    => '0',
					'has_cron_final_diff'               => '1',
					'has_changes_cron_final_diff'       => '1',
					'has_options_during_update_diff'    => '1',
					'has_changes_options_during_update_diff' => null,
					'has_cron_post_update_diff'         => '0',
					'has_changes_cron_post_update_diff' => '1',
				)
			)
		);

		$this->assertSame(
			array(
				'recorded'    => true,
				'has_changes' => false,
			),
			$item['phases']['final']['options'],
			'Recorded without changes.'
		);
		$this->assertSame(
			array(
				'recorded'    => true,
				'has_changes' => true,
			),
			$item['phases']['final']['cron']
		);
		$this->assertFalse( $item['phases']['during_update']['options']['has_changes'], 'A missing SQL flag is not a change.' );
		$this->assertSame(
			array(
				'recorded'    => false,
				'has_changes' => null,
			),
			$item['phases']['post_update']['cron'],
			'Not recorded: no change flag, whatever the row says.'
		);
		$this->assertTrue( $item['has_final'] );
		$this->assertFalse( $item['has_post_update'], 'has_<phase> stays options-only.' );
	}

	/**
	 * Integer flags (e.g. from SQLite) work like MySQL strings.
	 */
	public function test_history_flags_from_integers() {
		$item = ( new AnalysisReadModel() )->history_item(
			self::row(
				array(
					'has_options_during_update_diff' => 1,
					'has_options_post_update_diff'   => 0,
				)
			)
		);

		$this->assertTrue( $item['has_during_update'] );
		$this->assertFalse( $item['has_post_update'] );
		$this->assertFalse( $item['has_final'] );
	}

	/**
	 * Reports never contain snapshots, user IDs, stored messages or other internal columns.
	 */
	public function test_report_whitelists_fields() {
		$report = ( new AnalysisReadModel() )->report( self::row() );

		$this->assertSame( array( 'id', 'plugin', 'status', 'settle_outcome', 'timestamps', 'observation_window_seconds', 'phases', 'error' ), array_keys( $report ) );
		$this->assertSame( 300, $report['observation_window_seconds'] );
		foreach ( $report['phases'] as $phase ) {
			$this->assertSame( array( 'options', 'cron', 'action_scheduler' ), array_keys( $phase ) );
		}
		$json = (string) json_encode( $report ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
		foreach ( array( 'snapshot', 'fingerprint', 'hmac', 'user_id', 'Stored message', 'active_plugin', 'updated_at', '"schema"' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}

	/**
	 * Stored UTC DATETIME → ISO 8601 UTC, invalid → null.
	 *
	 * @return array
	 */
	public function provide_timestamps() {
		return array(
			'datetime'      => array( '2026-10-05 17:46:23', '2026-10-05T17:46:23Z' ),
			'leap day'      => array( '2028-02-29 00:00:00', '2028-02-29T00:00:00Z' ),
			'null'          => array( null, null ),
			'empty'         => array( '', null ),
			'zero date'     => array( '0000-00-00 00:00:00', null ),
			'invalid day'   => array( '2026-02-30 10:00:00', null ),
			'invalid hour'  => array( '2026-10-05 24:00:00', null ),
			'already ISO'   => array( '2026-10-05T17:46:23Z', null ),
			'trailing text' => array( "2026-10-05 17:46:23\n", null ),
			'integer'       => array( 1800000000, null ),
		);
	}

	/**
	 * Timestamp conversion.
	 *
	 * @dataProvider provide_timestamps
	 * @param mixed       $stored   Stored value.
	 * @param string|null $expected API value.
	 */
	public function test_timestamps( $stored, $expected ) {
		$report = ( new AnalysisReadModel() )->report( self::row( array( 'started_at' => $stored ) ) );

		$this->assertSame( $expected, $report['timestamps']['started_at'] );
	}

	/**
	 * Plugin files must be relative plugin basenames.
	 *
	 * @return array
	 */
	public function provide_plugin_files() {
		return array(
			'dir/file'        => array( 'woocommerce/woocommerce.php', 'woocommerce/woocommerce.php' ),
			'single file'     => array( 'hello.php', 'hello.php' ),
			'dots in names'   => array( 'my.plugin/my.plugin.php', 'my.plugin/my.plugin.php' ),
			'absolute'        => array( '/var/www/wp-content/plugins/acme/acme.php', null ),
			'windows path'    => array( 'C:\\www\\acme\\acme.php', null ),
			'backslash'       => array( 'acme\\acme.php', null ),
			'traversal'       => array( '../acme.php', null ),
			'dot segment'     => array( './acme.php', null ),
			'nested'          => array( 'a/b/c.php', null ),
			'not php'         => array( 'acme/readme.txt', null ),
			'control char'    => array( "acme/ac\x00me.php", null ),
			'trailing nl'     => array( "acme/acme.php\n", null ),
			'empty'           => array( '', null ),
			'url'             => array( 'https://example.test/acme.php', null ),
			'not a string'    => array( null, null ),
			'too long'        => array( str_repeat( 'a', 252 ) . '.php', null ),
			'unicode allowed' => array( 'плагин/плагин.php', 'плагин/плагин.php' ),
			'invalid UTF-8'   => array( "acme/\xff.php", null ),
		);
	}

	/**
	 * Plugin file validation.
	 *
	 * @dataProvider provide_plugin_files
	 * @param mixed       $stored   Stored value.
	 * @param string|null $expected API value.
	 */
	public function test_plugin_file( $stored, $expected ) {
		$report = ( new AnalysisReadModel() )->report( self::row( array( 'plugin_file' => $stored ) ) );

		$this->assertSame( $expected, $report['plugin']['file'] );
	}

	/**
	 * Text fields lose control characters; invalid UTF-8 becomes empty.
	 */
	public function test_text_fields() {
		$report = ( new AnalysisReadModel() )->report(
			self::row(
				array(
					'plugin_name'    => "Acme\x00 <b>Pro</b>\n",
					'version_before' => "\xff\xfe",
					'version_after'  => null,
				)
			)
		);

		$this->assertSame( 'Acme <b>Pro</b>', $report['plugin']['name'], 'Plain text; React escapes it.' );
		$this->assertSame( '', $report['plugin']['version_before'] );
		$this->assertNull( $report['plugin']['version_after'] );
	}

	/**
	 * Unknown status, outcome and unsafe error codes become `unknown`; nothing is reinterpreted as completed.
	 */
	public function test_unknown_values() {
		$report = ( new AnalysisReadModel() )->report(
			self::row(
				array(
					'status'         => 'legacy',
					'settle_outcome' => 'COMPLETED',
					'error_code'     => '/var/www/error <x>',
				)
			)
		);

		$this->assertSame( 'unknown', $report['status'] );
		$this->assertSame( 'unknown', $report['settle_outcome'] );
		$this->assertSame( array( 'code' => 'unknown' ), $report['error'] );
		foreach ( $report['phases'] as $phase ) {
			$this->assertSame( UnavailableReason::NOT_RECORDED, $phase['options']['reason'] );
		}
	}

	/**
	 * Null and empty outcome/error stay null.
	 */
	public function test_null_values() {
		$report = ( new AnalysisReadModel() )->report(
			self::row(
				array(
					'settle_outcome' => null,
					'error_code'     => '',
				)
			)
		);

		$this->assertNull( $report['settle_outcome'] );
		$this->assertNull( $report['error'] );
	}

	/**
	 * Unavailable phase reason by status/error/outcome.
	 *
	 * @return array
	 */
	public function provide_reasons() {
		return array(
			'captured'                     => array( 'captured', null, null, UnavailableReason::UPDATE_IN_PROGRESS ),
			'awaiting'                     => array( 'awaiting_settle', null, null, UnavailableReason::AWAITING_SETTLE ),
			'expired'                      => array( 'completed', null, 'expired', UnavailableReason::SETTLE_EXPIRED ),
			'completed without diff'       => array( 'completed', null, 'admin_shutdown', UnavailableReason::NOT_RECORDED ),
			'WordPress error'              => array( 'failed', 'download_failed', 'not_applicable', UnavailableReason::UPDATE_FAILED ),
			'not completed'                => array( 'failed', 'update_not_completed', 'not_applicable', UnavailableReason::UPDATE_FAILED ),
			'analysis error'               => array( 'failed', 'analysis_error', 'next_update', UnavailableReason::ANALYSIS_FAILED ),
			'corrupt snapshot'             => array( 'failed', 'snapshot_corrupt', 'admin_shutdown', UnavailableReason::ANALYSIS_FAILED ),
			'incompatible'                 => array( 'incompatible', 'fingerprint_context_changed', 'admin_shutdown', UnavailableReason::FINGERPRINT_CONTEXT_CHANGED ),
			'abandoned (stale)'            => array( 'abandoned', 'stale', 'not_applicable', UnavailableReason::ANALYSIS_ABANDONED ),
			'abandoned (another update)'   => array( 'abandoned', 'another_update_started', 'not_applicable', UnavailableReason::ANALYSIS_ABANDONED ),
			'unknown status'               => array( 'mystery', null, null, UnavailableReason::NOT_RECORDED ),
			'unknown status with an error' => array( 'mystery', 'download_failed', null, UnavailableReason::NOT_RECORDED ),
		);
	}

	/**
	 * Reasons.
	 *
	 * @dataProvider provide_reasons
	 * @param string      $status     Stored status.
	 * @param string|null $error_code Stored error code.
	 * @param string|null $outcome    Stored settle outcome.
	 * @param string      $reason     Expected reason.
	 */
	public function test_unavailable_reasons( $status, $error_code, $outcome, $reason ) {
		$report = ( new AnalysisReadModel() )->report(
			self::row(
				array(
					'status'         => $status,
					'error_code'     => $error_code,
					'settle_outcome' => $outcome,
				)
			)
		);

		$this->assertSame(
			array(
				'during_update' => array(
					'available'   => false,
					'association' => 'update_request',
					'reason'      => $reason,
				),
				'post_update'   => array(
					'available'   => false,
					'association' => 'observed_after_update',
					'reason'      => $reason,
				),
				'final'         => array(
					'available'   => false,
					'association' => 'net_across_phases',
					'reason'      => $reason,
				),
			),
			self::options( $report )
		);
	}

	/**
	 * An available Cron phase is the decoded Cron diff: no arguments, fingerprints or context.
	 */
	public function test_cron_available_phase() {
		$json   = self::cron_diff_json();
		$report = ( new AnalysisReadModel() )->report( self::row( array( 'cron_post_update_diff' => $json ) ) );
		$diff   = ( new CronDiffCodec() )->decode( $json );

		$this->assertSame(
			array(
				'available'   => true,
				'association' => 'observed_after_update',
				'summary'     => $diff['summary'],
				'added'       => $diff['added'],
				'removed'     => $diff['removed'],
				'rescheduled' => $diff['rescheduled'],
				'changed'     => $diff['changed'],
			),
			$report['phases']['post_update']['cron']
		);
		$this->assertSame( array( 'acme_once' ), array_column( $report['phases']['post_update']['cron']['added'], 'hook' ) );
		$this->assertSame( 3600, $report['phases']['post_update']['cron']['rescheduled'][0]['timestamp_delta'] );

		$output = (string) json_encode( $report ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
		$md5    = md5( serialize( array( 'token' => self::FAKE_SECRET ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Core's unkeyed event key.
		foreach ( array( self::FAKE_SECRET, 'hooks.example.test', 'token', 'fingerprint', 'cron-args', '"schema"', $md5 ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $output );
		}
	}

	/**
	 * Stored Cron reasons are passed through; unknown values become `unknown`.
	 *
	 * @return array
	 */
	public function provide_cron_reasons() {
		$cases = array();
		foreach ( AnalysisReadModel::CRON_REASONS as $reason ) {
			$cases[ $reason ] = array( $reason, $reason );
		}
		$cases['unknown value'] = array( 'Malformed: <b>raw</b>', 'unknown' );

		return $cases;
	}

	/**
	 * Cron reasons.
	 *
	 * @dataProvider provide_cron_reasons
	 * @param string $stored   Stored reason.
	 * @param string $expected API reason.
	 */
	public function test_cron_stored_reasons( $stored, $expected ) {
		$report = ( new AnalysisReadModel() )->report( self::row( array( 'cron_final_reason' => $stored ) ) );

		$this->assertSame(
			array(
				'available'   => false,
				'association' => 'net_across_phases',
				'reason'      => $expected,
			),
			$report['phases']['final']['cron']
		);
	}

	/**
	 * The pass-through list covers every CronPhaseReason constant.
	 */
	public function test_cron_reason_list_is_complete() {
		$constants = ( new ReflectionClass( CronPhaseReason::class ) )->getConstants();

		$this->assertEqualsCanonicalizing( array_values( $constants ), AnalysisReadModel::CRON_REASONS );
	}

	/**
	 * Cron phases without diff or reason are pending (explained by the status) or not recorded.
	 *
	 * @return array
	 */
	public function provide_pending_cron() {
		return array(
			'captured' => array( 'captured', array( 'update_in_progress', 'update_in_progress', 'update_in_progress' ) ),
			'awaiting' => array( 'awaiting_settle', array( 'not_recorded', 'awaiting_settle', 'awaiting_settle' ) ),
			'pre-Cron' => array( 'completed', array( 'not_recorded', 'not_recorded', 'not_recorded' ) ),
			'unknown'  => array( 'mystery', array( 'not_recorded', 'not_recorded', 'not_recorded' ) ),
		);
	}

	/**
	 * Pending Cron phases.
	 *
	 * @dataProvider provide_pending_cron
	 * @param string   $status  Stored status.
	 * @param string[] $reasons Expected reasons per phase.
	 */
	public function test_cron_without_data( $status, array $reasons ) {
		$report = ( new AnalysisReadModel() )->report( self::row( array( 'status' => $status ) ) );

		$this->assertSame( $reasons, array_values( array_column( self::cron( $report ), 'reason' ) ) );
	}

	/**
	 * A corrupt Cron diff only makes that Cron phase unavailable.
	 */
	public function test_corrupt_cron_diff_is_isolated() {
		$report = ( new AnalysisReadModel() )->report(
			self::row(
				array(
					'cron_during_update_diff' => self::cron_diff_json(),
					'cron_post_update_diff'   => '{"schema":1,"added":"' . self::FAKE_SECRET . '"',
					'cron_final_diff'         => self::cron_diff_json(),
				)
			)
		);

		$this->assertTrue( $report['phases']['during_update']['cron']['available'] );
		$this->assertTrue( $report['phases']['final']['cron']['available'] );
		$this->assertSame( UnavailableReason::DATA_CORRUPT, $report['phases']['post_update']['cron']['reason'] );
		$this->assertSame( UnavailableReason::SETTLE_EXPIRED, $report['phases']['post_update']['options']['reason'], 'Options are unaffected.' );
		$this->assertStringNotContainsString( self::FAKE_SECRET, (string) json_encode( $report ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
	}

	/**
	 * Cron snapshot columns never reach a report, whatever the row contains.
	 */
	public function test_cron_snapshots_are_never_exposed() {
		$report = ( new AnalysisReadModel() )->report(
			self::row(
				array(
					'cron_before_snapshot'    => '{"schema":1,"fingerprint_context":"cron-args-hmac-sha256-v1:' . self::FAKE_SECRET . '"}',
					'cron_immediate_snapshot' => self::FAKE_SECRET,
				)
			)
		);

		$this->assertStringNotContainsString( self::FAKE_SECRET, (string) json_encode( $report ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
	}

	/**
	 * An available Action Scheduler phase is the decoded diff: hooks, groups,
	 * statuses, times and normalized schedules; no arguments, fingerprints,
	 * context or IDs.
	 */
	public function test_action_scheduler_available_phase() {
		$json   = self::action_scheduler_diff_json();
		$report = ( new AnalysisReadModel() )->report( self::row( array( 'action_scheduler_post_update_diff' => $json ) ) );
		$diff   = ( new ActionSchedulerDiffCodec() )->decode( $json );
		$phase  = $report['phases']['post_update']['action_scheduler'];

		$this->assertSame(
			array(
				'available'   => true,
				'association' => 'observed_after_update',
				'summary'     => $diff['summary'],
				'added'       => $diff['added'],
				'removed'     => $diff['removed'],
				'rescheduled' => $diff['rescheduled'],
				'changed'     => $diff['changed'],
			),
			$phase
		);
		$this->assertSame( array( 'fetch_patterns', 'wc_run_on_admin_updated' ), array_column( $phase['added'], 'hook' ) );
		$this->assertSame( array( 'wpforms_admin_notifications_update' ), array_column( $phase['removed'], 'hook' ) );
		$this->assertSame( array( 'action_scheduler/migration_hook' ), array_column( $phase['rescheduled'], 'hook' ) );
		$this->assertSame( 84, $phase['rescheduled'][0]['timestamp_delta'] );
		$this->assertSame( array( 'hook', 'group', 'status', 'timestamp', 'schedule_type', 'interval', 'cron_expression', 'is_recurring' ), array_keys( $phase['added'][0] ) );
		$this->assertSame( 'in-progress', $phase['added'][1]['status'] );
		$this->assertSame( 'async', $phase['added'][1]['schedule_type'] );
		$this->assertSame( 'woocommerce-remote-inbox-engine', $phase['added'][1]['group'] );
		$this->assertSame( array( 'interval', 'cron', 86400, null, null, '0 */6 * * *' ), array( $phase['changed'][0]['before_schedule_type'], $phase['changed'][0]['after_schedule_type'], $phase['changed'][0]['before_interval'], $phase['changed'][0]['after_interval'], $phase['changed'][0]['before_cron_expression'], $phase['changed'][0]['after_cron_expression'] ) );
		$this->assertSame( ActionSchedulerDiffCodec::SUMMARY_KEYS, array_keys( $phase['summary'] ) );
		$this->assertSame( 3, $phase['summary']['before_action_count'] );
		$this->assertSame( 4, $phase['summary']['after_action_count'] );
		$this->assertSame( UnavailableReason::SETTLE_EXPIRED, $report['phases']['post_update']['options']['reason'], 'Options are unaffected.' );
		$this->assertSame( UnavailableReason::NOT_RECORDED, $report['phases']['post_update']['cron']['reason'], 'Cron is unaffected.' );

		$output = (string) json_encode( $report ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
		foreach ( array( self::AS_SECRET, 'hooks.example.test', 'token', '1234', 'fingerprint', 'as-args-hmac', '"schema"', 'action_id', 'claim', 'O:', 'ActionScheduler_' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $output );
		}
	}

	/**
	 * Stored Action Scheduler reasons are passed through; unknown values become `unknown`.
	 *
	 * @return array
	 */
	public function provide_action_scheduler_reasons() {
		$cases = array();
		foreach ( AnalysisReadModel::ACTION_SCHEDULER_REASONS as $reason ) {
			$cases[ $reason ] = array( $reason, $reason );
		}
		$cases['unknown value']  = array( 'Unsupported store: <b>My_Store</b>', 'unknown' );
		$cases['Cron-only code'] = array( 'malformed_cron_state', 'unknown' );

		return $cases;
	}

	/**
	 * Action Scheduler reasons.
	 *
	 * @dataProvider provide_action_scheduler_reasons
	 * @param string $stored   Stored reason.
	 * @param string $expected API reason.
	 */
	public function test_action_scheduler_stored_reasons( $stored, $expected ) {
		$report = ( new AnalysisReadModel() )->report( self::row( array( 'action_scheduler_during_update_reason' => $stored ) ) );

		$this->assertSame(
			array(
				'available'   => false,
				'association' => 'update_request',
				'reason'      => $expected,
			),
			$report['phases']['during_update']['action_scheduler']
		);
		$this->assertSame( 'not_recorded', $report['phases']['during_update']['cron']['reason'], 'Cron is unaffected.' );
	}

	/**
	 * The pass-through list is exactly ActionSchedulerPhaseReason::ALL.
	 */
	public function test_action_scheduler_reason_list_is_complete() {
		$this->assertSame( ActionSchedulerPhaseReason::ALL, AnalysisReadModel::ACTION_SCHEDULER_REASONS );
	}

	/**
	 * Action Scheduler phases without diff or reason are pending (explained by the status) or not recorded.
	 *
	 * @dataProvider provide_pending_cron
	 * @param string   $status  Stored status.
	 * @param string[] $reasons Expected reasons per phase.
	 */
	public function test_action_scheduler_without_data( $status, array $reasons ) {
		$report = ( new AnalysisReadModel() )->report( self::row( array( 'status' => $status ) ) );

		$this->assertSame( $reasons, array_column( self::action_scheduler( $report ), 'reason' ) );
	}

	/**
	 * A diff wins over a stored reason (cannot happen in the lifecycle, but the diff is the data).
	 */
	public function test_action_scheduler_diff_wins_over_reason() {
		$report = ( new AnalysisReadModel() )->report(
			self::row(
				array(
					'action_scheduler_final_diff'   => self::action_scheduler_diff_json(),
					'action_scheduler_final_reason' => 'not_installed',
				)
			)
		);

		$this->assertTrue( $report['phases']['final']['action_scheduler']['available'] );
	}

	/**
	 * A corrupt Action Scheduler diff only makes that Action Scheduler phase unavailable.
	 */
	public function test_corrupt_action_scheduler_diff_is_isolated() {
		$json   = self::action_scheduler_diff_json();
		$report = ( new AnalysisReadModel() )->report(
			self::row(
				array(
					'options_final_diff'                  => null,
					'cron_final_diff'                     => self::cron_diff_json(),
					'action_scheduler_during_update_diff' => $json,
					'action_scheduler_post_update_diff'   => $json,
					'action_scheduler_final_diff'         => str_replace( '"hook":"fetch_patterns"', '"hook":"' . self::AS_SECRET . '","args":"x"', $json ),
				)
			)
		);

		$this->assertTrue( $report['phases']['during_update']['action_scheduler']['available'] );
		$this->assertTrue( $report['phases']['post_update']['action_scheduler']['available'] );
		$this->assertSame(
			array(
				'available'   => false,
				'association' => 'net_across_phases',
				'reason'      => UnavailableReason::DATA_CORRUPT,
			),
			$report['phases']['final']['action_scheduler']
		);
		$this->assertTrue( $report['phases']['final']['cron']['available'], 'Cron is unaffected.' );
		$this->assertSame( UnavailableReason::SETTLE_EXPIRED, $report['phases']['final']['options']['reason'], 'Options are unaffected.' );
		$this->assertStringNotContainsString( self::AS_SECRET, (string) json_encode( $report ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
	}

	/**
	 * A diff stored by another signal's codec is not accepted as an Action Scheduler diff.
	 */
	public function test_cron_diff_is_not_an_action_scheduler_diff() {
		$report = ( new AnalysisReadModel() )->report( self::row( array( 'action_scheduler_final_diff' => self::cron_diff_json() ) ) );

		$this->assertSame( UnavailableReason::DATA_CORRUPT, $report['phases']['final']['action_scheduler']['reason'] );
	}

	/**
	 * Action Scheduler snapshot columns never reach a report or history row, whatever the row contains.
	 */
	public function test_action_scheduler_snapshots_are_never_exposed() {
		$row = self::row(
			array(
				'action_scheduler_before_snapshot'    => '{"schema":1,"fingerprint_context":"as-args-hmac-sha256-v1:' . self::AS_SECRET . '"}',
				'action_scheduler_immediate_snapshot' => self::AS_SECRET,
			)
		);

		foreach ( array( ( new AnalysisReadModel() )->report( $row ), ( new AnalysisReadModel() )->history_item( $row ) ) as $output ) {
			$this->assertStringNotContainsString( self::AS_SECRET, (string) json_encode( $output ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
		}
	}

	/**
	 * History flags of the Action Scheduler signal are independent of the other signals.
	 */
	public function test_history_action_scheduler_flags() {
		$item = ( new AnalysisReadModel() )->history_item(
			self::row(
				array(
					'has_options_post_update_diff'         => '1',
					'has_changes_options_post_update_diff' => '0',
					'has_cron_post_update_diff'            => '1',
					'has_changes_cron_post_update_diff'    => '0',
					'has_action_scheduler_post_update_diff' => '1',
					'has_changes_action_scheduler_post_update_diff' => '1',
					'has_action_scheduler_final_diff'      => '0',
					'has_changes_action_scheduler_final_diff' => '1',
					'has_action_scheduler_during_update_diff' => '1',
					'has_changes_action_scheduler_during_update_diff' => '0',
				)
			)
		);

		$this->assertSame(
			array(
				'recorded'    => true,
				'has_changes' => true,
			),
			$item['phases']['post_update']['action_scheduler']
		);
		$this->assertFalse( $item['phases']['post_update']['cron']['has_changes'] );
		$this->assertSame(
			array(
				'recorded'    => false,
				'has_changes' => null,
			),
			$item['phases']['final']['action_scheduler']
		);
		$this->assertSame(
			array(
				'recorded'    => true,
				'has_changes' => false,
			),
			$item['phases']['during_update']['action_scheduler']
		);
	}

	/**
	 * Malformed stored diffs become `data_corrupt`, never decoded data.
	 *
	 * @return array
	 */
	public function provide_corrupt_diffs() {
		return array(
			'not JSON'       => array( 'not json' ),
			'empty'          => array( '' ),
			'JSON scalar'    => array( '42' ),
			'future schema'  => array( '{"schema":2,"added":[],"removed":[],"changed":[],"summary":{}}' ),
			'missing fields' => array( '{"schema":1,"added":[]}' ),
		);
	}

	/**
	 * Corrupt diffs.
	 *
	 * @dataProvider provide_corrupt_diffs
	 * @param string $json Stored JSON.
	 */
	public function test_corrupt_diff( $json ) {
		$report = ( new AnalysisReadModel() )->report( self::row( array( 'options_during_update_diff' => $json ) ) );

		$this->assertSame(
			array(
				'available'   => false,
				'association' => 'update_request',
				'reason'      => UnavailableReason::DATA_CORRUPT,
			),
			$report['phases']['during_update']['options']
		);
	}
}
