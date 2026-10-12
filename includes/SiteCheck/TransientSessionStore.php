<?php
/**
 * Site check sessions in WordPress transients.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

defined( 'ABSPATH' ) || exit;

/**
 * SessionStore on the Transients API (database or persistent object cache),
 * with claims in the options table.
 *
 * A claim is an `INSERT IGNORE` of a claim row (CLAIM_PREFIX + SHA-256 of
 * the key, not autoloaded) on the unique option name, written with SQL
 * only, never through the options API or the object cache: of any number of
 * concurrent or repeated claims of one key exactly one inserts it. Only that
 * claim removes the transient (best effort: a session that is still found
 * can never be claimed again). delete_transient()'s result is never used:
 * with a persistent object cache it is the drop-in's delete result, which
 * some report as success for every caller (W3 Total Cache's disk and Redis
 * engines), so concurrent reports of one session would all be accepted.
 *
 * Claim rows outlive every session they could belong to (CLAIM_LIFETIME);
 * each claim removes at most CLEANUP_LIMIT expired ones, and uninstall()
 * removes all.
 */
final class TransientSessionStore implements SessionStore {

	/**
	 * Option name prefix of claim rows (UpdateLens prefix: never in snapshots).
	 */
	const CLAIM_PREFIX = 'updatelens_site_check_claim_';

	/**
	 * Seconds a claim row is kept. A session can be reported only while it
	 * lives (CheckSession::TTL), so this keeps its claim well past that, even
	 * across web servers whose clocks differ by minutes.
	 */
	const CLAIM_LIFETIME = CheckSession::TTL * 5;

	/**
	 * Most expired claim rows removed per claim (more than one is added).
	 */
	const CLEANUP_LIMIT = 10;

	/**
	 * Clock: function (): int (Unix seconds).
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param callable|null $now Clock (default: `time()`).
	 */
	public function __construct( ?callable $now = null ) {
		$this->now = null === $now ? 'time' : $now;
	}

	/**
	 * Stored value.
	 *
	 * @param string $key Key.
	 * @return string|null
	 */
	public function get( $key ) {
		$value = get_transient( $key );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Store a value.
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return bool
	 */
	public function put( $key, $value, $ttl ) {
		return set_transient( $key, $value, $ttl );
	}

	/**
	 * Claim a value: true only for the one call that inserted its claim row.
	 * A failed insert claims nothing.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function claim( $key ) {
		global $wpdb;

		$now = (int) call_user_func( $this->now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim on the unique option name; must never go through a cache.
		$claimed = 1 === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::claim_name( $key ), (string) ( $now + self::CLAIM_LIFETIME ), OptionBaselineStore::AUTOLOAD ) );
		if ( ! $claimed ) {
			return false;
		}

		delete_transient( $key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded removal of UpdateLens's own expired claim rows (unique index range); never cached.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_name ASC LIMIT %d', $wpdb->options, $wpdb->esc_like( self::CLAIM_PREFIX ) . '%', $now, self::CLEANUP_LIMIT ) );

		return true;
	}

	/**
	 * Option name of a key's claim row.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function claim_name( $key ) {
		return self::CLAIM_PREFIX . hash( 'sha256', (string) $key );
	}

	/**
	 * Remove every claim row. Used on uninstall.
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UpdateLens's own rows, never cached.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( self::CLAIM_PREFIX ) . '%' ) );
	}
}
