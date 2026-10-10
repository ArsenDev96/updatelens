<?php
/**
 * When the current PHP request started, on the clock of the analysis timestamps.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

defined( 'ABSPATH' ) || exit;

/**
 * The request start used to order requests against an analysis's IMMEDIATE
 * observation (`immediate_captured_at`, `microtime( true )`).
 *
 * It is WordPress's `$timestart`: `timer_start()` sets it with
 * `microtime( true )` in wp-settings.php before any drop-in (advanced-cache.php,
 * db.php, object-cache.php), must-use plugin or plugin is loaded (WordPress
 * 6.2 to 7.1), so a request whose `$timestart` is later than IMMEDIATE loaded
 * only updated plugin files, and both values come from the same clock.
 *
 * `$_SERVER['REQUEST_TIME_FLOAT']` is not used: it comes from the web
 * server's clock, which can differ from PHP's. Apache on Windows takes it
 * from the system tick (`GetSystemTimeAsFileTime()`, measured up to 14 ms
 * behind `microtime( true )`), so a follow-up sent after the update response
 * could appear to have started before IMMEDIATE.
 *
 * Read once, when UpdateLens's main file is loaded, so code that runs later
 * cannot move it. A missing, malformed or future value is not trusted: then
 * the start is unknown and this request settles nothing (conservative).
 */
final class RequestStart {

	/**
	 * The captured start (float), unknown (null), or not captured yet (false).
	 *
	 * @var float|null|false
	 */
	private static $start = false;

	/**
	 * Capture the request start from WordPress's `$timestart` (once per request).
	 *
	 * @return void
	 */
	public static function capture() {
		if ( false !== self::$start ) {
			return;
		}

		self::$start = self::from( isset( $GLOBALS['timestart'] ) ? $GLOBALS['timestart'] : null, microtime( true ) );
	}

	/**
	 * The captured request start.
	 *
	 * @return float|null Unix time with microseconds, or null if unknown or not captured.
	 */
	public static function get() {
		return false === self::$start ? null : self::$start;
	}

	/**
	 * Validate a `$timestart` value.
	 *
	 * A value that is too early only makes a request look older (it settles
	 * less); one in the future cannot be this request's start.
	 *
	 * @param mixed $timestart WordPress's `$timestart`.
	 * @param float $now       `microtime( true )` when it is read.
	 * @return float|null The start, or null if it cannot be trusted.
	 */
	public static function from( $timestart, $now ) {
		if ( ! is_float( $timestart ) || ! is_finite( $timestart ) || $timestart <= 0.0 || $timestart > $now ) {
			return null;
		}

		return $timestart;
	}
}
