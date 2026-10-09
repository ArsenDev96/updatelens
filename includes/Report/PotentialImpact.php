<?php
/**
 * Potential Impact findings from an analysis's Net result.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Report;

use UpdateLens\Update\ObservationPhase;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates three fixed review rules on a report's Net result (`final`
 * phase). Pure: takes the report phase built by `AnalysisReadModel` (each
 * signal either available with the lists its codec decoded, or unavailable
 * with a reason), never reads WordPress or stored rows, and stores nothing.
 *
 * Findings are observations worth a look, not problems: no severity, no
 * score, and never attribution to the updated plugin. A signal without a Net
 * result is `not_evaluated` with the phase's reason, never "no findings";
 * only an Action Scheduler absent throughout is `not_applicable`.
 *
 * Rules:
 *
 * - `large_autoloaded_option` (Options): an option that is effectively
 *   autoloaded after the update and larger than LARGE_OPTION_BYTES, reached
 *   through one of three transitions: added autoloaded, became autoloaded, or
 *   already autoloaded and grew from at most the threshold to above it.
 *   Options that were already large and autoloaded are not findings. The
 *   threshold is an advisory review size, not a WordPress limit.
 * - `recurring_cron_event_removed` (WP-Cron): per hook, more recurring
 *   instances removed than recurring instances of the same hook added.
 *   Arguments are not in the diff, so a removal plus a recurring addition of
 *   the same hook counts as a replacement. Unchanged instances are never part
 *   of a diff: the evidence describes recorded changes only and never says
 *   whether the hook has any instance left.
 * - `recurring_schedule_changed` (WP-Cron, Action Scheduler): a changed entry
 *   (same hook and arguments, and group for Action Scheduler) that was
 *   recurring before and whose recurrence configuration differs: no longer
 *   recurring, another known interval, another schedule type (interval ↔
 *   cron), or a cron expression that is not equivalent. WP-Cron schedule
 *   renames with the same interval, changes with an unknown interval, Action
 *   Scheduler cron expressions written differently but meaning the same,
 *   status changes and next-run rescheduling are not findings.
 *
 * Output contains only option names, hooks, groups, timestamps, schedules,
 * intervals, cron expressions, sizes and autoload states that the decoded
 * diffs already hold: never option values, arguments or fingerprints.
 */
final class PotentialImpact {

	/**
	 * Rule: large autoloaded option.
	 */
	const LARGE_AUTOLOADED_OPTION = 'large_autoloaded_option';

	/**
	 * Rule: recurring WP-Cron event instance no longer observed.
	 */
	const RECURRING_CRON_EVENT_REMOVED = 'recurring_cron_event_removed';

	/**
	 * Rule: recurring schedule configuration changed.
	 */
	const RECURRING_SCHEDULE_CHANGED = 'recurring_schedule_changed';

	/**
	 * Signals, as in the reports API.
	 */
	const SIGNAL_OPTIONS          = 'options';
	const SIGNAL_CRON             = 'cron';
	const SIGNAL_ACTION_SCHEDULER = 'action_scheduler';

	/**
	 * Evaluation statuses (per signal and overall).
	 *
	 * - `evaluated`:      every rule of the signal ran on its Net result
	 *                     (overall: every applicable signal, at least one).
	 * - `partial`:        overall only; some signals evaluated, others not.
	 * - `not_evaluated`:  no Net result to evaluate (overall: for any signal).
	 * - `not_applicable`: per signal, Action Scheduler only; known to be
	 *                     absent at all three captures (see evaluate()).
	 *                     Ignored by the overall status.
	 */
	const EVALUATED      = 'evaluated';
	const PARTIAL        = 'partial';
	const NOT_EVALUATED  = 'not_evaluated';
	const NOT_APPLICABLE = 'not_applicable';

	/**
	 * Advisory review size in bytes: an option counts as large above it.
	 *
	 * The same number as core's default `wp_max_autoloaded_option_size`, used
	 * here only as a review threshold; it is not read from or tied to WordPress.
	 */
	const LARGE_OPTION_BYTES = 150000;

	/**
	 * `large_autoloaded_option` transitions.
	 */
	const TRANSITION_ADDED               = 'added';
	const TRANSITION_BECAME_AUTOLOADED   = 'became_autoloaded';
	const TRANSITION_GREW_PAST_THRESHOLD = 'grew_past_threshold';

