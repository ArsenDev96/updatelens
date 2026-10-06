<?php
/**
 * Persistence for plugin update analyses.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Storage;

use RuntimeException;
use UpdateLens\Update\AnalysisStatus;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows of the `updatelens_analyses` table.
 *
 * SQL only: lifecycle rules live in Update\PluginUpdateAnalyzer. Failed
 * writes throw instead of being ignored. Rows are plain arrays with the
 * column names as keys.
 */
class AnalysisRepository {

	/**
	 * Columns returned for open analyses: enough to decide what to do, without snapshots.
	 */
	const OPEN_COLUMNS = 'id, plugin_file, status, started_at, settle_deadline';

	/**
	 * Report metadata columns. Never snapshots, user IDs or stored error messages.
	 */
	const REPORT_METADATA_COLUMNS = 'id, plugin_file, plugin_name, version_before, version_after, status, settle_outcome, started_at, settle_deadline, completed_at, error_code';

	/**
	 * Columns of a history row: report metadata plus whether each phase diff is
	 * stored (NULL check only; the diff JSON is not read).
	 */
	const HISTORY_COLUMNS = self::REPORT_METADATA_COLUMNS . ', options_during_update_diff IS NOT NULL AS has_options_during_update_diff, options_post_update_diff IS NOT NULL AS has_options_post_update_diff, options_final_diff IS NOT NULL AS has_options_final_diff';

	/**
	 * Columns of a full report: report metadata plus the options and Cron
	 * phase diffs and Cron phase reasons. Never snapshots.
	 */
	const REPORT_COLUMNS = self::REPORT_METADATA_COLUMNS . ', options_during_update_diff, options_post_update_diff, options_final_diff, cron_during_update_diff, cron_post_update_diff, cron_final_diff, cron_during_update_reason, cron_post_update_reason, cron_final_reason';

	/**
	 * Database.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Full table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . Schema::ANALYSES_TABLE;
	}

	/**
	 * Insert a new analysis.
	 *
	 * @param array<string, mixed> $row Column values.
	 * @return int New analysis ID.
	 * @throws RuntimeException If the insert fails (including a second open analysis for the same plugin).
	 */
	public function create( array $row ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$inserted = $this->wpdb->insert( $this->table, $row );
		if ( 1 !== $inserted ) {
			throw new RuntimeException( 'UpdateLens could not create the analysis.' );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update an analysis only if it is still in the expected status.
	 *
	 * Compare-and-set, so a repeated or concurrent hook cannot apply the same
	 * transition twice.
	 *
	 * @param int                  $id          Analysis ID.
	 * @param string               $from_status Status the row must currently have.
	 * @param array<string, mixed> $changes     Column values to set.
	 * @return bool Whether the row was updated (false if its status had already changed).
	 * @throws RuntimeException If the query fails.
	 */
	public function transition( $id, $from_status, array $changes ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; must not be cached.
		$updated = $this->wpdb->update(
			$this->table,
			$changes,
			array(
				'id'     => (int) $id,
				'status' => (string) $from_status,
			)
		);
		if ( false === $updated ) {
			throw new RuntimeException( 'UpdateLens could not update the analysis.' );
		}

		return 1 === $updated;
	}

	/**
	 * Find one analysis.
	 *
	 * @param int $id Analysis ID.
	 * @return array<string, mixed>|null
	 * @throws RuntimeException If the query fails.
	 */
	public function find( $id ) {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; must not be cached.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, (int) $id ), ARRAY_A );
		$this->assert_no_error();

		return $row ? $row : null;
	}

	/**
	 * The open (non-terminal) analysis for a plugin, if any (metadata columns only).
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return array<string, mixed>|null
	 * @throws RuntimeException If the query fails.
	 */
	public function find_open_for_plugin( $plugin_file ) {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; must not be cached. Column list is a class constant.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::OPEN_COLUMNS . ' FROM %i WHERE active_plugin = %s', $this->table, (string) $plugin_file ), ARRAY_A );
		$this->assert_no_error();

		return $row ? $row : null;
	}

	/**
	 * All open (non-terminal) analyses, oldest first (metadata columns only).
	 *
	 * Runs at shutdown of wp-admin pages: uses the `status` index and reads at
	 * most one row per plugin (open analyses are unique per plugin), never the
	 * snapshot or diff columns.
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws RuntimeException If the query fails.
	 */
	public function find_open() {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; must not be cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Column list is a class constant.
				'SELECT ' . self::OPEN_COLUMNS . ' FROM %i WHERE status IN (%s, %s) ORDER BY id ASC',
				$this->table,
				AnalysisStatus::CAPTURED,
				AnalysisStatus::AWAITING_SETTLE
			),
			ARRAY_A
		);
		$this->assert_no_error();

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One page of analyses, newest first (HISTORY_COLUMNS).
	 *
	 * Ordered by primary key, so the page is read from the PK index without a sort.
	 *
	 * @param int $limit  Page size.
	 * @param int $offset Rows to skip.
	 * @return array<int, array<string, mixed>>
	 * @throws RuntimeException If the query fails.
	 */
	public function find_page( $limit, $offset ) {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; must not be cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Column list is a class constant.
				'SELECT ' . self::HISTORY_COLUMNS . ' FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
				$this->table,
				(int) $limit,
				(int) $offset
			),
			ARRAY_A
		);
		$this->assert_no_error();

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Number of analyses.
	 *
	 * @return int
	 * @throws RuntimeException If the query fails.
	 */
	public function count_all() {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; must not be cached.
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table ) );
		$this->assert_no_error();

		return (int) $count;
	}

	/**
	 * One analysis for a report (REPORT_COLUMNS: no snapshots), by primary key.
	 *
	 * @param int $id Analysis ID.
	 * @return array<string, mixed>|null
	 * @throws RuntimeException If the query fails.
	 */
	public function find_report( $id ) {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; must not be cached. Column list is a class constant.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::REPORT_COLUMNS . ' FROM %i WHERE id = %d', $this->table, (int) $id ), ARRAY_A );
		$this->assert_no_error();

		return $row ? $row : null;
	}

	/**
	 * Throw if the last query failed.
	 *
	 * @return void
	 * @throws RuntimeException If the last query failed.
	 */
	private function assert_no_error() {
		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'UpdateLens could not read analyses.' );
		}
	}
}
