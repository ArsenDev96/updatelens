<?php
/**
 * Whether an observation fits in the request's memory.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

use Throwable;
use UpdateLens\Snapshot\ActionSchedulerSnapshotProvider;
use UpdateLens\Snapshot\MemoryBudget;
use UpdateLens\Snapshot\OptionsSnapshotProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Preflight before each observation stage: estimates the memory of the
 * options, WP-Cron and Action Scheduler work of the stage from cheap
 * metadata and asks the MemoryBudget, before anything large is allocated
 * (plan()). Nothing large is read to decide: the options are counted, the
 * stored `cron` option is measured with SQL (`LENGTH()`), and the active
 * actions are counted.
 *
 * Options decide whether the observation happens at all (see
 * PluginUpdateAnalyzer); WP-Cron and Action Scheduler are independent
 * signals, each kept only if it fits on top of what was already planned, in
 * that order. A signal that does not fit is not captured (its phases are
 * unavailable); the plugin update itself is never affected.
 *
 * Each stage needs memory for the records it builds, decodes, compares,
 * encodes and stores (the peak above the request's usage measured on real
 * updates on PHP 7.4 and 8.1, plus about a third), plus a capture's working
 * set (one batch of rows read through $wpdb). The estimates of the signals
 * are added up although their peaks do not coincide: conservative. Large
 * option values are also checked one by one while capturing, against the
 * real usage.
 *
 * A BEFORE observation is planned with the most expensive stage (SETTLED),
 * so a signal is not begun that cannot be completed in a request like this
 * one.
 */
final class ObservationBudget {

	const BEFORE    = 'before';
	const IMMEDIATE = 'immediate';
	const SETTLED   = 'settled';

	/**
	 * Signals of a plan.
	 */
	const OPTIONS          = 'options';
	const CRON             = 'cron';
	const ACTION_SCHEDULER = 'action_scheduler';

	/**
	 * Memory each stage needs per option, in bytes.
	 *
	 * - BEFORE: capture, encode, store.
	 * - IMMEDIATE: read and decode the BEFORE snapshot, capture, compare,
	 *   encode and store.
	 * - SETTLED: read and decode the BEFORE and IMMEDIATE snapshots (one at a
	 *   time), capture, compare twice, store.
	 *
	 * Measured with every record changed during the update (the largest
	 * diffs; with nothing changed: 1,713 and 1,896 per option).
	 */
	const BYTES_PER_OPTION = array(
		self::BEFORE    => 1792, // Measured 1,330 (PHP 8.1) / 1,333 (PHP 7.4).
		self::IMMEDIATE => 3712, // Measured 2,758 / 2,758.
		self::SETTLED   => 3968, // Measured 2,324 / 2,957.
	);

	/**
	 * The options capture's working set, in bytes: one batch of small values
	 * read through $wpdb and its records.
	 */
	const CAPTURE_BYTES = OptionsSnapshotProvider::VALUE_READ_FACTOR * OptionsSnapshotProvider::BATCH_SIZE * OptionsSnapshotProvider::INLINE_VALUE_BYTES + OptionsSnapshotProvider::BATCH_SIZE * OptionsSnapshotProvider::RECORD_BYTES;

	/**
	 * Memory each stage needs per byte of the stored `cron` option (the
	 * unserialized array WordPress returns, the event records, the stored
	 * snapshots decoded one at a time, the diffs), in bytes. Same stages and
	 * measurements as BYTES_PER_OPTION (every event rescheduled; IMMEDIATE is
	 * an upper bound: the update's own rewrite of the option peaked higher).
	 */
	const BYTES_PER_CRON_BYTE = array(
		self::BEFORE    => 16, // Measured 11.6 (PHP 8.1) / 11.0 (PHP 7.4).
		self::IMMEDIATE => 32, // Measured at most 23.8 / 22.5.
		self::SETTLED   => 32, // Measured 23.2 / 23.1.
	);

	/**
	 * Memory each stage needs per active (pending or in-progress) Action
	 * Scheduler action, in bytes. Same stages and measurements as
	 * BYTES_PER_OPTION (every action rescheduled).
	 */
	const BYTES_PER_ACTION = array(
		self::BEFORE    => 2688, // Measured 1,999 (PHP 8.1) / 2,003 (PHP 7.4).
		self::IMMEDIATE => 4544, // Measured 3,313 / 3,314.
		self::SETTLED   => 4800, // Measured 3,541 / 3,541.
	);

