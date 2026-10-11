<?php
/**
 * Tests for ObservationBudget.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\Snapshot\ActionSchedulerSnapshotProvider;
use UpdateLens\Snapshot\MemoryBudget;
use UpdateLens\Update\ObservationBudget;

/**
 * The preflight estimate per stage, from the number of options.
 */
final class ObservationBudgetTest extends TestCase {

	const MIB = 1048576;

	/**
	 * Number of times the options were counted.
	 *
	 * @var int
	 */
	private $counts = 0;

	/**
	 * Budget for a request using $usage of $limit with $options options.
	 *
	 * @param int      $limit   Limit in bytes (0: unlimited).
	 * @param int      $usage   Usage in bytes.
	 * @param int|null $options Options, or null when counting fails.
	 * @return ObservationBudget
	 */
	private function budget( $limit, $usage, $options ) {
		return new ObservationBudget(
			new MemoryBudget(
				$limit,
				static function () use ( $usage ) {
					return $usage;
				}
			),
			function () use ( $options ) {
				++$this->counts;
				if ( null === $options ) {
					throw new RuntimeException( 'count failed' );
				}
				return $options;
			}
		);
	}

	/**
	 * The largest options count that fits a stage at 128 MiB with 40 MiB used.
	 *
	 * @param string $stage Stage.
	 * @return int
	 */
	private static function largest_fitting( $stage ) {
		$free = 128 * self::MIB - 40 * self::MIB - MemoryBudget::RESERVE_BYTES - ObservationBudget::CAPTURE_BYTES;

		return (int) floor( $free / ObservationBudget::BYTES_PER_OPTION[ $stage ] );
	}

	/**
	 * Each stage fits up to its estimate and not one option more.
	 */
	public function test_stage_boundaries() {
		foreach ( array( ObservationBudget::IMMEDIATE, ObservationBudget::SETTLED ) as $stage ) {
			$fitting = self::largest_fitting( $stage );
			$this->assertTrue( $this->budget( 128 * self::MIB, 40 * self::MIB, $fitting )->fits( $stage ), $stage );
			$this->assertFalse( $this->budget( 128 * self::MIB, 40 * self::MIB, $fitting + 1 )->fits( $stage ), $stage );
		}
	}

	/**
	 * BEFORE begins an observation only if its most expensive stage fits too.
	 */
	public function test_before_needs_the_whole_observation() {
		$settled = self::largest_fitting( ObservationBudget::SETTLED );

		$this->assertTrue( $this->budget( 128 * self::MIB, 40 * self::MIB, $settled )->fits( ObservationBudget::BEFORE ) );
		$this->assertFalse( $this->budget( 128 * self::MIB, 40 * self::MIB, $settled + 1 )->fits( ObservationBudget::BEFORE ) );
		$this->assertGreaterThan( $settled + 1, self::largest_fitting( ObservationBudget::BEFORE ), 'BEFORE alone would have fitted.' );
	}

	/**
	 * Measured sizes: an ordinary site fits at 128 MiB; 50,000 options do not
	 * (with every option changed they could need about 145 MiB in SETTLED);
	 * 40,000 fit at 256 MiB, 50,000 at 512 MiB.
	 */
	public function test_realistic_sites() {
		$this->assertTrue( $this->budget( 128 * self::MIB, 42 * self::MIB, 400 )->fits( ObservationBudget::BEFORE ) );
		$this->assertTrue( $this->budget( 128 * self::MIB, 42 * self::MIB, 10000 )->fits( ObservationBudget::BEFORE ) );
		$this->assertFalse( $this->budget( 128 * self::MIB, 42 * self::MIB, 50000 )->fits( ObservationBudget::BEFORE ) );
		$this->assertTrue( $this->budget( 256 * self::MIB, 42 * self::MIB, 40000 )->fits( ObservationBudget::BEFORE ) );
		$this->assertFalse( $this->budget( 256 * self::MIB, 42 * self::MIB, 50000 )->fits( ObservationBudget::BEFORE ) );
		$this->assertTrue( $this->budget( 512 * self::MIB, 42 * self::MIB, 50000 )->fits( ObservationBudget::BEFORE ) );
	}

	/**
	 * Unknown option counts never fit; unlimited memory never counts.
	 */
	public function test_unknown_count_and_unlimited_memory() {
		$this->assertFalse( $this->budget( 512 * self::MIB, 40 * self::MIB, null )->fits( ObservationBudget::SETTLED ) );

		$this->counts = 0;
		$this->assertTrue( $this->budget( 0, 40 * self::MIB, null )->fits( ObservationBudget::SETTLED ) );
		$this->assertSame( 0, $this->counts );
	}

