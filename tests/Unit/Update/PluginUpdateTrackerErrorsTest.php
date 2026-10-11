<?php
/**
 * Tests that UpdateLens's database errors are not printed into responses.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\Storage\AnalysisRepository;
use UpdateLens\Update\PluginUpdateAnalyzer;
use UpdateLens\Update\PluginUpdateTracker;

/**
 * The tracker runs UpdateLens's storage work with $wpdb errors hidden
 * (WordPress still logs them), so a missing analyses table never prints
 * database errors into the update's Ajax response or a page, and restores
 * the previous setting afterwards.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PluginUpdateTrackerErrorsTest extends TestCase {

	/**
	 * Whether $wpdb showed errors while UpdateLens read its analyses.
	 *
	 * @var bool[]
	 */
	private $shown_during = array();

	/**
	 * Load the stand-ins: $wpdb and an eligible wp-admin page request.
	 *
	 * @before
	 */
	public function load_stubs() {
		require dirname( __DIR__, 2 ) . '/fixtures/wpdb-recorder.php';
		require_once dirname( __DIR__, 2 ) . '/fixtures/wp-tracker-stubs.php';
	}

	/**
	 * A tracker whose analyses table is missing: every read records whether
	 * errors were shown and fails.
	 *
	 * @return PluginUpdateTracker
	 */
	private function tracker() {
		$test       = $this;
		$repository = new class( $GLOBALS['wpdb'], $test ) extends AnalysisRepository {
			/**
			 * Test.
			 *
			 * @var PluginUpdateTrackerErrorsTest
			 */
			private $test;

			/**
			 * Constructor.
			 *
			 * @param \wpdb                         $wpdb Database.
			 * @param PluginUpdateTrackerErrorsTest $test Test.
			 */
			public function __construct( $wpdb, $test ) {
				parent::__construct( $wpdb );
				$this->test = $test;
			}

			/**
			 * The table is missing.
			 *
			 * @throws RuntimeException Always.
			 */
			public function find_open() {
				$this->test->record( $GLOBALS['wpdb']->show_errors );
				throw new RuntimeException( "Table 'wp_updatelens_analyses' doesn't exist" );
			}
		};
		$analyzer   = new PluginUpdateAnalyzer(
			$repository,
			static function () {
				throw new RuntimeException( 'not captured in this test' );
			},
			static function () {
				return 'snapshot_unavailable';
			},
			null,
			null,
			microtime( true )
		);

		return new PluginUpdateTracker( $analyzer );
	}

	/**
	 * Record the error display setting.
	 *
	 * @param bool $shown Whether errors were shown.
	 * @return void
	 */
	public function record( $shown ) {
		$this->shown_during[] = $shown;
	}

	/**
	 * At shutdown of an admin page (settling): hidden while UpdateLens works,
	 * shown again afterwards.
	 */
	public function test_errors_hidden_during_shutdown_work_and_restored() {
		$GLOBALS['wpdb']->show_errors = true;

		$this->tracker()->on_shutdown();

		$this->assertSame( array( false ), $this->shown_during );
		$this->assertTrue( $GLOBALS['wpdb']->show_errors, 'Restored for the rest of the request.' );
	}

	/**
	 * A site that hides errors keeps hiding them (the previous setting is
	 * restored, never forced on).
	 */
	public function test_previous_setting_is_kept() {
		$GLOBALS['wpdb']->show_errors = false;

		$this->tracker()->on_shutdown();

		$this->assertSame( array( false ), $this->shown_during );
		$this->assertFalse( $GLOBALS['wpdb']->show_errors );
	}
}
