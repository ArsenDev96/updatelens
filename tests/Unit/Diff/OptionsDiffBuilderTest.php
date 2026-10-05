<?php
/**
 * Tests for OptionsDiffBuilder and the diff it produces.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use UpdateLens\Diff\ChangedOption;
use UpdateLens\Diff\IncompatibleSnapshotsException;
use UpdateLens\Diff\OptionsDiff;
use UpdateLens\Diff\OptionsDiffBuilder;
use UpdateLens\Diff\OptionState;
use UpdateLens\Snapshot\AutoloadPolicy;
use UpdateLens\Snapshot\OptionNoiseFilter;
use UpdateLens\Snapshot\OptionRecord;
use UpdateLens\Snapshot\OptionsSnapshot;
use UpdateLens\Snapshot\OptionsSnapshotBuilder;
use UpdateLens\Snapshot\OptionValueHasher;

/**
 * Before snapshot + after snapshot → safe diff.
 */
final class OptionsDiffBuilderTest extends TestCase {

	const SECRET = 'test-site-secret';

	/**
	 * Obviously fake credential used in privacy fixtures.
	 */
	const FAKE_SECRET = 'sk_test_UPDATE_LENS_DIFF_SECRET';

	/**
	 * Snapshot from `name => [ raw value, raw autoload ]` rows.
	 *
	 * Uses the WordPress 6.6+ autoload values explicitly, so tests do not depend
	 * on the running WordPress version.
	 *
	 * @param array<string, array{string, string}> $options Options.
	 * @param string                               $secret  Hasher secret.
	 * @return OptionsSnapshot
	 */
	private function snapshot( array $options, $secret = self::SECRET ) {
		$rows = array();
		foreach ( $options as $name => $option ) {
			$rows[] = array(
				'option_name'  => (string) $name,
				'option_value' => $option[0],
				'autoload'     => $option[1],
			);
		}

		$builder = new OptionsSnapshotBuilder(
			new OptionValueHasher( $secret ),
			new OptionNoiseFilter(),
			new AutoloadPolicy( array( 'yes', 'on', 'auto-on', 'auto' ) )
		);

		return $builder->build( $rows );
	}

	/**
	 * Diff two option sets.
	 *
	 * @param array<string, array{string, string}> $before Options before.
	 * @param array<string, array{string, string}> $after  Options after.
	 * @return OptionsDiff
	 */
	private function diff( array $before, array $after ) {
		return ( new OptionsDiffBuilder() )->build( $this->snapshot( $before ), $this->snapshot( $after ) );
	}

	/**
	 * Known fixture, before.
	 *
	 * Totals: 5 options, 24 bytes; autoloaded: 4 options, 14 bytes.
	 *
	 * @return array<string, array{string, string}>
	 */
	private function fixture_before() {
		return array(
			'alpha'   => array( 'aaaa', 'on' ),         // 4 bytes, autoloaded.
			'bravo'   => array( 'bbbbbbbbbb', 'off' ),  // 10 bytes.
			'charlie' => array( 'cc', 'yes' ),          // 2 bytes, autoloaded.
			'delta'   => array( 'ddddd', 'auto' ),      // 5 bytes, autoloaded; removed.
			'echo'    => array( 'eee', 'on' ),          // 3 bytes, autoloaded; unchanged.
		);
	}

	/**
	 * Known fixture, after.
	 *
	 * Totals: 6 options, 30 bytes; autoloaded: 5 options, 24 bytes.
	 *
	 * @return array<string, array{string, string}>
	 */
	private function fixture_after() {
		return array(
			'alpha'   => array( 'aaaaaaaa', 'on' ),     // Value + size (+4).
			'bravo'   => array( 'bbbbbbbbbb', 'on' ),   // Autoload off → on: raw and behavior.
			'charlie' => array( 'cc', 'auto-on' ),      // Autoload yes → auto-on: raw only.
			'echo'    => array( 'eee', 'on' ),          // Unchanged.
			'foxtrot' => array( 'ffffff', 'auto-off' ), // Added, 6 bytes, not autoloaded.
			'golf'    => array( 'g', 'on' ),            // Added, 1 byte, autoloaded.
		);
	}

