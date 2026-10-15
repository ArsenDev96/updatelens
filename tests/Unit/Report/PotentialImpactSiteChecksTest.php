<?php
/**
 * Tests for Potential Impact on site checks.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use UpdateLens\Report\PotentialImpact;
use UpdateLens\Report\SiteCheckReport;
use UpdateLens\SiteCheck\CheckResult;
use UpdateLens\SiteCheck\CheckSession;
use UpdateLens\Storage\SiteCheckResultCodec;
use UpdateLens\Tests\Support\SiteCheckFixture as F;
use UpdateLens\Update\SiteCheckAfter;

/**
 * Site check findings come only from the comparison (SiteCheckReport, built
 * here from rows holding real encoded results): `newly_failing` and
 * `changed`. Everything else is compared without a finding, not compared,
 * pending or not applicable, and is never presented as "no findings".
 */
final class PotentialImpactSiteChecksTest extends TestCase {

	/**
	 * Long after the update: nothing is pending any more.
	 */
	const LATER = F::UPDATE_END + 3600;

	/**
	 * An analysis row with the given BEFORE and AFTER sides per check.
	 *
	 * @param array $before    Per check: CheckResult or reason.
	 * @param array $after     Per check: CheckResult, reason, or [CheckResult, reason].
	 * @param array $overrides Columns to replace.
	 * @return array
	 */
	private static function row( array $before, array $after, array $overrides = array() ) {
		return F::analysis_row( $before, $after, $overrides );
	}

	/**
	 * Evaluate a row.
	 *
	 * @param array $row Analysis row.
	 * @param float $now Read time.
	 * @return array
	 */
	private static function evaluate( array $row, $now = self::LATER ) {
		return ( new PotentialImpact() )->evaluate_site_checks( SiteCheckReport::build( $row, $now ) );
	}

	/**
	 * Both attempts with the same facts.
	 *
	 * @param array $facts Facts.
	 * @return array[]
	 */
	private static function twice( array $facts ) {
		return array( $facts, $facts );
	}

	/**
	 * Passing BEFORE results of both checks.
	 *
	 * @return array
	 */
	private static function passing_before() {
		return array(
			'home' => F::before( 'home', array( F::home() ) ),
			'rest' => F::before( 'rest', array( F::rest() ) ),
		);
	}

	/**
	 * Per check: status, state, reason and finding count (`change` is
	 * asserted where it matters).
	 *
	 * @param array $result Evaluation.
	 * @return array
	 */
	private static function checks( array $result ) {
		return array_map(
			static function ( array $check ) {
				unset( $check['change'] );
				return array_values( $check );
			},
			$result['checks']
		);
	}

	/**
	 * A homepage answering HTTP 500 twice after the update: one finding with
	 * the failure, the attempt count and both sides as the report has them.
	 */
	public function test_homepage_newly_failing_rest_passed() {
		$row    = self::row(
			self::passing_before(),
			array(
				'home' => F::after(
					'home',
					self::twice(
						F::home(
							array(
								'status'     => 500,
								'end_marker' => 'absent',
							)
						)
					)
				),
				'rest' => F::after( 'rest', array( F::rest() ) ),
			)
		);
		$report = SiteCheckReport::build( $row, self::LATER );
		$result = ( new PotentialImpact() )->evaluate_site_checks( $report );

		$this->assertSame( 'evaluated', $result['status'] );
		$this->assertSame(
			array(
				'home' => array( 'evaluated', 'newly_failing', null, 1 ),
				'rest' => array( 'evaluated', 'passed_both', null, 0 ),
			),
			self::checks( $result )
		);
		$this->assertCount( 1, $result['findings'] );
		$finding = $result['findings'][0];
		$this->assertSame(
			array(
				'code'     => 'site_check_newly_failing',
				'signal'   => 'site_checks',
				'check'    => 'home',
				'evidence' => array(
					'failure'       => 'server_error',
					'attempt_count' => 2,
				),
				'before'   => $report['checks']['home']['before'],
				'after'    => $report['checks']['home']['after'],
				'priority' => 'high',
			),
			$finding
		);
		$this->assertSame( array( 500, 500 ), array_column( $finding['after']['result']['attempts'], 'status' ) );
		$this->assertSame( array( 'verified', 'verified' ), array_column( $finding['after']['result']['attempts'], 'origin' ) );
		$this->assertSame( 1, $finding['before']['age_seconds'] );
		$this->assertSame( 0, $finding['after']['delay_seconds'] );
	}

