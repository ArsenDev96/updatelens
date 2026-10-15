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
	AnalysisReport,
	FindingPriority,
	ImpactActionSide,
	ImpactCronInstance,
	ImpactCronSide,
	ImpactFinding,
	PotentialImpact as PotentialImpactData,
	SiteCheckFinding,
	SiteCheckId,
	SiteChecks,
} from '../types/api';
import {
	formatBytesDelta,
	formatBytes,
	formatBytesPair,
	formatCount,
	formatDuration,
	keepTogether,
} from '../utils/format';
import { notAssessedLines } from '../utils/glance';
import {
	checkedPatterns,
	checkLimitations,
	cronRemovalContext,
	findingGroupTitle,
	findingSubject,
	groupFacts,
	groupFindings,
	groupSubjects,
	groupSummary,
	impactCompleteness,
	impactSignalText,
	impactSourceLabel,
	priorityLabel,
	thresholdText,
	whatChanged,
	whatToCheck,
	whyItMatters,
	type CheckCategory,
	type FindingGroup,
	type ImpactView,
} from '../utils/impact';
import {
	informationalText,
	resultPanel,
	type ResultPanel,
} from '../utils/results';
import { siteCheckFindingButtonId } from '../utils/siteChecks';
import {
	SITE_CHECKS,
	siteCheckCoverage,
	siteCheckFindings,
	siteCheckFindingTitle,
	siteCheckLimitations,
	siteCheckName,
	siteCheckPatterns,
	siteChecksEvaluated,
	siteChecksNotUsed,
	siteCheckSubject,
	siteCheckSummary,
	siteCheckWhatChanged,
	siteCheckWhatToCheck,
	siteCheckWhyItMatters,
} from '../utils/siteCheckImpact';
import {
	actionScheduleTypeLabel,
	PROVIDERS,
	providerLabel,
	type Tone,
} from '../utils/labels';
import type { ReportTab } from '../utils/route';
import { AppLink } from './AppLink';
import { Time, To } from './DiffRow';
import { Icon, type IconName } from './Icon';
import { Identifier, RowDetails } from './SignalParts';

/*
 * The "Update results" tab of a report: the review rules the API evaluated
 * on the Net result and on the website checks, whatever phase is selected
 * in Configuration changes.
 *
 * With findings, they are listed by the priority the API gave them (High
 * priority, then Needs review). Progressive disclosure: findings are grouped
 * by rule and signal, and each group starts as one compact row (title,
 * count, signal, the first names and one actionable sentence). Its details
 * (what changed, why it might matter, what to check next, and the evidence:
 * one row per finding with the exact before → after values and a link to
 * the located entry) are rendered only while it is expanded, a few findings
 * at a time. Without findings, one result panel says so, worded by how
 * complete the observations were (never "safe"). What could not be assessed
 * and the informational changes follow, kept apart from the priorities;
 * every pattern checked, and what a result cannot show, is behind "View
 * review rules".
 */

/** Findings shown when a group is expanded, before "Show more". */
export const DEFAULT_VISIBLE_FINDINGS = 3;

/** Findings revealed per "Show more". */
const FINDINGS_STEP = 50;

/** Id of the review rules disclosure's content. */
const RULES_ID = 'updatelens-review-rules';

/**
 * The Update results tab.
 *
 * @param props                 Props.
 * @param props.report          Report.
 * @param props.findingHref     Link target of a finding's signal details.
 * @param props.onOpenFinding   Opens a finding's signal details in the app.
 * @param props.view            Expanded groups and disclosures.
 * @param props.onViewChange    Updates them.
 * @param props.onShowSiteCheck Opens a check in Website checks.
 * @param props.onShowTab       Opens another tab of the report.
 */
