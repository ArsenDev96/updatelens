<?php
/**
 * Potential Impact findings from an analysis's Net result.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates three fixed review rules on the decoded Net result (`final`
 * phase) diffs of one analysis. Pure: takes the arrays returned by
 * `OptionsDiffCodec::decode()`, `CronDiffCodec::decode()` and
 * `ActionSchedulerDiffCodec::decode()` (or null when that signal's Net result
 * is unavailable), never reads WordPress or stored rows, and stores nothing.
 *
 * Findings are observations worth a look, not problems: no severity, no
 * score, and never attribution to the updated plugin. A signal without a Net
 * result is reported as `not_evaluated`, never as having no findings.
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
 *   Arguments are not in the diff, so a removal plus an addition of the same
 *   hook is treated as a replacement. Unchanged instances never appear in a
 *   diff, so the evidence cannot prove that a hook has no instance left.
 * - `recurring_schedule_changed` (WP-Cron, Action Scheduler): a changed entry
 *   (same hook and arguments, and group for Action Scheduler) that was recurring
 *   before and whose recurrence differs: no longer recurring, another known
 *   interval, or another Action Scheduler schedule (cron expression). WP-Cron
 *   schedule renames with the same interval and changes with an unknown
 *   interval are not findings. Next-run rescheduling never is.
 *
 * Output contains only hooks, groups, option names, timestamps, schedules,
 * intervals, cron expressions, sizes and autoload states that the decoded
 * diffs already hold: never option values, arguments or fingerprints.
 */
final class PotentialImpact {

	/**
	 * Rule: large autoloaded option.
	 */
	const LARGE_AUTOLOADED_OPTION = 'large_autoloaded_option';

	/**
	 * Rule: recurring WP-Cron event no longer observed.
	 */
	const RECURRING_CRON_EVENT_REMOVED = 'recurring_cron_event_removed';

	/**
	 * Rule: recurring schedule changed.
	 */
	const RECURRING_SCHEDULE_CHANGED = 'recurring_schedule_changed';

	/**
	 * Signals, as in the reports API.
	 */
	const SIGNAL_OPTIONS          = 'options';
	const SIGNAL_CRON             = 'cron';
	const SIGNAL_ACTION_SCHEDULER = 'action_scheduler';

	/**
	 * Check statuses.
	 */
	const EVALUATED     = 'evaluated';
	const NOT_EVALUATED = 'not_evaluated';

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
	 * - `no_longer_recurring`: recurring before, one-time (or async) after.
	 * - `interval_changed`: recurring on both sides, both intervals known and different.
	 * - `schedule_changed`: Action Scheduler only; recurring on both sides with a
	 *   cron expression on at least one side (frequency not computed).
	 */
	const CHANGE_NO_LONGER_RECURRING = 'no_longer_recurring';
	const CHANGE_INTERVAL            = 'interval_changed';
	const CHANGE_SCHEDULE            = 'schedule_changed';

	/**
	 * Checks in output order: rule and the signal it reads.
	 */
	const CHECKS = array(
		array( self::LARGE_AUTOLOADED_OPTION, self::SIGNAL_OPTIONS ),
		array( self::RECURRING_CRON_EVENT_REMOVED, self::SIGNAL_CRON ),
		array( self::RECURRING_SCHEDULE_CHANGED, self::SIGNAL_CRON ),
		array( self::RECURRING_SCHEDULE_CHANGED, self::SIGNAL_ACTION_SCHEDULER ),
	);

