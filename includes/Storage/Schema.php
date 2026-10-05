<?php
/**
 * UpdateLens database schema.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Storage;

use UpdateLens\Core\Plugin;
use UpdateLens\Update\AnalysisStatus;
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
	 */
	const VERSION = 2;

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
	 * Create or update the tables, then record the schema version.
	 *
	 * The dbDelta() function adds columns and indexes but cannot rename; renames from older
	 * versions run first.
	 *
	 * @return bool Whether the analyses table exists afterwards.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table     = $wpdb->prefix . self::ANALYSES_TABLE;
		$installed = (int) get_option( self::VERSION_OPTION );

		if ( 1 === $installed && self::table_exists( $table ) ) {
			self::rename_v1_columns( $table );
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
	 * keeping any number of finished ones. `before_snapshot` and
	 * `immediate_snapshot` are temporary and cleared on every final state.
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
  before_snapshot longtext DEFAULT NULL,
  immediate_snapshot longtext DEFAULT NULL,
  during_update_diff longtext DEFAULT NULL,
  post_update_diff longtext DEFAULT NULL,
  final_diff longtext DEFAULT NULL,
  error_code varchar(64) DEFAULT NULL,
  error_message text DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY active_plugin (active_plugin(191)),
  KEY status (status),
  KEY plugin_file (plugin_file(191))
) {$charset_collate};";
	}

	/**
	 * Rename v1 columns to their v2 names, keeping their data.
	 *
	 * Version 1 `settled_diff` was BEFORE → SETTLED, i.e. the version 2 final diff.
	 *
	 * @param string $table Full table name.
	 * @return void
	 */
	private static function rename_v1_columns( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema inspection.
		$columns = (array) $wpdb->get_col( $wpdb->prepare( 'DESCRIBE %i', $table ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema upgrade.
		if ( in_array( 'immediate_diff', $columns, true ) && ! in_array( 'during_update_diff', $columns, true ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE COLUMN immediate_diff during_update_diff longtext DEFAULT NULL', $table ) );
		}
		if ( in_array( 'settled_diff', $columns, true ) && ! in_array( 'final_diff', $columns, true ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE COLUMN settled_diff final_diff longtext DEFAULT NULL', $table ) );
		}
		if ( in_array( 'settle_trigger', $columns, true ) && ! in_array( 'settle_outcome', $columns, true ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE COLUMN settle_trigger settle_outcome varchar(20) DEFAULT NULL', $table ) );
		}
		// phpcs:enable
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
