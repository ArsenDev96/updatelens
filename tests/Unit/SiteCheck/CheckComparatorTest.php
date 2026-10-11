<?php
/**
 * Tests for before/after site check comparison.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\SiteCheck;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UpdateLens\SiteCheck\CheckComparator;
use UpdateLens\SiteCheck\CheckComparison;
use UpdateLens\SiteCheck\CheckDefinition;
use UpdateLens\SiteCheck\CheckResult;
use UpdateLens\SiteCheck\ComparisonState;
use UpdateLens\SiteCheck\UpdateWindow;
use UpdateLens\Tests\Support\SiteCheckFixture as F;

/**
 * Conservative comparison: only a passing baseline followed by a confirmed
 * failure is a potential failure finding.
 */
final class CheckComparatorTest extends TestCase {

	/**
	 * Facts of common homepage runs.
	 *
	 * @param string $name Run name.
	 * @return array[]
	 */
	private static function home_run( $name ) {
		$runs = array(
			'pass'       => array( F::home() ),
			'500'        => array(
				F::home(
					array(
						'status'     => 500,
						'end_marker' => 'absent',
					)
				),
				F::home(
					array(
						'status'     => 500,
						'end_marker' => 'absent',
					)
				),
			),
			'timeouts'   => array( F::no_response( 'timeout' ), F::no_response( 'timeout' ) ),
			'incomplete' => array( F::home( array( 'end_marker' => 'absent' ) ), F::home( array( 'end_marker' => 'absent' ) ) ),
			'redirect'   => array( F::no_response( 'redirect', 40 ), F::no_response( 'redirect', 40 ) ),
			'cache'      => array(
				F::home(
					array(
						'header'     => 'absent',
						'end_marker' => 'absent',
					)
				),
				F::home(
					array(
						'header'     => 'absent',
						'end_marker' => 'absent',
					)
				),
			),
			'flaky'      => array(
				F::home(
					array(
						'status'     => 500,
						'end_marker' => 'absent',
					)
				),
				F::home(),
			),
			// Verified responses whose bodies were still arriving at the time limit.
			'slow'       => array(
				F::home(
					array(
						'end_marker'  => 'unread',
						'duration_ms' => 10000,
					)
				),
				F::home(
					array(
						'end_marker'  => 'unread',
						'duration_ms' => 10000,
					)
				),
			),
			// Time limits noticed late (suspended page), reported as network errors.
			'suspended'  => array( F::no_response( 'network_error', 60000 ), F::no_response( 'network_error', 60000 ) ),
		);

		return $runs[ $name ];
	}

	/**
	 * Compare homepage runs around the default update.
	 *
	 * @param string|null  $before        BEFORE run name (null = none).
	 * @param string|null  $after         AFTER run name (null = none).
	 * @param array        $before_option BEFORE options.
	 * @param array        $after_option  AFTER options.
	 * @param UpdateWindow $window        Update window.
	 * @return CheckComparison
	 */
	private static function compare_home( $before, $after, array $before_option = array(), array $after_option = array(), ?UpdateWindow $window = null ) {
		return CheckComparator::compare(
			CheckDefinition::home(),
			null === $before ? null : F::before( 'home', self::home_run( $before ), $before_option ),
			null === $after ? null : F::after( 'home', self::home_run( $after ), $after_option ),
			null === $window ? F::window() : $window
		);
	}

	/**
	 * Comparable state transitions.
	 *
	 * @return array<string, array>
	 */
	public function transitions() {
		return array(
			'passed both'                      => array( 'pass', 'pass', 'passed_both', false ),
			'newly failing: HTTP 500 twice'    => array( 'pass', '500', 'newly_failing', true ),
			'newly failing: incomplete page'   => array( 'pass', 'incomplete', 'newly_failing', true ),
			'newly failing: two timeouts'      => array( 'pass', 'timeouts', 'newly_failing', true ),
			'failing both'                     => array( '500', '500', 'failing_both', false ),
			'failing both, different failures' => array( 'incomplete', 'timeouts', 'failing_both', false ),
			'recovered'                        => array( '500', 'pass', 'recovered', false ),
			'changed: new redirect'            => array( 'pass', 'redirect', 'changed', false ),
			'changed: redirect gone'           => array( 'redirect', 'pass', 'changed', false ),
			'changed: redirect then failure'   => array( 'redirect', '500', 'changed', false ),
			'changed: failure then redirect'   => array( '500', 'redirect', 'changed', false ),
		);
	}

