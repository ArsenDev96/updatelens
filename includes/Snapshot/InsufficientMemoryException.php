<?php
/**
 * An observation that does not fit in the request's memory.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown before an allocation that MemoryBudget says would not fit, so the
 * observation stops safely instead of ending the request with a fatal error.
 * Fixed message, never data.
 */
final class InsufficientMemoryException extends RuntimeException {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'UpdateLens skipped an observation that would not fit in the available memory.' );
	}
}
