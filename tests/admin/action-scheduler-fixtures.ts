/**
 * Action Scheduler report fixtures shaped like real observations: the
 * WooCommerce 11.1.1 → 11.1.2 update of the integration study (October
 * 2026, WordPress 7.1.2), including WPForms jobs observed in the same
 * window. Hooks, groups, schedules and movements as observed; never
 * arguments. Summaries are derived from the lists, like the API's.
 */
import type {
	ActionSchedulerActionState,
	AnalysisReport,
	AvailableActionSchedulerPhase,
	ChangedAction,
	PhaseAssociation,
	RescheduledAction,
} from '@/admin/types/api';

import { cronPhase, optionsPhase } from './dogfood-fixtures';
import {
	asEverywhere,
	asUnavailable,
	COMPLETED,
	CRON_FINAL,
	FINAL,
	phases,
	T,
	withImpact,
} from './fixtures';

/**
 * Action Scheduler phase from its lists; `actions` active actions before,
 * `recurring` of them recurring, `hooks` unique hooks.
 *
 * @param association       Phase association.
 * @param lists             Action lists.
 * @param lists.added
 * @param lists.removed
 * @param lists.rescheduled
 * @param lists.changed
 * @param actions           Active actions before.
 * @param recurring         Recurring actions before.
 */
export function asPhase(
	association: PhaseAssociation,
	{
		added = [],
		removed = [],
		rescheduled = [],
		changed = [],
	}: {
		added?: ActionSchedulerActionState[];
		removed?: ActionSchedulerActionState[];
		rescheduled?: RescheduledAction[];
		changed?: ChangedAction[];
	},
	actions = 6,
	recurring = 4
): AvailableActionSchedulerPhase {
	const recurringOf = ( list: ActionSchedulerActionState[] ) =>
		list.filter( ( a ) => a.is_recurring ).length;
	const delta = added.length - removed.length;
	const recurringDelta =
		recurringOf( added ) -
		recurringOf( removed ) +
		changed.filter( ( c ) => c.after_is_recurring ).length -
		changed.filter( ( c ) => c.before_is_recurring ).length;
	const hookDelta =
		new Set( added.map( ( a ) => a.hook ) ).size -
		new Set( removed.map( ( a ) => a.hook ) ).size;

	return {
		available: true,
		association,
		summary: {
			before_action_count: actions,
			after_action_count: actions + delta,
			action_count_delta: delta,
			before_recurring_count: recurring,
			after_recurring_count: recurring + recurringDelta,
			recurring_count_delta: recurringDelta,
			before_single_count: actions - recurring,
			after_single_count: actions - recurring + delta - recurringDelta,
			single_count_delta: delta - recurringDelta,
			before_unique_hook_count: actions,
			after_unique_hook_count: actions + hookDelta,
			unique_hook_count_delta: hookDelta,
			added_count: added.length,
			removed_count: removed.length,
			rescheduled_count: rescheduled.length,
			changed_count: changed.length,
		},
		added,
		removed,
		rescheduled,
		changed,
	};
}

/**
 * Active action state.
 *
 * @param hook      Hook.
 * @param group     Group slug ('' for none).
 * @param timestamp Scheduled run.
 * @param schedule  Normalized schedule fields.
 */
export function action(
	hook: string,
	group: string,
	timestamp: number,
	schedule: Pick<
		ActionSchedulerActionState,
		'schedule_type' | 'interval' | 'cron_expression' | 'is_recurring'
	> & { status?: ActionSchedulerActionState[ 'status' ] }
): ActionSchedulerActionState {
	return {
		hook,
		group,
		status: schedule.status ?? 'pending',
		timestamp,
		schedule_type: schedule.schedule_type,
		interval: schedule.interval,
		cron_expression: schedule.cron_expression,
		is_recurring: schedule.is_recurring,
	};
}

export const SINGLE = {
	schedule_type: 'single',
	interval: null,
	cron_expression: null,
	is_recurring: false,
} as const;

export const ASYNC = {
	schedule_type: 'async',
	interval: null,
	cron_expression: null,
	is_recurring: false,
} as const;

export function every( seconds: number ) {
	return {
		schedule_type: 'interval',
		interval: seconds,
		cron_expression: null,
		is_recurring: true,
	} as const;
}

export function cronSchedule( expression: string ) {
	return {
		schedule_type: 'cron',
		interval: null,
		cron_expression: expression,
		is_recurring: true,
	} as const;
}

/**
 * The same action at another time with the same schedule.
 *
 * @param state Action before.
 * @param delta Seconds moved.
 */
export function movedAction(
	state: ActionSchedulerActionState,
	delta: number
): RescheduledAction {
	return {
		hook: state.hook,
		group: state.group,
		before_timestamp: state.timestamp,
		after_timestamp: state.timestamp + delta,
		timestamp_delta: delta,
		schedule_type: state.schedule_type,
		interval: state.interval,
		cron_expression: state.cron_expression,
		is_recurring: state.is_recurring,
	};
}

/** WooCommerce queued its daily pattern fetch during the update request. */
export const FETCH_PATTERNS = action(
	'fetch_patterns',
	'woocommerce',
	T + 5,
	every( 86400 )
);

/** Action Scheduler's own migration job, moved by a normal queue run. */
export const MIGRATION_HOOK = action(
	'action_scheduler/migration_hook',
	'action-scheduler-migration',
	T + 60,
	SINGLE
);

/** WooCommerce's async job, running at the settled capture. */
export const ADMIN_UPDATED = action(
	'woocommerce_run_on_woocommerce_admin_updated',
	'woocommerce-remote-inbox-engine',
	T + 12,
	{ ...ASYNC, status: 'in-progress' }
);