	/**
	 * Every comparable transition, and only `newly_failing` is a potential failure.
	 *
	 * @dataProvider transitions
	 * @param string $before    BEFORE run.
	 * @param string $after     AFTER run.
	 * @param string $state     Expected state.
	 * @param bool   $potential Expected potential failure.
	 */
	public function test_transitions( $before, $after, $state, $potential ) {
		$comparison = self::compare_home( $before, $after );

		$this->assertSame( $state, $comparison->get_state() );
		$this->assertNull( $comparison->get_reason() );
		$this->assertSame( $potential, $comparison->is_potential_failure() );
	}

	/**
	 * Two timeouts are kept as evidence: both attempts timed out, no status,
	 * origin unknown. Nothing claims the server was down.
	 */
	public function test_two_timeouts_keep_their_evidence() {
		$after = self::compare_home( 'pass', 'timeouts' )->to_array()['after'];

		$this->assertSame( 'fail', $after['verdict'] );
		$this->assertSame( 'timeout', $after['reason'] );
		$this->assertCount( 2, $after['attempts'] );
		foreach ( $after['attempts'] as $attempt ) {
			$this->assertSame( array( 'timeout', null, 'unknown', 10000 ), array( $attempt['outcome'], $attempt['status'], $attempt['origin'], $attempt['duration_ms'] ) );
		}
	}

	/**
	 * Missing results are `not_run`, never a pass or "no problems".
	 */
	public function test_missing_results_are_not_run() {
		$cases = array(
			array( null, 'pass', 'baseline_missing' ),
			array( 'pass', null, 'after_missing' ),
			array( null, null, 'both_missing' ),
		);
		foreach ( $cases as $case ) {
			$comparison = self::compare_home( $case[0], $case[1] );
			$this->assertSame( array( 'not_run', $case[2] ), array( $comparison->get_state(), $comparison->get_reason() ) );
			$this->assertFalse( $comparison->is_potential_failure() );
		}
	}

	/**
	 * Inconclusive runs make a comparison not comparable, whatever the other side.
	 */
	public function test_inconclusive_runs_are_not_comparable() {
		$cases = array(
			array( 'cache', 'pass', 'before_inconclusive' ),
			array( 'cache', '500', 'before_inconclusive' ),
			array( 'pass', 'cache', 'after_inconclusive' ),
			array( 'pass', 'flaky', 'after_inconclusive' ),
			array( 'pass', 'slow', 'after_inconclusive' ),
			array( 'pass', 'suspended', 'after_inconclusive' ),
			array( 'slow', 'pass', 'before_inconclusive' ),
			array( 'flaky', 'pass', 'before_inconclusive' ),
			array( 'redirect', 'redirect', 'redirected_both' ),
		);
		foreach ( $cases as $case ) {
			$comparison = self::compare_home( $case[0], $case[1] );
			$this->assertSame( array( 'not_comparable', $case[2] ), array( $comparison->get_state(), $comparison->get_reason() ), implode( ' → ', $case ) );
		}
	}

	/**
	 * A single failure after the update is never a finding.
	 */
	public function test_unconfirmed_failure_is_not_a_finding() {
		$comparison = CheckComparator::compare(
			CheckDefinition::home(),
			F::before( 'home', array( F::home() ) ),
			F::after(
				'home',
				array(
					F::home(
						array(
							'status'     => 500,
							'end_marker' => 'absent',
						)
					),
				)
			),
			F::window()
		);

		$this->assertSame( array( 'not_comparable', 'after_inconclusive' ), array( $comparison->get_state(), $comparison->get_reason() ) );
		$this->assertSame( 'unconfirmed', $comparison->to_array()['after']['reason'] );
	}

