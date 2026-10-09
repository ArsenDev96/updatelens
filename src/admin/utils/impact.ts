import { _n, __, sprintf } from '@wordpress/i18n';

import type {
	ImpactFinding,
	PotentialImpact,
	Provider,
	ScheduleChange,
} from '../types/api';
import { formatBytes, formatCount } from './format';
import {
	actionSchedulerUnavailableReasonText,
	cronUnavailableReasonText,
	providerLabel,
	unavailableReasonText,
	windowText,
} from './labels';

/*
 * Wording of Potential Impact: the API's rule codes, statuses and evidence
 * mapped to localized text. Nothing is decided here: which findings exist,
 * which signals were evaluated and why not all come from the API.
 *
 * Every finding answers three questions: what changed (from its evidence),
 * why it might matter and what to check next (per rule and signal). Wording
 * is calm and observational: a finding is worth a look, never a confirmed
 * problem, and never attributed to the updated plugin.
 */

/** Findings of one rule and signal, in API order. */
export interface FindingGroup {
	key: string;
	code: ImpactFinding[ 'code' ];
	signal: Provider;
	findings: ImpactFinding[];
}

/**
 * Findings grouped by rule and signal, keeping the API's order (signal,
 * rule, subject).
 *
 * @param findings API findings.
 */
export function groupFindings( findings: ImpactFinding[] ): FindingGroup[] {
	const groups: FindingGroup[] = [];
	const byKey = new Map< string, FindingGroup >();
	for ( const finding of findings ) {
		const key = `${ finding.code }:${ finding.signal }`;
		let group = byKey.get( key );
		if ( ! group ) {
			group = {
				key,
				code: finding.code,
				signal: finding.signal,
				findings: [],
			};
			byKey.set( key, group );
			groups.push( group );
		}
		group.findings.push( finding );
	}
	return groups;
}

/**
 * Title of a group of findings.
 *
 * @param group Findings of one rule and signal.
 */
export function findingGroupTitle( group: FindingGroup ): string {
	const count = group.findings.length;
	switch ( group.code ) {
		case 'large_autoloaded_option':
			return _n(
				'Large autoloaded option',
				'Large autoloaded options',
				count,
				'updatelens'
			);
		case 'recurring_cron_event_removed':
			return _n(
				'Recurring WP-Cron event no longer observed',
				'Recurring WP-Cron events no longer observed',
				count,
				'updatelens'
			);
		case 'recurring_schedule_changed':
			return group.signal === 'action_scheduler'
				? _n(
						'Recurring Action Scheduler schedule changed',
						'Recurring Action Scheduler schedules changed',
						count,
						'updatelens'
					)
				: _n(
						'Recurring WP-Cron schedule changed',
						'Recurring WP-Cron schedules changed',
						count,
						'updatelens'
					);
	}
}

/**
 * The review size of the large-option rule, e.g. "150,000 bytes (146.5 KB)".
 *
 * @param bytes Threshold from the API's evidence.
 */
export function thresholdText( bytes: number ): string {
	return sprintf(
		/* translators: 1: exact number of bytes, e.g. "150,000". 2: the same size in KB, e.g. "146.5 KB". */
		__( '%1$s bytes (%2$s)', 'updatelens' ),
		formatCount( bytes ),
		formatBytes( bytes )
	);
}

/**
 * Why a group of findings might matter: plain, conditional sentences.
 *
 * @param group Findings of one rule and signal.
 */
