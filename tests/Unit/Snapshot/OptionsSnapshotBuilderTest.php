<?php
/**
 * Tests for OptionsSnapshotBuilder and the snapshot it produces.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Snapshot;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UpdateLens\Snapshot\AutoloadPolicy;
use UpdateLens\Snapshot\OptionNoiseFilter;
use UpdateLens\Snapshot\OptionRecord;
use UpdateLens\Snapshot\OptionsSnapshot;
use UpdateLens\Snapshot\OptionsSnapshotBuilder;
use UpdateLens\Snapshot\OptionValueHasher;

/**
 * Raw rows → safe snapshot.
 */
final class OptionsSnapshotBuilderTest extends TestCase {

	const SECRET = 'test-site-secret';

	/**
	 * Obviously fake credential used in privacy fixtures.
	 */
	const FAKE_SECRET = 'sk_test_SUPER_SECRET_EXAMPLE';

	/**
	 * Builder with a fixed test secret and WordPress 6.6+ autoload values.
	 *
	 * @param string $secret Hasher secret.
	 * @return OptionsSnapshotBuilder
	 */
	private function builder( $secret = self::SECRET ) {
		return new OptionsSnapshotBuilder(
			new OptionValueHasher( $secret ),
			new OptionNoiseFilter(),
			new AutoloadPolicy( array( 'yes', 'on', 'auto-on', 'auto' ) )
		);
	}

	/**
	 * Raw row as returned by $wpdb->get_results( ..., ARRAY_A ).
	 *
	 * @param string $name     Option name.
	 * @param string $value    Raw value.
	 * @param string $autoload Raw autoload value.
	 * @return array{option_name: string, option_value: string, autoload: string}
	 */
	private function row( $name, $value, $autoload ) {
		return array(
			'option_name'  => $name,
			'option_value' => $value,
			'autoload'     => $autoload,
		);
	}

	/**
	 * Fixture: included options of known size plus noise.
	 *
	 * @return array<int, array{option_name: string, option_value: string, autoload: string}>
	 */
	private function fixture_rows() {
		return array(
			$this->row( 'siteurl', 'https://example.test', 'on' ),                                          // 20 bytes, autoloaded.
			$this->row( 'my_plugin_settings', 'a:1:{s:7:"api_key";s:28:"' . self::FAKE_SECRET . '";}', 'auto-off' ), // 56 bytes.
			$this->row( 'legacy_option', 'abc', 'yes' ),                                                    // 3 bytes, autoloaded.
			$this->row( 'large_option', str_repeat( 'x', 1000 ), 'no' ),                                     // 1000 bytes.
			$this->row( 'empty_option', '', 'auto' ),                                                        // 0 bytes, autoloaded.
			$this->row( '_transient_feed_1', str_repeat( 't', 500 ), 'off' ),
			$this->row( '_transient_timeout_feed_1', '1700000000', 'off' ),
			$this->row( '_site_transient_update_core', self::FAKE_SECRET, 'off' ),
			$this->row( 'updatelens_state', 'internal', 'off' ),
			$this->row( 'cron', 'a:0:{}', 'on' ),
		);
	}

	/**
	 * Noise is dropped; persistent options are kept.
	 */
	public function test_noise_rows_are_excluded() {
		$snapshot = $this->builder()->build( $this->fixture_rows() );

		$this->assertSame(
			array( 'empty_option', 'large_option', 'legacy_option', 'my_plugin_settings', 'siteurl' ),
			array_keys( $snapshot->get_records() )
		);
		$this->assertNull( $snapshot->get_record( 'cron' ) );
		$this->assertNull( $snapshot->get_record( 'updatelens_state' ) );
		$this->assertNull( $snapshot->get_record( '_transient_feed_1' ) );
	}

	/**
	 * The three hosting update-check records are dropped; other `wpe_*` options are kept.
	 */
	public function test_hosting_update_check_records_are_excluded() {
		$snapshot = $this->builder()->build(
			array(
				$this->row( 'wpe_site_transient_update_core', str_repeat( 'c', 1000 ), 'off' ),
				$this->row( 'wpe_site_transient_update_plugins', str_repeat( 'p', 3000 ), 'off' ),
				$this->row( 'wpe_site_transient_update_themes', str_repeat( 't', 2000 ), 'on' ),
				$this->row( 'wpe_update_source', 'mirror', 'on' ),                                     // 6 bytes, autoloaded.
				$this->row( 'wpe_site_transient_update_translations', 'abcd', 'off' ),                 // 4 bytes.
			)
		);

		$this->assertSame(
			array( 'wpe_site_transient_update_translations', 'wpe_update_source' ),
			array_keys( $snapshot->get_records() )
		);
		$this->assertSame(
			array(
				'option_count'     => 2,
				'autoloaded_count' => 1,
				'total_bytes'      => 10,
				'autoloaded_bytes' => 6,
			),
			$snapshot->get_summary()->to_array()
		);
	}

