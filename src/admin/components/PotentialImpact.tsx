import { _n, __, sprintf } from '@wordpress/i18n';
import {
	Fragment,
	useEffect,
	useId,
	useMemo,
	useRef,
	type ReactNode,
} from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import type {
	ActionScheduleType,
	ImpactActionSide,
	ImpactCronInstance,
	ImpactCronSide,
	ImpactFinding,
	PhaseKey,
	PotentialImpact as PotentialImpactData,
} from '../types/api';
import {
	formatBytesDelta,
	formatBytes,
	formatBytesPair,
	formatCount,
	formatDuration,
} from '../utils/format';
import {
	checkedPatterns,
	checkLimitations,
	cronRemovalContext,
	findingGroupTitle,
	findingSubject,
	groupFindings,
	groupSubjects,
	groupSummary,
	impactDescription,
	impactHeadline,
	impactSignalText,
	impactState,
	thresholdText,
	whatChanged,
	whatToCheck,
	whyItMatters,
	type FindingGroup,
	type ImpactView,
} from '../utils/impact';
import {
	actionScheduleTypeLabel,
	phaseLabel,
	PROVIDERS,
	providerLabel,
} from '../utils/labels';
import { AppLink } from './AppLink';
import { Time, To } from './DiffRow';
import { Icon } from './Icon';
import { Identifier, RowDetails } from './SignalParts';

/*
 * Potential Impact on the report overview: review rules the API evaluated on
 * the Net result, whatever phase is selected.
 *
 * Progressive disclosure: findings are grouped by rule and signal, and each
 * group starts as one compact row (title, count, signal, the first names and
 * one actionable sentence). Its details (one row per finding with the exact
 * before → after evidence and a link to the located entry, why it might
 * matter, what to check next) are rendered only while it is expanded, a few
 * findings at a time. Checks that did not complete stay visible above the
 * groups; everything that was checked, and what a result cannot show, is in
 * "What was checked".
 */

/** Findings shown when a group is expanded, before "Show more". */
export const DEFAULT_VISIBLE_FINDINGS = 3;

/** Findings revealed per "Show more". */
const FINDINGS_STEP = 50;

/**
 * The Potential Impact section.
 *
 * @param props                 Props.
 * @param props.impact          API evaluation.
 * @param props.windowSeconds   Observation window length from the API.
 * @param props.findingHref     Link target of a finding's signal details.
 * @param props.onOpenFinding   Opens a finding's signal details in the app.
 * @param props.onShowNetResult Selects the Net result phase; null if it is
 *                              selected or has no data.
 * @param props.selectedPhase   Phase selected in the report's tabs.
 * @param props.view            Expanded groups and disclosures.
 * @param props.onViewChange    Updates them.
 */