	/**
	 * `recurring_schedule_changed` kinds.
	 *
	 * - `no_longer_recurring`:     recurring before, one-time (or async) after.
	 * - `interval_changed`:        recurring by interval on both sides, both known and different.
	 * - `schedule_type_changed`:   Action Scheduler only; interval ↔ cron expression.
	 * - `cron_expression_changed`: Action Scheduler only; cron on both sides, not equivalent.
	 */
	const CHANGE_NO_LONGER_RECURRING = 'no_longer_recurring';
	const CHANGE_INTERVAL            = 'interval_changed';
	const CHANGE_SCHEDULE_TYPE       = 'schedule_type_changed';
	const CHANGE_CRON_EXPRESSION     = 'cron_expression_changed';

	/**
	 * Rules per signal, in output order.
	 */
	const RULES = array(
		self::SIGNAL_OPTIONS          => array( self::LARGE_AUTOLOADED_OPTION ),
		self::SIGNAL_CRON             => array( self::RECURRING_CRON_EVENT_REMOVED, self::RECURRING_SCHEDULE_CHANGED ),
		self::SIGNAL_ACTION_SCHEDULER => array( self::RECURRING_SCHEDULE_CHANGED ),
	);

	/**
	 * Cron expression names, as numbers (month field, day-of-week field).
	 */
	const MONTH_NAMES = array(
		'JAN' => '1',
		'FEB' => '2',
		'MAR' => '3',
		'APR' => '4',
		'MAY' => '5',
		'JUN' => '6',
		'JUL' => '7',
		'AUG' => '8',
		'SEP' => '9',
		'OCT' => '10',
		'NOV' => '11',
		'DEC' => '12',
	);
	const DAY_NAMES   = array(
		'SUN' => '0',
		'MON' => '1',
		'TUE' => '2',
		'WED' => '3',
		'THU' => '4',
		'FRI' => '5',
		'SAT' => '6',
	);

	/**
	 * Evaluate a report's Net result.
	 *
	 * Findings are ordered by signal (RULES order), then rule, then subject:
	 * option names and hooks byte-wise; schedule changes in the decoded diff's
	 * canonical order, which sorts by hook first.
	 *
	 * Only `final` is evaluated. An Action Scheduler without a Net result is
	 * `not_applicable` only when the caller knows it was absent at every
	 * capture (BEFORE, IMMEDIATE and SETTLED): then there was no Action
	 * Scheduler state whose recurring schedules could change. A report's
	 * `not_installed` reasons alone cannot show that (older analyses stored
	 * it after the first capture of a phase only), and appearing, no longer
	 * detected, failures, corrupt data and lifecycle reasons never are.
	 *
	 * @param array<string, array> $phases                   Report phases (`during_update`, `post_update`, `final`):
	 *                                                       each `options`, `cron`, `action_scheduler`, `available`
	 *                                                       with decoded lists or with a `reason`.
	 * @param bool                 $action_scheduler_absent  Whether Action Scheduler is known to have been
	 *                                                       absent at all three captures.
	 * @return array{phase: string, status: string, signals: array<string, array>, findings: array<int, array>}
	 */
	public function evaluate( array $phases, $action_scheduler_absent = false ) {
		$phase       = isset( $phases[ ObservationPhase::FINAL ] ) && is_array( $phases[ ObservationPhase::FINAL ] ) ? $phases[ ObservationPhase::FINAL ] : array();
		$signals     = array();
		$findings    = array();
		$evaluated   = 0;
		$unevaluated = 0;

		foreach ( self::RULES as $signal => $rules ) {
			$data = isset( $phase[ $signal ] ) && is_array( $phase[ $signal ] ) ? $phase[ $signal ] : array();

			if ( empty( $data['available'] ) ) {
				$reason       = isset( $data['reason'] ) && is_string( $data['reason'] ) ? $data['reason'] : UnavailableReason::NOT_RECORDED;
				$applicable   = ! ( self::SIGNAL_ACTION_SCHEDULER === $signal && true === $action_scheduler_absent );
				$unevaluated += $applicable ? 1 : 0;

				$signals[ $signal ] = array(
					'status'        => $applicable ? self::NOT_EVALUATED : self::NOT_APPLICABLE,
					'reason'        => $reason,
					'rules'         => $rules,
					'finding_count' => null,
				);
				continue;
			}

			$found = array();
			foreach ( $rules as $rule ) {
				foreach ( self::run( $rule, $signal, $data ) as $finding ) {
					$found[] = $finding;
				}
			}

			$signals[ $signal ] = array(
				'status'        => self::EVALUATED,
				'reason'        => null,
				'rules'         => $rules,
				'finding_count' => count( $found ),
			);
			$findings           = array_merge( $findings, $found );
			++$evaluated;
		}

		if ( 0 === $evaluated ) {
			$status = self::NOT_EVALUATED;
		} else {
			$status = 0 === $unevaluated ? self::EVALUATED : self::PARTIAL;
		}

		return array(
			'phase'    => ObservationPhase::FINAL,
			'status'   => $status,
			'signals'  => $signals,
			'findings' => $findings,
		);
	}

