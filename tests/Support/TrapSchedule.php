<?php
/**
 * Class whose deserialization would have a side effect.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Support;

/**
 * Records whether an instance was ever woken up from a serialized string.
 */
final class TrapSchedule {

	/**
	 * Set when an instance is woken up.
	 *
	 * @var bool
	 */
	public static $woken = false;

	/**
	 * Records a wakeup.
	 */
	public function __wakeup() {
		self::$woken = true;
	}
}
