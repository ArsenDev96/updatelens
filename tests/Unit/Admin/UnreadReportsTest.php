<?php
/**
 * Tests for the per-user count of new UpdateLens reports.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use UpdateLens\Admin\UnreadReports;
use UpdateLens\Tests\Support\InMemoryAnalysisRepository;

/**
 * UnreadReports against an in-memory History and option/user-meta stand-ins.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class UnreadReportsTest extends TestCase {

	/**
	 * Analyses (History lists every row).
	 *
	 * @var InMemoryAnalysisRepository
	 */
	private $repository;

	/**
	 * Stored origin, or false.
	 *
	 * @var string|false
	 */
	private $origin = false;

	/**
	 * Stored watermarks by user ID.
	 *
	 * @var array<int, string>
	 */
	private $seen = array();

	/**
	 * Load the i18n/escaping stand-ins and start with an empty History.
	 *
	 * @before
	 */
	public function set_up_site() {
		require dirname( __DIR__, 2 ) . '/fixtures/wp-admin-menu-stubs.php';

		$this->repository = new InMemoryAnalysisRepository();
	}

	/**
	 * Unread tracking over the test state.
	 *
	 * @return UnreadReports
	 */
	private function unread() {
		return new UnreadReports(
			$this->repository,
			function () {
				return $this->origin;
			},
			function ( $id ) {
				if ( false === $this->origin ) {
					$this->origin = (string) $id;
				}
			},
			function ( $user_id ) {
				return $this->seen[ $user_id ] ?? '';
			},
			function ( $user_id, $id ) {
				$this->seen[ $user_id ] = (string) $id;
			}
		);
	}

	/**
	 * Add an analysis as the updater would (any status shows in History).
	 *
	 * @param string $status Analysis status.
	 * @return int ID.
	 */
	private function report( $status = 'completed' ) {
		static $n = 0;
		++$n;

		return $this->repository->create(
			array(
				'plugin_file' => "plugin-{$n}/plugin-{$n}.php",
				'status'      => $status,
			)
		);
	}

	/**
	 * Fresh install: origin 0, no count until the first report, which counts.
	 */
	public function test_fresh_install_counts_the_first_report() {
		$this->assertSame( 0, $this->unread()->origin() );
		$this->assertSame( '0', $this->origin );
		$this->assertSame( 0, $this->unread()->count_for_user( 1 ) );

		$this->report();
		$this->assertSame( 1, $this->unread()->count_for_user( 1 ) );

		$this->report();
		$this->report();
		$this->assertSame( 3, $this->unread()->count_for_user( 1 ) );
	}

	/**
	 * Upgrade with existing reports: they start as seen; later ones count.
	 */
	public function test_existing_reports_start_as_seen() {
		$this->report();
		$this->report();
		$this->report();

		$this->assertSame( 0, $this->unread()->count_for_user( 1 ) );
		$this->assertSame( '3', $this->origin );

		$this->report();
		$this->assertSame( 1, $this->unread()->count_for_user( 1 ) );
		$this->assertSame( 1, $this->unread()->count_for_user( 2 ), 'Users without a watermark start at the origin.' );
	}

	/**
	 * The origin is written once and never moved by later reports.
	 */
	public function test_origin_is_kept() {
		$this->unread()->origin();
		$this->report();
		$this->report();

		$this->assertSame( 0, $this->unread()->origin() );
		$this->assertSame( 2, $this->unread()->count_for_user( 1 ) );
	}

	/**
	 * Two administrators: each has their own watermark.
	 */
	public function test_watermarks_are_per_user() {
		$a = 1;
		$b = 2;
		$this->unread()->origin();

		$this->report();
		$this->assertSame( 1, $this->unread()->count_for_user( $a ) );
		$this->assertSame( 1, $this->unread()->count_for_user( $b ) );

		$this->unread()->mark_seen( $a );
		$this->assertSame( 0, $this->unread()->count_for_user( $a ) );
		$this->assertSame( 1, $this->unread()->count_for_user( $b ), 'A opening UpdateLens does not clear B.' );

		$this->report();
		$this->assertSame( 1, $this->unread()->count_for_user( $a ) );
		$this->assertSame( 2, $this->unread()->count_for_user( $b ) );

		$this->unread()->mark_seen( $b );
		$this->assertSame( 0, $this->unread()->count_for_user( $b ) );
		$this->assertSame( 1, $this->unread()->count_for_user( $a ) );
		$this->assertSame(
			array(
				$a => '1',
				$b => '2',
			),
			$this->seen,
			'Only IDs are stored.'
		);
	}

	/**
	 * Open, failed and abandoned analyses are History entries too.
	 */
	public function test_every_history_entry_counts() {
		$this->unread()->origin();
		$this->report( 'captured' );
		$this->report( 'awaiting_settle' );
		$this->report( 'failed' );
		$this->report( 'abandoned' );

		$this->assertSame( 4, $this->unread()->count_for_user( 1 ) );
	}

	/**
	 * Marking seen only moves forward; no reports leaves nothing to store.
	 */
	public function test_mark_seen_moves_forward_only() {
		$this->unread()->mark_seen( 1 );
		$this->assertSame( array(), $this->seen );

		$this->report();
		$this->report();
		$this->seen[1] = '5';
		$this->unread()->mark_seen( 1 );
		$this->assertSame( '5', $this->seen[1] );
	}

	/**
	 * The effective watermark never drops below the origin; unreadable values fall back to it.
	 */
	public function test_effective_last_seen() {
		$this->assertSame( 4, UnreadReports::effective_last_seen( 4, '' ) );
		$this->assertSame( 4, UnreadReports::effective_last_seen( 4, '2' ) );
		$this->assertSame( 9, UnreadReports::effective_last_seen( 4, '9' ) );
		$this->assertSame( 4, UnreadReports::effective_last_seen( 4, 'abc' ) );
		$this->assertSame( 4, UnreadReports::effective_last_seen( 4, '-3' ) );
		$this->assertSame( 4, UnreadReports::effective_last_seen( 4, array( 9 ) ) );
		$this->assertSame( 0, UnreadReports::effective_last_seen( -1, false ) );
	}

	/**
	 * Read failures show no count and store nothing (a later request retries).
	 */
	public function test_read_failure_shows_no_count() {
		$this->report();
		$this->repository->fail_reads = true;

		$this->assertNull( $this->unread()->origin() );
		$this->assertSame( 0, $this->unread()->count_for_user( 1 ) );
		$this->unread()->mark_seen( 1 );
		$this->assertFalse( $this->origin );
		$this->assertSame( array(), $this->seen );
	}

	/**
	 * No user (e.g. logged out): no count, nothing stored.
	 */
	public function test_no_user() {
		$this->report();

		$this->assertSame( 0, $this->unread()->count_for_user( 0 ) );
		$this->unread()->mark_seen( 0 );
		$this->assertSame( array(), $this->seen );
	}

	/**
	 * WordPress menu-count markup; none for 0; "99+" above 99 with the real number for screen readers.
	 */
	public function test_menu_count_markup() {
		$this->assertSame( '', UnreadReports::menu_count( 0 ) );
		$this->assertSame( '', UnreadReports::menu_count( -2 ) );
		$this->assertSame(
			' <span class="awaiting-mod count-1"><span class="pending-count" aria-hidden="true">1</span><span class="screen-reader-text">1 new UpdateLens report</span></span>',
			UnreadReports::menu_count( 1 )
		);
		$this->assertStringContainsString( 'aria-hidden="true">99</span><span class="screen-reader-text">99 new UpdateLens reports</span>', UnreadReports::menu_count( 99 ) );
		$this->assertSame(
			' <span class="awaiting-mod count-100"><span class="pending-count" aria-hidden="true">99+</span><span class="screen-reader-text">1,234 new UpdateLens reports</span></span>',
			UnreadReports::menu_count( 1234 )
		);
	}
}
