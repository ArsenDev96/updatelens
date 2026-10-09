/**
 * Reports with Potential Impact findings, shaped like the API's output
 * (docs/rest-api.md, `potential_impact`). Findings refer to rows that exist
 * in the report's Net result, so links can locate them.
 */
import type {
	ActionScheduleChangedFinding,
	AddedCronEvent,
	AnalysisReport,
	AvailableCronPhase,
	ChangedCronEvent,
	CronScheduleChangedFinding,
	ImpactFinding,
	LargeAutoloadedOptionFinding,
	RecurringCronEventRemovedFinding,
	RemovedCronEvent,
} from '@/admin/types/api';

import {
	asPhase,
	WOOCOMMERCE,
	WPFORMS_SUMMARIES,
} from './action-scheduler-fixtures';
import {
	addedOption,
	changedOption,
	cronPhase,
	moved,
	optionsPhase,
	recurring,
} from './dogfood-fixtures';
import { asEverywhere, COMPLETED, phases, T, withImpact } from './fixtures';

const SYNC = recurring( 'acme_sync_feeds', T + 1800, 'hourly', 3600 );
const VERSION_CHECK = recurring(
	'wp_version_check',
	T + 300,
	'twicedaily',
	43200
);

const CLEANUP_CHANGED: ChangedCronEvent = {
	hook: 'acme_cleanup',
	before_timestamp: T + 600,
	after_timestamp: T + 600,
	timestamp_changed: false,
	before_schedule: 'daily',
	after_schedule: 'weekly',
	before_interval: 86400,
	after_interval: 604800,
	before_is_recurring: true,
	after_is_recurring: true,
};

const BECAME_AUTOLOADED = {
	...changedOption( 'acme_feed_cache', 182340, 182340, 'on' ),
	value_changed: false,
	before_autoload: 'off',
	before_is_autoloaded: false,
	autoload_value_changed: true,
	autoload_behavior_changed: true,
};

export const LARGE_ADDED: LargeAutoloadedOptionFinding = {
	code: 'large_autoloaded_option',
	signal: 'options',
	option: 'acme_feed_index',
	evidence: {
		transition: 'added',
		threshold_bytes: 150000,
		size_delta: null,
		value_changed: null,
	},
	before: null,
	after: { size: 163000, autoload: 'auto', is_autoloaded: true },
};

export const LARGE_BECAME: LargeAutoloadedOptionFinding = {
	code: 'large_autoloaded_option',
	signal: 'options',
	option: 'acme_feed_cache',
	evidence: {
		transition: 'became_autoloaded',
		threshold_bytes: 150000,
		size_delta: 0,
		value_changed: false,
	},
	before: { size: 182340, autoload: 'off', is_autoloaded: false },
	after: { size: 182340, autoload: 'on', is_autoloaded: true },
};

export const CRON_REMOVED: RecurringCronEventRemovedFinding = {
	code: 'recurring_cron_event_removed',
	signal: 'cron',
	hook: 'acme_sync_feeds',
	evidence: {
		removed_recurring_count: 1,
		added_recurring_count: 0,
		not_replaced_count: 1,
		other_recorded_changes: {
			added_one_time: 0,
			rescheduled: 0,
			changed: 0,
		},
	},
	before: {
		removed_recurring: [
			{ timestamp: T + 1800, schedule: 'hourly', interval: 3600 },
		],
	},
	after: { added_recurring: [] },
};

export const CRON_INTERVAL: CronScheduleChangedFinding = {
	code: 'recurring_schedule_changed',
	signal: 'cron',
	hook: 'acme_cleanup',
	evidence: { change: 'interval_changed' },
	before: {
		timestamp: T + 600,
		schedule: 'daily',
		interval: 86400,
		is_recurring: true,
	},
	after: {
		timestamp: T + 600,
		schedule: 'weekly',
		interval: 604800,
		is_recurring: true,
	},
};

export const AS_TYPE_CHANGED: ActionScheduleChangedFinding = {
	code: 'recurring_schedule_changed',
	signal: 'action_scheduler',
	hook: WPFORMS_SUMMARIES.hook,
	group: 'wpforms',
	evidence: { change: 'schedule_type_changed' },
	before: {
		timestamp: T + 3600,
		schedule_type: 'interval',
		interval: 604800,
		cron_expression: null,
		is_recurring: true,
	},
	after: {
		timestamp: T + 3600,
		schedule_type: 'cron',
		interval: null,
		cron_expression: '0 */6 * * *',
		is_recurring: true,
	},
};

