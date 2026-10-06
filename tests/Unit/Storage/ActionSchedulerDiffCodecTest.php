<?php
/**
 * Tests for ActionSchedulerDiffCodec.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use UpdateLens\Diff\ActionSchedulerDiff;
use UpdateLens\Diff\ActionSchedulerDiffBuilder;
use UpdateLens\Storage\ActionSchedulerDiffCodec;
use UpdateLens\Tests\Support\ActionSchedulerFixture;

/**
 * Versioned JSON persistence of Action Scheduler diffs.
 */
final class ActionSchedulerDiffCodecTest extends TestCase {

	/**
	 * Obviously fake credential used in privacy fixtures.
	 */
	const FAKE_SECRET = 'sk_test_UPDATE_LENS_ACTION_SCHEDULER_LIFECYCLE_SECRET';

	const T = 1767225600; // 2026-01-01T00:00:00Z.

	/**
	 * Diff two row lists.
	 *
	 * @param array $before Rows before.
	 * @param array $after  Rows after.
	 * @return ActionSchedulerDiff
	 */
	private static function diff( array $before, array $after ) {
		return ( new ActionSchedulerDiffBuilder() )->build( ActionSchedulerFixture::snapshot( $before ), ActionSchedulerFixture::snapshot( $after ) );
	}

	/**
	 * A diff with every category, groups, repeated identities and secret arguments.
	 *
	 * @return ActionSchedulerDiff
	 */
	private static function full_diff() {
		$secret = array( 'token' => self::FAKE_SECRET );

		return self::diff(
			array(
				ActionSchedulerFixture::recurring( 'wc_cleanup', self::T, 3600, $secret, 'woocommerce' ),
				ActionSchedulerFixture::single( 'acme_removed', self::T + 10, $secret ),
				ActionSchedulerFixture::recurring( 'acme_sync', self::T, 86400, array(), 'acme' ),
				ActionSchedulerFixture::single( 'acme_upgrade', self::T + 20, array( 1 ) ),
				ActionSchedulerFixture::async( 'acme_dupe', self::T ),
				ActionSchedulerFixture::async( 'acme_dupe', self::T ),
			),
			array(
				ActionSchedulerFixture::recurring( 'wc_cleanup', self::T + 7200, 3600, $secret, 'woocommerce' ),
				ActionSchedulerFixture::cron( 'acme_sync', self::T + 30, '0 3 * * *', array(), 'acme' ),
				ActionSchedulerFixture::recurring( 'acme_upgrade', self::T + 20, 600, array( 1 ) ),
				ActionSchedulerFixture::async( 'acme_dupe', self::T ),
				ActionSchedulerFixture::single( 'acme_added', self::T + 40, $secret, 'acme' ),
				ActionSchedulerFixture::async( 'acme_added', self::T + 41 ),
			)
		);
	}

	/**
	 * Every category is present and decodes to exactly to_array().
	 */
	public function test_round_trip_full() {
		$codec = new ActionSchedulerDiffCodec();
		$diff  = self::full_diff();
		$data  = $codec->decode( $codec->encode( $diff ) );

		$this->assertSame( $diff->to_array(), $data );
		$this->assertSame( array( 'acme_added', 'acme_added' ), array_column( $data['added'], 'hook' ) );
		$this->assertSame( array( 'acme_dupe', 'acme_removed' ), array_column( $data['removed'], 'hook' ) );
		$this->assertSame( array( 'wc_cleanup' ), array_column( $data['rescheduled'], 'hook' ) );
		$this->assertSame( 7200, $data['rescheduled'][0]['timestamp_delta'] );
		$this->assertSame( array( 'acme_sync', 'acme_upgrade' ), array_column( $data['changed'], 'hook' ) );
		$this->assertSame( array( 'interval', 'single' ), array_column( $data['changed'], 'before_schedule_type' ) );
		$this->assertSame( array( 'cron', 'interval' ), array_column( $data['changed'], 'after_schedule_type' ) );
		$this->assertSame( 0, $data['summary']['action_count_delta'] );
		$this->assertSame( 1, $data['summary']['recurring_count_delta'] );
	}

