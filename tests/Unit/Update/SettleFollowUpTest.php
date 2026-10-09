<?php
/**
 * Tests for the follow-up request and the settle timing rules.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\Tests\Support\FakeSite;
use UpdateLens\Update\AnalysisStatus;
use UpdateLens\Update\FollowUpRequest;
use UpdateLens\Update\PluginUpdateAnalyzer;
use UpdateLens\Update\SettleOutcome;

/**
 * Update request (BEFORE, IMMEDIATE) → follow-up or page request (SETTLED).
 *
 * The fake clock may carry fractions of a second, so requests that start
 * within the same second as IMMEDIATE can be ordered exactly.
 */
final class SettleFollowUpTest extends TestCase {

	const PLUGIN = 'acme/acme.php';

	const OTHER = 'other/other.php';

	const USER = 7;

	const T = 1767225600; // 2026-01-01T00:00:00Z.

	/**
	 * Site.
	 *
	 * @var FakeSite
	 */
	private $site;

	/**
	 * Fresh site.
	 *
	 * @before
	 */
	public function create_site() {
		$this->site = new FakeSite( array( 'blogname' => array( 'Site', 'on' ) ), self::T );
	}

	/**
	 * Run a successful update in its own request; IMMEDIATE is taken at $immediate_at.
	 *
	 * @param string     $plugin       Plugin file.
	 * @param int        $user_id      Updating user.
	 * @param float|null $immediate_at Time of IMMEDIATE. Default: the current fake time.
	 * @return PluginUpdateAnalyzer The update request.
	 */
	private function update( $plugin = self::PLUGIN, $user_id = self::USER, $immediate_at = null ) {
		$request = $this->site->request();
		$request->update_starting(
			$plugin,
			array(
				'name'    => 'Acme',
				'version' => '1.0.0',
			),
			$user_id
		);
		if ( null !== $immediate_at ) {
			$this->site->time = $immediate_at;
		}
		$this->site->options['acme_pending'] = array( 'yes', 'off' );
		$request->update_finished( $plugin, null, '1.1.0' );
		$request->request_ending( false );

		return $request;
	}

	/**
	 * The analysis of a plugin.
	 *
	 * @param string $plugin Plugin file.
	 * @return array
	 */
	private function row( $plugin = self::PLUGIN ) {
		foreach ( $this->site->repository->rows as $row ) {
			if ( $row['plugin_file'] === $plugin ) {
				return $row;
			}
		}
		$this->fail( 'No analysis for ' . $plugin );
	}

	/**
	 * Option names added in a stored diff.
	 *
	 * @param array  $row    Analysis.
	 * @param string $column Diff column.
	 * @return string[]
	 */
	private static function added( array $row, $column ) {
		return array_column( json_decode( $row[ $column ], true )['added'], 'name' );
	}

	/**
	 * Assert an analysis still awaits its settled snapshot, untouched.
	 *
	 * @param array $row Analysis.
	 * @return void
	 */
	private function assertAwaiting( array $row ) {
		$this->assertSame( AnalysisStatus::AWAITING_SETTLE, $row['status'] );
		$this->assertNull( $row['settle_outcome'] );
		$this->assertNull( $row['options_post_update_diff'] );
		$this->assertNull( $row['options_final_diff'] );
		$this->assertNotNull( $row['options_immediate_snapshot'] );
	}

