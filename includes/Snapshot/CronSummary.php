<?php
/**
 * Totals for a WP-Cron snapshot.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Event counts over the scheduled events in a cron snapshot.
 */
final class CronSummary {

	/**
	 * Number of scheduled event instances.
	 *
	 * @var int
	 */
	private $event_count = 0;

	/**
	 * Number of recurring event instances.
	 *
	 * @var int
	 */
	private $recurring_event_count = 0;

	/**
	 * Number of one-time event instances.
	 *
	 * @var int
	 */
	private $single_event_count = 0;

	/**
	 * Number of distinct hook names.
	 *
	 * @var int
	 */
	private $unique_hook_count = 0;

	/**
	 * Constructor.
	 *
	 * @param CronEventRecord[] $events Event records.
	 */
	public function __construct( array $events ) {
		$hooks = array();

		foreach ( $events as $event ) {
			++$this->event_count;

			if ( $event->is_recurring() ) {
				++$this->recurring_event_count;
			} else {
				++$this->single_event_count;
			}

			// Prefix so numeric hook names stay distinct string keys.
			$hooks[ 'h:' . $event->get_hook() ] = true;
		}

		$this->unique_hook_count = count( $hooks );
	}

	/**
	 * Number of scheduled event instances.
	 *
	 * @return int
	 */
	public function get_event_count() {
		return $this->event_count;
	}

	/**
	 * Number of recurring event instances.
	 *
	 * @return int
	 */
	public function get_recurring_event_count() {
		return $this->recurring_event_count;
	}

	/**
	 * Number of one-time event instances.
	 *
	 * @return int
	 */
	public function get_single_event_count() {
		return $this->single_event_count;
	}

	/**
	 * Number of distinct hook names.
	 *
	 * @return int
	 */
	public function get_unique_hook_count() {
		return $this->unique_hook_count;
	}

	/**
	 * Array form.
	 *
	 * @return array{event_count: int, recurring_event_count: int, single_event_count: int, unique_hook_count: int}
	 */
	public function to_array() {
		return array(
			'event_count'           => $this->event_count,
			'recurring_event_count' => $this->recurring_event_count,
			'single_event_count'    => $this->single_event_count,
			'unique_hook_count'     => $this->unique_hook_count,
		);
	}
}
