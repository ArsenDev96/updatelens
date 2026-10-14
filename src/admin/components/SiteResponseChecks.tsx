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
import { requestFailureText } from '../utils/labels';
import {
	attemptView,
	requestLabel,
	sideSummaryText,
	sideTimingText,
	SITE_CHECKS,
	siteCheckNextTimeText,
} from '../utils/siteCheckImpact';
import {
	missingSideText,
	sharedReason,
	siteCheckButtonId,
	siteCheckGuidanceReason,
	siteCheckNextStep,
	siteCheckReadingNote,
	siteCheckResultLabel,
	siteCheckRow,
	siteCheckSideCell,
	siteCheckViewKey,
	type SiteCheckRowText,
	siteChecksUnused,
	siteCheckTitle,
} from '../utils/siteChecks';
import { TONE_ICON } from '../utils/tone';
import { Icon } from './Icon';

/*
 * "Site checks" on the report overview: what the administrator's browser
 * observed when it requested the homepage and the WordPress REST API before
 * and after the update. Not part of any phase and not a configuration
 * change, so it has its own section.
 *
 * Two compact rows, always shown (also when both passed): the check, what it
 * returned before and after the update, and the API's comparison. A reason
 * both checks share, and how to get a comparison on a future update, are said
 * once below the rows. Every request, the verification and the timing are
 * behind each row's disclosure. A check reads as passed only when the API
 * compared it as passed. Checks that were turned off, never recorded or
 * unsupported for the whole update are one quiet sentence instead of rows.
 * Findings stay in Findings; each side links to the other.
 */

/** Relative link to the Settings screen (both are admin.php pages). */
const SETTINGS_HREF = 'admin.php?page=updatelens-settings';

/** Grid of a row from `md` up: check, before, after, result, chevron. */
const COLUMNS =
	'md:grid-cols-[minmax(9rem,1.1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_1.25rem]';

