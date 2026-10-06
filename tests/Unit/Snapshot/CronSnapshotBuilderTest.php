<?php
/**
 * Tests for CronSnapshotBuilder and the snapshot it produces.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Snapshot;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;
use UpdateLens\Snapshot\CronArgsHasher;
use UpdateLens\Snapshot\CronSnapshot;
use UpdateLens\Snapshot\MalformedCronStateException;
use UpdateLens\Tests\Support\CronFixture;

/**
 * Raw `cron` option → safe snapshot.
 */
final class CronSnapshotBuilderTest extends TestCase {

	/**
	 * Obviously fake credential used in privacy fixtures.
	 */
	const FAKE_SECRET = 'sk_test_UPDATE_LENS_CRON_SECRET';

	const T = 1767225600; // 2026-01-01T00:00:00Z.

	/**
	 * Fingerprint of arguments under the fixture secret.
	 *
	 * @param array $args Arguments.
	 * @return string
	 */
	private function fp( array $args ) {
		return ( new CronArgsHasher( CronFixture::SECRET ) )->fingerprint( $args );
	}

	/**
	 * Hooks of a snapshot in order.
	 *
	 * @param CronSnapshot $snapshot Snapshot.
	 * @return string[]
	 */
	private function hooks( CronSnapshot $snapshot ) {
		return array_map(
			static function ( $event ) {
				return $event->get_hook();
			},
			$snapshot->get_events()
		);
	}

	/**
	 * States WordPress reads as "no events" give an empty snapshot.
	 *
	 * @dataProvider provide_empty_states
	 *
	 * @param mixed $cron Cron option value.
	 */
	public function test_empty_state( $cron ) {
		$snapshot = CronFixture::snapshot( $cron );

		$this->assertSame( array(), $snapshot->get_events() );
		$this->assertSame(
			array(
				'event_count'           => 0,
				'recurring_event_count' => 0,
				'single_event_count'    => 0,
				'unique_hook_count'     => 0,
			),
			$snapshot->get_summary()->to_array()
		);
		$this->assertSame( ( new CronArgsHasher( CronFixture::SECRET ) )->get_context(), $snapshot->get_fingerprint_context() );
	}

	/**
	 * Empty states.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function provide_empty_states() {
		return array(
			'option missing (false)'    => array( false ),
			'only the version marker'   => array( array( 'version' => 2 ) ),
			'empty array'               => array( array() ),
			'non-array value'           => array( 'not cron' ),
			'empty timestamp and hooks' => array(
				array(
					self::T     => array(),
					self::T + 1 => array( 'acme_hook' => array() ),
					'version'   => 2,
				),
			),
		);
	}

	/**
	 * One-time event: schedule and interval null, not recurring.
	 */
	public function test_single_event() {
		$snapshot = CronFixture::snapshot( CronFixture::cron( array( CronFixture::single( self::T, 'acme_send_digest', array( 7 ) ) ) ) );

		$this->assertCount( 1, $snapshot->get_events() );
		$this->assertSame(
			array(
				'hook'             => 'acme_send_digest',
				'timestamp'        => self::T,
				'schedule'         => null,
				'interval'         => null,
				'is_recurring'     => false,
				'args_fingerprint' => $this->fp( array( 7 ) ),
			),
			$snapshot->get_events()[0]->to_array()
		);
		$this->assertSame( 1, $snapshot->get_summary()->get_single_event_count() );
		$this->assertSame( 0, $snapshot->get_summary()->get_recurring_event_count() );
	}

	/**
	 * Recurring event: schedule name, stored interval, recurring.
	 */
	public function test_recurring_event() {
		$snapshot = CronFixture::snapshot( CronFixture::cron( array( CronFixture::recurring( self::T, 'acme_cleanup', 'daily' ) ) ) );

		$this->assertSame(
			array(
				'hook'             => 'acme_cleanup',
				'timestamp'        => self::T,
				'schedule'         => 'daily',
				'interval'         => 86400,
				'is_recurring'     => true,
				'args_fingerprint' => $this->fp( array() ),
			),
			$snapshot->get_events()[0]->to_array()
		);
		$this->assertSame( 1, $snapshot->get_summary()->get_recurring_event_count() );
	}