	/**
	 * Records carry the expected metadata.
	 */
	public function test_record_metadata() {
		$record = $this->builder()->build( $this->fixture_rows() )->get_record( 'siteurl' );

		$this->assertInstanceOf( OptionRecord::class, $record );
		$this->assertSame(
			array(
				'name'          => 'siteurl',
				'fingerprint'   => ( new OptionValueHasher( self::SECRET ) )->fingerprint( 'https://example.test' ),
				'size'          => 20,
				'autoload'      => 'on',
				'is_autoloaded' => true,
			),
			$record->to_array()
		);
	}

	/**
	 * Raw autoload values are kept and normalized.
	 */
	public function test_autoload_is_kept_and_normalized() {
		$snapshot = $this->builder()->build( $this->fixture_rows() );

		$this->assertSame( 'auto-off', $snapshot->get_record( 'my_plugin_settings' )->get_autoload() );
		$this->assertFalse( $snapshot->get_record( 'my_plugin_settings' )->is_autoloaded() );
		$this->assertSame( 'yes', $snapshot->get_record( 'legacy_option' )->get_autoload() );
		$this->assertTrue( $snapshot->get_record( 'legacy_option' )->is_autoloaded() );
		$this->assertTrue( $snapshot->get_record( 'empty_option' )->is_autoloaded() );
		$this->assertFalse( $snapshot->get_record( 'large_option' )->is_autoloaded() );
	}

	/**
	 * Size is the byte length of the raw value, not the character count.
	 */
	public function test_size_is_byte_length() {
		$snapshot = $this->builder()->build(
			array(
				$this->row( 'ascii', 'hello', 'no' ),
				$this->row( 'two_byte', "h\u{00E9}llo", 'no' ),       // 5 characters, 6 bytes.
				$this->row( 'four_byte', "\u{1F600}", 'no' ),          // 1 character, 4 bytes.
				$this->row( 'serialized', 's:6:"h' . "\u{00E9}" . 'llo";', 'no' ), // Serialized lengths are bytes too.
				$this->row( 'empty', '', 'no' ),
			)
		);

		$this->assertSame( 5, $snapshot->get_record( 'ascii' )->get_size() );
		$this->assertSame( 6, $snapshot->get_record( 'two_byte' )->get_size() );
		$this->assertSame( 4, $snapshot->get_record( 'four_byte' )->get_size() );
		$this->assertSame( 13, $snapshot->get_record( 'serialized' )->get_size() );
		$this->assertSame( 0, $snapshot->get_record( 'empty' )->get_size() );
	}

	/**
	 * Summary totals for the fixture.
	 */
	public function test_summary() {
		$summary = $this->builder()->build( $this->fixture_rows() )->get_summary();

		$this->assertSame(
			array(
				'option_count'     => 5,
				'autoloaded_count' => 3,
				'total_bytes'      => 20 + 56 + 3 + 1000 + 0,
				'autoloaded_bytes' => 20 + 3 + 0,
			),
			$summary->to_array()
		);
		$this->assertSame( 5, $summary->get_option_count() );
		$this->assertSame( 3, $summary->get_autoloaded_count() );
		$this->assertSame( 1079, $summary->get_total_bytes() );
		$this->assertSame( 23, $summary->get_autoloaded_bytes() );
	}

	/**
	 * An empty table gives an empty snapshot with zero totals.
	 */
	public function test_empty_input() {
		$snapshot = $this->builder()->build( array() );

		$this->assertSame( array(), $snapshot->get_records() );
		$this->assertSame(
			array(
				'fingerprint_context' => ( new OptionValueHasher( self::SECRET ) )->get_context(),
				'options'             => array(),
				'summary'             => array(
					'option_count'     => 0,
					'autoloaded_count' => 0,
					'total_bytes'      => 0,
					'autoloaded_bytes' => 0,
				),
			),
			$snapshot->to_array()
		);
	}

	/**
	 * Identical input gives equal snapshots.
	 */
	public function test_identical_input_gives_equal_snapshots() {
		$first  = $this->builder()->build( $this->fixture_rows() );
		$second = $this->builder()->build( $this->fixture_rows() );

		$this->assertSame( $first->to_array(), $second->to_array() );
		$this->assertEquals( $first, $second );
	}

	/**
	 * Row order does not affect the snapshot; records are sorted by name.
	 */
	public function test_output_order_is_independent_of_input_order() {
		$rows     = $this->fixture_rows();
		$expected = $this->builder()->build( $rows )->to_array();

		$this->assertSame( $expected, $this->builder()->build( array_reverse( $rows ) )->to_array() );

		// Byte-wise order, not database collation order (uppercase sorts before lowercase).
		$snapshot = $this->builder()->build(
			array(
				$this->row( 'b', '1', 'no' ),
				$this->row( 'a', '1', 'no' ),
				$this->row( 'B', '1', 'no' ),
				$this->row( 'a_b', '1', 'no' ),
			)
		);
		$this->assertSame( array( 'B', 'a', 'a_b', 'b' ), array_keys( $snapshot->get_records() ) );
	}

