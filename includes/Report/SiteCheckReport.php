<?php
/**
 * Report view of an analysis's site checks.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Report;

use InvalidArgumentException;
use UpdateLens\SiteCheck\AfterReason;
use UpdateLens\SiteCheck\CheckComparator;
use UpdateLens\SiteCheck\CheckDefinition;
use UpdateLens\SiteCheck\CheckResult;
use UpdateLens\SiteCheck\ComparisonState;
use UpdateLens\SiteCheck\UpdateWindow;
use UpdateLens\Update\SiteCheckAfter;
use UpdateLens\Update\SiteCheckObservation;

defined( 'ABSPATH' ) || exit;

/**
 * The `site_checks` field of a report: per check the BEFORE and AFTER
 * sides and their comparison (CheckComparator), computed on every read,
 * never stored. Pure.
 *
 * Per check `state` is a ComparisonState (`passed_both`, `newly_failing`,
 * `failing_both`, `recovered`, `changed`: compared; `not_run`: a side is
 * missing; `not_comparable`: both exist but may not be compared) or
 * `pending` (the AFTER result may still arrive). An AFTER run that
 * overlapped other updater activity is `not_comparable`
 * (`overlapping_update` / `update_order_unknown`) even though its facts are
 * shown. Overall `status`: `pending`, `complete` (both compared),
 * `partial` (one) or `unavailable` (none).
 *
 * Contains verdicts, fixed reason codes, browser-observed facts (outcome,
 * HTTP status, duration, marker and body codes, origin), UTC timestamps and
 * whole-second ages. Never targets, login sessions, fingerprints, user IDs,
 * generations, URLs, markers, nonces, bodies or messages.
 */
final class SiteCheckReport {

	/**
	 * Overall statuses.
	 */
	const PENDING     = 'pending';
	const COMPLETE    = 'complete';
	const PARTIAL     = 'partial';
	const UNAVAILABLE = 'unavailable';

	/**
	 * Per-check state while the AFTER result may still arrive.
	 */
	const STATE_PENDING = 'pending';

	/**
	 * States of a check whose sides were compared.
	 */
	const COMPARED = array(
		ComparisonState::PASSED_BOTH,
		ComparisonState::NEWLY_FAILING,
		ComparisonState::FAILING_BOTH,
		ComparisonState::RECOVERED,
		ComparisonState::CHANGED,
	);

	/**
	 * Every per-check state.
	 */
	const STATES = array(
		ComparisonState::PASSED_BOTH,
		ComparisonState::NEWLY_FAILING,
		ComparisonState::FAILING_BOTH,
		ComparisonState::RECOVERED,
		ComparisonState::CHANGED,
		ComparisonState::NOT_RUN,
		ComparisonState::NOT_COMPARABLE,
		self::STATE_PENDING,
	);

	/**
	 * The site checks of an analysis row.
	 *
	 * @param array $row Analysis row (AnalysisRepository::REPORT_COLUMNS; missing columns read as NULL).
	 * @param float $now Current Unix time.
	 * @return array{status: string, checks: array<string, array>}
	 */
	public static function build( array $row, $now ) {
		$before  = SiteCheckObservation::read( $row );
		$after   = SiteCheckAfter::read( $row, $now );
		$started = isset( $row[ SiteCheckObservation::UPDATE_STARTED_AT ] ) && is_numeric( $row[ SiteCheckObservation::UPDATE_STARTED_AT ] ) ? (float) $row[ SiteCheckObservation::UPDATE_STARTED_AT ] : null;
		$ended   = self::time( isset( $row['completed_at'] ) ? $row['completed_at'] : null );
		if ( null !== $started && null !== $ended ) {
			// Completion is stored in whole seconds; it is never before the start.
			$ended = max( $started, $ended );
		}

		$checks   = array();
		$compared = 0;
		$pending  = false;
		foreach ( CheckDefinition::all() as $definition ) {
			$check                       = $definition->get_id();
			list( $state, $reason )      = self::compare( $definition, $before[ $check ], $after[ $check ], $started, $ended );
			$compared                   += in_array( $state, self::COMPARED, true ) ? 1 : 0;
			$pending                     = $pending || self::STATE_PENDING === $state;
			$checks[ $check ]            = array(
				'state'  => $state,
				'reason' => $reason,
				'before' => self::side( $before[ $check ]['result'], $before[ $check ]['reason'] ),
				'after'  => self::side( $after[ $check ]['result'], $after[ $check ]['reason'] ),
			);
			$checks[ $check ]['before'] += array( 'age_seconds' => self::seconds( $before[ $check ]['result'] ? $before[ $check ]['result']->get_completed_at() : null, $started ) );
			$checks[ $check ]['after']  += array( 'delay_seconds' => self::seconds( $ended, $after[ $check ]['result'] ? $after[ $check ]['result']->get_started_at() : null ) );
		}

		if ( $pending ) {
			$status = self::PENDING;
		} elseif ( count( $checks ) === $compared ) {
			$status = self::COMPLETE;
		} else {
			$status = $compared > 0 ? self::PARTIAL : self::UNAVAILABLE;
		}

		return array(
			'status' => $status,
			'checks' => $checks,
		);
	}

