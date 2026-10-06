<?php
/**
 * Captures the active Action Scheduler state.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the pending and in-progress actions of Action Scheduler's database
 * store and hands them to ActionSchedulerSnapshotBuilder.
 *
 * Supported: ActionScheduler_DBStore (the default store since Action
 * Scheduler 3.0), with the `actionscheduler_actions` and
 * `actionscheduler_groups` tables of Action Scheduler 3.1.6 to 4.2.0
 * (schema versions 3 to 9). Also ActionScheduler_HybridStore while its
 * legacy post store holds no pending or in-progress actions: Action
 * Scheduler switches to it after a fresh install and after any plugin
 * deactivation until its migration hook runs again, and it saves new
 * actions to the same tables, so the tables then hold every active action.
 * Anything else (custom stores, DBStore subclasses, a migration with legacy
 * actions left) is reported as unavailable with a reason
 * (ActionSchedulerUnavailableException), never as an empty snapshot.
 *
 * Read-only: it never schedules, cancels, claims or runs actions, never
 * initializes Action Scheduler or its tables, and only issues SELECT and
 * SHOW statements on the third-party tables. It does not use Action
 * Scheduler's query API, which can trigger store work.
 *
 * Query: only active rows are read, in batches ordered by `action_id` with
 * a cursor (`action_id > last`), never OFFSET, using Action Scheduler's own
 * indexes. While active rows are a small share of the table (the normal
 * case), MySQL serves `status IN ( 'pending', 'in-progress' )` from the
 * `status_scheduled_date_gmt` index and sorts only the remaining active
 * rows, so the complete/failed/canceled history is not read. When most rows
 * are active, it walks the primary key instead, which reads the table at
 * most once across all batches. Batches are
 * separate statements, so a large snapshot is not atomic (like the options
 * snapshot): a queue run during capture can complete actions already read,
 * or add rows (with higher IDs) that are read later. Rows are never read
 * twice and rows present for the whole capture are never missed.
 */
final class ActionSchedulerSnapshotProvider {

	/**
	 * Store class whose tables hold every active action.
	 */
	const DB_STORE = 'ActionScheduler_DBStore';

	/**
	 * Migration store: supported only while its legacy post store has no active actions.
	 */
	const HYBRID_STORE = 'ActionScheduler_HybridStore';

	/**
	 * Post type of the legacy post store (ActionScheduler_wpPostStore::POST_TYPE).
	 */
	const LEGACY_POST_TYPE = 'scheduled-action';

	/**
	 * Rows per query.
	 */
	const BATCH_SIZE = 500;

	/**
	 * Action Scheduler's actions table, without the site prefix.
	 */
	const ACTIONS_TABLE = 'actionscheduler_actions';

	/**
	 * Action Scheduler's groups table, without the site prefix.
	 */
	const GROUPS_TABLE = 'actionscheduler_groups';

	/**
	 * Columns read from the actions table (present since schema version 3).
	 */
	const ACTION_COLUMNS = array( 'action_id', 'hook', 'status', 'scheduled_date_gmt', 'args', 'extended_args', 'schedule', 'group_id' );

	/**
	 * Columns read from the groups table.
	 */
	const GROUP_COLUMNS = array( 'group_id', 'slug' );

	/**
	 * Snapshot builder.
	 *
	 * @var ActionSchedulerSnapshotBuilder
	 */
	private $builder;

	/**
	 * Constructor.
	 *
	 * @param ActionSchedulerSnapshotBuilder $builder Snapshot builder.
	 */
	public function __construct( ActionSchedulerSnapshotBuilder $builder ) {
		$this->builder = $builder;
	}

	/**
	 * Provider wired to the running WordPress site.
	 *
	 * @return self
	 */
	public static function create() {
		return new self( new ActionSchedulerSnapshotBuilder( ActionSchedulerArgsHasher::from_wordpress() ) );
	}

	/**
	 * Snapshot the current active actions.
	 *
	 * @return ActionSchedulerSnapshot
	 * @throws ActionSchedulerUnavailableException     If Action Scheduler state cannot be read on this site.
	 * @throws MalformedActionSchedulerStateException If an active action cannot be normalized.
	 */
	public function capture() {
		$this->assert_available();

		return $this->builder->build( $this->active_rows() );
	}

	/**
	 * Whether a snapshot can be taken: `available`, or the unavailability reason.
	 *
	 * @return string `available` or an ActionSchedulerUnavailableException reason.
	 */
	public function get_availability() {
		try {
			$this->assert_available();
		} catch ( ActionSchedulerUnavailableException $e ) {
			return $e->get_reason();
		}

		return 'available';
	}