export function whyItMatters( group: FindingGroup ): string[] {
	const changes = scheduleChanges( group );
	switch ( group.code ) {
		case 'large_autoloaded_option': {
			const first = group.findings[ 0 ];
			const threshold =
				first.code === 'large_autoloaded_option'
					? first.evidence.threshold_bytes
					: 0;
			return [
				__(
					'WordPress loads every autoloaded option on each page request, whether or not the page uses it. A large autoloaded option adds to that work and can slow requests down, especially on sites without a persistent object cache.',
					'updatelens'
				),
				sprintf(
					/* translators: %s: review size, e.g. "150,000 bytes (146.5 KB)". */
					__(
						'UpdateLens points out autoloaded options larger than %s. This is a review size, not a WordPress limit.',
						'updatelens'
					),
					thresholdText( threshold )
				),
			];
		}
		case 'recurring_cron_event_removed':
			return [
				__(
					'A recurring event repeats a task on a schedule, such as syncing data, cleaning up or sending email. If it was removed unintentionally, that task would no longer run by itself. It may also have been removed or replaced on purpose.',
					'updatelens'
				),
				__(
					'Instances that did not change are not part of the report, so this does not show whether the hook still has other scheduled events.',
					'updatelens'
				),
			];
		case 'recurring_schedule_changed': {
			const sentences: string[] =
				group.signal === 'action_scheduler'
					? [
							__(
								'The schedule decides when Action Scheduler runs this action. A different schedule can change when or how often it runs.',
								'updatelens'
							),
						]
					: [
							__(
								'The schedule decides how often WordPress runs this task.',
								'updatelens'
							),
						];
			if ( changes.has( 'interval_changed' ) ) {
				sentences.push(
					__(
						'A different interval changes how often the task runs: more often adds background work, less often delays it.',
						'updatelens'
					)
				);
			}
			if (
				changes.has( 'cron_expression_changed' ) ||
				changes.has( 'schedule_type_changed' )
			) {
				sentences.push(
					__(
						'UpdateLens compares the stored schedules only; it does not calculate how often a cron expression runs.',
						'updatelens'
					)
				);
			}
			if ( changes.has( 'no_longer_recurring' ) ) {
				sentences.push(
					__(
						'A one-time job runs once and does not repeat unless something schedules it again.',
						'updatelens'
					)
				);
			}
			return sentences;
		}
	}
}

/**
 * What to check next for a group of findings.
 *
 * @param group Findings of one rule and signal.
 */
export function whatToCheck( group: FindingGroup ): string[] {
	const changes = scheduleChanges( group );
	switch ( group.code ) {
		case 'large_autoloaded_option':
			return [
				__(
					'Check whether this option needs to be loaded on every request. If you recognize the option name, the related plugin’s settings or documentation may explain what it stores.',
					'updatelens'
				),
				__(
					'Compare its size with the site’s total autoloaded data in Options & autoload.',
					'updatelens'
				),
				__(
					'If it keeps growing after later updates, ask the developer of the plugin that uses it whether it can be stored without autoload.',
					'updatelens'
				),
			];
		case 'recurring_cron_event_removed':
			return [
				__(
					'Check whether the hook still has scheduled events, for example with WP-CLI (wp cron event list) or a cron management plugin.',
					'updatelens'
				),
				__(
					'Look for a replacement: an event added under another hook name appears in WP-Cron’s added events.',
					'updatelens'
				),
				__(
					'If you recognize the hook, check the related plugin’s release notes or settings for changes to scheduled tasks.',
					'updatelens'
				),
			];
		case 'recurring_schedule_changed': {
			const steps: string[] = [
				__(
					'Check whether the new schedule is intended, for example in the related plugin’s settings or release notes.',
					'updatelens'
				),
			];
			if ( changes.has( 'cron_expression_changed' ) ) {
				steps.push(
					__(
						'Read the cron expressions to confirm when the action runs; they are shown exactly as stored.',
						'updatelens'
					)
				);
			}
			if ( changes.has( 'no_longer_recurring' ) ) {
				steps.push(
					__(
						'If the task should keep repeating, check after its next run whether it was scheduled again.',
						'updatelens'
					)
				);
			}
			steps.push(
				sprintf(
					/* translators: %s: signal name, e.g. "WP-Cron". */
					__(
						'Open %s to see the next scheduled run.',
						'updatelens'
					),
					providerLabel( group.signal )
				)
			);
			return steps;
		}
	}
}

