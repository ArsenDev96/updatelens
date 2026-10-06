<?php
/**
 * Tests for CronDiffCodec.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use UpdateLens\Diff\CronDiff;
use UpdateLens\Diff\CronDiffBuilder;
use UpdateLens\Storage\CronDiffCodec;
use UpdateLens\Tests\Support\CronFixture;

/**
 * Versioned JSON persistence of cron diffs.
 */
final class CronDiffCodecTest extends TestCase {

	/**
	 * Obviously fake credential used in privacy fixtures.
	 */
	const FAKE_SECRET = 'sk_test_UPDATE_LENS_CRON_LIFECYCLE_SECRET';

	const T = 1767225600; // 2026-01-01T00:00:00Z.

	/**
	 * Diff two event lists.
	 *
	 * @param array $before Events before.
	 * @param array $after  Events after.
	 * @return CronDiff
	 */
	private function diff( array $before, array $after ) {
		return ( new CronDiffBuilder() )->build(
			CronFixture::snapshot( CronFixture::cron( $before ) ),
			CronFixture::snapshot( CronFixture::cron( $after ) )
		);
	}

	/**
	 * A diff with every category, repeated identities and secret arguments.
	 *
	 * @return CronDiff
	 */
	private function full_diff() {
		$secret = array( 'token' => self::FAKE_SECRET );

		return $this->diff(
			array(
				CronFixture::recurring( self::T, 'acme_cleanup', 'daily', $secret ),
				CronFixture::recurring( self::T, 'acme_sync', 'daily', $secret ),
				CronFixture::single( self::T, 'acme_removed', $secret ),
				CronFixture::recurring( self::T, 'acme_dupe', 'hourly' ),
				CronFixture::recurring( self::T + 60, 'acme_dupe', 'hourly' ),
			),
			array(
				CronFixture::recurring( self::T + 3600, 'acme_cleanup', 'daily', $secret ),
				CronFixture::recurring( self::T, 'acme_sync', 'hourly', $secret ),
				CronFixture::single( self::T + 5, 'acme_added', $secret ),
				CronFixture::single( self::T + 5, 'acme_added', array( 'other' ) ),
				CronFixture::recurring( self::T + 3600, 'acme_dupe', 'hourly' ),
				CronFixture::recurring( self::T + 3660, 'acme_dupe', 'hourly' ),
			)
		);
	}

	/**
	 * Valid encoded diff as an array, for tampering.
	 *
	 * @return array
	 */
	private function data() {
		return json_decode( ( new CronDiffCodec() )->encode( $this->full_diff() ), true );
	}

	/**
	 * Encode an array as JSON.
	 *
	 * @param array $data Data.
	 * @return string
	 */
	private static function json( array $data ) {
		return json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test fixture.
	}

	/**
	 * Decoding returns exactly CronDiff::to_array().
	 *
	 * @dataProvider provide_diffs
	 *
	 * @param array $before Events before.
	 * @param array $after  Events after.
	 */
	public function test_round_trip( array $before, array $after ) {
		$diff  = $this->diff( $before, $after );
		$codec = new CronDiffCodec();

		$this->assertSame( $diff->to_array(), $codec->decode( $codec->encode( $diff ) ) );
	}

	/**
	 * A diff starts with EMPTY_PREFIX exactly when it has no records (history flags rely on it).
	 *
	 * @dataProvider provide_diffs
	 *
	 * @param array $before Events before.
	 * @param array $after  Events after.
	 */
	public function test_empty_prefix_marks_diffs_without_changes( array $before, array $after ) {
		$diff    = $this->diff( $before, $after )->to_array();
		$records = count( $diff['added'] ) + count( $diff['removed'] ) + count( $diff['rescheduled'] ) + count( $diff['changed'] );
		$json    = ( new CronDiffCodec() )->encode( $this->diff( $before, $after ) );
		$empty   = 0 === strpos( $json, CronDiffCodec::EMPTY_PREFIX );

		$this->assertSame( 0 === $records, $empty );
	}

	/**
	 * Diffs of each category.
	 *
	 * @return array<string, array{array, array}>
	 */
	public function provide_diffs() {
		$base = array( CronFixture::recurring( self::T, 'acme_cleanup', 'daily' ) );

		return array(
			'no change'             => array( $base, $base ),
			'empty'                 => array( array(), array() ),
			'added'                 => array( $base, array_merge( $base, array( CronFixture::single( self::T, 'acme_once' ) ) ) ),
			'removed'               => array( $base, array() ),
			'rescheduled'           => array( $base, array( CronFixture::recurring( self::T + 3600, 'acme_cleanup', 'daily' ) ) ),
			'recurrence changed'    => array( $base, array( CronFixture::recurring( self::T, 'acme_cleanup', 'hourly' ) ) ),
			'single to recurring'   => array( array( CronFixture::single( self::T, 'acme_x' ) ), array( CronFixture::recurring( self::T, 'acme_x', 'hourly' ) ) ),
			'recurring no interval' => array( array(), array( array( self::T, 'acme_legacy', array(), 'daily', null ) ) ),
		);
	}

