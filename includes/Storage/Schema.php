<?php
/**
 * UpdateLens database schema.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Storage;

use UpdateLens\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the UpdateLens tables with dbDelta().
 */
final class Schema {

	/**
	 * Current schema version. Bump when the table definition changes.
	 */
	const VERSION = 1;

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
	 * @return bool Whether the analyses table exists afterwards.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::analyses_table_sql( $wpdb->prefix . self::ANALYSES_TABLE, $wpdb->get_charset_collate() ) );

		$table = $wpdb->prefix . self::ANALYSES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return false;
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
	 * keeping any number of finished ones.
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
  settle_trigger varchar(20) DEFAULT NULL,
  started_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  completed_at datetime DEFAULT NULL,
  before_snapshot longtext DEFAULT NULL,
  immediate_diff longtext DEFAULT NULL,
  settled_diff longtext DEFAULT NULL,
  error_code varchar(64) DEFAULT NULL,
  error_message text DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY active_plugin (active_plugin(191)),
  KEY status (status),
  KEY plugin_file (plugin_file(191))
) {$charset_collate};";
	}
}
