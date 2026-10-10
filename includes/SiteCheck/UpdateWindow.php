<?php
/**
 * The update a site check comparison is about.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * When the update began and ended (server times, Unix seconds), and whether
 * other updates interfered: `queued` when it ran as one of several updates
 * in a row, `overlapping` when another update is known to have run while a
 * BEFORE or AFTER check was in flight.
 */
final class UpdateWindow {

	/**
	 * Update began (BEFORE capture).
	 *
	 * @var float
	 */
	private $started_at;

	/**
	 * Update ended (settled; AFTER checks start from here).
	 *
	 * @var float
	 */
	private $ended_at;

	/**
	 * Part of a queue of updates.
	 *
	 * @var bool
	 */
	private $queued;

	/**
	 * Another update overlapped a check.
	 *
	 * @var bool
	 */
	private $overlapping;

	/**
	 * Constructor.
	 *
	 * @param float|int $started_at  Update began (Unix seconds).
	 * @param float|int $ended_at    Update ended (Unix seconds), not before it began.
	 * @param bool      $queued      Part of a queue of updates.
	 * @param bool      $overlapping Another update overlapped a check.
	 * @throws InvalidArgumentException If a value is invalid.
	 */
	public function __construct( $started_at, $ended_at, $queued = false, $overlapping = false ) {
		if ( ! CheckResult::is_timestamp( $started_at ) || ! CheckResult::is_timestamp( $ended_at ) || $ended_at < $started_at || ! is_bool( $queued ) || ! is_bool( $overlapping ) ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens update window.' );
		}

		$this->started_at  = (float) $started_at;
		$this->ended_at    = (float) $ended_at;
		$this->queued      = $queued;
		$this->overlapping = $overlapping;
	}

	/**
	 * Update began.
	 *
	 * @return float
	 */
	public function get_started_at() {
		return $this->started_at;
	}

	/**
	 * Update ended.
	 *
	 * @return float
	 */
	public function get_ended_at() {
		return $this->ended_at;
	}

	/**
	 * Part of a queue of updates.
	 *
	 * @return bool
	 */
	public function is_queued() {
		return $this->queued;
	}

	/**
	 * Another update overlapped a check.
	 *
	 * @return bool
	 */
	public function is_overlapping() {
		return $this->overlapping;
	}
}