/**
 * Change kinds of the schedule findings in a group.
 *
 * @param group Findings of one rule and signal.
 */
function scheduleChanges( group: FindingGroup ): Set< ScheduleChange > {
	const changes = new Set< ScheduleChange >();
	for ( const finding of group.findings ) {
		if ( finding.code === 'recurring_schedule_changed' ) {
			changes.add( finding.evidence.change );
		}
	}
	return changes;
}

/**
 * One-sentence summary of what changed for a finding.
 *
 * @param finding Finding.
 */
export function whatChanged( finding: ImpactFinding ): string {
	switch ( finding.code ) {
		case 'large_autoloaded_option':
			switch ( finding.evidence.transition ) {
				case 'added':
					return __( 'Added as an autoloaded option.', 'updatelens' );
				case 'became_autoloaded':
					return finding.evidence.value_changed
						? __(
								'Became autoloaded, and its value changed.',
								'updatelens'
							)
						: __(
								'Became autoloaded; its value did not change.',
								'updatelens'
							);
				case 'grew_past_threshold':
					return __(
						'Already autoloaded; grew past the review size.',
						'updatelens'
					);
			}
			break;
		case 'recurring_cron_event_removed': {
			const removed = finding.evidence.removed_recurring_count;
			return sprintf(
				/* translators: %s: number of recurring WP-Cron event instances. */
				_n(
					'%s recurring instance scheduled before the update was not observed after it.',
					'%s recurring instances scheduled before the update were not observed after it.',
					removed,
					'updatelens'
				),
				formatCount( removed )
			);
		}
		case 'recurring_schedule_changed':
			return scheduleChangeText(
				finding.evidence.change,
				finding.signal,
				finding.after.is_recurring
					? null
					: finding.signal === 'action_scheduler' &&
							finding.after.schedule_type === 'async'
			);
	}
	return '';
}

/**
 * Summary of a schedule change.
 *
 * @param change     Change kind.
 * @param signal     Signal.
 * @param afterAsync For no-longer-recurring Action Scheduler actions:
 *                   whether the action became async.
 */
function scheduleChangeText(
	change: ScheduleChange,
	signal: Provider,
	afterAsync: boolean | null
): string {
	switch ( change ) {
		case 'interval_changed':
			return __( 'The recurring interval changed.', 'updatelens' );
		case 'schedule_type_changed':
			return __(
				'The schedule type changed between an interval and a cron expression.',
				'updatelens'
			);
		case 'cron_expression_changed':
			return __( 'The cron expression changed.', 'updatelens' );
		case 'no_longer_recurring':
			if ( signal === 'cron' ) {
				return __(
					'Changed from a recurring event to a one-time event.',
					'updatelens'
				);
			}
			return afterAsync
				? __(
						'Changed from a recurring action to an async action that runs once.',
						'updatelens'
					)
				: __(
						'Changed from a recurring action to a one-time action.',
						'updatelens'
					);
	}
}

/**
 * Extra facts about a removed recurring WP-Cron event, from the other
 * recorded changes of its hook. Never claims the hook has no events left.
 *
 * @param finding Finding.
 */
export function cronRemovalContext( finding: ImpactFinding ): string[] {
	if ( finding.code !== 'recurring_cron_event_removed' ) {
		return [];
	}
	const { added_recurring_count: added, other_recorded_changes: other } =
		finding.evidence;
	const notes: string[] = [];
	if ( added > 0 ) {
		notes.push(
			sprintf(
				/* translators: %s: number of recurring WP-Cron event instances. */
				_n(
					'%s recurring instance of the same hook was added.',
					'%s recurring instances of the same hook were added.',
					added,
					'updatelens'
				),
				formatCount( added )
			)
		);
	}
	if ( other.rescheduled + other.changed > 0 ) {
		notes.push(
			__(
				'At least one other instance of this hook is still scheduled.',
				'updatelens'
			)
		);
	}
	if ( other.added_one_time > 0 ) {
		notes.push(
			sprintf(
				/* translators: %s: number of one-time WP-Cron events. */
				_n(
					'%s one-time event of the same hook was added.',
					'%s one-time events of the same hook were added.',
					other.added_one_time,
					'updatelens'
				),
				formatCount( other.added_one_time )
			)
		);
	}
	return notes;
}