	/**
	 * Findings of one rule on one signal's decoded diff.
	 *
	 * @param string $rule   Rule.
	 * @param string $signal Signal.
	 * @param array  $diff   Decoded diff lists.
	 * @return array<int, array>
	 */
	private static function run( $rule, $signal, array $diff ) {
		switch ( $rule ) {
			case self::LARGE_AUTOLOADED_OPTION:
				return self::large_autoloaded_options( $diff );
			case self::RECURRING_CRON_EVENT_REMOVED:
				return self::removed_recurring_cron_events( $diff );
			default:
				return self::changed_recurring_schedules( $diff, $signal );
		}
	}

	/**
	 * Large autoloaded options reached through a relevant transition.
	 *
	 * @param array $diff Decoded Options diff.
	 * @return array<int, array>
	 */
	private static function large_autoloaded_options( array $diff ) {
		$findings = array();

		foreach ( $diff['added'] as $option ) {
			if ( $option['is_autoloaded'] && $option['size'] > self::LARGE_OPTION_BYTES ) {
				$findings[] = self::large_option(
					$option['name'],
					self::TRANSITION_ADDED,
					null,
					array(
						'size'          => $option['size'],
						'autoload'      => $option['autoload'],
						'is_autoloaded' => true,
					),
					null,
					null
				);
			}
		}

		foreach ( $diff['changed'] as $option ) {
			if ( ! $option['after_is_autoloaded'] || $option['after_size'] <= self::LARGE_OPTION_BYTES ) {
				continue;
			}
			if ( ! $option['before_is_autoloaded'] ) {
				$transition = self::TRANSITION_BECAME_AUTOLOADED;
			} elseif ( $option['before_size'] <= self::LARGE_OPTION_BYTES ) {
				$transition = self::TRANSITION_GREW_PAST_THRESHOLD;
			} else {
				continue; // Already large and autoloaded.
			}
			$findings[] = self::large_option(
				$option['name'],
				$transition,
				array(
					'size'          => $option['before_size'],
					'autoload'      => $option['before_autoload'],
					'is_autoloaded' => $option['before_is_autoloaded'],
				),
				array(
					'size'          => $option['after_size'],
					'autoload'      => $option['after_autoload'],
					'is_autoloaded' => true,
				),
				$option['size_delta'],
				$option['value_changed']
			);
		}

		usort(
			$findings,
			static function ( array $a, array $b ) {
				return strcmp( $a['option'], $b['option'] );
			}
		);

		return $findings;
	}

	/**
	 * A `large_autoloaded_option` finding.
	 *
	 * @param string     $name          Option name.
	 * @param string     $transition    TRANSITION_* constant.
	 * @param array|null $before        Size and autoload before, null if added.
	 * @param array      $after         Size and autoload after.
	 * @param int|null   $size_delta    Signed size change, null if added.
	 * @param bool|null  $value_changed Whether the value changed, null if added.
	 * @return array
	 */
	private static function large_option( $name, $transition, $before, array $after, $size_delta, $value_changed ) {
		return array(
			'code'     => self::LARGE_AUTOLOADED_OPTION,
			'signal'   => self::SIGNAL_OPTIONS,
			'option'   => $name,
			'evidence' => array(
				'transition'      => $transition,
				'threshold_bytes' => self::LARGE_OPTION_BYTES,
				'size_delta'      => $size_delta,
				'value_changed'   => $value_changed,
			),
			'before'   => $before,
			'after'    => $after,
		);
	}

