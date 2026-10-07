import { _n, __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type {
	AvailableCronPhase,
	ChangedCronEvent,
	CronDiffSummary,
	CronEventState,
	RescheduledCronEvent,
} from '../types/api';
import {
	formatCount,
	formatCountDelta,
	formatDuration,
	formatDurationDelta,
} from '../utils/format';
import { Details, HookName, Time, To } from './DiffRow';
import { DiffSection } from './DiffSection';
import { Metric, Total } from './PhaseSummary';

/*
 * WP-Cron changes of one phase. Only hooks, timing and recurrence exist in
 * the API: event arguments are never stored, so they are never shown.
 * Rescheduling is shown with less emphasis than additions, removals and
 * recurrence changes, because normal WP-Cron runs move recurring events.
 * A one-time event that disappeared is "no longer scheduled": it may have
 * run or been unscheduled, which UpdateLens cannot tell apart.
 */

/**
 * Added, no longer present, changed and rescheduled counts, then before →
 * after totals. "No longer present" covers every event of the backend's
 * `removed` category, recurring ("Removed") and one-time ("No longer
 * scheduled") alike.
 *
 * @param props         Props.
 * @param props.summary Cron phase summary.
 */
export function CronSummary( { summary }: { summary: CronDiffSummary } ) {
	return (
		<div className="space-y-3">
			<dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
				<Metric
					label={ __( 'Added', 'updatelens' ) }
					value={ formatCount( summary.added_count ) }
				/>
				<Metric
					label={
						/* translators: Summary count of WP-Cron events present before and absent after (removed or no longer scheduled). */
						__( 'No longer present', 'updatelens' )
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
					label={ __( 'Events', 'updatelens' ) }
					before={ formatCount( summary.before_event_count ) }
					after={ formatCount( summary.after_event_count ) }
					delta={ formatCountDelta( summary.event_count_delta ) }
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
 * Event lists of one Cron phase with changes, then the notes that apply to
 * them. Empty lists are left out (the summary shows their zero count); long
 * lists collapse. Removed one-time events are listed as "no longer
 * scheduled", recurring ones as removed.
 *
 * @param props       Props.
 * @param props.phase Available Cron phase with at least one change.
 */
export function CronDiffList( { phase }: { phase: AvailableCronPhase } ) {
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
		<div className="space-y-5">
			{ phase.added.length > 0 && (
				<DiffSection
					id="updatelens-cron-added"
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
			<div className="space-y-1 text-xs text-muted-foreground">
				<p>
					{ __(
						'WP-Cron event arguments are fingerprinted for matching but are never stored or shown. Events with the same hook may therefore represent different argument sets.',
						'updatelens'
					) }
				</p>
				{ gone.length > 0 && (
					<p>
						{ __(
							'One-time jobs may disappear because they ran or were unscheduled.',
							'updatelens'
						) }
					</p>
				) }
				{ movedOnce && (
					<p>
						{ __(
							'UpdateLens observes scheduled state. A moved one-time event may represent a reschedule or a new equivalent event after execution.',
							'updatelens'
						) }
					</p>
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
		<li className="space-y-1.5 border-l-2 border-l-emerald-400 px-4 py-2.5">
			<HookName hook={ event.hook } />
			<p className="text-sm">
				{ recurrence( event.schedule, event.is_recurring ) }
			</p>
			<Details rows={ rows } />
		</li>
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
		<li className="space-y-1.5 border-l-2 border-l-rose-300 px-4 py-2.5">
			<HookName hook={ event.hook } />
			<p className="text-sm">
				{ recurrence( event.schedule, event.is_recurring ) }
			</p>
			<p className="text-sm text-muted-foreground">
				{ __( 'Was scheduled for', 'updatelens' ) }{ ' ' }
				<Time timestamp={ event.timestamp } />
			</p>
		</li>
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
		<li className="space-y-1.5 border-l-2 border-l-slate-300 px-4 py-2.5">
			<HookName hook={ event.hook } />
			<p className="text-sm">
				{ __( 'One-time', 'updatelens' ) } ·{ ' ' }
				{ __( 'No longer scheduled', 'updatelens' ) }
			</p>
			<p className="text-sm text-muted-foreground">
				{ __( 'Previously scheduled for', 'updatelens' ) }{ ' ' }
				<Time timestamp={ event.timestamp } />
			</p>
		</li>
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
		<li className="space-y-1.5 border-l-2 border-l-sky-300 px-4 py-2.5">
			<HookName hook={ event.hook } />
			<p className="text-sm font-medium">
				{ __( 'Schedule changed', 'updatelens' ) }
			</p>
			<Details rows={ rows } />
		</li>
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
		<li className="space-y-1 border-l-2 border-l-slate-200 px-4 py-2.5 text-muted-foreground">
			<HookName hook={ event.hook } />
			<p className="text-sm">
				<Time timestamp={ event.before_timestamp } />
				<To />
				<Time timestamp={ event.after_timestamp } />
				{ delta && (
					<>
						{ ' ' }
						<span className="ml-1 tabular-nums">({ delta })</span>
					</>
				) }
			</p>
			<p className="text-sm">
				{ recurrence( event.schedule, event.is_recurring ) }
			</p>
		</li>
	);
}
