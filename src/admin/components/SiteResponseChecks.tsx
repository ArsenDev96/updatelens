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
} from '../utils/siteCheckImpact';
import {
	missingSideText,
	sharedReason,
	siteCheckButtonId,
	siteCheckNextStep,
	siteCheckReadingNote,
	siteCheckRow,
	siteCheckViewKey,
	type SiteCheckRowText,
	siteChecksUnused,
	siteCheckTitle,
} from '../utils/siteChecks';
import { TONE_ICON } from '../utils/tone';
import { Icon } from './Icon';

/*
 * "Site response checks" on the report overview: what the administrator's
 * browser observed when it requested the homepage and the WordPress REST API
 * in the minutes before and shortly after the update. Not part of any phase and not a
 * configuration change, so it has its own section above the phase tabs.
 *
 * One row per check, always shown (also when both passed): the API's
 * comparison state as a short status, and a sentence only when it says
 * more; expanded, "Before update" and "After update" as labelled fields with
 * every request, and how to read them. A check reads as passed only when
 * the API compared it as passed. Checks that were turned off, never recorded
 * or unsupported for the whole update are one quiet sentence instead of
 * rows. Findings stay in Potential Impact; each side links to the other.
 */

/** Relative link to the Settings screen (both are admin.php pages). */
const SETTINGS_HREF = 'admin.php?page=updatelens-settings';

/**
 * The section.
 *
 * @param props                Props.
 * @param props.siteChecks     The report's `site_checks`.
 * @param props.impact         `potential_impact.site_checks`.
 * @param props.pendingRefresh Background reads while results are pending.
 * @param props.view           Expanded rows (shared with Potential Impact).
 * @param props.onViewChange   Updates them.
 * @param props.onShowFinding  Opens a check's finding in Potential Impact.
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
	// A reason both checks share is said once, above them.
	const shared = sharedReason( checks.map( ( { text } ) => text ) );
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
			className={ cn(
				'rounded-xl border bg-card px-4 shadow-surface sm:px-8',
				unused ? 'py-4' : 'py-5 sm:py-6'
			) }
		>
			<div className="flex flex-wrap items-center gap-x-3 gap-y-2">
				<h3
					id="updatelens-site-checks-title"
					ref={ title }
					tabIndex={ -1 }
					className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-600 focus:outline-none"
				>
					{ __( 'Site response checks', 'updatelens' ) }
				</h3>
				{ /* Only for checks that ran: not for turned off, unsupported or unrecorded ones. */ }
				{ ! unused && (
					<span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
						{ __( 'Before and after the update', 'updatelens' ) }
					</span>
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
					<p className="mt-2 max-w-3xl text-sm leading-relaxed text-slate-700">
						{ __(
							'Your browser requested the homepage and the WordPress REST API in the minutes before the update and again shortly after it; each check shows when it ran. These checks are separate from the configuration phases below, and they do not test every page or feature.',
							'updatelens'
						) }
					</p>
					{ shared && (
						<p className="mt-3 flex max-w-3xl gap-2 rounded-lg bg-amber-50/70 px-3 py-2 text-[13px] leading-5 text-amber-900 ring-1 ring-inset ring-amber-200">
							<Icon
								name="caution"
								className="mt-0.5 size-4 text-amber-700"
							/>
							<span>{ shared }</span>
						</p>
					) }
					{ refreshing && (
						<PendingRefreshLine
							refresh={ pendingRefresh }
							onFocus={ () => {
								refreshFocused.current = true;
							} }
						/>
					) }
					<ul
						aria-label={ __( 'Checks', 'updatelens' ) }
						className="mt-3 space-y-2"
					>
						{ checks.map( ( { check, comparison, text } ) => (
							<CheckRow
								key={ check }
								check={ check }
								comparison={ comparison }
								text={
									shared ? { ...text, detail: null } : text
								}
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
 * One check: its name, the status and one sentence, and a button over the
 * row that shows the earlier and later checks. Details render only while
 * expanded (never hidden DOM).
 *
 * @param props               Props.
 * @param props.check         Check.
 * @param props.comparison    The check in `site_checks`.
 * @param props.text          Its status and sentence.
 * @param props.reason        The reason already shown for it (row or section).
 * @param props.expanded      Whether it is expanded.
 * @param props.onToggle      Expands or collapses it.
 * @param props.nextStep      What to check next, for a finding (with a link to it).
 * @param props.onShowFinding Opens its finding in Potential Impact.
 */
function CheckRow( {
	check,
	comparison,
	text,
	reason,
	nextStep,
	expanded,
	onToggle,
	onShowFinding,
}: {
	check: SiteCheckId;
	comparison: SiteCheckComparison;
	text: SiteCheckRowText;
	reason: string | null;
	nextStep: string | null;
	expanded: boolean;
	onToggle: () => void;
	onShowFinding: () => void;
} ) {
	const id = useId();
	const statusId = `${ id }-status`;
	const panelId = `${ id }-panel`;

	return (
		<li
			data-check={ check }
			data-state={ comparison.state }
			className={ cn(
				'overflow-hidden rounded-xl border bg-card transition-colors',
				expanded ? 'border-slate-300' : 'hover:border-slate-300'
			) }
		>
			<div
				className={ cn(
					'relative flex items-start gap-3 px-4 py-3 sm:px-5',
					! expanded && 'hover:bg-slate-50/60'
				) }
			>
				<Icon
					name={ text.icon }
					className={ cn( 'mt-0.5', TONE_ICON[ text.tone ] ) }
				/>
				<div className="min-w-0 flex-1">
					<h4 className="text-[15px] font-semibold leading-6 text-slate-900">
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
					<p id={ statusId } className="mt-0.5 text-sm leading-6">
						<span
							className={ cn(
								'font-medium',
								text.tone === 'negative'
									? 'text-rose-800'
									: text.tone === 'caution'
										? 'text-amber-900'
										: 'text-slate-800'
							) }
						>
							{ text.status }
						</span>
						{ text.detail && (
							<>
								{ ' ' }
								<span className="block text-[13px] leading-5 text-slate-600">
									{ text.detail }
								</span>
							</>
						) }
					</p>
					{ nextStep && (
						// Above the row's stretched toggle, so it can be clicked.
						<p className="relative z-[1] mt-1.5 text-[13px] leading-5 text-slate-800">
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
				<span
					aria-hidden="true"
					className="mt-0.5 inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary"
				>
					<span className="hidden sm:inline">
						{ expanded
							? __( 'Hide details', 'updatelens' )
							: __( 'Show details', 'updatelens' ) }
					</span>
					<Icon
						name="chevron-down"
						className={ cn(
							'size-4 transition-transform',
							expanded && 'rotate-180'
						) }
					/>
				</span>
			</div>
			<div id={ panelId } hidden={ ! expanded }>
				{ expanded && (
					<div className="border-t px-4 pb-4 pt-3 sm:px-5">
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
		<div className="min-w-0 rounded-lg bg-slate-50 px-3 py-2.5 ring-1 ring-inset ring-slate-200/70">
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