	/**
	 * Normal successful Ajax update, the admin stays on plugins.php: the
	 * follow-up request runs the new code and takes the observation.
	 */
	public function test_follow_up_takes_the_post_update_observation() {
		$this->update();
		$row = $this->row();
		$this->assertAwaiting( $row );
		$this->assertSame( sprintf( '%.6F', self::T ), $row['immediate_captured_at'], 'IMMEDIATE time with microseconds.' );

		// Heartbeat and other Ajax requests from plugins.php never settle.
		$this->site->request()->request_ending( false );
		$this->assertAwaiting( $this->row() );

		// The follow-up request: the new code runs (plugins_loaded, init, admin_init) before the handler.
		$this->site->time                      += 2;
		$this->site->options['acme_db_version'] = array( '1.1.0', 'yes' );
		$results                                = $this->site->request()->settle_follow_up( array( self::PLUGIN ), self::USER );

		$this->assertSame( array( self::PLUGIN => FollowUpRequest::SETTLED ), $results );
		$row = $this->row();
		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( SettleOutcome::FOLLOW_UP, $row['settle_outcome'] );
		$this->assertSame( array( 'acme_db_version' ), self::added( $row, 'options_post_update_diff' ) );
		$this->assertSame( array( 'acme_db_version', 'acme_pending' ), self::added( $row, 'options_final_diff' ) );
		$this->assertSame( array( 'acme_pending' ), self::added( $row, 'options_during_update_diff' ), 'During update unchanged.' );
		$this->assertNull( $row['options_before_snapshot'], 'Temporary snapshots cleared.' );
		$this->assertNull( $row['options_immediate_snapshot'] );
		$this->assertNull( $row['active_plugin'] );

		// A duplicate follow-up finds nothing to settle and changes nothing.
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_PENDING ), $this->site->request()->settle_follow_up( array( self::PLUGIN ), self::USER ) );
		$this->assertSame( $row, $this->row() );
	}

	/**
	 * A follow-up never settles analyses it was not asked for.
	 */
	public function test_follow_up_settles_only_listed_plugins() {
		// Two updates running concurrently in two requests: neither settles the other.
		$first  = $this->site->request();
		$second = $this->site->request();
		$first->update_starting( self::PLUGIN, array(), self::USER );
		$second->update_starting( self::OTHER, array(), self::USER );
		$first->update_finished( self::PLUGIN, null, '1.1.0' );
		$second->update_finished( self::OTHER, null, '2.0.0' );
		$this->assertAwaiting( $this->row( self::PLUGIN ) );
		$this->assertAwaiting( $this->row( self::OTHER ) );

		$this->site->time += 1;
		$this->assertSame( array( self::OTHER => FollowUpRequest::SETTLED ), $this->site->request()->settle_follow_up( array( self::OTHER ), self::USER ) );

		$this->assertSame( SettleOutcome::FOLLOW_UP, $this->row( self::OTHER )['settle_outcome'] );
		$this->assertAwaiting( $this->row( self::PLUGIN ) );
	}

	/**
	 * Several updated plugins in one follow-up share one snapshot; results are per plugin.
	 */
	public function test_one_follow_up_for_several_plugins() {
		$first  = $this->site->request();
		$second = $this->site->request();
		$first->update_starting( self::PLUGIN, array(), self::USER );
		$second->update_starting( self::OTHER, array(), self::USER );
		$first->update_finished( self::PLUGIN, null, '1.1.0' );
		$second->update_finished( self::OTHER, null, '2.0.0' );
		$captures = $this->site->captures;

		$this->site->time += 1;
		$results           = $this->site->request()->settle_follow_up( array( self::PLUGIN, 'never/updated.php', self::OTHER ), self::USER );

		$this->assertSame(
			array(
				self::PLUGIN        => FollowUpRequest::SETTLED,
				'never/updated.php' => FollowUpRequest::NOT_PENDING,
				self::OTHER         => FollowUpRequest::SETTLED,
			),
			$results
		);
		$this->assertSame( $captures + 1, $this->site->captures, 'One settled snapshot for both.' );
	}

	/**
	 * Queued updates in one page: each next update ends the previous
	 * observation (`next_update`); the single follow-up after the queue settles the last.
	 */
	public function test_queued_updates() {
		$this->update( self::PLUGIN );
		$this->site->time += 3;
		$this->update( self::OTHER );

		$this->assertSame( SettleOutcome::NEXT_UPDATE, $this->row( self::PLUGIN )['settle_outcome'] );
		$this->assertAwaiting( $this->row( self::OTHER ) );

		$this->site->time += 1;
		$this->assertSame(
			array(
				self::PLUGIN => FollowUpRequest::NOT_PENDING,
				self::OTHER  => FollowUpRequest::SETTLED,
			),
			$this->site->request()->settle_follow_up( array( self::PLUGIN, self::OTHER ), self::USER )
		);
		$this->assertSame( SettleOutcome::NEXT_UPDATE, $this->row( self::PLUGIN )['settle_outcome'], 'Unchanged.' );
		$this->assertSame( SettleOutcome::FOLLOW_UP, $this->row( self::OTHER )['settle_outcome'] );
	}

	/**
	 * A failed update has no observation to settle.
	 */
	public function test_failed_update() {
		$request = $this->site->request();
		$request->update_starting( self::PLUGIN, array(), self::USER );
		$request->update_finished( self::PLUGIN, 'download_failed', null );

		$this->assertSame( AnalysisStatus::FAILED, $this->row()['status'] );
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_PENDING ), $this->site->request()->settle_follow_up( array( self::PLUGIN ), self::USER ) );
	}

	/**
	 * An update still running (BEFORE taken, no IMMEDIATE yet) is not settled.
	 */
	public function test_update_still_running() {
		$request = $this->site->request();
		$request->update_starting( self::PLUGIN, array(), self::USER );

		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_READY ), $this->site->request()->settle_follow_up( array( self::PLUGIN ), self::USER ) );
		$this->assertSame( AnalysisStatus::CAPTURED, $this->row()['status'] );
	}

	/**
	 * Only the user who ran the update can settle it with a follow-up.
	 */
	public function test_follow_up_of_another_user() {
		$this->update( self::PLUGIN, self::USER );
		$this->update( self::OTHER, 9 ); // Another administrator; ends the first observation (next_update).

		$this->site->time += 1;
		$this->assertSame(
			array( self::OTHER => FollowUpRequest::NOT_ALLOWED ),
			$this->site->request()->settle_follow_up( array( self::OTHER ), self::USER )
		);
		$this->assertSame( array( self::OTHER => FollowUpRequest::NOT_ALLOWED ), $this->site->request()->settle_follow_up( array( self::OTHER ), 0 ) );
		$this->assertAwaiting( $this->row( self::OTHER ) );

		$this->assertSame( array( self::OTHER => FollowUpRequest::SETTLED ), $this->site->request()->settle_follow_up( array( self::OTHER ), 9 ) );
	}

	/**
	 * After the observation window the follow-up expires the analysis, without a snapshot.
	 */
	public function test_expired_observation() {
		$this->update();
		$captures = $this->site->captures;

		$this->site->time += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS + 1;
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::EXPIRED ), $this->site->request()->settle_follow_up( array( self::PLUGIN ), self::USER ) );

		$row = $this->row();
		$this->assertSame( AnalysisStatus::COMPLETED, $row['status'] );
		$this->assertSame( SettleOutcome::EXPIRED, $row['settle_outcome'] );
		$this->assertNull( $row['options_post_update_diff'] );
		$this->assertNull( $row['options_final_diff'] );
		$this->assertSame( $captures, $this->site->captures, 'No late snapshot.' );
	}

	/**
	 * Exactly at the deadline the follow-up still settles (inclusive window).
	 */
	public function test_follow_up_at_the_deadline() {
		$this->update();

		$this->site->time += PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS;
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::SETTLED ), $this->site->request()->settle_follow_up( array( self::PLUGIN ), self::USER ) );
	}

	/**
	 * A capture failure stores nothing: the analysis keeps waiting and a
	 * later request can still take the observation.
	 */
	public function test_capture_failure() {
		$this->update();
		$this->site->time += 1;

		$request = $this->failing_capture_request();
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::CAPTURE_FAILED ), $request->settle_follow_up( array( self::PLUGIN ), self::USER ) );
		$this->assertAwaiting( $this->row() );

		$this->assertSame( array( self::PLUGIN => FollowUpRequest::SETTLED ), $this->site->request()->settle_follow_up( array( self::PLUGIN ), self::USER ) );
	}

	/**
	 * A request whose options capture throws.
	 *
	 * @return PluginUpdateAnalyzer
	 */
	private function failing_capture_request() {
		return new PluginUpdateAnalyzer(
			$this->site->repository,
			static function () {
				throw new RuntimeException( 'capture failed' );
			},
			static function () {
				throw new RuntimeException( 'capture failed' );
			},
			function () {
				return $this->site->time;
			},
			null,
			$this->site->time + 0.5
		);
	}

	/**
	 * A storage failure leaves the analysis waiting and is reported as such.
	 */
	public function test_storage_failure() {
		$this->update();
		$this->site->time                   += 1;
		$this->site->repository->fail_writes = true;

		$this->assertSame( array( self::PLUGIN => FollowUpRequest::STORAGE_FAILED ), $this->site->request()->settle_follow_up( array( self::PLUGIN ), self::USER ) );

		$this->site->repository->fail_writes = false;
		$this->assertAwaiting( $this->row() );
	}

	/**
	 * Concurrent settle attempts: a page request settles the analysis between
	 * the follow-up's read and its compare-and-set write. The follow-up's write
	 * is not applied, so there is exactly one Net result.
	 */
	public function test_compare_and_set_race() {
		$this->update();
		$this->site->time += 1;

		$follow_up                                 = $this->site->request();
		$this->site->repository->before_transition = function () {
			// The state moves on between the two snapshots.
			unset( $this->site->options['acme_follow_up'] );
			$this->site->options['acme_page_request'] = array( 'yes', 'off' );
			$this->site->request()->request_ending( true ); // The page request wins.
		};
		$this->site->options['acme_follow_up']     = array( 'yes', 'off' );

		$this->assertSame( array( self::PLUGIN => FollowUpRequest::CONFLICT ), $follow_up->settle_follow_up( array( self::PLUGIN ), self::USER ) );

		$row = $this->row();
		$this->assertSame( SettleOutcome::ADMIN_SHUTDOWN, $row['settle_outcome'], 'The first writer wins.' );
		$this->assertContains( 'acme_page_request', self::added( $row, 'options_final_diff' ) );
		$this->assertNotContains( 'acme_follow_up', self::added( $row, 'options_final_diff' ), 'The losing snapshot is not stored.' );
	}

	/**
	 * Two follow-ups for the same update (e.g. a double submission) settle it once.
	 */
	public function test_duplicate_follow_ups() {
		$this->update();
		$this->site->time += 1;

		$first  = $this->site->request();
		$second = $this->site->request();
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::SETTLED ), $first->settle_follow_up( array( self::PLUGIN ), self::USER ) );
		$row = $this->row();

		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_PENDING ), $second->settle_follow_up( array( self::PLUGIN ), self::USER ) );
		$this->assertSame( $row, $this->row() );
	}

	/**
	 * A request that started before IMMEDIATE was taken may have loaded the
	 * old plugin files: neither a follow-up nor a page request it ends may settle.
	 */
	public function test_request_started_before_immediate() {
		$early = $this->site->request( self::T + 0.25 ); // E.g. a page opened while the update ran.
		$this->update( self::PLUGIN, self::USER, self::T + 3.5 );

		$this->site->time = self::T + 9;
		$early->request_ending( true );
		$this->assertAwaiting( $this->row() );

		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_READY ), $early->settle_follow_up( array( self::PLUGIN ), self::USER ) );
		$this->assertAwaiting( $this->row() );

		// A request that started afterwards settles.
		$this->site->request( self::T + 9 )->request_ending( true );
		$this->assertSame( SettleOutcome::ADMIN_SHUTDOWN, $this->row()['settle_outcome'] );
	}

	/**
	 * Same-second ordering: IMMEDIATE at .600; requests that started at .300
	 * (before) are refused, at .600 (not after) too, at .700 (after) accepted.
	 */
	public function test_same_second_ordering() {
		$this->update( self::PLUGIN, self::USER, self::T + 0.6 );
		$this->assertSame( '1767225600.600000', $this->row()['immediate_captured_at'] );

		$this->site->time = self::T + 0.8;
		$this->site->request( self::T + 0.3 )->request_ending( true );
		$this->assertAwaiting( $this->row() );
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_READY ), $this->site->request( self::T + 0.6 )->settle_follow_up( array( self::PLUGIN ), self::USER ) );
		$this->assertAwaiting( $this->row() );

		$this->assertSame( array( self::PLUGIN => FollowUpRequest::SETTLED ), $this->site->request( self::T + 0.7 )->settle_follow_up( array( self::PLUGIN ), self::USER ) );
	}

	/**
	 * Same-second page request after IMMEDIATE settles at its shutdown.
	 */
	public function test_same_second_page_request() {
		$this->update( self::PLUGIN, self::USER, self::T + 0.6 );

		$this->site->time = self::T + 0.9;
		$this->site->request( self::T + 0.7 )->request_ending( true );

		$this->assertSame( SettleOutcome::ADMIN_SHUTDOWN, $this->row()['settle_outcome'] );
	}

	/**
	 * Analyses recorded before `immediate_captured_at` existed (schema 4):
	 * only a request that started in a later second than IMMEDIATE may settle.
	 */
	public function test_analysis_without_precise_immediate_time() {
		$this->update( self::PLUGIN, self::USER, self::T + 0.6 );
		$id = (int) $this->row()['id'];
		$this->site->repository->rows[ $id ]['immediate_captured_at'] = null;

		$this->site->time = self::T + 0.95;
		$this->site->request( self::T + 0.9 )->request_ending( true );
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_READY ), $this->site->request( self::T + 0.9 )->settle_follow_up( array( self::PLUGIN ), self::USER ) );
		$this->assertAwaiting( $this->row() );

		$this->site->time = self::T + 1.2;
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::SETTLED ), $this->site->request( self::T + 1.1 )->settle_follow_up( array( self::PLUGIN ), self::USER ) );
	}

	/**
	 * Without a known request start nothing is settled.
	 */
	public function test_unknown_request_start() {
		$this->update();
		$this->site->time += 5;

		$request = new PluginUpdateAnalyzer(
			$this->site->repository,
			static function () {
				throw new RuntimeException( 'must not capture' );
			},
			static function () {
				throw new RuntimeException( 'must not capture' );
			},
			function () {
				return $this->site->time;
			}
		);
		$request->request_ending( true );
		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_READY ), $request->settle_follow_up( array( self::PLUGIN ), self::USER ) );
		$this->assertAwaiting( $this->row() );
	}

	/**
	 * A follow-up in a request that activated or deactivated the plugin waits.
	 */
	public function test_activation_changed_in_the_follow_up_request() {
		$this->update();
		$this->site->time += 1;

		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_READY ), $this->site->request()->settle_follow_up( array( self::PLUGIN ), self::USER, array( self::PLUGIN ) ) );
		$this->assertAwaiting( $this->row() );
	}

	/**
	 * The update request itself never settles its own analysis, also not through a follow-up.
	 */
	public function test_update_request_never_settles_itself() {
		$request = $this->update();

		$this->assertSame( array( self::PLUGIN => FollowUpRequest::NOT_READY ), $request->settle_follow_up( array( self::PLUGIN ), self::USER ) );
		$this->assertAwaiting( $this->row() );
	}

	/**
	 * The next update still ends an earlier observation even if its request
	 * started before that IMMEDIATE (concurrent updates): the cut keeps the
	 * next update out of the earlier phases.
	 */
	public function test_next_update_cut_is_independent_of_request_start() {
		$next = $this->site->request( self::T + 0.1 );
		$this->update( self::PLUGIN, self::USER, self::T + 2 );

		$this->site->time = self::T + 3;
		$next->update_starting( self::OTHER, array(), self::USER );

		$this->assertSame( SettleOutcome::NEXT_UPDATE, $this->row()['settle_outcome'] );
	}
}
