import { __ } from '@wordpress/i18n';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import type { PendingRefresh } from '../hooks/use-pending-refresh';
import type {
	SiteCheckComparison,
	SiteCheckId,
	SiteCheckImpact,
	SiteChecks,
	SiteCheckSide,
} from '../types/api';
import type { ImpactView } from '../utils/impact';
import { requestFailureText, type Tone } from '../utils/labels';
import {
	attemptView,
	requestLabel,
	sideCellParts,
	sideSummaryText,
	sideTimingText,
	SITE_CHECKS,
	siteCheckNextTimeText,
} from '../utils/siteCheckImpact';
import {
	missingSideText,
	type RequestTimesView,
	sharedReason,
	siteCheckButtonId,
	siteCheckGuidanceReason,
	siteCheckNextStep,
	siteCheckReadingNote,
	siteCheckRequestTimes,
	siteCheckResultLabel,
	siteCheckRow,
	siteCheckViewKey,
	type SiteCheckRowText,
	siteChecksUnused,
	siteCheckTitle,
} from '../utils/siteChecks';
import { TONE_ICON } from '../utils/tone';
import { Icon, type IconName } from './Icon';

/*
 * The "Website checks" tab of a report: what the administrator's browser
 * observed when it requested the homepage and the WordPress REST API before
 * and after the update. Not part of any phase and not a configuration
 * change.
 *
 * Two compact rows, always shown (also when both passed): the check, what it
 * returned before and after the update, and the API's comparison, with how
 * long each side's first request took when it measured a complete response
 * (the difference only for equivalent ones; never a finding). A reason
 * both checks share, and how to get a comparison on a future update, are said
 * once, above the rows. Every request, the verification and the timing are
 * behind each row's disclosure, closed by default. A check reads as passed
 * only when the API compared it as passed. Checks that were turned off,
 * never recorded or unsupported for the whole update are one quiet sentence
 * instead of rows. Findings stay in Update results; each side links to the
 * other.
 */

/** Relative link to the Settings screen (both are admin.php pages). */
const SETTINGS_HREF = 'admin.php?page=updatelens-settings';

/**
 * The tab's content.
 *
 * @param props                Props.
 * @param props.siteChecks     The report's `site_checks`.
 * @param props.impact         `potential_impact.site_checks`.
 * @param props.pendingRefresh Background reads while results are pending.
 * @param props.view           Expanded rows (shared with Update results).
 * @param props.onViewChange   Updates them.
 * @param props.onShowFinding  Opens a check's finding in Update results.
 */