export function UpdateResults( {
	report,
	findingHref,
	onOpenFinding,
	view,
	onViewChange,
	onShowSiteCheck,
	onShowTab,
}: {
	report: AnalysisReport;
	findingHref: ( finding: ImpactFinding ) => string;
	onOpenFinding: ( finding: ImpactFinding ) => void;
	view: ImpactView;
	onViewChange: ( view: ImpactView ) => void;
	onShowSiteCheck: ( check: SiteCheckId ) => void;
	onShowTab: ( tab: ReportTab ) => void;
} ) {
	const impact = report.potential_impact;
	const groups = useMemo(
		() => groupFindings( impact.findings ),
		[ impact.findings ]
	);
	const siteFindings = siteCheckFindings( impact );
	// Position of each finding in the API's list, for links and focus.
	const indexes = useMemo(
		() =>
			new Map(
				impact.findings.map( ( finding, index ) => [ finding, index ] )
			),
		[ impact.findings ]
	);
	const panel = resultPanel( report );
	// An expired observation is explained once, by the status above the
	// tabs: only what else could not be assessed is listed here.
	const notAssessed = notAssessedLines( report, {
		siteChecks: true,
		expired: false,
	} );
	const informational = informationalText( report );
	const setVisible = ( key: string, visible: number | null ) => {
		const open = { ...view.open };
		if ( visible === null ) {
			delete open[ key ];
		} else {
			open[ key ] = visible;
		}
		onViewChange( { ...view, open } );
	};

	const siteItem = ( finding: SiteCheckFinding ) => {
		const key = `site_check:${ finding.check }`;
		const expanded = view.open[ key ] !== undefined;
		return (
			<SiteCheckFindingItem
				key={ key }
				finding={ finding }
				onShowSiteCheck={ onShowSiteCheck }
				expanded={ expanded }
				onToggle={ () =>
					setVisible(
						key,
						expanded ? null : DEFAULT_VISIBLE_FINDINGS
					)
				}
			/>
		);
	};
	const groupItem = ( group: FindingGroup ) => (
		<FindingGroupItem
			key={ group.key }
			group={ group }
			visible={ view.open[ group.key ] ?? null }
			onVisibleChange={ ( visible ) => setVisible( group.key, visible ) }
			indexes={ indexes }
			findingHref={ findingHref }
			onOpenFinding={ onOpenFinding }
		/>
	);
	const isHigh = ( priority: FindingPriority ) => priority === 'high';
	// Highest priority first: confirmed newly failing website checks, then
	// the rules that merit a review (configuration, then website check
	// changes).
	const high = [
		...siteFindings
			.filter( ( finding ) => isHigh( finding.priority ) )
			.map( siteItem ),
		...groups
			.filter( ( group ) => isHigh( group.findings[ 0 ].priority ) )
			.map( groupItem ),
	];
	const review = [
		...groups
			.filter( ( group ) => ! isHigh( group.findings[ 0 ].priority ) )
			.map( groupItem ),
		...siteFindings
			.filter( ( finding ) => ! isHigh( finding.priority ) )
			.map( siteItem ),
	];
	// Findings listed while website check results are still on their way.
	const pending =
		panel === null && impactCompleteness( impact ) === 'pending';

	return (
		<div className="space-y-5">
			{ panel ? (
				<ResultPanelView panel={ panel } />
			) : (
				<div className="space-y-6">
					<p className="text-[13px] leading-5 text-muted-foreground">
						{ impactSourceLabel(
							impact.status !== 'not_evaluated',
							siteChecksEvaluated( impact )
						) }
					</p>
					{ high.length > 0 && (
						<PriorityGroup
							priority="high"
							count={ impact.assessment.high_priority }
						>
							{ high }
						</PriorityGroup>
					) }
					{ review.length > 0 && (
						<PriorityGroup
							priority="review"
							count={ impact.assessment.needs_review }
						>
							{ review }
						</PriorityGroup>
					) }
				</div>
			) }
			{ ( pending || notAssessed.length > 0 || informational ) && (
				<div className="space-y-3">
					{ pending && (
						<Remark icon="progress" tone="progress">
							{ __(
								'The website checks after the update have not arrived yet; until they do, they are not counted as passed. This report checks for them by itself while it is open.',
								'updatelens'
							) }
						</Remark>
					) }
					{ notAssessed.length > 0 && (
						<NotAssessed
							lines={ notAssessed }
							onShowChecks={
								impact.assessment.not_assessed.some(
									( entry ) => entry.type === 'site_check'
								)
									? () => onShowTab( 'checks' )
									: null
							}
						/>
					) }
					{ informational && (
						<Remark icon="info" tone="neutral">
							{ informational }{ ' ' }
							<TextButton
								onClick={ () => onShowTab( 'configuration' ) }
							>
								{ __(
									'View configuration changes',
									'updatelens'
								) }
							</TextButton>
						</Remark>
					) }
				</div>
			) }
			<ReviewRules
				impact={ impact }
				siteChecks={ report.site_checks }
				windowSeconds={ report.observation_window_seconds }
				open={ view.checks }
				onToggle={ ( checks ) => onViewChange( { ...view, checks } ) }
			/>
		</div>
	);
}