	/**
	 * Context requirements: same definition version, target, user and
	 * browser session, for the homepage too.
	 */
	public function test_context_requirements() {
		$cases = array(
			'definition changed'       => array( array( 'version' => 2 ), array(), 'definition_changed' ),
			'definition changed after' => array( array(), array( 'version' => 2 ), 'definition_changed' ),
			'target changed'           => array( array(), array( 'target' => str_repeat( 'd', 64 ) ), 'target_changed' ),
			'other administrator'      => array( array(), array( 'user' => 8 ), 'different_user' ),
			'other browser'            => array( array(), array( 'session' => str_repeat( 'e', 64 ) ), 'different_login_session' ),
		);
		foreach ( $cases as $name => $case ) {
			$comparison = self::compare_home( 'pass', '500', $case[0], $case[1] );
			$this->assertSame( array( 'not_comparable', $case[2] ), array( $comparison->get_state(), $comparison->get_reason() ), $name );
			$this->assertFalse( $comparison->is_potential_failure(), $name );
		}
	}

	/**
	 * Timing: the baseline finished before the update began and at most 10
	 * minutes earlier (inclusive); AFTER started once the update had ended.
	 */
	public function test_timing_requirements() {
		$start = F::UPDATE_START;
		$cases = array(
			'baseline at the update start'    => array( array( 'completed_at' => $start ), array(), 'not_comparable', 'baseline_after_update_start', 0 ),
			'baseline after the update start' => array( array( 'completed_at' => $start + 0.3 ), array(), 'not_comparable', 'baseline_after_update_start', null ),
			'baseline exactly 10 minutes old' => array(
				array(
					'started_at'   => $start - 601,
					'completed_at' => $start - 600,
				),
				array(),
				'newly_failing',
				null,
				600,
			),
			'baseline just over 10 minutes'   => array(
				array(
					'started_at'   => $start - 601,
					'completed_at' => $start - 600.001,
				),
				array(),
				'not_comparable',
				'baseline_expired',
				600,
			),
			'baseline 31 minutes old'         => array(
				array(
					'started_at'   => $start - 1861,
					'completed_at' => $start - 1860,
				),
				array(),
				'not_comparable',
				'baseline_expired',
				1860,
			),
			'AFTER started during the update' => array( array(), array( 'started_at' => F::UPDATE_END - 0.1 ), 'not_comparable', 'after_during_update', 1 ),
			'AFTER started as it ended'       => array( array(), array( 'started_at' => F::UPDATE_END ), 'newly_failing', null, 1 ),
		);
		foreach ( $cases as $name => $case ) {
			$comparison = self::compare_home( 'pass', '500', $case[0], $case[1] );
			$this->assertSame( array( $case[2], $case[3], $case[4] ), array( $comparison->get_state(), $comparison->get_reason(), $comparison->to_array()['baseline_age_seconds'] ), $name );
		}
	}

	/**
	 * Queued and overlapping updates are not comparable, and win over every
	 * other reason, including missing results.
	 */
	public function test_queued_and_overlapping_updates() {
		$queued = self::compare_home( 'pass', '500', array(), array(), F::window( true ) );
		$this->assertSame( array( 'not_comparable', 'queued_update' ), array( $queued->get_state(), $queued->get_reason() ) );

		$overlap = self::compare_home( 'pass', '500', array(), array(), F::window( false, true ) );
		$this->assertSame( array( 'not_comparable', 'overlapping_update' ), array( $overlap->get_state(), $overlap->get_reason() ) );

		$both = self::compare_home( null, '500', array(), array(), F::window( true, true ) );
		$this->assertSame( 'queued_update', $both->get_reason() );
	}

	/**
	 * Reasons are checked in a fixed order: the first unmet requirement wins.
	 */
	public function test_reason_precedence() {
		$this->assertSame(
			'definition_changed',
			self::compare_home(
				'cache',
				'pass',
				array(
					'version' => 2,
					'user'    => 8,
				)
			)->get_reason()
		);
		$this->assertSame(
			'target_changed',
			self::compare_home(
				'pass',
				'pass',
				array(),
				array(
					'target' => str_repeat( 'd', 64 ),
					'user'   => 8,
				)
			)->get_reason()
		);
		$this->assertSame(
			'different_user',
			self::compare_home(
				'pass',
				'pass',
				array(),
				array(
					'user'    => 8,
					'session' => str_repeat( 'e', 64 ),
				)
			)->get_reason()
		);
		$this->assertSame(
			'baseline_expired',
			self::compare_home(
				'cache',
				'pass',
				array(
					'started_at'   => F::UPDATE_START - 2000,
					'completed_at' => F::UPDATE_START - 1999,
				)
			)->get_reason()
		);
		$this->assertSame( 'before_inconclusive', self::compare_home( 'cache', 'cache' )->get_reason() );
	}