export function SiteResponseChecks( {
	siteChecks,
	impact,
	pendingRefresh,
	view,
	onViewChange,
	onShowFinding,
}: {
	siteChecks?: SiteChecks;
	impact?: SiteCheckImpact;
	pendingRefresh?: PendingRefresh;
	view: ImpactView;
	onViewChange: ( view: ImpactView ) => void;
	onShowFinding: ( check: SiteCheckId ) => void;
} ) {
	const title = useRef< HTMLHeadingElement >( null );
	const refreshing =
		pendingRefresh !== undefined && pendingRefresh.state !== 'off';
	// The refresh line disappears when results arrive: if it had focus, focus
	// moves to the section title instead of being lost.
	const refreshFocused = useRef( false );
	useEffect( () => {
		if ( ! refreshing && refreshFocused.current ) {
			refreshFocused.current = false;
			if (
				document.activeElement === null ||
				document.activeElement === document.body
			) {
				title.current?.focus();
			}
		}
	}, [ refreshing ] );

	const unused = siteChecksUnused( impact, siteChecks );
	const checks = SITE_CHECKS.flatMap( ( check ) => {
		const comparison = siteChecks?.checks[ check ];
		return comparison
			? [
					{
						check,
						comparison,
						times: siteCheckRequestTimes( comparison ),
						text: siteCheckRow(
							check,
							comparison,
							impact?.checks[ check ]
						),
					},
				]
			: [];
	} );
	// A reason both checks share is said once, above them, under the status
	// they share.
	const texts = checks.map( ( { text } ) => text );
	const shared = sharedReason( texts );
	const sharedStatus =
		shared !== null &&
		texts.every( ( text ) => text.status === texts[ 0 ].status )
			? texts[ 0 ].status
			: null;
	// How to get a comparison next time, once, when how the update ran
	// explains why there is none. Nothing offers to compare this update later.
	const nextTime = [
		...new Set(
			checks
				.map( ( { comparison } ) =>
					siteCheckNextTimeText(
						siteCheckGuidanceReason( comparison )
					)
				)
				.filter( ( text ): text is string => text !== null )
		),
	];
	const toggle = ( check: SiteCheckId ) => {
		const open = { ...view.open };
		const key = siteCheckViewKey( check );
		if ( open[ key ] === undefined ) {
			open[ key ] = 1;
		} else {
			delete open[ key ];
		}
		onViewChange( { ...view, open } );
	};

	return (
		<section
			aria-labelledby="updatelens-site-checks-title"
			className="space-y-4"
		>
			<div>
				<h3
					id="updatelens-site-checks-title"
					ref={ title }
					tabIndex={ -1 }
					className="text-section-title font-semibold tracking-tight text-slate-950 focus:outline-none"
				>
					{ __( 'Website checks', 'updatelens' ) }
				</h3>
				{ /* Only for checks that ran: not for turned off, unsupported or unrecorded ones. */ }
				{ ! unused && (
					<p className="mt-1 text-section-description text-muted-foreground">
						{ __(
							'Compare your homepage and WordPress REST API responses around this update. Results are shown only for checks that actually ran. They do not test every page or feature.',
							'updatelens'
						) }
					</p>
				) }
			</div>
			{ unused ? (
				<p className="flex max-w-3xl gap-2 text-sm leading-relaxed text-slate-700">
					<Icon
						name="info"
						className="mt-0.5 size-4 text-slate-600"
					/>
					<span>
						{ unused.text }
						{ unused.reason === 'disabled' && (
							<>
								{ ' ' }
								<a
									href={ SETTINGS_HREF }
									className="font-medium text-primary underline-offset-2 hover:underline focus:shadow-none focus-visible:rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
								>
									{ __(
										'Website check settings',
										'updatelens'
									) }
								</a>
							</>
						) }
					</span>
				</p>
			) : (
				<>
					{ shared && (
						<Callout
							tone={ texts[ 0 ].tone }
							title={
								sharedStatus ??
								__( 'Not compared', 'updatelens' )
							}
						>
							<p>{ shared }</p>
							{ nextTime.map( ( sentence ) => (
								<p key={ sentence }>{ sentence }</p>
							) ) }
							{ /* Both waiting: how the report checks for them belongs here. */ }
							{ refreshing && texts[ 0 ].tone === 'progress' && (
								<PendingRefreshLine
									refresh={ pendingRefresh }
									onFocus={ () => {
										refreshFocused.current = true;
									} }
								/>
							) }
						</Callout>
					) }
					{ refreshing &&
						! ( shared && texts[ 0 ].tone === 'progress' ) && (
							<PendingRefreshLine
								refresh={ pendingRefresh }
								onFocus={ () => {
									refreshFocused.current = true;
								} }
							/>
						) }
					<ul
						aria-label={ __( 'Checks', 'updatelens' ) }
						className="space-y-3"
					>
						{ checks.map(
							( { check, comparison, times, text } ) => (
								<CheckRow
									key={ check }
									check={ check }
									comparison={ comparison }
									times={ times }
									text={
										// A shared reason is said once above; a newly
										// failing check's sentence would repeat its After
										// cell (the requests are in its details).
										shared ||
										comparison.state === 'newly_failing'
											? { ...text, detail: null }
											: text
									}
									result={ siteCheckResultLabel(
										comparison,
										impact?.checks[ check ]
									) }
									reason={ shared ?? text.detail }
									nextStep={
										text.finding
											? siteCheckNextStep(
													check,
													impact?.checks[ check ],
													comparison.after
												)
											: null
									}
									expanded={
										view.open[
											siteCheckViewKey( check )
										] !== undefined
									}
									onToggle={ () => toggle( check ) }
									onShowFinding={ () =>
										onShowFinding( check )
									}
								/>
							)
						) }
					</ul>
					{ checks.some( ( { times } ) => times !== null ) && (
						<p className="flex max-w-3xl gap-2 text-[13px] leading-5 text-slate-700">
							<Icon
								name="info"
								className="mt-0.5 size-4 shrink-0 text-slate-600"
							/>
							<span>
								{ __(
									'Request times are individual observations, not a performance benchmark.',
									'updatelens'
								) }
							</span>
						</p>
					) }
					{ ! shared && nextTime.length > 0 && (
						<div className="flex max-w-3xl gap-2 text-[13px] leading-5 text-slate-800">
							<Icon
								name="info"
								className="mt-0.5 size-4 shrink-0 text-slate-600"
							/>
							<div className="space-y-1">
								{ nextTime.map( ( sentence ) => (
									<p key={ sentence }>{ sentence }</p>
								) ) }
							</div>
						</div>
					) }
				</>
			) }
		</section>
	);
}

