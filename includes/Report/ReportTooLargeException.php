<?php
/**
 * A report that does not fit in the request's memory.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Report;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown before a report's stored diffs are read when decoding them would
 * not fit in the request's memory (AnalysisReports::report()). Fixed
 * message, never data.
 */
final class ReportTooLargeException extends RuntimeException {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'This UpdateLens report is too large for the available memory.' );
	}
}