	/**
	 * Budget with WP-Cron and Action Scheduler sizes (null: cannot be measured).
	 *
	 * @param int      $limit      Limit in bytes (0: unlimited).
	 * @param int      $usage      Usage in bytes.
	 * @param int      $options    Options.
	 * @param int|null $cron_bytes Stored size of the `cron` option.
	 * @param int|null $actions    Active actions.
	 * @return ObservationBudget
	 */
	private function signals( $limit, $usage, $options, $cron_bytes, $actions ) {
		$measure = function ( $value ) {
			return function () use ( $value ) {
				++$this->counts;
				if ( null === $value ) {
					throw new RuntimeException( 'cannot measure' );
				}
				return $value;
			};
		};

		return new ObservationBudget(
			new MemoryBudget(
				$limit,
				static function () use ( $usage ) {
					return $usage;
				}
			),
			$measure( $options ),
			$measure( $cron_bytes ),
			$measure( $actions )
		);
	}

	/**
	 * Plan with every signal set to $value.
	 *
	 * @param bool $options          Options.
	 * @param bool $cron             WP-Cron.
	 * @param bool $action_scheduler Action Scheduler.
	 * @return array<string, bool>
	 */
	private static function planned( $options, $cron, $action_scheduler ) {
		return array(
			ObservationBudget::OPTIONS          => $options,
			ObservationBudget::CRON             => $cron,
			ObservationBudget::ACTION_SCHEDULER => $action_scheduler,
		);
	}

	/**
	 * WP-Cron and Action Scheduler fit up to their estimates on top of the
	 * options, and not one byte or action more.
	 */
	public function test_signal_boundaries() {
		$stage   = ObservationBudget::IMMEDIATE;
		$options = 1000;
		$free    = 128 * self::MIB - 40 * self::MIB - MemoryBudget::RESERVE_BYTES - ObservationBudget::CAPTURE_BYTES - $options * ObservationBudget::BYTES_PER_OPTION[ $stage ];

		$cron = (int) floor( $free / ObservationBudget::BYTES_PER_CRON_BYTE[ $stage ] );
		$this->assertSame( self::planned( true, true, true ), $this->signals( 128 * self::MIB, 40 * self::MIB, $options, $cron, 0 )->plan( $stage ) );
		$this->assertSame( self::planned( true, false, true ), $this->signals( 128 * self::MIB, 40 * self::MIB, $options, $cron + 1, 0 )->plan( $stage ) );

		$actions = (int) floor( ( $free - ActionSchedulerSnapshotProvider::BATCH_SIZE * ObservationBudget::ACTION_READ_BYTES ) / ObservationBudget::BYTES_PER_ACTION[ $stage ] );
		$this->assertSame( self::planned( true, true, true ), $this->signals( 128 * self::MIB, 40 * self::MIB, $options, 0, $actions )->plan( $stage ) );
		$this->assertSame( self::planned( true, true, false ), $this->signals( 128 * self::MIB, 40 * self::MIB, $options, 0, $actions + 1 )->plan( $stage ) );
	}

	/**
	 * Signals are planned in order, each on top of what was planned: WP-Cron
	 * first, then Action Scheduler with what is left; a signal that does not
	 * fit leaves its share to the next.
	 */
	public function test_signals_are_added_up_in_order() {
		$stage = ObservationBudget::SETTLED;
		$half  = (int) ( 35 * self::MIB );
		$cron  = (int) ( $half / ObservationBudget::BYTES_PER_CRON_BYTE[ $stage ] );
		$as    = (int) ( ( $half - ActionSchedulerSnapshotProvider::BATCH_SIZE * ObservationBudget::ACTION_READ_BYTES ) / ObservationBudget::BYTES_PER_ACTION[ $stage ] );

		// About 61 MiB free after the options: either one (35 MiB), not both.
		$this->assertSame( self::planned( true, true, false ), $this->signals( 128 * self::MIB, 40 * self::MIB, 1000, $cron, $as )->plan( $stage ) );
		$this->assertSame( self::planned( true, false, true ), $this->signals( 128 * self::MIB, 40 * self::MIB, 1000, 3 * $cron, $as )->plan( $stage ) );
		$this->assertSame( self::planned( true, true, true ), $this->signals( 256 * self::MIB, 40 * self::MIB, 1000, $cron, $as )->plan( $stage ) );
	}

