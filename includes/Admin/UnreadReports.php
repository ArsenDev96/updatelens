<?php
/**
 * New UpdateLens reports since an administrator's last visit (menu count).
 *
 * @package UpdateLens
 */

namespace UpdateLens\Admin;

use Throwable;
use UpdateLens\Core\Plugin;
use UpdateLens\Storage\AnalysisRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Per-user unread count of History entries, by analysis ID watermark.
 *
 * History lists every analysis row, ordered by its auto-increment ID, so
 * "new since the last visit" is the number of rows above a watermark: one
 * primary-key COUNT, no diffs. Each user's watermark is user meta set to the
 * highest ID whenever they open the UpdateLens screen. Users without one
 * start at the tracking origin: the highest ID when unread tracking began
 * (0 on a fresh install), so reports from before it never count as new.
 *
 * Local UI state only: IDs, never report contents. Failures are swallowed
 * (no count is shown) and never affect the admin screen.
 */
final class UnreadReports {

	/**
	 * Option holding the tracking origin (an analysis ID).
	 */
	const ORIGIN_OPTION = Plugin::OPTION_PREFIX . 'unread_origin_id';

	/**
	 * User meta key holding the highest analysis ID the user has seen.
	 */
	const USER_META = Plugin::OPTION_PREFIX . 'last_seen_analysis_id';

	/**
	 * Largest count shown as a number; above it the menu shows "99+".
	 */
	const MAX_DISPLAY = 99;

	/**
	 * Analyses.
	 *
	 * @var AnalysisRepository
	 */
	private $repository;

	/**
	 * Returns the stored origin, or false if there is none.
	 *
	 * @var callable
	 */
	private $read_origin;

	/**
	 * Stores the origin only if none exists.
	 *
	 * @var callable
	 */
	private $add_origin;

	/**
	 * Returns a user's stored watermark (user ID), or '' if there is none.
	 *
	 * @var callable
	 */
	private $read_seen;

	/**
	 * Stores a user's watermark (user ID, analysis ID).
	 *
	 * @var callable
	 */
	private $write_seen;

	/**
	 * Constructor.
	 *
	 * @param AnalysisRepository $repository  Analyses.
	 * @param callable           $read_origin Returns the stored origin, or false.
	 * @param callable           $add_origin  Receives an ID; stores it only if no origin exists.
	 * @param callable           $read_seen   Receives a user ID; returns the stored watermark or ''.
	 * @param callable           $write_seen  Receives a user ID and an analysis ID.
	 */
	public function __construct( AnalysisRepository $repository, callable $read_origin, callable $add_origin, callable $read_seen, callable $write_seen ) {
		$this->repository  = $repository;
		$this->read_origin = $read_origin;
		$this->add_origin  = $add_origin;
		$this->read_seen   = $read_seen;
		$this->write_seen  = $write_seen;
	}

	/**
	 * Unread tracking of the running site.
	 *
	 * @return self
	 */
	public static function create() {
		global $wpdb;

		return new self(
			new AnalysisRepository( $wpdb ),
			static function () {
				return get_option( self::ORIGIN_OPTION, false );
			},
			static function ( $id ) {
				// Read on every admin page that shows the menu count.
				add_option( self::ORIGIN_OPTION, (string) $id, '', true );
			},
			static function ( $user_id ) {
				return get_user_meta( $user_id, self::USER_META, true );
			},
			static function ( $user_id, $id ) {
				update_user_meta( $user_id, self::USER_META, (string) $id );
			}
		);
	}

	/**
	 * Remove the origin and every user's watermark. Used on uninstall
	 * (deactivation keeps them).
	 *
	 * @return void
	 */
	public static function uninstall() {
		delete_option( self::ORIGIN_OPTION );
		delete_metadata( 'user', 0, self::USER_META, '', true );
	}

	/**
	 * Watermark of a user: their stored ID, never below the origin; the
	 * origin if they have none (or it is unreadable).
	 *
	 * @param int   $origin Tracking origin.
	 * @param mixed $stored Stored user meta value ('' if none).
	 * @return int
	 */
	public static function effective_last_seen( $origin, $stored ) {
		$origin = max( 0, (int) $origin );
		if ( ! is_scalar( $stored ) || ! preg_match( '/^\d+$/', (string) $stored ) ) {
			return $origin;
		}

		return max( $origin, (int) $stored );
	}

	/**
	 * Menu count markup in WordPress's own style (as for Comments), or '' for
	 * no new reports. Above MAX_DISPLAY the bubble shows "99+"; the
	 * screen-reader text keeps the real number.
	 *
	 * @param int $count New reports.
	 * @return string HTML.
	 */
	public static function menu_count( $count ) {
		$count = (int) $count;
		if ( $count < 1 ) {
			return '';
		}

		$visible = $count > self::MAX_DISPLAY
			? sprintf(
				/* translators: %s: largest number shown in the menu count, e.g. "99". */
				__( '%s+', 'updatelens' ),
				number_format_i18n( self::MAX_DISPLAY )
			)
			: number_format_i18n( $count );
		$text = sprintf(
			/* translators: %s: number of new UpdateLens reports. */
			_n( '%s new UpdateLens report', '%s new UpdateLens reports', $count, 'updatelens' ),
			number_format_i18n( $count )
		);

		return ' <span class="awaiting-mod count-' . absint( min( $count, self::MAX_DISPLAY + 1 ) ) . '"><span class="pending-count" aria-hidden="true">' . esc_html( $visible ) . '</span><span class="screen-reader-text">' . esc_html( $text ) . '</span></span>';
	}

	/**
	 * Store the tracking origin if none exists: the newest analysis now, so
	 * earlier reports never count as new. Runs on activation and before every
	 * count. Never throws.
	 *
	 * @return int|null Origin, or null if it could not be determined (a later request retries).
	 */
	public function origin() {
		try {
			$stored = call_user_func( $this->read_origin );
			if ( false !== $stored ) {
				return self::effective_last_seen( 0, $stored );
			}

			$origin = $this->repository->max_id();
			call_user_func( $this->add_origin, $origin );

			return $origin;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Reports added since the user last opened UpdateLens. Never throws.
	 *
	 * @param int $user_id User ID.
	 * @return int 0 if unknown.
	 */
	public function count_for_user( $user_id ) {
		try {
			$origin = $this->origin();
			if ( null === $origin || $user_id < 1 ) {
				return 0;
			}

			return $this->repository->count_after(
				self::effective_last_seen( $origin, call_user_func( $this->read_seen, $user_id ) )
			);
		} catch ( Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Mark every existing report as seen by the user (their watermark becomes
	 * the newest ID). Only moves forward. Never throws.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function mark_seen( $user_id ) {
		try {
			$origin = $this->origin();
			if ( null === $origin || $user_id < 1 ) {
				return;
			}

			$newest = $this->repository->max_id();
			if ( $newest > self::effective_last_seen( $origin, call_user_func( $this->read_seen, $user_id ) ) ) {
				call_user_func( $this->write_seen, $user_id, $newest );
			}
		} catch ( Throwable $e ) {
			return;
		}
	}
}
