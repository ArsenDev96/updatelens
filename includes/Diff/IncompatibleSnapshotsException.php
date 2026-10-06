<?php
/**
 * Snapshots that cannot be compared.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when two snapshots were fingerprinted under different contexts
 * (changed WordPress salts or fingerprint scheme). Comparing them would
 * report every option as changed, or every cron event as removed + added.
 */
final class IncompatibleSnapshotsException extends RuntimeException {

	/**
	 * Exception for mismatched fingerprint contexts.
	 *
	 * @return self
	 */
	public static function fingerprint_context_mismatch() {
		return new self(
			'Cannot compare snapshots: they have different fingerprint contexts (the WordPress salts or the UpdateLens fingerprint scheme changed between them).'
		);
	}
}