export function PotentialImpact( {
	impact,
	windowSeconds,
	findingHref,
	onOpenFinding,
	onShowNetResult,
	selectedPhase,
	view,
	onViewChange,
}: {
	impact: PotentialImpactData;
	windowSeconds: number;
	findingHref: ( finding: ImpactFinding ) => string;
	onOpenFinding: ( finding: ImpactFinding ) => void;
	onShowNetResult: ( () => void ) | null;
	selectedPhase: PhaseKey;
	view: ImpactView;
	onViewChange: ( view: ImpactView ) => void;
} ) {
	const state = impactState( impact );
	const groups = useMemo(
		() => groupFindings( impact.findings ),
		[ impact.findings ]
	);
	// Position of each finding in the API's list, for links and focus.
	const indexes = useMemo(
		() =>
			new Map(
				impact.findings.map( ( finding, index ) => [ finding, index ] )
			),
		[ impact.findings ]
	);
	const evaluated = state === 'findings' || state === 'none';
	// With another phase selected, say first that these results are not from
	// it. Without a Net result there are none, and the headline says why.
	const elsewhere = evaluated && selectedPhase !== 'final';
	const setVisible = ( key: string, visible: number | null ) => {
		const open = { ...view.open };
		if ( visible === null ) {
			delete open[ key ];
		} else {
			open[ key ] = visible;
		}
		onViewChange( { ...view, open } );
	};

	return (
		<section
			aria-labelledby="updatelens-impact-title"
			className="rounded-xl border bg-card px-5 py-5 shadow-surface sm:px-8 sm:py-6"
		>
			<div className="flex flex-wrap items-center gap-x-3 gap-y-2">
				<h3
					id="updatelens-impact-title"
					className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-600"
				>
					{ __( 'Potential impact', 'updatelens' ) }
				</h3>
				<span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
					{ __( 'Based on Net result', 'updatelens' ) }
				</span>
			</div>
			{ elsewhere && (
				<p className="mt-3 max-w-3xl rounded-lg bg-slate-50 px-3 py-2 text-[13px] leading-5 text-slate-700 ring-1 ring-inset ring-slate-200">
					{ sprintf(
						/* translators: %s: selected phase, e.g. "During update". */
						__(
							'You are viewing %s. These findings do not come from that phase: Potential Impact always uses the Net result (before the update compared with after it).',
							'updatelens'
						),
						phaseLabel( selectedPhase )
					) }
					{ onShowNetResult && (
						<>
							{ ' ' }
							<button
								type="button"
								onClick={ onShowNetResult }
								className="rounded font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
							>
								{ __(
									'Show the Net result phase',
									'updatelens'
								) }
							</button>
						</>
					) }
				</p>
			) }
			<p
				className={ cn(
					'mt-2 font-semibold tracking-tight text-slate-900',
					state === 'findings'
						? 'text-2xl tabular-nums'
						: 'text-lg leading-snug sm:text-xl'
				) }
			>
				{ impactHeadline( impact, state ) }
			</p>
			<p className="mt-1.5 max-w-3xl text-sm leading-relaxed text-slate-700 sm:text-[15px]">
				{ impactDescription( impact, state, windowSeconds ) }
			</p>
			{ evaluated && (
				<IncompleteChecks
					impact={ impact }
					windowSeconds={ windowSeconds }
				/>
			) }
			{ groups.length > 0 && (
				<ul
					aria-label={ __( 'Findings', 'updatelens' ) }
					className="mt-5 space-y-2.5"
				>
					{ groups.map( ( group ) => (
						<FindingGroupItem
							key={ group.key }
							group={ group }
							visible={ view.open[ group.key ] ?? null }
							onVisibleChange={ ( visible ) =>
								setVisible( group.key, visible )
							}
							indexes={ indexes }
							findingHref={ findingHref }
							onOpenFinding={ onOpenFinding }
						/>
					) ) }
				</ul>
			) }
			<WhatWasChecked
				impact={ impact }
				windowSeconds={ windowSeconds }
				open={ view.checks }
				onToggle={ ( checks ) => onViewChange( { ...view, checks } ) }
			/>
		</section>
	);
}

/**
 * Signals whose checks did not run, always visible (also while every group
 * is collapsed): partial results must never read as complete.
 *
 * @param props               Props.
 * @param props.impact        API evaluation.
 * @param props.windowSeconds Observation window length from the API.
 */
function IncompleteChecks( {
	impact,
	windowSeconds,
}: {
	impact: PotentialImpactData;
	windowSeconds: number;
} ) {
	const incomplete = PROVIDERS.filter(
		( signal ) => impact.signals[ signal ].status === 'not_evaluated'
	);
	if ( incomplete.length === 0 ) {
		return null;
	}
	return (
		<ul
			aria-label={ __( 'Checks that did not run', 'updatelens' ) }
			className="mt-3 space-y-1 rounded-lg bg-amber-50/70 px-3 py-2 text-[13px] leading-5 text-amber-900 ring-1 ring-inset ring-amber-200"
		>
			{ incomplete.map( ( signal ) => {
				const text = impactSignalText( signal, impact, windowSeconds );
				return (
					<li
						key={ signal }
						data-category={ text.category }
						className="flex gap-2"
					>
						<Icon
							name={ signal }
							className="mt-0.5 size-4 shrink-0 text-amber-700"
						/>
						<span>
							<span className="font-medium">
								{ providerLabel( signal ) }
							</span>
							{ ': ' }
							{ text.status }
							{ text.detail && (
								<>
									{ ' · ' }
									{ text.detail }
								</>
							) }
						</span>
					</li>
				);
			} ) }
		</ul>
	);
}