/**
 * Announces once that pending website check results arrived while the
 * report is open. Rendered with the report, whichever tab is shown.
 *
 * @param props            Props.
 * @param props.siteChecks The report's `site_checks`.
 */
export function SiteCheckArrival( {
	siteChecks,
}: {
	siteChecks?: SiteChecks;
} ) {
	const pending = siteChecks?.status === 'pending';
	const [ wasPending, setWasPending ] = useState( pending );
	const [ arrived, setArrived ] = useState( false );
	if ( wasPending !== pending ) {
		setWasPending( pending );
		setArrived( ! pending );
	}
	return (
		<p role="status" className="sr-only">
			{ arrived && __( 'Website check results arrived.', 'updatelens' ) }
		</p>
	);
}

/** Classes of a callout per tone. */
const CALLOUT: Partial< Record< Tone, string > > = {
	caution: 'border-amber-300 bg-amber-50',
	progress: 'border-tint-border bg-tint',
};

/**
 * A reason both checks share, above them, with its status as title.
 *
 * @param props          Props.
 * @param props.tone     Tone (caution or progress).
 * @param props.title    Status both checks share.
 * @param props.children Sentences.
 */
function Callout( {
	tone,
	title,
	children,
}: {
	tone: Tone;
	title: string;
	children: ReactNode;
} ) {
	return (
		<div
			className={ cn(
				'flex gap-3 rounded-lg border px-4 py-3',
				CALLOUT[ tone ] ?? 'border-slate-300 bg-slate-50'
			) }
		>
			<Icon
				name={ tone === 'progress' ? 'progress' : 'caution' }
				className={ cn( 'mt-0.5', TONE_ICON[ tone ] ) }
			/>
			<div className="min-w-0 space-y-1 text-[13px] leading-5 text-slate-800">
				<p className="text-sm font-semibold text-slate-900">
					{ title }
				</p>
				{ children }
			</div>
		</div>
	);
}

/**
 * While results are pending: that the report checks for them by itself, or
 * that it stopped, and a button that reads the report again ("Refresh
 * results": it starts no check). The button stays the same element in both
 * states, so focus is kept.
 *
 * @param props         Props.
 * @param props.refresh Background reads.
 * @param props.onFocus Notes that focus is inside.
 */
