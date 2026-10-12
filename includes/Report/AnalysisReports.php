<?php
/**
 * Read access to analysis reports.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Report;

use RuntimeException;
use UpdateLens\Snapshot\MemoryBudget;
use UpdateLens\Storage\AnalysisRepository;
use UpdateLens\Storage\Schema;
use UpdateLens\Update\PluginUpdateAnalyzer;

defined( 'ABSPATH' ) || exit;

/**
 * History pages and single reports, as safe read models.
 *
 * Read-only, except for lifecycle maintenance before every read: the
 * analyses table is recreated if it went missing, and analyses past their
 * settle deadline are expired (PluginUpdateAnalyzer::expire_overdue()), so a
 * report is never shown as waiting after its deadline.
 */
final class AnalysisReports {

	/**
	 * Default history page size.
	 */
	const DEFAULT_PER_PAGE = 20;

	/**
	 * Maximum history page size.
	 */
	const MAX_PER_PAGE = 100;

	/**
	 * Memory a report read needs per byte of its stored phase diffs, in
	 * bytes: the decoded diffs, Potential Impact and the JSON response.
	 * Measured on real report reads of 12.5 and 20.4 MB of stored diffs: at
	 * most 9.7 (PHP 8.1) / 9.6 (PHP 7.4), plus about a third.
	 */
	const BYTES_PER_STORED_BYTE = 13;

	/**
	 * Analysis storage.
	 *
	 * @var AnalysisRepository
	 */
	private $repository;

	/**
	 * Lifecycle (expiration only).
	 *
	 * @var PluginUpdateAnalyzer
	 */
	private $analyzer;

	/**
	 * Makes sure the schema exists; returns false if it cannot.
	 *
	 * @var callable
	 */
	private $ensure_schema;

	/**
	 * Read model.
	 *
	 * @var AnalysisReadModel
	 */
	private $read_model;

	/**
	 * Memory available to the request, or null for the running request's.
	 *
	 * @var MemoryBudget|null
	 */
	private $memory;

	/**
	 * Constructor.
	 *
	 * @param AnalysisRepository   $repository    Analysis storage.
	 * @param PluginUpdateAnalyzer $analyzer      Lifecycle, for expiring overdue analyses.
	 * @param callable             $ensure_schema Returns whether the analyses table exists, creating it if needed.
	 * @param MemoryBudget|null    $memory        Memory available to the request; null: the running request's.
	 */
	public function __construct( AnalysisRepository $repository, PluginUpdateAnalyzer $analyzer, callable $ensure_schema, ?MemoryBudget $memory = null ) {
		$this->repository    = $repository;
		$this->analyzer      = $analyzer;
		$this->ensure_schema = $ensure_schema;
		$this->read_model    = new AnalysisReadModel();
		$this->memory        = $memory;
	}

	/**
	 * Reports for the running site.
	 *
	 * @return self
	 */
	public static function create() {
		global $wpdb;

		return new self( new AnalysisRepository( $wpdb ), PluginUpdateAnalyzer::create(), array( Schema::class, 'repair' ) );
	}

	/**
	 * One page of history, newest first.
	 *
	 * A page past the end is empty.
	 *
	 * @param int $page     1-based page number.
	 * @param int $per_page Page size (1 to MAX_PER_PAGE).
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 * @throws RuntimeException If the analyses cannot be read.
	 */
	public function history( $page, $per_page ) {
		$page     = max( 1, (int) $page );
		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) $per_page ) );

		$this->maintain();

		$total  = $this->repository->count_all();
		$offset = ( $page - 1 ) * $per_page;
		$items  = array();
		if ( $offset < $total ) {
			foreach ( $this->repository->find_page( $per_page, $offset ) as $row ) {
				$items[] = $this->read_model->history_item( $row );
			}
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * One full report.
	 *
	 * Decoding a report holds every stored phase diff at once, as arrays, and
	 * then its JSON response: far more than the stored bytes, and more than
	 * any one observation request held. A request that cannot afford it
	 * (BYTES_PER_STORED_BYTE times the stored diffs, measured in SQL before
	 * anything is read) fails with ReportTooLargeException instead of
	 * running out of memory, which would be a fatal error.
	 *
	 * @param int $id Analysis ID.
	 * @return array<string, mixed>|null Null if there is no such analysis.
	 * @throws ReportTooLargeException If the report does not fit in memory (other read failures: RuntimeException).
	 */
	public function report( $id ) {
		$this->maintain();

		$bytes = $this->repository->find_report_bytes( (int) $id );
		if ( null === $bytes ) {
			return null;
		}
		$memory = null === $this->memory ? MemoryBudget::from_php() : $this->memory;
		if ( ! $memory->allows( (float) $bytes * self::BYTES_PER_STORED_BYTE ) ) {
			throw new ReportTooLargeException();
		}

		$row = $this->repository->find_report( (int) $id );

		return null === $row ? null : $this->read_model->report( $row );
	}

	/**
	 * Lifecycle maintenance before a read.
	 *
	 * @return void
	 * @throws RuntimeException If the analyses table is missing and cannot be created.
	 */
	private function maintain() {
		if ( ! call_user_func( $this->ensure_schema ) ) {
			throw new RuntimeException( 'UpdateLens reports are unavailable.' );
		}

		$this->analyzer->expire_overdue();
	}
}
