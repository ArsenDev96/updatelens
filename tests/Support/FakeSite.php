<?php
/**
 * Fake site for lifecycle-driven tests.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Support;

use Throwable;
use UpdateLens\Snapshot\ActionSchedulerArgsHasher;
use UpdateLens\Snapshot\ActionSchedulerSnapshotBuilder;
use UpdateLens\Snapshot\ActionSchedulerUnavailableException;
use UpdateLens\Snapshot\AutoloadPolicy;
use UpdateLens\Snapshot\CronArgsHasher;
use UpdateLens\Snapshot\CronSnapshotBuilder;
use UpdateLens\Snapshot\OptionNoiseFilter;
use UpdateLens\Snapshot\OptionsSnapshotBuilder;
use UpdateLens\Snapshot\OptionValueHasher;
use UpdateLens\Update\PluginUpdateAnalyzer;

/**
 * A fake `wp_options` table, `cron` option, Action Scheduler queue, salt and clock shared by several
 * "requests" (PluginUpdateAnalyzer instances) over one InMemoryAnalysisRepository.
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
	 * Fake `cron` option value (see CronFixture::cron()).
	 *
	 * @var mixed
	 */
	public $cron = array( 'version' => 2 );

	/**
	 * Site secret used for fingerprints (change to simulate rotated salts).
	 *
	 * @var string
	 */
	public $salt = 'site-salt';

	/**
	 * Secret used for Cron argument fingerprints; null means $salt.
	 *
	 * @var string|null
	 */
	public $cron_salt = null;

	/**
	 * Fake Action Scheduler: null when not installed, a Throwable the provider
	 * throws (e.g. an unsupported store), or active action rows (see
	 * ActionSchedulerFixture::row()).
	 *
	 * @var array|Throwable|null
	 */
	public $action_scheduler = null;

	/**
	 * Secret used for Action Scheduler argument fingerprints; null means $salt.
	 *
	 * @var string|null
	 */
	public $action_scheduler_salt = null;

	/**
	 * Number of Action Scheduler snapshots attempted.
	 *
	 * @var int
	 */
	public $action_scheduler_captures = 0;

	/**
	 * Number of Cron snapshots attempted.
	 *
	 * @var int
	 */
	public $cron_captures = 0;

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
	 * @param float|null $started_at When the request started. Default: half a
	 *                               second after the current fake time.
	 * @return PluginUpdateAnalyzer
	 */
	public function request( $started_at = null ) {
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

		$capture_cron = function () {
			++$this->cron_captures;

			return ( new CronSnapshotBuilder( new CronArgsHasher( null === $this->cron_salt ? $this->salt : $this->cron_salt ) ) )->build( $this->cron );
		};

		$capture_action_scheduler = function () {
			++$this->action_scheduler_captures;
			if ( null === $this->action_scheduler ) {
				throw ActionSchedulerUnavailableException::not_installed();
			}
			if ( $this->action_scheduler instanceof Throwable ) {
				throw $this->action_scheduler;
			}

			return ( new ActionSchedulerSnapshotBuilder( new ActionSchedulerArgsHasher( null === $this->action_scheduler_salt ? $this->salt : $this->action_scheduler_salt ) ) )->build( $this->action_scheduler );
		};

		return new PluginUpdateAnalyzer(
			$this->repository,
			$capture,
			$capture_cron,
			function () {
				return $this->time;
			},
			$capture_action_scheduler,
			null === $started_at ? $this->time + 0.5 : (float) $started_at
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