function PendingRefreshLine( {
	refresh,
	onFocus,
}: {
	refresh: PendingRefresh;
	onFocus: () => void;
} ) {
	const stopped = refresh.state === 'stopped';
	return (
		<p
			onFocus={ onFocus }
			className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[13px] leading-5 text-slate-700"
		>
			{ /* Polite: a failed read or a stopped run is said when it happens. */ }
			<span aria-live="polite">
				{ stopped && refresh.error
					? `${ __(
							'Automatic checking stopped: refreshed results are unavailable. The results shown are from the last successful read, and checks still waiting stay unresolved and are not counted as passed.',
							'updatelens'
						) } ${ requestFailureText( refresh.error ) ?? '' }`.trim()
					: refresh.error
						? `${ __(
								'Refreshing the results failed; this report tries again in a few seconds. The results shown are from the last successful read, and checks still waiting stay unresolved.',
								'updatelens'
							) } ${ requestFailureText( refresh.error ) ?? '' }`.trim()
						: stopped
							? __(
									'Automatic checking stopped; the website check results have not arrived yet.',
									'updatelens'
								)
							: __(
									'This report checks for the pending website check results every few seconds while it is open.',
									'updatelens'
								) }
			</span>
			<Button
				type="button"
				variant="outline"
				size="sm"
				aria-disabled={ refresh.checking }
				onClick={ () => {
					if ( ! refresh.checking ) {
						refresh.refreshNow();
					}
				} }
			>
				<Icon
					name="progress"
					className={ cn(
						'size-4',
						refresh.checking && 'motion-safe:animate-spin'
					) }
				/>
				{ __( 'Refresh results', 'updatelens' ) }
			</Button>
		</p>
	);
}

/** Icon of each check. */
const CHECK_ICON: Record< SiteCheckId, IconName > = {
	home: 'globe',
	rest: 'api',
};

/**
 * How a check makes its request, under its name.
 *
 * @param check Check.
 */
function checkContext( check: SiteCheckId ): string {
	return check === 'home'
		? __( 'Requested as a signed-out visitor', 'updatelens' )
		: __( 'Requested as you, signed in', 'updatelens' );
}

/** Text color of the comparison, per tone. */
const RESULT_TEXT: Record< Tone, string > = {
	negative: 'text-rose-800',
	caution: 'text-amber-900',
	positive: 'text-emerald-800',
	neutral: 'text-slate-900',
	progress: 'text-slate-900',
};

/**
 * One check as a compact row: its name (the toggle), what it returned before
 * and after the update, and the comparison; the request times when measured;
 * one sentence below only when it says more. The earlier and later requests
 * render only while expanded (never hidden DOM).
 *
 * @param props               Props.
 * @param props.check         Check.
 * @param props.comparison    The check in `site_checks`.
 * @param props.times         Its request times, or null when none was measured.
 * @param props.text          Its tone, status and sentence.
 * @param props.result        The comparison in a few words.
 * @param props.reason        The reason already shown for it (row or section).
 * @param props.expanded      Whether it is expanded.
 * @param props.onToggle      Expands or collapses it.
 * @param props.nextStep      What to check next, for a finding (with a link to it).
 * @param props.onShowFinding Opens its finding in Update results.
 */