/** Classes of the result panel and its badge, per tone. */
const PANEL: Record< Tone, { panel: string; badge: string } > = {
	positive: {
		panel: 'border-emerald-200 bg-emerald-50/70',
		badge: 'bg-emerald-600 text-white ring-emerald-100',
	},
	neutral: {
		panel: 'border-slate-200 bg-slate-50',
		badge: 'bg-slate-200 text-slate-700 ring-slate-100',
	},
	progress: {
		panel: 'border-tint-border bg-tint',
		badge: 'bg-primary text-primary-foreground ring-tint-strong',
	},
	caution: {
		panel: 'border-amber-200 bg-amber-50',
		badge: 'bg-amber-500 text-white ring-amber-100',
	},
	negative: {
		panel: 'border-rose-200 bg-rose-50',
		badge: 'bg-rose-600 text-white ring-rose-100',
	},
};

/**
 * The result without findings: a spacious panel with its answer.
 *
 * @param props       Props.
 * @param props.panel Panel.
 */
function ResultPanelView( { panel }: { panel: ResultPanel } ) {
	const style = PANEL[ panel.tone ];
	return (
		<section
			aria-labelledby="updatelens-result-title"
			data-tone={ panel.tone }
			className={ cn(
				'flex flex-col gap-4 rounded-xl border px-5 py-6 sm:flex-row sm:items-center sm:gap-7 sm:px-8 sm:py-8',
				style.panel
			) }
		>
			<span
				className={ cn(
					'grid size-12 shrink-0 place-items-center rounded-full ring-[6px] sm:size-16 sm:ring-8',
					style.badge
				) }
			>
				<Icon
					name={ panel.icon === 'check' ? 'checkmark' : panel.icon }
					className="size-6 stroke-[2.5] sm:size-8"
				/>
			</span>
			<div className="min-w-0 space-y-1.5">
				<h3
					id="updatelens-result-title"
					className="text-xl font-semibold leading-tight tracking-tight text-slate-900 sm:text-[1.375rem]"
				>
					{ panel.title }
				</h3>
				<p className="max-w-2xl text-[15px] leading-relaxed text-slate-700">
					{ panel.text }
				</p>
				{ panel.note && (
					<p className="max-w-2xl text-sm leading-relaxed text-slate-600">
						{ panel.note }
					</p>
				) }
			</div>
		</section>
	);
}

/**
 * The findings of one priority, under its heading with the API's count.
 *
 * @param props          Props.
 * @param props.priority Priority.
 * @param props.count    Findings of this priority (the API's count).
 * @param props.children Rows.
 */
function PriorityGroup( {
	priority,
	count,
	children,
}: {
	priority: FindingPriority;
	count: number;
	children: ReactNode;
} ) {
	const id = useId();
	return (
		<section aria-labelledby={ id } data-priority={ priority }>
			<h3
				id={ id }
				className={ cn(
					'flex items-center gap-2 text-base font-semibold',
					priority === 'high' ? 'text-rose-800' : 'text-amber-900'
				) }
			>
				<Icon
					name={ priority === 'high' ? 'failure' : 'caution' }
					className="size-[1.125rem]"
				/>
				{ priorityLabel( priority ) }
				<span
					className={ cn(
						'inline-flex min-w-6 justify-center rounded-full px-2 text-xs font-semibold tabular-nums leading-5 ring-1 ring-inset',
						priority === 'high'
							? 'bg-rose-50 text-rose-800 ring-rose-200'
							: 'bg-amber-50 text-amber-900 ring-amber-200'
					) }
				>
					<span className="sr-only">{ ', ' }</span>
					{ formatCount( count ) }
				</span>
			</h3>
			<ul className="mt-2.5 space-y-2">{ children }</ul>
		</section>
	);
}

