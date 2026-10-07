<?php
/**
 * Monitoring baseline: when UpdateLens started observing, and which plugins were installed then.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Baseline;

use Throwable;
use UnexpectedValueException;
use UpdateLens\Core\Plugin;
use UpdateLens\Storage\MonitoringBaselineCodec;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, reads and removes the monitoring baseline option.
 *
 * The baseline is an inventory marker, not an analysis snapshot: the time
 * UpdateLens started monitoring and the plugins installed at that moment
 * (file, name, version, active). It is written once and never changed by
 * later updates, activations or reports; an existing value, even an
 * unreadable one, is never replaced. It is supportive UX data: failures are
 * swallowed and never affect activation or update analysis.
 */
final class MonitoringBaseline {

	/**
	 * Option holding the baseline JSON (not autoloaded).
	 */
	const OPTION = Plugin::OPTION_PREFIX . 'monitoring_baseline';

	/**
	 * Returns the stored option value, or false if there is none.
	 *
	 * @var callable
	 */
	private $read_option;

	/**
	 * Stores a value only if the option does not exist yet; returns whether it was added.
	 *
	 * @var callable
	 */
	private $add_option;

	/**
	 * Deletes the option.
	 *
	 * @var callable
	 */
	private $delete_option;

	/**
	 * Returns the current plugin inventory (PluginInventory::build() records).
	 *
	 * @var callable
	 */
	private $inventory;

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Storage format.
	 *
	 * @var MonitoringBaselineCodec
	 */
	private $codec;

	/**
	 * Constructor.
	 *
	 * @param callable      $read_option   Returns the stored value, or false if there is none.
	 * @param callable      $add_option    Receives the JSON; adds it only if the option does not exist; returns bool.
	 * @param callable      $delete_option Deletes the option.
	 * @param callable      $inventory     Returns PluginInventory::build() records for the installed plugins.
	 * @param callable|null $now           Returns the current Unix time. Default time().
	 */
	public function __construct( callable $read_option, callable $add_option, callable $delete_option, callable $inventory, ?callable $now = null ) {
		$this->read_option   = $read_option;
		$this->add_option    = $add_option;
		$this->delete_option = $delete_option;
		$this->inventory     = $inventory;
		$this->now           = null === $now ? 'time' : $now;
		$this->codec         = new MonitoringBaselineCodec();
	}

	/**
	 * Baseline of the running site.
	 *
	 * @return self
	 */
	public static function create() {
		return new self(
			static function () {
				return get_option( self::OPTION, false );
			},
			static function ( $value ) {
				return add_option( self::OPTION, $value, '', false );
			},
			static function () {
				delete_option( self::OPTION );
			},
			static function () {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$plugins = get_plugins();
				$active  = array_values( array_filter( array_map( 'strval', array_keys( $plugins ) ), 'is_plugin_active' ) );

				return PluginInventory::build( $plugins, $active, plugin_basename( UPDATELENS_FILE ) );
			}
		);
	}

	/**
	 * Remove the baseline. Used on uninstall (deactivation keeps it).
	 *
	 * @return void
	 */
	public static function uninstall() {
		self::create()->delete();
	}

	/**
	 * Create the baseline if none is stored yet.
	 *
	 * Runs on activation and when the UpdateLens screen loads (sites that
	 * upgraded from a version without a baseline, or whose activation could not
	 * create it). Never overwrites a stored value, never throws.
	 *
	 * @return bool Whether a baseline is stored afterwards (readable or not).
	 */
	public function ensure() {
		try {
			if ( false !== call_user_func( $this->read_option ) ) {
				return true;
			}

			$json = $this->codec->encode(
				gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->now ) ),
				call_user_func( $this->inventory )
			);

			return (bool) call_user_func( $this->add_option, $json );
		} catch ( Throwable $e ) {
			// Supportive data only: a later visit to the UpdateLens screen tries again.
			return false;
		}
	}

	/**
	 * The stored baseline.
	 *
	 * @return array{started_at: string, plugins: array<int, array{file: string, name: string, version: string, active: bool}>}|null Null if missing or unreadable.
	 */
	public function read() {
		$stored = call_user_func( $this->read_option );
		if ( false === $stored ) {
			return null;
		}

		try {
			return $this->codec->decode( $stored );
		} catch ( UnexpectedValueException $e ) {
			return null;
		}
	}

	/**
	 * Delete the stored baseline.
	 *
	 * @return void
	 */
	public function delete() {
		call_user_func( $this->delete_option );
	}
}
