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
				'high_priority'    => 0,
				'needs_review'     => 0,
				'informational'    => 0,
				'assessed_changes' => 0,
				'net_result'       => array(
					'options'          => array(
						'changes'       => 0,
						'matched'       => 0,
						'informational' => 0,
					),
					'cron'             => array(
						'changes'       => 0,
						'matched'       => 0,
						'informational' => 0,
					),
					// Absent throughout: not applicable, so not evaluated.
					'action_scheduler' => null,
				),
				'not_assessed'     => array(),
				'pending'          => array(),
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
	 * Regression: two recurring instances of a hook removed and one re-added.
	 * The finding names exactly the records it covers (both removed instances
	 * as subjects, the re-added one as a replacement), and the counts come
	 * from those records: a one-time instance of the same hook removed with
	 * them, and another hook's rescheduling, stay informational. The signal
	 * page marks these same records, so it cannot count differently.
	 */
	public function test_two_instances_removed_one_readded() {
		$diff   = self::cron(
			array(
				CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 1 ) ),
				CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 2 ) ),
				CronFixture::single( self::T + 30, 'acme_sync', array( 'once' ) ),
				CronFixture::recurring( self::T, 'acme_report', 'daily' ),
			),
			array(
				CronFixture::recurring( self::T + 120, 'acme_sync', 'hourly', array( 3 ) ),
				CronFixture::recurring( self::T + 60, 'acme_report', 'daily' ),
			)
		);
		$result = self::assess( self::options( array(), array() ), $diff, self::passed_both() );

		$this->assertCount( 1, $result['impact']['findings'] );
		$finding = $result['impact']['findings'][0];
		$this->assertSame( array( 'recurring_cron_event_removed', 'acme_sync' ), array( $finding['code'], $finding['hook'] ) );
		$this->assertSame( array( 2, 1, 1 ), array( $finding['evidence']['removed_recurring_count'], $finding['evidence']['added_recurring_count'], $finding['evidence']['not_replaced_count'] ) );

		// Every record is an entry of the finding's hook, in the list it names.
		$roles = array();
		foreach ( $finding['records'] as $record ) {
			$entry = $diff[ $record['list'] ][ $record['index'] ];
			$this->assertSame( 'acme_sync', $entry['hook'] );
			$this->assertTrue( $entry['is_recurring'] );
			$roles[] = $record['list'] . ':' . $record['role'];
		}
		$this->assertSame( array( 'removed:subject', 'removed:subject', 'added:replacement' ), $roles );

		// 3 removed (2 recurring, 1 one-time) + 1 added + 1 rescheduled = 5 records; 3 covered.
		$this->assertSame(
			array(
				'changes'       => 5,
				'matched'       => 3,
				'informational' => 2,
			),
			$result['assessment']['net_result']['cron']
		);
		$this->assertSame( array( 0, 1, 2 ), self::counts( $result['assessment'] ) );
	}

	/**
	 * Findings never share a record, and every record of every rule exists in
	 * the Net result: matched counts distinct records.
	 */
	public function test_records_are_distinct_and_exist() {
		$options = self::options(
			array( 'acme_grow' => array( 'x', 'on' ) ),
			array(
				'acme_grow'  => array( str_repeat( 'x', PotentialImpact::LARGE_OPTION_BYTES + 1 ), 'on' ),
				'acme_cache' => array( str_repeat( 'y', PotentialImpact::LARGE_OPTION_BYTES + 1 ), 'on' ),
			)
		);
		$cron    = self::cron(
			array(
				CronFixture::recurring( self::T, 'acme_gone', 'daily' ),
				CronFixture::recurring( self::T, 'acme_sync', 'daily' ),
			),
			array( CronFixture::recurring( self::T, 'acme_sync', 'hourly' ) )
		);
		$result  = self::assess( $options, $cron, self::passed_both() );
		$diffs   = array(
			'options' => $options,
			'cron'    => $cron,
		);

		$seen = array();
		foreach ( $result['impact']['findings'] as $finding ) {
			foreach ( $finding['records'] as $record ) {
				$key = $finding['signal'] . ':' . $record['list'] . ':' . $record['index'];
				$this->assertArrayNotHasKey( $key, $seen );
				$this->assertArrayHasKey( $record['index'], $diffs[ $finding['signal'] ][ $record['list'] ] );
				$seen[ $key ] = true;
			}
		}
		$this->assertCount( 4, $seen );
		$this->assertSame( 2, $result['assessment']['net_result']['options']['matched'] );
		$this->assertSame( 2, $result['assessment']['net_result']['cron']['matched'] );
		$this->assertSame( 0, $result['assessment']['informational'] );
	}

	/**
	 * Not assessed entries say why, by category: an expired observation, a
	 * report that predates a source, a source that could not be collected,
	 * and site checks that were not compared. None is "no changes".
	 */
	public function test_not_assessed_categories() {
		$historical = self::assess( 'settle_expired', 'not_captured', F::analysis_row( self::passing_before(), array(), array( 'settle_outcome' => 'expired' ) ), false, 'not_captured' );
		$failed     = self::assess( self::options( array(), array() ), 'snapshot_unavailable', F::analysis_row( self::passing_before(), array(), array( 'settle_outcome' => 'admin_shutdown' ) ), false, 'data_corrupt' );

		$this->assertSame(
			array(
				'signal:options:settle_expired:expired',
				'signal:cron:not_captured:not_recorded',
				'signal:action_scheduler:not_captured:not_recorded',
				'site_check:home:settle_expired:expired',
				'site_check:rest:settle_expired:expired',
			),
			self::not_assessed( $historical['assessment'] )
		);
		$this->assertSame(
			array(
				'signal:cron:snapshot_unavailable:unavailable',
				'signal:action_scheduler:data_corrupt:unavailable',
				'site_check:home:not_followed_up:not_compared',
				'site_check:rest:not_followed_up:not_compared',
			),
			self::not_assessed( $failed['assessment'] )
		);
		// Options was evaluated: its records count, the others are not guessed.
		$this->assertSame( 0, $failed['assessment']['informational'] );
		$this->assertNull( $failed['assessment']['net_result']['cron'] );
	}

	/**
	 * Only site check rules are high priority, so History's count from the
	 * site check evaluation alone is the report's whole high priority count.
	 */
	public function test_only_site_check_rules_are_high_priority() {
		foreach ( PotentialImpact::PRIORITIES as $code => $priority ) {
			if ( PotentialImpact::PRIORITY_HIGH === $priority ) {
				$this->assertContains( $code, array( PotentialImpact::SITE_CHECK_NEWLY_FAILING, PotentialImpact::SITE_CHECK_CHANGED ), 'A high priority configuration rule would need History to decode diffs.' );
			}
		}

		$fail   = F::home(
			array(
				'status'     => 500,
				'end_marker' => 'absent',
			)
		);
		$result = self::assess(
			self::options( array(), array( 'acme_cache' => array( str_repeat( 'x', PotentialImpact::LARGE_OPTION_BYTES + 1 ), 'on' ) ) ),
			self::cron( array(), array() ),
			F::analysis_row(
				self::passing_before(),
				array(
					'home' => F::after( 'home', array( $fail, $fail ) ),
					'rest' => F::after( 'rest', array( F::rest() ) ),
				)
			)
		);
		$this->assertSame( 1, $result['assessment']['high_priority'] );
		$this->assertSame( $result['assessment']['high_priority'], PotentialImpact::site_check_high_priority( $result['impact']['site_checks'] ) );
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
				'high_priority'    => 0,
				'needs_review'     => 0,
				'informational'    => null,
				'assessed_changes' => null,
				'net_result'       => array(
					'options'          => null,
					'cron'             => null,
					'action_scheduler' => null,
				),
				'not_assessed'     => array(
					array(
						'type'     => 'signal',
						'name'     => 'options',
						'reason'   => 'settle_expired',
						'category' => 'expired',
					),
					array(
						'type'     => 'signal',
						'name'     => 'cron',
						'reason'   => 'settle_expired',
						'category' => 'expired',
					),
					array(
						'type'     => 'signal',
						'name'     => 'action_scheduler',
						'reason'   => 'settle_expired',
						'category' => 'expired',
					),
					array(
						'type'     => 'site_check',
						'name'     => 'home',
						'reason'   => 'next_update',
						'category' => 'not_compared',
					),
					array(
						'type'     => 'site_check',
						'name'     => 'rest',
						'reason'   => 'next_update',
						'category' => 'not_compared',
					),
				),
				'pending'          => array(),
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
					'type'     => 'signal',
					'name'     => 'action_scheduler',
					'reason'   => 'not_installed',
					'category' => 'unavailable',
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

	/**
	 * Not assessed entries as `type:name:reason:category`.
	 *
	 * @param array $assessment Assessment.
	 * @return string[]
	 */
	private static function not_assessed( array $assessment ) {
		return array_map(
			static function ( array $entry ) {
				return $entry['type'] . ':' . $entry['name'] . ':' . $entry['reason'] . ':' . $entry['category'];
			},
			$assessment['not_assessed']
		);
	}
}