	/**
	 * Hooks with more recurring instances removed than recurring instances added.
	 *
	 * One pass over each list, so large diffs stay linear.
	 *
	 * @param array $diff Decoded WP-Cron diff.
	 * @return array<int, array>
	 */
	private static function removed_recurring_cron_events( array $diff ) {
		// Keys are prefixed so numeric hook names stay strings.
		$removed = array();
		foreach ( $diff['removed'] as $event ) {
			if ( $event['is_recurring'] ) {
				$removed[ 'h' . $event['hook'] ][] = self::cron_instance( $event );
			}
		}
		if ( array() === $removed ) {
			return array();
		}

		$added_recurring = array();
		$other           = array();
		foreach ( $diff['added'] as $event ) {
			$key = 'h' . $event['hook'];
			if ( ! isset( $removed[ $key ] ) ) {
				continue;
			}
			if ( $event['is_recurring'] ) {
				$added_recurring[ $key ][] = self::cron_instance( $event );
			} else {
				self::increment( $other, $key, 'added_one_time' );
			}
		}
		foreach ( array( 'rescheduled', 'changed' ) as $list ) {
			foreach ( $diff[ $list ] as $event ) {
				$key = 'h' . $event['hook'];
				if ( isset( $removed[ $key ] ) ) {
					self::increment( $other, $key, $list );
				}
			}
		}

		$findings = array();
		foreach ( $removed as $key => $instances ) {
			$added = isset( $added_recurring[ $key ] ) ? $added_recurring[ $key ] : array();
			if ( count( $instances ) <= count( $added ) ) {
				continue; // Replaced by as many recurring instances of the same hook.
			}

			$findings[] = array(
				'code'     => self::RECURRING_CRON_EVENT_REMOVED,
				'signal'   => self::SIGNAL_CRON,
				'hook'     => (string) substr( $key, 1 ),
				'evidence' => array(
					'removed_recurring_count' => count( $instances ),
					'added_recurring_count'   => count( $added ),
					'not_replaced_count'      => count( $instances ) - count( $added ),
					'other_recorded_changes'  => array(
						'added_one_time' => isset( $other[ $key ]['added_one_time'] ) ? $other[ $key ]['added_one_time'] : 0,
						'rescheduled'    => isset( $other[ $key ]['rescheduled'] ) ? $other[ $key ]['rescheduled'] : 0,
						'changed'        => isset( $other[ $key ]['changed'] ) ? $other[ $key ]['changed'] : 0,
					),
				),
				'before'   => array( 'removed_recurring' => $instances ),
				'after'    => array( 'added_recurring' => $added ),
			);
		}

		usort(
			$findings,
			static function ( array $a, array $b ) {
				return strcmp( $a['hook'], $b['hook'] );
			}
		);

		return $findings;
	}

	/**
	 * Schedule of one WP-Cron instance as evidence.
	 *
	 * @param array $event Added or removed event.
	 * @return array{timestamp: int, schedule: string|null, interval: int|null}
	 */
	private static function cron_instance( array $event ) {
		return array(
			'timestamp' => $event['timestamp'],
			'schedule'  => $event['schedule'],
			'interval'  => $event['interval'],
		);
	}

	/**
	 * Increment a per-hook counter.
	 *
	 * @param array  $counts Counters (modified).
	 * @param string $key    Hook key.
	 * @param string $name   Counter.
	 * @return void
	 */
	private static function increment( array &$counts, $key, $name ) {
		$counts[ $key ][ $name ] = isset( $counts[ $key ][ $name ] ) ? $counts[ $key ][ $name ] + 1 : 1;
	}

	/**
	 * Changed entries whose recurrence configuration changed.
	 *
	 * @param array  $diff   Decoded WP-Cron or Action Scheduler diff.
	 * @param string $signal SIGNAL_CRON or SIGNAL_ACTION_SCHEDULER.
	 * @return array<int, array>
	 */
	private static function changed_recurring_schedules( array $diff, $signal ) {
		$findings = array();

		foreach ( $diff['changed'] as $entry ) {
			$change = self::SIGNAL_CRON === $signal ? self::cron_change( $entry ) : self::action_scheduler_change( $entry );
			if ( null === $change ) {
				continue;
			}

			$finding = array(
				'code'   => self::RECURRING_SCHEDULE_CHANGED,
				'signal' => $signal,
				'hook'   => $entry['hook'],
			);
			if ( self::SIGNAL_ACTION_SCHEDULER === $signal ) {
				$finding['group'] = $entry['group'];
			}
			$finding['evidence'] = array( 'change' => $change );
			$finding['before']   = self::side( $entry, 'before_', $signal );
			$finding['after']    = self::side( $entry, 'after_', $signal );

			$findings[] = $finding;
		}

		// Decoded lists are already in canonical order (hook first, byte-wise).
		return $findings;
	}