	/**
	 * Diff pairs for round trips.
	 *
	 * @return array<string, array{array, array}>
	 */
	public function diffs() {
		$a = ActionSchedulerFixture::recurring( 'acme', self::T, 60 );

		return array(
			'both empty'  => array( array(), array() ),
			'no changes'  => array( array( $a ), array( $a ) ),
			'all added'   => array( array(), array( $a, ActionSchedulerFixture::async( 'b', self::T ) ) ),
			'all removed' => array( array( $a ), array() ),
			'status only' => array(
				array( $a ),
				array( array( 'status' => 'in-progress' ) + $a ),
			),
		);
	}

	/**
	 * Round trip of small diffs.
	 *
	 * @dataProvider diffs
	 * @param array $before Rows before.
	 * @param array $after  Rows after.
	 */
	public function test_round_trip( array $before, array $after ) {
		$codec = new ActionSchedulerDiffCodec();
		$diff  = self::diff( $before, $after );

		$this->assertSame( $diff->to_array(), $codec->decode( $codec->encode( $diff ) ) );
	}

	/**
	 * EMPTY_PREFIX marks exactly the diffs without changes.
	 *
	 * @dataProvider diffs
	 * @param array $before Rows before.
	 * @param array $after  Rows after.
	 */
	public function test_empty_prefix_marks_diffs_without_changes( array $before, array $after ) {
		$diff = self::diff( $before, $after );

		$this->assertSame( ! $diff->has_changes(), 0 === strpos( ( new ActionSchedulerDiffCodec() )->encode( $diff ), ActionSchedulerDiffCodec::EMPTY_PREFIX ) );
		$this->assertDoesNotMatchRegularExpression( '/[%_\\\\\']/', ActionSchedulerDiffCodec::EMPTY_PREFIX );
	}

