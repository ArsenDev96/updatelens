<?php
/**
 * Builds a cron snapshot from the raw `cron` option.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Turns WordPress's cron array into a CronSnapshot.
 *
 * Pure: no WordPress access, so it is unit-tested directly. Arguments are
 * only fingerprinted here; they never leave this class. The input is never
 * modified.
 *
 * Expected input (WordPress's version 2 format):
 *
 *     [ 'version' => 2, <timestamp> => [ <hook> => [ <key> => [
 *         'schedule' => string|false, 'args' => array, 'interval' => int (recurring only)
 *     ] ] ] ]
 *
 * `<key>` is WordPress's `md5( serialize( $args ) )`; it is ignored (not
 * stored, not trusted) and the arguments are fingerprinted instead.
 *
 * Malformed state fails the whole snapshot with MalformedCronStateException
 * rather than skipping entries: a snapshot that silently leaves events out
 * would report them as added or removed when it changes. What WordPress
 * itself accepts is not malformed: a non-array option (WordPress sees no
 * events), empty timestamp or hook entries, unregistered schedule names,
 * recurring events without a stored interval, empty or numeric hook names.
 */
final class CronSnapshotBuilder {

	/**
	 * The cron array format this builder reads.
	 */
	const SUPPORTED_VERSION = 2;

	/**
	 * Argument fingerprinting.
	 *
	 * @var CronArgsHasher
	 */
	private $hasher;

	/**
	 * Constructor.
	 *
	 * @param CronArgsHasher $hasher Argument fingerprinting.
	 */
	public function __construct( CronArgsHasher $hasher ) {
		$this->hasher = $hasher;
	}

	/**
	 * Build a snapshot.
	 *
	 * @param mixed $cron Value of the `cron` option as WordPress returns it.
	 * @return CronSnapshot
	 * @throws MalformedCronStateException If the state is not valid cron data.
	 */
	public function build( $cron ) {
		$events = array();

		// Like core's _get_cron_array(): anything but an array means no events.
		if ( is_array( $cron ) && array() !== $cron ) {
			if ( ! isset( $cron['version'] ) || self::SUPPORTED_VERSION !== $cron['version'] ) {
				throw MalformedCronStateException::unsupported_format();
			}

			foreach ( $cron as $timestamp => $hooks ) {
				if ( 'version' === $timestamp ) {
					continue; // Format marker, not an event.
				}

				if ( ! is_int( $timestamp ) || $timestamp <= 0 ) {
					throw MalformedCronStateException::invalid_timestamp();
				}
				if ( ! is_array( $hooks ) ) {
					throw MalformedCronStateException::invalid_event();
				}

				foreach ( $hooks as $hook => $instances ) {
					// PHP turns numeric-string keys into integers; hook names are strings.
					$hook = (string) $hook;
					if ( 1 !== preg_match( '//u', $hook ) ) {
						throw MalformedCronStateException::invalid_hook();
					}
					if ( ! is_array( $instances ) ) {
						throw MalformedCronStateException::invalid_event();
					}

					foreach ( $instances as $event ) {
						$events[] = $this->record( $hook, $timestamp, $event );
					}
				}
			}
		}

		return new CronSnapshot( $events, $this->hasher->get_context() );
	}

	/**
	 * Record for one event entry.
	 *
	 * @param string $hook      Hook name.
	 * @param int    $timestamp Timestamp key.
	 * @param mixed  $event     Event entry.
	 * @return CronEventRecord
	 * @throws MalformedCronStateException If the entry is not a valid event.
	 */
	private function record( $hook, $timestamp, $event ) {
		if ( ! is_array( $event ) ) {
			throw MalformedCronStateException::invalid_event();
		}

		if ( ! array_key_exists( 'schedule', $event ) ) {
			throw MalformedCronStateException::invalid_schedule();
		}
		$schedule = $event['schedule'];
		if ( false === $schedule ) {
			$schedule = null; // One-time event.
		} elseif ( ! is_string( $schedule ) || '' === $schedule ) {
			throw MalformedCronStateException::invalid_schedule();
		}

		// Only recurring events carry an interval; WordPress ignores it otherwise.
		$interval = null;
		if ( null !== $schedule && isset( $event['interval'] ) ) {
			if ( ! is_int( $event['interval'] ) || $event['interval'] < 0 ) {
				throw MalformedCronStateException::invalid_interval();
			}
			$interval = $event['interval'];
		}

		if ( ! isset( $event['args'] ) || ! is_array( $event['args'] ) ) {
			throw MalformedCronStateException::invalid_args();
		}
		try {
			$fingerprint = $this->hasher->fingerprint( $event['args'] );
		} catch ( Throwable $e ) {
			// E.g. an unserializable object. The original exception is dropped: it may describe the data.
			throw MalformedCronStateException::invalid_args();
		}

		return new CronEventRecord( $hook, $timestamp, $schedule, $interval, $fingerprint );
	}
}