/**
 * "What was checked": every signal's state, the patterns checked and what a
 * result cannot show. Collapsed by default.
 *
 * @param props               Props.
 * @param props.impact        API evaluation.
 * @param props.windowSeconds Observation window length from the API.
 * @param props.open          Whether it is open.
 * @param props.onToggle      Reports a new open state.
 */
function WhatWasChecked( {
	impact,
	windowSeconds,
	open,
	onToggle,
}: {
	impact: PotentialImpactData;
	windowSeconds: number;
	open: boolean;
	onToggle: ( open: boolean ) => void;
} ) {
	return (
		<details
			open={ open }
			onToggle={ ( event ) => {
				if ( event.currentTarget.open !== open ) {
					onToggle( event.currentTarget.open );
				}
			} }
			className="group mt-4 border-t pt-3"
		>
			<summary className="-mx-1 inline-flex cursor-pointer select-none list-none items-center gap-1.5 rounded px-1 py-1 text-sm font-medium text-slate-700 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&::-webkit-details-marker]:hidden">
				<Icon
					name="chevron-down"
					className="size-4 -rotate-90 text-slate-500 transition-transform group-open:rotate-0"
				/>
				{ __( 'What was checked', 'updatelens' ) }
			</summary>
			<div className="mt-2 space-y-4 pb-1 text-[13px] leading-5">
				<dl className="grid gap-x-6 gap-y-2 sm:grid-cols-[auto_minmax(0,1fr)]">
					{ PROVIDERS.map( ( signal ) => {
						const text = impactSignalText(
							signal,
							impact,
							windowSeconds
						);
						const incomplete =
							text.category !== 'checked' &&
							text.category !== 'not_applicable';
						return (
							<div key={ signal } className="contents">
								<dt className="flex items-center gap-2 font-medium text-slate-800">
									<Icon
										name={ signal }
										className="size-4 text-slate-500"
									/>
									{ providerLabel( signal ) }
								</dt>
								<dd
									data-category={ text.category }
									className={ cn(
										'pl-6 sm:pl-0',
										incomplete
											? 'text-amber-900'
											: 'text-slate-600'
									) }
								>
									<span
										className={ cn(
											'font-medium',
											text.category === 'checked' &&
												'text-slate-800'
										) }
									>
										{ text.status }
									</span>
									{ text.detail && (
										<>
											{ ' · ' }
											{ text.detail }
										</>
									) }
									{ text.description && (
										<span className="block text-slate-600">
											{ text.description }
										</span>
									) }
								</dd>
							</div>
						);
					} ) }
				</dl>
				<div>
					<h4 className="font-semibold text-slate-800">
						{ __( 'Patterns checked', 'updatelens' ) }
					</h4>
					<ul className="mt-1 list-disc space-y-0.5 pl-5 text-slate-600 marker:text-slate-400">
						{ checkedPatterns().map( ( line ) => (
							<li key={ line }>{ line }</li>
						) ) }
					</ul>
				</div>
				<div>
					<h4 className="font-semibold text-slate-800">
						{ __( 'What this does not show', 'updatelens' ) }
					</h4>
					<ul className="mt-1 list-disc space-y-0.5 pl-5 text-slate-600 marker:text-slate-400">
						{ checkLimitations().map( ( line ) => (
							<li key={ line }>{ line }</li>
						) ) }
					</ul>
				</div>
			</div>
		</details>
	);
}

