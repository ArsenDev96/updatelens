<?php
/**
 * Tests for ActionSchedulerSnapshotCodec.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use UpdateLens\Snapshot\ActionSchedulerSnapshot;
use UpdateLens\Storage\ActionSchedulerSnapshotCodec;
use UpdateLens\Tests\Support\ActionSchedulerFixture;

/**
 * Versioned JSON persistence of temporary Action Scheduler snapshots.
 */
final class ActionSchedulerSnapshotCodecTest extends TestCase {

	/**
	 * Obviously fake credential used in privacy fixtures.
	 */
	const FAKE_SECRET = 'sk_test_UPDATE_LENS_ACTION_SCHEDULER_LIFECYCLE_SECRET';

	const T = 1767225600; // 2026-01-01T00:00:00Z.

	/**
	 * Snapshot with every schedule type, groups, an in-progress action,
	 * duplicates, long (extended) arguments and secret arguments.
	 *
	 * @return ActionSchedulerSnapshot
	 */
	private static function snapshot() {
		$secret = array( 'token' => self::FAKE_SECRET );
		$long   = array( 'payload' => str_repeat( 'x', 300 ) . self::FAKE_SECRET );

		$in_progress           = ActionSchedulerFixture::single( 'acme_running', self::T, $secret );
		$in_progress['status'] = 'in-progress';

		return ActionSchedulerFixture::snapshot(
			array(
				ActionSchedulerFixture::recurring( 'woocommerce_cleanup', self::T + 3600, 3600, $secret, 'woocommerce' ),
				ActionSchedulerFixture::single( 'acme_once', self::T + 60, $long ),
				ActionSchedulerFixture::cron( 'acme_report', self::T + 7200, '0 3 * * *', array(), 'acme' ),
				ActionSchedulerFixture::async( 'acme_async', self::T, array( 1, 2 ) ),
				ActionSchedulerFixture::async( 'acme_async', self::T, array( 1, 2 ) ),
				$in_progress,
				ActionSchedulerFixture::single( 'ünïcode/hook "quoted"', self::T + 5, array() ),
			)
		);
	}

	/**
	 * Encode → decode keeps every record and the context, and re-encodes to the same bytes.
	 */
	public function test_round_trip() {
		$codec    = new ActionSchedulerSnapshotCodec();
		$snapshot = self::snapshot();
		$json     = $codec->encode( $snapshot );
		$decoded  = $codec->decode( $json );

		$this->assertSame( $snapshot->to_array(), $decoded->to_array() );
		$this->assertSame( $json, $codec->encode( $decoded ) );
		$this->assertSame( 7, $decoded->get_summary()->get_action_count() );
		$this->assertSame( 1, $decoded->get_summary()->get_in_progress_count() );
	}

	/**
	 * Exact top-level and record keys; the summary is derived, not stored.
	 */
	public function test_format() {
		$data = json_decode( ( new ActionSchedulerSnapshotCodec() )->encode( self::snapshot() ), true );

		$this->assertSame( array( 'schema', 'fingerprint_context', 'actions' ), array_keys( $data ) );
		$this->assertSame( 1, $data['schema'] );
		$this->assertStringStartsWith( 'as-args-hmac-sha256-v1:', $data['fingerprint_context'] );
		$this->assertSame( ActionSchedulerSnapshotCodec::ACTION_KEYS, array_keys( $data['actions'][0] ) );
	}

	/**
	 * An empty queue is a valid snapshot (Action Scheduler installed, nothing pending).
	 */
	public function test_empty_snapshot() {
		$codec    = new ActionSchedulerSnapshotCodec();
		$snapshot = ActionSchedulerFixture::snapshot( array() );

		$this->assertSame( array(), $codec->decode( $codec->encode( $snapshot ) )->get_actions() );
	}

