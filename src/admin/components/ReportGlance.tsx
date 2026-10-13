import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

import type { AnalysisReport, PhaseKey, SiteCheckId } from '../types/api';
import type { PhaseChangeCounts } from '../utils/changes';
import {
	configurationGlance,
	coverageLines,
	siteChecksGlance,
	type GlanceItem,
} from '../utils/glance';
import type { Tone } from '../utils/labels';
import { siteCheckSubject } from '../utils/siteCheckImpact';
import { siteCheckNextStep } from '../utils/siteChecks';
import { TONE_ICON } from '../utils/tone';
import { Icon, type IconName } from './Icon';

/*
 * A compact summary right below the report header: configuration changes,
 * site response checks and observation coverage, side by side and kept
 * apart. Each is a fact with its own tone; there is no overall verdict on
 * the update. Newly failing and pending site checks show here before the
 * longer sections, a finding with its next step and a link to it, and
 * coverage names what was observed, sources and site responses separately.
 */

/**
 * The summary.
 *
 * @param props               Props.
 * @param props.report        Report.
 * @param props.counts        Change counts per phase.
 * @param props.onShowFinding Opens a site check's finding in Potential Impact.
 */
export function ReportGlance( {
	report,
	counts,
	onShowFinding,
}: {
	report: AnalysisReport;
	counts: Record< PhaseKey, PhaseChangeCounts >;
	onShowFinding: ( check: SiteCheckId ) => void;
} ) {
	const site = siteChecksGlance( report );
	const evaluation = report.potential_impact.site_checks;

	return (
		<section
			aria-label={ __( 'Report summary', 'updatelens' ) }
			className="rounded-xl border bg-card shadow-surface"
		>
			<dl className="grid divide-y md:grid-cols-3 md:divide-x md:divide-y-0">
				<Item label={ __( 'Configuration changes', 'updatelens' ) }>
					<Fact item={ configurationGlance( report, counts ) } />
				</Item>
				<Item label={ __( 'Site response checks', 'updatelens' ) }>
					<Fact item={ site } />
					{ site.outcome.findings.length > 0 && (
						<div className="ml-6 mt-2 space-y-1.5 text-[13px] leading-5">
							<ul className="space-y-1.5">
								{ site.outcome.findings.map( ( check ) => (
									<li
										key={ check }
										className="text-slate-800"
									>
										<span className="font-medium">
											{ siteCheckSubject( check ) }
											{ ': ' }
										</span>
										{ siteCheckNextStep(
											check,
											evaluation?.checks[ check ]
										) }{ ' ' }
										<button
											type="button"
											onClick={ () =>
												onShowFinding( check )
											}
											className="inline-flex min-h-6 items-center rounded font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
										>
											{ check === 'home'
												? __(
														'Show the homepage finding',
														'updatelens'
													)
												: __(
														'Show the REST API finding',
														'updatelens'
													) }
										</button>
									</li>
								) ) }
							</ul>
							<p className="text-slate-600">
								{ __(
									'An observed result, not proof that the updated plugin caused it.',
									'updatelens'
								) }
							</p>
						</div>
					) }
				</Item>
				<Item label={ __( 'Observation coverage', 'updatelens' ) }>
					<ul className="space-y-1.5">
						{ coverageLines( report, counts ).map( ( line ) => (
							<li key={ line.label } className="flex gap-2">
								<ToneIcon
									icon={ line.icon }
									tone={ line.tone }
								/>
								<span className="min-w-0 text-[13px] leading-5 text-slate-700">
									<span className="font-semibold text-slate-900">
										{ line.label }
										{ ': ' }
									</span>
									{ line.text }
								</span>
							</li>
						) ) }
					</ul>
				</Item>
			</dl>
		</section>
	);
}

/**
 * One labelled part of the summary.
 *
 * @param props          Props.
 * @param props.label    Label.
 * @param props.children Content.
 */
function Item( { label, children }: { label: string; children: ReactNode } ) {
	return (
		<div className="min-w-0 px-4 py-3 sm:px-5">
			<dt className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-600">
				{ label }
			</dt>
			<dd className="mt-1.5">{ children }</dd>
		</div>
	);
}

/**
 * An icon in its tone's color (decorative).
 *
 * @param props      Props.
 * @param props.icon Icon.
 * @param props.tone Tone.
 */
function ToneIcon( { icon, tone }: { icon: IconName; tone: Tone } ) {
	return (
		<Icon
			name={ icon }
			className={ cn( 'mt-0.5 size-4 shrink-0', TONE_ICON[ tone ] ) }
		/>
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
			<ToneIcon icon={ item.icon } tone={ item.tone } />
			<span className="min-w-0">
				<span
					className={ cn(
						'block text-[15px] font-semibold leading-5',
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