	/**
	 * Invalid REST JSON after the update, homepage unchanged.
	 */
	public function test_rest_newly_failing_homepage_passed() {
		$result = self::evaluate(
			self::row(
				self::passing_before(),
				array(
					'home' => F::after( 'home', array( F::home() ) ),
					'rest' => F::after(
						'rest',
						self::twice(
							F::rest(
								array(
									'body'    => 'invalid_json',
									'user_id' => null,
								)
							)
						)
					),
				)
			)
		);

		$this->assertSame( 'evaluated', $result['status'] );
		$this->assertSame( array( 'rest' ), array_column( $result['findings'], 'check' ) );
		$this->assertSame(
			array(
				'failure'       => 'invalid_json',
				'attempt_count' => 2,
			),
			$result['findings'][0]['evidence']
		);
	}

	/**
	 * Both checks newly failing: two findings, homepage first.
	 */
	public function test_both_newly_failing() {
		$result = self::evaluate(
			self::row(
				self::passing_before(),
				array(
					'home' => F::after( 'home', self::twice( F::no_response( 'timeout' ) ) ),
					'rest' => F::after(
						'rest',
						self::twice(
							F::rest(
								array(
									'status'  => 500,
									'body'    => 'empty',
									'user_id' => null,
								)
							)
						)
					),
				)
			)
		);

		$this->assertSame( array( 'home', 'rest' ), array_column( $result['findings'], 'check' ) );
		$this->assertSame( array( 'site_check_newly_failing', 'site_check_newly_failing' ), array_column( $result['findings'], 'code' ) );
		$this->assertSame( array( 'timeout', 'server_error' ), array_column( array_column( $result['findings'], 'evidence' ), 'failure' ) );
	}

	/**
	 * Repeated timeouts are a confirmed failure (`timeout`), with the attempts'
	 * facts: no status, origin unknown, the waiting time.
	 */
	public function test_confirmed_timeouts() {
		$result  = self::evaluate(
			self::row(
				self::passing_before(),
				array(
					'home' => F::after( 'home', self::twice( F::no_response( 'timeout', 10000 ) ) ),
					'rest' => F::after( 'rest', array( F::rest() ) ),
				)
			)
		);
		$attempt = $result['findings'][0]['after']['result']['attempts'][0];

		$this->assertSame( 'timeout', $result['findings'][0]['evidence']['failure'] );
		$this->assertSame( array( 'timeout', null, 'unknown', 10000 ), array( $attempt['outcome'], $attempt['status'], $attempt['origin'], $attempt['duration_ms'] ) );
	}

	/**
	 * A verified page without the end-of-page marker is `incomplete`; a page
	 * too large to inspect at both checks is never compared.
	 */
	public function test_incomplete_homepage() {
		$incomplete = self::evaluate(
			self::row(
				self::passing_before(),
				array(
					'home' => F::after( 'home', self::twice( F::home( array( 'end_marker' => 'absent' ) ) ) ),
					'rest' => F::after( 'rest', array( F::rest() ) ),
				)
			)
		);
		$this->assertSame( 'incomplete', $incomplete['findings'][0]['evidence']['failure'] );

		$unread = self::evaluate(
			self::row(
				array(
					'home' => F::before( 'home', self::twice( F::home( array( 'end_marker' => 'unread' ) ) ) ),
					'rest' => F::before( 'rest', array( F::rest() ) ),
				),
				array(
					'home' => F::after( 'home', self::twice( F::home( array( 'end_marker' => 'unread' ) ) ) ),
					'rest' => F::after( 'rest', array( F::rest() ) ),
				)
			)
		);
		$this->assertSame( array(), $unread['findings'] );
		$this->assertSame( array( 'not_evaluated', 'not_comparable', 'before_inconclusive', null ), self::checks( $unread )['home'] );
		$this->assertSame( 'partial', $unread['status'] );
	}

