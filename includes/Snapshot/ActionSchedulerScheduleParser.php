<?php
/**
 * Normalizes Action Scheduler's serialized schedule objects.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the `schedule` column of an Action Scheduler action into safe fields.
 *
 * Action Scheduler stores schedules as PHP-serialized objects. UpdateLens
 * never instantiates them: the string is decoded with
 * `unserialize( …, [ 'allowed_classes' => false ] )`, which turns every
 * object into an inert `__PHP_Incomplete_Class` (no constructor, no
 * `__wakeup()`, no autoloading), and only plain property values are read.
 * The length and nesting depth are bounded first.
 *
 * Supported classes, with the property names of Action Scheduler 3.1.6 to
 * 4.2.0 and their legacy aliases (the ones the classes' `__wakeup()` reads):
 *
 * - `ActionScheduler_SimpleSchedule` → single;
 * - `ActionScheduler_NullSchedule` (as_enqueue_async_action()) → async;
 * - `ActionScheduler_IntervalSchedule` → interval, seconds from `recurrence`
 *   (or legacy `interval_in_seconds`);
 * - `ActionScheduler_CronSchedule` → cron, the expression from the
 *   `CronExpression` in `recurrence` (or legacy `cron`), its fields joined
 *   with single spaces.
 *
 * The scheduled time is not read from here: the `scheduled_date_gmt`
 * column is what Action Scheduler runs actions by. Other classes (custom
 * schedules, or `ActionScheduler_CanceledSchedule` on an active action) are
 * unsupported rather than guessed.
 */
final class ActionSchedulerScheduleParser {

	/**
	 * Longest schedule string accepted, in bytes. Real schedules are well under 2 KB.
	 */
	const MAX_LENGTH = 65536;

	/**
	 * Deepest nesting accepted (a cron schedule nests about 5 levels).
	 */
	const MAX_DEPTH = 16;

	/**
	 * Supported schedule classes and their types.
	 */
	const CLASS_TYPES = array(
		'ActionScheduler_SimpleSchedule'   => ActionSchedulerActionRecord::TYPE_SINGLE,
		'ActionScheduler_NullSchedule'     => ActionSchedulerActionRecord::TYPE_ASYNC,
		'ActionScheduler_IntervalSchedule' => ActionSchedulerActionRecord::TYPE_INTERVAL,
		'ActionScheduler_CronSchedule'     => ActionSchedulerActionRecord::TYPE_CRON,
	);

	/**
	 * Characters of a cron expression field (digits, names like MON/JAN, `* , / - ? L W #`).
	 */
	const CRON_FIELD_PATTERN = '/\A[0-9A-Za-z*,\/?#-]+\z/';

	/**
	 * Normalize a serialized schedule.
	 *
	 * @param mixed $serialized Value of the `schedule` column.
	 * @return array{type: string, interval: int|null, cron_expression: string|null}
	 * @throws MalformedActionSchedulerStateException If the schedule cannot be read or is not supported.
	 */
	public static function parse( $serialized ) {
		if ( ! is_string( $serialized ) || '' === $serialized || strlen( $serialized ) > self::MAX_LENGTH ) {
			throw MalformedActionSchedulerStateException::invalid_schedule();
		}
		if ( 1 !== preg_match( '/\AO:\d+:"([^"]+)":\d+:\{/', $serialized, $match ) ) {
			throw MalformedActionSchedulerStateException::invalid_schedule();
		}
		$class_name = $match[1];
		if ( ! isset( self::CLASS_TYPES[ $class_name ] ) ) {
			throw MalformedActionSchedulerStateException::unsupported_schedule();
		}

		$properties = self::properties( self::decode( $serialized ), $class_name );
		$type       = self::CLASS_TYPES[ $class_name ];
		$interval   = null;
		$expression = null;

		if ( ActionSchedulerActionRecord::TYPE_INTERVAL === $type ) {
			$interval = self::interval( self::property( $properties, "\0*\0recurrence", "\0{$class_name}\0interval_in_seconds" ) );
		} elseif ( ActionSchedulerActionRecord::TYPE_CRON === $type ) {
			$expression = self::cron_expression( self::property( $properties, "\0*\0recurrence", "\0{$class_name}\0cron" ) );
		}

		return array(
			'type'            => $type,
			'interval'        => $interval,
			'cron_expression' => $expression,
		);
	}

