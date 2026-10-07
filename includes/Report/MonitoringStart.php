<?php
/**
 * Read model of the monitoring start.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Report;

use RuntimeException;
use UpdateLens\Baseline\MonitoringBaseline;
use UpdateLens\Storage\AnalysisRepository;

defined( 'ABSPATH' ) || exit;

/**
 * When UpdateLens started monitoring, and which plugins were installed then.
 *
 * The visible start is the earlier of the baseline time and the first
 * stored analysis. Sites that ran UpdateLens before the baseline existed get
 * their baseline later than their first analysis: their start is that
 * analysis, and the baseline's plugin list is not shown as the plugins at
 * the start (it was captured later). Nothing is reconstructed from other data.
 *
 * Read-only.
 */
final class MonitoringStart {

	/**
	 * Monitoring baseline.
	 *
	 * @var MonitoringBaseline
	 */
	private $baseline;

	/**
	 * Analysis storage.
	 *
	 * @var AnalysisRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param MonitoringBaseline $baseline   Monitoring baseline.
	 * @param AnalysisRepository $repository Analysis storage.
	 */
	public function __construct( MonitoringBaseline $baseline, AnalysisRepository $repository ) {
		$this->baseline   = $baseline;
		$this->repository = $repository;
	}

	/**
	 * Monitoring start of the running site.
	 *
	 * @return self
	 */
	public static function create() {
		global $wpdb;

		return new self( MonitoringBaseline::create(), new AnalysisRepository( $wpdb ) );
	}

	/**
	 * Safe read model.
	 *
	 * `started_at` is null when unknown (no readable baseline and no analyses,
	 * or an unreadable first analysis time). `plugin_count` and `plugins` are
	 * null unless the baseline describes the monitoring start.
	 *
	 * @return array{started_at: string|null, plugin_count: int|null, plugins: array<int, array{file: string, name: string, version: string, active: bool}>|null}
	 * @throws RuntimeException If the analyses cannot be read.
	 */
	public function read() {
		$unknown = array(
			'started_at'   => null,
			'plugin_count' => null,
			'plugins'      => null,
		);

		$baseline = $this->baseline->read();
		$first    = $this->repository->first_started_at();

		if ( null !== $first && null === AnalysisReadModel::timestamp( $first ) ) {
			// The start cannot be placed relative to an unreadable analysis time.
			return $unknown;
		}

		if ( null !== $baseline && ( null === $first || strcmp( $baseline['started_at'], $first ) <= 0 ) ) {
			$plugins = array();
			foreach ( $baseline['plugins'] as $plugin ) {
				$plugins[] = array(
					'file'    => $plugin['file'],
					'name'    => $plugin['name'],
					'version' => $plugin['version'],
					'active'  => $plugin['active'],
				);
			}

			return array(
				'started_at'   => AnalysisReadModel::timestamp( $baseline['started_at'] ),
				'plugin_count' => count( $plugins ),
				'plugins'      => $plugins,
			);
		}

		if ( null !== $first ) {
			$unknown['started_at'] = AnalysisReadModel::timestamp( $first );
		}

		return $unknown;
	}
}
