<?php
/**
 * An option present in both snapshots whose state differs.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\OptionRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Before/after comparison of one option. Holds no fingerprints.
 *
 * Raw autoload storage (`yes` → `auto-on`) and effective autoload behavior
 * (autoloaded → not autoloaded) are tracked separately: the first can change
 * without the second.
 */
final class ChangedOption {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Whether the value fingerprint differs.
	 *
	 * @var bool
	 */
	private $value_changed;

	/**
	 * Size before, in bytes.
	 *
	 * @var int
	 */
	private $before_size;

	/**
	 * Size after, in bytes.
	 *
	 * @var int
	 */
	private $after_size;

	/**
	 * Raw `autoload` value before.
	 *
	 * @var string
	 */
	private $before_autoload;

	/**
	 * Raw `autoload` value after.
	 *
	 * @var string
	 */
	private $after_autoload;

	/**
	 * Whether autoloaded before.
	 *
	 * @var bool
	 */
	private $before_is_autoloaded;

	/**
	 * Whether autoloaded after.
	 *
	 * @var bool
	 */
	private $after_is_autoloaded;

	/**
	 * Constructor. Use between().
	 *
	 * @param OptionRecord $before Record before.
	 * @param OptionRecord $after  Record after.
	 */
	private function __construct( OptionRecord $before, OptionRecord $after ) {
		$this->name                 = $after->get_name();
		$this->value_changed        = $before->get_fingerprint() !== $after->get_fingerprint();
		$this->before_size          = $before->get_size();
		$this->after_size           = $after->get_size();
		$this->before_autoload      = $before->get_autoload();
		$this->after_autoload       = $after->get_autoload();
		$this->before_is_autoloaded = $before->is_autoloaded();
		$this->after_is_autoloaded  = $after->is_autoloaded();
	}

	/**
	 * Compare two records of the same option.
	 *
	 * Both records must come from snapshots with the same fingerprint context.
	 *
	 * @param OptionRecord $before Record before.
	 * @param OptionRecord $after  Record after.
	 * @return self|null Null if nothing differs.
	 */
	public static function between( OptionRecord $before, OptionRecord $after ) {
		$changed = new self( $before, $after );

		$differs = $changed->value_changed
			|| 0 !== $changed->get_size_delta()
			|| $changed->is_autoload_value_changed()
			|| $changed->is_autoload_behavior_changed();

		return $differs ? $changed : null;
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
	 * Whether the value changed (by fingerprint, independent of size).
	 *
	 * @return bool
	 */
	public function is_value_changed() {
		return $this->value_changed;
	}

	/**
	 * Size before, in bytes.
	 *
	 * @return int
	 */
	public function get_before_size() {
		return $this->before_size;
	}

	/**
	 * Size after, in bytes.
	 *
	 * @return int
	 */
	public function get_after_size() {
		return $this->after_size;
	}

	/**
	 * Signed size change in bytes (after - before).
	 *
	 * @return int
	 */
	public function get_size_delta() {
		return $this->after_size - $this->before_size;
	}

	/**
	 * Raw `autoload` value before.
	 *
	 * @return string
	 */
	public function get_before_autoload() {
		return $this->before_autoload;
	}

	/**
	 * Raw `autoload` value after.
	 *
	 * @return string
	 */
	public function get_after_autoload() {
		return $this->after_autoload;
	}

	/**
	 * Whether the raw `autoload` column value changed (e.g. `yes` → `auto-on`).
	 *
	 * @return bool
	 */
	public function is_autoload_value_changed() {
		return $this->before_autoload !== $this->after_autoload;
	}

	/**
	 * Whether autoloaded before.
	 *
	 * @return bool
	 */
	public function get_before_is_autoloaded() {
		return $this->before_is_autoloaded;
	}

	/**
	 * Whether autoloaded after.
	 *
	 * @return bool
	 */
	public function get_after_is_autoloaded() {
		return $this->after_is_autoloaded;
	}

	/**
	 * Whether WordPress's effective autoload behavior changed.
	 *
	 * @return bool
	 */
	public function is_autoload_behavior_changed() {
		return $this->before_is_autoloaded !== $this->after_is_autoloaded;
	}

	/**
	 * Array form.
	 *
	 * @return array<string, string|int|bool>
	 */
	public function to_array() {
		return array(
			'name'                      => $this->name,
			'value_changed'             => $this->value_changed,
			'before_size'               => $this->before_size,
			'after_size'                => $this->after_size,
			'size_delta'                => $this->get_size_delta(),
			'before_autoload'           => $this->before_autoload,
			'after_autoload'            => $this->after_autoload,
			'autoload_value_changed'    => $this->is_autoload_value_changed(),
			'before_is_autoloaded'      => $this->before_is_autoloaded,
			'after_is_autoloaded'       => $this->after_is_autoloaded,
			'autoload_behavior_changed' => $this->is_autoload_behavior_changed(),
		);
	}
}
