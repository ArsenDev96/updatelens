import { _n, __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type {
	ActionSchedulerActionState,
	ActionSchedulerDiffSummary,
	ActionScheduleType,
	AvailableActionSchedulerPhase,
	ChangedAction,
	PhaseKey,
	ReportPhase,
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
	noChangesText,
	phaseLabel,
	signalUnavailableText,
} from '../utils/labels';
import { Time, TimeRange, To } from './DiffRow';
import { DiffSection } from './DiffSection';
import {
	ChangeMark,
	ChangeRow,
	RowDetails,
	SignalEmptyState,
	SignalNote,
	SignalSummary,
	SignalTotals,
	StatePill,
} from './SignalParts';

/*
 * The Action Scheduler page of one phase: a diff of active (pending or
 * in-progress) actions between two observation points, not execution
 * history. Only hooks, groups, statuses, times and normalized schedules
 * exist in the API: arguments are never stored, so they are never shown.
 * An action that is no longer active may have run, been canceled or left
 * the queue otherwise, which UpdateLens cannot tell apart. Groups are
 * observed metadata, never shown as ownership.
 */

/**
 * Action Scheduler in one phase: summary, action lists, notes and site
 * totals, or one calm empty or unavailable state.
 *
 * @param props               Props.
 * @param props.phase         Phase.
 * @param props.data          Phase data of every signal.
 * @param props.count         Action Scheduler change count, null if unavailable.
 * @param props.windowSeconds Observation window length from the API.
 */
export function ActionSchedulerDetail( {
	phase,
	data,
	count,
	windowSeconds,
}: {
	phase: PhaseKey;
	data: ReportPhase;
	count: number | null;
	windowSeconds: number;
} ) {
	const actions = data.action_scheduler;
	if ( count === null || count === 0 || ! actions.available ) {
		const unavailable = count === null;
		const text = unavailable
			? signalUnavailableText( 'action_scheduler', data, windowSeconds )
			: noChangesText( 'action_scheduler' );
		return (
			<SignalEmptyState
				icon={ unavailable ? 'info' : 'action_scheduler' }
				title={ text?.title ?? '' }
				description={ text?.description ?? '' }
			/>
		);
	}

	const summary = actions.summary;
	return (
		<div className="space-y-8">
			<SignalSummary
				id="updatelens-as-summary"
				eyebrow={ phaseLabel( phase ) }
				headline={ sprintf(
					/* translators: %s: number of observed Action Scheduler changes in a phase. */
					_n(
						'%s observed Action Scheduler change',
						'%s observed Action Scheduler changes',
						count,
						'updatelens'
					),
					formatCount( count )
				) }
				counts={ [
					{
						kind: 'added',
						label: __( 'Added', 'updatelens' ),
						value: formatCount( summary.added_count ),
						quiet: summary.added_count === 0,
					},
					{
						kind: 'gone',
						/* translators: Summary count of Action Scheduler actions active before and not active after (ran, canceled or otherwise left the queue). */
						label: __( 'No longer active', 'updatelens' ),
						value: formatCount( summary.removed_count ),
						quiet: summary.removed_count === 0,
					},
					{
						kind: 'changed',
						label: __( 'Changed', 'updatelens' ),
						value: formatCount( summary.changed_count ),
						quiet: summary.changed_count === 0,
					},
					{
						kind: 'rescheduled',
						label: __( 'Rescheduled', 'updatelens' ),
						value: formatCount( summary.rescheduled_count ),
						// Recurring actions move whenever they run: never emphasized.
						quiet: true,
					},
				] }
				note={ actionSchedulerPhaseNote() }
			/>
			<ActionSchedulerDiffList phase={ actions } />
			<SignalTotals
				id="updatelens-as-totals"
				label={ __( 'Action Scheduler totals', 'updatelens' ) }
				items={ totals( summary ) }
			/>
		</div>
	);
}

/**
 * Active actions of the site before → after, with deltas.
 *
 * @param summary Action Scheduler phase summary.
 */
function totals(
	summary: ActionSchedulerDiffSummary
): Array< [ string, string, string, string ] > {
	return [
		[
			__( 'Active actions', 'updatelens' ),
			formatCount( summary.before_action_count ),
			formatCount( summary.after_action_count ),
			formatCountDelta( summary.action_count_delta ),
		],
		[
			__( 'Recurring', 'updatelens' ),
			formatCount( summary.before_recurring_count ),
			formatCount( summary.after_recurring_count ),
			formatCountDelta( summary.recurring_count_delta ),
		],
		[
			__( 'One-time', 'updatelens' ),
			formatCount( summary.before_single_count ),
			formatCount( summary.after_single_count ),
			formatCountDelta( summary.single_count_delta ),
		],
		[
			__( 'Unique hooks', 'updatelens' ),
			formatCount( summary.before_unique_hook_count ),
			formatCount( summary.after_unique_hook_count ),
			formatCountDelta( summary.unique_hook_count_delta ),
		],
	];
}

/**
 * Action lists of one phase with changes, then the notes that apply to
 * them. Empty lists are left out (the summary shows their zero count); long
 * lists collapse.
 *
 * @param props       Props.
 * @param props.phase Available Action Scheduler phase with at least one change.
 */
function ActionSchedulerDiffList( {
	phase,
}: {
	phase: AvailableActionSchedulerPhase;
} ) {
	const key = ( action: { hook: string; group: string }, index: number ) =>
		`${ action.hook }-${ action.group }-${ index }`;

	return (
		<div className="space-y-6">
			{ phase.added.length > 0 && (
				<DiffSection
					id="updatelens-as-added"
					appearance="card"
					icon={ <ChangeMark kind="added" /> }
					title={ sprintf(
						/* translators: %s: number of listed items (options, WP-Cron events or Action Scheduler actions). */
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
					appearance="card"
					icon={ <ChangeMark kind="gone" /> }
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
					appearance="card"
					icon={ <ChangeMark kind="changed" /> }
					title={ sprintf(
						/* translators: %s: number of listed items (options, WP-Cron events or Action Scheduler actions). */
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
					appearance="card"
					icon={ <ChangeMark kind="rescheduled" /> }
					title={ sprintf(
						/* translators: %s: number of listed items (options, WP-Cron events or Action Scheduler actions). */
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
			<div className="space-y-2">
				<SignalNote>
					{ __(
						'Action arguments are fingerprinted for matching but are never stored or shown. Similar-looking rows may represent different argument sets.',
						'updatelens'
					) }
				</SignalNote>
				{ phase.removed.length > 0 && (
					<SignalNote>
						{ __(
							'An action may leave the active queue because it ran, was canceled, or otherwise changed state.',
							'updatelens'
						) }
					</SignalNote>
				) }
				<SignalNote>
					{ __(
						'UpdateLens compares active Action Scheduler state at observation points. Very short-lived actions that are queued and completed between captures may not appear.',
						'updatelens'
					) }
				</SignalNote>
			</div>
		</div>
	);
}

/**
 * An action's group as secondary metadata (observed, never ownership), or
 * nothing for actions without a group.
 *
 * @param group Group slug, '' for none.
 */
function groupLine( group: string ): ReactNode {
	return group === '' ? undefined : (
		<>
			{ __( 'Group:', 'updatelens' ) }{ ' ' }
			<span className="font-mono">{ group }</span>
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
 * A cron expression in monospace, exactly as stored.
 *
 * @param props            Props.
 * @param props.expression Normalized cron expression.
 */
function Expression( { expression }: { expression: string } ) {
	return (
		<code className="m-0 bg-transparent p-0 font-mono text-[12px] [overflow-wrap:anywhere]">
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
		<ChangeRow
			kind="added"
			name={ action.hook }
			meta={ groupLine( action.group ) }
			facts={
				<StatePill>
					<Schedule
						type={ action.schedule_type }
						interval={ action.interval }
						expression={ action.cron_expression }
					/>
				</StatePill>
			}
		>
			<RowDetails
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
		</ChangeRow>
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
		<ChangeRow
			kind="gone"
			name={ action.hook }
			meta={ groupLine( action.group ) }
			facts={
				<StatePill muted>
					{ __( 'No longer active', 'updatelens' ) } ·{ ' ' }
					<Schedule
						type={ action.schedule_type }
						interval={ action.interval }
						expression={ action.cron_expression }
					/>
				</StatePill>
			}
		>
			<RowDetails
				rows={ [
					[
						__( 'Previously scheduled for', 'updatelens' ),
						<span key="t">
							<Time timestamp={ action.timestamp } />
							{ action.status === 'in-progress' && (
								<>
									{ ' · ' }
									{ __( 'was in progress', 'updatelens' ) }
								</>
							) }
						</span>,
					],
				] }
			/>
		</ChangeRow>
	);
}

/**
 * The same action (hook, group and arguments) with another schedule. Only
 * the schedule fields that differ are listed, then the scheduled time.
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
			<TimeRange
				from={ action.before_timestamp }
				to={ action.after_timestamp }
			/>
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
		<ChangeRow
			kind="changed"
			name={ action.hook }
			meta={ groupLine( action.group ) }
			facts={
				<span className="font-medium text-slate-800">
					{ __( 'Schedule changed', 'updatelens' ) }
				</span>
			}
		>
			<RowDetails boxed rows={ rows } />
		</ChangeRow>
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
		<ChangeRow
			kind="rescheduled"
			name={ action.hook }
			meta={ groupLine( action.group ) }
			quiet
			facts={
				<StatePill muted>
					<Schedule
						type={ action.schedule_type }
						interval={ action.interval }
						expression={ action.cron_expression }
					/>
				</StatePill>
			}
		>
			<RowDetails
				rows={ [
					[
						timeLabel( action.is_recurring ),
						<span key="t" className="tabular-nums">
							<TimeRange
								from={ action.before_timestamp }
								to={ action.after_timestamp }
							/>
							{ delta && (
								<span className="ml-1.5 text-muted-foreground">
									({ delta })
								</span>
							) }
						</span>,
					],
				] }
			/>
		</ChangeRow>
	);
}