/**
 * One rule and signal as a compact row: title, count, signal, the first
 * names and one sentence, with a button that shows its details. Details are
 * rendered only while expanded.
 *
 * @param props                 Props.
 * @param props.group           Findings of one rule and signal.
 * @param props.visible         Findings shown while expanded; null: collapsed.
 * @param props.onVisibleChange Expands (with a count), reveals more or collapses (null).
 * @param props.indexes         Position of each finding in the API's list.
 * @param props.findingHref     Link target of a finding's signal details.
 * @param props.onOpenFinding   Opens a finding's signal details in the app.
 */
function FindingGroupItem( {
	group,
	visible,
	onVisibleChange,
	indexes,
	findingHref,
	onOpenFinding,
}: {
	group: FindingGroup;
	visible: number | null;
	onVisibleChange: ( visible: number | null ) => void;
	indexes: ReadonlyMap< ImpactFinding, number >;
	findingHref: ( finding: ImpactFinding ) => string;
	onOpenFinding: ( finding: ImpactFinding ) => void;
} ) {
	const id = useId();
	const titleId = `${ id }-title`;
	const summaryId = `${ id }-summary`;
	const panelId = `${ id }-panel`;
	const expanded = visible !== null;
	const total = group.findings.length;
	const { names, more } = groupSubjects( group );

	return (
		<li
			aria-labelledby={ titleId }
			className={ cn(
				'overflow-hidden rounded-xl border bg-card transition-colors',
				expanded ? 'border-slate-300' : 'hover:border-slate-300'
			) }
		>
			<div
				className={ cn(
					'relative flex items-start gap-3 px-4 py-3.5 sm:px-5',
					! expanded && 'hover:bg-slate-50/60'
				) }
			>
				{ /* The signal is named in the row; on phones its icon would only narrow the text. */ }
				<span className="hidden size-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-600 sm:grid">
					<Icon name={ group.signal } className="size-[1.125rem]" />
				</span>
				<div className="min-w-0 flex-1">
					<h4
						id={ titleId }
						className="text-[15px] font-semibold leading-6 text-slate-900"
					>
						<button
							type="button"
							aria-expanded={ expanded }
							aria-controls={ panelId }
							aria-describedby={ summaryId }
							onClick={ () =>
								onVisibleChange(
									expanded ? null : DEFAULT_VISIBLE_FINDINGS
								)
							}
							className="text-left after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:rounded-[inherit] focus-visible:after:ring-2 focus-visible:after:ring-inset focus-visible:after:ring-ring"
						>
							{ findingGroupTitle( group ) }
							<span
								aria-hidden="true"
								className="ml-2 inline-flex min-w-6 justify-center rounded-full bg-slate-100 px-2 align-[1px] text-xs font-semibold tabular-nums leading-5 text-slate-700"
							>
								{ formatCount( total ) }
							</span>
							<span className="sr-only">
								{ ', ' }
								{ sprintf(
									/* translators: %s: number of findings. */
									_n(
										'%s finding',
										'%s findings',
										total,
										'updatelens'
									),
									formatCount( total )
								) }
							</span>
						</button>
					</h4>
					<p className="mt-0.5 flex min-w-0 flex-wrap items-baseline gap-x-1.5 text-[13px] leading-5 text-muted-foreground">
						<span>{ providerLabel( group.signal ) }</span>{ ' ' }
						<span aria-hidden="true">·</span>{ ' ' }
						{ names.map( ( name, index ) => (
							<Fragment key={ name }>
								<span className="inline-flex min-w-0 max-w-full">
									<code
										title={ name }
										className="truncate bg-transparent p-0 font-mono text-[12.5px] text-slate-700"
									>
										{ name }
									</code>
									{ index < names.length - 1 && ',' }
								</span>{ ' ' }
							</Fragment>
						) ) }
						{ more > 0 && (
							<span>
								{ sprintf(
									/* translators: %s: number of further option names or hooks. */
									_n(
										'and %s more',
										'and %s more',
										more,
										'updatelens'
									),
									formatCount( more )
								) }
							</span>
						) }
					</p>
					<p
						id={ summaryId }
						className="mt-1 text-sm leading-6 text-slate-700"
					>
						{ groupSummary( group ) }
					</p>
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
					<GroupDetails
						group={ group }
						visible={ visible }
						onVisibleChange={ onVisibleChange }
						indexes={ indexes }
						findingHref={ findingHref }
						onOpenFinding={ onOpenFinding }
					/>
				) }
			</div>
		</li>
	);
}