	/**
	 * Evaluate the Net result.
	 *
	 * Returns `checks` (every rule/signal pair in CHECKS order with its status)
	 * and `findings` (in CHECKS order; within a check by option name or hook,
	 * byte-wise; schedule changes in the decoded diff's canonical order, which
	 * sorts by hook first).
	 *
	 * @param array|null $options          Decoded Options Net diff, or null if unavailable.
	 * @param array|null $cron             Decoded WP-Cron Net diff, or null if unavailable.
	 * @param array|null $action_scheduler Decoded Action Scheduler Net diff, or null if unavailable.
	 * @return array{checks: array<int, array{rule: string, signal: string, status: string}>, findings: array<int, array>}
	 */
	public function evaluate( ?array $options, ?array $cron, ?array $action_scheduler ) {
		$diffs = array(
			self::SIGNAL_OPTIONS          => $options,
			self::SIGNAL_CRON             => $cron,
			self::SIGNAL_ACTION_SCHEDULER => $action_scheduler,
		);

		$checks   = array();
		$findings = array();
		foreach ( self::CHECKS as $check ) {
			list( $rule, $signal ) = $check;
			$diff                  = $diffs[ $signal ];

			$checks[] = array(
				'rule'   => $rule,
				'signal' => $signal,
				'status' => null === $diff ? self::NOT_EVALUATED : self::EVALUATED,
			);
			if ( null === $diff ) {
				continue;
			}

			switch ( $rule ) {
				case self::LARGE_AUTOLOADED_OPTION:
					$found = self::large_autoloaded_options( $diff );
					break;
				case self::RECURRING_CRON_EVENT_REMOVED:
					$found = self::removed_recurring_cron_events( $diff );
					break;
				default:
					$found = self::changed_recurring_schedules( $diff, $signal );
			}
			foreach ( $found as $finding ) {
				$findings[] = $finding;
			}
		}

		return array(
			'checks'   => $checks,
			'findings' => $findings,
		);
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
				$findings[] = self::large_option( $option['name'], self::TRANSITION_ADDED, null, $option['size'], null, $option['autoload'], null );
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
				$option['before_size'],
				$option['after_size'],
				$option['before_autoload'],
				$option['after_autoload'],
				$option['before_is_autoloaded']
			);
		}

		usort(
			$findings,
			static function ( array $a, array $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return $findings;
	}

	/**
	 * A `large_autoloaded_option` finding.
	 *
	 * @param string      $name                 Option name.
	 * @param string      $transition           TRANSITION_* constant.
	 * @param int|null    $before_size          Size before, null if added.
	 * @param int         $after_size           Size after.
	 * @param string|null $before_autoload      Raw autoload before, null if added.
	 * @param string      $after_autoload       Raw autoload after.
	 * @param bool|null   $before_is_autoloaded Effective autoload before, null if added.
	 * @return array
	 */
	private static function large_option( $name, $transition, $before_size, $after_size, $before_autoload, $after_autoload, $before_is_autoloaded ) {
		return array(
			'code'                 => self::LARGE_AUTOLOADED_OPTION,
			'signal'               => self::SIGNAL_OPTIONS,
			'name'                 => $name,
			'transition'           => $transition,
			'threshold_bytes'      => self::LARGE_OPTION_BYTES,
			'before_size'          => $before_size,
			'after_size'           => $after_size,
			'before_autoload'      => $before_autoload,
			'after_autoload'       => $after_autoload,
			'before_is_autoloaded' => $before_is_autoloaded,
			'after_is_autoloaded'  => true,
		);
	}

	/**
	 * Hooks with more recurring instances removed than recurring instances added.
	 *
	 * @param array $diff Decoded WP-Cron diff.
	 * @return array<int, array>
	 */
	private static function removed_recurring_cron_events( array $diff ) {
		// Keys are prefixed so numeric hook names stay strings.
		$hooks = array();
		foreach ( $diff['removed'] as $event ) {
			if ( $event['is_recurring'] ) {
				$hooks[ 'h' . $event['hook'] ][] = array(
					'timestamp' => $event['timestamp'],
					'schedule'  => $event['schedule'],
					'interval'  => $event['interval'],
				);
			}
		}

		$findings = array();
		foreach ( $hooks as $key => $removed ) {
			$hook            = (string) substr( $key, 1 );
			$added_recurring = 0;
			$still_observed  = 0;
			foreach ( $diff['added'] as $event ) {
				if ( $hook === $event['hook'] ) {
					++$still_observed;
					if ( $event['is_recurring'] ) {
						++$added_recurring;
					}
				}
			}
			foreach ( array( 'rescheduled', 'changed' ) as $list ) {
				foreach ( $diff[ $list ] as $event ) {
					if ( $hook === $event['hook'] ) {
						++$still_observed;
					}
				}
			}

			if ( count( $removed ) <= $added_recurring ) {
				continue; // Replaced by as many recurring instances of the same hook.
			}

			$findings[] = array(
				'code'                  => self::RECURRING_CRON_EVENT_REMOVED,
				'signal'                => self::SIGNAL_CRON,
				'hook'                  => $hook,
				'removed'               => $removed,
				'removed_count'         => count( $removed ),
				'added_recurring_count' => $added_recurring,
				'still_observed_count'  => $still_observed,
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
	 * Changed entries whose recurring schedule changed reliably.
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
			$finding['change'] = $change;
			$finding['before'] = self::side( $entry, 'before_', $signal );
			$finding['after']  = self::side( $entry, 'after_', $signal );

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
	 * Kind of an Action Scheduler schedule change, or null if it is not a finding.
	 *
	 * The codec guarantees that type, interval or cron expression differ.
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
		if ( null !== $entry['before_interval'] && null !== $entry['after_interval'] ) {
			return self::CHANGE_INTERVAL;
		}

		return self::CHANGE_SCHEDULE;
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