	/**
	 * Identical snapshots give an empty diff with zero deltas.
	 */
	public function test_no_change() {
		$diff = $this->diff( $this->fixture_before(), $this->fixture_before() );

		$this->assertFalse( $diff->has_changes() );
		$this->assertSame( array(), $diff->get_added() );
		$this->assertSame( array(), $diff->get_removed() );
		$this->assertSame( array(), $diff->get_changed() );
		$this->assertSame(
			array(
				'before_option_count'             => 5,
				'after_option_count'              => 5,
				'option_count_delta'              => 0,
				'before_total_bytes'              => 24,
				'after_total_bytes'               => 24,
				'total_bytes_delta'               => 0,
				'before_autoloaded_count'         => 4,
				'after_autoloaded_count'          => 4,
				'autoloaded_count_delta'          => 0,
				'before_autoloaded_bytes'         => 14,
				'after_autoloaded_bytes'          => 14,
				'autoloaded_bytes_delta'          => 0,
				'added_count'                     => 0,
				'removed_count'                   => 0,
				'changed_count'                   => 0,
				'value_changed_count'             => 0,
				'autoload_value_changed_count'    => 0,
				'autoload_behavior_changed_count' => 0,
			),
			$diff->get_summary()->to_array()
		);
	}

	/**
	 * Two empty snapshots give an empty diff.
	 */
	public function test_empty_snapshots() {
		$diff = $this->diff( array(), array() );

		$this->assertFalse( $diff->has_changes() );
		$this->assertSame( array( 0 ), array_unique( array_values( $diff->get_summary()->to_array() ) ) );
	}

	/**
	 * A new option is reported as added with its after-state.
	 */
	public function test_added_option() {
		$before = $this->fixture_before();
		$after  = $before + array( 'acme_feature_enabled' => array( 'yes', 'auto-on' ) );

		$diff = $this->diff( $before, $after );

		$this->assertTrue( $diff->has_changes() );
		$this->assertSame( array( 'acme_feature_enabled' ), array_keys( $diff->get_added() ) );
		$this->assertSame( array(), $diff->get_removed() );
		$this->assertSame( array(), $diff->get_changed() );
		$this->assertInstanceOf( OptionState::class, $diff->get_added()['acme_feature_enabled'] );
		$this->assertSame(
			array(
				'name'          => 'acme_feature_enabled',
				'size'          => 3,
				'autoload'      => 'auto-on',
				'is_autoloaded' => true,
			),
			$diff->get_added()['acme_feature_enabled']->to_array()
		);

		$summary = $diff->get_summary();
		$this->assertSame( 1, $summary->get_added_count() );
		$this->assertSame( 1, $summary->get_option_count_delta() );
		$this->assertSame( 3, $summary->get_total_bytes_delta() );
		$this->assertSame( 1, $summary->get_autoloaded_count_delta() );
		$this->assertSame( 3, $summary->get_autoloaded_bytes_delta() );
	}

	/**
	 * A missing option is reported as removed with its before-state.
	 */
	public function test_removed_option() {
		$before = $this->fixture_before();
		$after  = $before;
		unset( $after['bravo'] );

		$diff = $this->diff( $before, $after );

		$this->assertTrue( $diff->has_changes() );
		$this->assertSame( array(), $diff->get_added() );
		$this->assertSame( array(), $diff->get_changed() );
		$this->assertSame(
			array(
				array(
					'name'          => 'bravo',
					'size'          => 10,
					'autoload'      => 'off',
					'is_autoloaded' => false,
				),
			),
			$diff->to_array()['removed']
		);

		$summary = $diff->get_summary();
		$this->assertSame( 1, $summary->get_removed_count() );
		$this->assertSame( -1, $summary->get_option_count_delta() );
		$this->assertSame( -10, $summary->get_total_bytes_delta() );
		$this->assertSame( 0, $summary->get_autoloaded_count_delta() );
		$this->assertSame( 0, $summary->get_autoloaded_bytes_delta() );
	}

