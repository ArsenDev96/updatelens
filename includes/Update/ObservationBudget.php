<?php
/**
 * Whether an options observation fits in the request's memory.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

use Throwable;
use UpdateLens\Snapshot\MemoryBudget;
use UpdateLens\Snapshot\OptionsSnapshotProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Preflight before an options observation: estimates its memory from the
 * number of options and asks the MemoryBudget, before anything large is
 * allocated. An observation that does not fit is skipped (see
 * PluginUpdateAnalyzer); the plugin update itself is never affected.
 *
 * Each stage needs memory per option for the records it builds, decodes,
 * compares and encodes (BYTES_PER_OPTION: the peak above the request's
 * usage measured on real updates with 50,000 options on PHP 7.4 and 8.1,
 * plus about a third), plus the capture's working set (CAPTURE_BYTES: one batch
 * of small values and its records, see OptionsSnapshotProvider). Large
 * values are checked one by one while capturing, against the real usage.
 *
 * A BEFORE observation is started only if the most expensive stage
 * (SETTLED) would fit too, so an analysis is not begun that cannot be
 * completed in a request like this one.
 */
final class ObservationBudget {

	const BEFORE    = 'before';
	const IMMEDIATE = 'immediate';
	const SETTLED   = 'settled';

	/**
	 * Memory each stage needs per option, in bytes.
	 *
	 * - BEFORE: capture, encode, store.
	 * - IMMEDIATE: read and decode the BEFORE snapshot, capture, compare,
	 *   encode and store.
	 * - SETTLED: read and decode the BEFORE and IMMEDIATE snapshots (one at a
	 *   time), capture, compare twice, store.
	 */
	const BYTES_PER_OPTION = array(
		self::BEFORE    => 1792, // Measured 1,330 (PHP 8.1) / 1,333 (PHP 7.4).
		self::IMMEDIATE => 2304, // Measured 1,713 / 1,713.
		self::SETTLED   => 2560, // Measured 1,894 / 1,896.
	);

	/**
	 * The capture's working set, in bytes: one batch of small values read
	 * through $wpdb and its records.
	 */
	const CAPTURE_BYTES = OptionsSnapshotProvider::VALUE_READ_FACTOR * OptionsSnapshotProvider::BATCH_SIZE * OptionsSnapshotProvider::INLINE_VALUE_BYTES + OptionsSnapshotProvider::BATCH_SIZE * OptionsSnapshotProvider::RECORD_BYTES;

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
	 * Constructor.
	 *
	 * @param MemoryBudget $memory        Memory available to the request.
	 * @param callable     $count_options Returns the number of options a snapshot may keep.
	 */
	public function __construct( MemoryBudget $memory, callable $count_options ) {
		$this->memory        = $memory;
		$this->count_options = $count_options;
	}

	/**
	 * Whether a stage fits. With no memory limit, every stage fits (and the
	 * options are not counted). If the options cannot be counted, nothing
	 * fits: an estimate is required.
	 *
	 * @param string $stage BEFORE, IMMEDIATE or SETTLED.
	 * @return bool
	 */
	public function fits( $stage ) {
		if ( ! $this->memory->is_limited() ) {
			return true;
		}
		$per_option = isset( self::BYTES_PER_OPTION[ $stage ] ) ? self::BYTES_PER_OPTION[ $stage ] : max( self::BYTES_PER_OPTION );
		if ( self::BEFORE === $stage ) {
			$per_option = max( self::BYTES_PER_OPTION ); // The whole observation.
		}

		try {
			$options = (int) call_user_func( $this->count_options );
		} catch ( Throwable $e ) {
			return false;
		}

		return $this->memory->allows( self::CAPTURE_BYTES + $options * $per_option );
	}
}