function CheckRow( {
	check,
	comparison,
	times,
	text,
	result,
	reason,
	nextStep,
	expanded,
	onToggle,
	onShowFinding,
}: {
	check: SiteCheckId;
	comparison: SiteCheckComparison;
	times: RequestTimesView | null;
	text: SiteCheckRowText;
	result: string;
	reason: string | null;
	nextStep: string | null;
	expanded: boolean;
	onToggle: () => void;
	onShowFinding: () => void;
} ) {
	const id = useId();
	const statusId = `${ id }-status`;
	const panelId = `${ id }-panel`;
	const label = 'font-medium text-muted-foreground md:text-xs md:leading-5';

	return (
		<li
			data-check={ check }
			data-state={ comparison.state }
			className={ cn(
				'overflow-hidden rounded-lg border bg-card shadow-surface transition-colors',
				expanded ? 'border-slate-400' : 'hover:border-slate-400'
			) }
		>
			<div
				className={ cn(
					'relative grid grid-cols-[minmax(0,1fr)_1.25rem] items-center gap-x-4 gap-y-3 px-3 py-3 sm:px-4 md:grid-cols-[minmax(11rem,1fr)_minmax(0,2.6fr)_1.25rem]',
					! expanded && 'hover:bg-slate-50'
				) }
			>
				<div className="flex min-w-0 items-center gap-3">
					<span className="grid size-10 shrink-0 place-items-center rounded-lg bg-tint-strong text-primary">
						<Icon name={ CHECK_ICON[ check ] } />
					</span>
					<div className="min-w-0">
						<h4 className="text-[15px] font-semibold leading-5 text-slate-950">
							<button
								type="button"
								id={ siteCheckButtonId( check ) }
								aria-expanded={ expanded }
								aria-controls={ panelId }
								aria-describedby={ statusId }
								onClick={ onToggle }
								className="text-left after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:rounded-[inherit] focus-visible:after:ring-2 focus-visible:after:ring-inset focus-visible:after:ring-ring"
							>
								{ siteCheckTitle( check ) }
							</button>
						</h4>
						<p className="text-xs leading-5 text-muted-foreground">
							{ checkContext( check ) }
						</p>
					</div>
				</div>
				<span
					aria-hidden="true"
					className="col-start-2 row-start-1 text-slate-600 md:col-start-3"
				>
					<Icon
						name="chevron-down"
						className={ cn(
							'size-4 transition-transform',
							expanded && 'rotate-180'
						) }
					/>
				</span>
				<dl
					id={ statusId }
					className="col-span-2 grid grid-cols-[6.5rem_minmax(0,1fr)] items-center gap-x-3 gap-y-1.5 text-[13px] leading-5 md:col-span-1 md:col-start-2 md:row-start-1 md:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)_minmax(0,0.85fr)] md:items-start md:gap-x-4"
				>
					<div className="contents md:block">
						<dt className={ label }>
							{ __( 'Before update', 'updatelens' ) }
						</dt>
						<dd className="flex min-w-0 items-center gap-2 md:mt-1">
							<SideCell
								side={ comparison.before }
								purpose="before"
								comparison={ comparison }
							/>
							<Icon
								name="arrow-right"
								className="ml-auto hidden size-4 text-slate-500 md:block"
							/>
						</dd>
					</div>
					<div className="contents md:block">
						<dt className={ label }>
							{ __( 'After update', 'updatelens' ) }
						</dt>
						<dd className="flex min-w-0 items-center gap-2 md:mt-1">
							<SideCell
								side={ comparison.after }
								purpose="after"
								comparison={ comparison }
							/>
						</dd>
					</div>
					<div className="contents md:block">
						<dt className={ label }>
							{ __( 'Result', 'updatelens' ) }
						</dt>
						<dd
							className={ cn(
								'min-w-0 font-semibold md:mt-1 md:leading-6',
								RESULT_TEXT[ text.tone ]
							) }
						>
							{ result }
						</dd>
					</div>
				</dl>
				{ times && <RequestTimes times={ times } /> }
				{ ( text.detail || nextStep ) && (
					<div className="col-span-2 space-y-1 text-[13px] leading-5 md:col-span-1 md:col-start-2">
						{ text.detail && (
							<p className="text-slate-700">{ text.detail }</p>
						) }
						{ nextStep && (
							// Above the row's stretched toggle, so it can be clicked.
							<p className="relative z-[1] text-slate-800">
								<span className="font-medium">
									{ __( 'Next:', 'updatelens' ) }
								</span>{ ' ' }
								{ nextStep }{ ' ' }
								<button
									type="button"
									onClick={ onShowFinding }
									className="inline-flex min-h-6 items-center rounded font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
								>
									{ __( 'Show the finding', 'updatelens' ) }
								</button>
							</p>
						) }
					</div>
				) }
			</div>
			<div id={ panelId } hidden={ ! expanded }>
				{ expanded && (
					<div className="border-t bg-slate-50 p-3 sm:p-4">
						{ /* The status only when it says more than the result above. */ }
						{ text.status !== result && (
							<p className="mb-2 text-[13px] font-medium leading-5 text-slate-800">
								{ text.status }
							</p>
						) }
						<div className="grid gap-3 xl:grid-cols-2">
							<SideView
								check={ check }
								side={ comparison.before }
								purpose="before"
								comparison={ comparison }
								reason={ reason }
							/>
							<SideView
								check={ check }
								side={ comparison.after }
								purpose="after"
								comparison={ comparison }
								reason={ reason }
							/>
						</div>
						<p className="mt-3 max-w-3xl text-[13px] leading-5 text-slate-700">
							{ siteCheckReadingNote( check ) }
						</p>
					</div>
				) }
			</div>
		</li>
	);
}