/**
 * An expanded group: what changed (a few findings at a time), why it might
 * matter and what to check next.
 *
 * @param props                 Props.
 * @param props.group           Findings of one rule and signal.
 * @param props.visible         Findings shown.
 * @param props.onVisibleChange Reveals more or fewer.
 * @param props.indexes         Position of each finding in the API's list.
 * @param props.findingHref     Link target of a finding's signal details.
 * @param props.onOpenFinding   Opens a finding's signal details in the app.
 */
function GroupDetails( {
	group,
	visible: visibleCount,
	onVisibleChange,
	indexes,
	findingHref,
	onOpenFinding,
}: {
	group: FindingGroup;
	visible: number;
	onVisibleChange: ( visible: number | null ) => void;
	indexes: ReadonlyMap< ImpactFinding, number >;
	findingHref: ( finding: ImpactFinding ) => string;
	onOpenFinding: ( finding: ImpactFinding ) => void;
} ) {
	const listId = useId();
	const total = group.findings.length;
	const visible = group.findings.slice( 0, visibleCount );
	const hidden = total - visible.length;
	const more = Math.min( hidden, FINDINGS_STEP );
	// The button that was pressed can disappear (nothing left to show, or
	// back to the first rows): focus then moves to the other one.
	const moreButton = useRef< HTMLButtonElement >( null );
	const fewerButton = useRef< HTMLButtonElement >( null );
	const focusNext = useRef< 'more' | 'fewer' | null >( null );
	useEffect( () => {
		const target = focusNext.current;
		focusNext.current = null;
		if ( target === 'more' ) {
			moreButton.current?.focus();
		} else if ( target === 'fewer' ) {
			fewerButton.current?.focus();
		}
	}, [ visibleCount ] );

	return (
		<div className="border-t">
			<div className="px-4 pt-3 sm:px-5">
				<h5 className="text-xs font-semibold uppercase tracking-[0.06em] text-slate-500">
					{ __( 'What changed', 'updatelens' ) }
				</h5>
			</div>
			<ul id={ listId } className="mt-2 divide-y border-y bg-slate-50/50">
				{ visible.map( ( finding ) => {
					const index = indexes.get( finding ) ?? -1;
					return (
						<FindingRow
							key={ index }
							index={ index }
							finding={ finding }
							href={ findingHref( finding ) }
							onOpen={ () => onOpenFinding( finding ) }
						/>
					);
				} ) }
			</ul>
			{ total > DEFAULT_VISIBLE_FINDINGS && (
				<div className="flex flex-wrap items-center gap-2 border-b px-4 py-2 sm:px-5">
					<span className="mr-auto text-[13px] tabular-nums text-muted-foreground">
						{ sprintf(
							/* translators: 1: number of findings shown. 2: number of findings in the group. */
							__( 'Showing %1$s of %2$s', 'updatelens' ),
							formatCount( visible.length ),
							formatCount( total )
						) }
					</span>
					{ hidden > 0 && (
						<Button
							type="button"
							variant="ghost"
							size="sm"
							ref={ moreButton }
							aria-controls={ listId }
							onClick={ () => {
								if ( hidden <= FINDINGS_STEP ) {
									focusNext.current = 'fewer';
								}
								onVisibleChange( visibleCount + FINDINGS_STEP );
							} }
							className="text-primary hover:bg-tint hover:text-primary"
						>
							{ sprintf(
								/* translators: 1: number of findings to reveal. 2: number of hidden findings. */
								_n(
									'Show %1$s more (%2$s hidden)',
									'Show %1$s more (%2$s hidden)',
									more,
									'updatelens'
								),
								formatCount( more ),
								formatCount( hidden )
							) }
						</Button>
					) }
					{ visibleCount > DEFAULT_VISIBLE_FINDINGS && (
						<Button
							type="button"
							variant="ghost"
							size="sm"
							ref={ fewerButton }
							aria-controls={ listId }
							onClick={ () => {
								focusNext.current = 'more';
								onVisibleChange( DEFAULT_VISIBLE_FINDINGS );
							} }
							className="text-slate-600"
						>
							{ __( 'Show fewer', 'updatelens' ) }
						</Button>
					) }
				</div>
			) }
			<div className="grid gap-x-8 gap-y-4 px-4 py-4 sm:px-5 md:grid-cols-2">
				<div>
					<h5 className="text-xs font-semibold uppercase tracking-[0.06em] text-slate-500">
						{ __( 'Why it might matter', 'updatelens' ) }
					</h5>
					<div className="mt-1.5 space-y-1.5 text-sm leading-relaxed text-slate-700">
						{ whyItMatters( group ).map( ( sentence ) => (
							<p key={ sentence }>{ sentence }</p>
						) ) }
					</div>
				</div>
				<div>
					<h5 className="text-xs font-semibold uppercase tracking-[0.06em] text-slate-500">
						{ __( 'What to check next', 'updatelens' ) }
					</h5>
					<ul className="mt-1.5 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-slate-700 marker:text-slate-400">
						{ whatToCheck( group ).map( ( step ) => (
							<li key={ step }>{ step }</li>
						) ) }
					</ul>
				</div>
			</div>
		</div>
	);
}

