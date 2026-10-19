<?php
/**
 * Time budget for the observations taken inside one update request.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

defined( 'ABSPATH' ) || exit;

/**
 * Caps the time UpdateLens's own observation work adds to one update request.
 *
 * A multi-plugin update (`bulk_upgrade()` with several plugins) runs every
 * download, unpack and install in one request, under maintenance mode. Each
 * analysed plugin adds an observation (capture, comparison, storage). A PHP
 * or web-server timeout would stop the update half-way with maintenance mode
 * on, which UpdateLens must never cause, so the observations of one request
 * share a fixed budget: the next one is allowed only while the time spent so
 * far plus that many of the longest observation seen so far stays within the
 * cap.
 *
 * The cap is absolute, never "the time left": WordPress restarts PHP's timer
 * for every package (`set_time_limit( 300 )` in `WP_Upgrader::install_package()`),
 * and web-server and proxy timeouts cannot be seen from PHP at all. It is the
 * smaller of CEILING_SECONDS and EXECUTION_TIME_SHARE of `max_execution_time`
 * as configured when the request started (when it is set).
 */
final class CaptureTimeBudget {

	/**
	 * Most time (seconds) UpdateLens's observations may add to one update request.
	 */
	const CEILING_SECONDS = 10.0;

	/**
	 * Share of the configured `max_execution_time` they may use, when it is set.
	 */
	const EXECUTION_TIME_SHARE = 0.25;

	/**
	 * Cap in seconds.
	 *
	 * @var float
	 */
	private $cap;

	/**
	 * Returns the current time in seconds (with fractions).
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Time spent on observations in this request, in seconds.
	 *
	 * @var float
	 */
	private $spent = 0.0;

	/**
	 * Longest single observation in this request, in seconds.
	 *
	 * @var float
	 */
	private $longest = 0.0;

	/**
	 * Constructor.
	 *
	 * @param float         $cap   Cap in seconds.
	 * @param callable|null $clock Returns the current time in seconds. Default microtime( true ).
	 */
	public function __construct( $cap, ?callable $clock = null ) {
		$this->cap   = max( 0.0, (float) $cap );
		$this->clock = null === $clock ? static function () {
			return microtime( true );
		} : $clock;
	}

	/**
	 * Budget for this request, from PHP's `max_execution_time` setting.
	 *
	 * @return self
	 */
	public static function from_php() {
		return new self( self::cap_for( ini_get( 'max_execution_time' ) ) );
	}

	/**
	 * A budget that allows everything (no update request to protect).
	 *
	 * @return self
	 */
	public static function unlimited() {
		return new self( PHP_FLOAT_MAX );
	}

	/**
	 * The cap for a `max_execution_time` setting (0, empty or unreadable: not set).
	 *
	 * @param mixed $max_execution_time Setting as read from PHP.
	 * @return float Seconds.
	 */
	public static function cap_for( $max_execution_time ) {
		$limit = is_numeric( $max_execution_time ) ? (float) $max_execution_time : 0.0;
		if ( $limit <= 0 ) {
			return self::CEILING_SECONDS;
		}

		return min( self::CEILING_SECONDS, $limit * self::EXECUTION_TIME_SHARE );
	}

	/**
	 * Whether $observations more observations still fit, each assumed to take
	 * as long as the longest one so far (nothing measured yet: they fit).
	 *
	 * @param int $observations Observations needed.
	 * @return bool
	 */
	public function allows( $observations ) {
		return $this->spent + max( 0, (int) $observations ) * $this->longest <= $this->cap;
	}

	/**
	 * Start timing one observation.
	 *
	 * @return float Start time, for stop().
	 */
	public function start() {
		return (float) call_user_func( $this->clock );
	}

	/**
	 * Record an observation that began at $started.
	 *
	 * @param float $started Value returned by start().
	 * @return float Its duration in seconds.
	 */
	public function stop( $started ) {
		$duration      = max( 0.0, (float) call_user_func( $this->clock ) - (float) $started );
		$this->spent  += $duration;
		$this->longest = max( $this->longest, $duration );

		return $duration;
	}

	/**
	 * Cap in seconds.
	 *
	 * @return float
	 */
	public function cap() {
		return $this->cap;
	}

	/**
	 * Time spent so far, in seconds.
	 *
	 * @return float
	 */
	public function spent() {
		return $this->spent;
	}
}
