<?php
/**
 * Action Scheduler rows for tests.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Support;

use UpdateLens\Snapshot\ActionSchedulerArgsHasher;
use UpdateLens\Snapshot\ActionSchedulerSnapshot;
use UpdateLens\Snapshot\ActionSchedulerSnapshotBuilder;

/**
 * Builds `actionscheduler_actions` rows (joined with the group slug) the
 * way ActionScheduler_DBStore stores them, including PHP-serialized
 * schedules byte-identical to Action Scheduler 4.0.0's.
 */
final class ActionSchedulerFixture {

	/**
	 * Default test secret.
	 */
	const SECRET = 'test-site-secret';

	/**
	 * `max_index_length` of ActionScheduler_DBStore: longer argument JSON goes to `extended_args`.
	 */
	const MAX_ARGS_LENGTH = 191;

	/**
	 * Serialized ActionScheduler_SimpleSchedule.
	 *
	 * @param int $timestamp Scheduled time.
	 * @return string
	 */
	public static function single_schedule( $timestamp ) {
		return self::object(
			'ActionScheduler_SimpleSchedule',
			array(
				"\0*\0scheduled_timestamp" => 'i:' . $timestamp . ';',
				"\0ActionScheduler_SimpleSchedule\0timestamp" => 'i:' . $timestamp . ';',
			)
		);
	}

	/**
	 * Serialized ActionScheduler_NullSchedule (async actions).
	 *
	 * @return string
	 */
	public static function async_schedule() {
		return self::object( 'ActionScheduler_NullSchedule', array() );
	}

	/**
	 * Serialized ActionScheduler_IntervalSchedule.
	 *
	 * @param int $timestamp Scheduled time.
	 * @param int $interval  Interval in seconds.
	 * @return string
	 */
	public static function interval_schedule( $timestamp, $interval ) {
		return self::object(
			'ActionScheduler_IntervalSchedule',
			array(
				"\0*\0scheduled_timestamp" => 'i:' . $timestamp . ';',
				"\0*\0first_timestamp"     => 'i:' . $timestamp . ';',
				"\0*\0recurrence"          => 'i:' . $interval . ';',
				"\0ActionScheduler_IntervalSchedule\0start_timestamp" => 'i:' . $timestamp . ';',
				"\0ActionScheduler_IntervalSchedule\0interval_in_seconds" => 'i:' . $interval . ';',
			)
		);
	}

	/**
	 * Serialized ActionScheduler_CronSchedule with its CronExpression.
	 *
	 * `cron` is a reference to the `recurrence` object, as in Action Scheduler's own output.
	 *
	 * @param int    $timestamp  Scheduled time.
	 * @param string $expression Cron expression (5 or 6 fields).
	 * @return string
	 */
	public static function cron_schedule( $timestamp, $expression ) {
		$parts = '';
		foreach ( explode( ' ', $expression ) as $index => $part ) {
			$parts .= 'i:' . $index . ';' . self::string( $part );
		}
		$fields = array( 'CronExpression_MinutesField', 'CronExpression_HoursField', 'CronExpression_DayOfMonthField', 'CronExpression_MonthField', 'CronExpression_DayOfWeekField' );
		$items  = '';
		foreach ( $fields as $index => $field ) {
			$items .= 'i:' . $index . ';' . self::object( $field, array() );
		}
		$expression_object = self::object(
			'CronExpression',
			array(
				"\0CronExpression\0cronParts"    => 'a:' . count( explode( ' ', $expression ) ) . ':{' . $parts . '}',
				"\0CronExpression\0fieldFactory" => self::object(
					'CronExpression_FieldFactory',
					array( "\0CronExpression_FieldFactory\0fields" => 'a:' . count( $fields ) . ':{' . $items . '}' )
				),
			)
		);

		return self::object(
			'ActionScheduler_CronSchedule',
			array(
				"\0*\0scheduled_timestamp"             => 'i:' . $timestamp . ';',
				"\0*\0first_timestamp"                 => 'i:' . $timestamp . ';',
				"\0*\0recurrence"                      => $expression_object,
				"\0ActionScheduler_CronSchedule\0start_timestamp" => 'i:' . $timestamp . ';',
				"\0ActionScheduler_CronSchedule\0cron" => 'r:4;',
			)
		);
	}