/**
 * One finding: its option or hook, what changed with the before and after
 * values, and a link to the row on the signal's details.
 *
 * @param props         Props.
 * @param props.index   Position in the API's findings (focus on return).
 * @param props.finding Finding.
 * @param props.href    Link target of the signal's details.
 * @param props.onOpen  Opens the details in the app.
 */
function FindingRow( {
	index,
	finding,
	href,
	onOpen,
}: {
	index: number;
	finding: ImpactFinding;
	href: string;
	onOpen: () => void;
} ) {
	const subject = findingSubject( finding );
	const context = cronRemovalContext( finding );

	return (
		<li className="grid gap-x-4 gap-y-1.5 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:px-5">
			<div className="min-w-0">
				<Identifier name={ subject } />
				{ finding.signal === 'action_scheduler' &&
					finding.group !== '' && (
						<p className="text-xs leading-5 text-muted-foreground [overflow-wrap:anywhere]">
							{ sprintf(
								/* translators: %s: Action Scheduler group slug. */
								__( 'Group: %s', 'updatelens' ),
								finding.group
							) }
						</p>
					) }
			</div>
			<AppLink
				href={ href }
				onNavigate={ onOpen }
				data-updatelens-finding={ index }
				aria-label={ sprintf(
					/* translators: 1: signal name, e.g. "WP-Cron". 2: option name or hook. */
					__( 'View in %1$s: %2$s', 'updatelens' ),
					providerLabel( finding.signal ),
					subject
				) }
				className="inline-flex scroll-mt-24 items-center gap-1 self-start rounded text-sm font-medium text-primary no-underline hover:underline focus:shadow-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:row-span-2 sm:justify-self-end"
			>
				{ sprintf(
					/* translators: %s: signal name, e.g. "WP-Cron". */
					__( 'View in %s', 'updatelens' ),
					providerLabel( finding.signal )
				) }
				<Icon name="arrow-right" className="size-4" />
			</AppLink>
			<div className="min-w-0 space-y-1.5">
				<p className="text-sm leading-6 text-slate-800">
					{ whatChanged( finding ) }
				</p>
				<RowDetails boxed rows={ evidenceRows( finding ) } />
				{ context.length > 0 && (
					<p className="text-[13px] leading-5 text-slate-600">
						{ context.join( ' ' ) }
					</p>
				) }
			</div>
		</li>
	);
}

