<?php
/**
 * Tests for OptionsDiffCodec.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
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
}
