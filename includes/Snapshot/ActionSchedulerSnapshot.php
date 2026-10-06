<?php
/**
 * Safe representation of the active Action Scheduler state.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Active action records plus their summary and fingerprint context.
 *
 * Records are a sorted list, not a map: several actions may share every
 * field. Order: hook, group (byte-wise), arguments fingerprint, timestamp,
 * schedule type, interval, cron expression, status (compared with
 * CronEventOrder's null-first, byte-wise rules). Contains no arguments,
 * database IDs or capture time, so two snapshots of unchanged state are
 * equal.
 */
final class ActionSchedulerSnapshot {

	/**
	 * Fingerprint context the arguments were hashed under (ActionSchedulerArgsHasher::get_context()).
	 *
	 * @var string
	 */
	private $fingerprint_context;

	/**
	 * Action records in snapshot order.
	 *
	 * @var ActionSchedulerActionRecord[]
	 */
	private $actions;

	/**
	 * Summary over the records.
	 *
	 * @var ActionSchedulerSummary
	 */
	private $summary;

	/**
	 * Constructor.
	 *
	 * @param ActionSchedulerActionRecord[] $actions             Records in any order.
	 * @param string                        $fingerprint_context Context the fingerprints were made under.
	 * @throws InvalidArgumentException If the context is empty.
	 */
	public function __construct( array $actions, $fingerprint_context ) {
		if ( ! is_string( $fingerprint_context ) || '' === $fingerprint_context ) {
			throw new InvalidArgumentException( 'ActionSchedulerSnapshot requires a fingerprint context.' );
		}
		$this->fingerprint_context = $fingerprint_context;

		$actions = array_values( $actions );
		usort(
			$actions,
			static function ( ActionSchedulerActionRecord $a, ActionSchedulerActionRecord $b ) {
				return CronEventOrder::compare( self::sort_fields( $a ), self::sort_fields( $b ) );
			}
		);
		$this->actions = $actions;

		$this->summary = new ActionSchedulerSummary( $this->actions );
	}

	/**
	 * Fields in sort order.
	 *
	 * @param ActionSchedulerActionRecord $action Record.
	 * @return array<int, string|int|null>
	 */
	private static function sort_fields( ActionSchedulerActionRecord $action ) {
		return array(
			$action->get_hook(),
			$action->get_group(),
			$action->get_args_fingerprint(),
			$action->get_timestamp(),
			$action->get_schedule_type(),
			$action->get_interval(),
			$action->get_cron_expression(),
			$action->get_status(),
		);
	}

	/**
	 * All action records in snapshot order.
	 *
	 * @return ActionSchedulerActionRecord[]
	 */
	public function get_actions() {
		return $this->actions;
	}

	/**
	 * Summary over the records.
	 *
	 * @return ActionSchedulerSummary
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
	 * @return array{fingerprint_context: string, actions: array<int, array>, summary: array}
	 */
	public function to_array() {
		$actions = array();
		foreach ( $this->actions as $action ) {
			$actions[] = $action->to_array();
		}

		return array(
			'fingerprint_context' => $this->fingerprint_context,
			'actions'             => $actions,
			'summary'             => $this->summary->to_array(),
		);
	}
}