const FINAL_CRON: AvailableCronPhase = {
	...cronPhase( 'net_across_phases', {
		removed: [ SYNC ],
		rescheduled: [ moved( VERSION_CHECK, 43200 ) ],
	} ),
	changed: [ CLEANUP_CHANGED ],
};
FINAL_CRON.summary = { ...FINAL_CRON.summary, changed_count: 1 };

/** All finding types: two options, two WP-Cron, one Action Scheduler. */
export const IMPACT: AnalysisReport = withImpact(
	{
		...WOOCOMMERCE,
		id: 60,
		plugin: {
			file: 'acme-feeds/acme-feeds.php',
			name: 'Acme Feeds',
			version_before: '2.3.1',
			version_after: '2.4.0',
		},
		phases: {
			...WOOCOMMERCE.phases,
			final: {
				options: optionsPhase( 'net_across_phases', {
					added: [ addedOption( 'acme_feed_index', 163000 ) ],
					changed: [
						BECAME_AUTOLOADED,
						changedOption( 'acme_settings', 10, 12 ),
					],
				} ),
				cron: FINAL_CRON,
				action_scheduler: asPhase( 'net_across_phases', {
					changed: [ WPFORMS_SUMMARIES ],
				} ),
			},
		},
	},
	[ LARGE_BECAME, LARGE_ADDED, CRON_REMOVED, CRON_INTERVAL, AS_TYPE_CHANGED ]
);

/** One finding; Action Scheduler absent throughout (not applicable). */
export const ONE_FINDING: AnalysisReport = withImpact(
	{
		...COMPLETED,
		id: 61,
		phases: phases(
			{
				during_update: optionsPhase( 'update_request', {} ),
				post_update: optionsPhase( 'observed_after_update', {} ),
				final: optionsPhase( 'net_across_phases', {} ),
			},
			{
				during_update: cronPhase( 'update_request', {} ),
				post_update: cronPhase( 'observed_after_update', {} ),
				final: cronPhase( 'net_across_phases', {
					removed: [ SYNC ],
				} ),
			},
			asEverywhere( 'not_installed' )
		),
	},
	[ CRON_REMOVED ]
);

/** Action Scheduler present before the update and gone after it. */
export const AS_GONE: AnalysisReport = withImpact( {
	...ONE_FINDING,
	id: 62,
	phases: {
		during_update: {
			...ONE_FINDING.phases.during_update,
			action_scheduler: {
				available: false,
				association: 'update_request',
				reason: 'no_longer_detected',
			},
		},
		post_update: ONE_FINDING.phases.post_update,
		final: {
			...ONE_FINDING.phases.final,
			cron: cronPhase( 'net_across_phases', {} ),
			action_scheduler: {
				available: false,
				association: 'net_across_phases',
				reason: 'no_longer_detected',
			},
		},
	},
} );

/**
 * Many findings: `options` large added options and `hooks` removed
 * recurring WP-Cron events, all in the Net result.
 *
 * @param options Number of option findings.
 * @param hooks   Number of WP-Cron findings.
 */
export function manyFindings( options: number, hooks: number ): AnalysisReport {
	const names = Array.from(
		{ length: options },
		( _, i ) => `stress_option_${ String( i ).padStart( 4, '0' ) }`
	);
	const events = Array.from( { length: hooks }, ( _, i ) =>
		recurring(
			`stress_hook_${ String( i ).padStart( 4, '0' ) }`,
			T + i,
			'hourly',
			3600
		)
	);
	const findings: ImpactFinding[] = [
		...names.map( ( option ): LargeAutoloadedOptionFinding => ( {
			...LARGE_ADDED,
			option,
		} ) ),
		...events.map( ( event ): RecurringCronEventRemovedFinding => ( {
			...CRON_REMOVED,
			hook: event.hook,
			before: {
				removed_recurring: [
					{
						timestamp: event.timestamp,
						schedule: 'hourly',
						interval: 3600,
					},
				],
			},
		} ) ),
	];
	return withImpact(
		{
			...ONE_FINDING,
			id: 63,
			phases: {
				...ONE_FINDING.phases,
				final: {
					...ONE_FINDING.phases.final,
					options: optionsPhase( 'net_across_phases', {
						added: names.map( ( name ) =>
							addedOption( name, 163000 )
						),
					} ),
					cron: cronPhase( 'net_across_phases', {
						removed: events,
					} ),
				},
			},
		},
		findings
	);
}