	/**
	 * Check that Action Scheduler runs with a supported store and schema.
	 *
	 * @return void
	 * @throws ActionSchedulerUnavailableException If not.
	 */
	private function assert_available() {
		// No autoloading: only an Action Scheduler some active plugin already loaded counts.
		if ( ! class_exists( 'ActionScheduler', false ) ) {
			throw ActionSchedulerUnavailableException::not_installed();
		}
		// Versions before 3.1.6 cannot tell whether the store is initialized.
		if ( ! method_exists( 'ActionScheduler', 'is_initialized' ) ) {
			throw ActionSchedulerUnavailableException::unsupported_store();
		}
		// The store singleton exists from plugins_loaded on, but reading it before `init` would be premature.
		if ( ! \ActionScheduler::is_initialized() ) {
			throw ActionSchedulerUnavailableException::not_initialized();
		}
		// Exact classes: subclasses and custom stores may keep actions elsewhere.
		$store = get_class( \ActionScheduler::store() );
		if ( self::DB_STORE !== $store && ( self::HYBRID_STORE !== $store || self::has_legacy_active_actions() ) ) {
			throw ActionSchedulerUnavailableException::unsupported_store();
		}

		if ( ! self::has_columns( self::ACTIONS_TABLE, self::ACTION_COLUMNS ) || ! self::has_columns( self::GROUPS_TABLE, self::GROUP_COLUMNS ) ) {
			throw ActionSchedulerUnavailableException::unsupported_schema();
		}
	}

	/**
	 * Whether the legacy post store still holds pending or in-progress actions.
	 *
	 * @return bool
	 * @throws ActionSchedulerUnavailableException If the query fails.
	 */
	private static function has_legacy_active_actions() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only; must reflect the current state.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ( %s, %s ) LIMIT 1",
				self::LEGACY_POST_TYPE,
				ActionSchedulerActionRecord::STATUS_PENDING,
				ActionSchedulerActionRecord::STATUS_IN_PROGRESS
			)
		);
		self::assert_no_error();

		return null !== $found;
	}

	/**
	 * Whether a table exists with the given columns.
	 *
	 * @param string   $table   Table name without prefix.
	 * @param string[] $columns Required columns.
	 * @return bool
	 * @throws ActionSchedulerUnavailableException If the query fails.
	 */
	private static function has_columns( $table, array $columns ) {
		global $wpdb;

		$name = $wpdb->prefix . $table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only metadata of a third-party table; must reflect the current schema.
		if ( $name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) ) {
			self::assert_no_error();
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only metadata of a third-party table; must reflect the current schema.
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $name ) );
		self::assert_no_error();

		return array() === array_diff( $columns, $found );
	}

	/**
	 * Active rows, batch by batch.
	 *
	 * @return \Generator<int, array<string, mixed>>
	 * @throws ActionSchedulerUnavailableException If a query fails.
	 */
	private function active_rows() {
		global $wpdb;

		$actions = $wpdb->prefix . self::ACTIONS_TABLE;
		$groups  = $wpdb->prefix . self::GROUPS_TABLE;
		$cursor  = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only snapshot of a third-party table; must reflect the current state.
			$batch = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT a.action_id, a.hook, a.status, a.scheduled_date_gmt, a.args, a.extended_args, a.schedule, g.slug AS group_slug
					FROM %i a LEFT JOIN %i g ON g.group_id = a.group_id
					WHERE a.status IN ( %s, %s ) AND a.action_id > %d
					ORDER BY a.action_id
					LIMIT %d',
					$actions,
					$groups,
					ActionSchedulerActionRecord::STATUS_PENDING,
					ActionSchedulerActionRecord::STATUS_IN_PROGRESS,
					$cursor,
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			self::assert_no_error();
			$batch = is_array( $batch ) ? $batch : array();

			foreach ( $batch as $row ) {
				$cursor = (int) $row['action_id'];
				yield array(
					'hook'               => $row['hook'],
					'status'             => $row['status'],
					'scheduled_date_gmt' => $row['scheduled_date_gmt'],
					'args'               => $row['args'],
					'extended_args'      => $row['extended_args'],
					'schedule'           => $row['schedule'],
					'group'              => $row['group_slug'],
				);
			}
			$full = count( $batch ) === self::BATCH_SIZE;
		} while ( $full );
	}

	/**
	 * Fail on a database error instead of reading a partial state.
	 *
	 * @return void
	 * @throws ActionSchedulerUnavailableException If the last query failed.
	 */
	private static function assert_no_error() {
		global $wpdb;

		if ( '' !== (string) $wpdb->last_error ) {
			throw ActionSchedulerUnavailableException::read_failed();
		}
	}
}
