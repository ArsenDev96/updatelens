<?php
/**
 * Tests for OptionsDiffCodec.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use UpdateLens\Diff\OptionsDiffBuilder;
use UpdateLens\Snapshot\AutoloadPolicy;
use UpdateLens\Snapshot\OptionNoiseFilter;
use UpdateLens\Snapshot\OptionsSnapshotBuilder;
use UpdateLens\Snapshot\OptionValueHasher;
use UpdateLens\Storage\OptionsDiffCodec;

/**
 * Diff → JSON.
 */
final class OptionsDiffCodecTest extends TestCase {

	const FAKE_SECRET = 'sk_test_UPDATE_LENS_LIFECYCLE_SECRET';

	/**
	 * Persisted diff is versioned diff data and contains no values, fingerprints or context.
	 */
	public function test_format_and_privacy() {
		$builder = new OptionsSnapshotBuilder( new OptionValueHasher( 'diff-codec-secret' ), new OptionNoiseFilter(), new AutoloadPolicy( array( 'yes', 'on', 'auto-on', 'auto' ) ) );
		$row     = static function ( $name, $value, $autoload ) {
			return array(
				'option_name'  => $name,
				'option_value' => $value,
				'autoload'     => $autoload,
			);
		};
		$before  = $builder->build(
			array(
				$row( 'acme_settings', 'key=' . self::FAKE_SECRET, 'yes' ),
				$row( 'acme_removed', self::FAKE_SECRET, 'off' ),
			)
		);
		$after   = $builder->build(
			array(
				$row( 'acme_settings', 'key=' . self::FAKE_SECRET . '2', 'auto-off' ),
				$row( 'acme_added', 'token=' . self::FAKE_SECRET, 'on' ),
			)
		);
		$diff    = ( new OptionsDiffBuilder() )->build( $before, $after );

		$json = ( new OptionsDiffCodec() )->encode( $diff );
		$data = json_decode( $json, true );

		$this->assertSame( array( 'schema', 'added', 'removed', 'changed', 'summary' ), array_keys( $data ) );
		$this->assertSame( 1, $data['schema'] );
		$this->assertSame( $diff->to_array(), array_slice( $data, 1 ) );

		$forbidden = array( self::FAKE_SECRET, 'key=', 'token=', 'fingerprint', 'diff-codec-secret', $before->get_fingerprint_context() );
		foreach ( array_merge( $before->get_records(), $after->get_records() ) as $record ) {
			$forbidden[] = $record->get_fingerprint();
		}
		foreach ( $forbidden as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}

	/**
	 * A diff with added, removed and changed options (incl. autoload changes).
	 *
	 * @return \UpdateLens\Diff\OptionsDiff
	 */
	private static function sample_diff() {
		$builder = new OptionsSnapshotBuilder( new OptionValueHasher( 'diff-codec-secret' ), new OptionNoiseFilter(), new AutoloadPolicy( array( 'yes', 'on', 'auto-on', 'auto' ) ) );
		$row     = static function ( $name, $value, $autoload ) {
			return array(
				'option_name'  => $name,
				'option_value' => $value,
				'autoload'     => $autoload,
			);
		};

		return ( new OptionsDiffBuilder() )->build(
			$builder->build(
				array(
					$row( 'b_value', 'one', 'yes' ),
					$row( 'c_autoload', 'same', 'yes' ),
					$row( 'd_behavior', 'same', 'on' ),
					$row( 'e_removed', 'gone', 'off' ),
				)
			),
			$builder->build(
				array(
					$row( 'a_added', 'new', 'auto' ),
					$row( 'b_value', 'two', 'yes' ),
					$row( 'c_autoload', 'same', 'auto-on' ),
					$row( 'd_behavior', 'same', 'off' ),
				)
			)
		);
	}

	/**
	 * Decoding returns exactly OptionsDiff::to_array().
	 */
	public function test_decode_round_trip() {
		$codec = new OptionsDiffCodec();
		$diff  = self::sample_diff();

		$this->assertSame( $diff->to_array(), $codec->decode( $codec->encode( $diff ) ) );
	}

	/**
	 * An empty diff round-trips.
	 */
	public function test_decode_empty_diff() {
		$codec   = new OptionsDiffCodec();
		$builder = new OptionsSnapshotBuilder( new OptionValueHasher( 's' ), new OptionNoiseFilter(), new AutoloadPolicy( array( 'yes' ) ) );
		$diff    = ( new OptionsDiffBuilder() )->build( $builder->build( array() ), $builder->build( array() ) );

		$this->assertSame( $diff->to_array(), $codec->decode( $codec->encode( $diff ) ) );
	}

	/**
	 * Mutations of a valid stored diff that must be rejected.
	 *
	 * @return array
	 */
	public function provide_invalid_mutations() {
		return array(
			'unsupported schema'         => array(
				static function ( array &$d ) {
					$d['schema'] = 2;
				},
			),
			'schema as string'           => array(
				static function ( array &$d ) {
					$d['schema'] = '1';
				},
			),
			'extra top-level key'        => array(
				static function ( array &$d ) {
					$d['fingerprint_context'] = 'x';
				},
			),
			'missing summary'            => array(
				static function ( array &$d ) {
					unset( $d['summary'] );
				},
			),
			'added not a list'           => array(
				static function ( array &$d ) {
					$d['added'] = array( 'a_added' => $d['added'][0] );
				},
			),
			'size as string'             => array(
				static function ( array &$d ) {
					$d['added'][0]['size'] = '3';
				},
			),
			'negative size'              => array(
				static function ( array &$d ) {
					$d['removed'][0]['size'] = -1;
				},
			),
			'extra field (fingerprint)'  => array(
				static function ( array &$d ) {
					$d['added'][0]['fingerprint'] = str_repeat( 'a', 64 );
				},
			),
			'is_autoloaded as int'       => array(
				static function ( array &$d ) {
					$d['added'][0]['is_autoloaded'] = 1;
				},
			),
			'unsorted'                   => array(
				static function ( array &$d ) {
					$d['changed'] = array_reverse( $d['changed'] );
				},
			),
			'duplicate name'             => array(
				static function ( array &$d ) {
					$d['changed'][1]['name'] = $d['changed'][0]['name'];
				},
			),
			'name not a string'          => array(
				static function ( array &$d ) {
					$d['added'][0]['name'] = 5;
				},
			),
			'inconsistent size delta'    => array(
				static function ( array &$d ) {
					$d['changed'][0]['size_delta'] = 99;
				},
			),
			'inconsistent autoload flag' => array(
				static function ( array &$d ) {
					$d['changed'][1]['autoload_value_changed'] = false;
				},
			),
			'inconsistent behavior flag' => array(
				static function ( array &$d ) {
					$d['changed'][2]['autoload_behavior_changed'] = false;
				},
			),
			'changed without difference' => array(
				static function ( array &$d ) {
					$d['changed'][0]['value_changed'] = false;
				},
			),
			'summary count mismatch'     => array(
				static function ( array &$d ) {
					++$d['summary']['added_count'];
				},
			),
			'summary flag count'         => array(
				static function ( array &$d ) {
					$d['summary']['value_changed_count'] = 0;
				},
			),
			'summary delta mismatch'     => array(
				static function ( array &$d ) {
					++$d['summary']['total_bytes_delta'];
				},
			),
			'negative summary total'     => array(
				static function ( array &$d ) {
					$d['summary']['before_total_bytes'] = -1;
					$d['summary']['total_bytes_delta']  = $d['summary']['after_total_bytes'] + 1;
				},
			),
			'summary key missing'        => array(
				static function ( array &$d ) {
					unset( $d['summary']['changed_count'] );
				},
			),
		);
	}

	/**
	 * Invalid stored diffs are rejected with a fixed message that contains none of the stored data.
	 *
	 * @dataProvider provide_invalid_mutations
	 * @param callable $mutate Changes the decoded data.
	 */
	public function test_decode_rejects_invalid_data( callable $mutate ) {
		$codec = new OptionsDiffCodec();
		$data  = json_decode( $codec->encode( self::sample_diff() ), true );
		$this->assertCount( 3, $data['changed'] );
		$mutate( $data );
		$json = json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test fixture.

		try {
			$codec->decode( $json );
			$this->fail( 'Expected UnexpectedValueException.' );
		} catch ( UnexpectedValueException $e ) {
			$this->assertContains( $e->getMessage(), array( 'Stored diff is invalid.', 'Stored diff has an unsupported schema.' ) );
		}
	}

	/**
	 * Non-diff input is rejected without echoing it.
	 *
	 * @return array
	 */
	public function provide_invalid_json() {
		return array(
			'null'          => array( null ),
			'empty'         => array( '' ),
			'not JSON'      => array( '{"schema":1,' . self::FAKE_SECRET ),
			'scalar'        => array( '1' ),
			'list'          => array( '[1,2]' ),
			'too deep'      => array( '{"schema":1,"added":[[[[[[[[[1]]]]]]]]],"removed":[],"changed":[],"summary":{}}' ),
			'not a string'  => array( array( 'schema' => 1 ) ),
			'invalid UTF-8' => array( "{\"schema\":1,\"added\":[\"\xff\"]}" ),
		);
	}

	/**
	 * Invalid JSON.
	 *
	 * @dataProvider provide_invalid_json
	 * @param mixed $json Stored value.
	 */
	public function test_decode_rejects_invalid_json( $json ) {
		try {
			( new OptionsDiffCodec() )->decode( $json );
			$this->fail( 'Expected UnexpectedValueException.' );
		} catch ( UnexpectedValueException $e ) {
			$this->assertSame( 'Stored diff is invalid.', $e->getMessage() );
		}
	}
}