	/**
	 * Decode without instantiating any class.
	 *
	 * @param string $serialized Serialized schedule.
	 * @return mixed Decoded value; objects are __PHP_Incomplete_Class.
	 * @throws MalformedActionSchedulerStateException If the string does not decode.
	 */
	private static function decode( $serialized ) {
		// unserialize() reports syntax errors as notices/warnings; the result (false) is what counts.
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Silences unserialize() notices for this one call.
			static function () {
				return true;
			}
		);
		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- No classes are instantiated (allowed_classes false); length and depth are bounded.
			$value = unserialize(
				$serialized,
				array(
					'allowed_classes' => false,
					'max_depth'       => self::MAX_DEPTH,
				)
			);
		} finally {
			restore_error_handler();
		}

		if ( false === $value ) {
			throw MalformedActionSchedulerStateException::invalid_schedule();
		}

		return $value;
	}

	/**
	 * Raw properties of a decoded object of the expected class.
	 *
	 * @param mixed  $value      Decoded value.
	 * @param string $class_name Expected class name.
	 * @return array<string, mixed> Property values keyed by their serialized (mangled) names.
	 * @throws MalformedActionSchedulerStateException If the value is not an object of that class.
	 */
	private static function properties( $value, $class_name ) {
		if ( ! $value instanceof \__PHP_Incomplete_Class ) {
			throw MalformedActionSchedulerStateException::invalid_schedule();
		}
		$properties = (array) $value;
		if ( ! isset( $properties['__PHP_Incomplete_Class_Name'] ) || $class_name !== $properties['__PHP_Incomplete_Class_Name'] ) {
			throw MalformedActionSchedulerStateException::invalid_schedule();
		}

		return $properties;
	}

	/**
	 * A property, or its legacy alias when the property is missing or null.
	 *
	 * @param array<string, mixed> $properties Properties.
	 * @param string               $name       Property name (mangled).
	 * @param string               $legacy     Legacy property name (mangled).
	 * @return mixed
	 */
	private static function property( array $properties, $name, $legacy ) {
		if ( isset( $properties[ $name ] ) ) {
			return $properties[ $name ];
		}

		return isset( $properties[ $legacy ] ) ? $properties[ $legacy ] : null;
	}

	/**
	 * Interval in seconds.
	 *
	 * Action Scheduler casts it to an integer since 3.x; older versions stored
	 * what was passed, so a string of digits is accepted too.
	 *
	 * @param mixed $value Stored recurrence.
	 * @return int
	 * @throws MalformedActionSchedulerStateException If it is not an integer.
	 */
	private static function interval( $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/\A-?[0-9]{1,18}\z/', $value ) ) {
			return (int) $value;
		}

		throw MalformedActionSchedulerStateException::invalid_schedule();
	}

	/**
	 * Cron expression, fields joined with single spaces.
	 *
	 * @param mixed $value Stored recurrence: a CronExpression object or an expression string.
	 * @return string
	 * @throws MalformedActionSchedulerStateException If it is not a 5- or 6-field cron expression.
	 */
	private static function cron_expression( $value ) {
		if ( is_string( $value ) ) {
			$parts = preg_split( '/\s+/', trim( $value ) );
		} elseif ( $value instanceof \__PHP_Incomplete_Class ) {
			$properties = (array) $value;
			$name       = "\0CronExpression\0cronParts";
			if ( ! isset( $properties['__PHP_Incomplete_Class_Name'], $properties[ $name ] ) || 'CronExpression' !== $properties['__PHP_Incomplete_Class_Name'] ) {
				throw MalformedActionSchedulerStateException::invalid_schedule();
			}
			$parts = $properties[ $name ];
		} else {
			throw MalformedActionSchedulerStateException::invalid_schedule();
		}

		if ( ! is_array( $parts ) || count( $parts ) < 5 || count( $parts ) > 6 ) {
			throw MalformedActionSchedulerStateException::invalid_schedule();
		}
		foreach ( $parts as $part ) {
			if ( ! is_string( $part ) || 1 !== preg_match( self::CRON_FIELD_PATTERN, $part ) ) {
				throw MalformedActionSchedulerStateException::invalid_schedule();
			}
		}

		return implode( ' ', $parts );
	}
}
