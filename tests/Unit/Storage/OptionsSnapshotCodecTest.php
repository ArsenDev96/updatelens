<?php
/**
 * Tests for OptionsSnapshotCodec.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use UpdateLens\Snapshot\AutoloadPolicy;
use UpdateLens\Snapshot\OptionNoiseFilter;
use UpdateLens\Snapshot\OptionsSnapshot;
use UpdateLens\Snapshot\OptionsSnapshotBuilder;
use UpdateLens\Snapshot\OptionValueHasher;
use UpdateLens\Storage\OptionsSnapshotCodec;

/**
 * Snapshot ↔ JSON.
 */
final class OptionsSnapshotCodecTest extends TestCase {

	const FAKE_SECRET = 'sk_test_UPDATE_LENS_LIFECYCLE_SECRET';

	/**
	 * Fixture snapshot.
	 *
	 * @param bool $reverse Build from reversed input.
	 * @return OptionsSnapshot
	 */
	private function snapshot( $reverse = false ) {
		$rows = array(
			array( 'acme_settings', 'a:1:{s:7:"api_key";s:36:"' . self::FAKE_SECRET . '";}', 'auto-off' ),
			array( 'siteurl', 'https://example.test', 'on' ),
			array( '123', 'numeric name', 'yes' ),
			array( "name_\u{00E9}_\u{1F600}", '', 'auto' ),
			array( 'quote"back\\slash/', 'x', 'no' ),
		);
		if ( $reverse ) {
			$rows = array_reverse( $rows );
		}

		$builder = new OptionsSnapshotBuilder(
			new OptionValueHasher( 'codec-test-secret' ),
			new OptionNoiseFilter(),
			new AutoloadPolicy( array( 'yes', 'on', 'auto-on', 'auto' ) )
		);

		return $builder->build(
			array_map(
				static function ( $row ) {
					return array(
						'option_name'  => $row[0],
						'option_value' => $row[1],
						'autoload'     => $row[2],
					);
				},
				$rows
			)
		);
	}

	/**
	 * Snapshot → JSON → snapshot preserves equality.
	 */
	public function test_round_trip() {
		$codec    = new OptionsSnapshotCodec();
		$snapshot = $this->snapshot();

		$decoded = $codec->decode( $codec->encode( $snapshot ) );

		$this->assertEquals( $snapshot, $decoded );
		$this->assertSame( $snapshot->to_array(), $decoded->to_array() );
		$this->assertSame( $codec->encode( $snapshot ), $codec->encode( $decoded ) );
	}

	/**
	 * Encoding is deterministic and independent of input order.
	 */
	public function test_encoding_is_deterministic() {
		$codec = new OptionsSnapshotCodec();

		$this->assertSame( $codec->encode( $this->snapshot() ), $codec->encode( $this->snapshot( true ) ) );
	}

	/**
	 * Documented, versioned shape.
	 */
	public function test_format() {
		$data = json_decode( ( new OptionsSnapshotCodec() )->encode( $this->snapshot() ), true );

		$this->assertSame( array( 'schema', 'fingerprint_context', 'options' ), array_keys( $data ) );
		$this->assertSame( 1, $data['schema'] );
		$this->assertMatchesRegularExpression( '/^hmac-sha256-v1:[0-9a-f]{64}$/', $data['fingerprint_context'] );
		$this->assertSame( array( 'name', 'fingerprint', 'size', 'autoload', 'is_autoloaded' ), array_keys( $data['options'][0] ) );
		$this->assertSame( '123', $data['options'][0]['name'], 'Numeric names stay strings.' );
	}

	/**
	 * JSON contains no option values.
	 */
	public function test_no_option_values_in_json() {
		$json = ( new OptionsSnapshotCodec() )->encode( $this->snapshot() );

		foreach ( array( self::FAKE_SECRET, 'api_key', 'https://example.test', 'numeric name', 'codec-test-secret' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}

	/**
	 * Malformed, corrupt or unsupported input.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function provide_invalid() {
		$valid_option = array(
			'name'          => 'a',
			'fingerprint'   => str_repeat( 'a', 64 ),
			'size'          => 1,
			'autoload'      => 'on',
			'is_autoloaded' => true,
		);
		$context      = 'hmac-sha256-v1:' . str_repeat( 'b', 64 );
		$doc          = static function ( array $options, $schema = 1, $ctx = null ) use ( $context ) {
			return json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test fixture.
				array(
					'schema'              => $schema,
					'fingerprint_context' => null === $ctx ? $context : $ctx,
					'options'             => $options,
				)
			);
		};

		return array(
			'empty string'           => array( '' ),
			'not a string'           => array( null ),
			'not JSON'               => array( '{"schema":1,' ),
			'JSON scalar'            => array( '1' ),
			'empty array'            => array( '[]' ),
			'PHP serialized object'  => array( 'O:8:"stdClass":0:{}' ),
			'unsupported schema'     => array( $doc( array( $valid_option ), 2 ) ),
			'schema as string'       => array( $doc( array( $valid_option ), '1' ) ),
			'bad context'            => array( $doc( array( $valid_option ), 1, 'nope' ) ),
			'extra top-level key'    => array( substr( $doc( array() ), 0, -1 ) . ',"extra":1}' ),
			'options not a list'     => array( $doc( array( 'x' => $valid_option ) ) ),
			'option not an object'   => array( $doc( array( 'a' ) ) ),
			'missing field'          => array( $doc( array( array_diff_key( $valid_option, array( 'size' => 0 ) ) ) ) ),
			'extra field (value)'    => array( $doc( array( $valid_option + array( 'value' => 'secret' ) ) ) ),
			'bad fingerprint'        => array( $doc( array( array_replace( $valid_option, array( 'fingerprint' => 'xyz' ) ) ) ) ),
			'negative size'          => array( $doc( array( array_replace( $valid_option, array( 'size' => -1 ) ) ) ) ),
			'size as string'         => array( $doc( array( array_replace( $valid_option, array( 'size' => '1' ) ) ) ) ),
			'name not a string'      => array( $doc( array( array_replace( $valid_option, array( 'name' => 5 ) ) ) ) ),
			'is_autoloaded not bool' => array( $doc( array( array_replace( $valid_option, array( 'is_autoloaded' => 1 ) ) ) ) ),
			'duplicate names'        => array( $doc( array( $valid_option, $valid_option ) ) ),
			'too deep'               => array( str_repeat( '[', 50 ) . str_repeat( ']', 50 ) ),
		);
	}

	/**
	 * Invalid input fails with UnexpectedValueException.
	 *
	 * @dataProvider provide_invalid
	 *
	 * @param mixed $json Input.
	 */
	public function test_invalid_input_fails_safely( $json ) {
		$this->expectException( UnexpectedValueException::class );

		( new OptionsSnapshotCodec() )->decode( $json );
	}

	/**
	 * Names that are not valid UTF-8 cannot be encoded losslessly: encoding fails instead.
	 */
	public function test_invalid_utf8_name_fails_to_encode() {
		$builder  = new OptionsSnapshotBuilder( new OptionValueHasher( 's' ), new OptionNoiseFilter(), new AutoloadPolicy( array( 'yes' ) ) );
		$snapshot = $builder->build(
			array(
				array(
					'option_name'  => "bad_\xff",
					'option_value' => 'v',
					'autoload'     => 'yes',
				),
			)
		);

		$this->expectException( UnexpectedValueException::class );

		( new OptionsSnapshotCodec() )->encode( $snapshot );
	}
}
