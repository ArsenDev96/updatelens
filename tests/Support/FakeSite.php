<?php
/**
 * Fake site for lifecycle-driven tests.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Support;

use UpdateLens\Snapshot\AutoloadPolicy;
use UpdateLens\Snapshot\OptionNoiseFilter;
use UpdateLens\Snapshot\OptionsSnapshotBuilder;
use UpdateLens\Snapshot\OptionValueHasher;
use UpdateLens\Update\PluginUpdateAnalyzer;

/**
 * A fake `wp_options` table, salt and clock shared by several "requests"
 * (PluginUpdateAnalyzer instances) over one InMemoryAnalysisRepository.
 */
final class FakeSite {

	/**
	 * Storage.
	 *
	 * @var InMemoryAnalysisRepository
	 */
	public $repository;

	/**
	 * Fake wp_options: name => [ raw value, raw autoload ].
	 *
	 * @var array<string, array{string, string}>
	 */
	public $options;

	/**
	 * Site secret used for fingerprints (change to simulate rotated salts).
	 *
	 * @var string
	 */
	public $salt = 'site-salt';

	/**
	 * Current Unix time.
	 *
	 * @var int
	 */
	public $time;

	/**
	 * Number of snapshots captured.
	 *
	 * @var int
	 */
	public $captures = 0;

	/**
	 * Constructor.
	 *
	 * @param array<string, array{string, string}> $options Initial options.
	 * @param int                                  $time    Initial time.
	 */
	public function __construct( array $options, $time ) {
		$this->repository = new InMemoryAnalysisRepository();
		$this->options    = $options;
		$this->time       = $time;
	}

	/**
	 * A new "request".
	 *
	 * @return PluginUpdateAnalyzer
	 */
	public function request() {
		$capture = function () {
			++$this->captures;

			$rows = array();
			foreach ( $this->options as $name => $option ) {
				$rows[] = array(
					'option_name'  => $name,
					'option_value' => $option[0],
					'autoload'     => $option[1],
				);
			}

			$builder = new OptionsSnapshotBuilder(
				new OptionValueHasher( $this->salt ),
				new OptionNoiseFilter(),
				new AutoloadPolicy( array( 'yes', 'on', 'auto-on', 'auto' ) )
			);

			return $builder->build( $rows );
		};

		return new PluginUpdateAnalyzer(
			$this->repository,
			$capture,
			function () {
				return $this->time;
			}
		);
	}

	/**
	 * Start a supported single-plugin update in a request.
	 *
	 * @param PluginUpdateAnalyzer $request Request.
	 * @param string               $plugin  Plugin file.
	 * @param string               $name    Plugin name.
	 * @return void
	 */
	public function start( PluginUpdateAnalyzer $request, $plugin, $name = 'Acme' ) {
		$request->update_starting(
			$plugin,
			array(
				'name'    => $name,
				'version' => '1.0.0',
			),
			7
		);
	}

	/**
	 * A successful update request (update + its own shutdown), leaving the analysis awaiting settle.
	 *
	 * @param string $plugin Plugin file.
	 * @param string $name   Plugin name.
	 * @return void
	 */
	public function update( $plugin, $name = 'Acme' ) {
		$request = $this->request();
		$this->start( $request, $plugin, $name );
		$request->update_finished( $plugin, null, '1.1.0' );
		$request->request_ending( true );
	}

	/**
	 * A later wp-admin page request (settles within the window, expires after).
	 *
	 * @return void
	 */
	public function admin_page() {
		$this->request()->request_ending( true );
	}
}