	/**
	 * An HTTP 500 without the verification marker is still an observed
	 * failure; the evidence keeps the origin as observed (`unverified`).
	 */
	public function test_unverified_server_error() {
		$result = self::evaluate(
			self::row(
				self::passing_before(),
				array(
					'home' => F::after(
						'home',
						self::twice(
							F::home(
								array(
									'status'     => 500,
									'header'     => 'absent',
									'end_marker' => 'absent',
								)
							)
						)
					),
					'rest' => F::after( 'rest', array( F::rest() ) ),
				)
			)
		);

		$this->assertSame( 'server_error', $result['findings'][0]['evidence']['failure'] );
		$this->assertSame( array( 'unverified', 'unverified' ), array_column( $result['findings'][0]['after']['result']['attempts'], 'origin' ) );
	}

	/**
	 * Redirects are changes, never failures: appeared (a finding: the
	 * baseline was a verified pass), disappeared and a redirecting baseline
	 * followed by a failure (compared observations without a finding: the
	 * baseline was not a verified pass).
	 *
	 * @return array
	 */
	public function provide_redirect_changes() {
		return array(
			'appeared'     => array( array( F::home() ), self::twice( F::no_response( 'redirect', 120 ) ), 'redirect_appeared', true ),
			'disappeared'  => array( self::twice( F::no_response( 'redirect', 120 ) ), array( F::home() ), 'redirect_disappeared', false ),
			'then failure' => array(
				self::twice( F::no_response( 'redirect', 120 ) ),
				self::twice(
					F::home(
						array(
							'status'     => 500,
							'end_marker' => 'absent',
						)
					)
				),
				'redirect_then_failure',
				false,
			),
		);
	}

	/**
	 * A changed homepage response.
	 *
	 * @dataProvider provide_redirect_changes
	 * @param array[] $before Facts before.
	 * @param array[] $after  Facts after.
	 * @param string  $change  Expected kind.
	 * @param bool    $finding Whether it is a finding.
	 */
	public function test_homepage_changed( array $before, array $after, $change, $finding ) {
		$result = self::evaluate(
			self::row(
				array(
					'home' => F::before( 'home', $before ),
					'rest' => F::before( 'rest', array( F::rest() ) ),
				),
				array(
					'home' => F::after( 'home', $after ),
					'rest' => F::after( 'rest', array( F::rest() ) ),
				)
			)
		);

		$this->assertSame( array( 'evaluated', 'changed', null, $finding ? 1 : 0 ), self::checks( $result )['home'] );
		$this->assertSame( $change, $result['checks']['home']['change'] );
		$this->assertSame( 'evaluated', $result['status'] );
		if ( ! $finding ) {
			$this->assertSame( array(), $result['findings'] );
			return;
		}
		$this->assertSame( 'site_check_changed', $result['findings'][0]['code'] );
		$this->assertSame(
			array(
				'change'        => $change,
				'attempt_count' => count( $after ),
			),
			$result['findings'][0]['evidence']
		);
	}

	/**
	 * Compared states without a finding: passed at both, failing at both
	 * (not newly introduced) and recovered. Evaluated, zero findings.
	 *
	 * @return array
	 */
	public function provide_compared_without_finding() {
		$failure = self::twice(
			F::home(
				array(
					'status'     => 500,
					'end_marker' => 'absent',
				)
			)
		);
		return array(
			'passed both'  => array( array( F::home() ), array( F::home() ), 'passed_both' ),
			'failing both' => array( $failure, $failure, 'failing_both' ),
			'recovered'    => array( $failure, array( F::home() ), 'recovered' ),
		);
	}

