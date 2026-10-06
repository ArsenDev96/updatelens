<?php
/**
 * In-memory AnalysisRepository for unit tests.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Support;

use RuntimeException;
use UpdateLens\Storage\AnalysisRepository;
use UpdateLens\Update\AnalysisStatus;

/**
 * Mirrors AnalysisRepository semantics (compare-and-set transitions, one
 * open analysis per plugin via `active_plugin`) without a database.
 */
final class InMemoryAnalysisRepository extends AnalysisRepository {

	/**
	 * Columns of a row, with defaults.
	 */
	const COLUMNS = array(
		'id'                                    => null,
		'plugin_file'                           => '',
		'plugin_name'                           => '',
		'version_before'                        => '',
		'version_after'                         => null,
		'user_id'                               => 0,
		'status'                                => '',
		'active_plugin'                         => null,
		'settle_outcome'                        => null,
		'started_at'                            => '',
		'updated_at'                            => '',
		'settle_deadline'                       => null,
		'completed_at'                          => null,
		'options_before_snapshot'               => null,
		'options_immediate_snapshot'            => null,
		'options_during_update_diff'            => null,
		'options_post_update_diff'              => null,
		'options_final_diff'                    => null,
		'cron_before_snapshot'                  => null,
		'cron_immediate_snapshot'               => null,
		'cron_during_update_diff'               => null,
		'cron_post_update_diff'                 => null,
		'cron_final_diff'                       => null,
		'cron_during_update_reason'             => null,
		'cron_post_update_reason'               => null,
		'cron_final_reason'                     => null,
		'action_scheduler_before_snapshot'      => null,
		'action_scheduler_immediate_snapshot'   => null,
		'action_scheduler_during_update_diff'   => null,
		'action_scheduler_post_update_diff'     => null,
		'action_scheduler_final_diff'           => null,
		'action_scheduler_during_update_reason' => null,
		'action_scheduler_post_update_reason'   => null,
		'action_scheduler_final_reason'         => null,
		'error_code'                            => null,
		'error_message'                         => null,
	);

	/**
	 * Cron columns holding snapshot or diff JSON.
	 */
	const CRON_PAYLOAD_COLUMNS = array( 'cron_before_snapshot', 'cron_immediate_snapshot', 'cron_during_update_diff', 'cron_post_update_diff', 'cron_final_diff' );

	/**
	 * Action Scheduler columns holding snapshot or diff JSON.
	 */
	const ACTION_SCHEDULER_PAYLOAD_COLUMNS = array( 'action_scheduler_before_snapshot', 'action_scheduler_immediate_snapshot', 'action_scheduler_during_update_diff', 'action_scheduler_post_update_diff', 'action_scheduler_final_diff' );

	/**
	 * When true, a write that stores any Action Scheduler snapshot or diff throws.
	 *
	 * @var bool
	 */
	public $fail_action_scheduler_writes = false;

	/**
	 * Every write attempted (create + transition), in order, as column values.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $attempted = array();

	/**
	 * When true, a write that stores any Cron snapshot or diff throws (e.g. a payload too large for the database).
	 *
	 * @var bool
	 */
	public $fail_cron_writes = false;

	/**
	 * Number of writes attempted (create + transition).
	 *
	 * @var int
	 */
	public $writes = 0;

	/**
	 * Throw if a write is set to fail.
	 *
	 * @param array $values Column values written.
	 * @return void
	 * @throws RuntimeException If the write fails.
	 */
	private function write( array $values ) {
		++$this->writes;
		$this->attempted[] = $values;
		if ( $this->fail_writes ) {
			throw new RuntimeException( 'write failed' );
		}
		if ( $this->fail_cron_writes && array_filter( array_intersect_key( $values, array_flip( self::CRON_PAYLOAD_COLUMNS ) ), 'is_string' ) ) {
			throw new RuntimeException( 'cron write failed' );
		}
		if ( $this->fail_action_scheduler_writes && array_filter( array_intersect_key( $values, array_flip( self::ACTION_SCHEDULER_PAYLOAD_COLUMNS ) ), 'is_string' ) ) {
			throw new RuntimeException( 'action scheduler write failed' );
		}
	}

	/**
	 * Rows by ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $rows = array();

	/**
	 * When true, every write throws like a failed query.
	 *
	 * @var bool
	 */
	public $fail_writes = false;

	/**
	 * No database.
	 */
	public function __construct() {
	}