/** Identifiers far longer than a phone screen, without separators. */
export const LONG_OPTION = `acme${ 'x'.repeat( 180 ) }_cache`;
export const LONG_HOOK = `acme_${ 'very_long_segment_'.repeat( 12 ) }sync`;

export const LONG_IDS: AnalysisReport = withImpact(
	{
		...ONE_FINDING,
		id: 64,
		phases: {
			...ONE_FINDING.phases,
			final: {
				...ONE_FINDING.phases.final,
				options: optionsPhase( 'net_across_phases', {
					added: [ addedOption( LONG_OPTION, 163000 ) ],
				} ),
				cron: cronPhase( 'net_across_phases', {
					removed: [ { ...SYNC, hook: LONG_HOOK } ],
				} ),
			},
		},
	},
	[
		{ ...LARGE_ADDED, option: LONG_OPTION },
		{ ...CRON_REMOVED, hook: LONG_HOOK },
	]
);

/**
 * A report whose Net result has the given WP-Cron lists, for locating rows of
 * events that share a hook: they can differ only in arguments, never shown.
 *
 * @param lists    Net result WP-Cron lists.
 * @param findings Findings, as the API would report them for these lists.
 */
export function cronFinal(
	lists: {
		added?: AddedCronEvent[];
		removed?: RemovedCronEvent[];
		changed?: ChangedCronEvent[];
	},
	findings: ImpactFinding[]
): AnalysisReport {
	const cron = cronPhase( 'net_across_phases', {
		added: lists.added,
		removed: lists.removed,
	} );
	cron.changed = lists.changed ?? [];
	cron.summary = { ...cron.summary, changed_count: cron.changed.length };
	return withImpact(
		{
			...ONE_FINDING,
			id: 65,
			phases: {
				...ONE_FINDING.phases,
				final: { ...ONE_FINDING.phases.final, cron },
			},
		},
		findings
	);
}

/**
 * A changed recurring WP-Cron event (same hook and arguments, other interval).
 *
 * @param hook      Hook.
 * @param timestamp Next run, before and after.
 * @param before    Schedule and interval before.
 * @param after     Schedule and interval after.
 */
export function cronChange(
	hook: string,
	timestamp: number,
	before: [ string, number ],
	after: [ string, number ]
): ChangedCronEvent {
	return {
		hook,
		before_timestamp: timestamp,
		after_timestamp: timestamp,
		timestamp_changed: false,
		before_schedule: before[ 0 ],
		after_schedule: after[ 0 ],
		before_interval: before[ 1 ],
		after_interval: after[ 1 ],
		before_is_recurring: true,
		after_is_recurring: true,
	};
}

/**
 * The `recurring_schedule_changed` finding the API reports for a changed event.
 *
 * @param change Changed WP-Cron event.
 */
export function cronChangeFinding(
	change: ChangedCronEvent
): CronScheduleChangedFinding {
	return {
		code: 'recurring_schedule_changed',
		signal: 'cron',
		hook: change.hook,
		evidence: { change: 'interval_changed' },
		before: {
			timestamp: change.before_timestamp,
			schedule: change.before_schedule,
			interval: change.before_interval,
			is_recurring: change.before_is_recurring,
		},
		after: {
			timestamp: change.after_timestamp,
			schedule: change.after_schedule,
			interval: change.after_interval,
			is_recurring: change.after_is_recurring,
		},
	};
}

/**
 * An analysis recorded before absence was resolved per phase: every Action
 * Scheduler phase `not_installed`, which cannot show it was absent at every
 * capture, so the API reports it `not_evaluated`.
 */
export const HISTORICAL_NOT_INSTALLED: AnalysisReport = {
	...ONE_FINDING,
	id: 66,
	potential_impact: {
		...ONE_FINDING.potential_impact,
		status: 'partial',
		signals: {
			...ONE_FINDING.potential_impact.signals,
			action_scheduler: {
				...ONE_FINDING.potential_impact.signals.action_scheduler,
				status: 'not_evaluated',
			},
		},
	},
};

/** Action Scheduler not active before the update and active after it. */
export const AS_NEW: AnalysisReport = withImpact( {
	...ONE_FINDING,
	id: 67,
	phases: {
		during_update: ONE_FINDING.phases.during_update,
		post_update: {
			...ONE_FINDING.phases.post_update,
			action_scheduler: {
				available: false,
				association: 'observed_after_update',
				reason: 'newly_detected',
			},
		},
		final: {
			...ONE_FINDING.phases.final,
			action_scheduler: {
				available: false,
				association: 'net_across_phases',
				reason: 'newly_detected',
			},
		},
	},
} );
