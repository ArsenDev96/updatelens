import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

import type { AnalysisReport, PhaseKey } from '../types/api';
import type { PhaseChangeCounts } from '../utils/changes';
import {
	configurationGlance,
	coverageLine,
	impactGlance,
	siteChecksGlance,
	type GlanceItem,
} from '../utils/glance';
import type { Tone } from '../utils/labels';
import { TONE_ICON } from '../utils/tone';
import { Icon, type IconName } from './Icon';

/*
 * A compact summary right below the report header: configuration changes,
 * site response checks and Potential Impact side by side and kept apart,
 * with the observation coverage of the configuration sources below them.
 * Each is a fact with its own tone; there is no overall verdict on the
 * update. Next steps and explanations are in the sections below: the
 * summary says what was observed and where to look.
 */

/**
 * The summary.
 *
 * @param props              Props.
 * @param props.report       Report.
 * @param props.counts       Change counts per phase.
 * @param props.onShowImpact Moves to the Potential Impact section.
 */
export function ReportGlance( {
	report,
	counts,
	onShowImpact,
}: {
	report: AnalysisReport;
	counts: Record< PhaseKey, PhaseChangeCounts >;
	onShowImpact: () => void;
} ) {
	const impact = impactGlance( report );
	const coverage = coverageLine( report, counts );

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
					<Fact item={ siteChecksGlance( report ) } />
				</Item>
				<Item label={ __( 'Potential impact', 'updatelens' ) }>
					<Fact item={ impact } />
					{ impact.state === 'findings' && (
						<button
							type="button"
							onClick={ onShowImpact }
							className="ml-6 mt-1.5 inline-flex min-h-6 items-center rounded text-[13px] font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
						>
							{ __( 'Go to findings', 'updatelens' ) }
						</button>
					) }
				</Item>
			</dl>
			<p className="flex gap-2 rounded-b-xl border-t bg-slate-50 px-4 py-2.5 text-[13px] leading-5 text-slate-700 sm:px-5">
				<ToneIcon icon={ coverage.icon } tone={ coverage.tone } />
				<span className="min-w-0">
					<span className="font-semibold text-slate-900">
						{ coverage.label }
						{ ': ' }
					</span>
					{ coverage.text }
				</span>
			</p>
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