	/**
	 * Insert a row.
	 *
	 * @param array $row Column values.
	 * @return int
	 * @throws RuntimeException On failure or a second open analysis for a plugin.
	 */
	public function create( array $row ) {
		$this->write( $row );
		if ( isset( $row['active_plugin'] ) && null !== $this->find_open_for_plugin( $row['active_plugin'] ) ) {
			throw new RuntimeException( 'duplicate active_plugin' );
		}

		$id                = count( $this->rows ) + 1;
		$this->rows[ $id ] = array_merge( self::COLUMNS, $row, array( 'id' => (string) $id ) );

		return $id;
	}

	/**
	 * Compare-and-set update.
	 *
	 * @param int    $id          ID.
	 * @param string $from_status Expected status.
	 * @param array  $changes     Column values.
	 * @return bool
	 * @throws RuntimeException On failure.
	 */
	public function transition( $id, $from_status, array $changes ) {
		$this->write( $changes );
		if ( ! isset( $this->rows[ $id ] ) || $this->rows[ $id ]['status'] !== $from_status ) {
			return false;
		}

		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $changes );

		return true;
	}

	/**
	 * Find a row.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public function find( $id ) {
		return isset( $this->rows[ $id ] ) ? $this->rows[ $id ] : null;
	}

	/**
	 * Open row for a plugin (metadata columns only, like the real repository).
	 *
	 * @param string $plugin_file Plugin.
	 * @return array|null
	 */
	public function find_open_for_plugin( $plugin_file ) {
		foreach ( $this->rows as $row ) {
			if ( $row['active_plugin'] === $plugin_file ) {
				return self::open_columns( $row );
			}
		}

		return null;
	}

	/**
	 * Open rows, oldest first (metadata columns only, like the real repository).
	 *
	 * @return array
	 */
	public function find_open() {
		$open = array_values(
			array_filter(
				$this->rows,
				static function ( $row ) {
					return in_array( $row['status'], array( AnalysisStatus::CAPTURED, AnalysisStatus::AWAITING_SETTLE ), true );
				}
			)
		);

		return array_map( array( self::class, 'open_columns' ), $open );
	}

	/**
	 * When true, every read throws like a failed query.
	 *
	 * @var bool
	 */
	public $fail_reads = false;

	/**
	 * Page of rows, newest first, projected like history_columns() (flags as "0"/"1" strings or NULL, like MySQL).
	 *
	 * @param int $limit  Page size.
	 * @param int $offset Offset.
	 * @return array
	 * @throws RuntimeException On failure.
	 */
	public function find_page( $limit, $offset ) {
		if ( $this->fail_reads ) {
			throw new RuntimeException( 'read failed' );
		}

		$rows = $this->rows;
		krsort( $rows );

		$page = array();
		foreach ( array_slice( $rows, (int) $offset, (int) $limit ) as $row ) {
			$item = self::project( $row, AnalysisRepository::REPORT_METADATA_COLUMNS );
			foreach ( AnalysisRepository::HISTORY_DIFFS as $column => $empty_prefix ) {
				$diff                             = isset( $row[ $column ] ) ? $row[ $column ] : null;
				$item[ 'has_' . $column ]         = null === $diff ? '0' : '1';
				$item[ 'has_changes_' . $column ] = null === $diff ? null : ( 0 === strpos( $diff, $empty_prefix ) ? '0' : '1' );
			}
			$page[] = $item;
		}

		return $page;
	}

	/**
	 * Number of rows.
	 *
	 * @return int
	 * @throws RuntimeException On failure.
	 */
	public function count_all() {
		if ( $this->fail_reads ) {
			throw new RuntimeException( 'read failed' );
		}

		return count( $this->rows );
	}

	/**
	 * Row projected like REPORT_COLUMNS.
	 *
	 * @param int $id ID.
	 * @return array|null
	 * @throws RuntimeException On failure.
	 */
	public function find_report( $id ) {
		if ( $this->fail_reads ) {
			throw new RuntimeException( 'read failed' );
		}

		return isset( $this->rows[ $id ] ) ? self::project( $this->rows[ $id ], AnalysisRepository::REPORT_COLUMNS ) : null;
	}

	/**
	 * Project a row to a comma-separated column list.
	 *
	 * @param array  $row     Row.
	 * @param string $columns Column list.
	 * @return array
	 */
	private static function project( array $row, $columns ) {
		return array_intersect_key( $row, array_flip( array_map( 'trim', explode( ',', $columns ) ) ) );
	}

	/**
	 * Project a row to AnalysisRepository::OPEN_COLUMNS.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function open_columns( array $row ) {
		return array_intersect_key( $row, array_flip( array_map( 'trim', explode( ',', AnalysisRepository::OPEN_COLUMNS ) ) ) );
	}
}
