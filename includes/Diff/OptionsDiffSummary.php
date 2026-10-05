<?php
/**
 * Totals for a `wp_options` diff.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

use UpdateLens\Snapshot\OptionsSummary;

defined( 'ABSPATH' ) || exit;

/**
 * Before/after totals, signed deltas (after - before) and change counts.
 *
 * Deltas come from the snapshot summaries, so they include every way a total
 * can move: added/removed options, resized values and autoload switches.
 * Byte counts are raw integers; formatting is presentation.
 */
final class OptionsDiffSummary {

	/**
	 * Snapshot summary before.
	 *
	 * @var OptionsSummary
	 */
	private $before;

	/**
	 * Snapshot summary after.
	 *
	 * @var OptionsSummary
	 */
	private $after;

	/**
	 * Number of added options.
	 *
	 * @var int
	 */
	private $added_count;

	/**
	 * Number of removed options.
	 *
	 * @var int
	 */
	private $removed_count;

	/**
	 * Number of changed options.
	 *
	 * @var int
	 */
	private $changed_count = 0;

	/**
	 * Changed options whose value changed.
	 *
	 * @var int
	 */
	private $value_changed_count = 0;

	/**
	 * Changed options whose raw `autoload` value changed.
	 *
	 * @var int
	 */
	private $autoload_value_changed_count = 0;

	/**
	 * Changed options whose effective autoload behavior changed.
	 *
	 * @var int
	 */
	private $autoload_behavior_changed_count = 0;

	/**
	 * Constructor.
	 *
	 * @param OptionsSummary  $before  Snapshot summary before.
	 * @param OptionsSummary  $after   Snapshot summary after.
	 * @param int             $added   Number of added options.
	 * @param int             $removed Number of removed options.
	 * @param ChangedOption[] $changed Changed options.
	 */
	public function __construct( OptionsSummary $before, OptionsSummary $after, $added, $removed, array $changed ) {
		$this->before        = $before;
		$this->after         = $after;
		$this->added_count   = (int) $added;
		$this->removed_count = (int) $removed;

		foreach ( $changed as $option ) {
			++$this->changed_count;
			$this->value_changed_count             += $option->is_value_changed() ? 1 : 0;
			$this->autoload_value_changed_count    += $option->is_autoload_value_changed() ? 1 : 0;
			$this->autoload_behavior_changed_count += $option->is_autoload_behavior_changed() ? 1 : 0;
		}
	}

	/**
	 * Snapshot summary before.
	 *
	 * @return OptionsSummary
	 */
	public function get_before() {
		return $this->before;
	}

	/**
	 * Snapshot summary after.
	 *
	 * @return OptionsSummary
	 */
	public function get_after() {
		return $this->after;
	}

	/**
	 * Signed change in option count.
	 *
	 * @return int
	 */
	public function get_option_count_delta() {
		return $this->after->get_option_count() - $this->before->get_option_count();
	}

	/**
	 * Signed change in total option bytes.
	 *
	 * @return int
	 */
	public function get_total_bytes_delta() {
		return $this->after->get_total_bytes() - $this->before->get_total_bytes();
	}

	/**
	 * Signed change in autoloaded option count.
	 *
	 * @return int
	 */
	public function get_autoloaded_count_delta() {
		return $this->after->get_autoloaded_count() - $this->before->get_autoloaded_count();
	}

	/**
	 * Signed change in autoloaded bytes.
	 *
	 * @return int
	 */
	public function get_autoloaded_bytes_delta() {
		return $this->after->get_autoloaded_bytes() - $this->before->get_autoloaded_bytes();
	}

	/**
	 * Number of added options.
	 *
	 * @return int
	 */
	public function get_added_count() {
		return $this->added_count;
	}

	/**
	 * Number of removed options.
	 *
	 * @return int
	 */
	public function get_removed_count() {
		return $this->removed_count;
	}

	/**
	 * Number of changed options.
	 *
	 * @return int
	 */
	public function get_changed_count() {
		return $this->changed_count;
	}

	/**
	 * Changed options whose value changed.
	 *
	 * @return int
	 */
	public function get_value_changed_count() {
		return $this->value_changed_count;
	}

	/**
	 * Changed options whose raw `autoload` value changed.
	 *
	 * @return int
	 */
	public function get_autoload_value_changed_count() {
		return $this->autoload_value_changed_count;
	}

	/**
	 * Changed options whose effective autoload behavior changed.
	 *
	 * @return int
	 */
	public function get_autoload_behavior_changed_count() {
		return $this->autoload_behavior_changed_count;
	}

	/**
	 * Array form.
	 *
	 * @return array<string, int>
	 */
	public function to_array() {
		return array(
			'before_option_count'             => $this->before->get_option_count(),
			'after_option_count'              => $this->after->get_option_count(),
			'option_count_delta'              => $this->get_option_count_delta(),
			'before_total_bytes'              => $this->before->get_total_bytes(),
			'after_total_bytes'               => $this->after->get_total_bytes(),
			'total_bytes_delta'               => $this->get_total_bytes_delta(),
			'before_autoloaded_count'         => $this->before->get_autoloaded_count(),
			'after_autoloaded_count'          => $this->after->get_autoloaded_count(),
			'autoloaded_count_delta'          => $this->get_autoloaded_count_delta(),
			'before_autoloaded_bytes'         => $this->before->get_autoloaded_bytes(),
			'after_autoloaded_bytes'          => $this->after->get_autoloaded_bytes(),
			'autoloaded_bytes_delta'          => $this->get_autoloaded_bytes_delta(),
			'added_count'                     => $this->added_count,
			'removed_count'                   => $this->removed_count,
			'changed_count'                   => $this->changed_count,
			'value_changed_count'             => $this->value_changed_count,
			'autoload_value_changed_count'    => $this->autoload_value_changed_count,
			'autoload_behavior_changed_count' => $this->autoload_behavior_changed_count,
		);
	}
}