	/**
	 * A value change with the same byte length is still detected (by fingerprint).
	 */
	public function test_value_changed_same_size() {
		$diff = $this->diff(
			array( 'acme' => array( 'abcd', 'on' ) ),
			array( 'acme' => array( 'wxyz', 'on' ) )
		);

		$change = $diff->get_changed()['acme'];
		$this->assertTrue( $change->is_value_changed() );
		$this->assertSame( 0, $change->get_size_delta() );
		$this->assertFalse( $change->is_autoload_value_changed() );
		$this->assertFalse( $change->is_autoload_behavior_changed() );
		$this->assertSame( 1, $diff->get_summary()->get_value_changed_count() );
		$this->assertSame( 0, $diff->get_summary()->get_total_bytes_delta() );
	}

	/**
	 * A growing value has a positive size delta.
	 */
	public function test_value_changed_size_increased() {
		$change = $this->diff(
			array( 'acme' => array( str_repeat( 'a', 100 ), 'off' ) ),
			array( 'acme' => array( str_repeat( 'a', 150 ), 'off' ) )
		)->get_changed()['acme'];

		$this->assertTrue( $change->is_value_changed() );
		$this->assertSame( 100, $change->get_before_size() );
		$this->assertSame( 150, $change->get_after_size() );
		$this->assertSame( 50, $change->get_size_delta() );
	}

	/**
	 * A shrinking value has a negative size delta.
	 */
	public function test_value_changed_size_decreased() {
		$diff   = $this->diff(
			array( 'acme' => array( str_repeat( 'a', 150 ), 'on' ) ),
			array( 'acme' => array( str_repeat( 'a', 100 ), 'on' ) )
		);
		$change = $diff->get_changed()['acme'];

		$this->assertTrue( $change->is_value_changed() );
		$this->assertSame( -50, $change->get_size_delta() );
		$this->assertSame( -50, $diff->get_summary()->get_total_bytes_delta() );
		$this->assertSame( -50, $diff->get_summary()->get_autoloaded_bytes_delta() );
	}

	/**
	 * `yes` → `auto-on`: raw storage changed, effective behavior did not.
	 */
	public function test_raw_autoload_changed_same_behavior() {
		$diff   = $this->diff(
			array( 'acme' => array( 'value', 'yes' ) ),
			array( 'acme' => array( 'value', 'auto-on' ) )
		);
		$change = $diff->get_changed()['acme'];

		$this->assertFalse( $change->is_value_changed() );
		$this->assertSame( 0, $change->get_size_delta() );
		$this->assertTrue( $change->is_autoload_value_changed() );
		$this->assertSame( 'yes', $change->get_before_autoload() );
		$this->assertSame( 'auto-on', $change->get_after_autoload() );
		$this->assertFalse( $change->is_autoload_behavior_changed() );
		$this->assertTrue( $change->get_before_is_autoloaded() );
		$this->assertTrue( $change->get_after_is_autoloaded() );

		$summary = $diff->get_summary();
		$this->assertSame( 1, $summary->get_autoload_value_changed_count() );
		$this->assertSame( 0, $summary->get_autoload_behavior_changed_count() );
		$this->assertSame( 0, $summary->get_autoloaded_bytes_delta() );
	}