/** One request time field: its label, value, and how the value reads. */
interface TimeField {
	label: string;
	value: string;
	/** A duration: prominent, tabular figures. */
	number: boolean;
	/** A sentence ("Not shown: …"): the whole width on phones. */
	wide: boolean;
}

/**
 * How long each side's first request took, and the difference, as three
 * aligned fields (Before, After, Difference) under the row's status: on
 * phones below it, apart by a hairline; from `md` in the status columns,
 * under Before update / After update / Result, with the caption in the name
 * column. Durations stand out from their labels; colors stay neutral (faster
 * or slower says nothing about the update). No Difference field without both
 * durations.
 *
 * @param props       Props.
 * @param props.times The check's request times.
 */
function RequestTimes( { times }: { times: RequestTimesView } ) {
	const labelId = useId();
	const fields: TimeField[] = [
		{
			label: __( 'Before', 'updatelens' ),
			value: times.before.text,
			number: times.before.measured,
			wide: false,
		},
		{
			label: __( 'After', 'updatelens' ),
			value: times.after.text,
			number: times.after.measured,
			wide: false,
		},
	];
	if ( times.difference ) {
		fields.push( {
			label: __( 'Difference', 'updatelens' ),
			value: times.difference.text,
			number: times.difference.kind === 'value',
			wide: times.difference.kind === 'not_shown',
		} );
	}
	return (
		<div className="col-span-2 border-t border-slate-200 pt-2.5 md:contents">
			<div
				id={ labelId }
				className="text-xs font-medium leading-5 text-slate-600 md:col-start-1 md:self-start md:pl-[3.25rem]"
			>
				{ __( 'Request time', 'updatelens' ) }
			</div>
			<dl
				aria-labelledby={ labelId }
				className="mt-1 grid grid-cols-3 gap-x-3 gap-y-2 md:col-start-2 md:mt-0 md:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)_minmax(0,0.85fr)] md:gap-x-4"
			>
				{ fields.map( ( field ) => (
					<div
						key={ field.label }
						className={ cn(
							'min-w-0',
							field.wide && 'col-span-3 md:col-span-1'
						) }
					>
						<dt className="text-xs font-medium leading-5 text-slate-600">
							{ field.label }
						</dt>
						<dd
							className={
								field.number
									? 'whitespace-nowrap text-[15px] font-semibold tabular-nums leading-6 text-slate-900'
									: 'text-[13px] leading-6 text-slate-700'
							}
						>
							{ field.value }
						</dd>
					</div>
				) ) }
			</dl>
		</div>
	);
}

/** Pill classes per verdict, and for a side without a result. */
const PILL = {
	pass: 'bg-emerald-50 text-emerald-800 ring-emerald-300',
	fail: 'bg-rose-50 text-rose-800 ring-rose-300',
	redirect: 'bg-amber-50 text-amber-900 ring-amber-300',
	inconclusive: 'bg-slate-100 text-slate-800 ring-slate-300',
	waiting: 'bg-tint text-tint-foreground ring-tint-border',
	missing: 'bg-slate-100 text-slate-700 ring-slate-300',
};

/**
 * What one side returned in a few words: its verdict as a pill, and how
 * many requests showed it with their response ("Failed in both requests ·
 * HTTP 500"); "Waiting" while the later check may still arrive, or "Not run".
 *
 * @param props            Props.
 * @param props.side       Side.
 * @param props.purpose    `before` or `after`.
 * @param props.comparison The check's comparison.
 */