	/**
	 * No finding for a compared check that did not newly fail or change.
	 *
	 * @dataProvider provide_compared_without_finding
	 * @param array[] $before Facts before.
	 * @param array[] $after  Facts after.
	 * @param string  $state  Expected state.
	 */
	public function test_compared_without_finding( array $before, array $after, $state ) {
		$result = self::evaluate(
			self::row(
				array(
					'home' => F::before( 'home', $before ),
					'rest' => F::before( 'rest', array( F::rest() ) ),
				),
				array(
					'home' => F::after( 'home', $after ),
					'rest' => F::after( 'rest', array( F::rest() ) ),
				)
			)
		);

		$this->assertSame( 'evaluated', $result['status'] );
		$this->assertSame( array( 'evaluated', $state, null, 0 ), self::checks( $result )['home'] );
		$this->assertSame( array(), $result['findings'] );
	}

	/**
	 * A failure followed by a passing retry is inconclusive (`unconfirmed`):
	 * never a confirmed failure, never a pass, never "no findings".
	 */
	public function test_unconfirmed_failure_followed_by_success() {
		$report = SiteCheckReport::build(
			self::row(
				self::passing_before(),
				array(
					'home' => F::after(
						'home',
						array(
							F::home(
								array(
									'status'     => 500,
									'end_marker' => 'absent',
								)
							),
							F::home(),
						)
					),
					'rest' => F::after( 'rest', array( F::rest() ) ),
				)
			),
			self::LATER
		);
		$result = ( new PotentialImpact() )->evaluate_site_checks( $report );

		$this->assertSame( 'unconfirmed', $report['checks']['home']['after']['result']['reason'] );
		$this->assertSame( array( 'not_evaluated', 'not_comparable', 'after_inconclusive', null ), self::checks( $result )['home'] );
		$this->assertSame( array(), $result['findings'] );
		$this->assertSame( 'partial', $result['status'] );
	}

	/**
	 * Not compared: the reason of the missing side, or the comparison's.
	 *
	 * @return array
	 */
	public function provide_not_compared() {
		$pass = array(
			'home' => F::after( 'home', array( F::home() ) ),
			'rest' => F::after( 'rest', array( F::rest() ) ),
		);
		return array(
			'no baseline'        => array(
				array(
					'home' => 'missing',
					'rest' => F::before( 'rest', array( F::rest() ) ),
				),
				array(
					'home' => null,
					'rest' => $pass['rest'],
				),
				array( 'not_evaluated', 'not_run', 'missing', null ),
			),
			'after not reported' => array(
				self::passing_before(),
				array(
					'home' => null,
					'rest' => $pass['rest'],
				),
				array( 'not_evaluated', 'not_run', 'not_reported', null ),
			),
			'overlapping update' => array(
				self::passing_before(),
				array(
					'home' => array(
						F::after(
							'home',
							self::twice(
								F::home(
									array(
										'status'     => 500,
										'end_marker' => 'absent',
									)
								)
							)
						),
						'overlapping_update',
					),
					'rest' => $pass['rest'],
				),
				array( 'not_evaluated', 'not_comparable', 'overlapping_update', null ),
			),
			'baseline too old'   => array(
				array(
					'home' => F::before(
						'home',
						array( F::home() ),
						array(
							'started_at'   => F::UPDATE_START - 700,
							'completed_at' => F::UPDATE_START - 690,
						)
					),
					'rest' => F::before( 'rest', array( F::rest() ) ),
				),
				$pass,
				array( 'not_evaluated', 'not_comparable', 'baseline_expired', null ),
			),
		);
	}

