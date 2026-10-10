<?php
/**
 * The before/after comparison of one site check.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

defined( 'ABSPATH' ) || exit;

/**
 * State, reason and evidence of one check's comparison (CheckComparator).
 * Immutable.
 */
final class CheckComparison {

	/**
	 * Check identifier.
	 *
	 * @var string
	 */
	private $check;

	/**
	 * ComparisonState state.
	 *
	 * @var string
	 */
	private $state;

	/**
	 * ComparisonState reason (`not_run` and `not_comparable` only).
	 *
	 * @var string|null
	 */
	private $reason;

	/**
	 * Whole seconds between the BEFORE result and the update start.
	 *
	 * @var int|null
	 */
	private $baseline_age_seconds;

	/**
	 * BEFORE result.
	 *
	 * @var CheckResult|null
	 */
	private $before;

	/**
	 * AFTER result.
	 *
	 * @var CheckResult|null
	 */
	private $after;

	/**
	 * Constructor.
	 *
	 * @param string           $check                Check identifier.
	 * @param string           $state                ComparisonState state.
	 * @param string|null      $reason               ComparisonState reason.
	 * @param int|null         $baseline_age_seconds Baseline age at the update start.
	 * @param CheckResult|null $before               BEFORE result.
	 * @param CheckResult|null $after                AFTER result.
	 */
	public function __construct( $check, $state, $reason, $baseline_age_seconds, ?CheckResult $before, ?CheckResult $after ) {
		$this->check                = $check;
		$this->state                = $state;
		$this->reason               = $reason;
		$this->baseline_age_seconds = $baseline_age_seconds;
		$this->before               = $before;
		$this->after                = $after;
	}

	/**
	 * Check identifier.
	 *
	 * @return string
	 */
	public function get_check() {
		return $this->check;
	}

	/**
	 * Comparison state.
	 *
	 * @return string
	 */
	public function get_state() {
		return $this->state;
	}

	/**
	 * Reason (null unless `not_run` or `not_comparable`).
	 *
	 * @return string|null
	 */
	public function get_reason() {
		return $this->reason;
	}

	/**
	 * Whether this is a potential failure finding: a passing baseline and a
	 * confirmed failure after the update. Nothing else ever is.
	 *
	 * @return bool
	 */
	public function is_potential_failure() {
		return ComparisonState::NEWLY_FAILING === $this->state;
	}

	/**
	 * Deterministic output: state, reason, baseline age and each side's
	 * verdict with its attempts' facts. Never targets, sessions, users,
	 * timestamps, markers, URLs or bodies.
	 *
	 * @return array{check: string, state: string, reason: string|null, baseline_age_seconds: int|null, before: array|null, after: array|null}
	 */
	public function to_array() {
		return array(
			'check'                => $this->check,
			'state'                => $this->state,
			'reason'               => $this->reason,
			'baseline_age_seconds' => $this->baseline_age_seconds,
			'before'               => null === $this->before ? null : $this->before->to_report_array(),
			'after'                => null === $this->after ? null : $this->after->to_report_array(),
		);
	}
}
