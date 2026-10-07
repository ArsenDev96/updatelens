<?php
/**
 * UpdateLens database schema.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Storage;

use UpdateLens\Core\Plugin;
use UpdateLens\Update\ActionSchedulerPhaseReason;
use UpdateLens\Update\AnalysisStatus;
use UpdateLens\Update\CronPhaseReason;
use UpdateLens\Update\SettleOutcome;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the UpdateLens tables with dbDelta().
 */
final class Schema {

	/**
	 * Current schema version. Bump when the table definition changes.
	 *
	 * 2: phase diffs (during_update_diff, post_update_diff, final_diff),
	 *    immediate_snapshot, settle_deadline, settle_outcome.
	 * 3: options columns prefixed `options_`; WP-Cron snapshot, diff and
	 *    reason columns.
	 * 4: Action Scheduler snapshot, diff and reason columns.
	 */
	const VERSION = 4;

	/**
	 * Column renames: current name => [ definition, earlier names, newest first ].
	 */
	const RENAMES = array(
		'options_before_snapshot'    => array( 'longtext DEFAULT NULL', 'before_snapshot' ),
		'options_immediate_snapshot' => array( 'longtext DEFAULT NULL', 'immediate_snapshot' ),
		'options_during_update_diff' => array( 'longtext DEFAULT NULL', 'during_update_diff', 'immediate_diff' ),
		'options_post_update_diff'   => array( 'longtext DEFAULT NULL', 'post_update_diff' ),
		'options_final_diff'         => array( 'longtext DEFAULT NULL', 'final_diff', 'settled_diff' ),
		'settle_outcome'             => array( 'varchar(20) DEFAULT NULL', 'settle_trigger' ),
	);

	/**
	 * Option holding the installed schema version.
	 */
	const VERSION_OPTION = Plugin::OPTION_PREFIX . 'db_version';

	/**
	 * Analyses table name without the WordPress prefix.
	 */
	const ANALYSES_TABLE = 'updatelens_analyses';

	/**
	 * Install or upgrade the schema if the stored version differs.
	 *
	 * Cheap when up to date: one autoloaded option read.
	 *
	 * @return bool Whether the schema is ready.
	 */
	public static function maybe_upgrade() {
		if ( self::VERSION === (int) get_option( self::VERSION_OPTION ) ) {
			return true;
		}

		return self::install();
	}

	/**
	 * Like maybe_upgrade(), and also recreate the analyses table if it is missing
	 * although the stored version is current (e.g. dropped manually).
	 *
	 * Costs one extra `SHOW TABLES` query, so it runs only in bounded contexts
	 * (the UpdateLens screen and report REST requests), never on every request.
	 *
	 * @return bool Whether the analyses table exists afterwards.
	 */
	public static function repair() {
		global $wpdb;

		if ( ! self::maybe_upgrade() ) {
			return false;
		}
		if ( self::table_exists( $wpdb->prefix . self::ANALYSES_TABLE ) ) {
			return true;
		}

		return self::install();
	}

