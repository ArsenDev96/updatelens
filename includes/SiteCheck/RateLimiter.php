<?php
/**
 * Per-user limit on site check runs.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Sliding-window limit: at most MAX_STARTS session starts per user within
 * WINDOW_SECONDS. The start times are kept as a short JSON list (user meta
 * in WordPress, USER_META). Unreadable stored data counts as no starts.
 *
 * Concurrent starts of one user may both pass the read-check-write, so the
 * bound is approximate under races (at most one extra start per concurrent
 * request), which is acceptable for a limit on the user's own requests.
 */
final class RateLimiter {

	/**
	 * Starts allowed per window (each start opens one session per check).
	 */
	const MAX_STARTS = 12;

	/**
	 * Window length in seconds.
	 */
	const WINDOW_SECONDS = 600;

	/**
	 * User meta key holding the start times.
	 */
	const USER_META = 'updatelens_site_check_starts';

	/**
	 * Reads a user's stored value: function ( int $user ): mixed.
	 *
	 * @var callable
	 */
	private $read;

	/**
	 * Writes a user's stored value: function ( int $user, string $value ): void.
	 *
	 * @var callable
	 */
	private $write;

	/**
	 * Constructor.
	 *
	 * @param callable $read  Reads a user's stored value.
	 * @param callable $write Writes a user's stored value.
	 */
	public function __construct( callable $read, callable $write ) {
		$this->read  = $read;
		$this->write = $write;
	}

	/**
	 * Limiter on user meta.
	 *
	 * @return self
	 */
	public static function from_wordpress() {
		return new self(
			static function ( $user ) {
				return get_user_meta( $user, self::USER_META, true );
			},
			static function ( $user, $value ) {
				update_user_meta( $user, self::USER_META, $value );
			}
		);
	}

	/**
	 * Remove every user's start times. Used on uninstall.
	 *
	 * @return void
	 */
	public static function uninstall() {
		delete_metadata( 'user', 0, self::USER_META, '', true );
	}

	/**
	 * Record a start if the user is under the limit.
	 *
	 * @param int   $user User ID.
	 * @param float $now  Unix seconds.
	 * @return int 0 when allowed (and recorded), else seconds until the next start is allowed.
	 */
	public function consume( $user, $now ) {
		$now    = (int) floor( $now );
		$starts = self::recent( call_user_func( $this->read, $user ), $now );
		if ( count( $starts ) >= self::MAX_STARTS ) {
			return max( 1, $starts[0] + self::WINDOW_SECONDS - $now );
		}

		$starts[] = $now;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure code, unit-tested without WordPress; a list of integers.
		call_user_func( $this->write, $user, (string) json_encode( $starts ) );

		return 0;
	}

	/**
	 * Start times within the window, oldest first, from a stored value.
	 *
	 * @param mixed $stored Stored value.
	 * @param int   $now    Unix seconds.
	 * @return int[]
	 */
	public static function recent( $stored, $now ) {
		if ( ! is_string( $stored ) || '' === $stored || strlen( $stored ) > 512 ) {
			return array();
		}
		$list = json_decode( $stored, true, 2 );
		if ( ! is_array( $list ) ) {
			return array();
		}

		$starts = array();
		foreach ( $list as $time ) {
			if ( is_int( $time ) && $time > $now - self::WINDOW_SECONDS && $time <= $now ) {
				$starts[] = $time;
			}
		}
		sort( $starts );

		return array_slice( $starts, -self::MAX_STARTS );
	}
}