	/**
	 * Serialized object of any class with pre-serialized property values.
	 *
	 * @param string                $class_name Class name.
	 * @param array<string, string> $properties Mangled property names → serialized values.
	 * @return string
	 */
	public static function object( $class_name, array $properties ) {
		$body = '';
		foreach ( $properties as $name => $value ) {
			$body .= self::string( $name ) . $value;
		}

		return 'O:' . strlen( $class_name ) . ':"' . $class_name . '":' . count( $properties ) . ':{' . $body . '}';
	}

	/**
	 * Serialized string.
	 *
	 * @param string $value String.
	 * @return string
	 */
	public static function string( $value ) {
		return 's:' . strlen( $value ) . ':"' . $value . '";';
	}

	/**
	 * Row of an active action, as the provider passes it to the builder.
	 *
	 * @param string      $hook      Hook.
	 * @param int         $timestamp Scheduled time.
	 * @param string      $schedule  Serialized schedule.
	 * @param array       $args      Arguments.
	 * @param string|null $group     Group slug (null: no group row).
	 * @param string      $status    Status.
	 * @return array<string, mixed>
	 */
	public static function row( $hook, $timestamp, $schedule, array $args = array(), $group = null, $status = 'pending' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() is not loaded in unit tests; same output for these values.
		$json = json_encode( $args );
		$long = strlen( $json ) > self::MAX_ARGS_LENGTH;

		return array(
			'hook'               => $hook,
			'status'             => $status,
			'scheduled_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'args'               => $long ? md5( $json ) : $json,
			'extended_args'      => $long ? $json : null,
			'schedule'           => $schedule,
			'group'              => $group,
		);
	}

	/**
	 * Pending one-time action.
	 *
	 * @param string      $hook      Hook.
	 * @param int         $timestamp Scheduled time.
	 * @param array       $args      Arguments.
	 * @param string|null $group     Group slug.
	 * @return array<string, mixed>
	 */
	public static function single( $hook, $timestamp, array $args = array(), $group = null ) {
		return self::row( $hook, $timestamp, self::single_schedule( $timestamp ), $args, $group );
	}

	/**
	 * Pending interval action.
	 *
	 * @param string      $hook      Hook.
	 * @param int         $timestamp Next run.
	 * @param int         $interval  Interval in seconds.
	 * @param array       $args      Arguments.
	 * @param string|null $group     Group slug.
	 * @return array<string, mixed>
	 */
	public static function recurring( $hook, $timestamp, $interval, array $args = array(), $group = null ) {
		return self::row( $hook, $timestamp, self::interval_schedule( $timestamp, $interval ), $args, $group );
	}

	/**
	 * Pending cron action.
	 *
	 * @param string      $hook       Hook.
	 * @param int         $timestamp  Next run.
	 * @param string      $expression Cron expression.
	 * @param array       $args       Arguments.
	 * @param string|null $group      Group slug.
	 * @return array<string, mixed>
	 */
	public static function cron( $hook, $timestamp, $expression, array $args = array(), $group = null ) {
		return self::row( $hook, $timestamp, self::cron_schedule( $timestamp, $expression ), $args, $group );
	}

	/**
	 * Pending async action.
	 *
	 * @param string      $hook      Hook.
	 * @param int         $timestamp Enqueue time (Action Scheduler stores "now").
	 * @param array       $args      Arguments.
	 * @param string|null $group     Group slug.
	 * @return array<string, mixed>
	 */
	public static function async( $hook, $timestamp, array $args = array(), $group = null ) {
		return self::row( $hook, $timestamp, self::async_schedule(), $args, $group );
	}

	/**
	 * Snapshot of rows.
	 *
	 * @param iterable $rows   Rows.
	 * @param string   $secret Site secret.
	 * @return ActionSchedulerSnapshot
	 */
	public static function snapshot( $rows, $secret = self::SECRET ) {
		return ( new ActionSchedulerSnapshotBuilder( new ActionSchedulerArgsHasher( $secret ) ) )->build( $rows );
	}
}
