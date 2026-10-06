<?php
/**
 * Tests for ActionSchedulerSnapshotProvider without WordPress.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Snapshot;

use PHPUnit\Framework\TestCase;
use UpdateLens\Snapshot\ActionSchedulerArgsHasher;
use UpdateLens\Snapshot\ActionSchedulerSnapshotBuilder;
use UpdateLens\Snapshot\ActionSchedulerSnapshotProvider;
use UpdateLens\Snapshot\ActionSchedulerUnavailableException;
use UpdateLens\Tests\Support\ActionSchedulerFixture as AS_Fixture;

/**
 * Availability when Action Scheduler is not loaded (the store and schema
 * checks need a WordPress site and are covered by integration testing).
 */
final class ActionSchedulerSnapshotProviderTest extends TestCase {

	/**
	 * Provider under test.
	 *
	 * @return ActionSchedulerSnapshotProvider
	 */
	private function provider() {
		return new ActionSchedulerSnapshotProvider( new ActionSchedulerSnapshotBuilder( new ActionSchedulerArgsHasher( AS_Fixture::SECRET ) ) );
	}

	/**
	 * Without Action Scheduler loaded, the state is "not installed", not an empty snapshot.
	 */
	public function test_not_installed() {
		$this->assertFalse( class_exists( 'ActionScheduler', false ) );
		$this->assertSame( ActionSchedulerUnavailableException::NOT_INSTALLED, $this->provider()->get_availability() );

		try {
			$this->provider()->capture();
			$this->fail( 'Expected ActionSchedulerUnavailableException.' );
		} catch ( ActionSchedulerUnavailableException $e ) {
			$this->assertSame( ActionSchedulerUnavailableException::NOT_INSTALLED, $e->get_reason() );
		}
	}
}
