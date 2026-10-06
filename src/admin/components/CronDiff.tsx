import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

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
	formatUnixDateTime,
	unixToIso,
} from '../utils/format';
import { Metric, Total } from './PhaseSummary';

/*
 * WP-Cron changes of one phase. Only hooks, timing and recurrence exist in
 * the API: event arguments are never stored, so they are never shown.
 * Rescheduling is shown with less emphasis than additions, removals and
 * recurrence changes, because normal WP-Cron runs move recurring events.
 */

/**
 * Added, removed, changed and rescheduled counts, then before → after totals.
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
					label={ __( 'Removed', 'updatelens' ) }
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
 * Event lists of one Cron phase, then the notes on hidden arguments and
 * one-time events. Every section is always shown; an empty one says so.
 *
 * @param props       Props.
 * @param props.phase Available Cron phase.
 */
export function CronDiffList( { phase }: { phase: AvailableCronPhase } ) {
	const titles = {
		/* translators: %d: number of WP-Cron events. */
		added: sprintf( __( 'Added (%d)', 'updatelens' ), phase.added.length ),
		/* translators: %d: number of WP-Cron events. */
		removed: sprintf(
			__( 'Removed (%d)', 'updatelens' ),
			phase.removed.length
		),
		/* translators: %d: number of WP-Cron events. */
		changed: sprintf(
			__( 'Changed (%d)', 'updatelens' ),
			phase.changed.length
		),
		/* translators: %d: number of WP-Cron events. */
		rescheduled: sprintf(
			__( 'Rescheduled (%d)', 'updatelens' ),
			phase.rescheduled.length
		),
	};

	return (
		<div className="space-y-5">
			<Section
				id="added"
				title={ titles.added }
				empty={ __( 'No added events.', 'updatelens' ) }
				count={ phase.added.length }
			>
				{ phase.added.map( ( event, index ) => (
					<AddedEventRow
						key={ `${ event.hook }-${ index }` }
						event={ event }
					/>
				) ) }
			</Section>
			<Section
				id="removed"
				title={ titles.removed }
				empty={ __( 'No removed events.', 'updatelens' ) }
				count={ phase.removed.length }
			>
				{ phase.removed.map( ( event, index ) => (
					<RemovedEventRow
						key={ `${ event.hook }-${ index }` }
						event={ event }
					/>
				) ) }
			</Section>
			<Section
				id="changed"
				title={ titles.changed }
				empty={ __( 'No changed events.', 'updatelens' ) }
				count={ phase.changed.length }
			>
				{ phase.changed.map( ( event, index ) => (
					<ChangedEventRow
						key={ `${ event.hook }-${ index }` }
						event={ event }
					/>
				) ) }
			</Section>
			<Section
				id="rescheduled"
				title={ titles.rescheduled }
				empty={ __( 'No rescheduled events.', 'updatelens' ) }
				count={ phase.rescheduled.length }
				quiet
			>
				{ phase.rescheduled.map( ( event, index ) => (
					<RescheduledEventRow
						key={ `${ event.hook }-${ index }` }
						event={ event }
					/>
				) ) }
			</Section>
			<div className="space-y-1 text-xs text-muted-foreground">
				<p>
					{ __(
						'WP-Cron event arguments are fingerprinted for matching but are never stored or shown. Events with the same hook may therefore represent different argument sets.',
						'updatelens'
					) }
				</p>
				<p>
					{ __(
						'UpdateLens observes scheduled state. A moved one-time event may represent a reschedule or a new equivalent event after execution.',
						'updatelens'
					) }
				</p>
			</div>
		</div>
	);
}

function Section( {
	id,
	title,
	empty,
	count,
	quiet = false,
	children,
}: {
	id: string;
	title: string;
	empty: string;
	count: number;
	quiet?: boolean;
	children: ReactNode;
} ) {
	const headingId = `updatelens-cron-${ id }`;
	return (
		<section aria-labelledby={ headingId } className="space-y-2">
			<h3
				id={ headingId }
				className={ cn(
					'text-sm',
					quiet
						? 'font-medium text-muted-foreground'
						: 'font-semibold'
				) }
			>
				{ title }
			</h3>
			{ count === 0 ? (
				<p className="text-sm text-muted-foreground">{ empty }</p>
			) : (
				<ul className="divide-y overflow-hidden rounded-lg border bg-card">
					{ children }
				</ul>
			) }
		</section>
	);
}

function HookName( { hook }: { hook: string } ) {
	return (
		<code className="m-0 block select-text break-all bg-transparent p-0 font-mono text-[13px] text-foreground">
			{ hook }
		</code>
	);
}

function Time( { timestamp }: { timestamp: number } ) {
	return (
		<time dateTime={ unixToIso( timestamp ) }>
			{ formatUnixDateTime( timestamp ) }
		</time>
	);
}

function To() {
	return (
		<>
			{ ' ' }
			<span aria-hidden="true">→</span>
			<span className="sr-only">{ __( 'to', 'updatelens' ) }</span>{ ' ' }
		</>
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
 * Definition list of a Cron row.
 *
 * @param props      Props.
 * @param props.rows Label and content pairs.
 */
function Details( { rows }: { rows: Array< [ string, ReactNode ] > } ) {
	return (
		<dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[auto_minmax(0,1fr)]">
			{ rows.map( ( [ label, content ] ) => (
				<div key={ label } className="contents">
					<dt className="text-muted-foreground">{ label }</dt>
					<dd className="min-w-0 break-words">{ content }</dd>
				</div>
			) ) }
		</dl>
	);
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
 * An event that disappeared (state before).
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