/** Overall state of the Potential Impact section. */
export type ImpactState =
	'findings' | 'none' | 'waiting' | 'expired' | 'unavailable';

/**
 * Which state the section shows, from the API's status and reasons.
 *
 * @param impact API evaluation.
 */
export function impactState( impact: PotentialImpact ): ImpactState {
	if ( impact.status !== 'not_evaluated' ) {
		return impact.findings.length > 0 ? 'findings' : 'none';
	}
	// Options drives the analysis lifecycle, so its reason explains the report.
	switch ( impact.signals.options.reason ) {
		case 'awaiting_settle':
		case 'update_in_progress':
			return 'waiting';
		case 'settle_expired':
			return 'expired';
		default:
			return 'unavailable';
	}
}

/**
 * Headline of the section.
 *
 * @param impact API evaluation.
 * @param state  Section state.
 */
export function impactHeadline(
	impact: PotentialImpact,
	state: ImpactState
): string {
	switch ( state ) {
		case 'findings':
			return sprintf(
				/* translators: %s: number of Potential Impact findings. */
				_n(
					'%s finding to review',
					'%s findings to review',
					impact.findings.length,
					'updatelens'
				),
				formatCount( impact.findings.length )
			);
		case 'none':
			return __(
				'No Potential Impact patterns matched the available observations.',
				'updatelens'
			);
		case 'waiting':
			return __( 'Waiting for the Net result', 'updatelens' );
		case 'expired':
		case 'unavailable':
			return __( 'Not available for this report', 'updatelens' );
	}
}

/**
 * Sentences under the headline.
 *
 * @param impact        API evaluation.
 * @param state         Section state.
 * @param windowSeconds Observation window length from the API.
 */
export function impactDescription(
	impact: PotentialImpact,
	state: ImpactState,
	windowSeconds: number
): string {
	switch ( state ) {
		case 'findings':
			return __(
				'Changes in the Net result that match patterns worth a closer look. They are not confirmed problems, and they may not come from the updated plugin.',
				'updatelens'
			);
		case 'none':
			return __(
				'Only a few specific patterns are checked, so this does not show that the update had no other effects.',
				'updatelens'
			);
		case 'waiting':
			return __(
				'Potential Impact checks the Net result, which is available once the post-update observation has been captured. Refresh the report later to see it.',
				'updatelens'
			);
		case 'expired':
			return sprintf(
				/* translators: %s: observation window length, e.g. "5 minutes". */
				__(
					'The post-update observation was not captured within %s, so there is no Net result to check. Changes observed during the update are still listed in the During update phase.',
					'updatelens'
				),
				windowText( windowSeconds )
			);
		case 'unavailable':
			return sprintf(
				/* translators: %s: why the Net result is unavailable, e.g. "Not available because the plugin update failed." */
				__( 'Potential Impact needs the Net result. %s', 'updatelens' ),
				impactSignalReason(
					'options',
					impact.signals.options.reason,
					windowSeconds
				).description
			);
	}
}

/**
 * How one signal's checks ended, from the API's status and reason:
 *
 * - `checked`:        evaluated (with or without findings);
 * - `not_applicable`: Action Scheduler, known to be absent at every capture;
 * - `unavailable`:    not evaluated because the provider's data was not
 *                     there to compare (not detected, appeared or disappeared,
 *                     unsupported, not captured or recorded);
 * - `failed`:         not evaluated because something went wrong (malformed or
 *                     unreadable data, storage or comparison errors, a failed
 *                     or abandoned update);
 * - `waiting`:        the settled capture has not been taken yet;
 * - `expired`:        the observation ended without a settled capture.
 */