	/**
	 * `auto-on` → `auto-off`: effective behavior changed.
	 */
	public function test_effective_autoload_changed() {
		$diff   = $this->diff(
			array( 'acme' => array( 'value', 'auto-on' ) ),
			array( 'acme' => array( 'value', 'auto-off' ) )
		);
		$change = $diff->get_changed()['acme'];

		$this->assertFalse( $change->is_value_changed() );
		$this->assertTrue( $change->is_autoload_value_changed() );
		$this->assertTrue( $change->is_autoload_behavior_changed() );
		$this->assertTrue( $change->get_before_is_autoloaded() );
		$this->assertFalse( $change->get_after_is_autoloaded() );

		$summary = $diff->get_summary();
		$this->assertSame( 1, $summary->get_autoload_behavior_changed_count() );
		$this->assertSame( -1, $summary->get_autoloaded_count_delta() );
		$this->assertSame( -5, $summary->get_autoloaded_bytes_delta() );
		$this->assertSame( 0, $summary->get_total_bytes_delta() );
	}

	/**
	 * Behavior can change with the same raw value (e.g. WordPress's autoload rules changed in between).
	 */
	public function test_effective_autoload_changed_with_same_raw_value() {
		$change = ChangedOption::between(
			new OptionRecord( 'acme', str_repeat( 'a', 64 ), 5, 'auto', false ),
			new OptionRecord( 'acme', str_repeat( 'a', 64 ), 5, 'auto', true )
		);

		$this->assertNotNull( $change );
		$this->assertFalse( $change->is_autoload_value_changed() );
		$this->assertTrue( $change->is_autoload_behavior_changed() );
	}

	/**
	 * Several simultaneous differences stay one changed option.
	 */
	public function test_multiple_simultaneous_changes_are_one_record() {
		$diff = $this->diff(
			array( 'acme' => array( 'abc', 'yes' ) ),
			array( 'acme' => array( 'abcdefgh', 'off' ) )
		);

		$this->assertSame( array(), $diff->get_added() );
		$this->assertSame( array(), $diff->get_removed() );
		$this->assertCount( 1, $diff->get_changed() );
		$this->assertSame(
			array(
				'name'                      => 'acme',
				'value_changed'             => true,
				'before_size'               => 3,
				'after_size'                => 8,
				'size_delta'                => 5,
				'before_autoload'           => 'yes',
				'after_autoload'            => 'off',
				'autoload_value_changed'    => true,
				'before_is_autoloaded'      => true,
				'after_is_autoloaded'       => false,
				'autoload_behavior_changed' => true,
			),
			$diff->get_changed()['acme']->to_array()
		);

		$summary = $diff->get_summary();
		$this->assertSame( 1, $summary->get_changed_count() );
		$this->assertSame( 1, $summary->get_value_changed_count() );
		$this->assertSame( 1, $summary->get_autoload_value_changed_count() );
		$this->assertSame( 1, $summary->get_autoload_behavior_changed_count() );
	}

	/**
	 * Unchanged records never appear in the diff, and between() reports null for them.
	 */
	public function test_unchanged_options_are_not_reported() {
		$diff = $this->diff( $this->fixture_before(), $this->fixture_after() );

		$this->assertArrayNotHasKey( 'echo', $diff->get_added() );
		$this->assertArrayNotHasKey( 'echo', $diff->get_removed() );
		$this->assertArrayNotHasKey( 'echo', $diff->get_changed() );

		$record = new OptionRecord( 'echo', str_repeat( 'e', 64 ), 3, 'on', true );
		$this->assertNull( ChangedOption::between( $record, clone $record ) );
	}

