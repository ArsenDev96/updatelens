<?php
/**
 * Tests for the analysis read model.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use UpdateLens\Report\AnalysisReadModel;
use UpdateLens\Report\UnavailableReason;

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
	 * Only whitelisted fields, with JSON types; database strings become ints/bools.
	 */
	public function test_history_item_fields_and_types() {
		$item = ( new AnalysisReadModel() )->history_item( self::row( array( 'has_options_during_update_diff' => '1' ) ) );

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
			),
			$item
		);
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

		$this->assertSame( array( 'id', 'plugin', 'status', 'settle_outcome', 'timestamps', 'phases', 'error' ), array_keys( $report ) );
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
			$this->assertSame( UnavailableReason::NOT_RECORDED, $phase['reason'] );
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
			$report['phases']
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
			$report['phases']['during_update']
		);
	}
}