/**
 * What could not be assessed, one line per API category, apart from the
 * priorities (never a finding, never "passed").
 *
 * @param props              Props.
 * @param props.lines        Lines (notAssessedLines()).
 * @param props.onShowChecks Opens Website checks, when a check is named.
 */
function NotAssessed( {
	lines,
	onShowChecks,
}: {
	lines: string[];
	onShowChecks: ( () => void ) | null;
} ) {
	const id = useId();
	return (
		<section
			aria-labelledby={ id }
			className="rounded-xl border bg-card px-4 py-3 sm:px-5"
		>
			<h3
				id={ id }
				className="flex items-center gap-2 text-sm font-semibold text-slate-900"
			>
				<Icon name="info" className="size-4 text-slate-500" />
				{ __( 'Not assessed', 'updatelens' ) }
			</h3>
			<ul className="mt-1 space-y-0.5 pl-6 text-[13px] leading-5 text-slate-700">
				{ lines.map( ( line ) => (
					<li key={ line }>{ line }</li>
				) ) }
			</ul>
			{ onShowChecks && (
				<p className="mt-1.5 pl-6 text-[13px]">
					<TextButton onClick={ onShowChecks }>
						{ __( 'View website checks', 'updatelens' ) }
					</TextButton>
				</p>
			) }
		</section>
	);
}

/**
 * One quiet line with an icon.
 *
 * @param props          Props.
 * @param props.icon     Icon.
 * @param props.tone     Tone of the icon.
 * @param props.children Text.
 */
function Remark( {
	icon,
	tone,
	children,
}: {
	icon: IconName;
	tone: Tone;
	children: ReactNode;
} ) {
	return (
		<p className="flex gap-2 px-1 text-[13px] leading-5 text-slate-600">
			<Icon
				name={ icon }
				className={ cn(
					'mt-0.5 size-4',
					tone === 'progress' ? 'text-primary' : 'text-slate-500'
				) }
			/>
			<span>{ children }</span>
		</p>
	);
}

/**
 * A button that reads as a link (it moves within the report).
 *
 * @param props          Props.
 * @param props.onClick  Action.
 * @param props.children Text.
 */
function TextButton( {
	onClick,
	children,
}: {
	onClick: () => void;
	children: ReactNode;
} ) {
	return (
		<button
			type="button"
			onClick={ onClick }
			className="inline-flex min-h-6 items-center gap-1 rounded font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
		>
			{ children }
			<Icon name="arrow-right" className="size-3.5" />
		</button>
	);
}

/**
 * "What UpdateLens checks": what the review rules look at, in two
 * sentences, and behind "View review rules" every signal's state, the
 * patterns checked and what a result cannot show (rendered only while open).
 *
 * @param props               Props.
 * @param props.impact        API evaluation.
 * @param props.siteChecks    The report's site checks (timing of compared checks).
 * @param props.windowSeconds Observation window length from the API.
 * @param props.open          Whether the rules are shown.
 * @param props.onToggle      Reports a new open state.
 */
