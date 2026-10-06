<?php
/**
 * WP-Cron arrays for tests.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Support;

use UpdateLens\Snapshot\CronArgsHasher;
use UpdateLens\Snapshot\CronSnapshot;
use UpdateLens\Snapshot\CronSnapshotBuilder;

/**
 * Builds `cron` option values in WordPress's version 2 format, the way
 * core's wp_schedule_event() / wp_schedule_single_event() store them.
 */
final class CronFixture {

	/**
	 * Default test secret.
	 */
	const SECRET = 'test-site-secret';

	/**
	 * One-time event.
	 *
	 * @param int    $timestamp Timestamp.
	 * @param string $hook      Hook.
	 * @param array  $args      Arguments.
	 * @return array{int, string, array, false, null}
	 */
	public static function single( $timestamp, $hook, array $args = array() ) {
		return array( $timestamp, $hook, $args, false, null );
	}

	/**
	 * Recurring event, with core's interval for the built-in schedules.
	 *
	 * @param int      $timestamp Timestamp.
	 * @param string   $hook      Hook.
	 * @param string   $schedule  Schedule name.
	 * @param array    $args      Arguments.
	 * @param int|null $interval  Interval; defaults to the built-in schedule's.
	 * @return array{int, string, array, string, int|null}
	 */
	public static function recurring( $timestamp, $hook, $schedule, array $args = array(), $interval = null ) {
		$intervals = array(
			'hourly'     => 3600,
			'twicedaily' => 43200,
			'daily'      => 86400,
			'weekly'     => 604800,
		);
		if ( null === $interval && isset( $intervals[ $schedule ] ) ) {
			$interval = $intervals[ $schedule ];
		}

		return array( $timestamp, $hook, $args, $schedule, $interval );
	}

	/**
	 * Cron option value from events.
	 *
	 * @param array<int, array{int, string, array, string|false, int|null}> $events Events from single()/recurring().
	 * @return array
	 */
	public static function cron( array $events ) {
		$cron = array();
		foreach ( $events as $event ) {
			list( $timestamp, $hook, $args, $schedule, $interval ) = $event;

			$entry = array(
				'schedule' => $schedule,
				'args'     => $args,
			);
			if ( false !== $schedule ) {
				$entry['interval'] = $interval;
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Mirrors core's event key.
			$cron[ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] = $entry;
		}
		uksort( $cron, 'strnatcasecmp' );
		$cron['version'] = 2;

		return $cron;
	}

	/**
	 * Snapshot of a cron option value.
	 *
	 * @param mixed  $cron   Cron option value.
	 * @param string $secret Hasher secret.
	 * @return CronSnapshot
	 */
	public static function snapshot( $cron, $secret = self::SECRET ) {
		return ( new CronSnapshotBuilder( new CronArgsHasher( $secret ) ) )->build( $cron );
	}
}