	/**
	 * Create or update the tables, then record the schema version.
	 *
	 * The dbDelta() function adds columns and indexes but cannot rename; renames from older
	 * versions run first. If they fail, the version is not recorded, so the
	 * upgrade is retried instead of dbDelta() adding empty columns next to the old ones.
	 *
	 * @return bool Whether the analyses table exists afterwards.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table     = $wpdb->prefix . self::ANALYSES_TABLE;
		$installed = (int) get_option( self::VERSION_OPTION );
		$upgrading = $installed > 0 && $installed < self::VERSION && self::table_exists( $table );

		if ( $upgrading && ! self::rename_columns( $table ) ) {
			return false;
		}

		dbDelta( self::analyses_table_sql( $table, $wpdb->get_charset_collate() ) );

		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		if ( 1 === $installed ) {
			// v1 recorded no settle outcome for analyses that ended before settling.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema upgrade.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET settle_outcome = %s WHERE settle_outcome IS NULL AND status IN (%s, %s, %s)',
					$table,
					SettleOutcome::NOT_APPLICABLE,
					AnalysisStatus::FAILED,
					AnalysisStatus::INCOMPATIBLE,
					AnalysisStatus::ABANDONED
				)
			);
		}

		if ( $upgrading && $installed < 3 ) {
			// Finished analyses from before WP-Cron observation never captured Cron.
			// Open ones resolve the same way when they end (no Cron BEFORE snapshot).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema upgrade.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET cron_during_update_reason = %s, cron_post_update_reason = %s, cron_final_reason = %s WHERE status NOT IN (%s, %s)',
					$table,
					CronPhaseReason::NOT_CAPTURED,
					CronPhaseReason::NOT_CAPTURED,
					CronPhaseReason::NOT_CAPTURED,
					AnalysisStatus::CAPTURED,
					AnalysisStatus::AWAITING_SETTLE
				)
			);
		}

		if ( $upgrading && $installed < 4 ) {
			// Finished analyses from before Action Scheduler observation never captured it.
			// Open ones resolve when they end (no Action Scheduler BEFORE or IMMEDIATE snapshot).
			// Options and Cron columns are not touched.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema upgrade.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET action_scheduler_during_update_reason = %s, action_scheduler_post_update_reason = %s, action_scheduler_final_reason = %s WHERE status NOT IN (%s, %s)',
					$table,
					ActionSchedulerPhaseReason::NOT_CAPTURED,
					ActionSchedulerPhaseReason::NOT_CAPTURED,
					ActionSchedulerPhaseReason::NOT_CAPTURED,
					AnalysisStatus::CAPTURED,
					AnalysisStatus::AWAITING_SETTLE
				)
			);
		}

		update_option( self::VERSION_OPTION, self::VERSION, true );

		return true;
	}

	/**
	 * Drop the tables and the version option. Used on uninstall.
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . self::ANALYSES_TABLE ) );

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * CREATE TABLE statement for dbDelta().
	 *
	 * `active_plugin` holds the plugin file only while an analysis is not in a
	 * terminal state; its unique key allows one open analysis per plugin while
	 * keeping any number of finished ones. Options and WP-Cron are independent
	 * signals with their own columns. The `*_before_snapshot` and
	 * `*_immediate_snapshot` columns are temporary and cleared on every final
	 * state; `cron_*_reason` holds the CronPhaseReason of an unavailable Cron phase.
	 *
	 * @param string $table           Full table name.
	 * @param string $charset_collate Charset/collation clause.
	 * @return string
	 */
	public static function analyses_table_sql( $table, $charset_collate ) {
		return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  plugin_file varchar(255) NOT NULL,
  plugin_name varchar(255) NOT NULL DEFAULT '',
  version_before varchar(64) NOT NULL DEFAULT '',
  version_after varchar(64) DEFAULT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL,
  active_plugin varchar(255) DEFAULT NULL,
  settle_outcome varchar(20) DEFAULT NULL,
  started_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  settle_deadline datetime DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  options_before_snapshot longtext DEFAULT NULL,
  options_immediate_snapshot longtext DEFAULT NULL,
  options_during_update_diff longtext DEFAULT NULL,
  options_post_update_diff longtext DEFAULT NULL,
  options_final_diff longtext DEFAULT NULL,
  cron_before_snapshot longtext DEFAULT NULL,
  cron_immediate_snapshot longtext DEFAULT NULL,
  cron_during_update_diff longtext DEFAULT NULL,
  cron_post_update_diff longtext DEFAULT NULL,
  cron_final_diff longtext DEFAULT NULL,
  cron_during_update_reason varchar(40) DEFAULT NULL,
  cron_post_update_reason varchar(40) DEFAULT NULL,
  cron_final_reason varchar(40) DEFAULT NULL,
  action_scheduler_before_snapshot longtext DEFAULT NULL,
  action_scheduler_immediate_snapshot longtext DEFAULT NULL,
  action_scheduler_during_update_diff longtext DEFAULT NULL,
  action_scheduler_post_update_diff longtext DEFAULT NULL,
  action_scheduler_final_diff longtext DEFAULT NULL,
  action_scheduler_during_update_reason varchar(40) DEFAULT NULL,
  action_scheduler_post_update_reason varchar(40) DEFAULT NULL,
  action_scheduler_final_reason varchar(40) DEFAULT NULL,
  error_code varchar(64) DEFAULT NULL,
  error_message text DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY active_plugin (active_plugin(191)),
  KEY status (status),
  KEY plugin_file (plugin_file(191))
) {$charset_collate};";
	}

	/**
	 * Rename columns from earlier versions to their current names, keeping their data.
	 *
	 * Version 1 `settled_diff` was BEFORE → SETTLED, i.e. today's final diff;
	 * version 1 and 2 phase columns held the options signal. Each rename is
	 * its own statement. If one fails, the upgrade stops and is retried later;
	 * renames() then skips the columns already renamed.
	 *
	 * @param string $table Full table name.
	 * @return bool Whether the renames succeeded (true if none were needed).
	 */
	public static function rename_columns( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema inspection.
		$columns = (array) $wpdb->get_col( $wpdb->prepare( 'DESCRIBE %i', $table ) );
		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		foreach ( self::renames( $columns ) as $rename ) {
			if ( ! self::rename_column( $table, $rename[0], $rename[1], $rename[2] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Rename one column, keeping its data. Only the definitions used in
	 * RENAMES are supported.
	 *
	 * @param string $table      Full table name.
	 * @param string $from       Existing column name.
	 * @param string $to         New column name.
	 * @param string $definition Column definition from RENAMES.
	 * @return bool Whether the column was renamed.
	 */
	private static function rename_column( $table, $from, $to, $definition ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema upgrade of the plugin's own table.
		switch ( $definition ) {
			case 'longtext DEFAULT NULL':
				return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE COLUMN %i %i longtext DEFAULT NULL', $table, $from, $to ) );
			case 'varchar(20) DEFAULT NULL':
				return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE COLUMN %i %i varchar(20) DEFAULT NULL', $table, $from, $to ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

		return false;
	}

	/**
	 * Renames needed for an existing column list.
	 *
	 * A column is renamed from the newest earlier name that exists, and only if
	 * its current name does not exist yet (so a repeated upgrade is a no-op).
	 *
	 * @param string[] $columns Existing column names.
	 * @return array<int, array{string, string, string}> [ from, to, definition ] per rename.
	 */
	public static function renames( array $columns ) {
		$renames = array();
		foreach ( self::RENAMES as $to => $rename ) {
			$definition = array_shift( $rename );
			if ( in_array( $to, $columns, true ) ) {
				continue;
			}
			foreach ( $rename as $from ) {
				if ( in_array( $from, $columns, true ) ) {
					$renames[] = array( $from, $to, $definition );
					break;
				}
			}
		}

		return $renames;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