	/**
	 * Tampered data.
	 *
	 * @return array<string, array{callable}>
	 */
	public function invalid_data() {
		$entry   = static function ( $section, $key, $value ) {
			return static function ( array $data ) use ( $section, $key, $value ) {
				$data[ $section ][0][ $key ] = $value;
				return $data;
			};
		};
		$summary = static function ( $key, $value ) {
			return static function ( array $data ) use ( $key, $value ) {
				$data['summary'][ $key ] = $value;
				return $data;
			};
		};

		return array(
			'other schema'                    => array(
				static function ( array $data ) {
					$data['schema'] = 2;
					return $data;
				},
			),
			'extra top key'                   => array(
				static function ( array $data ) {
					$data['fingerprint_context'] = 'as-args-hmac-sha256-v1:' . str_repeat( 'a', 64 );
					return $data;
				},
			),
			'args fingerprint in entry'       => array( $entry( 'added', 'args_fingerprint', str_repeat( 'a', 64 ) ) ),
			'action id in entry'              => array(
				static function ( array $data ) {
					$data['added'][0] = array( 'action_id' => 1 ) + $data['added'][0];
					return $data;
				},
			),
			'added hook not string'           => array( $entry( 'added', 'hook', array() ) ),
			'added bad status'                => array( $entry( 'added', 'status', 'failed' ) ),
			'added bad timestamp'             => array( $entry( 'added', 'timestamp', -5 ) ),
			'added bad schedule'              => array( $entry( 'added', 'schedule_type', 'serialized' ) ),
			'added recurring flag'            => array( $entry( 'added', 'is_recurring', true ) ),
			'removed group not string'        => array( $entry( 'removed', 'group', 7 ) ),
			'rescheduled did not move'        => array(
				static function ( array $data ) {
					$data['rescheduled'][0]['after_timestamp'] = $data['rescheduled'][0]['before_timestamp'];
					$data['rescheduled'][0]['timestamp_delta'] = 0;
					return $data;
				},
			),
			'rescheduled wrong delta'         => array( $entry( 'rescheduled', 'timestamp_delta', 1 ) ),
			'rescheduled interval wrong type' => array( $entry( 'rescheduled', 'interval', '3600' ) ),
			'changed without schedule change' => array(
				static function ( array $data ) {
					foreach ( array( 'schedule_type', 'interval', 'cron_expression', 'is_recurring' ) as $field ) {
						$data['changed'][1][ 'after_' . $field ] = $data['changed'][1][ 'before_' . $field ];
					}
					return $data;
				},
			),
			'changed timestamp flag'          => array(
				static function ( array $data ) {
					$data['changed'][0]['timestamp_changed'] = ! $data['changed'][0]['timestamp_changed'];
					return $data;
				},
			),
			'changed bad cron expression'     => array( $entry( 'changed', 'after_cron_expression', '0  3 * * *' ) ),
			'unsorted added'                  => array(
				static function ( array $data ) {
					$data['added'] = array_reverse( $data['added'] );
					return $data;
				},
			),
			'added count mismatch'            => array( $summary( 'added_count', 3 ) ),
			'removed count mismatch'          => array( $summary( 'removed_count', 1 ) ),
			'rescheduled count mismatch'      => array( $summary( 'rescheduled_count', 0 ) ),
			'action delta mismatch'           => array( $summary( 'action_count_delta', 1 ) ),
			'recurring delta mismatch'        => array( $summary( 'recurring_count_delta', 0 ) ),
			'before totals inconsistent'      => array( $summary( 'before_single_count', 9 ) ),
			'negative count'                  => array( $summary( 'before_unique_hook_count', -1 ) ),
			'count as string'                 => array( $summary( 'changed_count', '2' ) ),
			'more hooks than actions'         => array(
				static function ( array $data ) {
					$data['summary']['after_unique_hook_count']  = 99;
					$data['summary']['unique_hook_count_delta'] = 99 - $data['summary']['before_unique_hook_count'];
					return $data;
				},
			),
			'more pairs than before actions'  => array(
				static function ( array $data ) {
					foreach ( array( 'before', 'after' ) as $side ) {
						$data['summary'][ $side . '_action_count' ]    = 2;
						$data['summary'][ $side . '_single_count' ]    = 2 - $data['summary'][ $side . '_recurring_count' ];
						$data['summary'][ $side . '_unique_hook_count' ] = 1;
					}
					$data['summary']['single_count_delta']      = $data['summary']['after_single_count'] - $data['summary']['before_single_count'];
					$data['summary']['unique_hook_count_delta'] = 0;
					return $data;
				},
			),
			'missing summary key'             => array(
				static function ( array $data ) {
					unset( $data['summary']['changed_count'] );
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
		$codec = new ActionSchedulerDiffCodec();
		$data  = $tamper( json_decode( $codec->encode( self::full_diff() ), true ) );

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test.
			$codec->decode( json_encode( $data ) );
			$this->fail( 'Invalid data was accepted.' );
		} catch ( UnexpectedValueException $e ) {
			$this->assertMatchesRegularExpression( '/^Stored Action Scheduler diff (is invalid|has an unsupported schema)\.$/', $e->getMessage() );
		}
	}

	/**
	 * Unreadable input never echoes the input in the message.
	 */
	public function test_unreadable_input_is_rejected() {
		$codec = new ActionSchedulerDiffCodec();
		foreach ( array( '', 'null', '{}', self::FAKE_SECRET, '{"schema":1,"added":"' . self::FAKE_SECRET . '"}', 7 ) as $input ) {
			try {
				$codec->decode( $input );
				$this->fail( 'Unreadable input was accepted.' );
			} catch ( UnexpectedValueException $e ) {
				$this->assertStringNotContainsString( self::FAKE_SECRET, $e->getMessage() );
			}
		}
	}

	/**
	 * No arguments, fingerprints, fingerprint context, serialized schedules or IDs are encoded.
	 */
	public function test_privacy() {
		$json = ( new ActionSchedulerDiffCodec() )->encode( self::full_diff() );

		foreach ( array( self::FAKE_SECRET, 'token', 'fingerprint', 'hmac', 'ActionScheduler_', 'O:', 'action_id', 'claim' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
		$this->assertDoesNotMatchRegularExpression( '/[0-9a-f]{64}/', $json );
	}
}
