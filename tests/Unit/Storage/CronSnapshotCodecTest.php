<?php
/**
 * Tests for CronSnapshotCodec.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use UpdateLens\Snapshot\CronArgsHasher;
use UpdateLens\Snapshot\CronSnapshot;
use UpdateLens\Storage\CronSnapshotCodec;
use UpdateLens\Tests\Support\CronFixture;

/**
 * Versioned JSON persistence of cron snapshots.
 */
final class CronSnapshotCodecTest extends TestCase {

	/**
	 * Obviously fake credential used in privacy fixtures.
	 */
	const FAKE_SECRET = 'sk_test_UPDATE_LENS_CRON_LIFECYCLE_SECRET';

	const T = 1767225600; // 2026-01-01T00:00:00Z.

	/**
	 * Snapshot with several hooks, a one-time event, repeated instances and an exact duplicate.
	 *
	 * @return CronSnapshot
	 */
	private function snapshot() {
		$cron = CronFixture::cron(
			array(
				CronFixture::recurring( self::T, 'acme_cleanup', 'daily', array( 'token' => self::FAKE_SECRET ) ),
				CronFixture::recurring( self::T + 3600, 'acme_cleanup', 'daily', array( 'token' => self::FAKE_SECRET ) ),
				CronFixture::single( self::T + 60, 'acme_mail', array( 'person@example.test', self::FAKE_SECRET ) ),
				CronFixture::recurring( self::T + 10, 'acme_custom', 'every_minute', array(), 60 ),
				CronFixture::single( self::T + 5, "acme_\u{00E9}t\u{00E9}" ),
			)
		);
		// Exact duplicate under a stale entry key (possible only through direct writes).
		$cron[ self::T + 60 ]['acme_mail']['stale-key'] = $cron[ self::T + 60 ]['acme_mail'][ md5( serialize( array( 'person@example.test', self::FAKE_SECRET ) ) ) ]; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Core's event key.

		return CronFixture::snapshot( $cron );
	}