/**
 * The finding's before and after values, as label/value rows.
 *
 * @param finding Finding.
 */
function evidenceRows(
	finding: ImpactFinding
): Array< [ string, ReactNode ] > {
	switch ( finding.code ) {
		case 'large_autoloaded_option': {
			const { before, after, evidence } = finding;
			const rows: Array< [ string, ReactNode ] > = [];
			if ( before === null ) {
				rows.push( [
					__( 'Size', 'updatelens' ),
					formatBytes( after.size ),
				] );
				rows.push( [
					__( 'Autoload', 'updatelens' ),
					autoloadText( after.autoload, after.is_autoloaded ),
				] );
			} else {
				const [ from, to ] = formatBytesPair( before.size, after.size );
				rows.push( [
					__( 'Size', 'updatelens' ),
					before.size === after.size ? (
						<>
							{ to }{ ' ' }
							<span className="text-muted-foreground">
								{ __( '(unchanged)', 'updatelens' ) }
							</span>
						</>
					) : (
						<>
							{ from }
							<To />
							{ to }
							{ evidence.size_delta !== null && (
								<span className="ml-1.5 tabular-nums text-muted-foreground">
									({ formatBytesDelta( evidence.size_delta ) }
									)
								</span>
							) }
						</>
					),
				] );
				rows.push( [
					__( 'Autoload', 'updatelens' ),
					before.is_autoloaded === after.is_autoloaded &&
					before.autoload === after.autoload ? (
						autoloadText( after.autoload, after.is_autoloaded )
					) : (
						<>
							{ autoloadText(
								before.autoload,
								before.is_autoloaded
							) }
							<To />
							{ autoloadText(
								after.autoload,
								after.is_autoloaded
							) }
						</>
					),
				] );
			}
			rows.push( [
				__( 'Review size', 'updatelens' ),
				sprintf(
					/* translators: %s: review size, e.g. "150,000 bytes (146.5 KB)". */
					__( 'More than %s', 'updatelens' ),
					thresholdText( evidence.threshold_bytes )
				),
			] );
			return rows;
		}
		case 'recurring_cron_event_removed': {
			const removed = finding.before.removed_recurring;
			const rows: Array< [ string, ReactNode ] > = [
				[
					_n(
						'Previous schedule',
						'Previous schedules',
						removed.length,
						'updatelens'
					),
					<CronInstances
						key="b"
						instances={ removed }
						timeLabel={ __(
							'previously scheduled for',
							'updatelens'
						) }
					/>,
				],
			];
			if ( finding.after.added_recurring.length > 0 ) {
				rows.push( [
					__( 'Added for this hook', 'updatelens' ),
					<CronInstances
						key="a"
						instances={ finding.after.added_recurring }
						timeLabel={ __( 'next run', 'updatelens' ) }
					/>,
				] );
			}
			return rows;
		}
		case 'recurring_schedule_changed':
			return finding.signal === 'cron'
				? cronScheduleRows( finding.before, finding.after )
				: actionScheduleRows( finding.before, finding.after );
	}
}

/**
 * "On", "Off", plus the stored value when it says more (`auto`, `auto-on`, `auto-off`).
 *
 * @param raw          Raw autoload value.
 * @param isAutoloaded Effective autoload behavior.
 */
function autoloadText( raw: string, isAutoloaded: boolean ): string {
	const label = isAutoloaded
		? __( 'On', 'updatelens' )
		: __( 'Off', 'updatelens' );
	return [ 'on', 'off', 'yes', 'no' ].includes( raw )
		? label
		: `${ label } (${ raw })`;
}

/**
 * "every 1 hour" or the schedule name alone when the interval is not stored.
 *
 * @param interval Seconds or null.
 */