	/**
	 * Several hooks: all captured, sorted byte-wise by hook, counted.
	 */
	public function test_multiple_hooks_and_summary() {
		$snapshot = CronFixture::snapshot(
			CronFixture::cron(
				array(
					CronFixture::recurring( self::T + 60, 'wp_version_check', 'twicedaily' ),
					CronFixture::recurring( self::T, 'acme_cleanup', 'daily' ),
					CronFixture::single( self::T + 30, 'Zeta_hook' ),
					CronFixture::recurring( self::T + 10, 'wp_scheduled_delete', 'daily' ),
					CronFixture::single( self::T + 5, 'acme_cleanup', array( 'once' ) ),
				)
			)
		);

		$this->assertSame( array( 'Zeta_hook', 'acme_cleanup', 'acme_cleanup', 'wp_scheduled_delete', 'wp_version_check' ), $this->hooks( $snapshot ) );
		$this->assertSame(
			array(
				'event_count'           => 5,
				'recurring_event_count' => 3,
				'single_event_count'    => 2,
				'unique_hook_count'     => 4,
			),
			$snapshot->get_summary()->to_array()
		);
	}

	/**
	 * Same hook, different arguments: two events with different fingerprints, one hook.
	 */
	public function test_same_hook_different_args() {
		$snapshot = CronFixture::snapshot(
			CronFixture::cron(
				array(
					CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 'feed' => 1 ) ),
					CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 'feed' => 2 ) ),
				)
			)
		);

		$events = $snapshot->get_events();
		$this->assertCount( 2, $events );
		$this->assertNotSame( $events[0]->get_args_fingerprint(), $events[1]->get_args_fingerprint() );
		$this->assertFalse( $events[0]->is_same_event( $events[1] ) );
		$this->assertSame( 1, $snapshot->get_summary()->get_unique_hook_count() );
	}

	/**
	 * Same hook and arguments at several timestamps: every instance kept, ordered by timestamp.
	 *
	 * Core's wp_schedule_event() has no duplicate check, so this is real state.
	 */
	public function test_same_hook_scheduled_multiple_times() {
		$snapshot = CronFixture::snapshot(
			CronFixture::cron(
				array(
					CronFixture::recurring( self::T + 7200, 'acme_poll', 'hourly' ),
					CronFixture::recurring( self::T, 'acme_poll', 'hourly' ),
					CronFixture::recurring( self::T + 3600, 'acme_poll', 'hourly' ),
				)
			)
		);

		$events = $snapshot->get_events();
		$this->assertCount( 3, $events );
		$this->assertSame(
			array( self::T, self::T + 3600, self::T + 7200 ),
			array(
				$events[0]->get_timestamp(),
				$events[1]->get_timestamp(),
				$events[2]->get_timestamp(),
			)
		);
		$this->assertTrue( $events[0]->is_same_event( $events[2] ) );
		$this->assertSame( 3, $snapshot->get_summary()->get_event_count() );
		$this->assertSame( 1, $snapshot->get_summary()->get_unique_hook_count() );
	}

	/**
	 * Exact duplicates (only possible when entry keys do not match the arguments,
	 * e.g. written directly) are both kept, never collapsed.
	 */
	public function test_exact_duplicates_are_preserved() {
		$entry    = array(
			'schedule' => 'daily',
			'args'     => array( 1 ),
			'interval' => 86400,
		);
		$snapshot = CronFixture::snapshot(
			array(
				self::T   => array(
					'acme_dupe' => array(
						'key-one' => $entry,
						'key-two' => $entry,
					),
				),
				'version' => 2,
			)
		);

		$this->assertCount( 2, $snapshot->get_events() );
		$this->assertSame( $snapshot->get_events()[0]->to_array(), $snapshot->get_events()[1]->to_array() );
		$this->assertSame( 2, $snapshot->get_summary()->get_event_count() );
	}

	/**
	 * The entry key is ignored: identity comes from the arguments themselves.
	 */
	public function test_entry_key_is_ignored() {
		$entry = array(
			'schedule' => false,
			'args'     => array( 'x' ),
		);

		$a = CronFixture::snapshot(
			array(
				self::T   => array( 'acme' => array( 'stale-key' => $entry ) ),
				'version' => 2,
			)
		);
		$b = CronFixture::snapshot( CronFixture::cron( array( CronFixture::single( self::T, 'acme', array( 'x' ) ) ) ) );

		$this->assertEquals( $a, $b );
	}

	/**
	 * Unchanged state gives equal snapshots, whatever the input order.
	 */
	public function test_deterministic_and_order_independent() {
		$events = array(
			CronFixture::recurring( self::T, 'acme_a', 'daily', array( 2 ) ),
			CronFixture::recurring( self::T, 'acme_a', 'daily', array( 1 ) ),
			CronFixture::single( self::T + 5, 'acme_a', array( 1 ) ),
			CronFixture::single( self::T - 5, 'acme_b' ),
			CronFixture::recurring( self::T + 9, 'acme_c', 'hourly' ),
		);

		$first  = CronFixture::snapshot( CronFixture::cron( $events ) );
		$second = CronFixture::snapshot( CronFixture::cron( $events ) );

		// Same events, reversed timestamp and hook order in the raw array, version first.
		$raw      = CronFixture::cron( $events );
		$reversed = array( 'version' => 2 );
		foreach ( array_reverse( array_diff_key( $raw, array( 'version' => true ) ), true ) as $timestamp => $hooks ) {
			$reversed[ $timestamp ] = array_reverse( $hooks, true );
		}
		$third = CronFixture::snapshot( $reversed );

		$this->assertEquals( $first, $second );
		$this->assertSame( $first->to_array(), $second->to_array() );
		$this->assertSame( $first->to_array(), $third->to_array() );
		$this->assertSame( serialize( $first ), serialize( $third ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Comparing objects.
	}

	/**
	 * The `version` marker is never an event, wherever it sits in the array.
	 */
	public function test_version_marker_is_ignored() {
		$snapshot = CronFixture::snapshot(
			array(
				'version' => 2,
				self::T   => array(
					'acme' => array(
						'k' => array(
							'schedule' => false,
							'args'     => array(),
						),
					),
				),
			)
		);

		$this->assertSame( array( 'acme' ), $this->hooks( $snapshot ) );
		$this->assertSame( 1, $snapshot->get_summary()->get_event_count() );
		$this->assertStringNotContainsString( 'version', json_encode( $snapshot->to_array() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Inspecting output.
	}

	/**
	 * Arrays without the version 2 marker are not read as cron data.
	 *
	 * @dataProvider provide_unsupported_formats
	 *
	 * @param array $cron Cron option value.
	 */
	public function test_unsupported_format_fails( array $cron ) {
		try {
			CronFixture::snapshot( $cron );
			$this->fail( 'Expected MalformedCronStateException.' );
		} catch ( MalformedCronStateException $e ) {
			$this->assertSame( MalformedCronStateException::UNSUPPORTED_FORMAT, $e->get_reason() );
		}
	}

	/**
	 * Unsupported formats.
	 *
	 * @return array<string, array{array}>
	 */
	public function provide_unsupported_formats() {
		$events = array(
			self::T => array(
				'acme' => array(
					'k' => array(
						'schedule' => false,
						'args'     => array(),
					),
				),
			),
		);

		return array(
			'no version marker'  => array( $events ),
			'legacy version 1'   => array( $events + array( 'version' => 1 ) ),
			'unknown version 3'  => array( $events + array( 'version' => 3 ) ),
			'string version "2"' => array( $events + array( 'version' => '2' ) ),
		);
	}

	/**
	 * Malformed entries fail the whole snapshot with a fixed reason and a message without data.
	 *
	 * @dataProvider provide_malformed_states
	 *
	 * @param array  $cron   Cron option value.
	 * @param string $reason Expected reason.
	 */
	public function test_malformed_state_fails( array $cron, $reason ) {
		try {
			CronFixture::snapshot( $cron + array( 'version' => 2 ) );
			$this->fail( 'Expected MalformedCronStateException.' );
		} catch ( MalformedCronStateException $e ) {
			$this->assertSame( $reason, $e->get_reason() );
			$this->assertStringNotContainsString( self::FAKE_SECRET, $e->getMessage() );
			$this->assertStringNotContainsString( 'acme', $e->getMessage() );
			$this->assertNull( $e->getPrevious() );
		}
	}

	/**
	 * Malformed states. Every one carries the fake secret, which must not leak.
	 *
	 * @return array<string, array{array, string}>
	 */
	public function provide_malformed_states() {
		$secret = array( 'token' => self::FAKE_SECRET );
		$valid  = array(
			'schedule' => 'daily',
			'args'     => $secret,
			'interval' => 86400,
		);

		return array(
			'non-numeric timestamp'  => array( array( 'acme' => array( 'acme' => array( 'k' => $valid ) ) ), MalformedCronStateException::INVALID_TIMESTAMP ),
			'zero timestamp'         => array( array( 0 => array( 'acme' => array( 'k' => $valid ) ) ), MalformedCronStateException::INVALID_TIMESTAMP ),
			'negative timestamp'     => array( array( -5 => array( 'acme' => array( 'k' => $valid ) ) ), MalformedCronStateException::INVALID_TIMESTAMP ),
			'fractional timestamp'   => array( array( '1767225600.5' => array( 'acme' => array( 'k' => $valid ) ) ), MalformedCronStateException::INVALID_TIMESTAMP ),
			'hooks not an array'     => array( array( self::T => self::FAKE_SECRET ), MalformedCronStateException::INVALID_EVENT ),
			'instances not an array' => array( array( self::T => array( 'acme' => self::FAKE_SECRET ) ), MalformedCronStateException::INVALID_EVENT ),
			'event not an array'     => array( array( self::T => array( 'acme' => array( 'k' => self::FAKE_SECRET ) ) ), MalformedCronStateException::INVALID_EVENT ),
			'invalid UTF-8 hook'     => array( array( self::T => array( "acme_\xC3\x28" => array( 'k' => $valid ) ) ), MalformedCronStateException::INVALID_HOOK ),
			'schedule missing'       => array( array( self::T => array( 'acme' => array( 'k' => array( 'args' => $secret ) ) ) ), MalformedCronStateException::INVALID_SCHEDULE ),
			'schedule empty string'  => array( array( self::T => array( 'acme' => array( 'k' => array( 'schedule' => '' ) + $valid ) ) ), MalformedCronStateException::INVALID_SCHEDULE ),
			'schedule true'          => array( array( self::T => array( 'acme' => array( 'k' => array( 'schedule' => true ) + $valid ) ) ), MalformedCronStateException::INVALID_SCHEDULE ),
			'schedule null'          => array( array( self::T => array( 'acme' => array( 'k' => array( 'schedule' => null ) + $valid ) ) ), MalformedCronStateException::INVALID_SCHEDULE ),
			'schedule array'         => array( array( self::T => array( 'acme' => array( 'k' => array( 'schedule' => $secret ) + $valid ) ) ), MalformedCronStateException::INVALID_SCHEDULE ),
			'interval string'        => array( array( self::T => array( 'acme' => array( 'k' => array( 'interval' => '86400' ) + $valid ) ) ), MalformedCronStateException::INVALID_INTERVAL ),
			'interval negative'      => array( array( self::T => array( 'acme' => array( 'k' => array( 'interval' => -1 ) + $valid ) ) ), MalformedCronStateException::INVALID_INTERVAL ),
			'args missing'           => array(
				array(
					self::T => array(
						'acme' => array(
							'k' => array(
								'schedule' => false,
								'secret'   => self::FAKE_SECRET,
							),
						),
					),
				),
				MalformedCronStateException::INVALID_ARGS,
			),
			'args not an array'      => array( array( self::T => array( 'acme' => array( 'k' => array( 'args' => self::FAKE_SECRET ) + $valid ) ) ), MalformedCronStateException::INVALID_ARGS ),
			'args not serializable'  => array(
				array(
					self::T => array(
						'acme' => array(
							'k' => array(
								'args' => array(
									self::FAKE_SECRET,
									static function () {},
								),
							) + $valid,
						),
					),
				),
				MalformedCronStateException::INVALID_ARGS,
			),
			'valid then malformed'   => array(
				array(
					self::T     => array( 'acme' => array( 'k' => $valid ) ),
					self::T + 1 => array( 'acme' => array( 'k' => array( 'schedule' => 42 ) + $valid ) ),
				),
				MalformedCronStateException::INVALID_SCHEDULE,
			),
		);
	}

	/**
	 * What WordPress itself accepts is not malformed.
	 */
	public function test_lenient_cases_wordpress_accepts() {
		$snapshot = CronFixture::snapshot(
			array(
				self::T   => array(
					// Unregistered (e.g. deactivated plugin's) schedule name.
					'acme_custom' => array(
						'a' => array(
							'schedule' => 'every_five_minutes',
							'args'     => array(),
							'interval' => 300,
						),
					),
					// Recurring without a stored interval.
					'acme_legacy' => array(
						'b' => array(
							'schedule' => 'daily',
							'args'     => array(),
						),
					),
					// Numeric hook name: PHP stores the key as an integer.
					'123'         => array(
						'c' => array(
							'schedule' => false,
							'args'     => array(),
						),
					),
					// Empty hook name, which core does not reject.
					''            => array(
						'd' => array(
							'schedule' => false,
							'args'     => array(),
						),
					),
					// One-time event with a meaningless interval and an unknown extra key.
					'acme_single' => array(
						'e' => array(
							'schedule' => false,
							'args'     => array( new stdClass() ),
							'interval' => 'ignored',
							'extra'    => true,
						),
					),
				),
				'version' => 2,
			)
		);

		$events = array();
		foreach ( $snapshot->get_events() as $event ) {
			$events[ $event->get_hook() ] = $event;
		}

		$this->assertSame( array( '', '123', 'acme_custom', 'acme_legacy', 'acme_single' ), $this->hooks( $snapshot ) );
		$this->assertSame( '123', $snapshot->get_events()[1]->get_hook() );
		$this->assertSame( 'every_five_minutes', $events['acme_custom']->get_schedule() );
		$this->assertSame( 300, $events['acme_custom']->get_interval() );
		$this->assertSame( 'daily', $events['acme_legacy']->get_schedule() );
		$this->assertNull( $events['acme_legacy']->get_interval() );
		$this->assertTrue( $events['acme_legacy']->is_recurring() );
		$this->assertNull( $events['acme_single']->get_interval() );
		$this->assertFalse( $events['acme_single']->is_recurring() );
	}

	/**
	 * UTF-8 hook names are kept as-is and sorted byte-wise; UTF-8 arguments fingerprint normally.
	 */
	public function test_utf8_hooks_and_args() {
		$snapshot = CronFixture::snapshot(
			CronFixture::cron(
				array(
					CronFixture::single( self::T, "acme_\u{00E9}t\u{00E9}", array( "caf\u{00E9}" ) ),
					CronFixture::single( self::T, "acme_\u{65E5}\u{672C}" ),
					CronFixture::single( self::T, 'acme_z' ),
				)
			)
		);

		$this->assertSame( array( 'acme_z', "acme_\u{00E9}t\u{00E9}", "acme_\u{65E5}\u{672C}" ), $this->hooks( $snapshot ) );
		$this->assertSame( $this->fp( array( "caf\u{00E9}" ) ), $snapshot->get_events()[1]->get_args_fingerprint() );
		$this->assertNotFalse( json_encode( $snapshot->to_array() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Encodability check.
	}

	/**
	 * Building never modifies the input.
	 */
	public function test_input_is_not_mutated() {
		$cron = CronFixture::cron(
			array(
				CronFixture::recurring( self::T, 'acme_b', 'daily', array( 'x' => 1 ) ),
				CronFixture::single( self::T - 100, 'acme_a', array( self::FAKE_SECRET ) ),
			)
		);
		$copy = $cron;

		CronFixture::snapshot( $cron );

		$this->assertSame( $copy, $cron );
		$this->assertSame( 'version', array_keys( $cron )[2] );
	}

	/**
	 * No argument value appears in any form of the snapshot.
	 */
	public function test_privacy() {
		$snapshot = CronFixture::snapshot(
			CronFixture::cron(
				array(
					CronFixture::recurring( self::T, 'acme_webhook', 'hourly', array( 'https://hooks.example.test/' . self::FAKE_SECRET ) ),
					CronFixture::single(
						self::T + 1,
						'acme_mail',
						array(
							array(
								'to'    => 'person@example.test',
								'token' => self::FAKE_SECRET,
							),
						)
					),
				)
			)
		);

		// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Inspecting every output form.
		$outputs = array(
			'serialize( snapshot )' => serialize( $snapshot ),
			'json_encode'           => json_encode( $snapshot->to_array() ),
			'print_r( snapshot )'   => print_r( $snapshot, true ),
			'var_export'            => var_export( $snapshot->to_array(), true ),
			'serialize( summary )'  => serialize( $snapshot->get_summary() ),
			'serialize( events )'   => serialize( $snapshot->get_events() ),
			'print_r( events )'     => print_r( $snapshot->get_events(), true ),
		);
		// phpcs:enable

		foreach ( $outputs as $label => $output ) {
			foreach ( array( self::FAKE_SECRET, 'person@example.test', 'hooks.example.test', 'token', CronFixture::SECRET ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $output, $label );
			}
		}
	}

	/**
	 * A snapshot requires a fingerprint context.
	 */
	public function test_snapshot_requires_context() {
		$this->expectException( InvalidArgumentException::class );

		new CronSnapshot( array(), '' );
	}
}