export type CheckCategory =
	| 'checked'
	| 'not_applicable'
	| 'unavailable'
	| 'failed'
	| 'waiting'
	| 'expired';

/** Reasons of a provider whose data was not there to compare. */
const UNAVAILABLE_REASONS = new Set( [
	'not_installed',
	'newly_detected',
	'no_longer_detected',
	'unsupported_store',
	'unsupported_schema',
	'unsupported_schedule',
	'not_captured',
	'not_recorded',
] );

/**
 * Category of one signal's checks.
 *
 * @param signal Signal.
 * @param impact API evaluation.
 */
export function checkCategory(
	signal: Provider,
	impact: PotentialImpact
): CheckCategory {
	const { status, reason } = impact.signals[ signal ];
	if ( status === 'evaluated' ) {
		return 'checked';
	}
	if ( status === 'not_applicable' ) {
		return 'not_applicable';
	}
	switch ( reason ) {
		case 'awaiting_settle':
		case 'update_in_progress':
			return 'waiting';
		case 'settle_expired':
			return 'expired';
	}
	return reason !== null && UNAVAILABLE_REASONS.has( reason )
		? 'unavailable'
		: 'failed';
}

/** The patterns UpdateLens checks, one per line, for "What was checked". */
export function checkedPatterns(): string[] {
	return [
		__(
			'Options & autoload: options that are autoloaded and larger than the review size after the update.',
			'updatelens'
		),
		__(
			'WP-Cron: recurring events no longer observed, and recurring schedules that changed.',
			'updatelens'
		),
		__(
			'Action Scheduler: recurring schedules that changed.',
			'updatelens'
		),
	];
}

/** What a Potential Impact result does not show, for "What was checked". */
export function checkLimitations(): string[] {
	return [
		__(
			'Checks use the Net result only: the state before the update compared with the state after it settled, whichever phase is selected. Changes undone in between are not seen.',
			'updatelens'
		),
		__(
			'Only the patterns above are checked. No match does not show that the update had no other effects.',
			'updatelens'
		),
		__(
			'Findings are observations worth a look, not confirmed problems, and they may not come from the updated plugin.',
			'updatelens'
		),
	];
}

/**
 * State of one signal's checks: a short status ("Checked", "Not checked
 * (failed)", …), a detail ("2 findings", the reason's title) and a longer
 * description for "What was checked".
 *
 * @param signal        Signal.
 * @param impact        API evaluation.
 * @param windowSeconds Observation window length from the API.
 */
export function impactSignalText(
	signal: Provider,
	impact: PotentialImpact,
	windowSeconds: number
): {
	category: CheckCategory;
	status: string;
	detail: string | null;
	description: string | null;
} {
	const evaluation = impact.signals[ signal ];
	const category = checkCategory( signal, impact );
	const reason = impactSignalReason(
		signal,
		evaluation.reason,
		windowSeconds
	);
	switch ( category ) {
		case 'checked': {
			const count = evaluation.finding_count ?? 0;
			return {
				category,
				status: __( 'Checked', 'updatelens' ),
				detail:
					count === 0
						? __( 'no findings', 'updatelens' )
						: sprintf(
								/* translators: %s: number of findings. */
								_n(
									'%s finding',
									'%s findings',
									count,
									'updatelens'
								),
								formatCount( count )
							),
				description: null,
			};
		}
		case 'not_applicable':
			return {
				category,
				status: __( 'Not applicable', 'updatelens' ),
				detail: __(
					'Action Scheduler was not detected during this update.',
					'updatelens'
				),
				description: null,
			};
		case 'waiting':
			return {
				category,
				status: __(
					'Not checked yet (waiting for the settled capture)',
					'updatelens'
				),
				detail: null,
				description: reason.description,
			};
		case 'expired':
			return {
				category,
				status: __( 'Not checked (observation expired)', 'updatelens' ),
				detail: null,
				description: reason.description,
			};
		case 'unavailable':
			if (
				signal === 'action_scheduler' &&
				evaluation.reason === 'not_installed'
			) {
				// Not detected in the Net result, but not shown to be absent
				// at every capture (e.g. older reports): never "not applicable".
				return {
					category,
					status: __( 'Not checked (unavailable)', 'updatelens' ),
					detail: __(
						'This report cannot confirm that Action Scheduler was absent throughout the update.',
						'updatelens'
					),
					description: null,
				};
			}
			return {
				category,
				status: __( 'Not checked (unavailable)', 'updatelens' ),
				detail: reason.title,
				description: reason.description,
			};
		case 'failed':
			return {
				category,
				status: __( 'Not checked (failed)', 'updatelens' ),
				detail: reason.title,
				description: reason.description,
			};
	}
}