function everyText( interval: number | null ): string | null {
	return interval === null
		? null
		: sprintf(
				/* translators: %s: duration, e.g. "1 hour". */
				__( 'every %s', 'updatelens' ),
				formatDuration( interval )
			);
}

/**
 * A WP-Cron recurrence: "hourly · every 1 hour", "One-time".
 *
 * @param side Schedule, interval and (for changes) whether it recurs.
 */
function cronRecurrence( side: {
	schedule: string | null;
	interval: number | null;
	is_recurring?: boolean;
} ): string {
	if ( side.is_recurring === false ) {
		return __( 'One-time', 'updatelens' );
	}
	return [ side.schedule, everyText( side.interval ) ]
		.filter( Boolean )
		.join( ' · ' );
}

/**
 * Removed or added recurring WP-Cron instances with their scheduled runs.
 *
 * @param props           Props.
 * @param props.instances Instances.
 * @param props.timeLabel Words before each run, e.g. "previously scheduled for".
 */
function CronInstances( {
	instances,
	timeLabel,
}: {
	instances: ImpactCronInstance[];
	timeLabel: string;
} ) {
	const shown = instances.slice( 0, 3 );
	return (
		<span className="block space-y-0.5">
			{ shown.map( ( instance, index ) => (
				<span key={ index } className="block">
					{ cronRecurrence( instance ) }
					<span className="text-muted-foreground">
						{ ' · ' }
						{ timeLabel } <Time timestamp={ instance.timestamp } />
					</span>
				</span>
			) ) }
			{ instances.length > shown.length && (
				<span className="block text-muted-foreground">
					{ sprintf(
						/* translators: %s: number of further instances. */
						_n(
							'and %s more',
							'and %s more',
							instances.length - shown.length,
							'updatelens'
						),
						formatCount( instances.length - shown.length )
					) }
				</span>
			) }
		</span>
	);
}

/**
 * Rows of a WP-Cron schedule change.
 *
 * @param before State before.
 * @param after  State after.
 */
function cronScheduleRows(
	before: ImpactCronSide,
	after: ImpactCronSide
): Array< [ string, ReactNode ] > {
	return [
		[
			__( 'Schedule', 'updatelens' ),
			<>
				{ cronRecurrence( before ) }
				<To />
				{ cronRecurrence( after ) }
			</>,
		],
		[
			__( 'Next run', 'updatelens' ),
			<Time key="t" timestamp={ after.timestamp } />,
		],
	];
}

/**
 * An Action Scheduler schedule: "Interval · every 1 hour", "Cron schedule ·
 * <expression>", "One-time".
 *
 * @param props      Props.
 * @param props.side Schedule.
 */
function ActionSchedule( { side }: { side: ImpactActionSide } ) {
	const type: ActionScheduleType = side.schedule_type;
	return (
		<>
			{ actionScheduleTypeLabel( type ) }
			{ type === 'interval' && side.interval !== null && (
				<>
					{ ' · ' }
					{ everyText( side.interval ) }
				</>
			) }
			{ type === 'cron' && side.cron_expression !== null && (
				<>
					{ ' · ' }
					<code className="rounded bg-white px-1 py-0.5 font-mono text-[12px] text-slate-800 ring-1 ring-inset ring-slate-200">
						{ side.cron_expression }
					</code>
				</>
			) }
		</>
	);
}

/**
 * Rows of an Action Scheduler schedule change.
 *
 * @param before State before.
 * @param after  State after.
 */
function actionScheduleRows(
	before: ImpactActionSide,
	after: ImpactActionSide
): Array< [ string, ReactNode ] > {
	return [
		[
			__( 'Schedule', 'updatelens' ),
			<>
				<ActionSchedule side={ before } />
				<To />
				<ActionSchedule side={ after } />
			</>,
		],
		[
			__( 'Next run', 'updatelens' ),
			<Time key="t" timestamp={ after.timestamp } />,
		],
	];
}