/**
 * The section.
 *
 * @param props                Props.
 * @param props.siteChecks     The report's `site_checks`.
 * @param props.impact         `potential_impact.site_checks`.
 * @param props.pendingRefresh Background reads while results are pending.
 * @param props.view           Expanded rows (shared with Findings).
 * @param props.onViewChange   Updates them.
 * @param props.onShowFinding  Opens a check's finding in Findings.
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
	// Announced once when pending results arrive while the report is open.
	const pending = siteChecks?.status === 'pending';
	const [ wasPending, setWasPending ] = useState( pending );
	const [ arrived, setArrived ] = useState( false );
	if ( wasPending !== pending ) {
		setWasPending( pending );
		setArrived( ! pending );
	}
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
						text: siteCheckRow(
							check,
							comparison,
							impact?.checks[ check ]
						),
					},
				]
			: [];
	} );
	// A reason both checks share is said once, below them.
	const shared = sharedReason( checks.map( ( { text } ) => text ) );
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
			className="rounded-xl border bg-card px-4 py-4 shadow-surface sm:px-6"
		>
			<div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
				<h3
					id="updatelens-site-checks-title"
					ref={ title }
					tabIndex={ -1 }
					className="text-base font-semibold text-slate-900 focus:outline-none"
				>
					{ __( 'Site checks', 'updatelens' ) }
				</h3>
				{ /* Only for checks that ran: not for turned off, unsupported or unrecorded ones. */ }
				{ ! unused && (
					<p className="text-[13px] leading-5 text-muted-foreground">
						{ __(
							'Requested by your browser before and after the update. They do not test every page or feature.',
							'updatelens'
						) }
					</p>
				) }
			</div>
			{ unused ? (
				<p className="mt-2 flex max-w-3xl gap-2 text-sm leading-relaxed text-slate-600">
					<Icon
						name="info"
						className="mt-0.5 size-4 text-slate-500"
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
										'Site check settings',
										'updatelens'
									) }
								</a>
							</>
						) }
					</span>
				</p>
			) : (
				<>
					{ refreshing && (
						<PendingRefreshLine
							refresh={ pendingRefresh }
							onFocus={ () => {
								refreshFocused.current = true;
							} }
						/>
					) }
					{ /* Column labels for wide screens; each row also names its fields for assistive technology and phones. */ }
					<div
						aria-hidden="true"
						className={ cn(
							'mt-3 hidden gap-x-4 px-3 text-xs font-medium text-muted-foreground md:grid',
							COLUMNS
						) }
					>
						<span>{ __( 'Check', 'updatelens' ) }</span>
						<span>{ __( 'Before update', 'updatelens' ) }</span>
						<span>{ __( 'After update', 'updatelens' ) }</span>
						<span>{ __( 'Result', 'updatelens' ) }</span>
					</div>
					<ul
						aria-label={ __( 'Checks', 'updatelens' ) }
						className="mt-3 divide-y overflow-hidden rounded-lg border md:mt-1.5"
					>
						{ checks.map( ( { check, comparison, text } ) => (
							<CheckRow
								key={ check }
								check={ check }
								comparison={ comparison }
								text={
									shared ? { ...text, detail: null } : text
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
												impact?.checks[ check ]
											)
										: null
								}
								expanded={
									view.open[ siteCheckViewKey( check ) ] !==
									undefined
								}
								onToggle={ () => toggle( check ) }
								onShowFinding={ () => onShowFinding( check ) }
							/>
						) ) }
					</ul>
					{ ( shared || nextTime.length > 0 ) && (
						<div className="mt-3 flex max-w-3xl gap-2 text-[13px] leading-5 text-slate-700">
							<Icon
								name="info"
								className="mt-0.5 size-4 shrink-0 text-slate-500"
							/>
							<div className="space-y-1">
								{ shared && <p>{ shared }</p> }
								{ nextTime.map( ( sentence ) => (
									<p key={ sentence }>{ sentence }</p>
								) ) }
							</div>
						</div>
					) }
				</>
			) }
			<p role="status" className="sr-only">
				{ arrived && __( 'Site check results arrived.', 'updatelens' ) }
			</p>
		</section>
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
			className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[13px] leading-5 text-slate-600"
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
									'Automatic checking stopped; the site check results have not arrived yet.',
									'updatelens'
								)
							: __(
									'This report checks for the pending site check results every few seconds while it is open.',
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

/**
 * One check as a compact row: its name (the toggle), what it returned before
 * and after the update, and the comparison; one sentence below only when it
 * says more. The earlier and later requests render only while expanded
 * (never hidden DOM).
 *
 * @param props               Props.
 * @param props.check         Check.
 * @param props.comparison    The check in `site_checks`.
 * @param props.text          Its tone, status and sentence.
 * @param props.result        The comparison in a few words.
 * @param props.reason        The reason already shown for it (row or section).
 * @param props.expanded      Whether it is expanded.
 * @param props.onToggle      Expands or collapses it.
 * @param props.nextStep      What to check next, for a finding (with a link to it).
 * @param props.onShowFinding Opens its finding in Findings.
 */
function CheckRow( {
	check,
	comparison,
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
	const sides: Array< [ string, string ] > = [
		[
			__( 'Before update', 'updatelens' ),
			siteCheckSideCell( comparison.before, 'before', comparison ),
		],
		[
			__( 'After update', 'updatelens' ),
			siteCheckSideCell( comparison.after, 'after', comparison ),
		],
	];

	return (
		<li data-check={ check } data-state={ comparison.state }>
			<div
				className={ cn(
					'relative grid grid-cols-[minmax(0,1fr)_1.25rem] items-start gap-x-4 gap-y-1 px-3 py-2.5 md:items-center',
					COLUMNS,
					expanded ? 'bg-slate-50' : 'hover:bg-slate-50/60'
				) }
			>
				<h4 className="flex min-w-0 items-center gap-2 text-sm font-semibold leading-6 text-slate-900">
					<Icon
						name={ text.icon }
						className={ cn(
							'size-4 shrink-0',
							TONE_ICON[ text.tone ]
						) }
					/>
					<button
						type="button"
						id={ siteCheckButtonId( check ) }
						aria-expanded={ expanded }
						aria-controls={ panelId }
						aria-describedby={ statusId }
						onClick={ onToggle }
						className="text-left after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-inset focus-visible:after:ring-ring"
					>
						{ siteCheckTitle( check ) }
					</button>
				</h4>
				<span
					aria-hidden="true"
					className="col-start-2 row-start-1 mt-1 text-slate-500 md:col-start-5 md:mt-0"
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
					className="col-span-2 grid grid-cols-[6.5rem_minmax(0,1fr)] gap-x-3 text-[13px] leading-5 md:col-span-3 md:col-start-2 md:row-start-1 md:grid-cols-3 md:gap-x-4"
				>
					{ sides.map( ( [ label, value ] ) => (
						<div key={ label } className="contents">
							<dt className="text-muted-foreground md:sr-only">
								{ label }
							</dt>
							<dd className="min-w-0 text-slate-700">
								{ value }
							</dd>
						</div>
					) ) }
					<dt className="text-muted-foreground md:sr-only">
						{ __( 'Result', 'updatelens' ) }
					</dt>
					<dd
						className={ cn(
							'min-w-0 font-semibold',
							{
								negative: 'text-rose-800',
								caution: 'text-amber-900',
								positive: 'text-emerald-800',
								neutral: 'text-slate-900',
								progress: 'text-slate-900',
							}[ text.tone ]
						) }
					>
						{ result }
					</dd>
				</dl>
				{ ( text.detail || nextStep ) && (
					<div className="col-span-2 space-y-1 text-[13px] leading-5 md:col-span-4 md:col-start-2">
						{ text.detail && (
							<p className="text-slate-600">{ text.detail }</p>
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
					<div className="border-t bg-slate-50/40 p-3">
						<p className="text-[13px] font-medium leading-5 text-slate-800">
							{ text.status }
						</p>
						<div className="mt-2 grid gap-3 xl:grid-cols-2">
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
						<p className="mt-3 max-w-3xl text-[13px] leading-5 text-slate-600">
							{ siteCheckReadingNote( check ) }
						</p>
					</div>
				) }
			</div>
		</li>
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
		<div className="min-w-0 rounded-lg bg-card px-3 py-2.5 ring-1 ring-inset ring-slate-200/70">
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