/**
 * Unique subjects (option names or hooks) of a group, the first few for a
 * compact row and how many more there are.
 *
 * @param group Findings of one rule and signal.
 * @param limit Names to show.
 */
export function groupSubjects(
	group: FindingGroup,
	limit = 2
): { names: string[]; more: number } {
	const subjects = new Set< string >();
	for ( const finding of group.findings ) {
		subjects.add( findingSubject( finding ) );
	}
	const names = Array.from( subjects ).slice( 0, limit );
	return { names, more: subjects.size - names.length };
}

/**
 * One concise, actionable sentence for a collapsed group, from its evidence.
 *
 * @param group Findings of one rule and signal.
 */
export function groupSummary( group: FindingGroup ): string {
	const [ first ] = group.findings;
	const count = group.findings.length;
	switch ( first.code ) {
		case 'large_autoloaded_option': {
			const review = formatBytes( first.evidence.threshold_bytes );
			if ( count === 1 ) {
				return sprintf(
					/* translators: 1: option size, e.g. "178.1 KB". 2: review size, e.g. "146.5 KB". */
					__(
						'Autoloaded at %1$s, above the %2$s review size. Check whether it needs to load on every page request.',
						'updatelens'
					),
					formatBytes( first.after.size ),
					review
				);
			}
			let total = 0;
			for ( const finding of group.findings ) {
				if ( finding.code === 'large_autoloaded_option' ) {
					total += finding.after.size;
				}
			}
			return sprintf(
				/* translators: 1: number of options. 2: their total size, e.g. "512.3 KB". 3: review size, e.g. "146.5 KB". */
				_n(
					'%1$s option, %2$s in total, is autoloaded and above the %3$s review size. Check whether it needs to load on every page request.',
					'%1$s options, %2$s in total, are autoloaded and each above the %3$s review size. Check whether they need to load on every page request.',
					count,
					'updatelens'
				),
				formatCount( count ),
				formatBytes( total ),
				review
			);
		}
		case 'recurring_cron_event_removed': {
			let removed = 0;
			for ( const finding of group.findings ) {
				if ( finding.code === 'recurring_cron_event_removed' ) {
					removed += finding.evidence.removed_recurring_count;
				}
			}
			return count === 1
				? sprintf(
						/* translators: %s: number of recurring WP-Cron event instances. */
						_n(
							'%s recurring instance scheduled before the update was not observed after it. Check whether the hook still has scheduled events.',
							'%s recurring instances scheduled before the update were not observed after it. Check whether the hook still has scheduled events.',
							removed,
							'updatelens'
						),
						formatCount( removed )
					)
				: [
						sprintf(
							/* translators: %s: number of WP-Cron hooks. */
							_n(
								'%s hook has recurring instances that were scheduled before the update and not observed after it.',
								'%s hooks have recurring instances that were scheduled before the update and not observed after it.',
								count,
								'updatelens'
							),
							formatCount( count )
						),
						// The total only when some hook lost more than one instance.
						removed > count
							? sprintf(
									/* translators: %s: number of recurring WP-Cron event instances. */
									_n(
										'%s instance in total.',
										'%s instances in total.',
										removed,
										'updatelens'
									),
									formatCount( removed )
								)
							: '',
						__(
							'Check whether these hooks still have scheduled events.',
							'updatelens'
						),
					]
						.filter( Boolean )
						.join( ' ' );
		}
		case 'recurring_schedule_changed': {
			if ( count === 1 ) {
				return `${ whatChanged( first ) } ${ __(
					'Check that the new schedule is intended.',
					'updatelens'
				) }`;
			}
			const changes = scheduleChanges( group );
			const kind = changes.size === 1 ? [ ...changes ][ 0 ] : null;
			return `${ scheduleChangesText( kind, count ) } ${ __(
				'Check that the new schedules are intended.',
				'updatelens'
			) }`;
		}
	}
}

