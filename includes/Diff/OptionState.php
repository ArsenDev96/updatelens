<?php
/**
 * Safe state of an added or removed option.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\OptionRecord;

defined( 'ABSPATH' ) || exit;

/**
 * An option's state in one snapshot, without its fingerprint.
 *
 * Used for added options (state after) and removed options (state before).
 */
final class OptionState {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Value size in bytes.
	 *
	 * @var int
	 */
	private $size;

	/**
	 * Raw `autoload` column value.
	 *
	 * @var string
	 */
	private $autoload;

	/**
	 * Whether WordPress autoloads the option.
	 *
	 * @var bool
	 */
	private $is_autoloaded;

	/**
	 * Constructor.
	 *
	 * @param OptionRecord $record Snapshot record.
	 */
	public function __construct( OptionRecord $record ) {
		$this->name          = $record->get_name();
		$this->size          = $record->get_size();
		$this->autoload      = $record->get_autoload();
		$this->is_autoloaded = $record->is_autoloaded();
	}

	/**
	 * Option name.
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Value size in bytes.
	 *
	 * @return int
	 */
	public function get_size() {
		return $this->size;
	}

	/**
	 * Raw `autoload` column value.
	 *
	 * @return string
	 */
	public function get_autoload() {
		return $this->autoload;
	}

	/**
	 * Whether WordPress autoloads the option.
	 *
	 * @return bool
	 */
	public function is_autoloaded() {
		return $this->is_autoloaded;
	}

	/**
	 * Array form.
	 *
	 * @return array{name: string, size: int, autoload: string, is_autoloaded: bool}
	 */
	public function to_array() {
		return array(
			'name'          => $this->name,
			'size'          => $this->size,
			'autoload'      => $this->autoload,
			'is_autoloaded' => $this->is_autoloaded,
		);
	}
}
