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
	 * 5: immediate_captured_at (Unix time of the IMMEDIATE observation with
	 *    microseconds), so a later request can prove it started afterwards.
	 * 6: site check BEFORE baselines per check (Update\SiteCheckObservation):
	 *    `site_check_<check>_before` (attached result) and
	 *    `site_check_<check>_before_reason`. Existing rows are not rewritten:
	 *    both NULL means the analysis was created before schema 6.
	 * 7: site check AFTER results per check (Update\SiteCheckAfter):
	 *    `site_check_<check>_after` and `site_check_<check>_after_reason`,
	 *    `site_check_update_started_at` (when the update began, with
	 *    microseconds; written when the analysis is created) and
	 *    `site_check_after_started_at` (the one AFTER run, claimed once).
	 *    Existing rows are not rewritten: a NULL update start means the
	 *    analysis was created before schema 7.
	 */
	const VERSION = 7;

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
	 * Option holding the earliest Unix time of the next automatic install
	 * attempt after one failed or while one runs (see claim_attempt()). Not
	 * autoloaded; read and written with SQL only, never through the object
	 * cache. Absent while the schema is installed.
	 */
	const RETRY_OPTION = Plugin::OPTION_PREFIX . 'db_upgrade_retry_at';

	/**
	 * Seconds between automatic install attempts while installing fails.
	 */
	const RETRY_AFTER_SECONDS = 600;

	/**
	 * Unique keys the lifecycle relies on: key name => its only column. The
	 * primary key identifies analyses; `active_plugin` allows one open
	 * analysis per plugin. The other keys only speed up reads.
	 */
	const REQUIRED_UNIQUE_KEYS = array(
		'PRIMARY'       => 'id',
		'active_plugin' => 'active_plugin',
	);

	/**
	 * Install or upgrade the schema if the stored version differs.
	 *
	 * Cheap when up to date: one autoloaded option read. Otherwise at most one
	 * request starts an install attempt per RETRY_AFTER_SECONDS, whatever the
	 * request (a visitor's included), and a failed attempt is retried only
	 * after that: no repeated DDL on every request while installing fails.
	 *
	 * @return bool Whether the schema is ready.
	 */
	public static function maybe_upgrade() {
		if ( self::VERSION === (int) get_option( self::VERSION_OPTION ) ) {
			return true;
		}

		return self::upgrade( false );
	}

	/**
	 * Like maybe_upgrade(), and also check that the analyses table has every
	 * column and unique key of this version although the stored version is
	 * current (a table dropped or altered manually, or a version recorded by
	 * an earlier release despite a failed ALTER), installing it again if not.
	 *
	 * Costs two schema queries (`DESCRIBE`, `SHOW INDEX`), so it runs only in
	 * bounded contexts (report REST reads), never on every request. Install
	 * attempts keep to the retry interval; see recover() for the UpdateLens
	 * screen.
	 *
	 * @return bool Whether the schema is ready.
	 */
	public static function repair() {
		return self::check( false );
	}

	/**
	 * Like repair(), but an administrator opening the UpdateLens screen may
	 * retry a failed install at once instead of waiting for the retry
	 * interval (e.g. after fixing a database permission). Still one attempt
	 * at a time: an attempt started in the same second is not repeated.
	 *
	 * @return bool Whether the schema is ready.
	 */
	public static function recover() {
		return self::check( true );
	}

	/**
	 * Create or update the tables, verify them, then record the schema version.
	 *
	 * The dbDelta() function adds columns and indexes but cannot rename; renames from older
	 * versions run first. If they fail, the version is not recorded, so the
	 * upgrade is retried instead of dbDelta() adding empty columns next to the old ones.
	 * The dbDelta() function reports no failures (e.g. an `ALTER TABLE` refused for lack of
	 * privileges), so the version is recorded only when the table has every
	 * column and unique key of this version afterwards and the one-time data
	 * updates succeeded. Every step is repeatable, so a failed install is
	 * simply run again later. Database errors are never printed (they would
	 * end up in pages, REST responses or activation output); WordPress still
	 * logs them.
	 *
	 * Runs at once (activation, or after claim_attempt()).
	 *
	 * @return bool Whether the schema is installed and its version recorded.
	 */
	public static function install() {
		global $wpdb;

		$show_errors = $wpdb->hide_errors();
		try {
			$installed = self::install_tables();
		} finally {
			$wpdb->show_errors( $show_errors );
		}

		if ( $installed ) {
			self::clear_retry();
		}

		return $installed;
	}

	/**
	 * Whether the table has the stored version's structure, else an install
	 * attempt.
	 *
	 * @param bool $immediately Whether to ignore the retry interval.
	 * @return bool Whether the schema is ready.
	 */
	private static function check( $immediately ) {
		global $wpdb;

		if ( self::VERSION === (int) get_option( self::VERSION_OPTION ) ) {
			$show_errors = $wpdb->hide_errors();
			$verified    = self::verify( $wpdb->prefix . self::ANALYSES_TABLE );
			$wpdb->show_errors( $show_errors );
			if ( $verified ) {
				return true;
			}
		}

		return self::upgrade( $immediately );
	}

	/**
	 * Install if this request may start an attempt.
	 *
	 * @param bool $immediately Whether to ignore the retry interval.
	 * @return bool Whether the schema is ready.
	 */
	private static function upgrade( $immediately ) {
		global $wpdb;

		$show_errors = $wpdb->hide_errors();
		$claimed     = self::claim_attempt( $immediately, time() );
		// A request that started earlier may have installed the schema since
		// this request read its options.
		$installed = $claimed && self::VERSION === self::stored_version() && self::verify( $wpdb->prefix . self::ANALYSES_TABLE );
		$wpdb->show_errors( $show_errors );

		if ( $installed ) {
			self::clear_retry();
			return true;
		}

		return $claimed && self::install();
	}

	/**
	 * The stored schema version, read from the database: not from this
	 * request's options, which may be older than an install another request
	 * just finished.
	 *
	 * @return int|null Null when the read failed.
	 */
	private static function stored_version() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must not be served from a cache.
		$version = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::VERSION_OPTION ) );

		return '' === $wpdb->last_error ? (int) $version : null;
	}

	/**
	 * Claim the next install attempt: store the time before which no other
	 * automatic attempt starts (now + RETRY_AFTER_SECONDS) and return true
	 * only to the one request whose atomic write succeeded (`INSERT IGNORE`
	 * on the unique option name, or compare-and-set on the stored bytes), so
	 * simultaneous requests never install together. The time stays stored
	 * when the attempt fails or the request dies, so a stuck attempt never
	 * blocks the next one longer than the interval; a successful install
	 * removes it.
	 *
	 * A stored time further ahead than the interval (clock changed,
	 * unreadable value) counts as due. A failed read claims nothing.
	 *
	 * @param bool $immediately Whether to ignore the retry interval.
	 * @param int  $now         Unix time.
	 * @return bool Whether this request may install now.
	 */
	public static function claim_attempt( $immediately, $now ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic retry marker; must never be served from a cache.
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::RETRY_OPTION ) );
		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		$next = (string) ( $now + self::RETRY_AFTER_SECONDS );
		if ( null === $stored ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic create-if-missing on the unique option name; never cached.
			return 1 === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::RETRY_OPTION, $next, 'no' ) );
		}

		$retry_at = is_numeric( $stored ) ? (int) $stored : 0;
		if ( ! $immediately && $retry_at > $now && $retry_at <= $now + self::RETRY_AFTER_SECONDS ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set; never cached.
		return 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, $next, self::RETRY_OPTION, (string) $stored ) );
	}

	/**
	 * Remove the retry marker after a successful install.
	 *
	 * @return void
	 */
	private static function clear_retry() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Retry marker; never cached.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, self::RETRY_OPTION ) );
	}

	/**
	 * Whether a table has every column of this version and the unique keys
	 * the lifecycle relies on. A failed schema query is "not installed".
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	public static function verify( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema inspection.
		$columns = $wpdb->get_col( $wpdb->prepare( 'DESCRIBE %i', $table ) );
		if ( '' !== $wpdb->last_error || ! is_array( $columns ) || array() !== array_diff( self::columns(), $columns ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema inspection.
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $indexes ) ) {
			return false;
		}
		foreach ( self::REQUIRED_UNIQUE_KEYS as $key => $column ) {
			if ( ! self::has_unique_key( $indexes, $key, $column ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Column names of this version, from the CREATE TABLE statement.
	 *
	 * @return string[]
	 */
	public static function columns() {
		preg_match_all( '/^  ([a-z0-9_]+) [a-z]/m', self::analyses_table_sql( 'table', '' ), $matches );

		return $matches[1];
	}

	/**
	 * Whether `SHOW INDEX` rows contain a unique key on exactly one column.
	 *
	 * @param array  $indexes `SHOW INDEX` rows.
	 * @param string $key     Key name.
	 * @param string $column  Column.
	 * @return bool
	 */
	private static function has_unique_key( array $indexes, $key, $column ) {
		$columns = array();
		foreach ( $indexes as $index ) {
			if ( ! is_array( $index ) || ! isset( $index['Key_name'] ) || $key !== $index['Key_name'] ) {
				continue;
			}
			if ( ! isset( $index['Non_unique'], $index['Column_name'] ) || 0 !== (int) $index['Non_unique'] ) {
				return false;
			}
			$columns[] = $index['Column_name'];
		}

		return array( $column ) === $columns;
	}

	/**
	 * The steps of install(), with database errors hidden.
	 *
	 * @return bool Whether the schema is installed and its version recorded.
	 */
	private static function install_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table     = $wpdb->prefix . self::ANALYSES_TABLE;
		$installed = self::stored_version();
		$exists    = self::table_exists( $table );
		if ( null === $installed || null === $exists ) {
			// Unknown state: renaming nothing could let dbDelta() add empty columns next to old ones.
			return false;
		}
		$upgrading = $installed > 0 && $installed < self::VERSION && $exists;

		if ( $upgrading && ! self::rename_columns( $table ) ) {
			return false;
		}

		dbDelta( self::analyses_table_sql( $table, $wpdb->get_charset_collate() ) );

		if ( ! self::verify( $table ) ) {
			return false;
		}

		if ( 1 === $installed ) {
			// v1 recorded no settle outcome for analyses that ended before settling.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema upgrade.
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET settle_outcome = %s WHERE settle_outcome IS NULL AND status IN (%s, %s, %s)',
					$table,
					SettleOutcome::NOT_APPLICABLE,
					AnalysisStatus::FAILED,
					AnalysisStatus::INCOMPATIBLE,
					AnalysisStatus::ABANDONED
				)
			);
			if ( false === $updated ) {
				return false;
			}
		}

		if ( $upgrading && $installed < 3 ) {
			// Finished analyses from before WP-Cron observation never captured Cron.
			// Open ones resolve the same way when they end (no Cron BEFORE snapshot).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema upgrade.
			$updated = $wpdb->query(
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
			if ( false === $updated ) {
				return false;
			}
		}

		if ( $upgrading && $installed < 4 ) {
			// Finished analyses from before Action Scheduler observation never captured it.
			// Open ones resolve when they end (no Action Scheduler BEFORE or IMMEDIATE snapshot).
			// Options and Cron columns are not touched.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema upgrade.
			$updated = $wpdb->query(
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
			if ( false === $updated ) {
				return false;
			}
		}

		// The data updates above are repeatable: an install that fails before
		// the version is recorded runs them again.
		update_option( self::VERSION_OPTION, self::VERSION, true );

		return self::VERSION === self::stored_version();
	}

	/**
	 * Drop the tables, the version option and the retry marker. Used on uninstall.
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . self::ANALYSES_TABLE ) );

		delete_option( self::VERSION_OPTION );
		delete_option( self::RETRY_OPTION );
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
	 * `site_check_*_before` / `_reason` and `site_check_update_started_at` are
	 * written once, when the analysis is created, and kept on every final
	 * state. `site_check_after_started_at` and `site_check_*_after` /
	 * `_after_reason` are written after the analysis completed, each at most
	 * once (guarded updates, Update\SiteCheckAfter).
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
  immediate_captured_at decimal(16,6) DEFAULT NULL,
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
  site_check_home_before text DEFAULT NULL,
  site_check_home_before_reason varchar(40) DEFAULT NULL,
  site_check_rest_before text DEFAULT NULL,
  site_check_rest_before_reason varchar(40) DEFAULT NULL,
  site_check_update_started_at decimal(16,6) DEFAULT NULL,
  site_check_after_started_at decimal(16,6) DEFAULT NULL,
  site_check_home_after text DEFAULT NULL,
  site_check_home_after_reason varchar(40) DEFAULT NULL,
  site_check_rest_after text DEFAULT NULL,
  site_check_rest_after_reason varchar(40) DEFAULT NULL,
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
	 * @return bool|null Null when the query failed.
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return '' === $wpdb->last_error ? $table === $found : null;
	}
}