	/**
	 * Known fixture: every summary field checked by hand.
	 */
	public function test_summary() {
		$diff = $this->diff( $this->fixture_before(), $this->fixture_after() );

		$this->assertSame(
			array(
				'before_option_count'             => 5,
				'after_option_count'              => 6,
				'option_count_delta'              => 1,
				'before_total_bytes'              => 24,
				'after_total_bytes'               => 30,
				'total_bytes_delta'               => 6,
				'before_autoloaded_count'         => 4,
				'after_autoloaded_count'          => 5,
				'autoloaded_count_delta'          => 1,
				'before_autoloaded_bytes'         => 14,
				'after_autoloaded_bytes'          => 24,
				'autoloaded_bytes_delta'          => 10, // alpha +4, bravo +10 (now autoloaded), golf +1, delta -5.
				'added_count'                     => 2,  // foxtrot, golf.
				'removed_count'                   => 1,  // delta.
				'changed_count'                   => 3,  // alpha, bravo, charlie.
				'value_changed_count'             => 1,  // alpha.
				'autoload_value_changed_count'    => 2,  // bravo, charlie.
				'autoload_behavior_changed_count' => 1,  // bravo.
			),
			$diff->get_summary()->to_array()
		);

		$this->assertSame( array( 'foxtrot', 'golf' ), array_keys( $diff->get_added() ) );
		$this->assertSame( array( 'delta' ), array_keys( $diff->get_removed() ) );
		$this->assertSame( array( 'alpha', 'bravo', 'charlie' ), array_keys( $diff->get_changed() ) );
		$this->assertSame( 5, $diff->get_summary()->get_before()->get_option_count() );
		$this->assertSame( 6, $diff->get_summary()->get_after()->get_option_count() );
	}

