<?php
/**
 * Tests for the Potential Impact assessment summary.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use UpdateLens\Diff\CronDiffBuilder;
use UpdateLens\Diff\OptionsDiffBuilder;
use UpdateLens\Report\PotentialImpact;
use UpdateLens\Report\SiteCheckReport;
use UpdateLens\Snapshot\AutoloadPolicy;
use UpdateLens\Snapshot\OptionNoiseFilter;
use UpdateLens\Snapshot\OptionsSnapshotBuilder;
use UpdateLens\Snapshot\OptionValueHasher;
use UpdateLens\Storage\CronDiffCodec;
use UpdateLens\Storage\OptionsDiffCodec;
use UpdateLens\Tests\Support\CronFixture;
use UpdateLens\Tests\Support\SiteCheckFixture as F;

/**
 * PotentialImpact::assess(): findings counted by their fixed priority, the
 * Net result changes no rule matched as informational (never "safe"), and
 * what could not be assessed or is still pending listed apart from them.
 *
 * Diffs go through the real builders and codecs; site checks through real
 * encoded results and SiteCheckReport, like AnalysisReadModel::report().
 */
final class PotentialImpactAssessmentTest extends TestCase {

	const SECRET = 'test-site-secret';

	const T = 1767225600; // 2026-01-01T00:00:00Z.

	/**
	 * Long after the update: nothing is pending any more.
	 */
	const LATER = F::UPDATE_END + 3600;

	/**
	 * Decoded Options diff from `name => [ raw value, raw autoload ]` rows.
	 *
	 * @param array<string, array{string, string}> $before Options before.
	 * @param array<string, array{string, string}> $after  Options after.
	 * @return array
	 */
	private static function options( array $before, array $after ) {
		$builder = new OptionsSnapshotBuilder(
			new OptionValueHasher( self::SECRET ),
			new OptionNoiseFilter(),
			new AutoloadPolicy( array( 'yes', 'on', 'auto-on', 'auto' ) )
		);
		$rows    = static function ( array $options ) {
			$rows = array();
			foreach ( $options as $name => $option ) {
				$rows[] = array(
					'option_name'  => (string) $name,
					'option_value' => $option[0],
					'autoload'     => $option[1],
				);
			}
			return $rows;
		};
		$codec   = new OptionsDiffCodec();

		return $codec->decode( $codec->encode( ( new OptionsDiffBuilder() )->build( $builder->build( $rows( $before ) ), $builder->build( $rows( $after ) ) ) ) );
	}

	/**
	 * Decoded WP-Cron diff from CronFixture events.
	 *
	 * @param array $before Events before.
	 * @param array $after  Events after.
	 * @return array
	 */
	private static function cron( array $before, array $after ) {
		$codec = new CronDiffCodec();

		return $codec->decode( $codec->encode( ( new CronDiffBuilder() )->build( CronFixture::snapshot( CronFixture::cron( $before ) ), CronFixture::snapshot( CronFixture::cron( $after ) ) ) ) );
	}

	/**
	 * A report phase object: available with the decoded lists, or unavailable with a reason.
	 *
	 * @param array|string $diff Decoded diff, or the unavailable reason.
	 * @return array
	 */
	private static function signal( $diff ) {
		if ( is_string( $diff ) ) {
			return array(
				'available'   => false,
				'association' => 'net_across_phases',
				'reason'      => $diff,
			);
		}

		return array(
			'available'   => true,
			'association' => 'net_across_phases',
		) + $diff;
	}