/** A WPForms job that left the active queue in the same window. */
export const WPFORMS_NOTIFICATIONS = action(
	'wpforms_admin_notifications_update',
	'wpforms',
	T + 30,
	SINGLE
);

/** A WPForms recurring job whose schedule changed from an interval to a cron expression. */
export const WPFORMS_SUMMARIES: ChangedAction = {
	hook: 'wpforms_email_summaries_fetch_info_blocks',
	group: 'wpforms',
	before_timestamp: T + 3600,
	after_timestamp: T + 3600,
	timestamp_changed: false,
	before_schedule_type: 'interval',
	after_schedule_type: 'cron',
	before_interval: 604800,
	after_interval: null,
	before_cron_expression: null,
	after_cron_expression: '0 */6 * * *',
	before_is_recurring: true,
	after_is_recurring: true,
};

export const AS_DURING = asPhase( 'update_request', {
	added: [ FETCH_PATTERNS ],
} );

export const AS_POST = asPhase(
	'observed_after_update',
	{
		added: [ ADMIN_UPDATED ],
		removed: [ WPFORMS_NOTIFICATIONS ],
		rescheduled: [
			movedAction( MIGRATION_HOOK, 84 ),
			movedAction( FETCH_PATTERNS, 86414 ),
		],
		changed: [ WPFORMS_SUMMARIES ],
	},
	7,
	5
);

export const AS_FINAL = asPhase( 'net_across_phases', {
	added: [ FETCH_PATTERNS, ADMIN_UPDATED ],
	removed: [ WPFORMS_NOTIFICATIONS ],
	rescheduled: [ movedAction( MIGRATION_HOOK, 84 ) ],
	changed: [ WPFORMS_SUMMARIES ],
} );

/** WooCommerce 11.1.1 → 11.1.2 with all three signals available. */
export const WOOCOMMERCE: AnalysisReport = withImpact( {
	...COMPLETED,
	id: 40,
	plugin: {
		file: 'woocommerce/woocommerce.php',
		name: 'WooCommerce',
		version_before: '11.1.1',
		version_after: '11.1.2',
	},
	phases: phases(
		{
			during_update: optionsPhase( 'update_request', {} ),
			post_update: optionsPhase( 'observed_after_update', {} ),
			final: FINAL,
		},
		{
			during_update: cronPhase( 'update_request', {} ),
			post_update: cronPhase( 'observed_after_update', {} ),
			final: CRON_FINAL,
		},
		{
			during_update: AS_DURING,
			post_update: AS_POST,
			final: AS_FINAL,
		}
	),
} );

/**
 * A report with the given signals of every phase: Options and WP-Cron
 * captured without changes unless given.
 *
 * @param id      Report ID.
 * @param signals Overrides per phase association.
 */
export function asReport(
	id: number,
	signals: (
		association: PhaseAssociation
	) => Partial< AnalysisReport[ 'phases' ][ 'final' ] >
): AnalysisReport {
	const phase = ( association: PhaseAssociation ) => ( {
		options: optionsPhase( association, {} ),
		cron: cronPhase( association, {} ),
		action_scheduler: asPhase( association, {} ),
		...signals( association ),
	} );
	return withImpact( {
		...COMPLETED,
		id,
		phases: {
			during_update: phase( 'update_request' ),
			post_update: phase( 'observed_after_update' ),
			final: phase( 'net_across_phases' ),
		},
	} );
}

/** WooCommerce with Action Scheduler unavailable for one reason in every phase. */
export function asReasonReport(
	reason: Parameters< typeof asEverywhere >[ 0 ]
): AnalysisReport {
	return withImpact( {
		...WOOCOMMERCE,
		id: 41,
		phases: phases(
			{
				during_update: WOOCOMMERCE.phases.during_update.options,
				post_update: WOOCOMMERCE.phases.post_update.options,
				final: WOOCOMMERCE.phases.final.options,
			},
			{
				during_update: WOOCOMMERCE.phases.during_update.cron,
				post_update: WOOCOMMERCE.phases.post_update.cron,
				final: WOOCOMMERCE.phases.final.cron,
			},
			asEverywhere( reason )
		),
	} );
}

/** Net result Action Scheduler data unreadable; the other phases and signals intact. */
export const AS_CORRUPT_FINAL: AnalysisReport = withImpact( {
	...WOOCOMMERCE,
	id: 42,
	phases: {
		...WOOCOMMERCE.phases,
		final: {
			...WOOCOMMERCE.phases.final,
			action_scheduler: asUnavailable(
				'net_across_phases',
				'data_corrupt'
			),
		},
	},
} );

/**
 * A synthetic queue: `n` added actions of one hook and group (different
 * arguments), plus `n` rescheduled ones.
 *
 * @param n Actions per list.
 */
export function largeQueue( n: number ): AnalysisReport {
	const added = Array.from( { length: n }, ( _, i ) =>
		action( 'ul_stress_import_batch', 'ul-stress', T + i, SINGLE )
	);
	const rescheduled = Array.from( { length: n }, ( _, i ) =>
		movedAction(
			action(
				`ul_stress_recurring_${ String( i ).padStart( 4, '0' ) }`,
				'ul-stress-a-very-long-group-name-that-must-wrap-safely-in-narrow-layouts',
				T + i,
				every( 3600 )
			),
			3600
		)
	);
	return asReport( 43, ( association ) => ( {
		action_scheduler: asPhase( association, { added, rescheduled }, n, n ),
	} ) );
}
