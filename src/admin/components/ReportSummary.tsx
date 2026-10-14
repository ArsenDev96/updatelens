import { _n, __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

import type { AnalysisReport, PhaseKey } from '../types/api';
import type { PhaseChangeCounts } from '../utils/changes';
import { formatCount } from '../utils/format';
import {
	configurationGlance,
	notAssessedLines,
	siteChecksGlance,
	type GlanceItem,
} from '../utils/glance';
import { impactHeadline, impactState } from '../utils/impact';
import { TONE_ICON } from '../utils/tone';
import { Icon } from './Icon';

/*
 * The report summary right below the header: the decision in four short
 * rows, each with where to look next. Findings by the priority the API gave
 * them (or, without any, how complete the observations were), the
 * configuration changes, the site checks, and what could not be assessed,
 * kept apart from the priorities. Never one verdict on the update: no
 * findings is not "safe", and changes no rule matched stay "informational".
 */

/**
 * The summary.
 *
 * @param props                     Props.
 * @param props.report              Report.
 * @param props.counts              Change counts per phase.
 * @param props.onShowFindings      Moves to the Findings section.
 * @param props.onShowConfiguration Moves to the Configuration changes section.
 * @param props.onShowSiteChecks    Moves to the Site checks section.
 */
export function ReportSummary( {
	report,
	counts,
	onShowFindings,
	onShowConfiguration,
	onShowSiteChecks,
}: {
	report: AnalysisReport;
	counts: Record< PhaseKey, PhaseChangeCounts >;
	onShowFindings: () => void;
	onShowConfiguration: () => void;
	onShowSiteChecks: () => void;
} ) {
	const impact = report.potential_impact;
	const { assessment } = impact;
	const state = impactState( impact );
	const findings = assessment.high_priority + assessment.needs_review;
	const notAssessed = notAssessedLines( report );
	const informational = informationalText( report );
	// Only when a phase has changes to inspect.
	const hasChanges = ( Object.values( counts ) as PhaseChangeCounts[] ).some(
		( phase ) => ( phase.total ?? 0 ) > 0
	);
	const siteChecks = siteChecksGlance( report );

	return (
		<section
			aria-label={ __( 'Report summary', 'updatelens' ) }
			className="rounded-xl border bg-card shadow-surface"
		>
			<dl className="divide-y">
				<Row
					label={ __( 'Findings', 'updatelens' ) }
					action={
						findings > 0
							? {
									label: __( 'Go to findings', 'updatelens' ),
									onClick: onShowFindings,
								}
							: null
					}
				>
					{ findings > 0 ? (
						<span className="flex flex-wrap items-center gap-1.5">
							{ assessment.high_priority > 0 && (
								<Count tone="high">
									{ sprintf(
										/* translators: %s: number of high priority findings. */
										_n(
											'%s high priority',
											'%s high priority',
											assessment.high_priority,
											'updatelens'
										),
										formatCount( assessment.high_priority )
									) }
								</Count>
							) }
							{ assessment.needs_review > 0 && (
								<Count tone="review">
									{ sprintf(
										/* translators: %s: number of findings that need review. */
										_n(
											'%s needs review',
											'%s need review',
											assessment.needs_review,
											'updatelens'
										),
										formatCount( assessment.needs_review )
									) }
								</Count>
							) }
						</span>
					) : (
						<span className="font-medium text-slate-900">
							{ impactHeadline( impact, state ) }
						</span>
					) }
					{ informational && (
						<span className="mt-0.5 block text-[13px] leading-5 text-slate-600">
							{ informational }
						</span>
					) }
				</Row>
				<Row
					label={ __( 'Configuration', 'updatelens' ) }
					action={
						hasChanges
							? {
									label: __(
										'Inspect changes',
										'updatelens'
									),
									onClick: onShowConfiguration,
								}
							: null
					}
				>
					<Fact item={ configurationGlance( report, counts ) } />
				</Row>
				<Row
					label={ __( 'Site checks', 'updatelens' ) }
					action={
						siteChecks.outcome.kind === 'disabled' ||
						siteChecks.outcome.kind === 'not_recorded' ||
						siteChecks.outcome.kind === 'unsupported'
							? null
							: {
									label: __(
										'Site check details',
										'updatelens'
									),
									onClick: onShowSiteChecks,
								}
					}
				>
					<span data-outcome={ siteChecks.outcome.kind }>
						<Fact item={ siteChecks } />
					</span>
				</Row>
				{ notAssessed.length > 0 && (
					<Row
						label={ __( 'Not assessed', 'updatelens' ) }
						action={ null }
					>
						<span className="flex gap-2 text-[13px] leading-5 text-slate-700">
							<Icon
								name="info"
								className="mt-0.5 size-4 shrink-0 text-slate-500"
							/>
							{ notAssessed.length === 1 ? (
								<span>{ notAssessed[ 0 ] }</span>
							) : (
								<ul className="space-y-0.5">
									{ notAssessed.map( ( line ) => (
										<li key={ line }>{ line }</li>
									) ) }
								</ul>
							) }
						</span>
					</Row>
				) }
			</dl>
		</section>
	);
}

/**
 * How many Net result configuration changes matched no review rule, out of
 * those assessed, from the API's exact per-record counts: "2 of 5
 * configuration changes in the Net result matched no review rule
 * (informational)." When some sources were not assessed, it says the count
 * covers the assessed ones only. Null without informational changes.
 *
 * @param report Report.
 */
function informationalText( report: AnalysisReport ): string | null {
	const impact = report.potential_impact;
	const { informational, assessed_changes: assessed } = impact.assessment;
	if ( ! informational || ! assessed ) {
		return null;
	}
	return impact.status === 'evaluated'
		? sprintf(
				/* translators: 1: changes no review rule matched. 2: all configuration changes in the Net result. */
				_n(
					'%1$s of %2$s configuration change in the Net result matched no review rule (informational).',
					'%1$s of %2$s configuration changes in the Net result matched no review rule (informational).',
					assessed,
					'updatelens'
				),
				formatCount( informational ),
				formatCount( assessed )
			)
		: sprintf(
				/* translators: 1: changes no review rule matched. 2: configuration changes in the sources that were assessed. */
				_n(
					'%1$s of %2$s configuration change in the assessed sources of the Net result matched no review rule (informational).',
					'%1$s of %2$s configuration changes in the assessed sources of the Net result matched no review rule (informational).',
					assessed,
					'updatelens'
				),
				formatCount( informational ),
				formatCount( assessed )
			);
}

/**
 * One row: its label, the answer, and where to look.
 *
 * @param props          Props.
 * @param props.label    Label.
 * @param props.action   Button that moves to the evidence, or null.
 * @param props.children Answer.
 */
function Row( {
	label,
	action,
	children,
}: {
	label: string;
	action: { label: string; onClick: () => void } | null;
	children: ReactNode;
} ) {
	return (
		<div className="grid gap-x-4 gap-y-1 px-4 py-2.5 sm:grid-cols-[7.5rem_minmax(0,1fr)_auto] sm:items-start sm:px-5">
			<dt className="pt-px text-[13px] font-medium leading-5 text-muted-foreground">
				{ label }
			</dt>
			<dd className="min-w-0 text-sm leading-5">{ children }</dd>
			{ action && (
				<dd className="sm:text-right">
					<button
						type="button"
						onClick={ action.onClick }
						className="inline-flex min-h-6 items-center gap-1 whitespace-nowrap rounded text-[13px] font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
					>
						{ action.label }
						<Icon name="arrow-right" className="size-3.5" />
					</button>
				</dd>
			) }
		</div>
	);
}

/**
 * A count of findings of one priority.
 *
 * @param props          Props.
 * @param props.tone     Priority.
 * @param props.children Text.
 */
function Count( {
	tone,
	children,
}: {
	tone: 'high' | 'review';
	children: ReactNode;
} ) {
	return (
		<span
			className={ cn(
				'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[13px] font-semibold ring-1 ring-inset',
				tone === 'high'
					? 'bg-rose-50 text-rose-800 ring-rose-200'
					: 'bg-amber-50 text-amber-900 ring-amber-200'
			) }
		>
			<Icon
				name={ tone === 'high' ? 'failure' : 'caution' }
				className="size-3.5"
			/>
			{ children }
		</span>
	);
}

/**
 * A value with its tone and one detail line.
 *
 * @param props      Props.
 * @param props.item Fact.
 */
function Fact( { item }: { item: GlanceItem } ) {
	return (
		<span className="flex gap-2">
			<Icon
				name={ item.icon }
				className={ cn(
					'mt-px size-4 shrink-0',
					TONE_ICON[ item.tone ]
				) }
			/>
			<span className="min-w-0">
				<span
					className={ cn(
						'font-medium',
						item.tone === 'negative'
							? 'text-rose-800'
							: 'text-slate-900'
					) }
				>
					{ item.value }
				</span>
				{ item.detail && (
					<span className="mt-0.5 block text-[13px] leading-5 text-slate-600">
						{ item.detail }
					</span>
				) }
			</span>
		</span>
	);
}
