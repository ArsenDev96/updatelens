<?php
/**
 * One completed run of a site check.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * The attempts of one check run (BEFORE or AFTER an update) with the
 * context and times needed to decide comparability. Immutable; the verdict
 * is derived from the attempts (ResponseClassifier::result_verdict()).
 *
 * Times are server times (Unix seconds, `microtime( true )`): `started_at`
 * when the check session was opened, `completed_at` when its report was
 * accepted. They are never taken from the browser.
 */
final class CheckResult {

	/**
	 * Latest accepted timestamp (2100-01-01 UTC), a bound for stored data.
	 */
	const MAX_TIMESTAMP = 4102444800;

	/**
	 * Highest accepted definition version, a bound for stored data.
	 */
	const MAX_DEFINITION_VERSION = 65535;

	/**
	 * Check identifier.
	 *
	 * @var string
	 */
	private $check;

	/**
	 * Definition version the run used.
	 *
	 * @var int
	 */
	private $definition_version;

	/**
	 * Context.
	 *
	 * @var CheckContext
	 */
	private $context;

	/**
	 * Session start (server time).
	 *
	 * @var float
	 */
	private $started_at;

	/**
	 * Report accepted (server time).
	 *
	 * @var float
	 */
	private $completed_at;

	/**
	 * Attempts in order.
	 *
	 * @var Attempt[]
	 */
	private $attempts;

	/**
	 * Verdict and reason.
	 *
	 * @var array{0: string, 1: string|null}
	 */
	private $verdict;

	/**
	 * Constructor.
	 *
	 * @param string       $check              Check identifier.
	 * @param int          $definition_version Definition version the run used.
	 * @param CheckContext $context            Context.
	 * @param float|int    $started_at         Session start (Unix seconds).
	 * @param float|int    $completed_at       Report accepted (Unix seconds), not before the start.
	 * @param Attempt[]    $attempts           One or two attempts of this check, a valid run.
	 * @throws InvalidArgumentException If anything is invalid or inconsistent.
	 */
	public function __construct( $check, $definition_version, CheckContext $context, $started_at, $completed_at, array $attempts ) {
		if ( ! CheckDefinition::is_id( $check ) || ! is_int( $definition_version ) || $definition_version < 1 || $definition_version > self::MAX_DEFINITION_VERSION ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check result.' );
		}
		// The authentication context is fixed per check: the homepage is always
		// requested anonymously, REST always as the session user.
		if ( CheckDefinition::get( $check )->get_auth() !== $context->get_auth() ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check result.' );
		}
		if ( ! self::is_timestamp( $started_at ) || ! self::is_timestamp( $completed_at ) || $completed_at < $started_at ) {
			throw new InvalidArgumentException( 'Invalid UpdateLens site check result.' );
		}
		foreach ( $attempts as $attempt ) {
			if ( ! $attempt instanceof Attempt || $attempt->get_check() !== $check ) {
				throw new InvalidArgumentException( 'Invalid UpdateLens site check result.' );
			}
		}

		$this->verdict            = ResponseClassifier::result_verdict( $attempts );
		$this->check              = $check;
		$this->definition_version = $definition_version;
		$this->context            = $context;
		$this->started_at         = (float) $started_at;
		$this->completed_at       = (float) $completed_at;
		$this->attempts           = $attempts;
	}

	/**
	 * Whether a value is a plausible Unix timestamp.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_timestamp( $value ) {
		return ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) && $value > 0 && $value <= self::MAX_TIMESTAMP;
	}

	/**
	 * Check identifier.
	 *
	 * @return string
	 */
	public function get_check() {
		return $this->check;
	}

	/**
	 * Definition version the run used.
	 *
	 * @return int
	 */
	public function get_definition_version() {
		return $this->definition_version;
	}

	/**
	 * Context.
	 *
	 * @return CheckContext
	 */
	public function get_context() {
		return $this->context;
	}

	/**
	 * Session start (Unix seconds).
	 *
	 * @return float
	 */
	public function get_started_at() {
		return $this->started_at;
	}

	/**
	 * Report accepted (Unix seconds).
	 *
	 * @return float
	 */
	public function get_completed_at() {
		return $this->completed_at;
	}

	/**
	 * Attempts in order.
	 *
	 * @return Attempt[]
	 */
	public function get_attempts() {
		return $this->attempts;
	}

	/**
	 * Verdict of the run.
	 *
	 * @return string Verdict constant.
	 */
	public function get_verdict() {
		return $this->verdict[0];
	}

	/**
	 * Reason of the verdict (null for a pass).
	 *
	 * @return string|null
	 */
	public function get_reason() {
		return $this->verdict[1];
	}

	/**
	 * Evidence for comparison output: verdict, reason and every attempt's
	 * facts. No context, times, fingerprints or user.
	 *
	 * @return array{verdict: string, reason: string|null, attempts: array}
	 */
	public function to_report_array() {
		$attempts = array();
		foreach ( $this->attempts as $attempt ) {
			$attempts[] = $attempt->to_report_array();
		}

		return array(
			'verdict'  => $this->verdict[0],
			'reason'   => $this->verdict[1],
			'attempts' => $attempts,
		);
	}
}
