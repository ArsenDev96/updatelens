import { _n, __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type {
	ActionSchedulerActionState,
	ActionSchedulerDiffSummary,
	ActionScheduleType,
	AvailableActionSchedulerPhase,
	ChangedAction,
	RescheduledAction,
} from '../types/api';
import {
	formatCount,
	formatCountDelta,
	formatDuration,
	formatDurationDelta,
} from '../utils/format';
import {
	actionSchedulerPhaseNote,
	actionScheduleTypeLabel,
} from '../utils/labels';
import { Details, HookName, Time, To } from './DiffRow';
import { DiffSection } from './DiffSection';
import { Metric, Total } from './PhaseSummary';

/*
 * Action Scheduler changes of one phase: a diff of active (pending or
 * in-progress) actions between two observation points, not execution
 * history. Only hooks, groups, statuses, times and normalized schedules
 * exist in the API: arguments are never stored, so they are never shown.
 * An action that is no longer active may have run, been canceled or left
 * the queue otherwise, which UpdateLens cannot tell apart. Groups are
 * observed metadata, never shown as ownership.
 */

/**
 * Note, summary and lists of an available Action Scheduler phase with changes.
 *
 * @param props       Props.
 * @param props.phase Available Action Scheduler phase with at least one change.
 */
export function ActionSchedulerChanges( {
	phase,
}: {
	phase: AvailableActionSchedulerPhase;
} ) {
	return (
		<div className="space-y-4">
			<p className="text-sm text-muted-foreground">
				{ actionSchedulerPhaseNote() }
			</p>
			<ActionSchedulerSummary summary={ phase.summary } />
			<ActionSchedulerDiffList phase={ phase } />
		</div>
	);
}

/**
 * Added, no longer active, changed and rescheduled counts, then before →
 * after totals of active actions.
 *
 * @param props         Props.
 * @param props.summary Action Scheduler phase summary.
 */
export function ActionSchedulerSummary( {
	summary,
}: {
	summary: ActionSchedulerDiffSummary;
} ) {
	return (
		<div className="space-y-3">
			<dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
				<Metric
					label={ __( 'Added', 'updatelens' ) }
					value={ formatCount( summary.added_count ) }
				/>
				<Metric
					label={
						/* translators: Summary count of Action Scheduler actions active before and not active after (ran, canceled or otherwise left the queue). */
						__( 'No longer active', 'updatelens' )
					}
					value={ formatCount( summary.removed_count ) }
				/>
				<Metric
					label={ __( 'Changed', 'updatelens' ) }
					value={ formatCount( summary.changed_count ) }
				/>
				<Metric
					label={ __( 'Rescheduled', 'updatelens' ) }
					value={ formatCount( summary.rescheduled_count ) }
					quiet
				/>
			</dl>
			<dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 xl:grid-cols-4">
				<Total
					label={ __( 'Active actions', 'updatelens' ) }
					before={ formatCount( summary.before_action_count ) }
					after={ formatCount( summary.after_action_count ) }
					delta={ formatCountDelta( summary.action_count_delta ) }
				/>
				<Total
					label={ __( 'Recurring', 'updatelens' ) }
					before={ formatCount( summary.before_recurring_count ) }
					after={ formatCount( summary.after_recurring_count ) }
					delta={ formatCountDelta( summary.recurring_count_delta ) }
				/>
				<Total
					label={ __( 'One-time', 'updatelens' ) }
					before={ formatCount( summary.before_single_count ) }
					after={ formatCount( summary.after_single_count ) }
					delta={ formatCountDelta( summary.single_count_delta ) }
				/>
				<Total
					label={ __( 'Unique hooks', 'updatelens' ) }
					before={ formatCount( summary.before_unique_hook_count ) }
					after={ formatCount( summary.after_unique_hook_count ) }
					delta={ formatCountDelta(
						summary.unique_hook_count_delta
					) }
				/>
			</dl>
		</div>
	);
}

/**
 * Action lists of one phase with changes, then the notes that apply to
 * them. Empty lists are left out (the summary shows their zero count); long
 * lists collapse.
 *
 * @param props       Props.
 * @param props.phase Available Action Scheduler phase with at least one change.
 */
export function ActionSchedulerDiffList( {
	phase,
}: {
	phase: AvailableActionSchedulerPhase;
} ) {
	const key = ( action: { hook: string; group: string }, index: number ) =>
		`${ action.hook }-${ action.group }-${ index }`;

	return (
		<div className="space-y-5">
			{ phase.added.length > 0 && (
				<DiffSection
					id="updatelens-as-added"
					title={ sprintf(
						/* translators: %s: number of Action Scheduler actions. */
						__( 'Added (%s)', 'updatelens' ),
						formatCount( phase.added.length )
					) }
					items={ phase.added }
					renderItem={ ( action, index ) => (
						<AddedActionRow
							key={ key( action, index ) }
							action={ action }
						/>
					) }
					moreLabel={ ( hidden ) =>
						sprintf(
							/* translators: %s: number of hidden rows. */
							_n(
								'Show %s more added action',
								'Show %s more added actions',
								hidden,
								'updatelens'
							),
							formatCount( hidden )
						)
					}
					lessLabel={ __( 'Show fewer added actions', 'updatelens' ) }
				/>
			) }
			{ phase.removed.length > 0 && (
				<DiffSection
					id="updatelens-as-removed"
					title={ sprintf(
						/* translators: %s: number of Action Scheduler actions that left the active queue. */
						__( 'No longer active (%s)', 'updatelens' ),
						formatCount( phase.removed.length )
					) }
					items={ phase.removed }
					renderItem={ ( action, index ) => (
						<InactiveActionRow
							key={ key( action, index ) }
							action={ action }
						/>
					) }
					moreLabel={ ( hidden ) =>
						sprintf(
							/* translators: %s: number of hidden rows. */
							_n(
								'Show %s more action no longer active',
								'Show %s more actions no longer active',
								hidden,
								'updatelens'
							),
							formatCount( hidden )
						)
					}
					lessLabel={ __(
						'Show fewer actions no longer active',
						'updatelens'
					) }
				/>
			) }
			{ phase.changed.length > 0 && (
				<DiffSection
					id="updatelens-as-changed"
					title={ sprintf(
						/* translators: %s: number of Action Scheduler actions. */
						__( 'Changed (%s)', 'updatelens' ),
						formatCount( phase.changed.length )
					) }
					items={ phase.changed }
					renderItem={ ( action, index ) => (
						<ChangedActionRow
							key={ key( action, index ) }
							action={ action }
						/>
					) }
					moreLabel={ ( hidden ) =>
						sprintf(
							/* translators: %s: number of hidden rows. */
							_n(
								'Show %s more changed action',
								'Show %s more changed actions',
								hidden,
								'updatelens'
							),
							formatCount( hidden )
						)
					}
					lessLabel={ __(
						'Show fewer changed actions',
						'updatelens'
					) }
				/>
			) }
			{ phase.rescheduled.length > 0 && (
				<DiffSection
					id="updatelens-as-rescheduled"
					title={ sprintf(
						/* translators: %s: number of Action Scheduler actions. */
						__( 'Rescheduled (%s)', 'updatelens' ),
						formatCount( phase.rescheduled.length )
					) }
					items={ phase.rescheduled }
					renderItem={ ( action, index ) => (
						<RescheduledActionRow
							key={ key( action, index ) }
							action={ action }
						/>
					) }
					moreLabel={ ( hidden ) =>
						sprintf(
							/* translators: %s: number of hidden rows. */
							_n(
								'Show %s more rescheduled action',
								'Show %s more rescheduled actions',
								hidden,
								'updatelens'
							),
							formatCount( hidden )
						)
					}
					lessLabel={ __(
						'Show fewer rescheduled actions',
						'updatelens'
					) }
					quiet
				/>
			) }
			<div className="space-y-1 text-xs text-muted-foreground">
				<p>
					{ __(
						'Action arguments are fingerprinted for matching but are never stored or shown. Similar-looking rows may represent different argument sets.',
						'updatelens'
					) }
				</p>
				{ phase.removed.length > 0 && (
					<p>
						{ __(
							'An action may leave the active queue because it ran, was canceled, or otherwise changed state.',
							'updatelens'
						) }
					</p>
				) }
				<p>
					{ __(
						'UpdateLens compares active Action Scheduler state at observation points. Very short-lived actions that are queued and completed between captures may not appear.',
						'updatelens'
					) }
				</p>
			</div>
		</div>
	);
}

/**
 * Hook in monospace and, when the action has one, its group as secondary
 * metadata (observed, not ownership).
 *
 * @param props        Props.
 * @param props.action Action.
 */
function ActionName( { action }: { action: { hook: string; group: string } } ) {
	return (
		<>
			<HookName hook={ action.hook } />
			{ action.group !== '' && (
				<p className="break-all text-xs text-muted-foreground">
					{ __( 'Group:', 'updatelens' ) }{ ' ' }
					<span className="font-mono">{ action.group }</span>
				</p>
			) }
		</>
	);
}

/**
 * "Every 1 day" for an interval, else null.
 *
 * @param interval Interval in seconds or null.
 */
function everyText( interval: number | null ): string | null {
	return interval === null
		? null
		: sprintf(
				/* translators: %s: duration, e.g. "1 day". */
				__( 'Every %s', 'updatelens' ),
				formatDuration( interval )
			);
}

/**
 * A cron expression in monospace.
 *
 * @param props            Props.
 * @param props.expression Normalized cron expression.
 */
function Expression( { expression }: { expression: string } ) {
	return (
		<code className="m-0 break-all bg-transparent p-0 font-mono text-[13px]">
			{ expression }
		</code>
	);
}

/**
 * Normalized schedule: "One-time", "Async · As soon as possible",
 * "Interval · Every 1 day" or "Cron schedule · 0 *\/6 * * *".
 *
 * @param props            Props.
 * @param props.type       Schedule type.
 * @param props.interval   Interval in seconds (interval only).
 * @param props.expression Cron expression (cron only).
 */
function Schedule( {
	type,
	interval,
	expression,
}: {
	type: ActionScheduleType;
	interval: number | null;
	expression: string | null;
} ) {
	const label = actionScheduleTypeLabel( type );
	let detail: ReactNode = null;
	if ( type === 'async' ) {
		detail = __( 'As soon as possible', 'updatelens' );
	} else if ( type === 'interval' ) {
		detail = everyText( interval );
	} else if ( type === 'cron' && expression !== null ) {
		detail = <Expression expression={ expression } />;
	}

	return (
		<>
			{ label }
			{ detail !== null && <> · { detail }</> }
		</>
	);
}

/**
 * Label of an action's scheduled time: "Next run" for recurring actions,
 * "Scheduled for" otherwise.
 *
 * @param isRecurring Whether the action recurs.
 */
function timeLabel( isRecurring: boolean ): string {
	return isRecurring
		? __( 'Next run', 'updatelens' )
		: __( 'Scheduled for', 'updatelens' );
}

function statusText( status: ActionSchedulerActionState[ 'status' ] ): string {
	return status === 'in-progress'
		? __( 'In progress', 'updatelens' )
		: __( 'Pending', 'updatelens' );
}

/**
 * An action that became active (state after).
 *
 * @param props        Props.
 * @param props.action Action.
 */
function AddedActionRow( { action }: { action: ActionSchedulerActionState } ) {
	return (
		<li className="space-y-1.5 border-l-2 border-l-emerald-400 px-4 py-2.5">
			<ActionName action={ action } />
			<p className="text-sm">
				<Schedule
					type={ action.schedule_type }
					interval={ action.interval }
					expression={ action.cron_expression }
				/>
			</p>
			<Details
				rows={ [
					[
						timeLabel( action.is_recurring ),
						<Time key="t" timestamp={ action.timestamp } />,
					],
					[
						__( 'Status', 'updatelens' ),
						statusText( action.status ),
					],
				] }
			/>
		</li>
	);
}

/**
 * An action that is no longer active (state before). It may have run, been
 * canceled or left the queue otherwise; UpdateLens observes only that it
 * is no longer pending or in progress.
 *
 * @param props        Props.
 * @param props.action Action.
 */
function InactiveActionRow( {
	action,
}: {
	action: ActionSchedulerActionState;
} ) {
	return (
		<li className="space-y-1.5 border-l-2 border-l-slate-300 px-4 py-2.5">
			<ActionName action={ action } />
			<p className="text-sm">
				{ __( 'No longer active', 'updatelens' ) } ·{ ' ' }
				<Schedule
					type={ action.schedule_type }
					interval={ action.interval }
					expression={ action.cron_expression }
				/>
			</p>
			<p className="text-sm text-muted-foreground">
				{ __( 'Previously scheduled for', 'updatelens' ) }{ ' ' }
				<Time timestamp={ action.timestamp } />
				{ action.status === 'in-progress' && (
					<>
						{ ' · ' }
						{ __( 'was in progress', 'updatelens' ) }
					</>
				) }
			</p>
		</li>
	);
}

/**
 * The same action (hook, group and arguments) with another schedule.
 *
 * @param props        Props.
 * @param props.action Action.
 */
function ChangedActionRow( { action }: { action: ChangedAction } ) {
	const rows: Array< [ string, ReactNode ] > = [];
	if ( action.before_schedule_type !== action.after_schedule_type ) {
		rows.push( [
			__( 'Schedule type', 'updatelens' ),
			<>
				{ actionScheduleTypeLabel( action.before_schedule_type ) }
				<To />
				{ actionScheduleTypeLabel( action.after_schedule_type ) }
			</>,
		] );
	}
	if ( action.before_interval !== action.after_interval ) {
		rows.push( [
			__( 'Interval', 'updatelens' ),
			<>
				{ everyText( action.before_interval ) ??
					__( 'None', 'updatelens' ) }
				<To />
				{ everyText( action.after_interval ) ??
					__( 'None', 'updatelens' ) }
			</>,
		] );
	}
	if ( action.before_cron_expression !== action.after_cron_expression ) {
		rows.push( [
			__( 'Cron expression', 'updatelens' ),
			<>
				{ action.before_cron_expression === null ? (
					__( 'None', 'updatelens' )
				) : (
					<Expression expression={ action.before_cron_expression } />
				) }
				<To />
				{ action.after_cron_expression === null ? (
					__( 'None', 'updatelens' )
				) : (
					<Expression expression={ action.after_cron_expression } />
				) }
			</>,
		] );
	}
	rows.push( [
		__( 'Scheduled time', 'updatelens' ),
		action.timestamp_changed ? (
			<>
				<Time timestamp={ action.before_timestamp } />
				<To />
				<Time timestamp={ action.after_timestamp } />
			</>
		) : (
			<>
				<Time timestamp={ action.after_timestamp } />{ ' ' }
				<span className="text-muted-foreground">
					{ __( '(unchanged)', 'updatelens' ) }
				</span>
			</>
		),
	] );

	return (
		<li className="space-y-1.5 border-l-2 border-l-sky-300 px-4 py-2.5">
			<ActionName action={ action } />
			<p className="text-sm font-medium">
				{ __( 'Schedule changed', 'updatelens' ) }
			</p>
			<Details rows={ rows } />
		</li>
	);
}

/**
 * The same action with the same schedule at another time. Observed only:
 * recurring actions move whenever they run.
 *
 * @param props        Props.
 * @param props.action Action.
 */
function RescheduledActionRow( { action }: { action: RescheduledAction } ) {
	const delta = formatDurationDelta( action.timestamp_delta );

	return (
		<li className="space-y-1 border-l-2 border-l-slate-200 px-4 py-2.5 text-muted-foreground">
			<ActionName action={ action } />
			<p className="text-sm">
				<Time timestamp={ action.before_timestamp } />
				<To />
				<Time timestamp={ action.after_timestamp } />
				{ delta && (
					<>
						{ ' ' }
						<span className="ml-1 tabular-nums">({ delta })</span>
					</>
				) }
			</p>
			<p className="text-sm">
				<Schedule
					type={ action.schedule_type }
					interval={ action.interval }
					expression={ action.cron_expression }
				/>
			</p>
		</li>
	);
}