/**
 * Several schedule changes of one kind, or of mixed kinds (null).
 *
 * @param kind  Change kind shared by every finding, or null.
 * @param count Number of findings.
 */
function scheduleChangesText(
	kind: ScheduleChange | null,
	count: number
): string {
	const n = formatCount( count );
	switch ( kind ) {
		case 'interval_changed':
			return sprintf(
				/* translators: %s: number of recurring schedules. */
				_n(
					'%s recurring interval changed.',
					'%s recurring intervals changed.',
					count,
					'updatelens'
				),
				n
			);
		case 'no_longer_recurring':
			return sprintf(
				/* translators: %s: number of recurring schedules. */
				_n(
					'%s recurring schedule now runs once.',
					'%s recurring schedules now run once.',
					count,
					'updatelens'
				),
				n
			);
		case 'schedule_type_changed':
			return sprintf(
				/* translators: %s: number of schedules. */
				_n(
					'%s schedule changed between an interval and a cron expression.',
					'%s schedules changed between an interval and a cron expression.',
					count,
					'updatelens'
				),
				n
			);
		case 'cron_expression_changed':
			return sprintf(
				/* translators: %s: number of cron expressions. */
				_n(
					'%s cron expression changed.',
					'%s cron expressions changed.',
					count,
					'updatelens'
				),
				n
			);
		default:
			return sprintf(
				/* translators: %s: number of recurring schedules. */
				_n(
					'%s recurring schedule changed.',
					'%s recurring schedules changed.',
					count,
					'updatelens'
				),
				n
			);
	}
}

/**
 * Why a signal could not be checked, in the words of its signal page.
 *
 * @param signal        Signal.
 * @param reason        API reason.
 * @param windowSeconds Observation window length from the API.
 */
function impactSignalReason(
	signal: Provider,
	reason: string | null,
	windowSeconds: number
): { title: string; description: string } {
	switch ( signal ) {
		case 'options':
			return unavailableReasonText( reason ?? '', windowSeconds );
		case 'cron':
			return cronUnavailableReasonText( reason ?? '', windowSeconds );
		case 'action_scheduler':
			return actionSchedulerUnavailableReasonText(
				reason ?? '',
				windowSeconds
			);
	}
}

/**
 * The subject of a finding: its option name or hook.
 *
 * @param finding Finding.
 */
export function findingSubject( finding: ImpactFinding ): string {
	return finding.code === 'large_autoloaded_option'
		? finding.option
		: finding.hook;
}

/**
 * Disclosure state of the Potential Impact section, kept by the report so it
 * survives a visit to a signal page and back.
 */
export interface ImpactView {
	/** Expanded groups by key, with the number of their findings shown. */
	open: Readonly< Record< string, number > >;
	/** Whether "What was checked" is open. */
	checks: boolean;
}

/** Everything collapsed. */
export const COLLAPSED_IMPACT_VIEW: ImpactView = { open: {}, checks: false };