function SideCell( {
	side,
	purpose,
	comparison,
}: {
	side: SiteCheckSide;
	purpose: 'before' | 'after';
	comparison: SiteCheckComparison;
} ) {
	const pill =
		'whitespace-nowrap rounded-full px-2 text-xs font-semibold leading-5 ring-1 ring-inset';
	if ( side.available && side.result !== null ) {
		const { verdict, detail } = sideCellParts( side );
		return (
			<span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
				<span className={ cn( pill, PILL[ side.result.verdict ] ) }>
					{ verdict }
				</span>{ ' ' }
				{ detail && (
					<span className="tabular-nums text-slate-700">
						{ detail }
					</span>
				) }
			</span>
		);
	}
	const waiting = purpose === 'after' && comparison.state === 'pending';
	return (
		<span className={ cn( pill, waiting ? PILL.waiting : PILL.missing ) }>
			{ waiting
				? __( 'Waiting', 'updatelens' )
				: __( 'Not run', 'updatelens' ) }
		</span>
	);
}

/**
 * One side of a check as labelled fields: its result, when it ran relative
 * to the update, and every request (response, response source, completion
 * marker or body); or why there is none. Labels sit above their values on
 * phones and beside them from `sm` up.
 *
 * @param props            Props.
 * @param props.check      Check.
 * @param props.side       Side.
 * @param props.purpose    `before` or `after`.
 * @param props.comparison The check's comparison.
 * @param props.reason     The reason already shown above (not repeated).
 */
function SideView( {
	check,
	side,
	purpose,
	comparison,
	reason,
}: {
	check: SiteCheckId;
	side: SiteCheckSide;
	purpose: 'before' | 'after';
	comparison: SiteCheckComparison;
	reason: string | null;
} ) {
	const rows: Array< [ string, ReactNode ] > = [];
	if ( ! side.available || side.result === null ) {
		const missing = missingSideText( side, purpose, comparison );
		rows.push( [
			__( 'Result', 'updatelens' ),
			<>
				<span className="font-medium text-slate-800">
					{ __( 'Not available', 'updatelens' ) }
				</span>
				{ missing !== reason && (
					<span className="block text-slate-600">{ missing }</span>
				) }
			</>,
		] );
	} else {
		rows.push( [
			__( 'Result', 'updatelens' ),
			<span key="result" className="font-medium text-slate-800">
				{ sideSummaryText( side ) }
			</span>,
		] );
		const timing = sideTimingText( side, purpose );
		if ( timing ) {
			rows.push( [ __( 'Checked', 'updatelens' ), timing ] );
		}
		side.result.attempts.forEach( ( attempt, index ) => {
			const view = attemptView( check, attempt );
			rows.push( [
				requestLabel( index ),
				<>
					<span className="block text-slate-800">
						{ view.summary }
					</span>
					{ view.fields.map( ( [ label, value ] ) => (
						<span key={ label } className="block">
							<span className="text-slate-500">
								{ label }
								{ ': ' }
							</span>
							{ value }
						</span>
					) ) }
				</>,
			] );
		} );
	}

	return (
		<div className="min-w-0 rounded-lg bg-card px-3 py-2.5 ring-1 ring-inset ring-slate-300">
			<h5 className="text-[13px] font-semibold leading-5 text-slate-900">
				{ purpose === 'before'
					? __( 'Before update', 'updatelens' )
					: __( 'After update', 'updatelens' ) }
			</h5>
			<dl className="mt-1.5 grid text-[13px] leading-5 sm:grid-cols-[6.5rem_minmax(0,1fr)] sm:gap-x-3 sm:gap-y-1.5">
				{ rows.map( ( [ label, content ], index ) => (
					<div key={ label } className="contents">
						<dt
							className={ cn(
								'font-medium text-slate-600',
								// Stacked on phones: a little air between fields.
								index > 0 && 'mt-1.5 sm:mt-0'
							) }
						>
							{ label }
						</dt>
						<dd className="min-w-0 break-words text-slate-700">
							{ content }
						</dd>
					</div>
				) ) }
			</dl>
		</div>
	);
}