	/**
	 * Checks are independent: a REST timeout or a missing REST report never
	 * affects a valid homepage comparison, and both are always present.
	 */
	public function test_checks_are_independent() {
		$comparisons = CheckComparator::compare_all(
			array(
				'home' => F::before( 'home', array( F::home() ) ),
				'rest' => F::before( 'rest', array( F::no_response( 'timeout' ), F::no_response( 'timeout' ) ) ),
			),
			array(
				'home' => F::after( 'home', self::home_run( '500' ) ),
				'rest' => F::after( 'rest', array( F::rest() ) ),
			),
			F::window()
		);
		$this->assertSame( array( 'home', 'rest' ), array( $comparisons[0]->get_check(), $comparisons[1]->get_check() ) );
		$this->assertSame( 'newly_failing', $comparisons[0]->get_state() );
		$this->assertSame( 'recovered', $comparisons[1]->get_state() );

		$partial = CheckComparator::compare_all(
			array( 'home' => F::before( 'home', array( F::home() ) ) ),
			array(
				'home' => F::after( 'home', array( F::home() ) ),
				'rest' => null,
			),
			F::window()
		);
		$this->assertSame( array( 'passed_both', null ), array( $partial[0]->get_state(), $partial[0]->get_reason() ) );
		$this->assertSame( array( 'not_run', 'both_missing' ), array( $partial[1]->get_state(), $partial[1]->get_reason() ) );

		$rest_only = CheckComparator::compare_all(
			array(
				'home' => F::before( 'home', self::home_run( 'cache' ) ),
				'rest' => F::before( 'rest', array( F::rest() ) ),
			),
			array(
				'home' => F::after( 'home', self::home_run( 'pass' ) ),
				'rest' => F::after(
					'rest',
					array(
						F::rest(
							array(
								'body'    => 'json_after_output',
								'user_id' => null,
							)
						),
						F::rest(
							array(
								'body'    => 'json_after_output',
								'user_id' => null,
							)
						),
					)
				),
			),
			F::window()
		);
		$this->assertSame( 'before_inconclusive', $rest_only[0]->get_reason() );
		$this->assertSame( 'newly_failing', $rest_only[1]->get_state() );
		$this->assertSame( 'extra_output', $rest_only[1]->to_array()['after']['reason'] );

		$nothing = CheckComparator::compare_all( array(), array(), F::window() );
		$this->assertCount( 2, $nothing );
		foreach ( $nothing as $comparison ) {
			$this->assertSame( array( 'not_run', 'both_missing' ), array( $comparison->get_state(), $comparison->get_reason() ) );
		}
	}

	/**
	 * REST: wrong user and authentication failures after a passing baseline.
	 */
	public function test_rest_findings() {
		$wrong = F::rest( array( 'user_id' => 1 ) );
		$auth  = F::rest(
			array(
				'status'     => 403,
				'user_id'    => null,
				'error_code' => 'rest_cookie_invalid_nonce',
			)
		);
		foreach ( array(
			'wrong_user' => $wrong,
			'refused'    => $auth,
		) as $reason => $facts ) {
			$comparison = CheckComparator::compare( CheckDefinition::rest(), F::before( 'rest', array( F::rest() ) ), F::after( 'rest', array( $facts, $facts ) ), F::window() );
			$this->assertSame( 'newly_failing', $comparison->get_state() );
			$this->assertSame( $reason, $comparison->to_array()['after']['reason'] );
		}

		$firewall   = F::rest(
			array(
				'status'  => 403,
				'header'  => 'absent',
				'body'    => 'invalid_json',
				'user_id' => null,
			)
		);
		$comparison = CheckComparator::compare( CheckDefinition::rest(), F::before( 'rest', array( F::rest() ) ), F::after( 'rest', array( $firewall, $firewall ) ), F::window() );
		$this->assertSame( array( 'not_comparable', 'after_inconclusive' ), array( $comparison->get_state(), $comparison->get_reason() ) );
	}