	/**
	 * A check that could not be compared is never a finding and never
	 * evaluated; the other check is still compared (partial).
	 *
	 * @dataProvider provide_not_compared
	 * @param array $before Sides before.
	 * @param array $after  Sides after.
	 * @param array $home   Expected homepage evaluation.
	 */
	public function test_not_compared( array $before, array $after, array $home ) {
		$result = self::evaluate( self::row( $before, $after ) );

		$this->assertSame( $home, self::checks( $result )['home'] );
		$this->assertSame( array( 'evaluated', 'passed_both', null, 0 ), self::checks( $result )['rest'] );
		$this->assertSame( 'partial', $result['status'] );
		$this->assertSame( array(), $result['findings'] );
	}

	/**
	 * While the AFTER result can still arrive the check is pending, and so is
	 * the evaluation, even when the other check has a finding.
	 */
	public function test_pending_after_result() {
		$row    = self::row(
			self::passing_before(),
			array(
				'home' => null,
				'rest' => F::after(
					'rest',
					self::twice(
						F::rest(
							array(
								'body'    => 'invalid_json',
								'user_id' => null,
							)
						)
					)
				),
			)
		);
		$result = self::evaluate( $row, F::UPDATE_END + 10 );

		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( array( 'pending', 'pending', null, null ), self::checks( $result )['home'] );
		$this->assertSame( array( 'rest' ), array_column( $result['findings'], 'check' ) );

		// Once its session can no longer report, the same row is not_reported.
		$later = self::evaluate( $row, F::UPDATE_END + 0.25 + CheckSession::TTL + SiteCheckAfter::REPORT_GRACE_SECONDS + 1 );
		$this->assertSame( array( 'not_evaluated', 'not_run', 'not_reported', null ), self::checks( $later )['home'] );
		$this->assertSame( 'partial', $later['status'] );
	}

	/**
	 * Site checks turned off for the update, and analyses recorded before
	 * site checks existed: not applicable, not an incomplete evaluation.
	 */
	public function test_disabled_and_historical_are_not_applicable() {
		$disabled = self::evaluate(
			self::row(
				array(
					'home' => 'disabled',
					'rest' => 'disabled',
				),
				array()
			)
		);
		$this->assertSame( 'not_applicable', $disabled['status'] );
		$this->assertSame(
			array(
				'home' => array( 'not_applicable', 'not_run', 'disabled', null ),
				'rest' => array( 'not_applicable', 'not_run', 'disabled', null ),
			),
			self::checks( $disabled )
		);

		// A row from before schema 6: no site check columns at all.
		$historical = self::evaluate(
			array(
				'status'         => 'completed',
				'settle_outcome' => 'admin_shutdown',
				'completed_at'   => '2026-01-05 10:00:00',
			)
		);
		$this->assertSame( 'not_applicable', $historical['status'] );
		$this->assertSame( array( 'not_applicable', 'not_run', 'not_captured', null ), self::checks( $historical )['rest'] );
		$this->assertSame( array(), $historical['findings'] );

		// Schema 6 (BEFORE stored, AFTER not recorded yet).
		$schema_6 = self::row( self::passing_before(), array(), array( 'site_check_update_started_at' => null ) );
		$this->assertSame( array( 'not_applicable', 'not_run', 'not_captured', null ), self::checks( self::evaluate( $schema_6 ) )['home'] );
	}

	/**
	 * A check whose target is on another origin than wp-admin cannot run on
	 * this site: not applicable (never passed, never a finding), and the
	 * other check is evaluated on its own.
	 */
	public function test_checks_on_other_origins_are_not_applicable() {
		$one = self::evaluate(
			self::row(
				array(
					'home' => 'cross_origin',
					'rest' => F::before( 'rest', array( F::rest() ) ),
				),
				array( 'rest' => F::after( 'rest', array( F::rest() ) ) )
			)
		);
		$this->assertSame( 'evaluated', $one['status'] );
		$this->assertSame(
			array(
				'home' => array( 'not_applicable', 'not_run', 'cross_origin', null ),
				'rest' => array( 'evaluated', 'passed_both', null, 0 ),
			),
			self::checks( $one )
		);
		$this->assertSame( array(), $one['findings'] );

		$both = self::evaluate(
			self::row(
				array(
					'home' => 'cross_origin',
					'rest' => 'cross_origin',
				),
				array(),
				array( SiteCheckAfter::STARTED_AT => null )
			)
		);
		$this->assertSame( 'not_applicable', $both['status'] );
		$this->assertSame( array( 'not_applicable', 'not_run', 'cross_origin', null ), self::checks( $both )['rest'] );
	}