	/**
	 * State and reason of one check.
	 *
	 * @param CheckDefinition $definition Current definition.
	 * @param array           $before     BEFORE side (SiteCheckObservation::read()).
	 * @param array           $after      AFTER side (SiteCheckAfter::read()).
	 * @param float|null      $started    Update start.
	 * @param float|null      $ended      Update end (completed).
	 * @return array{0: string, 1: string|null}
	 */
	private static function compare( CheckDefinition $definition, array $before, array $after, $started, $ended ) {
		if ( $after['pending'] ) {
			return array( self::STATE_PENDING, null );
		}
		if ( AfterReason::OVERLAPPING_UPDATE === $after['reason'] ) {
			return array( ComparisonState::NOT_COMPARABLE, ComparisonState::OVERLAPPING_UPDATE );
		}
		if ( null !== $after['result'] && null !== $after['reason'] ) {
			// Stored with the result: the ledger could not rule other updates out.
			return array( ComparisonState::NOT_COMPARABLE, ComparisonState::UPDATE_ORDER_UNKNOWN );
		}
		if ( AfterReason::UPDATE_ORDER_UNKNOWN === $after['reason'] ) {
			return array( ComparisonState::NOT_COMPARABLE, ComparisonState::UPDATE_ORDER_UNKNOWN );
		}
		if ( null === $before['result'] ) {
			return array( ComparisonState::NOT_RUN, ComparisonState::BASELINE_MISSING );
		}
		if ( null === $after['result'] || null === $started || null === $ended ) {
			return array( ComparisonState::NOT_RUN, ComparisonState::AFTER_MISSING );
		}

		try {
			$comparison = CheckComparator::compare( $definition, $before['result'], $after['result'], new UpdateWindow( $started, $ended ) );
		} catch ( InvalidArgumentException $e ) {
			return array( ComparisonState::NOT_RUN, ComparisonState::AFTER_MISSING );
		}

		return array( $comparison->get_state(), $comparison->get_reason() );
	}

	/**
	 * One side: availability, the reason, times and the run's evidence.
	 *
	 * @param CheckResult|null $result Result.
	 * @param string|null      $reason Reason (without a result: why there is none; with
	 *                                 an AFTER result: why it is not compared).
	 * @return array
	 */
	private static function side( ?CheckResult $result, $reason ) {
		return array(
			'available'    => null !== $result,
			'reason'       => $reason,
			'started_at'   => null === $result ? null : self::iso( $result->get_started_at() ),
			'completed_at' => null === $result ? null : self::iso( $result->get_completed_at() ),
			'result'       => null === $result ? null : $result->to_report_array(),
		);
	}

	/**
	 * Whole seconds from one time to a later one, or null.
	 *
	 * @param float|null $from Earlier time.
	 * @param float|null $to   Later time.
	 * @return int|null
	 */
	private static function seconds( $from, $to ) {
		return null === $from || null === $to ? null : (int) floor( max( 0.0, $to - $from ) );
	}

	/**
	 * Unix time as UTC ISO 8601 (whole seconds).
	 *
	 * @param float $time Unix time.
	 * @return string
	 */
	private static function iso( $time ) {
		return gmdate( 'Y-m-d\TH:i:s\Z', (int) floor( $time ) );
	}

	/**
	 * Stored UTC DATETIME as Unix time, or null.
	 *
	 * @param mixed $value Stored value.
	 * @return float|null
	 */
	private static function time( $value ) {
		$iso = AnalysisReadModel::timestamp( $value );
		if ( null === $iso ) {
			return null;
		}
		$time = strtotime( $iso );

		return false === $time ? null : (float) $time;
	}
}