	/**
	 * Exact, deterministic output without context, times or fingerprints.
	 */
	public function test_output_is_deterministic_and_minimal() {
		$expected = array(
			'check'                => 'home',
			'state'                => 'newly_failing',
			'reason'               => null,
			'baseline_age_seconds' => 1,
			'before'               => array(
				'verdict'  => 'pass',
				'reason'   => null,
				'attempts' => array(
					array(
						'outcome'     => 'success',
						'status'      => 200,
						'header'      => 'current',
						'end_marker'  => 'current',
						'duration_ms' => 912,
						'origin'      => 'verified',
						'verdict'     => 'pass',
						'reason'      => null,
					),
				),
			),
			'after'                => array(
				'verdict'  => 'fail',
				'reason'   => 'server_error',
				'attempts' => array(
					array(
						'outcome'     => 'server_error',
						'status'      => 500,
						'header'      => 'current',
						'end_marker'  => 'absent',
						'duration_ms' => 912,
						'origin'      => 'verified',
						'verdict'     => 'fail',
						'reason'      => 'server_error',
					),
					array(
						'outcome'     => 'server_error',
						'status'      => 500,
						'header'      => 'current',
						'end_marker'  => 'absent',
						'duration_ms' => 912,
						'origin'      => 'verified',
						'verdict'     => 'fail',
						'reason'      => 'server_error',
					),
				),
			),
		);

		$this->assertSame( $expected, self::compare_home( 'pass', '500' )->to_array() );
		$this->assertSame( self::compare_home( 'pass', '500' )->to_array(), self::compare_home( 'pass', '500' )->to_array() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Asserts the output form.
		$json = json_encode( self::compare_home( 'pass', '500', array(), array( 'user' => 8 ) )->to_array() );
		foreach ( array( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ), '"user"', 'started_at', 'completed_at', '1760090' ) as $absent ) {
			$this->assertStringNotContainsString( $absent, $json );
		}
	}

	/**
	 * Results of another check or unknown keys are programming errors.
	 */
	public function test_rejects_mismatched_results() {
		$rest = F::before( 'rest', array( F::rest() ) );
		foreach (
			array(
				function () use ( $rest ) {
					CheckComparator::compare( CheckDefinition::home(), $rest, null, F::window() );
				},
				function () use ( $rest ) {
					CheckComparator::compare_all( array( 'home' => $rest ), array(), F::window() );
				},
				function () {
					CheckComparator::compare_all( array( 'theme' => null ), array(), F::window() );
				},
				function () {
					CheckComparator::compare_all( array(), array( 'home' => 'pass' ), F::window() );
				},
			) as $call
		) {
			try {
				$call();
				$this->fail( 'Mismatched results accepted.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Invalid UpdateLens site check results.', $e->getMessage() );
			}
		}
	}