	/**
	 * Tampered data.
	 *
	 * @return array<string, array{callable}>
	 */
	public function invalid_data() {
		$set = static function ( $key, $value ) {
			return static function ( array $data ) use ( $key, $value ) {
				$data['actions'][0][ $key ] = $value;
				return $data;
			};
		};

		return array(
			'extra top key'         => array(
				static function ( array $data ) {
					$data['summary'] = array();
					return $data;
				},
			),
			'missing top key'       => array(
				static function ( array $data ) {
					unset( $data['fingerprint_context'] );
					return $data;
				},
			),
			'other schema'          => array(
				static function ( array $data ) {
					$data['schema'] = 2;
					return $data;
				},
			),
			'schema as string'      => array(
				static function ( array $data ) {
					$data['schema'] = '1';
					return $data;
				},
			),
			'bad context'           => array(
				static function ( array $data ) {
					$data['fingerprint_context'] = 'as-args-hmac-sha256-v1:XYZ';
					return $data;
				},
			),
			'actions not a list'    => array(
				static function ( array $data ) {
					$data['actions'] = array( 'a' => $data['actions'][0] );
					return $data;
				},
			),
			'extra action key'      => array(
				static function ( array $data ) {
					$data['actions'][0]['args'] = '[]';
					return $data;
				},
			),
			'action id key'         => array(
				static function ( array $data ) {
					$data['actions'][0] = array( 'action_id' => 5 ) + $data['actions'][0];
					return $data;
				},
			),
			'reordered keys'        => array(
				static function ( array $data ) {
					$data['actions'][0] = array_reverse( $data['actions'][0], true );
					return $data;
				},
			),
			'hook not a string'     => array( $set( 'hook', 5 ) ),
			'group not a string'    => array( $set( 'group', null ) ),
			'bad status'            => array( $set( 'status', 'complete' ) ),
			'zero timestamp'        => array( $set( 'timestamp', 0 ) ),
			'timestamp as string'   => array( $set( 'timestamp', '1767225600' ) ),
			'unknown schedule type' => array( $set( 'schedule_type', 'weekly' ) ),
			'bad fingerprint'       => array( $set( 'args_fingerprint', str_repeat( 'g', 64 ) ) ),
			'uppercase fingerprint' => array( $set( 'args_fingerprint', str_repeat( 'A', 64 ) ) ),
			'wrong recurring flag'  => array(
				static function ( array $data ) {
					foreach ( $data['actions'] as $i => $action ) {
						if ( 'interval' === $action['schedule_type'] ) {
							$data['actions'][ $i ]['is_recurring'] = false;
						}
					}
					return $data;
				},
			),
			'interval on single'    => array(
				static function ( array $data ) {
					foreach ( $data['actions'] as $i => $action ) {
						if ( 'single' === $action['schedule_type'] ) {
							$data['actions'][ $i ]['interval'] = 60;
						}
					}
					return $data;
				},
			),
			'bad cron expression'   => array(
				static function ( array $data ) {
					foreach ( $data['actions'] as $i => $action ) {
						if ( 'cron' === $action['schedule_type'] ) {
							$data['actions'][ $i ]['cron_expression'] = '0 3 * *';
						}
					}
					return $data;
				},
			),
			'not canonical order'   => array(
				static function ( array $data ) {
					$data['actions'] = array_reverse( $data['actions'] );
					return $data;
				},
			),
		);
	}

	/**
	 * Every invalid form is rejected with a fixed message.
	 *
	 * @dataProvider invalid_data
	 * @param callable $tamper Changes decoded data.
	 */
	public function test_invalid_data_is_rejected( callable $tamper ) {
		$codec = new ActionSchedulerSnapshotCodec();
		$data  = $tamper( json_decode( $codec->encode( self::snapshot() ), true ) );

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test.
			$codec->decode( json_encode( $data ) );
			$this->fail( 'Invalid data was accepted.' );
		} catch ( UnexpectedValueException $e ) {
			$this->assertMatchesRegularExpression( '/^Stored Action Scheduler snapshot (is invalid|has an unsupported schema)\.$/', $e->getMessage() );
		}
	}

	/**
	 * Not JSON, empty, too deep.
	 */
	public function test_unreadable_input_is_rejected() {
		$codec = new ActionSchedulerSnapshotCodec();
		foreach ( array( '', 'null', '[]', '{"schema":1', self::FAKE_SECRET, str_repeat( '[', 20 ) . str_repeat( ']', 20 ), 42 ) as $input ) {
			try {
				$codec->decode( $input );
				$this->fail( 'Unreadable input was accepted.' );
			} catch ( UnexpectedValueException $e ) {
				$this->assertStringNotContainsString( self::FAKE_SECRET, $e->getMessage() );
			}
		}
	}

	/**
	 * Neither the secret nor any part of the stored arguments is encoded.
	 */
	public function test_privacy() {
		$json = ( new ActionSchedulerSnapshotCodec() )->encode( self::snapshot() );

		foreach ( array( self::FAKE_SECRET, 'token', 'payload', 'xxxxxxxxxx', 'ActionScheduler_', 'O:', 'action_id', 'claim' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}
}
