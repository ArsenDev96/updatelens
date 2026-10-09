import { _n, __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type {
	AvailableCronPhase,
	ChangedCronEvent,
	CronDiffSummary,
	CronEventState,
	PhaseKey,
	ReportPhase,
	RescheduledCronEvent,
} from '../types/api';
import {
	formatCount,
	formatCountDelta,
	formatDuration,
	formatDurationDelta,
} from '../utils/format';
import {
	cronPhaseNote,
	noChangesText,
	phaseLabel,
	signalUnavailableText,
} from '../utils/labels';
import { Time, To } from './DiffRow';
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
 * The WP-Cron page of one phase. Only hooks, timing and recurrence exist in
 * the API: event arguments are never stored, so they are never shown.
 * Rescheduling is shown with less emphasis than additions, removals and
 * recurrence changes, because normal WP-Cron runs move recurring events.
 * A one-time event that disappeared is "no longer scheduled": it may have
 * run or been unscheduled, which UpdateLens cannot tell apart.
 */

/**
 * WP-Cron in one phase: summary, event lists, notes and site totals, or one
 * calm empty or unavailable state.
 *
 * @param props               Props.
 * @param props.phase         Phase.
 * @param props.data          Phase data of every signal.
 * @param props.count         WP-Cron change count, null if unavailable.
 * @param props.windowSeconds Observation window length from the API.
 */
export function CronDetail( {
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
	const cron = data.cron;
	if ( count === null || count === 0 || ! cron.available ) {
		const unavailable = count === null;
		const text = unavailable
			? signalUnavailableText( 'cron', data, windowSeconds )
			: noChangesText( 'cron' );
		return (
			<SignalEmptyState
				icon={ unavailable ? 'info' : 'cron' }
				title={ text?.title ?? '' }
				description={ text?.description ?? '' }
			/>
		);
	}

	const summary = cron.summary;
	return (
		<div className="space-y-8">
			<SignalSummary
				id="updatelens-cron-summary"
				eyebrow={ phaseLabel( phase ) }
				headline={ sprintf(
					/* translators: %s: number of observed WP-Cron changes in a phase. */
					_n(
						'%s observed WP-Cron change',
						'%s observed WP-Cron changes',
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
						kind: 'removed',
						/* translators: Summary count of WP-Cron events present before and absent after (removed or no longer scheduled). */
						label: __( 'No longer present', 'updatelens' ),
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
						// Often a normal run: never emphasized.
						quiet: true,
					},
				] }
				note={ cronPhaseNote() }
			/>
			<CronDiffList phase={ cron } />
			<SignalTotals
				id="updatelens-cron-totals"
				label={ __( 'WP-Cron totals', 'updatelens' ) }
				items={ totals( summary ) }
			/>
		</div>
	);
}

/**
 * All WP-Cron events of the site before → after, with deltas.
 *
 * @param summary Cron phase summary.
 */
function totals(
	summary: CronDiffSummary
): Array< [ string, string, string, string ] > {
	return [
		[
			__( 'Events', 'updatelens' ),
			formatCount( summary.before_event_count ),
			formatCount( summary.after_event_count ),
			formatCountDelta( summary.event_count_delta ),
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
 * Event lists of one Cron phase with changes, then the notes that apply to
 * them. Empty lists are left out (the summary shows their zero count); long
 * lists collapse. Removed one-time events are listed as "no longer
 * scheduled", recurring ones as removed.
 *
 * @param props       Props.
 * @param props.phase Available Cron phase with at least one change.
 */
function CronDiffList( { phase }: { phase: AvailableCronPhase } ) {
	const removed = phase.removed.filter( ( event ) => event.is_recurring );
	const gone = phase.removed.filter( ( event ) => ! event.is_recurring );
	const movedOnce = phase.rescheduled.some(
		( event ) => ! event.is_recurring
	);
	const titles = {
		/* translators: %s: number of listed items (options, WP-Cron events or Action Scheduler actions). */
		added: sprintf(
			__( 'Added (%s)', 'updatelens' ),
			formatCount( phase.added.length )
		),
		/* translators: %s: number of listed items (options, WP-Cron events or Action Scheduler actions). */
		removed: sprintf(
			__( 'Removed (%s)', 'updatelens' ),
			formatCount( removed.length )
		),
		/* translators: %s: number of one-time WP-Cron events that disappeared. */
		gone: sprintf(
			__( 'No longer scheduled (%s)', 'updatelens' ),
			formatCount( gone.length )
		),
		/* translators: %s: number of listed items (options, WP-Cron events or Action Scheduler actions). */
		changed: sprintf(
			__( 'Changed (%s)', 'updatelens' ),
			formatCount( phase.changed.length )
		),
		/* translators: %s: number of listed items (options, WP-Cron events or Action Scheduler actions). */
		rescheduled: sprintf(
			__( 'Rescheduled (%s)', 'updatelens' ),
			formatCount( phase.rescheduled.length )
		),
	};
	const key = ( event: { hook: string }, index: number ) =>
		`${ event.hook }-${ index }`;

	return (
		<div className="space-y-6">
			{ phase.added.length > 0 && (
				<DiffSection
					id="updatelens-cron-added"
					appearance="card"
					icon={ <ChangeMark kind="added" /> }
					title={ titles.added }
					items={ phase.added }
					renderItem={ ( event, index ) => (
						<AddedEventRow
							key={ key( event, index ) }
							event={ event }
						/>
					) }
					moreLabel={ ( hidden ) =>
						sprintf(
							/* translators: %s: number of hidden rows. */
							_n(
								'Show %s more added event',
								'Show %s more added events',
								hidden,
								'updatelens'
							),
							formatCount( hidden )
						)
					}
					lessLabel={ __( 'Show fewer added events', 'updatelens' ) }
				/>
			) }
			{ removed.length > 0 && (
				<DiffSection
					id="updatelens-cron-removed"
					appearance="card"
					icon={ <ChangeMark kind="removed" /> }
					title={ titles.removed }
					items={ removed }
					renderItem={ ( event, index ) => (
						<RemovedEventRow
							key={ key( event, index ) }
							event={ event }
						/>
					) }
					moreLabel={ ( hidden ) =>
						sprintf(
							/* translators: %s: number of hidden rows. */
							_n(
								'Show %s more removed event',
								'Show %s more removed events',
								hidden,
								'updatelens'
							),
							formatCount( hidden )
						)
					}
					lessLabel={ __(
						'Show fewer removed events',
						'updatelens'
					) }
				/>
			) }
			{ gone.length > 0 && (
				<DiffSection
					id="updatelens-cron-gone"
					appearance="card"
					icon={ <ChangeMark kind="gone" /> }
					title={ titles.gone }
					items={ gone }
					renderItem={ ( event, index ) => (
						<GoneEventRow
							key={ key( event, index ) }
							event={ event }
						/>
					) }
					moreLabel={ ( hidden ) =>
						sprintf(
							/* translators: %s: number of hidden rows. */
							_n(
								'Show %s more one-time event no longer scheduled',
								'Show %s more one-time events no longer scheduled',
								hidden,
								'updatelens'
							),
							formatCount( hidden )
						)
					}
					lessLabel={ __(
						'Show fewer one-time events no longer scheduled',
						'updatelens'
					) }
				/>
			) }
			{ phase.changed.length > 0 && (
				<DiffSection
					id="updatelens-cron-changed"
					appearance="card"
					icon={ <ChangeMark kind="changed" /> }
					title={ titles.changed }
					items={ phase.changed }
					renderItem={ ( event, index ) => (
						<ChangedEventRow
							key={ key( event, index ) }
							event={ event }
						/>
					) }
					moreLabel={ ( hidden ) =>
						sprintf(
							/* translators: %s: number of hidden rows. */
							_n(
								'Show %s more changed event',
								'Show %s more changed events',
								hidden,
								'updatelens'
							),
							formatCount( hidden )
						)
					}
					lessLabel={ __(
						'Show fewer changed events',
						'updatelens'
					) }
				/>
			) }
			{ phase.rescheduled.length > 0 && (
				<DiffSection
					id="updatelens-cron-rescheduled"
					appearance="card"
					icon={ <ChangeMark kind="rescheduled" /> }
					title={ titles.rescheduled }
					items={ phase.rescheduled }
					renderItem={ ( event, index ) => (
						<RescheduledEventRow
							key={ key( event, index ) }
							event={ event }
						/>
					) }
					moreLabel={ ( hidden ) =>
						sprintf(
							/* translators: %s: number of hidden rows. */
							_n(
								'Show %s more rescheduled event',
								'Show %s more rescheduled events',
								hidden,
								'updatelens'
							),
							formatCount( hidden )
						)
					}
					lessLabel={ __(
						'Show fewer rescheduled events',
						'updatelens'
					) }
					quiet
				/>
			) }
			<div className="space-y-2">
				<SignalNote>
					{ __(
						'WP-Cron event arguments are fingerprinted for matching but are never stored or shown. Events with the same hook may therefore represent different argument sets.',
						'updatelens'
					) }
				</SignalNote>
				{ gone.length > 0 && (
					<SignalNote>
						{ __(
							'One-time jobs may disappear because they ran or were unscheduled.',
							'updatelens'
						) }
					</SignalNote>
				) }
				{ movedOnce && (
					<SignalNote>
						{ __(
							'UpdateLens observes scheduled state. A moved one-time event may represent a reschedule or a new equivalent event after execution.',
							'updatelens'
						) }
					</SignalNote>
				) }
			</div>
		</div>
	);
}

/**
 * "Recurring · daily" or "One-time".
 *
 * @param schedule    Schedule name or null.
 * @param isRecurring Whether the event recurs.
 */
function recurrence( schedule: string | null, isRecurring: boolean ): string {
	return isRecurring
		? sprintf(
				/* translators: %s: WP-Cron schedule name, e.g. "daily". */
				__( 'Recurring · %s', 'updatelens' ),
				schedule ?? ''
			)
		: __( 'One-time', 'updatelens' );
}

function intervalText( interval: number | null ): string {
	return interval === null
		? __( 'Not stored', 'updatelens' )
		: formatDuration( interval );
}

/**
 * An event that appeared (state after).
 *
 * @param props       Props.
 * @param props.event Event.
 */
function AddedEventRow( { event }: { event: CronEventState } ) {
	const rows: Array< [ string, ReactNode ] > = [
		[
			__( 'Next run', 'updatelens' ),
			<Time key="t" timestamp={ event.timestamp } />,
		],
	];
	if ( event.is_recurring ) {
		rows.push( [
			__( 'Interval', 'updatelens' ),
			intervalText( event.interval ),
		] );
	}

	return (
		<ChangeRow
			kind="added"
			name={ event.hook }
			record={ event }
			facts={
				<StatePill>
					{ recurrence( event.schedule, event.is_recurring ) }
				</StatePill>
			}
		>
			<RowDetails rows={ rows } />
		</ChangeRow>
	);
}

/**
 * A recurring event that disappeared (state before).
 *
 * @param props       Props.
 * @param props.event Event.
 */
function RemovedEventRow( { event }: { event: CronEventState } ) {
	return (
		<ChangeRow
			kind="removed"
			name={ event.hook }
			record={ event }
			facts={
				<StatePill>
					{ recurrence( event.schedule, event.is_recurring ) }
				</StatePill>
			}
		>
			<RowDetails
				rows={ [
					[
						__( 'Was scheduled for', 'updatelens' ),
						<Time key="t" timestamp={ event.timestamp } />,
					],
				] }
			/>
		</ChangeRow>
	);
}

/**
 * A one-time event that is no longer scheduled (state before). It may have
 * run or been unscheduled; UpdateLens observes only that it is gone.
 *
 * @param props       Props.
 * @param props.event Event.
 */
function GoneEventRow( { event }: { event: CronEventState } ) {
	return (
		<ChangeRow
			kind="gone"
			name={ event.hook }
			record={ event }
			facts={
				<StatePill muted>
					{ __( 'One-time', 'updatelens' ) } ·{ ' ' }
					{ __( 'No longer scheduled', 'updatelens' ) }
				</StatePill>
			}
		>
			<RowDetails
				rows={ [
					[
						__( 'Previously scheduled for', 'updatelens' ),
						<Time key="t" timestamp={ event.timestamp } />,
					],
				] }
			/>
		</ChangeRow>
	);
}

/**
 * The same event (hook and arguments) with another recurrence.
 *
 * @param props       Props.
 * @param props.event Event.
 */
function ChangedEventRow( { event }: { event: ChangedCronEvent } ) {
	const typeChanged = event.before_is_recurring !== event.after_is_recurring;
	const rows: Array< [ string, ReactNode ] > = [
		[
			__( 'Schedule', 'updatelens' ),
			typeChanged ? (
				<>
					{ recurrence(
						event.before_schedule,
						event.before_is_recurring
					) }
					<To />
					{ recurrence(
						event.after_schedule,
						event.after_is_recurring
					) }
				</>
			) : (
				<>
					{ event.before_schedule }
					<To />
					{ event.after_schedule }
				</>
			),
		],
	];
	if ( event.before_interval !== event.after_interval ) {
		rows.push( [
			__( 'Interval', 'updatelens' ),
			<>
				{ event.before_is_recurring
					? intervalText( event.before_interval )
					: __( 'None', 'updatelens' ) }
				<To />
				{ event.after_is_recurring
					? intervalText( event.after_interval )
					: __( 'None', 'updatelens' ) }
			</>,
		] );
	}
	rows.push( [
		__( 'Next run', 'updatelens' ),
		event.timestamp_changed ? (
			<>
				<Time timestamp={ event.before_timestamp } />
				<To />
				<Time timestamp={ event.after_timestamp } />
			</>
		) : (
			<>
				<Time timestamp={ event.after_timestamp } />{ ' ' }
				<span className="text-muted-foreground">
					{ __( '(unchanged)', 'updatelens' ) }
				</span>
			</>
		),
	] );

	return (
		<ChangeRow
			kind="changed"
			name={ event.hook }
			record={ event }
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
 * The same event with the same recurrence at another time. Observed only:
 * normal WP-Cron runs move recurring events too.
 *
 * @param props       Props.
 * @param props.event Event.
 */
function RescheduledEventRow( { event }: { event: RescheduledCronEvent } ) {
	const delta = formatDurationDelta( event.timestamp_delta );

	return (
		<ChangeRow
			kind="rescheduled"
			name={ event.hook }
			record={ event }
			quiet
			facts={
				<StatePill muted>
					{ recurrence( event.schedule, event.is_recurring ) }
				</StatePill>
			}
		>
			<RowDetails
				rows={ [
					[
						__( 'Next run', 'updatelens' ),
						<span key="t" className="tabular-nums">
							<Time timestamp={ event.before_timestamp } />
							<To />
							<Time timestamp={ event.after_timestamp } />
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