	/**
	 * Results validate their own context and times.
	 */
	public function test_results_validate_context_and_times() {
		$attempt = F::attempt( 'home', F::home() );
		$context = F::context( 'home' );
		$cases   = array(
			'homepage claiming user auth' => function () use ( $attempt ) {
				new CheckResult( 'home', 1, F::context( 'rest' ), 1.0, 2.0, array( $attempt ) );
			},
			'completed before started'    => function () use ( $attempt, $context ) {
				new CheckResult( 'home', 1, $context, 2.0, 1.0, array( $attempt ) );
			},
			'NaN timestamp'               => function () use ( $attempt, $context ) {
				new CheckResult( 'home', 1, $context, NAN, 1.0, array( $attempt ) );
			},
			'infinite timestamp'          => function () use ( $attempt, $context ) {
				new CheckResult( 'home', 1, $context, 1.0, INF, array( $attempt ) );
			},
			'zero timestamp'              => function () use ( $attempt, $context ) {
				new CheckResult( 'home', 1, $context, 0, 1.0, array( $attempt ) );
			},
			'version zero'                => function () use ( $attempt, $context ) {
				new CheckResult( 'home', 0, $context, 1.0, 2.0, array( $attempt ) );
			},
			'version as string'           => function () use ( $attempt, $context ) {
				new CheckResult( 'home', '1', $context, 1.0, 2.0, array( $attempt ) );
			},
			'attempt of another check'    => function () use ( $context ) {
				new CheckResult( 'home', 1, $context, 1.0, 2.0, array( F::attempt( 'rest', F::rest() ) ) );
			},
			'no attempts'                 => function () use ( $context ) {
				new CheckResult( 'home', 1, $context, 1.0, 2.0, array() );
			},
			'unknown check'               => function () use ( $attempt, $context ) {
				new CheckResult( 'theme', 1, $context, 1.0, 2.0, array( $attempt ) );
			},
			'window ending before start'  => function () {
				new UpdateWindow( 2.0, 1.0 );
			},
			'window flags not boolean'    => function () {
				new UpdateWindow( 1.0, 2.0, 1 );
			},
		);
		foreach ( $cases as $name => $call ) {
			try {
				$call();
				$this->fail( $name . ' accepted.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'UpdateLens', $e->getMessage(), $name );
			}
		}
	}

	/**
	 * Approved decisions: a missing end-of-page marker before and after is
	 * never a new failure; 503 with Retry-After stays inconclusive with its
	 * precise reason; repeated unverified 5xx/404 are observed HTTP failures
	 * that keep their unverified origin.
	 */
	public function test_approved_product_decisions() {
		$no_footer = array( F::home( array( 'end_marker' => 'absent' ) ), F::home( array( 'end_marker' => 'absent' ) ) );
		$cases     = array(
			'no end marker before and after'   => array( $no_footer, $no_footer, 'failing_both' ),
			'no end marker, then two timeouts' => array( $no_footer, self::home_run( 'timeouts' ), 'failing_both' ),
			'no end marker, then complete'     => array( $no_footer, self::home_run( 'pass' ), 'recovered' ),
			'no end marker, then a single 500' => array(
				$no_footer,
				array(
					F::home(
						array(
							'status'     => 500,
							'end_marker' => 'absent',
						)
					),
				),
				'not_comparable',
			),
		);
		foreach ( $cases as $name => $case ) {
			$comparison = CheckComparator::compare( CheckDefinition::home(), F::before( 'home', $case[0] ), F::after( 'home', $case[1] ), F::window() );
			$this->assertSame( $case[2], $comparison->get_state(), $name );
			$this->assertFalse( $comparison->is_potential_failure(), $name );
		}

		$maintenance = F::home(
			array(
				'status'      => 503,
				'retry_after' => true,
				'end_marker'  => 'absent',
			)
		);
		$comparison  = CheckComparator::compare( CheckDefinition::home(), F::before( 'home', self::home_run( 'pass' ) ), F::after( 'home', array( $maintenance, $maintenance ) ), F::window() );
		$this->assertSame( array( 'not_comparable', 'after_inconclusive' ), array( $comparison->get_state(), $comparison->get_reason() ) );
		$this->assertSame( array( 'inconclusive', 'unavailable_retry_after' ), array( $comparison->to_array()['after']['verdict'], $comparison->to_array()['after']['reason'] ) );

		foreach ( array(
			502 => 'server_error',
			404 => 'not_found',
		) as $status => $reason ) {
			$proxy      = F::home(
				array(
					'status'     => $status,
					'header'     => 'absent',
					'end_marker' => 'absent',
				)
			);
			$comparison = CheckComparator::compare( CheckDefinition::home(), F::before( 'home', self::home_run( 'pass' ) ), F::after( 'home', array( $proxy, $proxy ) ), F::window() );
			$after      = $comparison->to_array()['after'];
			$this->assertSame( 'newly_failing', $comparison->get_state() );
			$this->assertSame( array( 'fail', $reason ), array( $after['verdict'], $after['reason'] ) );
			$this->assertSame( array( 'unverified', 'unverified' ), array_column( $after['attempts'], 'origin' ) );
		}
	}
}
