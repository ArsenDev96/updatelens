<?php
/**
 * Compares two `wp_options` snapshots.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\OptionsSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Builds an OptionsDiff from a before and an after OptionsSnapshot.
 *
 * Pure: works only on the snapshot objects, never reads the database.
 * Fingerprints are compared here and do not appear in the result.
 */
final class OptionsDiffBuilder {

	/**
	 * Compare two snapshots.
	 *
	 * @param OptionsSnapshot $before Snapshot before.
	 * @param OptionsSnapshot $after  Snapshot after.
	 * @return OptionsDiff
	 * @throws IncompatibleSnapshotsException If the snapshots have different fingerprint contexts.
	 */
	public function build( OptionsSnapshot $before, OptionsSnapshot $after ) {
		if ( $before->get_fingerprint_context() !== $after->get_fingerprint_context() ) {
			throw IncompatibleSnapshotsException::fingerprint_context_mismatch();
		}

		$before_records = $before->get_records();
		$after_records  = $after->get_records();

		$added   = array();
		$changed = array();
		foreach ( $after_records as $name => $after_record ) {
			if ( ! isset( $before_records[ $name ] ) ) {
				$added[] = new OptionState( $after_record );
				continue;
			}

			$change = ChangedOption::between( $before_records[ $name ], $after_record );
			if ( null !== $change ) {
				$changed[] = $change;
			}
		}

		$removed = array();
		foreach ( array_diff_key( $before_records, $after_records ) as $before_record ) {
			$removed[] = new OptionState( $before_record );
		}

		return new OptionsDiff(
			$added,
			$removed,
			$changed,
			new OptionsDiffSummary( $before->get_summary(), $after->get_summary(), count( $added ), count( $removed ), $changed )
		);
	}
}