	/**
	 * A diff with every category and repeated identities round-trips.
	 */
	public function test_round_trip_full() {
		$diff  = $this->full_diff();
		$codec = new CronDiffCodec();

		$this->assertSame( $diff->to_array(), $codec->decode( $codec->encode( $diff ) ) );
		$this->assertSame(
			array( 2, 1, 3, 1 ),
			array(
				$diff->get_summary()->get_added_count(),
				$diff->get_summary()->get_removed_count(),
				$diff->get_summary()->get_rescheduled_count(),
				$diff->get_summary()->get_changed_count(),
			)
		);
	}

	/**
	 * Invalid stored data is rejected.
	 *
	 * @dataProvider provide_invalid
	 *
	 * @param callable $tamper Changes the valid data, or returns a raw string.
	 */
	public function test_invalid_data_is_rejected( callable $tamper ) {
		$result = $tamper( $this->data() );
		$json   = is_string( $result ) ? $result : self::json( $result );

		$this->expectException( UnexpectedValueException::class );

		( new CronDiffCodec() )->decode( $json );
	}

	/**
	 * Tampering cases.
	 *
	 * @return array<string, array{callable}>
	 */
	public function provide_invalid() {
		$set = static function ( $path, $value ) {
			return static function ( array $data ) use ( $path, $value ) {
				$ref = &$data;
				foreach ( $path as $key ) {
					$ref = &$ref[ $key ];
				}
				$ref = $value;
				return $data;
			};
		};

		return array(
			'malformed JSON'                    => array(
				static function () {
					return '{"schema":1,"added":[';
				},
			),
			'unsupported schema'                => array( $set( array( 'schema' ), 2 ) ),
			'extra top-level field'             => array( $set( array( 'events' ), array() ) ),
			'fingerprint injected'              => array( $set( array( 'added', 0, 'args_fingerprint' ), str_repeat( 'a', 64 ) ) ),
			'args injected'                     => array( $set( array( 'removed', 0, 'args' ), array( self::FAKE_SECRET ) ) ),
			'added not a list'                  => array( $set( array( 'added' ), array( 'x' => array() ) ) ),
			'bad timestamp'                     => array( $set( array( 'added', 0, 'timestamp' ), -1 ) ),
			'recurring without schedule'        => array( $set( array( 'added', 0, 'is_recurring' ), true ) ),
			'wrong timestamp_delta'             => array( $set( array( 'rescheduled', 0, 'timestamp_delta' ), 1 ) ),
			'rescheduled without move'          => array(
				static function ( array $data ) {
					$data['rescheduled'][0]['after_timestamp'] = $data['rescheduled'][0]['before_timestamp'];
					$data['rescheduled'][0]['timestamp_delta'] = 0;
					return $data;
				},
			),
			'changed without recurrence change' => array(
				static function ( array $data ) {
					$data['changed'][0]['after_schedule'] = $data['changed'][0]['before_schedule'];
					$data['changed'][0]['after_interval'] = $data['changed'][0]['before_interval'];
					return $data;
				},
			),
			'wrong timestamp_changed'           => array( $set( array( 'changed', 0, 'timestamp_changed' ), true ) ),
			'invalid ordering'                  => array(
				static function ( array $data ) {
					$data['rescheduled'] = array_reverse( $data['rescheduled'] );
					return $data;
				},
			),
			'wrong added_count'                 => array( $set( array( 'summary', 'added_count' ), 3 ) ),
			'wrong rescheduled_count'           => array( $set( array( 'summary', 'rescheduled_count' ), 0 ) ),
			'wrong delta'                       => array( $set( array( 'summary', 'event_count_delta' ), 0 ) ),
			'inconsistent totals'               => array(
				static function ( array $data ) {
					++$data['summary']['before_event_count'];
					++$data['summary']['after_event_count'];
					return $data;
				},
			),
			'wrong recurring delta'             => array(
				static function ( array $data ) {
					++$data['summary']['after_recurring_count'];
					++$data['summary']['recurring_count_delta'];
					--$data['summary']['after_single_count'];
					--$data['summary']['single_count_delta'];
					return $data;
				},
			),
			'negative count'                    => array( $set( array( 'summary', 'before_unique_hook_count' ), -1 ) ),
			'summary key missing'               => array(
				static function ( array $data ) {
					unset( $data['summary']['changed_count'] );
					return $data;
				},
			),
		);
	}

	/**
	 * The stored diff holds no arguments, no fingerprints and no context.
	 */
	public function test_privacy() {
		$secret = array( 'token' => self::FAKE_SECRET );
		$before = CronFixture::snapshot( CronFixture::cron( array( CronFixture::recurring( self::T, 'acme_cleanup', 'daily', $secret ) ) ) );
		$after  = CronFixture::snapshot( CronFixture::cron( array( CronFixture::recurring( self::T + 60, 'acme_cleanup', 'daily', $secret ) ) ) );
		$json   = ( new CronDiffCodec() )->encode( ( new CronDiffBuilder() )->build( $before, $after ) );

		foreach ( array( self::FAKE_SECRET, 'token', 'fingerprint', $before->get_fingerprint_context(), $before->get_events()[0]->get_args_fingerprint() ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
		$this->assertStringNotContainsString( md5( serialize( $secret ) ), $json ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Core's unkeyed event key.
	}
}
