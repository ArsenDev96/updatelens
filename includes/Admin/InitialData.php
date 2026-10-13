<?php
/**
 * Data of the UpdateLens screen, supplied with the admin page itself.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Admin;

use Throwable;
use UpdateLens\Report\AnalysisReports;
use UpdateLens\Report\ReportTooLargeException;

defined( 'ABSPATH' ) || exit;

/**
 * The stored report or History page the screen's URL asks for, read with the
 * same read models as the REST endpoints (AnalysisReports, MonitoringStart)
 * and printed into the authenticated admin page, so stored reports and the
 * History stay readable when the site's REST API does not answer (a security
 * rule, PHP output before its JSON, a fatal error in `rest_api_init`). It
 * never calls the REST API, internally or over HTTP. Live refreshes still use
 * REST.
 *
 * Pure: the readers and the access decision are passed in (AdminPage wires
 * WordPress). Only the screen's own query arguments are read, validated like
 * the app parses them (src/admin/utils/route.ts): `analysis` (a report) or
 * `paged` (a History page). One report or one History page of
 * AnalysisReports::DEFAULT_PER_PAGE items, never the whole history; a report
 * is budgeted like a REST read (ReportTooLargeException), and anything that
 * would encode to more than MAX_JSON_BYTES is left to REST.
 */
final class InitialData {

	/**
	 * Global the app reads (src/admin/api/initial-data.ts).
	 */
	const GLOBAL_NAME = 'updatelensInitialData';

	/**
	 * Largest encoded payload printed into the page (4 MiB); larger ones are
	 * left to the REST API.
	 */
	const MAX_JSON_BYTES = 4194304;

	/**
	 * Largest History page supplied (the app reads anything beyond over REST).
	 */
	const MAX_PAGE = 100000;

	/**
	 * Longest accepted number in the URL (larger IDs are not analyses).
	 */
	const MAX_DIGITS = 15;

	/**
	 * The data for the screen's query, or null when none is supplied (no
	 * access, or nothing to read).
	 *
	 * @param array<string, mixed> $query      The request's query arguments (unslashed).
	 * @param bool                 $allowed    Whether the user may read reports (the REST permission).
	 * @param callable             $history    function ( int $page, int $per_page ): array{items: array, total: int} (AnalysisReports::history()).
	 * @param callable             $report     function ( int $id ): array|null (AnalysisReports::report()).
	 * @param callable             $monitoring function (): array (MonitoringStart::read()).
	 * @return array<string, mixed>|null
	 */
	public static function build( array $query, $allowed, callable $history, callable $report, callable $monitoring ) {
		if ( true !== $allowed ) {
			return null;
		}

		$id = self::positive_int( isset( $query['analysis'] ) ? $query['analysis'] : null );
		if ( null !== $id ) {
			return self::report( $id, $report );
		}

		$page = self::positive_int( isset( $query['paged'] ) ? $query['paged'] : null );
		$page = null === $page ? 1 : $page;
		if ( $page > self::MAX_PAGE ) {
			return null;
		}

		return self::history( $page, $history, $monitoring );
	}

	/**
	 * A report, or why there is none: `not_found`, `too_large` (the REST
	 * endpoint's `updatelens_report_too_large`) or `unavailable` (storage
	 * could not be read; the app then asks the REST API).
	 *
	 * @param int      $id     Analysis ID.
	 * @param callable $report Reader.
	 * @return array<string, mixed>
	 */
	private static function report( $id, callable $report ) {
		$data = array(
			'view' => 'report',
			'id'   => $id,
		);
		try {
			$read = call_user_func( $report, $id );
		} catch ( ReportTooLargeException $e ) {
			return $data + array( 'status' => 'too_large' );
		} catch ( Throwable $e ) {
			return $data + array( 'status' => 'unavailable' );
		}

		return null === $read
			? $data + array( 'status' => 'not_found' )
			: $data + array(
				'status' => 'ok',
				'report' => $read,
			);
	}

	/**
	 * A History page with its totals, plus the monitoring start where the
	 * screen shows it (the first-run screen without analyses, the History
	 * boundary on the last page). `unavailable` if storage could not be read.
	 *
	 * @param int      $page       Page.
	 * @param callable $history    Reader.
	 * @param callable $monitoring Monitoring start reader.
	 * @return array<string, mixed>
	 */
	private static function history( $page, callable $history, callable $monitoring ) {
		$per_page = AnalysisReports::DEFAULT_PER_PAGE;
		$data     = array(
			'view'     => 'history',
			'page'     => $page,
			'per_page' => $per_page,
		);
		try {
			$read = call_user_func( $history, $page, $per_page );
		} catch ( Throwable $e ) {
			return $data + array( 'status' => 'unavailable' );
		}
		$total       = (int) $read['total'];
		$total_pages = (int) ceil( $total / $per_page );

		$start = null;
		if ( 0 === $total || $page >= $total_pages ) {
			try {
				$start = call_user_func( $monitoring );
			} catch ( Throwable $e ) {
				$start = null;
			}
		}

		return $data + array(
			'status'      => 'ok',
			'items'       => array_values( $read['items'] ),
			'total'       => $total,
			'total_pages' => $total_pages,
			'monitoring'  => $start,
		);
	}

	/**
	 * The inline script that hands the data to the app, or null if it cannot
	 * be encoded or is too large. The JSON escapes `<`, `>`, `&` and quotes
	 * (and non-ASCII), so stored text can never end the script or become
	 * markup; the app renders every field as text.
	 *
	 * @param string|false $json Encoded data (wp_json_encode() with JSON_FLAGS).
	 * @return string|null
	 */
	public static function script( $json ) {
		if ( ! is_string( $json ) || '' === $json || strlen( $json ) > self::MAX_JSON_BYTES ) {
			return null;
		}

		return 'window.' . self::GLOBAL_NAME . ' = ' . $json . ';';
	}

	/**
	 * Flags for encoding the data into a script.
	 */
	const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

	/**
	 * A positive integer from a query argument, as the app parses it (digits
	 * only), or null.
	 *
	 * @param mixed $value Query value.
	 * @return int|null
	 */
	private static function positive_int( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[0-9]{1,' . self::MAX_DIGITS . '}\z/', $value ) ) {
			return null;
		}
		$number = (int) $value;

		return $number > 0 ? $number : null;
	}
}