	/**
	 * Rows are accepted from any iterable, e.g. the provider's generator.
	 */
	public function test_accepts_generator_input() {
		$rows      = $this->fixture_rows();
		$generator = ( static function () use ( $rows ) {
			foreach ( $rows as $row ) {
				yield $row;
			}
		} )();

		$this->assertSame(
			$this->builder()->build( $rows )->to_array(),
			$this->builder()->build( $generator )->to_array()
		);
	}

	/**
	 * A changed value changes only that option's fingerprint and size.
	 */
	public function test_changed_value_changes_fingerprint() {
		$rows    = $this->fixture_rows();
		$before  = $this->builder()->build( $rows );
		$rows[2] = $this->row( 'legacy_option', 'abcd', 'yes' );
		$after   = $this->builder()->build( $rows );

		$this->assertNotSame( $before->get_record( 'legacy_option' )->get_fingerprint(), $after->get_record( 'legacy_option' )->get_fingerprint() );
		$this->assertSame( 4, $after->get_record( 'legacy_option' )->get_size() );
		$this->assertSame( $before->get_record( 'siteurl' )->to_array(), $after->get_record( 'siteurl' )->to_array() );
	}

	/**
	 * Different site secrets give different fingerprints for the same database.
	 */
	public function test_site_secret_changes_fingerprints() {
		$a = $this->builder( 'site-a' )->build( $this->fixture_rows() );
		$b = $this->builder( 'site-b' )->build( $this->fixture_rows() );

		$this->assertNotSame(
			$a->get_record( 'siteurl' )->get_fingerprint(),
			$b->get_record( 'siteurl' )->get_fingerprint()
		);
		$this->assertSame( $a->get_summary()->to_array(), $b->get_summary()->to_array() );
	}

	/**
	 * No raw value — in particular the fake credential — appears in any output form.
	 */
	public function test_raw_values_never_appear_in_output() {
		$snapshot = $this->builder()->build( $this->fixture_rows() );

		// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Inspecting every output form.
		$outputs = array(
			'serialize( snapshot )' => serialize( $snapshot ),
			'to_array()'            => var_export( $snapshot->to_array(), true ),
			'json'                  => json_encode( $snapshot->to_array() ),
			'print_r( snapshot )'   => print_r( $snapshot, true ),
			'summary'               => var_export( $snapshot->get_summary()->to_array(), true ),
			'serialize( summary )'  => serialize( $snapshot->get_summary() ),
			'serialize( record )'   => serialize( $snapshot->get_record( 'my_plugin_settings' ) ),
			'record to_array()'     => var_export( $snapshot->get_record( 'my_plugin_settings' )->to_array(), true ),
		);
		// phpcs:enable

		foreach ( $outputs as $label => $output ) {
			$this->assertStringNotContainsString( self::FAKE_SECRET, $output, $label );
			$this->assertStringNotContainsString( 'api_key', $output, $label );
			$this->assertStringNotContainsString( 'https://example.test', $output, $label );
			$this->assertStringNotContainsString( self::SECRET, $output, $label );
		}
	}

	/**
	 * Records expose exactly the safe metadata fields.
	 */
	public function test_records_expose_only_safe_fields() {
		foreach ( $this->builder()->build( $this->fixture_rows() )->get_records() as $record ) {
			$this->assertSame(
				array( 'name', 'fingerprint', 'size', 'autoload', 'is_autoloaded' ),
				array_keys( $record->to_array() )
			);
		}
	}

	/**
	 * A snapshot rejects duplicate option names instead of silently dropping one.
	 */
	public function test_duplicate_names_are_rejected() {
		$this->expectException( InvalidArgumentException::class );

		new OptionsSnapshot(
			array(
				new OptionRecord( 'same', str_repeat( 'a', 64 ), 1, 'no', false ),
				new OptionRecord( 'same', str_repeat( 'b', 64 ), 1, 'no', false ),
			),
			'test-context'
		);
	}

	/**
	 * A snapshot requires a fingerprint context.
	 */
	public function test_empty_fingerprint_context_is_rejected() {
		$this->expectException( InvalidArgumentException::class );

		new OptionsSnapshot( array(), '' );
	}

	/**
	 * The snapshot carries the hasher's fingerprint context.
	 */
	public function test_snapshot_carries_fingerprint_context() {
		$snapshot = $this->builder()->build( $this->fixture_rows() );
		$expected = ( new OptionValueHasher( self::SECRET ) )->get_context();

		$this->assertSame( $expected, $snapshot->get_fingerprint_context() );
		$this->assertSame( $expected, $snapshot->to_array()['fingerprint_context'] );
		$this->assertSame( $expected, $this->builder()->build( array() )->get_fingerprint_context(), 'Independent of the option data.' );
		$this->assertNotSame( $expected, $this->builder( 'other-site' )->build( $this->fixture_rows() )->get_fingerprint_context() );
	}
}
