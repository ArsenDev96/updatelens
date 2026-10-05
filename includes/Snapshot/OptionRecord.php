<?php
/**
 * Safe metadata for one option.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * One option in a snapshot. Holds no raw value, only a fingerprint and size.
 */
final class OptionRecord {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Keyed fingerprint of the raw value (see OptionValueHasher).
	 *
	 * @var string
	 */
	private $fingerprint;

	/**
	 * Raw value size in bytes.
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
	 * @param string $name          Option name.
	 * @param string $fingerprint   Fingerprint of the raw value.
	 * @param int    $size          Raw value size in bytes.
	 * @param string $autoload      Raw `autoload` column value.
	 * @param bool   $is_autoloaded Whether WordPress autoloads the option.
	 */
	public function __construct( $name, $fingerprint, $size, $autoload, $is_autoloaded ) {
		$this->name          = (string) $name;
		$this->fingerprint   = (string) $fingerprint;
		$this->size          = (int) $size;
		$this->autoload      = (string) $autoload;
		$this->is_autoloaded = (bool) $is_autoloaded;
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
	 * Keyed fingerprint of the raw value.
	 *
	 * @return string
	 */
	public function get_fingerprint() {
		return $this->fingerprint;
	}

	/**
	 * Raw value size in bytes.
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
	 * @return array{name: string, fingerprint: string, size: int, autoload: string, is_autoloaded: bool}
	 */
	public function to_array() {
		return array(
			'name'          => $this->name,
			'fingerprint'   => $this->fingerprint,
			'size'          => $this->size,
			'autoload'      => $this->autoload,
			'is_autoloaded' => $this->is_autoloaded,
		);
	}
}
