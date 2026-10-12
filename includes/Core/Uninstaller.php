<?php
/**
 * Plugin uninstall handler.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Core;

use UpdateLens\Admin\UnreadReports;
use UpdateLens\Baseline\MonitoringBaseline;
use UpdateLens\SiteCheck\OptionBaselineStore;
use UpdateLens\SiteCheck\RateLimiter;
use UpdateLens\SiteCheck\Settings;
use UpdateLens\SiteCheck\TransientSessionStore;
use UpdateLens\Storage\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Removes UpdateLens's data when the plugin is deleted (uninstall.php).
 */
final class Uninstaller {

	/**
	 * Sites read per query on Multisite.
	 */
	const SITE_BATCH = 100;

	/**
	 * Remove every site's data, then the user meta (shared by all sites of a
	 * network, so removed once).
	 *
	 * On Multisite this covers every site of every network (including
	 * archived, spammed and deleted-flagged ones), read in batches of
	 * SITE_BATCH IDs: a site where UpdateLens was activated individually has
	 * its own table and options, and a converted single site keeps its
	 * earlier data on the main site.
	 *
	 * @return void
	 */
	public static function uninstall() {
		if ( is_multisite() ) {
			self::network();
		} else {
			self::site();
		}

		UnreadReports::uninstall_users();
		RateLimiter::uninstall();
	}

	/**
	 * Remove the data of the current site: the analyses table, options,
	 * site check baselines, claims and session transients.
	 *
	 * @return void
	 */
	private static function site() {
		Schema::uninstall();
		MonitoringBaseline::uninstall();
		UnreadReports::uninstall();
		Settings::uninstall();
		OptionBaselineStore::uninstall();
		TransientSessionStore::uninstall();
	}

	/**
	 * Remove the data of every site, SITE_BATCH at a time.
	 *
	 * Deleting an autoloaded option loads that site's autoloaded options into
	 * the object cache's memory; the runtime cache is emptied after each batch
	 * (where the cache supports it) so memory does not grow with the network.
	 *
	 * @return void
	 */
	private static function network() {
		$offset = 0;
		do {
			$ids = get_sites(
				array(
					'fields'                 => 'ids',
					'number'                 => self::SITE_BATCH,
					'offset'                 => $offset,
					'orderby'                => 'id',
					'order'                  => 'ASC',
					'update_site_meta_cache' => false,
				)
			);

			foreach ( $ids as $id ) {
				switch_to_blog( (int) $id );
				self::site();
				restore_current_blog();
			}

			if ( wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}

			$offset += self::SITE_BATCH;
			$more    = count( $ids ) === self::SITE_BATCH;
		} while ( $more );
	}
}
