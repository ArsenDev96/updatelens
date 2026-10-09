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
 * Report Net result phase → Potential Impact evaluation.
 *
 * Every fixture goes through the real snapshot builder, diff builder and
 * codec (encode + decode), and is wrapped like AnalysisReadModel wraps an
 * available phase, so the evaluator sees exactly what reports hold.
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
	 * Evaluate decoded diffs; null = unavailable (`settle_expired`), a string = that reason.
	 *
	 * @param array|string|null $options          Options diff.
	 * @param array|string|null $cron             WP-Cron diff.
	 * @param array|string|null $action_scheduler Action Scheduler diff.
	 * @return array
	 */
	private function evaluate( $options, $cron = null, $action_scheduler = null ) {
		return ( new PotentialImpact() )->evaluate(
			array(
				'final' => array(
					'options'          => self::signal( null === $options ? 'settle_expired' : $options ),
					'cron'             => self::signal( null === $cron ? 'settle_expired' : $cron ),
					'action_scheduler' => self::signal( null === $action_scheduler ? 'settle_expired' : $action_scheduler ),
				),
			)
		);
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
	 * The change kind of each finding.
	 *
	 * @param array<int, array> $findings Findings.
	 * @return string[]
	 */
	private static function changes( array $findings ) {
		return array_map(
			static function ( array $finding ) {
				return $finding['evidence']['change'];
			},
			$findings
		);
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
	// Evaluation status and availability.
	// ---------------------------------------------------------------------

	/**
	 * Without any Net result nothing is evaluated: every signal says why, and there
	 * is no finding count (not "0 findings").
	 */
	public function test_nothing_available_is_not_evaluated() {
		$this->assertSame(
			array(
				'phase'    => 'final',
				'status'   => 'not_evaluated',
				'signals'  => array(
					'options'          => array(
						'status'        => 'not_evaluated',
						'reason'        => 'settle_expired',
						'rules'         => array( 'large_autoloaded_option' ),
						'finding_count' => null,
					),
					'cron'             => array(
						'status'        => 'not_evaluated',
						'reason'        => 'settle_expired',
						'rules'         => array( 'recurring_cron_event_removed', 'recurring_schedule_changed' ),
						'finding_count' => null,
					),
					'action_scheduler' => array(
						'status'        => 'not_evaluated',
						'reason'        => 'settle_expired',
						'rules'         => array( 'recurring_schedule_changed' ),
						'finding_count' => null,
					),
				),
				'findings' => array(),
			),
			$this->evaluate( null, null, null )
		);
	}

	/**
	 * Empty diffs: every signal evaluated, zero findings — distinct from not evaluated.
	 */
	public function test_everything_evaluated_without_findings() {
		$result = $this->evaluate( $this->options( array(), array() ), $this->cron( array(), array() ), $this->action_scheduler( array(), array() ) );

		$this->assertSame( 'evaluated', $result['status'] );
		foreach ( $result['signals'] as $signal ) {
			$this->assertSame( array( 'evaluated', null, 0 ), array( $signal['status'], $signal['reason'], $signal['finding_count'] ) );
		}
		$this->assertSame( array(), $result['findings'] );
	}

	/**
	 * Each signal's availability is independent; the overall status summarizes them.
	 *
	 * @dataProvider provide_availability
	 *
	 * @param bool   $options          Options available.
	 * @param bool   $cron             WP-Cron available.
	 * @param bool   $action_scheduler Action Scheduler available.
	 * @param string $status           Expected overall status.
	 */
	public function test_signal_availability_is_independent( $options, $cron, $action_scheduler, $status ) {
		$large  = array( 'acme_cache' => array( self::bytes( self::LIMIT + 1 ), 'on' ) );
		$result = $this->evaluate(
			$options ? $this->options( array(), $large ) : null,
			$cron ? $this->cron( array( CronFixture::recurring( self::T, 'acme_sync', 'daily' ) ), array( CronFixture::recurring( self::T, 'acme_sync', 'hourly' ) ) ) : null,
			$action_scheduler ? $this->action_scheduler( array( AS_Fixture::recurring( 'acme_job', self::T, 3600 ) ), array( AS_Fixture::recurring( 'acme_job', self::T, 600 ) ) ) : null
		);

		$this->assertSame( $status, $result['status'] );
		$expected = array();
		foreach ( array(
			'options'          => $options,
			'cron'             => $cron,
			'action_scheduler' => $action_scheduler,
		) as $signal => $available ) {
			$this->assertSame( $available ? 'evaluated' : 'not_evaluated', $result['signals'][ $signal ]['status'] );
			$this->assertSame( $available ? 1 : null, $result['signals'][ $signal ]['finding_count'] );
			if ( $available ) {
				$expected[] = $signal;
			}
		}
		$this->assertSame( $expected, array_column( $result['findings'], 'signal' ) );
	}

	/**
	 * Availability combinations.
	 *
	 * @return array
	 */
	public function provide_availability() {
		return array(
			'only options'          => array( true, false, false, 'partial' ),
			'only cron'             => array( false, true, false, 'partial' ),
			'only action scheduler' => array( false, false, true, 'partial' ),
			'options missing'       => array( false, true, true, 'partial' ),
			'cron missing'          => array( true, false, true, 'partial' ),
			'all'                   => array( true, true, true, 'evaluated' ),
			'none'                  => array( false, false, false, 'not_evaluated' ),
		);
	}

	/**
	 * The phase's own reason explains a signal that was not evaluated.
	 */
	public function test_reasons_come_from_the_phase() {
		$result = $this->evaluate( 'data_corrupt', 'malformed_cron_state', 'not_installed' );

		$this->assertSame(
			array(
				'options'          => 'data_corrupt',
				'cron'             => 'malformed_cron_state',
				'action_scheduler' => 'not_installed',
			),
			array_map(
				static function ( array $signal ) {
					return $signal['reason'];
				},
				$result['signals']
			)
		);
		$this->assertSame( 'not_evaluated', $result['status'] );
	}

	/**
	 * A missing signal object or reason is `not_recorded`, never evaluated.
	 */
	public function test_missing_signal_is_not_recorded() {
		foreach ( array(
			array(
				'final' => array(
					'options' => array( 'available' => false ),
					'cron'    => 'not an object',
				),
			),
			array(),
			array( 'final' => 'not an object' ),
		) as $phases ) {
			$result = ( new PotentialImpact() )->evaluate( $phases );

			$this->assertSame( 'not_evaluated', $result['status'] );
			foreach ( $result['signals'] as $signal ) {
				$this->assertSame( array( 'not_evaluated', 'not_recorded' ), array( $signal['status'], $signal['reason'] ) );
			}
		}
	}

	/**
	 * Action Scheduler is `not_applicable` only when the caller knows it was
	 * absent at every capture; then the overall status ignores it. Reasons in
	 * the phases alone (e.g. `not_installed` everywhere, as older analyses
	 * stored it) never are enough.
	 *
	 * @dataProvider provide_action_scheduler_applicability
	 *
	 * @param string|null $reason  Action Scheduler Net result reason, null = available.
	 * @param bool        $absent  Whether Action Scheduler is known to be absent at every capture.
	 * @param string      $status  Expected Action Scheduler status.
	 * @param string      $overall Expected overall status (Options and WP-Cron evaluated).
	 */
	public function test_action_scheduler_applicability( $reason, $absent, $status, $overall ) {
		$phases = array();
		foreach ( array( 'during_update', 'post_update', 'final' ) as $phase ) {
			$phases[ $phase ] = array(
				'options'          => self::signal( $this->options( array(), array() ) ),
				'cron'             => self::signal( $this->cron( array(), array() ) ),
				'action_scheduler' => self::signal( null === $reason ? $this->action_scheduler( array(), array() ) : $reason ),
			);
		}

		$result = ( new PotentialImpact() )->evaluate( $phases, $absent );

		$this->assertSame( $status, $result['signals']['action_scheduler']['status'] );
		$this->assertSame( $overall, $result['status'] );
		if ( 'evaluated' !== $status ) {
			$this->assertSame( array( $reason, null ), array( $result['signals']['action_scheduler']['reason'], $result['signals']['action_scheduler']['finding_count'] ) );
		}
	}

	/**
	 * Net result reason, known absence, expected statuses.
	 *
	 * @return array
	 */
	public function provide_action_scheduler_applicability() {
		return array(
			'absent at every capture'          => array( 'not_installed', true, 'not_applicable', 'evaluated' ),
			'not_installed, absence not known' => array( 'not_installed', false, 'not_evaluated', 'partial' ),
			'available'                        => array( null, false, 'evaluated', 'evaluated' ),
			'available wins over a stale flag' => array( null, true, 'evaluated', 'evaluated' ),
			'newly detected'                   => array( 'newly_detected', false, 'not_evaluated', 'partial' ),
			'no longer detected'               => array( 'no_longer_detected', false, 'not_evaluated', 'partial' ),
			'expired'                          => array( 'settle_expired', false, 'not_evaluated', 'partial' ),
			'update failed'                    => array( 'update_failed', false, 'not_evaluated', 'partial' ),
			'unreadable'                       => array( 'snapshot_unavailable', false, 'not_evaluated', 'partial' ),
			'corrupt net result'               => array( 'data_corrupt', false, 'not_evaluated', 'partial' ),
			'predates Action Scheduler'        => array( 'not_captured', false, 'not_evaluated', 'partial' ),
			'default: absence not known'       => array( 'not_installed', null, 'not_evaluated', 'partial' ),
		);
	}

	/**
	 * The default is "absence not known": `not_installed` everywhere without the flag is not evaluated.
	 */
	public function test_absence_is_not_assumed() {
		$phases = array();
		foreach ( array( 'during_update', 'post_update', 'final' ) as $phase ) {
			$phases[ $phase ] = array(
				'options'          => self::signal( $this->options( array(), array() ) ),
				'cron'             => self::signal( $this->cron( array(), array() ) ),
				'action_scheduler' => self::signal( 'not_installed' ),
			);
		}

		$result = ( new PotentialImpact() )->evaluate( $phases );

		$this->assertSame( array( 'partial', 'not_evaluated' ), array( $result['status'], $result['signals']['action_scheduler']['status'] ) );
	}

	/**
	 * Only Action Scheduler can be `not_applicable`: Options or WP-Cron without a Net result
	 * are always `not_evaluated`, and with nothing evaluated the overall status is `not_evaluated`.
	 */
	public function test_not_applicable_never_counts_as_evaluated() {
		$phases = array();
		foreach ( array( 'during_update', 'post_update', 'final' ) as $phase ) {
			$phases[ $phase ] = array(
				'options'          => self::signal( 'settle_expired' ),
				'cron'             => self::signal( 'not_installed' ),
				'action_scheduler' => self::signal( 'not_installed' ),
			);
		}

		$result = ( new PotentialImpact() )->evaluate( $phases, true );

		$this->assertSame( 'not_evaluated', $result['status'] );
		$this->assertSame( array( 'not_evaluated', 'not_evaluated', 'not_applicable' ), array_column( $result['signals'], 'status' ) );
	}

	// ---------------------------------------------------------------------
	// Large autoloaded option.
	// ---------------------------------------------------------------------

	/**
	 * An added autoloaded option above the threshold: the full finding.
	 */
	public function test_added_large_autoloaded_option() {
		$this->assertSame(
			array(
				array(
					'code'     => 'large_autoloaded_option',
					'signal'   => 'options',
					'option'   => 'acme_cache',
					'evidence' => array(
						'transition'      => 'added',
						'threshold_bytes' => 150000,
						'size_delta'      => null,
						'value_changed'   => null,
					),
					'before'   => null,
					'after'    => array(
						'size'          => 180000,
						'autoload'      => 'on',
						'is_autoloaded' => true,
					),
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
			$this->assertSame(
				array(
					'transition'      => 'grew_past_threshold',
					'threshold_bytes' => 150000,
					'size_delta'      => $after - $before,
					'value_changed'   => true,
				),
				$findings[0]['evidence']
			);
			$this->assertSame( array( $before, $after ), array( $findings[0]['before']['size'], $findings[0]['after']['size'] ) );
			$this->assertTrue( $findings[0]['before']['is_autoloaded'] );
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

		$this->assertSame(
			array(
				array(
					'code'     => 'large_autoloaded_option',
					'signal'   => 'options',
					'option'   => 'acme_cache',
					'evidence' => array(
						'transition'      => 'became_autoloaded',
						'threshold_bytes' => 150000,
						'size_delta'      => 0,
						'value_changed'   => false,
					),
					'before'   => array(
						'size'          => 200000,
						'autoload'      => 'off',
						'is_autoloaded' => false,
					),
					'after'    => array(
						'size'          => 200000,
						'autoload'      => 'on',
						'is_autoloaded' => true,
					),
				),
			),
			$this->option_findings( array( 'acme_cache' => array( $value, 'off' ) ), array( 'acme_cache' => array( $value, 'on' ) ) )
		);
	}

	/**
	 * A small non-autoloaded option that becomes autoloaded and large at once is "became autoloaded".
	 */
	public function test_small_option_becoming_autoloaded_and_large() {
		$found = $this->option_findings( array( 'acme_cache' => array( 'x', 'auto-off' ) ), array( 'acme_cache' => array( self::bytes( 200000 ), 'auto-on' ) ) );

		$this->assertCount( 1, $found );
		$this->assertSame( 'became_autoloaded', $found[0]['evidence']['transition'] );
		$this->assertSame( array( 1, 200000, 199999 ), array( $found[0]['before']['size'], $found[0]['after']['size'], $found[0]['evidence']['size_delta'] ) );
	}

	/**
	 * Effective autoload behavior decides, not the raw string.
	 */
	public function test_effective_autoload_decides() {
		$large = self::bytes( 200000 );

		// Raw `auto` is effectively autoloaded: a finding.
		$found = $this->option_findings( array(), array( 'acme_auto' => array( $large, 'auto' ) ) );
		$this->assertCount( 1, $found );
		$this->assertSame( array( 'auto', true ), array( $found[0]['after']['autoload'], $found[0]['after']['is_autoloaded'] ) );

		// Raw change between two autoloaded values while crossing the threshold: growth, not "became autoloaded".
		$found = $this->option_findings( array( 'acme_cache' => array( 'x', 'yes' ) ), array( 'acme_cache' => array( $large, 'auto-on' ) ) );
		$this->assertCount( 1, $found );
		$this->assertSame( array( 'grew_past_threshold', 'yes', 'auto-on' ), array( $found[0]['evidence']['transition'], $found[0]['before']['autoload'], $found[0]['after']['autoload'] ) );

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

		$this->assertSame( array( '123', 'Z_turned', 'a_added', 'b_grows', 'c_added' ), array_column( $found, 'option' ) );
		$this->assertSame(
			array( 'added', 'became_autoloaded', 'added', 'grew_past_threshold', 'added' ),
			array_map(
				static function ( array $finding ) {
					return $finding['evidence']['transition'];
				},
				$found
			)
		);
	}

	// ---------------------------------------------------------------------
	// Recurring WP-Cron event instance no longer observed.
	// ---------------------------------------------------------------------

	/**
	 * A removed recurring event: the full finding with its prior schedule.
	 */
	public function test_removed_recurring_event() {
		$this->assertSame(
			array(
				array(
					'code'     => 'recurring_cron_event_removed',
					'signal'   => 'cron',
					'hook'     => 'acme_daily_sync',
					'evidence' => array(
						'removed_recurring_count' => 1,
						'added_recurring_count'   => 0,
						'not_replaced_count'      => 1,
						'other_recorded_changes'  => array(
							'added_one_time' => 0,
							'rescheduled'    => 0,
							'changed'        => 0,
						),
					),
					'before'   => array(
						'removed_recurring' => array(
							array(
								'timestamp' => self::T + 60,
								'schedule'  => 'daily',
								'interval'  => 86400,
							),
						),
					),
					'after'    => array( 'added_recurring' => array() ),
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
		$this->assertSame( array( 'evaluated', 0 ), array( $result['signals']['cron']['status'], $result['signals']['cron']['finding_count'] ) );
	}

	/**
	 * Ordinary rescheduling of recurring events (a normal cron run) is never a finding.
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
	 * Replaced by fewer recurring instances: a finding with the replacement as evidence.
	 */
	public function test_partly_replaced_recurring_events() {
		$found = $this->cron_findings(
			PotentialImpact::RECURRING_CRON_EVENT_REMOVED,
			array(
				CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 'site' => 1 ) ),
				CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 'site' => 2 ) ),
			),
			array(
				CronFixture::recurring( self::T + 7, 'acme_sync', 'hourly', array( 'site' => 3 ) ),
			)
		);

		$this->assertCount( 1, $found );
		$this->assertSame(
			array(
				'removed_recurring_count' => 2,
				'added_recurring_count'   => 1,
				'not_replaced_count'      => 1,
				'other_recorded_changes'  => array(
					'added_one_time' => 0,
					'rescheduled'    => 0,
					'changed'        => 0,
				),
			),
			$found[0]['evidence']
		);
		$this->assertCount( 2, $found[0]['before']['removed_recurring'] );
		$this->assertSame(
			array(
				array(
					'timestamp' => self::T + 7,
					'schedule'  => 'hourly',
					'interval'  => 3600,
				),
			),
			$found[0]['after']['added_recurring']
		);
	}

	/**
	 * A recurring event replaced only by a one-time event of the same hook is still a
	 * finding: no recurring instance replaces it. The one-time event is counted.
	 */
	public function test_recurring_event_replaced_by_one_time_event() {
		$found = $this->cron_findings(
			PotentialImpact::RECURRING_CRON_EVENT_REMOVED,
			array( CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 'v' => 1 ) ) ),
			array( CronFixture::single( self::T + 10, 'acme_sync', array( 'v' => 2 ) ) )
		);

		$this->assertCount( 1, $found );
		$this->assertSame( array( 1, 0, 1 ), array( $found[0]['evidence']['removed_recurring_count'], $found[0]['evidence']['added_recurring_count'], $found[0]['evidence']['not_replaced_count'] ) );
		$this->assertSame( 1, $found[0]['evidence']['other_recorded_changes']['added_one_time'] );
		$this->assertSame( array(), $found[0]['after']['added_recurring'] );
	}

	/**
	 * Unchanged instances of a hook never appear in a diff. With one instance removed
	 * and the others unchanged, the evidence shows zero other recorded changes — and
	 * says nothing about whether instances remain (no "remaining" or "still observed" field).
	 */
	public function test_unchanged_instances_are_not_claimed_either_way() {
		$before = array(
			CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 'site' => 1 ) ),
			CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 'site' => 2 ) ),
			CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 'site' => 3 ) ),
			CronFixture::recurring( self::T + 30, 'acme_sync', 'daily', array( 'site' => 4 ) ),
		);

		$found = $this->cron_findings( PotentialImpact::RECURRING_CRON_EVENT_REMOVED, $before, array_slice( $before, 0, 3 ) );
		$this->assertCount( 1, $found );
		$this->assertSame(
			array(
				'added_one_time' => 0,
				'rescheduled'    => 0,
				'changed'        => 0,
			),
			$found[0]['evidence']['other_recorded_changes']
		);
		$this->assertSame(
			array(
				array(
					'timestamp' => self::T + 30,
					'schedule'  => 'daily',
					'interval'  => 86400,
				),
			),
			$found[0]['before']['removed_recurring']
		);

		$json = (string) json_encode( $found ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
		foreach ( array( 'still', 'remaining', 'disappeared', 'missing', 'gone', 'total' ) as $claim ) {
			$this->assertStringNotContainsString( $claim, $json );
		}
	}

	/**
	 * Other recorded changes of the same hook are counted per list (rescheduled, changed).
	 */
	public function test_other_recorded_changes_of_the_hook() {
		$found = $this->cron_findings(
			PotentialImpact::RECURRING_CRON_EVENT_REMOVED,
			array(
				CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 'site' => 1 ) ),
				CronFixture::recurring( self::T, 'acme_sync', 'hourly', array( 'site' => 2 ) ),
				CronFixture::recurring( self::T, 'acme_sync', 'daily', array( 'site' => 3 ) ),
				CronFixture::recurring( self::T, 'other_hook', 'hourly' ),
			),
			array(
				CronFixture::recurring( self::T + 3600, 'acme_sync', 'hourly', array( 'site' => 1 ) ), // Rescheduled.
				CronFixture::recurring( self::T, 'acme_sync', 'weekly', array( 'site' => 3 ) ),        // Changed.
				CronFixture::recurring( self::T + 3600, 'other_hook', 'hourly' ),                       // Other hook.
			)
		);

		$this->assertCount( 1, $found );
		$this->assertSame(
			array(
				'removed_recurring_count' => 1,
				'added_recurring_count'   => 0,
				'not_replaced_count'      => 1,
				'other_recorded_changes'  => array(
					'added_one_time' => 0,
					'rescheduled'    => 1,
					'changed'        => 1,
				),
			),
			$found[0]['evidence']
		);
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
			$found[0]['before']['removed_recurring'][0]
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
		$this->assertSame( array( 'no_longer_recurring' ), self::changes( $result['findings'] ) );
	}

	/**
	 * Removal findings are sorted by hook, byte-wise; numeric hooks stay strings.
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
		$this->assertSame( '10', $found[0]['hook'] );
	}

	// ---------------------------------------------------------------------
	// Recurring schedule configuration changed.
	// ---------------------------------------------------------------------

	/**
	 * WP-Cron daily → hourly: the full finding.
	 */
	public function test_cron_interval_changed() {
		$this->assertSame(
			array(
				array(
					'code'     => 'recurring_schedule_changed',
					'signal'   => 'cron',
					'hook'     => 'acme_sync',
					'evidence' => array( 'change' => 'interval_changed' ),
					'before'   => array(
						'timestamp'    => self::T,
						'schedule'     => 'daily',
						'interval'     => 86400,
						'is_recurring' => true,
					),
					'after'    => array(
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

		$this->assertSame( null === $change ? array() : array( $change ), self::changes( $found ) );
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
					'code'     => 'recurring_schedule_changed',
					'signal'   => 'action_scheduler',
					'hook'     => 'acme_import',
					'group'    => 'acme',
					'evidence' => array( 'change' => 'interval_changed' ),
					'before'   => array(
						'timestamp'       => self::T,
						'schedule_type'   => 'interval',
						'interval'        => 3600,
						'cron_expression' => null,
						'is_recurring'    => true,
					),
					'after'    => array(
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

		$this->assertSame( array( 'cron_expression_changed' ), self::changes( $found ) );
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

		$this->assertSame( null === $change ? array() : array( $change ), self::changes( $found ) );
	}

	/**
	 * Action Scheduler cases (same hook, group and arguments unless stated).
	 *
	 * @return array
	 */
	public function provide_action_scheduler_changes() {
		$t = self::T;
		return array(
			'interval to cron'                   => array( AS_Fixture::recurring( 'acme', $t, 3600 ), AS_Fixture::cron( 'acme', $t, '0 * * * *' ), 'schedule_type_changed' ),
			'cron to interval'                   => array( AS_Fixture::cron( 'acme', $t, '0 * * * *' ), AS_Fixture::recurring( 'acme', $t, 3600 ), 'schedule_type_changed' ),
			'interval to single'                 => array( AS_Fixture::recurring( 'acme', $t, 3600 ), AS_Fixture::single( 'acme', $t ), 'no_longer_recurring' ),
			'cron to async'                      => array( AS_Fixture::cron( 'acme', $t, '0 * * * *' ), AS_Fixture::async( 'acme', $t ), 'no_longer_recurring' ),
			'other cron expression'              => array( AS_Fixture::cron( 'acme', $t, '0 9 * * MON' ), AS_Fixture::cron( 'acme', $t, '0 9 * * TUE' ), 'cron_expression_changed' ),
			'year field restricted'              => array( AS_Fixture::cron( 'acme', $t, '0 0 * * *' ), AS_Fixture::cron( 'acme', $t, '0 0 * * * 2027' ), 'cron_expression_changed' ),
			'other step'                         => array( AS_Fixture::cron( 'acme', $t, '*/5 * * * *' ), AS_Fixture::cron( 'acme', $t, '*/15 * * * *' ), 'cron_expression_changed' ),
			'equivalent: trailing year *'        => array( AS_Fixture::cron( 'acme', $t, '0 0 * * *' ), AS_Fixture::cron( 'acme', $t + 60, '0 0 * * * *' ), null ),
			'equivalent: day name and number'    => array( AS_Fixture::cron( 'acme', $t, '0 9 * * MON-FRI' ), AS_Fixture::cron( 'acme', $t, '0 9 * * 1-5' ), null ),
			'equivalent: month name, lower case' => array( AS_Fixture::cron( 'acme', $t, '0 0 1 jan,Jul *' ), AS_Fixture::cron( 'acme', $t, '0 0 1 1,7 *' ), null ),
			'equivalent: ? and *'                => array( AS_Fixture::cron( 'acme', $t, '0 0 ? * *' ), AS_Fixture::cron( 'acme', $t, '0 0 * * ?' ), null ),
			'equivalent: step of 1'              => array( AS_Fixture::cron( 'acme', $t, '*/1 * * * *' ), AS_Fixture::cron( 'acme', $t, '* * * * *' ), null ),
			'equivalent: leading zeros'          => array( AS_Fixture::cron( 'acme', $t, '05 00 * * *' ), AS_Fixture::cron( 'acme', $t, '5 0 * * *' ), null ),
			'single to interval'                 => array( AS_Fixture::single( 'acme', $t ), AS_Fixture::recurring( 'acme', $t, 3600 ), null ),
			'async to single'                    => array( AS_Fixture::async( 'acme', $t ), AS_Fixture::single( 'acme', $t + 60 ), null ),
			'recurring run (next row)'           => array( AS_Fixture::recurring( 'acme', $t, 3600 ), AS_Fixture::recurring( 'acme', $t + 3600, 3600 ), null ),
			'cron run (next row)'                => array( AS_Fixture::cron( 'acme', $t, '0 * * * *' ), AS_Fixture::cron( 'acme', $t + 3600, '0 * * * *' ), null ),
			'other arguments'                    => array( AS_Fixture::recurring( 'acme', $t, 3600, array( 1 ) ), AS_Fixture::recurring( 'acme', $t, 600, array( 2 ) ), null ),
			'other group'                        => array( AS_Fixture::recurring( 'acme', $t, 3600, array(), 'one' ), AS_Fixture::recurring( 'acme', $t, 600, array(), 'two' ), null ),
			'equivalent cron, other arguments'   => array( AS_Fixture::cron( 'acme', $t, '0 0 * * *', array( 1 ) ), AS_Fixture::cron( 'acme', $t, '0 0 * * MON', array( 2 ) ), null ),
		);
	}

	/**
	 * Ordinary execution is never a finding: an action that starts running, a recurring
	 * action whose next run was queued while the current one runs, and actions that left
	 * the queue (completed, failed or canceled) and show as removed.
	 */
	public function test_action_scheduler_execution_is_not_a_finding() {
		$running = static function ( array $row ) {
			$row['status'] = 'in-progress';
			return $row;
		};

		$result = $this->evaluate(
			null,
			null,
			$this->action_scheduler(
				array(
					AS_Fixture::recurring( 'acme_sync', self::T, 3600, array(), 'acme' ),
					AS_Fixture::cron( 'acme_report', self::T, '0 * * * *', array(), 'acme' ),
					AS_Fixture::recurring( 'acme_cleanup', self::T, 86400 ),
					AS_Fixture::single( 'acme_once', self::T ),
				),
				array(
					$running( AS_Fixture::recurring( 'acme_sync', self::T, 3600, array(), 'acme' ) ),
					AS_Fixture::recurring( 'acme_sync', self::T + 3600, 3600, array(), 'acme' ),
					AS_Fixture::cron( 'acme_report', self::T + 3600, '0 * * * *', array(), 'acme' ),
				)
			)
		);

		$this->assertSame( array(), $result['findings'] );
		$this->assertSame( array( 'evaluated', 0 ), array( $result['signals']['action_scheduler']['status'], $result['signals']['action_scheduler']['finding_count'] ) );
	}

	/**
	 * A removed recurring action is not a finding: the removal rule covers WP-Cron only.
	 */
	public function test_removed_recurring_action_is_not_a_finding() {
		$result = $this->evaluate( null, null, $this->action_scheduler( array( AS_Fixture::recurring( 'acme', self::T, 3600 ) ), array() ) );

		$this->assertSame( array(), $result['findings'] );
	}

	/**
	 * Equivalent cron forms, directly.
	 *
	 * @dataProvider provide_cron_expressions
	 *
	 * @param string $expression Expression.
	 * @param string $expected   Equivalent form.
	 */
	public function test_equivalent_cron_expression( $expression, $expected ) {
		$this->assertSame( $expected, PotentialImpact::equivalent_cron_expression( $expression ) );
	}

	/**
	 * Expressions.
	 *
	 * @return array
	 */
	public function provide_cron_expressions() {
		return array(
			'plain'                     => array( '0 */6 * * *', '0 */6 * * *' ),
			'year *'                    => array( '0 0 * * * *', '0 0 * * *' ),
			'year kept'                 => array( '0 0 * * * 2027', '0 0 * * * 2027' ),
			'names'                     => array( '0 9 * jan-mar mon,wed,FRI', '0 9 * 1-3 1,3,5' ),
			'names only in their field' => array( '0 0 * * MAR', '0 0 * * MAR' ),
			'question marks'            => array( '0 0 ? * ?', '0 0 * * *' ),
			'step of one'               => array( '*/1 */1 * * *', '* * * * *' ),
			'leading zeros'             => array( '00 05 01 01 0', '0 5 1 1 0' ),
			'zero stays zero'           => array( '0 10 20 * 0', '0 10 20 * 0' ),
			'last and nearest weekday'  => array( '0 0 LW * 5L', '0 0 LW * 5L' ),
			'nth weekday name'          => array( '0 0 * * fri#2', '0 0 * * 5#2' ),
		);
	}

	// ---------------------------------------------------------------------
	// Ordering, determinism, privacy and scale.
	// ---------------------------------------------------------------------

	/**
	 * Every rule at once: findings follow signal and rule order, then name/hook order.
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
					$subject = isset( $finding['option'] ) ? $finding['option'] : $finding['hook'];
					if ( isset( $finding['group'] ) ) {
						$subject .= '/' . $finding['group'];
					}
					return $finding['code'] . ':' . $finding['signal'] . ':' . $subject;
				},
				$result['findings']
			)
		);
		$this->assertSame( array( 2, 4, 3 ), array_column( $result['signals'], 'finding_count' ) );
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
		$this->assertStringNotContainsString( 'option_value', $json );
		$this->assertStringNotContainsString( '"value"', $json );
		$this->assertSame( 0, preg_match( '/[0-9a-f]{32}/', $json ), 'No hashes or md5 event keys.' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Checks the serialized form too.
		$this->assertStringNotContainsString( self::FAKE_SECRET, serialize( $result ) );
	}

	/**
	 * Finding keys are fixed per rule and signal (the evidence contract for the UI).
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

		$this->assertSame( array( 'phase', 'status', 'signals', 'findings' ), array_keys( $result ) );
		$this->assertSame(
			array(
				array( 'code', 'signal', 'option', 'evidence', 'before', 'after' ),
				array( 'code', 'signal', 'hook', 'evidence', 'before', 'after' ),
				array( 'code', 'signal', 'hook', 'evidence', 'before', 'after' ),
				array( 'code', 'signal', 'hook', 'group', 'evidence', 'before', 'after' ),
			),
			array_map( 'array_keys', $result['findings'] )
		);
		$this->assertSame(
			array(
				array( 'transition', 'threshold_bytes', 'size_delta', 'value_changed' ),
				array( 'removed_recurring_count', 'added_recurring_count', 'not_replaced_count', 'other_recorded_changes' ),
				array( 'change' ),
				array( 'change' ),
			),
			array_map(
				static function ( array $finding ) {
					return array_keys( $finding['evidence'] );
				},
				$result['findings']
			)
		);
	}

	/**
	 * Large diffs (thousands of records per signal) stay fast and produce the expected findings.
	 */
	public function test_large_diffs() {
		$options = array(
			'added'   => array(),
			'removed' => array(),
			'changed' => array(),
		);
		$cron    = array(
			'added'       => array(),
			'removed'     => array(),
			'rescheduled' => array(),
			'changed'     => array(),
		);
		$as      = $cron;
		for ( $i = 0; $i < 2000; $i++ ) {
			$name                 = sprintf( 'opt_%05d', $i );
			$options['added'][]   = array(
				'name'          => $name,
				'size'          => 0 === $i % 10 ? 200000 : 100,
				'autoload'      => 'on',
				'is_autoloaded' => true,
			);
			$options['changed'][] = array(
				'name'                      => $name . '_c',
				'value_changed'             => true,
				'before_size'               => 100,
				'after_size'                => 0 === $i % 10 ? 200000 : 200,
				'size_delta'                => 0 === $i % 10 ? 199900 : 100,
				'before_autoload'           => 'on',
				'after_autoload'            => 'on',
				'autoload_value_changed'    => false,
				'before_is_autoloaded'      => true,
				'after_is_autoloaded'       => true,
				'autoload_behavior_changed' => false,
			);
			$hook                 = sprintf( 'hook_%05d', $i );
			$event                = array(
				'hook'         => $hook,
				'timestamp'    => self::T,
				'schedule'     => 'hourly',
				'interval'     => 3600,
				'is_recurring' => true,
			);
			$cron['removed'][]    = $event;
			if ( 0 !== $i % 4 ) {
				$cron['added'][] = $event; // Replaced: three of four hooks.
			}
			$cron['rescheduled'][] = array(
				'hook'             => $hook,
				'before_timestamp' => self::T,
				'after_timestamp'  => self::T + 3600,
				'timestamp_delta'  => 3600,
				'schedule'         => 'hourly',
				'interval'         => 3600,
				'is_recurring'     => true,
			);
			$as['changed'][]       = array(
				'hook'                   => $hook,
				'group'                  => '',
				'before_timestamp'       => self::T,
				'after_timestamp'        => self::T,
				'timestamp_changed'      => false,
				'before_schedule_type'   => 'cron',
				'after_schedule_type'    => 'cron',
				'before_interval'        => null,
				'after_interval'         => null,
				'before_cron_expression' => '0 0 * * *',
				'after_cron_expression'  => 0 === $i % 2 ? '0 0 * * * *' : '0 1 * * *',
				'before_is_recurring'    => true,
				'after_is_recurring'     => true,
			);
		}

		$start   = microtime( true );
		$result  = ( new PotentialImpact() )->evaluate(
			array(
				'final' => array(
					'options'          => self::signal( $options ),
					'cron'             => self::signal( $cron ),
					'action_scheduler' => self::signal( $as ),
				),
			)
		);
		$elapsed = microtime( true ) - $start;

		$this->assertSame( array( 400, 500, 1000 ), array_column( $result['signals'], 'finding_count' ) );
		$this->assertSame( 1, $result['findings'][500]['evidence']['other_recorded_changes']['rescheduled'] );
		$this->assertLessThan( 1.0, $elapsed, 'Evaluation of 14,000 records should take well under a second.' );
	}
}
