<?php
/**
 * Tests for the monitoring baseline storage format.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use UpdateLens\Storage\MonitoringBaselineCodec;

/**
 * Versioned JSON with full validation on decode.
 */
final class MonitoringBaselineCodecTest extends TestCase {

	const STARTED_AT = '2026-10-07 22:32:00';

	/**
	 * Valid plugin records.
	 *
	 * @return array
	 */
	private static function plugins() {
		return array(
			array(
				'file'    => 'classic-editor/classic-editor.php',
				'name'    => 'Classic Editor',
				'version' => '1.7.0',
				'active'  => false,
			),
			array(
				'file'    => 'woocommerce/woocommerce.php',
				'name'    => 'WooCommerce',
				'version' => '11.1.2',
				'active'  => true,
			),
		);
	}

	/**
	 * Exact stored format, and a lossless round trip.
	 */
	public function test_encodes_and_decodes() {
		$codec = new MonitoringBaselineCodec();
		$json  = $codec->encode( self::STARTED_AT, self::plugins() );

		$this->assertSame(
			'{"schema":1,"started_at":"2026-10-07 22:32:00","plugins":[{"file":"classic-editor/classic-editor.php","name":"Classic Editor","version":"1.7.0","active":false},{"file":"woocommerce/woocommerce.php","name":"WooCommerce","version":"11.1.2","active":true}]}',
			$json
		);
		$this->assertSame(
			array(
				'started_at' => self::STARTED_AT,
				'plugins'    => self::plugins(),
			),
			$codec->decode( $json )
		);
	}

	/**
	 * A site without other plugins has an empty list.
	 */
	public function test_empty_plugin_list() {
		$codec = new MonitoringBaselineCodec();

		$this->assertSame( array(), $codec->decode( $codec->encode( self::STARTED_AT, array() ) )['plugins'] );
	}

	/**
	 * Encoding refuses data that decode() would reject.
	 */
	public function test_encode_rejects_invalid_data() {
		$plugins                  = self::plugins();
		$plugins[0]['update_uri'] = 'https://example.test';

		$this->expectException( UnexpectedValueException::class );
		( new MonitoringBaselineCodec() )->encode( self::STARTED_AT, $plugins );
	}

	/**
	 * Stored JSON of valid baseline data with top-level fields replaced.
	 *
	 * @param array $replace Field => value.
	 * @return string
	 */
	private static function stored( array $replace ) {
		$data = array(
			'schema'     => 1,
			'started_at' => self::STARTED_AT,
			'plugins'    => self::plugins(),
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test data.
		return json_encode( array_replace( $data, $replace ) );
	}

	/**
	 * Stored JSON of valid baseline data with one field of the first plugin replaced.
	 *
	 * @param string $field Plugin field.
	 * @param mixed  $value Value.
	 * @return string
	 */
	private static function stored_plugin( $field, $value ) {
		$plugins              = self::plugins();
		$plugins[0][ $field ] = $value;

		return self::stored( array( 'plugins' => $plugins ) );
	}

	/**
	 * Malformed stored values.
	 *
	 * @return array
	 */
	public function provide_invalid() {
		$plugins = self::plugins();

		return array(
			'not a string'       => array( array( 'schema' => 1 ) ),
			'empty'              => array( '' ),
			'not JSON'           => array( '{"schema":1,' ),
			'serialized PHP'     => array( 'a:1:{s:6:"schema";i:1;}' ),
			'other schema'       => array( self::stored( array( 'schema' => 2 ) ) ),
			'missing plugins'    => array( '{"schema":1,"started_at":"2026-10-07 22:32:00"}' ),
			'extra key'          => array( self::stored( array( 'user_id' => 1 ) ) ),
			'bad time'           => array( self::stored( array( 'started_at' => '2026-02-30 10:00:00' ) ) ),
			'ISO time'           => array( self::stored( array( 'started_at' => '2026-10-07T22:32:00Z' ) ) ),
			'plugins object'     => array( self::stored( array( 'plugins' => array( 'a' => $plugins[0] ) ) ) ),
			'duplicate file'     => array( self::stored( array( 'plugins' => array( $plugins[0], $plugins[0] ) ) ) ),
			'traversal file'     => array( self::stored_plugin( 'file', '../x.php' ) ),
			'empty name'         => array( self::stored_plugin( 'name', '' ) ),
			'control character'  => array( self::stored_plugin( 'name', "A\nB" ) ),
			'long version'       => array( self::stored_plugin( 'version', str_repeat( '1', 65 ) ) ),
			'active not boolean' => array( self::stored_plugin( 'active', 1 ) ),
			'extra plugin field' => array( self::stored_plugin( 'license', 'x' ) ),
		);
	}

	/**
	 * Every malformed value is rejected.
	 *
	 * @dataProvider provide_invalid
	 *
	 * @param mixed $stored Stored value.
	 */
	public function test_decode_rejects_invalid_data( $stored ) {
		$this->expectException( UnexpectedValueException::class );
		( new MonitoringBaselineCodec() )->decode( $stored );
	}
}