	/**
	 * A batch of actions holds at most as many rows as there are actions: a
	 * site with a few actions is not charged a full batch of the largest rows.
	 */
	public function test_action_working_set_scales_with_the_actions() {
		$stage = ObservationBudget::SETTLED;
		$free  = 128 * self::MIB - 40 * self::MIB - MemoryBudget::RESERVE_BYTES - ObservationBudget::CAPTURE_BYTES - 1000 * ObservationBudget::BYTES_PER_OPTION[ $stage ];
		$cron  = (int) floor( ( $free - 2 * ( ObservationBudget::BYTES_PER_ACTION[ $stage ] + ObservationBudget::ACTION_READ_BYTES ) ) / ObservationBudget::BYTES_PER_CRON_BYTE[ $stage ] );

		$this->assertSame( self::planned( true, true, true ), $this->signals( 128 * self::MIB, 40 * self::MIB, 1000, $cron, 2 )->plan( $stage ) );
		$this->assertSame( self::planned( true, true, false ), $this->signals( 128 * self::MIB, 40 * self::MIB, 1000, $cron, 3 )->plan( $stage ) );
		$this->assertSame( self::planned( true, true, true ), $this->signals( 128 * self::MIB, 40 * self::MIB, 1000, $cron, 0 )->plan( $stage ), 'No actions, nothing to read.' );
	}

	/**
	 * BEFORE plans every signal with its most expensive stage.
	 */
	public function test_before_plans_the_whole_observation_of_every_signal() {
		$free    = 128 * self::MIB - 40 * self::MIB - MemoryBudget::RESERVE_BYTES - ObservationBudget::CAPTURE_BYTES - 1000 * ObservationBudget::BYTES_PER_OPTION[ ObservationBudget::SETTLED ];
		$cron    = (int) floor( $free / ObservationBudget::BYTES_PER_CRON_BYTE[ ObservationBudget::SETTLED ] );
		$budget  = $this->signals( 128 * self::MIB, 40 * self::MIB, 1000, $cron + 1, 0 );
		$fitting = $this->signals( 128 * self::MIB, 40 * self::MIB, 1000, $cron, 0 );

		$this->assertFalse( $budget->plan( ObservationBudget::BEFORE )[ ObservationBudget::CRON ] );
		$this->assertTrue( $fitting->plan( ObservationBudget::BEFORE )[ ObservationBudget::CRON ] );
		$this->assertTrue( $budget->plan( ObservationBudget::IMMEDIATE )[ ObservationBudget::CRON ], 'IMMEDIATE alone would have fitted.' );
	}

	/**
	 * Without options, nothing is planned (and the other signals are not
	 * measured); a signal that cannot be measured does not fit, the others
	 * still do; unlimited memory measures nothing.
	 */
	public function test_unmeasurable_signals_and_unlimited_memory() {
		$this->counts = 0;
		$this->assertSame( self::planned( false, false, false ), $this->signals( 128 * self::MIB, 40 * self::MIB, 1000000, 10, 10 )->plan( ObservationBudget::SETTLED ) );
		$this->assertSame( 1, $this->counts, 'Only the options were counted.' );

		$this->assertSame( self::planned( true, false, true ), $this->signals( 128 * self::MIB, 40 * self::MIB, 1000, null, 10 )->plan( ObservationBudget::SETTLED ) );
		$this->assertSame( self::planned( true, true, false ), $this->signals( 128 * self::MIB, 40 * self::MIB, 1000, 10, null )->plan( ObservationBudget::SETTLED ) );

		$this->counts = 0;
		$this->assertSame( self::planned( true, true, true ), $this->signals( 0, 40 * self::MIB, null, null, null )->plan( ObservationBudget::SETTLED ) );
		$this->assertSame( 0, $this->counts );
	}

	/**
	 * Measured datasets (Step 6B.1b, real updates): at 128 MiB an ordinary
	 * site with Action Scheduler keeps every signal; 50,000 WP-Cron events
	 * (5.4 MB stored) or 100,000 active actions are skipped; at 512 MiB the
	 * 50,000 events and 30,000 actions fit together.
	 */
	public function test_realistic_signal_sizes() {
		$this->assertSame( self::planned( true, true, true ), $this->signals( 128 * self::MIB, 42 * self::MIB, 400, 30000, 200 )->plan( ObservationBudget::BEFORE ) );
		$this->assertSame( self::planned( true, false, true ), $this->signals( 128 * self::MIB, 42 * self::MIB, 400, 5390739, 200 )->plan( ObservationBudget::BEFORE ) );
		$this->assertSame( self::planned( true, true, false ), $this->signals( 128 * self::MIB, 42 * self::MIB, 400, 30000, 100000 )->plan( ObservationBudget::BEFORE ) );
		$this->assertSame( self::planned( true, true, true ), $this->signals( 512 * self::MIB, 42 * self::MIB, 400, 5390739, 30000 )->plan( ObservationBudget::BEFORE ) );
	}
}
