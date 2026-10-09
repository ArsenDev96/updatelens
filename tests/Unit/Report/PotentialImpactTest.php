<?php
/**
 * Tests for PotentialImpact.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use UpdateLens\Diff\ActionSchedulerDiffBuilder;
use UpdateLens\Diff\CronDiffBuilder;
use UpdateLens\Diff\OptionsDiffBuilder;
use UpdateLens\Report\PotentialImpact;
use UpdateLens\Snapshot\AutoloadPolicy;
use UpdateLens\Snapshot\OptionNoiseFilter;
use UpdateLens\Snapshot\OptionsSnapshotBuilder;
use UpdateLens\Snapshot\OptionValueHasher;
use UpdateLens\Storage\ActionSchedulerDiffCodec;
use UpdateLens\Storage\CronDiffCodec;
use UpdateLens\Storage\OptionsDiffCodec;
use UpdateLens\Tests\Support\ActionSchedulerFixture as AS_Fixture;
use UpdateLens\Tests\Support\CronFixture;

/**
 * Decoded Net result diffs → Potential Impact checks and findings.
 *
 * Every fixture goes through the real snapshot builder, diff builder and
 * codec (encode + decode), so the evaluator sees exactly what reports decode.
 */
final class PotentialImpactTest extends TestCase {

	const SECRET = 'test-site-secret';

	/**
	 * Obviously fake credential used in privacy fixtures.
	 */
	const FAKE_SECRET = 'sk_test_UPDATE_LENS_IMPACT_SECRET';

	const T = 1767225600; // 2026-01-01T00:00:00Z.

	const LIMIT = PotentialImpact::LARGE_OPTION_BYTES;