	/**
	 * Equivalent snapshots built from differently ordered input give identical diffs.
	 */
	public function test_input_order_independence() {
		$builder = new OptionsDiffBuilder();

		$first  = $builder->build( $this->snapshot( $this->fixture_before() ), $this->snapshot( $this->fixture_after() ) );
		$second = $builder->build(
			$this->snapshot( array_reverse( $this->fixture_before(), true ) ),
			$this->snapshot( array_reverse( $this->fixture_after(), true ) )
		);

		$this->assertSame( $first->to_array(), $second->to_array() );
		$this->assertEquals( $first, $second );
		$this->assertSame( serialize( $first ), serialize( $second ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Comparing representations.
	}

	/**
	 * Added, removed and changed are each sorted byte-wise by name.
	 */
	public function test_results_are_sorted_by_name() {
		$diff = $this->diff(
			array(
				'removed_z' => array( '1', 'no' ),
				'Removed_A' => array( '1', 'no' ),
				'removed_a' => array( '1', 'no' ),
				'changed_z' => array( '1', 'no' ),
				'Changed_A' => array( '1', 'no' ),
				'changed_a' => array( '1', 'no' ),
			),
			array(
				'changed_z' => array( '2', 'no' ),
				'Changed_A' => array( '2', 'no' ),
				'changed_a' => array( '2', 'no' ),
				'added_z'   => array( '1', 'no' ),
				'Added_A'   => array( '1', 'no' ),
				'added_a'   => array( '1', 'no' ),
			)
		);

		$this->assertSame( array( 'Added_A', 'added_a', 'added_z' ), array_keys( $diff->get_added() ) );
		$this->assertSame( array( 'Removed_A', 'removed_a', 'removed_z' ), array_keys( $diff->get_removed() ) );
		$this->assertSame( array( 'Changed_A', 'changed_a', 'changed_z' ), array_keys( $diff->get_changed() ) );
		$this->assertSame( array( 'Added_A', 'added_a', 'added_z' ), array_column( $diff->to_array()['added'], 'name' ) );
	}

	/**
	 * Diff records expose exactly the documented fields, and no fingerprint.
	 */
	public function test_records_expose_only_safe_fields() {
		$result = $this->diff( $this->fixture_before(), $this->fixture_after() )->to_array();

		$this->assertSame( array( 'added', 'removed', 'changed', 'summary' ), array_keys( $result ) );
		foreach ( array_merge( $result['added'], $result['removed'] ) as $state ) {
			$this->assertSame( array( 'name', 'size', 'autoload', 'is_autoloaded' ), array_keys( $state ) );
		}
		foreach ( $result['changed'] as $change ) {
			$this->assertSame(
				array( 'name', 'value_changed', 'before_size', 'after_size', 'size_delta', 'before_autoload', 'after_autoload', 'autoload_value_changed', 'before_is_autoloaded', 'after_is_autoloaded', 'autoload_behavior_changed' ),
				array_keys( $change )
			);
		}
	}

	/**
	 * No option value, fingerprint, context or secret appears in any form of the diff.
	 */
	public function test_privacy() {
		$secret_value = 'a:1:{s:7:"api_key";s:31:"' . self::FAKE_SECRET . '";}';
		$before       = $this->snapshot(
			array(
				'acme_changed' => array( $secret_value, 'on' ),
				'acme_removed' => array( 'token=' . self::FAKE_SECRET, 'off' ),
				'acme_same'    => array( self::FAKE_SECRET, 'on' ),
			)
		);
		$after        = $this->snapshot(
			array(
				'acme_changed' => array( $secret_value . ' ', 'auto-off' ),
				'acme_added'   => array( 'password=' . self::FAKE_SECRET, 'on' ),
				'acme_same'    => array( self::FAKE_SECRET, 'on' ),
			)
		);
		$diff         = ( new OptionsDiffBuilder() )->build( $before, $after );

		// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Inspecting every output form.
		$outputs = array(
			'serialize( diff )'    => serialize( $diff ),
			'json_encode'          => json_encode( $diff->to_array() ),
			'print_r( diff )'      => print_r( $diff, true ),
			'var_export'           => var_export( $diff->to_array(), true ),
			'summary'              => print_r( $diff->get_summary(), true ),
			'added record'         => print_r( $diff->get_added(), true ),
			'removed record'       => print_r( $diff->get_removed(), true ),
			'changed record'       => print_r( $diff->get_changed(), true ),
			'serialize( changed )' => serialize( $diff->get_changed() ),
		);
		// phpcs:enable

		$forbidden = array( self::FAKE_SECRET, 'api_key', 'password=', 'token=', self::SECRET, $before->get_fingerprint_context() );
		foreach ( array_merge( $before->get_records(), $after->get_records() ) as $record ) {
			$forbidden[] = $record->get_fingerprint();
		}

		foreach ( $outputs as $label => $output ) {
			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString( $needle, $output, $label );
			}
		}

		$this->assertSame( array( 'acme_added' ), array_keys( $diff->get_added() ) );
		$this->assertSame( array( 'acme_removed' ), array_keys( $diff->get_removed() ) );
		$this->assertSame( array( 'acme_changed' ), array_keys( $diff->get_changed() ) );
	}

	/**
	 * Snapshots with the same fingerprint context compare normally.
	 */
	public function test_same_fingerprint_context_compares() {
		$before = $this->snapshot( $this->fixture_before(), 'same-secret' );
		$after  = $this->snapshot( $this->fixture_after(), 'same-secret' );

		$this->assertSame( $before->get_fingerprint_context(), $after->get_fingerprint_context() );
		$this->assertSame( 3, ( new OptionsDiffBuilder() )->build( $before, $after )->get_summary()->get_changed_count() );
	}

	/**
	 * Rotated salts: identical data under different contexts fails instead of reporting everything as changed.
	 */
	public function test_different_fingerprint_context_fails() {
		$before = $this->snapshot( $this->fixture_before(), 'old-salts' );
		$after  = $this->snapshot( $this->fixture_before(), 'new-salts' );

		$this->expectException( IncompatibleSnapshotsException::class );
		$this->expectExceptionMessage( 'different fingerprint contexts' );

		( new OptionsDiffBuilder() )->build( $before, $after );
	}

	/**
	 * A different fingerprint scheme (context string) fails even with matching records.
	 */
	public function test_different_scheme_context_fails() {
		$records = array( new OptionRecord( 'acme', str_repeat( 'a', 64 ), 1, 'on', true ) );

		$this->expectException( IncompatibleSnapshotsException::class );

		( new OptionsDiffBuilder() )->build(
			new OptionsSnapshot( $records, 'hmac-sha256-v1:' . str_repeat( '0', 64 ) ),
			new OptionsSnapshot( $records, 'hmac-sha256-v2:' . str_repeat( '0', 64 ) )
		);
	}
}