	/**
	 * The Action Scheduler capture's working set per row of a batch, in
	 * bytes: a row of at most MAX_ROW_BYTES read through $wpdb. A batch holds
	 * at most BATCH_SIZE rows (fewer when there are fewer actions).
	 */
	const ACTION_READ_BYTES = OptionsSnapshotProvider::VALUE_READ_FACTOR * ActionSchedulerSnapshotProvider::MAX_ROW_BYTES;

	/**
	 * Memory available to the request.
	 *
	 * @var MemoryBudget
	 */
	private $memory;

	/**
	 * Returns the number of options a snapshot may keep (an upper bound).
	 *
	 * @var callable
	 */
	private $count_options;

	/**
	 * Returns the stored size of the `cron` option in bytes.
	 *
	 * @var callable|null
	 */
	private $cron_bytes;

	/**
	 * Returns the number of active Action Scheduler actions (0 when Action
	 * Scheduler is not available to snapshots).
	 *
	 * @var callable|null
	 */
	private $count_actions;

	/**
	 * Constructor.
	 *
	 * @param MemoryBudget  $memory        Memory available to the request.
	 * @param callable      $count_options Returns the number of options a snapshot may keep.
	 * @param callable|null $cron_bytes    Returns the stored size of the `cron` option. Null: no WP-Cron observation.
	 * @param callable|null $count_actions Returns the number of active actions. Null: no Action Scheduler observation.
	 */
	public function __construct( MemoryBudget $memory, callable $count_options, ?callable $cron_bytes = null, ?callable $count_actions = null ) {
		$this->memory        = $memory;
		$this->count_options = $count_options;
		$this->cron_bytes    = $cron_bytes;
		$this->count_actions = $count_actions;
	}

	/**
	 * Which signals a stage may observe: OPTIONS, CRON and ACTION_SCHEDULER
	 * => bool. Options first (if they do not fit, nothing does), then WP-Cron
	 * and Action Scheduler, each only if it fits together with what was
	 * already planned. With no memory limit, everything fits (and nothing is
	 * counted). Whatever cannot be measured does not fit: an estimate is
	 * required.
	 *
	 * @param string $stage BEFORE, IMMEDIATE or SETTLED.
	 * @return array<string, bool>
	 */
	public function plan( $stage ) {
		$plan = array(
			self::OPTIONS          => true,
			self::CRON             => true,
			self::ACTION_SCHEDULER => true,
		);
		if ( ! $this->memory->is_limited() ) {
			return $plan;
		}
		// BEFORE begins the whole observation.
		$stage = isset( self::BYTES_PER_OPTION[ $stage ] ) && self::BEFORE !== $stage ? $stage : self::SETTLED;

		$options = self::measure( $this->count_options );
		$needed  = self::CAPTURE_BYTES + (float) $options * self::BYTES_PER_OPTION[ $stage ];
		if ( null === $options || ! $this->memory->allows( $needed ) ) {
			return array_fill_keys( array_keys( $plan ), false );
		}

		$cron_bytes = self::measure( $this->cron_bytes );
		$actions    = self::measure( $this->count_actions );
		$signals    = array(
			self::CRON             => null === $cron_bytes ? null : (float) $cron_bytes * self::BYTES_PER_CRON_BYTE[ $stage ],
			self::ACTION_SCHEDULER => null === $actions ? null : (float) $actions * self::BYTES_PER_ACTION[ $stage ] + min( $actions, ActionSchedulerSnapshotProvider::BATCH_SIZE ) * self::ACTION_READ_BYTES,
		);
		foreach ( $signals as $signal => $more ) {
			$plan[ $signal ] = null !== $more && $this->memory->allows( $needed + $more );
			if ( $plan[ $signal ] ) {
				$needed += $more;
			}
		}

		return $plan;
	}

	/**
	 * Whether a stage may observe the options (and so begin or continue the
	 * observation at all).
	 *
	 * @param string $stage BEFORE, IMMEDIATE or SETTLED.
	 * @return bool
	 */
	public function fits( $stage ) {
		return $this->plan( $stage )[ self::OPTIONS ];
	}

	/**
	 * A signal's size (options, bytes or actions).
	 *
	 * @param callable|null $measure Returns the size; null when the signal is not observed.
	 * @return int|null Null when it cannot be measured.
	 */
	private static function measure( $measure ) {
		if ( null === $measure ) {
			return null;
		}
		try {
			return max( 0, (int) call_user_func( $measure ) );
		} catch ( Throwable $e ) {
			return null;
		}
	}
}