function ReviewRules( {
	impact,
	siteChecks,
	windowSeconds,
	open,
	onToggle,
}: {
	impact: PotentialImpactData;
	siteChecks?: SiteChecks;
	windowSeconds: number;
	open: boolean;
	onToggle: ( open: boolean ) => void;
} ) {
	return (
		<section
			aria-labelledby="updatelens-rules-title"
			className="rounded-xl border bg-card"
		>
			<div className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:gap-4 sm:px-5">
				<span className="hidden size-10 shrink-0 place-items-center rounded-lg bg-tint text-primary sm:grid">
					<Icon name="info" />
				</span>
				<div className="min-w-0 flex-1">
					<h3
						id="updatelens-rules-title"
						className="text-sm font-semibold text-slate-900"
					>
						{ __( 'What UpdateLens checks', 'updatelens' ) }
					</h3>
					<p className="mt-0.5 max-w-3xl text-[13px] leading-5 text-slate-600">
						{ __(
							'UpdateLens reviews the Net result for selected changes to options, WP-Cron events and Action Scheduler actions, and compares homepage and REST API responses when website checks ran.',
							'updatelens'
						) }
					</p>
				</div>
				<Button
					type="button"
					variant="outline"
					aria-expanded={ open }
					aria-controls={ RULES_ID }
					onClick={ () => onToggle( ! open ) }
					className="shrink-0 self-start sm:self-center"
				>
					{ open
						? __( 'Hide review rules', 'updatelens' )
						: __( 'View review rules', 'updatelens' ) }
					<Icon
						name="chevron-down"
						className={ cn(
							'size-4 transition-transform',
							open && 'rotate-180'
						) }
					/>
				</Button>
			</div>
			<div id={ RULES_ID } hidden={ ! open }>
				{ open && (
					<WhatWasChecked
						impact={ impact }
						siteChecks={ siteChecks }
						windowSeconds={ windowSeconds }
					/>
				) }
			</div>
		</section>
	);
}

/**
 * Every signal's state, the patterns checked and what a result cannot show.
 *
 * @param props               Props.
 * @param props.impact        API evaluation.
 * @param props.siteChecks    The report's site checks (timing of compared checks).
 * @param props.windowSeconds Observation window length from the API.
 */
function WhatWasChecked( {
	impact,
	siteChecks,
	windowSeconds,
}: {
	impact: PotentialImpactData;
	siteChecks?: SiteChecks;
	windowSeconds: number;
} ) {
	// Site checks that were off or not recorded: one quiet row, not one per check.
	const notUsed = siteChecksNotUsed( impact );
	return (
		<div className="space-y-4 border-t px-4 py-4 text-[13px] leading-5 sm:px-5">
			<div>
				<h4 className="font-semibold text-slate-800">
					{ __( 'What was checked', 'updatelens' ) }
				</h4>
				<dl className="mt-1.5 grid gap-x-6 gap-y-2 sm:grid-cols-[auto_minmax(0,1fr)]">
					{ PROVIDERS.map( ( signal ) => (
						<CheckedRow
							key={ signal }
							icon={ signal }
							label={ providerLabel( signal ) }
							text={ impactSignalText(
								signal,
								impact,
								windowSeconds
							) }
						/>
					) ) }
					{ notUsed ? (
						<CheckedRow
							icon="site_checks"
							label={ __( 'Website checks', 'updatelens' ) }
							text={ {
								...notUsed,
								category: 'not_applicable',
								description: null,
							} }
						/>
					) : (
						SITE_CHECKS.map( ( check ) => {
							const evaluation =
								impact.site_checks.checks[ check ];
							return (
								evaluation && (
									<CheckedRow
										key={ check }
										icon="site_checks"
										label={ siteCheckName( check ) }
										text={ {
											...siteCheckCoverage(
												check,
												evaluation,
												siteChecks?.checks[ check ]
											),
											// The earlier and later checks are in
											// Website checks.
											detail: null,
											description: null,
										} }
									/>
								)
							);
						} )
					) }
				</dl>
			</div>
			<div>
				<h4 className="font-semibold text-slate-800">
					{ __( 'Patterns checked', 'updatelens' ) }
				</h4>
				<ul className="mt-1 list-disc space-y-0.5 pl-5 text-slate-600 marker:text-slate-400">
					{ [
						...checkedPatterns(),
						...( notUsed ? [] : siteCheckPatterns() ),
					].map( ( line ) => (
						<li key={ line }>{ line }</li>
					) ) }
				</ul>
			</div>
			<div>
				<h4 className="font-semibold text-slate-800">
					{ __( 'What this does not show', 'updatelens' ) }
				</h4>
				<ul className="mt-1 list-disc space-y-0.5 pl-5 text-slate-600 marker:text-slate-400">
					{ [
						...checkLimitations( ! notUsed ),
						...( notUsed ? [] : siteCheckLimitations() ),
					].map( ( line ) => (
						<li key={ line }>{ line }</li>
					) ) }
				</ul>
			</div>
		</div>
	);
}