	/**
	 * Decoded Options diff from `name => [ raw value, raw autoload ]` rows.
	 *
	 * Uses the WordPress 6.6+ autoload values explicitly (`auto` counts as
	 * autoloaded, as on sites below the size limit).
	 *
	 * @param array<string, array{string, string}> $before Options before.
	 * @param array<string, array{string, string}> $after  Options after.
	 * @return array
	 */
	private function options( array $before, array $after ) {
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
	private function cron( array $before, array $after ) {
		$codec = new CronDiffCodec();
		$diff  = ( new CronDiffBuilder() )->build(
			CronFixture::snapshot( CronFixture::cron( $before ) ),
			CronFixture::snapshot( CronFixture::cron( $after ) )
		);

		return $codec->decode( $codec->encode( $diff ) );
	}

	/**
	 * Decoded Action Scheduler diff from ActionSchedulerFixture rows.
	 *
	 * @param array $before Rows before.
	 * @param array $after  Rows after.
	 * @return array
	 */
	private function action_scheduler( array $before, array $after ) {
		$codec = new ActionSchedulerDiffCodec();
		$diff  = ( new ActionSchedulerDiffBuilder() )->build( AS_Fixture::snapshot( $before ), AS_Fixture::snapshot( $after ) );

		return $codec->decode( $codec->encode( $diff ) );
	}

	/**
	 * Evaluate.
	 *
	 * @param array|null $options          Options diff.
	 * @param array|null $cron             WP-Cron diff.
	 * @param array|null $action_scheduler Action Scheduler diff.
	 * @return array
	 */
	private function evaluate( ?array $options, ?array $cron = null, ?array $action_scheduler = null ) {
		return ( new PotentialImpact() )->evaluate( $options, $cron, $action_scheduler );
	}

	/**
	 * Findings of one rule and signal.
	 *
	 * @param array  $result Evaluation.
	 * @param string $code   Rule.
	 * @param string $signal Signal.
	 * @return array<int, array>
	 */
	private function findings( array $result, $code, $signal ) {
		return array_values(
			array_filter(
				$result['findings'],
				static function ( array $finding ) use ( $code, $signal ) {
					return $code === $finding['code'] && $signal === $finding['signal'];
				}
			)
		);
	}

	/**
	 * Options findings.
	 *
	 * @param array $before Options before.
	 * @param array $after  Options after.
	 * @return array<int, array>
	 */
	private function option_findings( array $before, array $after ) {
		return $this->findings( $this->evaluate( $this->options( $before, $after ) ), PotentialImpact::LARGE_AUTOLOADED_OPTION, PotentialImpact::SIGNAL_OPTIONS );
	}

	/**
	 * WP-Cron findings of one rule.
	 *
	 * @param string $code   Rule.
	 * @param array  $before Events before.
	 * @param array  $after  Events after.
	 * @return array<int, array>
	 */
	private function cron_findings( $code, array $before, array $after ) {
		return $this->findings( $this->evaluate( null, $this->cron( $before, $after ) ), $code, PotentialImpact::SIGNAL_CRON );
	}

	/**
	 * Action Scheduler schedule-change findings.
	 *
	 * @param array $before Rows before.
	 * @param array $after  Rows after.
	 * @return array<int, array>
	 */
	private function action_scheduler_findings( array $before, array $after ) {
		return $this->findings( $this->evaluate( null, null, $this->action_scheduler( $before, $after ) ), PotentialImpact::RECURRING_SCHEDULE_CHANGED, PotentialImpact::SIGNAL_ACTION_SCHEDULER );
	}

	/**
	 * A value of a given byte size.
	 *
	 * @param int $bytes Size.
	 * @return string
	 */
	private static function bytes( $bytes ) {
		return str_repeat( 'x', $bytes );
	}

	// ---------------------------------------------------------------------
	// Checks and availability.
	// ---------------------------------------------------------------------

	/**
	 * Without any Net result nothing is evaluated, and that is not "no findings" per check.
	 */
	public function test_nothing_available_is_not_evaluated() {
		$this->assertSame(
			array(
				'checks'   => array(
					array(
						'rule'   => 'large_autoloaded_option',
						'signal' => 'options',
						'status' => 'not_evaluated',
					),
					array(
						'rule'   => 'recurring_cron_event_removed',
						'signal' => 'cron',
						'status' => 'not_evaluated',
					),
					array(
						'rule'   => 'recurring_schedule_changed',
						'signal' => 'cron',
						'status' => 'not_evaluated',
					),
					array(
						'rule'   => 'recurring_schedule_changed',
						'signal' => 'action_scheduler',
						'status' => 'not_evaluated',
					),
				),
				'findings' => array(),
			),
			$this->evaluate( null, null, null )
		);
	}

	/**
	 * Empty diffs: every check evaluated, no findings.
	 */
	public function test_everything_evaluated_without_findings() {
		$result = $this->evaluate( $this->options( array(), array() ), $this->cron( array(), array() ), $this->action_scheduler( array(), array() ) );

		$this->assertSame( array( 'evaluated', 'evaluated', 'evaluated', 'evaluated' ), array_column( $result['checks'], 'status' ) );
		$this->assertSame( array(), $result['findings'] );
	}

	/**
	 * Each signal's availability is independent of the others.
	 *
	 * @dataProvider provide_availability
	 *
	 * @param bool  $options          Options available.
	 * @param bool  $cron             WP-Cron available.
	 * @param bool  $action_scheduler Action Scheduler available.
	 * @param array $statuses         Expected check statuses.
	 */
	public function test_signal_availability_is_independent( $options, $cron, $action_scheduler, array $statuses ) {
		$large  = array( 'acme_cache' => array( self::bytes( self::LIMIT + 1 ), 'on' ) );
		$result = $this->evaluate(
			$options ? $this->options( array(), $large ) : null,
			$cron ? $this->cron( array( CronFixture::recurring( self::T, 'acme_sync', 'daily' ) ), array( CronFixture::recurring( self::T, 'acme_sync', 'hourly' ) ) ) : null,
			$action_scheduler ? $this->action_scheduler( array( AS_Fixture::recurring( 'acme_job', self::T, 3600 ) ), array( AS_Fixture::recurring( 'acme_job', self::T, 600 ) ) ) : null
		);

		$this->assertSame( $statuses, array_column( $result['checks'], 'status' ) );
		$expected = array();
		if ( $options ) {
			$expected[] = 'large_autoloaded_option:options';
		}
		if ( $cron ) {
			$expected[] = 'recurring_schedule_changed:cron';
		}
		if ( $action_scheduler ) {
			$expected[] = 'recurring_schedule_changed:action_scheduler';
		}
		$this->assertSame(
			$expected,
			array_map(
				static function ( array $finding ) {
					return $finding['code'] . ':' . $finding['signal'];
				},
				$result['findings']
			)
		);
	}

	/**
	 * Availability combinations.
	 *
	 * @return array
	 */
	public function provide_availability() {
		$e = 'evaluated';
		$n = 'not_evaluated';
		return array(
			'only options'          => array( true, false, false, array( $e, $n, $n, $n ) ),
			'only cron'             => array( false, true, false, array( $n, $e, $e, $n ) ),
			'only action scheduler' => array( false, false, true, array( $n, $n, $n, $e ) ),
			'options missing'       => array( false, true, true, array( $n, $e, $e, $e ) ),
			'cron missing'          => array( true, false, true, array( $e, $n, $n, $e ) ),
			'all'                   => array( true, true, true, array( $e, $e, $e, $e ) ),
		);
	}

	// ---------------------------------------------------------------------
	// Rule 3: large autoloaded option.
	// ---------------------------------------------------------------------

	/**
	 * An added autoloaded option above the threshold: the full finding.
	 */
	public function test_added_large_autoloaded_option() {
		$this->assertSame(
			array(
				array(
					'code'                 => 'large_autoloaded_option',
					'signal'               => 'options',
					'name'                 => 'acme_cache',
					'transition'           => 'added',
					'threshold_bytes'      => 150000,
					'before_size'          => null,
					'after_size'           => 180000,
					'before_autoload'      => null,
					'after_autoload'       => 'on',
					'before_is_autoloaded' => null,
					'after_is_autoloaded'  => true,
				),
			),
			$this->option_findings( array(), array( 'acme_cache' => array( self::bytes( 180000 ), 'on' ) ) )
		);
	}

	/**
	 * The threshold is exclusive: exactly 150,000 bytes is not large.
	 *
	 * @dataProvider provide_threshold
	 *
	 * @param int  $size     Size after.
	 * @param bool $is_large Whether a finding is expected.
	 */
	public function test_threshold_boundary_for_added( $size, $is_large ) {
		$this->assertCount( $is_large ? 1 : 0, $this->option_findings( array(), array( 'acme_cache' => array( self::bytes( $size ), 'on' ) ) ) );
	}

	/**
	 * The threshold applies to becoming autoloaded too.
	 *
	 * @dataProvider provide_threshold
	 *
	 * @param int  $size     Size after.
	 * @param bool $is_large Whether a finding is expected.
	 */
	public function test_threshold_boundary_for_becoming_autoloaded( $size, $is_large ) {
		$value = self::bytes( $size );
		$this->assertCount( $is_large ? 1 : 0, $this->option_findings( array( 'acme_cache' => array( $value, 'off' ) ), array( 'acme_cache' => array( $value, 'on' ) ) ) );
	}

	/**
	 * Sizes around the threshold.
	 *
	 * @return array
	 */
	public function provide_threshold() {
		return array(
			'149,999 bytes' => array( self::LIMIT - 1, false ),
			'150,000 bytes' => array( self::LIMIT, false ),
			'150,001 bytes' => array( self::LIMIT + 1, true ),
		);
	}

	/**
	 * Growth past the threshold: at or below before, above after.
	 *
	 * @dataProvider provide_growth
	 *
	 * @param int  $before   Size before.
	 * @param int  $after    Size after.
	 * @param bool $is_found Whether a finding is expected.
	 */
	public function test_growth_past_threshold( $before, $after, $is_found ) {
		$findings = $this->option_findings(
			array( 'acme_cache' => array( self::bytes( $before ), 'on' ) ),
			array( 'acme_cache' => array( self::bytes( $after ), 'on' ) )
		);

		$this->assertCount( $is_found ? 1 : 0, $findings );
		if ( $is_found ) {
			$this->assertSame( 'grew_past_threshold', $findings[0]['transition'] );
			$this->assertSame( array( $before, $after ), array( $findings[0]['before_size'], $findings[0]['after_size'] ) );
			$this->assertTrue( $findings[0]['before_is_autoloaded'] );
		}
	}

	/**
	 * Growth cases.
	 *
	 * @return array
	 */
	public function provide_growth() {
		return array(
			'small to large'                 => array( 1000, 200000, true ),
			'exactly the threshold to above' => array( self::LIMIT, self::LIMIT + 1, true ),
			'below to exactly the threshold' => array( self::LIMIT - 1, self::LIMIT, false ),
			'growth below the threshold'     => array( 1000, 149000, false ),
			'already large, grows'           => array( 160000, 300000, false ),
			'already large, shrinks'         => array( 300000, 160000, false ),
			'large shrinks below'            => array( 300000, 1000, false ),
		);
	}

	/**
	 * Already large and autoloaded, without a relevant transition: never a finding.
	 */
	public function test_already_large_autoloaded_options_are_not_findings() {
		$large = self::bytes( 200000 );
		$other = str_repeat( 'y', 200000 );

		$this->assertSame(
			array(),
			$this->option_findings(
				array(
					'acme_same_size' => array( $large, 'on' ),
					'acme_raw_only'  => array( $large, 'yes' ),
					'acme_unchanged' => array( $large, 'on' ),
				),
				array(
					'acme_same_size' => array( $other, 'on' ),       // Value changed, same size.
					'acme_raw_only'  => array( $large, 'auto-on' ),  // Raw autoload only.
					'acme_unchanged' => array( $large, 'on' ),
				)
			)
		);
	}

	/**
	 * An existing large option that becomes autoloaded is a finding, even with the same value.
	 */
	public function test_large_option_becoming_autoloaded() {
		$value = self::bytes( 200000 );
		$found = $this->option_findings( array( 'acme_cache' => array( $value, 'off' ) ), array( 'acme_cache' => array( $value, 'on' ) ) );

		$this->assertSame(
			array(
				array(
					'code'                 => 'large_autoloaded_option',
					'signal'               => 'options',
					'name'                 => 'acme_cache',
					'transition'           => 'became_autoloaded',
					'threshold_bytes'      => 150000,
					'before_size'          => 200000,
					'after_size'           => 200000,
					'before_autoload'      => 'off',
					'after_autoload'       => 'on',
					'before_is_autoloaded' => false,
					'after_is_autoloaded'  => true,
				),
			),
			$found
		);
	}

	/**
	 * A small non-autoloaded option that becomes autoloaded and large at once is "became autoloaded".
	 */
	public function test_small_option_becoming_autoloaded_and_large() {
		$found = $this->option_findings( array( 'acme_cache' => array( 'x', 'auto-off' ) ), array( 'acme_cache' => array( self::bytes( 200000 ), 'auto-on' ) ) );

		$this->assertCount( 1, $found );
		$this->assertSame( 'became_autoloaded', $found[0]['transition'] );
		$this->assertSame( array( 1, 200000 ), array( $found[0]['before_size'], $found[0]['after_size'] ) );
	}

	/**
	 * Effective autoload behavior decides, not the raw string.
	 */
	public function test_effective_autoload_decides() {
		$large = self::bytes( 200000 );

		// Raw `auto` is effectively autoloaded: a finding.
		$found = $this->option_findings( array(), array( 'acme_auto' => array( $large, 'auto' ) ) );
		$this->assertCount( 1, $found );
		$this->assertSame( array( 'auto', true ), array( $found[0]['after_autoload'], $found[0]['after_is_autoloaded'] ) );

		// Raw change between two autoloaded values while crossing the threshold: growth, not "became autoloaded".
		$found = $this->option_findings( array( 'acme_cache' => array( 'x', 'yes' ) ), array( 'acme_cache' => array( $large, 'auto-on' ) ) );
		$this->assertCount( 1, $found );
		$this->assertSame( array( 'grew_past_threshold', 'yes', 'auto-on' ), array( $found[0]['transition'], $found[0]['before_autoload'], $found[0]['after_autoload'] ) );

		// Not autoloaded after (raw values that are off): never a finding.
		$this->assertSame(
			array(),
			$this->option_findings(
				array(
					'acme_off'      => array( $large, 'on' ),
					'acme_auto_off' => array( 'x', 'off' ),
				),
				array(
					'acme_off'      => array( $large, 'off' ),
					'acme_auto_off' => array( $large, 'auto-off' ),
					'acme_added'    => array( $large, 'no' ),
				)
			)
		);
	}

	/**
	 * Removed options are never findings.
	 */
	public function test_removed_large_autoloaded_option_is_not_a_finding() {
		$this->assertSame( array(), $this->option_findings( array( 'acme_cache' => array( self::bytes( 200000 ), 'on' ) ), array() ) );
	}

	/**
	 * Findings from added and changed options are sorted by name, byte-wise.
	 */
	public function test_option_findings_sorted_by_name() {
		$large = self::bytes( 200000 );
		$found = $this->option_findings(
			array(
				'b_grows'  => array( 'x', 'on' ),
				'Z_turned' => array( $large, 'off' ),
			),
			array(
				'b_grows'  => array( $large, 'on' ),
				'Z_turned' => array( $large, 'on' ),
				'a_added'  => array( $large, 'on' ),
				'123'      => array( $large, 'on' ),
				'c_added'  => array( $large, 'on' ),
			)
		);

		$this->assertSame( array( '123', 'Z_turned', 'a_added', 'b_grows', 'c_added' ), array_column( $found, 'name' ) );
		$this->assertSame( array( 'added', 'became_autoloaded', 'added', 'grew_past_threshold', 'added' ), array_column( $found, 'transition' ) );
	}

	// ---------------------------------------------------------------------
	// Rule 1: removed recurring WP-Cron event.
	// ---------------------------------------------------------------------

	/**
	 * A removed recurring event: the full finding with its prior schedule.
	 */
	public function test_removed_recurring_event() {
		$this->assertSame(
			array(
				array(
					'code'                  => 'recurring_cron_event_removed',
					'signal'                => 'cron',
					'hook'                  => 'acme_daily_sync',
					'removed'               => array(
						array(
							'timestamp' => self::T + 60,
							'schedule'  => 'daily',
							'interval'  => 86400,
						),
					),
					'removed_count'         => 1,
					'added_recurring_count' => 0,
					'still_observed_count'  => 0,
				),
			),
			$this->cron_findings(
				PotentialImpact::RECURRING_CRON_EVENT_REMOVED,
				array(
					CronFixture::recurring( self::T, 'wp_version_check', 'twicedaily' ),
					CronFixture::recurring( self::T + 60, 'acme_daily_sync', 'daily', array( 'feed' => 1 ) ),
				),
				array(
					CronFixture::recurring( self::T, 'wp_version_check', 'twicedaily' ),
				)
			)
		);
	}

	/**
	 * Removed one-time events are never findings (they may simply have run).
	 */
	public function test_removed_one_time_event_is_not_a_finding() {
		$result = $this->evaluate(
			null,
			$this->cron(
				array(
					CronFixture::single( self::T, 'acme_send_digest', array( 9 ) ),
					CronFixture::single( self::T + 5, 'wp_update_plugins' ),
				),
				array()
			)
		);

		$this->assertSame( array(), $result['findings'] );
		$this->assertSame( 'evaluated', $result['checks'][1]['status'] );
	}

	/**
	 * Ordinary rescheduling of recurring events is never a finding.
	 */
	public function test_rescheduled_recurring_event_is_not_a_finding() {
		$result = $this->evaluate(
			null,
			$this->cron(
				array(
					CronFixture::recurring( self::T, 'wp_version_check', 'twicedaily' ),
					CronFixture::recurring( self::T + 60, 'acme_sync', 'hourly', array( 'feed' => 1 ) ),
				),
				array(
					CronFixture::recurring( self::T + 43200, 'wp_version_check', 'twicedaily' ),
					CronFixture::recurring( self::T + 3660, 'acme_sync', 'hourly', array( 'feed' => 1 ) ),
				)
			)
		);

		$this->assertSame( array(), $result['findings'] );
	}

	/**
	 * A recurring event replaced by a recurring event of the same hook (other arguments,
	 * even another schedule) is not a finding: the diff cannot tell it from a reconfiguration.
	 *
	 * @dataProvider provide_replacements
	 *
	 * @param array $after Replacement events.
	 */
	public function test_replaced_recurring_event_is_not_a_finding( array $after ) {
		$this->assertSame(
			array(),
			$this->cron_findings(
				PotentialImpact::RECURRING_CRON_EVENT_REMOVED,
				array( CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 'v' => 1 ) ) ),
				$after
			)
		);
	}

	/**
	 * Replacements.
	 *
	 * @return array
	 */
	public function provide_replacements() {
		return array(
			'other arguments'                => array( array( CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 'v' => 2 ) ) ) ),
			'other arguments and time'       => array( array( CronFixture::recurring( self::T + 99, 'acme_sync', 'daily', array( 'v' => 2 ) ) ) ),
			'other arguments and schedule'   => array( array( CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 'v' => 2 ) ) ) ),
			'replaced by two recurring ones' => array(
				array(
					CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 'v' => 2 ) ),
					CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 'v' => 3 ) ),
				),
			),
		);
	}

	/**
	 * Replaced by fewer recurring instances: a finding with the counts as evidence.
	 */
	public function test_partly_replaced_recurring_events() {
		$found = $this->cron_findings(
			PotentialImpact::RECURRING_CRON_EVENT_REMOVED,
			array(
				CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 'site' => 1 ) ),
				CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 'site' => 2 ) ),
			),
			array(
				CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 'site' => 3 ) ),
			)
		);

		$this->assertCount( 1, $found );
		$this->assertSame( array( 2, 1, 1 ), array( $found[0]['removed_count'], $found[0]['added_recurring_count'], $found[0]['still_observed_count'] ) );
		$this->assertCount( 2, $found[0]['removed'] );
	}

	/**
	 * A recurring event replaced only by a one-time event of the same hook is still a
	 * finding: no recurring instance replaces it. The one-time event is evidence.
	 */
	public function test_recurring_event_replaced_by_one_time_event() {
		$found = $this->cron_findings(
			PotentialImpact::RECURRING_CRON_EVENT_REMOVED,
			array( CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 'v' => 1 ) ) ),
			array( CronFixture::single( self::T + 10, 'acme_sync', array( 'v' => 2 ) ) )
		);

		$this->assertCount( 1, $found );
		$this->assertSame( array( 1, 0, 1 ), array( $found[0]['removed_count'], $found[0]['added_recurring_count'], $found[0]['still_observed_count'] ) );
	}

	/**
	 * Several instances of one hook: one finding per hook; instances that stay unchanged
	 * are not in the diff, so `still_observed_count` counts only instances the diff shows.
	 */
	public function test_multiple_instances_of_one_hook() {
		$before = array(
			CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 'site' => 1 ) ),
			CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 'site' => 2 ) ),
			CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 'site' => 3 ) ),
			CronFixture::recurring( self::T + 30, 'acme_sync', 'daily', array( 'site' => 4 ) ),
		);

		// One removed, the others unchanged: the diff cannot show that instances remain.
		$found = $this->cron_findings( PotentialImpact::RECURRING_CRON_EVENT_REMOVED, $before, array_slice( $before, 0, 3 ) );
		$this->assertCount( 1, $found );
		$this->assertSame( array( 1, 0 ), array( $found[0]['removed_count'], $found[0]['still_observed_count'] ) );
		$this->assertSame(
			array(
				array(
					'timestamp' => self::T + 30,
					'schedule'  => 'daily',
					'interval'  => 86400,
				),
			),
			$found[0]['removed']
		);

		// Two removed, one rescheduled, one unchanged: one finding; the rescheduled one is still observed.
		$found = $this->cron_findings(
			PotentialImpact::RECURRING_CRON_EVENT_REMOVED,
			$before,
			array(
				CronFixture::recurring( self::T + 3600, 'acme_sync', 'hourly', array( 'site' => 1 ) ),
				CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 'site' => 2 ) ),
			)
		);
		$this->assertCount( 1, $found );
		$this->assertSame( array( 2, 0, 1 ), array( $found[0]['removed_count'], $found[0]['added_recurring_count'], $found[0]['still_observed_count'] ) );
		$this->assertSame( array( 'hourly', 'daily' ), array_column( $found[0]['removed'], 'schedule' ) );
	}

	/**
	 * A recurring event without a stored interval is still recurring; the interval is unknown (null).
	 */
	public function test_removed_recurring_event_without_interval() {
		$found = $this->cron_findings(
			PotentialImpact::RECURRING_CRON_EVENT_REMOVED,
			array( CronFixture::recurring( self::T, 'acme_sync', 'acme_custom' ) ),
			array()
		);

		$this->assertCount( 1, $found );
		$this->assertSame(
			array(
				'timestamp' => self::T,
				'schedule'  => 'acme_custom',
				'interval'  => null,
			),
			$found[0]['removed'][0]
		);
	}

	/**
	 * A recurring event that turns one-time (same arguments) is changed, not removed:
	 * no removal finding, a schedule-change finding instead.
	 */
	public function test_recurring_to_one_time_is_not_a_removal() {
		$result = $this->evaluate(
			null,
			$this->cron(
				array( CronFixture::recurring( self::T, 'acme_sync', 'daily' ) ),
				array( CronFixture::single( self::T, 'acme_sync' ) )
			)
		);

		$this->assertSame( array( 'recurring_schedule_changed' ), array_column( $result['findings'], 'code' ) );
		$this->assertSame( 'no_longer_recurring', $result['findings'][0]['change'] );
	}

	/**
	 * Removal findings are sorted by hook, byte-wise.
	 */
	public function test_removal_findings_sorted_by_hook() {
		$found = $this->cron_findings(
			PotentialImpact::RECURRING_CRON_EVENT_REMOVED,
			array(
				CronFixture::recurring( self::T, 'b_hook', 'daily' ),
				CronFixture::recurring( self::T + 1, 'B_hook', 'daily' ),
				CronFixture::recurring( self::T + 2, 'a_hook', 'daily' ),
				CronFixture::recurring( self::T + 3, '10', 'daily' ),
				CronFixture::recurring( self::T + 4, '9', 'daily' ),
			),
			array()
		);

		$this->assertSame( array( '10', '9', 'B_hook', 'a_hook', 'b_hook' ), array_column( $found, 'hook' ) );
	}

	// ---------------------------------------------------------------------
	// Rule 2: changed recurring schedule.
	// ---------------------------------------------------------------------

	/**
	 * WP-Cron daily → hourly: the full finding.
	 */
	public function test_cron_interval_changed() {
		$this->assertSame(
			array(
				array(
					'code'   => 'recurring_schedule_changed',
					'signal' => 'cron',
					'hook'   => 'acme_sync',
					'change' => 'interval_changed',
					'before' => array(
						'timestamp'    => self::T,
						'schedule'     => 'daily',
						'interval'     => 86400,
						'is_recurring' => true,
					),
					'after'  => array(
						'timestamp'    => self::T + 600,
						'schedule'     => 'hourly',
						'interval'     => 3600,
						'is_recurring' => true,
					),
				),
			),
			$this->cron_findings(
				PotentialImpact::RECURRING_SCHEDULE_CHANGED,
				array( CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 'feed' => 1 ) ) ),
				array( CronFixture::recurring( self::T + 600, 'acme_sync', 'hourly', array( 'feed' => 1 ) ) )
			)
		);
	}

	/**
	 * WP-Cron changes that are findings, and those that are not.
	 *
	 * @dataProvider provide_cron_changes
	 *
	 * @param array       $before Event before.
	 * @param array       $after  Event after.
	 * @param string|null $change Expected change kind, null for no finding.
	 */
	public function test_cron_schedule_changes( array $before, array $after, $change ) {
		$found = $this->cron_findings( PotentialImpact::RECURRING_SCHEDULE_CHANGED, array( $before ), array( $after ) );

		$this->assertSame( null === $change ? array() : array( $change ), array_column( $found, 'change' ) );
	}

	/**
	 * WP-Cron cases (same hook and arguments on both sides).
	 *
	 * @return array
	 */
	public function provide_cron_changes() {
		$t = self::T;
		return array(
			'same schedule name, new interval'          => array( CronFixture::recurring( $t, 'acme', 'acme_often', array(), 300 ), CronFixture::recurring( $t, 'acme', 'acme_often', array(), 600 ), 'interval_changed' ),
			'recurring to one-time'                     => array( CronFixture::recurring( $t, 'acme', 'daily' ), CronFixture::single( $t, 'acme' ), 'no_longer_recurring' ),
			'recurring without interval to one-time'    => array( CronFixture::recurring( $t, 'acme', 'acme_custom' ), CronFixture::single( $t, 'acme' ), 'no_longer_recurring' ),
			'renamed schedule, same interval'           => array( CronFixture::recurring( $t, 'acme', 'daily' ), CronFixture::recurring( $t, 'acme', 'acme_daily', array(), 86400 ), null ),
			'renamed schedule, same interval and moved' => array( CronFixture::recurring( $t, 'acme', 'daily' ), CronFixture::recurring( $t + 5, 'acme', 'acme_daily', array(), 86400 ), null ),
			'interval unknown before'                   => array( CronFixture::recurring( $t, 'acme', 'acme_custom' ), CronFixture::recurring( $t, 'acme', 'hourly' ), null ),
			'interval unknown after'                    => array( CronFixture::recurring( $t, 'acme', 'hourly' ), CronFixture::recurring( $t, 'acme', 'acme_custom' ), null ),
			'interval unknown on both sides'            => array( CronFixture::recurring( $t, 'acme', 'acme_one' ), CronFixture::recurring( $t, 'acme', 'acme_two' ), null ),
			'one-time to recurring'                     => array( CronFixture::single( $t, 'acme' ), CronFixture::recurring( $t, 'acme', 'daily' ), null ),
			'rescheduled only'                          => array( CronFixture::recurring( $t, 'acme', 'daily' ), CronFixture::recurring( $t + 86400, 'acme', 'daily' ), null ),
		);
	}

	/**
	 * Action Scheduler interval change: the full finding, with the group.
	 */
	public function test_action_scheduler_interval_changed() {
		$this->assertSame(
			array(
				array(
					'code'   => 'recurring_schedule_changed',
					'signal' => 'action_scheduler',
					'hook'   => 'acme_import',
					'group'  => 'acme',
					'change' => 'interval_changed',
					'before' => array(
						'timestamp'       => self::T,
						'schedule_type'   => 'interval',
						'interval'        => 3600,
						'cron_expression' => null,
						'is_recurring'    => true,
					),
					'after'  => array(
						'timestamp'       => self::T + 60,
						'schedule_type'   => 'interval',
						'interval'        => 86400,
						'cron_expression' => null,
						'is_recurring'    => true,
					),
				),
			),
			$this->action_scheduler_findings(
				array( AS_Fixture::recurring( 'acme_import', self::T, 3600, array( 'feed' => 1 ), 'acme' ) ),
				array( AS_Fixture::recurring( 'acme_import', self::T + 60, 86400, array( 'feed' => 1 ), 'acme' ) )
			)
		);
	}

	/**
	 * Cron expressions are reported as stored, never turned into a frequency.
	 */
	public function test_action_scheduler_cron_expression_changed() {
		$found = $this->action_scheduler_findings(
			array( AS_Fixture::cron( 'acme_report', self::T, '0 */6 * * *' ) ),
			array( AS_Fixture::cron( 'acme_report', self::T, '0 0 * * *' ) )
		);

		$this->assertCount( 1, $found );
		$this->assertSame( 'schedule_changed', $found[0]['change'] );
		$this->assertSame( array( 'cron', null, '0 */6 * * *' ), array( $found[0]['before']['schedule_type'], $found[0]['before']['interval'], $found[0]['before']['cron_expression'] ) );
		$this->assertSame( array( 'cron', null, '0 0 * * *' ), array( $found[0]['after']['schedule_type'], $found[0]['after']['interval'], $found[0]['after']['cron_expression'] ) );
	}

	/**
	 * Action Scheduler changes that are findings, and those that are not.
	 *
	 * @dataProvider provide_action_scheduler_changes
	 *
	 * @param array       $before Row before.
	 * @param array       $after  Row after.
	 * @param string|null $change Expected change kind, null for no finding.
	 */
	public function test_action_scheduler_schedule_changes( array $before, array $after, $change ) {
		$found = $this->action_scheduler_findings( array( $before ), array( $after ) );

		$this->assertSame( null === $change ? array() : array( $change ), array_column( $found, 'change' ) );
	}

	/**
	 * Action Scheduler cases (same hook, group and arguments unless stated).
	 *
	 * @return array
	 */
	public function provide_action_scheduler_changes() {
		$t = self::T;
		return array(
			'interval to cron'         => array( AS_Fixture::recurring( 'acme', $t, 3600 ), AS_Fixture::cron( 'acme', $t, '0 * * * *' ), 'schedule_changed' ),
			'cron to interval'         => array( AS_Fixture::cron( 'acme', $t, '0 * * * *' ), AS_Fixture::recurring( 'acme', $t, 3600 ), 'schedule_changed' ),
			'interval to single'       => array( AS_Fixture::recurring( 'acme', $t, 3600 ), AS_Fixture::single( 'acme', $t ), 'no_longer_recurring' ),
			'cron to async'            => array( AS_Fixture::cron( 'acme', $t, '0 * * * *' ), AS_Fixture::async( 'acme', $t ), 'no_longer_recurring' ),
			'single to interval'       => array( AS_Fixture::single( 'acme', $t ), AS_Fixture::recurring( 'acme', $t, 3600 ), null ),
			'async to single'          => array( AS_Fixture::async( 'acme', $t ), AS_Fixture::single( 'acme', $t + 60 ), null ),
			'recurring run (next row)' => array( AS_Fixture::recurring( 'acme', $t, 3600 ), AS_Fixture::recurring( 'acme', $t + 3600, 3600 ), null ),
			'other arguments'          => array( AS_Fixture::recurring( 'acme', $t, 3600, array( 1 ) ), AS_Fixture::recurring( 'acme', $t, 600, array( 2 ) ), null ),
			'other group'              => array( AS_Fixture::recurring( 'acme', $t, 3600, array(), 'one' ), AS_Fixture::recurring( 'acme', $t, 600, array(), 'two' ), null ),
		);
	}

	/**
	 * A removed recurring action is not a finding: rule 1 covers WP-Cron only.
	 */
	public function test_removed_recurring_action_is_not_a_finding() {
		$result = $this->evaluate( null, null, $this->action_scheduler( array( AS_Fixture::recurring( 'acme', self::T, 3600 ) ), array() ) );

		$this->assertSame( array(), $result['findings'] );
	}

	// ---------------------------------------------------------------------
	// Ordering, determinism and privacy.
	// ---------------------------------------------------------------------

	/**
	 * Every rule at once: findings follow the check order, then name/hook order.
	 */
	public function test_order_across_rules() {
		$large  = self::bytes( 200000 );
		$result = $this->evaluate(
			$this->options(
				array(),
				array(
					'b_cache' => array( $large, 'on' ),
					'a_cache' => array( $large, 'on' ),
				)
			),
			$this->cron(
				array(
					CronFixture::recurring( self::T, 'z_sync', 'daily' ),
					CronFixture::recurring( self::T, 'b_change', 'daily' ),
					CronFixture::recurring( self::T, 'a_change', 'daily' ),
					CronFixture::recurring( self::T, 'y_gone', 'hourly' ),
				),
				array(
					CronFixture::recurring( self::T, 'b_change', 'hourly' ),
					CronFixture::recurring( self::T, 'a_change', 'weekly' ),
				)
			),
			$this->action_scheduler(
				array(
					AS_Fixture::recurring( 'b_job', self::T, 60 ),
					AS_Fixture::recurring( 'a_job', self::T, 60, array(), 'z' ),
					AS_Fixture::recurring( 'a_job', self::T, 60, array(), 'a' ),
				),
				array(
					AS_Fixture::recurring( 'b_job', self::T, 120 ),
					AS_Fixture::recurring( 'a_job', self::T, 120, array(), 'z' ),
					AS_Fixture::recurring( 'a_job', self::T, 120, array(), 'a' ),
				)
			)
		);

		$this->assertSame(
			array(
				'large_autoloaded_option:options:a_cache',
				'large_autoloaded_option:options:b_cache',
				'recurring_cron_event_removed:cron:y_gone',
				'recurring_cron_event_removed:cron:z_sync',
				'recurring_schedule_changed:cron:a_change',
				'recurring_schedule_changed:cron:b_change',
				'recurring_schedule_changed:action_scheduler:a_job/a',
				'recurring_schedule_changed:action_scheduler:a_job/z',
				'recurring_schedule_changed:action_scheduler:b_job/',
			),
			array_map(
				static function ( array $finding ) {
					$subject = isset( $finding['name'] ) ? $finding['name'] : $finding['hook'];
					if ( isset( $finding['group'] ) ) {
						$subject .= '/' . $finding['group'];
					}
					return $finding['code'] . ':' . $finding['signal'] . ':' . $subject;
				},
				$result['findings']
			)
		);
	}

	/**
	 * The result depends only on the observed state, not on input order or repetition.
	 */
	public function test_deterministic() {
		$large  = self::bytes( 200000 );
		$opts_a = array(
			'a' => array( $large, 'on' ),
			'b' => array( 'x', 'on' ),
		);
		$opts_b = array(
			'b' => array( $large, 'on' ),
			'c' => array( $large, 'on' ),
			'a' => array( $large, 'on' ),
		);
		$cron_a = array(
			CronFixture::recurring( self::T, 'acme', 'daily', array( 1 ) ),
			CronFixture::recurring( self::T, 'acme', 'daily', array( 2 ) ),
			CronFixture::recurring( self::T, 'beta', 'daily' ),
		);
		$cron_b = array( CronFixture::recurring( self::T, 'beta', 'hourly' ) );
		$as_a   = array(
			AS_Fixture::recurring( 'acme', self::T, 60, array( 1 ) ),
			AS_Fixture::recurring( 'acme', self::T, 60, array( 2 ) ),
		);
		$as_b   = array(
			AS_Fixture::recurring( 'acme', self::T, 120, array( 2 ) ),
			AS_Fixture::cron( 'acme', self::T, '0 * * * *', array( 1 ) ),
		);

		$first  = $this->evaluate( $this->options( $opts_a, $opts_b ), $this->cron( $cron_a, $cron_b ), $this->action_scheduler( $as_a, $as_b ) );
		$second = $this->evaluate(
			$this->options( array_reverse( $opts_a, true ), array_reverse( $opts_b, true ) ),
			$this->cron( array_reverse( $cron_a ), array_reverse( $cron_b ) ),
			$this->action_scheduler( array_reverse( $as_a ), array_reverse( $as_b ) )
		);

		$this->assertSame( $first, $second );
		$this->assertSame( $first, $this->evaluate( $this->options( $opts_a, $opts_b ), $this->cron( $cron_a, $cron_b ), $this->action_scheduler( $as_a, $as_b ) ) );
		$this->assertCount( 6, $first['findings'] );
	}

	/**
	 * Option values, arguments and fingerprints never reach the result.
	 */
	public function test_privacy() {
		$secret_value = str_pad( self::FAKE_SECRET, 200000, '#' );
		$result       = $this->evaluate(
			$this->options(
				array(
					'acme_grows' => array( self::FAKE_SECRET, 'on' ),
					'acme_turns' => array( $secret_value, 'off' ),
				),
				array(
					'acme_grows'  => array( $secret_value, 'on' ),
					'acme_turns'  => array( $secret_value, 'on' ),
					'acme_secret' => array( $secret_value, 'on' ),
				)
			),
			$this->cron(
				array(
					CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 'token' => self::FAKE_SECRET ) ),
					CronFixture::recurring( self::T, 'acme_gone', 'daily', array( self::FAKE_SECRET ) ),
				),
				array( CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 'token' => self::FAKE_SECRET ) ) )
			),
			$this->action_scheduler(
				array( AS_Fixture::recurring( 'acme_job', self::T, 60, array( 'key' => self::FAKE_SECRET ), 'acme' ) ),
				array( AS_Fixture::recurring( 'acme_job', self::T, 120, array( 'key' => self::FAKE_SECRET ), 'acme' ) )
			)
		);

		$this->assertCount( 6, $result['findings'] );

		$json = (string) json_encode( $result ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
		$this->assertStringNotContainsString( self::FAKE_SECRET, $json );
		$this->assertStringNotContainsString( 'UPDATE_LENS', $json );
		$this->assertStringNotContainsString( '###', $json );
		$this->assertStringNotContainsString( 'fingerprint', $json );
		$this->assertStringNotContainsString( 'args', $json );
		$this->assertStringNotContainsString( 'value', $json );
		$this->assertSame( 0, preg_match( '/[0-9a-f]{32}/', $json ), 'No hashes or md5 event keys.' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Checks the serialized form too.
		$this->assertStringNotContainsString( self::FAKE_SECRET, serialize( $result ) );
	}

	/**
	 * Finding keys are fixed per rule and signal (the evidence contract for later UI work).
	 */
	public function test_finding_keys() {
		$large  = self::bytes( 200000 );
		$result = $this->evaluate(
			$this->options( array(), array( 'acme' => array( $large, 'on' ) ) ),
			$this->cron(
				array(
					CronFixture::recurring( self::T, 'acme_gone', 'daily' ),
					CronFixture::recurring( self::T, 'acme_sync', 'daily' ),
				),
				array( CronFixture::recurring( self::T, 'acme_sync', 'hourly' ) )
			),
			$this->action_scheduler( array( AS_Fixture::recurring( 'acme', self::T, 60 ) ), array( AS_Fixture::recurring( 'acme', self::T, 120 ) ) )
		);

		$this->assertSame(
			array(
				array( 'code', 'signal', 'name', 'transition', 'threshold_bytes', 'before_size', 'after_size', 'before_autoload', 'after_autoload', 'before_is_autoloaded', 'after_is_autoloaded' ),
				array( 'code', 'signal', 'hook', 'removed', 'removed_count', 'added_recurring_count', 'still_observed_count' ),
				array( 'code', 'signal', 'hook', 'change', 'before', 'after' ),
				array( 'code', 'signal', 'hook', 'group', 'change', 'before', 'after' ),
			),
			array_map( 'array_keys', $result['findings'] )
		);
	}
}