	/**
	 * Kind of a WP-Cron recurrence change, or null if it is not a finding.
	 *
	 * @param array $entry Changed WP-Cron entry.
	 * @return string|null
	 */
	private static function cron_change( array $entry ) {
		if ( ! $entry['before_is_recurring'] ) {
			return null;
		}
		if ( ! $entry['after_is_recurring'] ) {
			return self::CHANGE_NO_LONGER_RECURRING;
		}
		if ( null === $entry['before_interval'] || null === $entry['after_interval'] ) {
			return null; // A schedule name alone does not tell whether the recurrence changed.
		}

		return $entry['before_interval'] !== $entry['after_interval'] ? self::CHANGE_INTERVAL : null;
	}

	/**
	 * Kind of an Action Scheduler recurrence change, or null if it is not a finding.
	 *
	 * Compares the recurrence itself: schedule type, interval and the
	 * equivalent form of the cron expression. The scheduled time and the
	 * status are never compared.
	 *
	 * @param array $entry Changed Action Scheduler entry.
	 * @return string|null
	 */
	private static function action_scheduler_change( array $entry ) {
		if ( ! $entry['before_is_recurring'] ) {
			return null;
		}
		if ( ! $entry['after_is_recurring'] ) {
			return self::CHANGE_NO_LONGER_RECURRING;
		}
		if ( $entry['before_schedule_type'] !== $entry['after_schedule_type'] ) {
			return self::CHANGE_SCHEDULE_TYPE;
		}
		if ( null !== $entry['before_cron_expression'] || null !== $entry['after_cron_expression'] ) {
			return self::equivalent_cron_expression( (string) $entry['before_cron_expression'] ) === self::equivalent_cron_expression( (string) $entry['after_cron_expression'] )
				? null
				: self::CHANGE_CRON_EXPRESSION;
		}

		return $entry['before_interval'] !== $entry['after_interval'] ? self::CHANGE_INTERVAL : null;
	}

	/**
	 * A cron expression in a form where notations with the same meaning are equal.
	 *
	 * Conservative: only rewrites that keep the meaning for Action Scheduler's
	 * CronExpression: case, `?` (any day), a whole-field step of 1 on `*`,
	 * leading zeros, month and day-of-week names, and a trailing year field `*`.
	 *
	 * @param string $expression Normalized cron expression (fields joined by single spaces).
	 * @return string
	 */
	public static function equivalent_cron_expression( $expression ) {
		$fields = explode( ' ', strtoupper( $expression ) );
		if ( 6 === count( $fields ) && '*' === $fields[5] ) {
			array_pop( $fields );
		}

		foreach ( $fields as $index => $field ) {
			if ( 3 === $index ) {
				$field = strtr( $field, self::MONTH_NAMES );
			} elseif ( 4 === $index ) {
				$field = strtr( $field, self::DAY_NAMES );
			}
			$field = (string) preg_replace( '/(?<![0-9])0+(?=[0-9])/', '', $field );
			if ( '?' === $field || '*/1' === $field ) {
				$field = '*';
			}
			$fields[ $index ] = $field;
		}

		return implode( ' ', $fields );
	}

	/**
	 * One side of a changed entry as evidence.
	 *
	 * @param array  $entry  Changed entry.
	 * @param string $prefix `before_` or `after_`.
	 * @param string $signal SIGNAL_CRON or SIGNAL_ACTION_SCHEDULER.
	 * @return array
	 */
	private static function side( array $entry, $prefix, $signal ) {
		if ( self::SIGNAL_CRON === $signal ) {
			return array(
				'timestamp'    => $entry[ $prefix . 'timestamp' ],
				'schedule'     => $entry[ $prefix . 'schedule' ],
				'interval'     => $entry[ $prefix . 'interval' ],
				'is_recurring' => $entry[ $prefix . 'is_recurring' ],
			);
		}

		return array(
			'timestamp'       => $entry[ $prefix . 'timestamp' ],
			'schedule_type'   => $entry[ $prefix . 'schedule_type' ],
			'interval'        => $entry[ $prefix . 'interval' ],
			'cron_expression' => $entry[ $prefix . 'cron_expression' ],
			'is_recurring'    => $entry[ $prefix . 'is_recurring' ],
		);
	}
}
