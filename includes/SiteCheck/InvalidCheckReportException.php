<?php
/**
 * A browser report of a site check that cannot be accepted.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by ReportValidator. Messages are fixed and reasons are fixed codes:
 * neither ever contains the submitted data.
 */
final class InvalidCheckReportException extends RuntimeException {

	/**
	 * The report is larger than ReportValidator::MAX_BYTES.
	 */
	const TOO_LARGE = 'too_large';

	/**
	 * The report is not JSON of the expected shape.
	 */
	const INVALID_REPORT = 'invalid_report';

	/**
	 * The report is for another check than the session's.
	 */
	const CHECK_MISMATCH = 'check_mismatch';

	/**
	 * An attempt has missing, unknown or invalid facts, or the attempts are
	 * not a valid run (e.g. a retry after a pass).
	 */
	const INVALID_ATTEMPT = 'invalid_attempt';

	/**
	 * Fixed reason code.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * The report is too large.
	 *
	 * @return self
	 */
	public static function too_large() {
		return self::because( self::TOO_LARGE );
	}

	/**
	 * The report is not JSON of the expected shape.
	 *
	 * @return self
	 */
	public static function invalid_report() {
		return self::because( self::INVALID_REPORT );
	}

	/**
	 * The report is for another check.
	 *
	 * @return self
	 */
	public static function check_mismatch() {
		return self::because( self::CHECK_MISMATCH );
	}

	/**
	 * An attempt or the run is invalid.
	 *
	 * @return self
	 */
	public static function invalid_attempt() {
		return self::because( self::INVALID_ATTEMPT );
	}

	/**
	 * Exception with a fixed message for a reason constant.
	 *
	 * @param string $reason Reason constant.
	 * @return self
	 */
	private static function because( $reason ) {
		$exception         = new self( 'UpdateLens could not accept the site check report (' . $reason . ').' );
		$exception->reason = $reason;

		return $exception;
	}

	/**
	 * Fixed reason code (one of the class constants).
	 *
	 * @return string
	 */
	public function get_reason() {
		return $this->reason;
	}
}
