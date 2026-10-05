<?php
/**
 * Result of comparing two `wp_options` snapshots.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Diff;

defined( 'ABSPATH' ) || exit;

/**
 * Added, removed and changed options plus a summary.
 *
 * Collections are keyed by option name in byte-wise order. Contains no option
 * values and no fingerprints.
 */
final class OptionsDiff {

	/**
	 * Added options (state after), keyed by name.
	 *
	 * @var array<string, OptionState>
	 */
	private $added = array();

	/**
	 * Removed options (state before), keyed by name.
	 *
	 * @var array<string, OptionState>
	 */
	private $removed = array();

	/**
	 * Changed options, keyed by name.
	 *
	 * @var array<string, ChangedOption>
	 */
	private $changed = array();

	/**
	 * Summary.
	 *
	 * @var OptionsDiffSummary
	 */
	private $summary;

	/**
	 * Constructor.
	 *
	 * @param OptionState[]      $added   Added options.
	 * @param OptionState[]      $removed Removed options.
	 * @param ChangedOption[]    $changed Changed options.
	 * @param OptionsDiffSummary $summary Summary.
	 */
	public function __construct( array $added, array $removed, array $changed, OptionsDiffSummary $summary ) {
		foreach ( $added as $option ) {
			$this->added[ $option->get_name() ] = $option;
		}
		foreach ( $removed as $option ) {
			$this->removed[ $option->get_name() ] = $option;
		}
		foreach ( $changed as $option ) {
			$this->changed[ $option->get_name() ] = $option;
		}

		ksort( $this->added, SORT_STRING );
		ksort( $this->removed, SORT_STRING );
		ksort( $this->changed, SORT_STRING );

		$this->summary = $summary;
	}

	/**
	 * Added options, keyed by name.
	 *
	 * @return array<string, OptionState>
	 */
	public function get_added() {
		return $this->added;
	}

	/**
	 * Removed options, keyed by name.
	 *
	 * @return array<string, OptionState>
	 */
	public function get_removed() {
		return $this->removed;
	}

	/**
	 * Changed options, keyed by name.
	 *
	 * @return array<string, ChangedOption>
	 */
	public function get_changed() {
		return $this->changed;
	}

	/**
	 * Summary.
	 *
	 * @return OptionsDiffSummary
	 */
	public function get_summary() {
		return $this->summary;
	}

	/**
	 * Whether any option was added, removed or changed.
	 *
	 * @return bool
	 */
	public function has_changes() {
		return $this->added || $this->removed || $this->changed;
	}

	/**
	 * Array form.
	 *
	 * @return array{added: array<int, array>, removed: array<int, array>, changed: array<int, array>, summary: array<string, int>}
	 */
	public function to_array() {
		$to_arrays = static function ( array $options ) {
			$result = array();
			foreach ( $options as $option ) {
				$result[] = $option->to_array();
			}
			return $result;
		};

		return array(
			'added'   => $to_arrays( $this->added ),
			'removed' => $to_arrays( $this->removed ),
			'changed' => $to_arrays( $this->changed ),
			'summary' => $this->summary->to_array(),
		);
	}
}