	/**
	 * Unexpected problems are never "not applicable": a stored baseline that
	 * cannot be read, an AFTER run that never started.
	 */
	public function test_failures_are_not_evaluated() {
		$result = self::evaluate(
			self::row(
				array(
					'home' => 'corrupt',
					'rest' => F::before( 'rest', array( F::rest() ) ),
				),
				array(),
				array( SiteCheckAfter::STARTED_AT => null )
			)
		);

		$this->assertSame( array( 'not_evaluated', 'not_run', 'corrupt', null ), self::checks( $result )['home'] );
		$this->assertSame( array( 'not_evaluated', 'not_run', 'not_started', null ), self::checks( $result )['rest'] );
		$this->assertSame( 'not_evaluated', $result['status'] );
	}

	/**
	 * A check that is not applicable is ignored by the overall status, like a
	 * not applicable Action Scheduler.
	 */
	public function test_not_applicable_check_does_not_count() {
		$result = ( new PotentialImpact() )->evaluate_site_checks(
			array(
				'status' => 'partial',
				'checks' => array(
					'home' => array(
						'state'  => 'passed_both',
						'reason' => null,
						'before' => array( 'result' => array( 'verdict' => 'pass' ) ),
						'after'  => array( 'result' => array( 'verdict' => 'pass' ) ),
					),
					'rest' => array(
						'state'  => 'not_run',
						'reason' => 'after_missing',
						'before' => array( 'available' => true ),
						'after'  => array(
							'available' => false,
							'reason'    => 'not_captured',
						),
					),
				),
			)
		);

		$this->assertSame( 'evaluated', $result['status'] );
		$this->assertSame( array( 'not_applicable', 'not_run', 'not_captured', null ), self::checks( $result )['rest'] );
	}

	/**
	 * Inconsistent input never produces a finding: a `newly_failing` state
	 * without a passing baseline and a confirmed failure is left alone.
	 */
	public function test_findings_need_the_verdicts() {
		$result = ( new PotentialImpact() )->evaluate_site_checks(
			array(
				'status' => 'complete',
				'checks' => array(
					'home' => array(
						'state'  => 'newly_failing',
						'reason' => null,
						'before' => array( 'result' => array( 'verdict' => 'inconclusive' ) ),
						'after'  => array( 'result' => array( 'verdict' => 'fail' ) ),
					),
				),
			)
		);

		$this->assertSame( array(), $result['findings'] );
		$this->assertSame( array( 'evaluated', 'newly_failing', null, 0 ), self::checks( $result )['home'] );
	}

	/**
	 * Nothing about the context leaves the report: no users, login sessions,
	 * targets, generations or URLs.
	 */
	public function test_privacy() {
		$result = self::evaluate(
			self::row(
				array(
					'home' => F::before( 'home', array( F::home() ), array( 'generation' => str_repeat( 'd', 16 ) . ':4' ) ),
					'rest' => F::before( 'rest', array( F::rest() ) ),
				),
				array(
					'home' => F::after(
						'home',
						self::twice(
							F::home(
								array(
									'status'     => 500,
									'end_marker' => 'absent',
								)
							)
						)
					),
					'rest' => F::after(
						'rest',
						self::twice(
							F::rest(
								array(
									'body'    => 'json_object',
									'user_id' => 99,
								)
							)
						)
					),
				)
			)
		);
		$json   = (string) json_encode( $result ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.

		$this->assertCount( 2, $result['findings'] );
		foreach ( array( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ), str_repeat( 'c', 64 ), str_repeat( 'd', 16 ), '"user', '99', 'http' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
	}
}