	/**
	 * Valid encoded snapshot as an array, for tampering.
	 *
	 * @return array
	 */
	private function data() {
		return json_decode( ( new CronSnapshotCodec() )->encode( $this->snapshot() ), true );
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
	 * Encode → decode keeps every record, the context and the summary; re-encoding is byte-identical.
	 */
	public function test_round_trip() {
		$codec    = new CronSnapshotCodec();
		$snapshot = $this->snapshot();
		$json     = $codec->encode( $snapshot );
		$decoded  = $codec->decode( $json );

		$this->assertEquals( $snapshot, $decoded );
		$this->assertSame( $snapshot->to_array(), $decoded->to_array() );
		$this->assertSame( $json, $codec->encode( $decoded ) );
		$this->assertSame( 6, $decoded->get_summary()->get_event_count() );
		$this->assertSame( 4, $decoded->get_summary()->get_unique_hook_count() );
	}

	/**
	 * Format: schema, context and the approved event fields only.
	 */
	public function test_format() {
		$data = $this->data();

		$this->assertSame( array( 'schema', 'fingerprint_context', 'events' ), array_keys( $data ) );
		$this->assertSame( 1, $data['schema'] );
		$this->assertSame( ( new CronArgsHasher( CronFixture::SECRET ) )->get_context(), $data['fingerprint_context'] );
		foreach ( $data['events'] as $event ) {
			$this->assertSame( CronSnapshotCodec::EVENT_KEYS, array_keys( $event ) );
		}
	}

	/**
	 * Empty snapshot round-trips.
	 */
	public function test_empty_snapshot() {
		$codec    = new CronSnapshotCodec();
		$snapshot = CronFixture::snapshot( array( 'version' => 2 ) );

		$this->assertSame( $snapshot->to_array(), $codec->decode( $codec->encode( $snapshot ) )->to_array() );
	}

	/**
	 * Repeated and duplicate logical events survive decoding.
	 */
	public function test_duplicates_preserved() {
		$events = ( new CronSnapshotCodec() )->decode( self::json( $this->data() ) )->get_events();
		$mail   = array_values(
			array_filter(
				$events,
				static function ( $event ) {
					return 'acme_mail' === $event->get_hook();
				}
			)
		);

		$this->assertCount( 2, $mail );
		$this->assertSame( $mail[0]->to_array(), $mail[1]->to_array() );
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

		( new CronSnapshotCodec() )->decode( $json );
	}

	/**
	 * Tampering cases.
	 *
	 * @return array<string, array{callable}>
	 */
	public function provide_invalid() {
		$event = static function ( $key, $value ) {
			return static function ( array $data ) use ( $key, $value ) {
				$data['events'][0][ $key ] = $value;
				return $data;
			};
		};

		return array(
			'empty string'              => array(
				static function () {
					return '';
				},
			),
			'malformed JSON'            => array(
				static function () {
					return '{"schema":1,';
				},
			),
			'not an object'             => array(
				static function () {
					return '[1,2]';
				},
			),
			'unsupported schema'        => array(
				static function ( array $data ) {
					$data['schema'] = 2;
					return $data;
				},
			),
			'extra top-level field'     => array(
				static function ( array $data ) {
					$data['summary'] = array();
					return $data;
				},
			),
			'bad context'               => array(
				static function ( array $data ) {
					$data['fingerprint_context'] = 'cron-args-hmac-sha256-v1:xyz';
					return $data;
				},
			),
			'events not a list'         => array(
				static function ( array $data ) {
					$data['events'] = array( 'a' => $data['events'][0] );
					return $data;
				},
			),
			'raw args injected'         => array( $event( 'args', array( self::FAKE_SECRET ) ) ),
			'missing field'             => array(
				static function ( array $data ) {
					unset( $data['events'][0]['interval'] );
					return $data;
				},
			),
			'bad fingerprint'           => array( $event( 'args_fingerprint', str_repeat( 'g', 64 ) ) ),
			'short fingerprint'         => array( $event( 'args_fingerprint', 'abc' ) ),
			'uppercase fingerprint'     => array( $event( 'args_fingerprint', str_repeat( 'A', 64 ) ) ),
			'zero timestamp'            => array( $event( 'timestamp', 0 ) ),
			'string timestamp'          => array( $event( 'timestamp', '1767225600' ) ),
			'float timestamp'           => array( $event( 'timestamp', 1767225600.5 ) ),
			'empty schedule'            => array( $event( 'schedule', '' ) ),
			'numeric schedule'          => array( $event( 'schedule', 86400 ) ),
			'string interval'           => array( $event( 'interval', '86400' ) ),
			'negative interval'         => array( $event( 'interval', -1 ) ),
			'is_recurring inconsistent' => array( $event( 'is_recurring', false ) ),
			'hook not a string'         => array( $event( 'hook', 42 ) ),
			'interval on single event'  => array(
				static function ( array $data ) {
					foreach ( $data['events'] as $i => $entry ) {
						if ( null === $entry['schedule'] ) {
							$data['events'][ $i ]['interval'] = 60;
							return $data;
						}
					}
					return $data;
				},
			),
			'not canonical order'       => array(
				static function ( array $data ) {
					$data['events'] = array_reverse( $data['events'] );
					return $data;
				},
			),
		);
	}

	/**
	 * No argument value appears in the stored JSON or the decoded objects.
	 */
	public function test_privacy() {
		$codec   = new CronSnapshotCodec();
		$json    = $codec->encode( $this->snapshot() );
		$decoded = $codec->decode( $json );

		// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Inspecting every form.
		foreach ( array( $json, serialize( $decoded ), print_r( $decoded, true ), var_export( $decoded->to_array(), true ) ) as $output ) {
			$this->assertStringNotContainsString( self::FAKE_SECRET, $output );
			$this->assertStringNotContainsString( 'person@example.test', $output );
			$this->assertStringNotContainsString( 'token', $output );
			// Core's unkeyed event key is never stored.
			$this->assertStringNotContainsString( md5( serialize( array( 'token' => self::FAKE_SECRET ) ) ), $output );
		}
		// phpcs:enable
	}
}
