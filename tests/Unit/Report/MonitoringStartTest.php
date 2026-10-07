<?php
/**
 * Tests for the monitoring start read model.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpdateLens\Baseline\MonitoringBaseline;
use UpdateLens\Report\MonitoringStart;
use UpdateLens\Storage\MonitoringBaselineCodec;
use UpdateLens\Tests\Support\InMemoryAnalysisRepository;
use UpdateLens\Update\AnalysisStatus;

/**
 * Visible monitoring start from the baseline and the analysis history.
 */
final class MonitoringStartTest extends TestCase {

	/**
	 * Stored baseline option, or false.
	 *
	 * @var mixed
	 */
	private $stored;

	/**
	 * Analyses.
	 *
	 * @var InMemoryAnalysisRepository
	 */
	private $repository;

	/**
	 * Fresh state.
	 *
	 * @before
	 */
	public function reset_state() {
		$this->stored     = false;
		$this->repository = new InMemoryAnalysisRepository();
	}

	/**
	 * Store a baseline.
	 *
	 * @param string $started_at UTC DATETIME.
	 * @return void
	 */
	private function store_baseline( $started_at ) {
		$this->stored = ( new MonitoringBaselineCodec() )->encode(
			$started_at,
			array(
				array(
					'file'    => 'classic-editor/classic-editor.php',
					'name'    => 'Classic Editor',
					'version' => '1.7.0',
					'active'  => false,
				),
				array(
					'file'    => 'woocommerce/woocommerce.php',
					'name'    => 'WooCommerce',
					'version' => '11.1.2',
					'active'  => true,
				),
			)
		);
	}

	/**
	 * Insert an analysis.
	 *
	 * @param string $started_at UTC DATETIME.
	 * @return void
	 */
	private function analysis( $started_at ) {
		$this->repository->create(
			array(
				'plugin_file'    => 'acme/acme.php',
				'plugin_name'    => 'Acme',
				'version_before' => '1.0.0',
				'status'         => AnalysisStatus::COMPLETED,
				'started_at'     => $started_at,
				'updated_at'     => $started_at,
			)
		);
	}

	/**
	 * Read model.
	 *
	 * @return array
	 */
	private function read() {
		$baseline = new MonitoringBaseline(
			function () {
				return $this->stored;
			},
			function () {
				throw new RuntimeException( 'reads never write' );
			},
			function () {
				throw new RuntimeException( 'reads never delete' );
			},
			function () {
				throw new RuntimeException( 'reads never capture' );
			}
		);

		return ( new MonitoringStart( $baseline, $this->repository ) )->read();
	}

	/**
	 * Fresh install: the baseline is the start; its plugins are the plugins at the start.
	 */
	public function test_fresh_install() {
		$this->store_baseline( '2026-10-07 22:32:00' );

		$this->assertSame(
			array(
				'started_at'   => '2026-10-07T22:32:00Z',
				'plugin_count' => 2,
				'plugins'      => array(
					array(
						'file'    => 'classic-editor/classic-editor.php',
						'name'    => 'Classic Editor',
						'version' => '1.7.0',
						'active'  => false,
					),
					array(
						'file'    => 'woocommerce/woocommerce.php',
						'name'    => 'WooCommerce',
						'version' => '11.1.2',
						'active'  => true,
					),
				),
			),
			$this->read()
		);
	}

	/**
	 * Analyses after the baseline do not move the start.
	 */
	public function test_later_analyses_keep_baseline_start() {
		$this->store_baseline( '2026-10-07 22:32:00' );
		$this->analysis( '2026-10-08 09:00:00' );
		$this->analysis( '2026-10-09 09:00:00' );

		$read = $this->read();
		$this->assertSame( '2026-10-07T22:32:00Z', $read['started_at'] );
		$this->assertSame( 2, $read['plugin_count'] );
	}

	/**
	 * Upgrade from a version without a baseline: the first analysis is the
	 * start, and the later baseline is not shown as the plugins at the start.
	 */
	public function test_upgraded_site_starts_at_first_analysis() {
		$this->analysis( '2026-09-01 08:00:00' );
		$this->analysis( '2026-09-15 08:00:00' );
		$this->store_baseline( '2026-10-07 22:32:00' );

		$this->assertSame(
			array(
				'started_at'   => '2026-09-01T08:00:00Z',
				'plugin_count' => null,
				'plugins'      => null,
			),
			$this->read()
		);
		$this->assertCount( 2, $this->repository->rows, 'No analyses are created or changed.' );
		$this->assertSame( '2026-09-01 08:00:00', $this->repository->rows[1]['started_at'] );
	}

	/**
	 * Analyses but no (readable) baseline: the first analysis is the start.
	 */
	public function test_missing_or_corrupt_baseline_with_history() {
		$this->analysis( '2026-09-01 08:00:00' );
		$this->assertSame( '2026-09-01T08:00:00Z', $this->read()['started_at'] );

		$this->stored = 'not json';
		$this->assertSame(
			array(
				'started_at'   => '2026-09-01T08:00:00Z',
				'plugin_count' => null,
				'plugins'      => null,
			),
			$this->read()
		);
	}

	/**
	 * Nothing known: every field null (not an error).
	 */
	public function test_missing_or_corrupt_baseline_without_history() {
		$unknown = array(
			'started_at'   => null,
			'plugin_count' => null,
			'plugins'      => null,
		);
		$this->assertSame( $unknown, $this->read() );

		$this->stored = '{"schema":99}';
		$this->assertSame( $unknown, $this->read() );
	}

	/**
	 * An unreadable first analysis time cannot be placed: the start is unknown.
	 */
	public function test_unreadable_first_analysis_time() {
		$this->store_baseline( '2026-10-07 22:32:00' );
		$this->analysis( 'garbage' );

		$this->assertSame(
			array(
				'started_at'   => null,
				'plugin_count' => null,
				'plugins'      => null,
			),
			$this->read()
		);
	}

	/**
	 * Storage failures propagate (the REST layer turns them into a generic error).
	 */
	public function test_read_failure_throws() {
		$this->store_baseline( '2026-10-07 22:32:00' );
		$this->repository->fail_reads = true;

		$this->expectException( RuntimeException::class );
		$this->read();
	}

	/**
	 * Only the documented fields, without plugin settings or update data.
	 */
	public function test_safe_shape() {
		$this->store_baseline( '2026-10-07 22:32:00' );
		$read = $this->read();

		$this->assertSame( array( 'started_at', 'plugin_count', 'plugins' ), array_keys( $read ) );
		foreach ( $read['plugins'] as $plugin ) {
			$this->assertSame( array( 'file', 'name', 'version', 'active' ), array_keys( $plugin ) );
		}
	}
}
