<?php
/**
 * Safe representation of the WP-Cron state.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled event records plus their summary and fingerprint context.
 *
 * Records are a sorted list, not a map: several instances may share a hook,
 * or even every field. Order: hook (byte-wise), arguments fingerprint,
 * timestamp, schedule (one-time first), interval. Contains no arguments and
 * no capture time, so two snapshots of unchanged cron state are equal.
 */
final class CronSnapshot {

	/**
	 * Fingerprint context the arguments were hashed under (CronArgsHasher::get_context()).
	 *
	 * @var string
	 */
	private $fingerprint_context;

	/**
	 * Event records in snapshot order.
	 *
	 * @var CronEventRecord[]
	 */
	private $events;

	/**
	 * Summary over the records.
	 *
	 * @var CronSummary
	 */
	private $summary;

	/**
	 * Constructor.
	 *
	 * @param CronEventRecord[] $events              Records in any order.
	 * @param string            $fingerprint_context Context the fingerprints were made under.
	 * @throws InvalidArgumentException If the context is empty.
	 */
	public function __construct( array $events, $fingerprint_context ) {
		if ( ! is_string( $fingerprint_context ) || '' === $fingerprint_context ) {
			throw new InvalidArgumentException( 'CronSnapshot requires a fingerprint context.' );
		}
		$this->fingerprint_context = $fingerprint_context;

		$events = array_values( $events );
		usort(
			$events,
			static function ( CronEventRecord $a, CronEventRecord $b ) {
				return CronEventOrder::compare( self::sort_fields( $a ), self::sort_fields( $b ) );
			}
		);
		$this->events = $events;

		$this->summary = new CronSummary( $this->events );
	}

	/**
	 * Fields in sort order.
	 *
	 * @param CronEventRecord $event Record.
	 * @return array<int, string|int|null>
	 */
	private static function sort_fields( CronEventRecord $event ) {
		return array(
			$event->get_hook(),
			$event->get_args_fingerprint(),
			$event->get_timestamp(),
			$event->get_schedule(),
			$event->get_interval(),
		);
	}

	/**
	 * All event records in snapshot order.
	 *
	 * @return CronEventRecord[]
	 */
	public function get_events() {
		return $this->events;
	}

	/**
	 * Summary over the records.
	 *
	 * @return CronSummary
	 */
	public function get_summary() {
		return $this->summary;
	}

	/**
	 * Fingerprint context the arguments were hashed under.
	 *
	 * @return string
	 */
	public function get_fingerprint_context() {
		return $this->fingerprint_context;
	}

	/**
	 * Array form.
	 *
	 * @return array{fingerprint_context: string, events: array<int, array>, summary: array}
	 */
	public function to_array() {
		$events = array();
		foreach ( $this->events as $event ) {
			$events[] = $event->to_array();
		}

		return array(
			'fingerprint_context' => $this->fingerprint_context,
			'events'              => $events,
			'summary'             => $this->summary->to_array(),
		);
	}
}
