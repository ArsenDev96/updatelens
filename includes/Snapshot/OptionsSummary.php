<?php
/**
 * Totals for a `wp_options` snapshot.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Counts and byte totals over the options included in a snapshot.
 *
 * Byte counts are raw integers; formatting (KB/MB) is presentation.
 */
final class OptionsSummary {

	/**
	 * Number of included options.
	 *
	 * @var int
	 */
	private $option_count = 0;

	/**
	 * Number of included autoloaded options.
	 *
	 * @var int
	 */
	private $autoloaded_count = 0;

	/**
	 * Bytes of all included option values.
	 *
	 * @var int
	 */
	private $total_bytes = 0;

	/**
	 * Bytes of included autoloaded option values.
	 *
	 * @var int
	 */
	private $autoloaded_bytes = 0;

	/**
	 * Constructor.
	 *
	 * @param OptionRecord[] $records Included option records.
	 */
	public function __construct( array $records ) {
		foreach ( $records as $record ) {
			++$this->option_count;
			$this->total_bytes += $record->get_size();

			if ( $record->is_autoloaded() ) {
				++$this->autoloaded_count;
				$this->autoloaded_bytes += $record->get_size();
			}
		}
	}

	/**
	 * Number of included options.
	 *
	 * @return int
	 */
	public function get_option_count() {
		return $this->option_count;
	}

	/**
	 * Number of included autoloaded options.
	 *
	 * @return int
	 */
	public function get_autoloaded_count() {
		return $this->autoloaded_count;
	}

	/**
	 * Bytes of all included option values.
	 *
	 * @return int
	 */
	public function get_total_bytes() {
		return $this->total_bytes;
	}

	/**
	 * Bytes of included autoloaded option values.
	 *
	 * @return int
	 */
	public function get_autoloaded_bytes() {
		return $this->autoloaded_bytes;
	}

	/**
	 * Array form.
	 *
	 * @return array{option_count: int, autoloaded_count: int, total_bytes: int, autoloaded_bytes: int}
	 */
	public function to_array() {
		return array(
			'option_count'     => $this->option_count,
			'autoloaded_count' => $this->autoloaded_count,
			'total_bytes'      => $this->total_bytes,
			'autoloaded_bytes' => $this->autoloaded_bytes,
		);
	}
}