/**
 * One row of "What was checked": a signal or site check and how its checks
 * ended. Incomplete checks are amber; checked and not applicable ones quiet.
 *
 * @param props       Props.
 * @param props.icon  Icon.
 * @param props.label Signal or check name.
 * @param props.text  Category, status, detail and description.
 */
function CheckedRow( {
	icon,
	label,
	text,
}: {
	icon: IconName;
	label: string;
	text: {
		category: CheckCategory;
		status: string;
		detail: string | null;
		description: string | null;
	};
} ) {
	const incomplete =
		text.category !== 'checked' && text.category !== 'not_applicable';
	return (
		<div className="contents">
			<dt className="flex items-center gap-2 font-medium text-slate-800">
				<Icon name={ icon } className="size-4 text-slate-500" />
				{ label }
			</dt>
			<dd
				data-category={ text.category }
				className={ cn(
					'pl-6 sm:pl-0',
					incomplete ? 'text-amber-900' : 'text-slate-600'
				) }
			>
				<span
					className={ cn(
						'font-medium',
						text.category === 'checked' && 'text-slate-800'
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
	const { names, more } = groupSubjects( group );

	return (
		<CompactRow
			icon={ group.signal }
			priority={ group.findings[ 0 ].priority }
			title={ findingGroupTitle( group ) }
			count={ group.findings.length }
			source={
				<>
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
								/* translators: %s: number of further items (option names, hooks or instances). */
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
				</>
			}
			summary={ groupSummary( group ) }
			expanded={ visible !== null }
			onToggle={ () =>
				onVisibleChange(
					visible !== null ? null : DEFAULT_VISIBLE_FINDINGS
				)
			}
			details={ () =>
				visible !== null && (
					<GroupDetails
						group={ group }
						visible={ visible }
						onVisibleChange={ onVisibleChange }
						indexes={ indexes }
						findingHref={ findingHref }
						onOpenFinding={ onOpenFinding }
					/>
				)
			}
		/>
	);
}

/**
 * A site check finding as a compact row, like a group of findings: title,
 * source ("Site checks · Homepage") and one sentence. Expanded, it says what
 * changed, why it might matter and what to check next; the earlier and later
 * requests are in "Site response checks", one link away (no signal page).
 *
 * @param props                 Props.
 * @param props.finding         Finding.
 * @param props.expanded        Whether it is expanded.
 * @param props.onToggle        Expands or collapses it.
 * @param props.onShowSiteCheck Opens the check in Website checks.
 */
function SiteCheckFindingItem( {
	finding,
	expanded,
	onToggle,
	onShowSiteCheck,
}: {
	finding: SiteCheckFinding;
	expanded: boolean;
	onToggle: () => void;
	onShowSiteCheck: ( check: SiteCheckId ) => void;
} ) {
	return (
		<CompactRow
			icon="site_checks"
			priority={ finding.priority }
			title={ siteCheckFindingTitle( finding ) }
			count={ 1 }
			buttonId={ siteCheckFindingButtonId( finding.check ) }
			source={
				<>
					<span>{ __( 'Website checks', 'updatelens' ) }</span>{ ' ' }
					<span aria-hidden="true">·</span>{ ' ' }
					<span>{ siteCheckSubject( finding.check ) }</span>
				</>
			}
			summary={ siteCheckSummary( finding ) }
			expanded={ expanded }
			onToggle={ onToggle }
			details={ () => (
				<div className="border-t">
					<div className="px-4 pt-3 sm:px-5">
						<DetailHeading>
							{ __( 'What changed', 'updatelens' ) }
						</DetailHeading>
						<p className="mt-1 text-sm leading-6 text-slate-800">
							{ siteCheckWhatChanged( finding ) }
						</p>
					</div>
					<Guidance
						why={ siteCheckWhyItMatters( finding ) }
						next={ siteCheckWhatToCheck( finding ) }
					/>
					<div className="border-t px-4 py-3 sm:px-5">
						<DetailHeading>
							{ __( 'Evidence', 'updatelens' ) }
						</DetailHeading>
						<p className="mt-1 text-[13px] leading-5 text-slate-700">
							{ __(
								'The requests before and after the update, with their responses and verification, are in Website checks.',
								'updatelens'
							) }{ ' ' }
							<button
								type="button"
								onClick={ () =>
									onShowSiteCheck( finding.check )
								}
								className="rounded font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
							>
								{ finding.check === 'home'
									? __(
											'Show the homepage requests',
											'updatelens'
										)
									: __(
											'Show the REST API requests',
											'updatelens'
										) }
							</button>
						</p>
					</div>
				</div>
			) }
		/>
	);
}

/**
 * A finding row, collapsed by default: a signal icon, the title with its
 * count, the source line, one summary sentence and a button over the whole
 * row. `details` is rendered only while expanded (never hidden DOM).
 *
 * @param props          Props.
 * @param props.icon     Icon of the source.
 * @param props.priority Priority from the API (in the accessible name; the
 *                       list's heading shows it).
 * @param props.title    Title.
 * @param props.count    Number of findings.
 * @param props.source   Source line (signal and subjects).
 * @param props.summary  One short sentence, the button's description.
 * @param props.expanded Whether it is expanded.
 * @param props.onToggle Expands or collapses it.
 * @param props.details  Renders the expanded content.
 * @param props.buttonId Id of the toggle button (a link target), if any.
 */
function CompactRow( {
	icon,
	priority,
	title,
	count,
	buttonId,
	source,
	summary,
	expanded,
	onToggle,
	details,
}: {
	icon: IconName;
	priority: FindingPriority;
	title: string;
	count: number;
	buttonId?: string;
	source: ReactNode;
	summary: string;
	expanded: boolean;
	onToggle: () => void;
	details: () => ReactNode;
} ) {
	const id = useId();
	const titleId = `${ id }-title`;
	const summaryId = `${ id }-summary`;
	const panelId = `${ id }-panel`;

	return (
		<li
			aria-labelledby={ titleId }
			data-priority={ priority }
			className={ cn(
				'overflow-hidden rounded-lg border border-l-4 bg-card transition-colors',
				priority === 'high'
					? 'border-l-rose-500'
					: 'border-l-amber-400',
				expanded
					? 'border-y-slate-300 border-r-slate-300'
					: 'hover:border-y-slate-300 hover:border-r-slate-300'
			) }
		>
			<div
				className={ cn(
					'relative flex items-start gap-3 px-4 py-3 sm:px-5',
					! expanded && 'hover:bg-slate-50/60'
				) }
			>
				{ /* The signal is named in the row; on phones its icon would only narrow the text. */ }
				<span className="hidden size-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-600 sm:grid">
					<Icon name={ icon } className="size-[1.125rem]" />
				</span>
				<div className="min-w-0 flex-1">
					<h4
						id={ titleId }
						className="text-[15px] font-semibold leading-6 text-slate-900"
					>
						<button
							type="button"
							id={ buttonId }
							aria-expanded={ expanded }
							aria-controls={ panelId }
							aria-describedby={ summaryId }
							onClick={ onToggle }
							className="text-left after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:rounded-[inherit] focus-visible:after:ring-2 focus-visible:after:ring-inset focus-visible:after:ring-ring"
						>
							{ title }
							{ /* One finding needs no count. */ }
							{ count > 1 && (
								<span
									aria-hidden="true"
									className="ml-2 inline-flex min-w-6 justify-center rounded-full bg-slate-100 px-2 align-[1px] text-xs font-semibold tabular-nums leading-5 text-slate-700"
								>
									{ formatCount( count ) }
								</span>
							) }
							<span className="sr-only">
								{ ', ' }
								{ sprintf(
									/* translators: %s: number of findings. */
									_n(
										'%s finding',
										'%s findings',
										count,
										'updatelens'
									),
									formatCount( count )
								) }
								{ ', ' }
								{ priorityLabel( priority ) }
							</span>
						</button>
					</h4>
					<p className="mt-0.5 flex min-w-0 flex-wrap items-baseline gap-x-1.5 text-[13px] leading-5 text-muted-foreground">
						{ source }
					</p>
					<p
						id={ summaryId }
						className="mt-1 text-sm leading-6 text-slate-700"
					>
						{ summary }
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
				{ expanded && details() }
			</div>
		</li>
	);
}

/**
 * "Why it might matter" and "What to check next", side by side from tablets on.
 *
 * @param props      Props.
 * @param props.why  Sentences.
 * @param props.next Steps.
 */
function Guidance( { why, next }: { why: string[]; next: string[] } ) {
	return (
		<div className="grid gap-x-8 gap-y-3 px-4 py-3 sm:px-5 md:grid-cols-2">
			<div>
				<DetailHeading>
					{ __( 'Why it might matter', 'updatelens' ) }
				</DetailHeading>
				<div className="mt-1.5 space-y-1.5 text-sm leading-relaxed text-slate-700">
					{ why.map( ( sentence ) => (
						<p key={ sentence }>{ sentence }</p>
					) ) }
				</div>
			</div>
			<div>
				<DetailHeading>
					{ __( 'What to check next', 'updatelens' ) }
				</DetailHeading>
				<ul className="mt-1.5 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-slate-700 marker:text-slate-400">
					{ next.map( ( step ) => (
						<li key={ step }>{ step }</li>
					) ) }
				</ul>
			</div>
		</div>
	);
}

/**
 * A heading inside an expanded finding.
 *
 * @param props          Props.
 * @param props.children Text.
 */
function DetailHeading( { children }: { children: ReactNode } ) {
	return (
		<h5 className="text-xs font-semibold uppercase tracking-[0.06em] text-slate-500">
			{ children }
		</h5>
	);
}

/**
 * An expanded group: what changed, why it might matter, what to check next,
 * and the evidence (a few findings at a time).
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
	const facts = groupFacts( group );
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
				<DetailHeading>
					{ __( 'What changed', 'updatelens' ) }
				</DetailHeading>
				{ total === 1 && (
					<p className="mt-1 text-sm leading-6 text-slate-800">
						{ whatChanged( group.findings[ 0 ] ) }
					</p>
				) }
				{ facts.rows.length > 0 && (
					<RowDetails boxed rows={ facts.rows } />
				) }
				{ facts.notes.length > 0 && (
					<p className="mt-1.5 text-[13px] leading-5 text-slate-600">
						{ facts.notes.join( ' ' ) }
					</p>
				) }
			</div>
			<Guidance
				why={ whyItMatters( group ) }
				next={ whatToCheck( group ) }
			/>
			<div className="border-t px-4 pt-3 sm:px-5">
				<DetailHeading>
					{ __( 'Evidence', 'updatelens' ) }
				</DetailHeading>
			</div>
			<ul id={ listId } className="mt-2 divide-y border-t bg-slate-50/50">
				{ visible.map( ( finding ) => {
					const index = indexes.get( finding ) ?? -1;
					return (
						<FindingRow
							key={ index }
							index={ index }
							finding={ finding }
							single={ total === 1 }
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
 * @param props.single  The group's only finding (its sentence is above).
 * @param props.href    Link target of the signal's details.
 * @param props.onOpen  Opens the details in the app.
 */
function FindingRow( {
	index,
	finding,
	single,
	href,
	onOpen,
}: {
	index: number;
	finding: ImpactFinding;
	single: boolean;
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
				{ ! single && (
					<p className="text-sm leading-6 text-slate-800">
						{ whatChanged( finding ) }
					</p>
				) }
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
					keepTogether( formatBytes( after.size ) ),
				] );
				rows.push( [
					__( 'Autoload', 'updatelens' ),
					autoloadText( after.autoload, after.is_autoloaded ),
				] );
			} else {
				const [ from, to ] = formatBytesPair(
					before.size,
					after.size
				).map( keepTogether );
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
									(
									{ keepTogether(
										formatBytesDelta( evidence.size_delta )
									) }
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
						/* translators: %s: number of further items (option names, hooks or instances). */
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
