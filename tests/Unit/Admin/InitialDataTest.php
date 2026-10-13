<?php
/**
 * Tests for the data printed into the UpdateLens screen.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\Admin\InitialData;
use UpdateLens\Report\ReportTooLargeException;

/**
 * InitialData: the stored report or History page the screen's URL asks for,
 * read without the REST API, only for users who may read reports.
 */
final class InitialDataTest extends TestCase {

	/**
	 * Reader calls, for asserting what was read.
	 *
	 * @var array<int, array>
	 */
	private $calls = array();

	/**
	 * Build with recording readers.
	 *
	 * @param array<string, mixed> $query   Query arguments.
	 * @param bool                 $allowed Whether the user may read reports.
	 * @param array                $options Reader results (`report`, `history`, `monitoring`; a Throwable is thrown).
	 * @return array<string, mixed>|null
	 */
	private function build( array $query, $allowed = true, array $options = array() ) {
		$options += array(
			'report'     => array( 'id' => 7 ),
			'history'    => array(
				'items' => array( array( 'id' => 3 ) ),
				'total' => 45,
			),
			'monitoring' => array( 'started_at' => '2026-10-13T10:00:00Z' ),
		);
		$answer   = static function ( $value ) {
			if ( $value instanceof \Throwable ) {
				throw $value;
			}
			return $value;
		};

		return InitialData::build(
			$query,
			$allowed,
			function ( $page, $per_page ) use ( $options, $answer ) {
				$this->calls[] = array( 'history', $page, $per_page );
				return $answer( $options['history'] );
			},
			function ( $id ) use ( $options, $answer ) {
				$this->calls[] = array( 'report', $id );
				return $answer( $options['report'] );
			},
			function () use ( $options, $answer ) {
				$this->calls[] = array( 'monitoring' );
				return $answer( $options['monitoring'] );
			}
		);
	}

	/**
	 * Without the reports capability nothing is read or supplied.
	 */
	public function test_nothing_without_access() {
		$this->assertNull( $this->build( array( 'analysis' => '7' ), false ) );
		$this->assertNull( $this->build( array(), false ) );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * A report the URL names, read once, as the REST endpoint returns it.
	 */
	public function test_report() {
		$this->assertSame(
			array(
				'view'   => 'report',
				'id'     => 7,
				'status' => 'ok',
				'report' => array( 'id' => 7 ),
			),
			$this->build( array( 'analysis' => '7' ) )
		);
		$this->assertSame( array( array( 'report', 7 ) ), $this->calls );
	}

	/**
	 * Missing, too large and unreadable reports have a status, never data.
	 */
	public function test_report_statuses() {
		$this->assertSame( 'not_found', $this->build( array( 'analysis' => '8' ), true, array( 'report' => null ) )['status'] );
		$too_large = $this->build( array( 'analysis' => '8' ), true, array( 'report' => new ReportTooLargeException() ) );
		$this->assertSame( 'too_large', $too_large['status'] );
		$this->assertArrayNotHasKey( 'report', $too_large );
		$this->assertSame( 'unavailable', $this->build( array( 'analysis' => '8' ), true, array( 'report' => new RuntimeException( 'SQL secret detail' ) ) )['status'] );
	}

	/**
	 * Only digit strings are IDs, as the app parses them; anything else is the
	 * History (which the app then shows too), never a report read.
	 */
	public function test_invalid_ids_are_never_read() {
		foreach ( array( '0', '-3', '1e3', '12abc', ' 5', '5 ', '0b1', '', '1234567890123456', array( '7' ), 7.0, null ) as $value ) {
			$this->calls = array();
			$data        = $this->build( array( 'analysis' => $value ) );
			$this->assertSame( 'history', $data['view'], gettype( $value ) . ' ' . ( is_scalar( $value ) ? (string) $value : '' ) );
			$this->assertSame( 'history', $this->calls[0][0] );
		}
		$this->assertSame( 123456789012345, $this->build( array( 'analysis' => '123456789012345' ) )['id'] );
	}

	/**
	 * One History page of the default size with its totals; the monitoring
	 * start only where the screen shows it (first run, last page).
	 */
	public function test_history_page() {
		$data = $this->build( array( 'paged' => '2' ) );
		$this->assertSame(
			array(
				'view'        => 'history',
				'page'        => 2,
				'per_page'    => 20,
				'status'      => 'ok',
				'items'       => array( array( 'id' => 3 ) ),
				'total'       => 45,
				'total_pages' => 3,
				'monitoring'  => null,
			),
			$data
		);
		$this->assertSame( array( array( 'history', 2, 20 ) ), $this->calls );

		$this->assertSame( array( 'started_at' => '2026-10-13T10:00:00Z' ), $this->build( array( 'paged' => '3' ) )['monitoring'] );
		$first_run = $this->build(
			array(),
			true,
			array(
				'history' => array(
					'items' => array(),
					'total' => 0,
				),
			)
		);
		$this->assertSame( 1, $first_run['page'] );
		$this->assertSame( array( 'started_at' => '2026-10-13T10:00:00Z' ), $first_run['monitoring'] );
	}

	/**
	 * Invalid pages are page 1, like the app; pages beyond MAX_PAGE are left
	 * to REST; an unreadable monitoring start is just unknown.
	 */
	public function test_history_inputs_and_failures() {
		foreach ( array( '0', '-1', 'x', array( '2' ) ) as $value ) {
			$this->assertSame( 1, $this->build( array( 'paged' => $value ) )['page'] );
		}
		$this->assertNull( $this->build( array( 'paged' => (string) ( InitialData::MAX_PAGE + 1 ) ) ) );
		$this->assertSame( 'unavailable', $this->build( array(), true, array( 'history' => new RuntimeException() ) )['status'] );
		$this->assertNull( $this->build( array( 'paged' => '3' ), true, array( 'monitoring' => new RuntimeException() ) )['monitoring'] );
	}

	/**
	 * Stored text cannot end the script or become markup: the encoding
	 * escapes `<`, `>`, `&` and quotes, and decodes to the same text.
	 */
	public function test_script_escapes_stored_text() {
		$hostile = '</script><script>alert(1)</script><!-- & \' " ' . "\u{2028}" . ' <img src=x onerror=alert(2)>';
		$data    = array(
			'view'   => 'report',
			'status' => 'ok',
			'report' => array( 'plugin' => array( 'name' => $hostile ) ),
		);
		$script  = InitialData::script( json_encode( $data, InitialData::JSON_FLAGS ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests; same flags as wp_json_encode().

		$this->assertStringStartsWith( 'window.updatelensInitialData = {', $script );
		foreach ( array( '<', '>', '&', "'", "\u{2028}" ) as $character ) {
			$this->assertStringNotContainsString( $character, $script );
		}
		$json = substr( $script, strlen( 'window.updatelensInitialData = ' ), -1 );
		$this->assertSame( $hostile, json_decode( $json, true )['report']['plugin']['name'] );
	}

	/**
	 * Unencodable or oversized data is not printed (the app uses REST).
	 */
	public function test_script_limits() {
		$this->assertNull( InitialData::script( false ) );
		$this->assertNull( InitialData::script( '' ) );
		$this->assertNull( InitialData::script( '"' . str_repeat( 'a', InitialData::MAX_JSON_BYTES ) . '"' ) );
		$this->assertSame( 'window.updatelensInitialData = {"a":1};', InitialData::script( '{"a":1}' ) );
	}
}