	/**
	 * Evaluate and assess like AnalysisReadModel::report().
	 *
	 * @param array|string $options     Options diff or reason.
	 * @param array|string $cron        WP-Cron diff or reason.
	 * @param array        $site_row    Site check columns (F::analysis_row()).
	 * @param bool         $as_absent   Whether Action Scheduler was absent throughout.
	 * @param string       $as_reason   Action Scheduler's Net result reason.
	 * @param float        $now         Read time.
	 * @return array{impact: array, assessment: array}
	 */
	private static function assess( $options, $cron, array $site_row, $as_absent = true, $as_reason = 'not_installed', $now = self::LATER ) {
		$phases = array(
			'final' => array(
				'options'          => self::signal( $options ),
				'cron'             => self::signal( $cron ),
				'action_scheduler' => self::signal( $as_reason ),
			),
		);

		$impact                = new PotentialImpact();
		$result                = $impact->evaluate( $phases, $as_absent );
		$result['site_checks'] = $impact->evaluate_site_checks( SiteCheckReport::build( $site_row, $now ) );

		return array(
			'impact'     => $result,
			'assessment' => $impact->assess( $result, $phases ),
		);
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
	 * Both checks passed before and after the update.
	 *
	 * @return array
	 */
	private static function passed_both() {
		return F::analysis_row(
			self::passing_before(),
			array(
				'home' => F::after( 'home', array( F::home() ) ),
				'rest' => F::after( 'rest', array( F::rest() ) ),
			)
		);
	}

	/**
	 * Nothing changed and both checks passed: no findings, zero informational
	 * changes, nothing missing. Absent Action Scheduler is not "not assessed".
	 */
	public function test_no_changes_both_checks_passed() {
		$same   = array( 'acme_settings' => array( 'a', 'off' ) );
		$result = self::assess( self::options( $same, $same ), self::cron( array(), array() ), self::passed_both() );

		$this->assertSame(
			array(
				'high_priority' => 0,
				'needs_review'  => 0,
				'informational' => 0,
				'not_assessed'  => array(),
				'pending'       => array(),
			),
			$result['assessment']
		);
	}

	/**
	 * A recurring event whose next run moved one minute is an observed change
	 * no rule matches: informational, never a finding.
	 */
	public function test_routine_reschedule_is_informational() {
		$result = self::assess(
			self::options( array(), array() ),
			self::cron(
				array( CronFixture::recurring( self::T, 'acme_daily_sync', 'daily' ) ),
				array( CronFixture::recurring( self::T + 60, 'acme_daily_sync', 'daily' ) )
			),
			self::passed_both()
		);

		$this->assertSame( array(), $result['impact']['findings'] );
		$this->assertSame( array( 0, 0, 1 ), self::counts( $result['assessment'] ) );
	}

	/**
	 * A new large autoloaded option is "needs review"; the small option added
	 * with it stays informational.
	 */
	public function test_large_option_needs_review_other_changes_informational() {
		$result = self::assess(
			self::options(
				array(),
				array(
					'acme_cache'   => array( str_repeat( 'x', PotentialImpact::LARGE_OPTION_BYTES + 1 ), 'on' ),
					'acme_version' => array( '2.0.0', 'off' ),
				)
			),
			self::cron( array(), array() ),
			self::passed_both()
		);

		$this->assertSame( array( 'review' ), array_column( $result['impact']['findings'], 'priority' ) );
		$this->assertSame( array( 0, 1, 1 ), self::counts( $result['assessment'] ) );
	}

	/**
	 * A removed recurring event covers its removed (and replacing) recurring
	 * instances; another hook's rescheduling stays informational, and a
	 * schedule change covers its one changed entry.
	 */
	public function test_cron_findings_cover_their_records() {
		$result = self::assess(
			self::options( array(), array() ),
			self::cron(
				array(
					CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 1 ) ),
					CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 2 ) ),
					CronFixture::recurring( self::T, 'acme_report', 'daily' ),
					CronFixture::recurring( self::T, 'acme_cleanup', 'daily' ),
				),
				array(
					CronFixture::recurring( self::T, 'acme_report', 'hourly' ),
					CronFixture::recurring( self::T + 60, 'acme_cleanup', 'daily' ),
				)
			),
			self::passed_both()
		);

		$this->assertSame( array( 'recurring_cron_event_removed', 'recurring_schedule_changed' ), array_column( $result['impact']['findings'], 'code' ) );
		// 2 removed + 1 changed + 1 rescheduled observed; 3 covered by findings.
		$this->assertSame( array( 0, 2, 1 ), self::counts( $result['assessment'] ) );
	}

	/**
	 * A verified pass before and a failure both requests confirmed after the
	 * update is the only high priority finding; a new redirect needs review.
	 */
	public function test_newly_failing_site_check_is_high_priority() {
		$fail     = F::home(
			array(
				'status'     => 500,
				'end_marker' => 'absent',
			)
		);
		$failing  = self::assess(
			self::options( array(), array() ),
			self::cron( array(), array() ),
			F::analysis_row(
				self::passing_before(),
				array(
					'home' => F::after( 'home', array( $fail, $fail ) ),
					'rest' => F::after( 'rest', array( F::rest() ) ),
				)
			)
		);
		$redirect = F::no_response( 'redirect', 120 );
		$changed  = self::assess(
			self::options( array(), array() ),
			self::cron( array(), array() ),
			F::analysis_row(
				self::passing_before(),
				array(
					'home' => F::after( 'home', array( $redirect, $redirect ) ),
					'rest' => F::after( 'rest', array( F::rest() ) ),
				)
			)
		);

		$this->assertSame( array( 'high' ), array_column( $failing['impact']['site_checks']['findings'], 'priority' ) );
		$this->assertSame( array( 1, 0, 0 ), self::counts( $failing['assessment'] ) );
		$this->assertSame( array( 'review' ), array_column( $changed['impact']['site_checks']['findings'], 'priority' ) );
		$this->assertSame( array( 0, 1, 0 ), self::counts( $changed['assessment'] ) );
		$this->assertSame( array(), $failing['assessment']['not_assessed'] );
	}

	/**
	 * Missing observations are listed with their reasons, apart from the
	 * priorities: an expired Net result is not "no changes", and site checks
	 * ended by a queued update are not passed.
	 */
	public function test_missing_observations_are_not_assessed() {
		$result = self::assess(
			'settle_expired',
			'settle_expired',
			F::analysis_row( self::passing_before(), array(), array( 'settle_outcome' => 'next_update' ) ),
			false,
			'settle_expired'
		);

		$this->assertSame(
			array(
				'high_priority' => 0,
				'needs_review'  => 0,
				'informational' => null,
				'not_assessed'  => array(
					array(
						'type'   => 'signal',
						'name'   => 'options',
						'reason' => 'settle_expired',
					),
					array(
						'type'   => 'signal',
						'name'   => 'cron',
						'reason' => 'settle_expired',
					),
					array(
						'type'   => 'signal',
						'name'   => 'action_scheduler',
						'reason' => 'settle_expired',
					),
					array(
						'type'   => 'site_check',
						'name'   => 'home',
						'reason' => 'next_update',
					),
					array(
						'type'   => 'site_check',
						'name'   => 'rest',
						'reason' => 'next_update',
					),
				),
				'pending'       => array(),
			),
			$result['assessment']
		);
	}

	/**
	 * An Action Scheduler not detected at one capture only (an older report)
	 * is not assessed; one absent throughout is not applicable, like site
	 * checks that were turned off.
	 */
	public function test_not_applicable_is_never_listed() {
		$off      = F::analysis_row(
			array(
				'home' => 'disabled',
				'rest' => 'disabled',
			),
			array()
		);
		$absent   = self::assess( self::options( array(), array() ), self::cron( array(), array() ), $off, true );
		$one_time = self::assess( self::options( array(), array() ), self::cron( array(), array() ), $off, false );

		$this->assertSame( array(), $absent['assessment']['not_assessed'] );
		$this->assertSame(
			array(
				array(
					'type'   => 'signal',
					'name'   => 'action_scheduler',
					'reason' => 'not_installed',
				),
			),
			$one_time['assessment']['not_assessed']
		);
	}

	/**
	 * While the observation runs and site check results may still arrive,
	 * they are pending: neither assessed nor missing.
	 */
	public function test_pending_observations() {
		$result = self::assess(
			'awaiting_settle',
			'awaiting_settle',
			F::analysis_row( self::passing_before(), array(), array( 'status' => 'awaiting_settle' ) ),
			false,
			'awaiting_settle',
			F::UPDATE_END + 5
		);

		$this->assertSame( array(), $result['assessment']['not_assessed'] );
		$this->assertNull( $result['assessment']['informational'] );
		$this->assertSame(
			array( 'signal:options', 'signal:cron', 'signal:action_scheduler', 'site_check:home', 'site_check:rest' ),
			array_map(
				static function ( array $entry ) {
					return $entry['type'] . ':' . $entry['name'];
				},
				$result['assessment']['pending']
			)
		);
	}

	/**
	 * High priority, needs review and informational counts.
	 *
	 * @param array $assessment Assessment.
	 * @return int[]
	 */
	private static function counts( array $assessment ) {
		return array( $assessment['high_priority'], $assessment['needs_review'], $assessment['informational'] );
	}
}
