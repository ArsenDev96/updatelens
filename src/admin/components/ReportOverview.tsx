import { __ } from '@wordpress/i18n';
import { Fragment } from 'react';

import { cn } from '@/lib/utils';

import type {
	AnalysisReport,
	PhaseKey,
	Provider,
	ReportPhase,
} from '../types/api';
import type { PhaseChangeCounts } from '../utils/changes';
import { formatCount } from '../utils/format';
import { coverageLine } from '../utils/glance';
import {
	actionSchedulerNotDetectedText,
	observedChangesText,
	PROVIDERS,
	providerLabel,
	signalStatusText,
	signalUnavailableText,
} from '../utils/labels';
import { TONE_ICON } from '../utils/tone';
import { AppLink } from './AppLink';
import { Icon } from './Icon';
import { PhaseTabs } from './PhaseTabs';

/*
 * The configuration changes of a report on its overview: secondary to the
 * summary, findings and site checks above it. The coverage of the
 * configuration sources and that observations are not causes in one quiet
 * line, the three phases as tabs, and per phase one compact row per signal
 * (its state and counts) that opens the signal's page. No change rows here:
 * they belong to the signal pages.
 */

/**
 * The section.
 *
 * @param props               Props.
 * @param props.report        Report.
 * @param props.counts        Change counts per phase.
 * @param props.selected      Selected phase, null if no phase is available.
 * @param props.onSelectPhase Selects a phase.
 * @param props.signalHref    Link target of a signal's details.
 * @param props.onOpenSignal  Opens a signal's details in the app.
 */
export function ConfigurationChanges( {
	report,
	counts,
	selected,
	onSelectPhase,
	signalHref,
	onOpenSignal,
}: {
	report: AnalysisReport;
	counts: Record< PhaseKey, PhaseChangeCounts >;
	selected: PhaseKey | null;
	onSelectPhase: ( phase: PhaseKey ) => void;
	signalHref: ( signal: Provider ) => string;
	onOpenSignal: ( signal: Provider ) => void;
} ) {
	const coverage = coverageLine( report, counts );

	return (
		<section
			aria-labelledby="updatelens-configuration-title"
			className="space-y-3"
		>
			<div>
				<h3
					id="updatelens-configuration-title"
					// The report summary's "Inspect changes" moves focus here.
					tabIndex={ -1 }
					className="text-base font-semibold text-slate-900 focus:outline-none"
				>
					{ __( 'Configuration changes', 'updatelens' ) }
				</h3>
				<p className="mt-1 flex gap-2 text-[13px] leading-5 text-slate-600">
					<Icon
						name={ coverage.icon }
						className={ cn(
							'mt-0.5 size-4 shrink-0',
							TONE_ICON[ coverage.tone ]
						) }
					/>
					<span>{ coverage.text }</span>
				</p>
				<p className="ml-6 text-[13px] leading-5 text-slate-600">
					{ __(
						'Observed changes are not proof that the updated plugin made them.',
						'updatelens'
					) }
				</p>
			</div>
			{ selected && (
				<PhaseTabs
					counts={ {
						during_update: counts.during_update.total,
						post_update: counts.post_update.total,
						final: counts.final.total,
					} }
					selected={ selected }
					onSelect={ onSelectPhase }
				>
					<ReportOverview
						data={ report.phases[ selected ] }
						counts={ counts[ selected ] }
						windowSeconds={ report.observation_window_seconds }
						actionSchedulerAbsent={
							report.potential_impact.signals.action_scheduler
								.status === 'not_applicable'
						}
						signalHref={ signalHref }
						onOpenSignal={ onOpenSignal }
					/>
				</PhaseTabs>
			) }
		</section>
	);
}

/**
 * One phase: a row per signal, always in the order Options & autoload,
 * WP-Cron, Action Scheduler. Without any available signal, the phase's
 * reason (from Options, the signal that drives the analysis) instead.
 *
 * @param props                       Props.
 * @param props.data                  Phase data of every signal.
 * @param props.counts                Change counts of the phase.
 * @param props.windowSeconds         Observation window length from the API.
 * @param props.actionSchedulerAbsent Whether Action Scheduler's absence is
 *                                    confirmed for the whole update.
 * @param props.signalHref            Link target of a signal's details.
 * @param props.onOpenSignal          Opens a signal's details in the app.
 */
export function ReportOverview( {
	data,
	counts,
	windowSeconds,
	actionSchedulerAbsent,
	signalHref,
	onOpenSignal,
}: {
	data: ReportPhase;
	counts: PhaseChangeCounts;
	windowSeconds: number;
	actionSchedulerAbsent: boolean;
	signalHref: ( signal: Provider ) => string;
	onOpenSignal: ( signal: Provider ) => void;
} ) {
	const unavailable =
		counts.total === null
			? signalUnavailableText( 'options', data, windowSeconds )
					?.description
			: null;

	return (
		<div className="space-y-2">
			{ /* The phase's total (compact without changes), and why without any signal. */ }
			<p className="text-sm leading-6 text-slate-700">
				<span className="font-semibold text-slate-900">
					{ observedChangesText( counts.total ) }
				</span>
				{ unavailable && (
					<span className="block text-slate-700">
						{ unavailable }
					</span>
				) }
			</p>
			<ul
				aria-label={ __( 'Signals', 'updatelens' ) }
				className="divide-y overflow-hidden rounded-lg border bg-card shadow-surface"
			>
				{ PROVIDERS.map( ( provider ) => (
					<SignalRow
						key={ provider }
						provider={ provider }
						data={ data }
						count={ counts[ provider ] }
						windowSeconds={ windowSeconds }
						actionSchedulerAbsent={ actionSchedulerAbsent }
						href={ signalHref( provider ) }
						onOpen={ () => onOpenSignal( provider ) }
					/>
				) ) }
			</ul>
		</div>
	);
}

/**
 * One signal of the phase as a compact row: its name (the link, stretched
 * over the row and described by the state), the number of changes with the
 * non-zero counts, or why it is not available. Rows without changes are
 * quieter.
 *
 * @param props                       Props.
 * @param props.provider              Signal.
 * @param props.data                  Phase data of every signal.
 * @param props.count                 Change count of the signal, null if unavailable.
 * @param props.windowSeconds         Observation window length from the API.
 * @param props.actionSchedulerAbsent Whether Action Scheduler's absence is
 *                                    confirmed for the whole update.
 * @param props.href                  Link target of the signal's details.
 * @param props.onOpen                Opens the details in the app.
 */
function SignalRow( {
	provider,
	data,
	count,
	windowSeconds,
	actionSchedulerAbsent,
	href,
	onOpen,
}: {
	provider: Provider;
	data: ReportPhase;
	count: number | null;
	windowSeconds: number;
	actionSchedulerAbsent: boolean;
	href: string;
	onOpen: () => void;
} ) {
	const statusId = `updatelens-card-${ provider }-status`;
	const hasChanges = ( count ?? 0 ) > 0;
	const breakdown = hasChanges ? signalBreakdown( provider, data ) : [];
	// Not detected is worded by its evidence, like the observation coverage.
	const notDetected =
		provider === 'action_scheduler' &&
		! data.action_scheduler.available &&
		data.action_scheduler.reason === 'not_installed';
	let unavailable: string | null | undefined = null;
	if ( count === null ) {
		unavailable = notDetected
			? actionSchedulerNotDetectedText( actionSchedulerAbsent )
			: signalUnavailableText( provider, data, windowSeconds )?.title;
	}

	return (
		<li className="group relative grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3 px-3 py-2.5 hover:bg-slate-50/70 sm:px-4">
			<span
				className={ cn(
					'grid size-8 place-items-center rounded-md',
					hasChanges
						? 'bg-tint-strong text-primary'
						: 'bg-slate-100 text-slate-500'
				) }
			>
				<Icon name={ provider } className="size-4" />
			</span>
			<div className="min-w-0 sm:flex sm:items-baseline sm:gap-4">
				<h4 className="text-sm font-semibold leading-6 sm:w-44 sm:shrink-0">
					<AppLink
						href={ href }
						onNavigate={ onOpen }
						aria-describedby={ statusId }
						className="text-foreground no-underline outline-none after:absolute after:inset-0 focus:shadow-none focus-visible:after:ring-2 focus-visible:after:ring-inset focus-visible:after:ring-ring"
					>
						{ providerLabel( provider ) }
					</AppLink>
				</h4>
				<p
					id={ statusId }
					className="min-w-0 text-[13px] leading-5 text-slate-600"
				>
					<span
						className={ cn(
							'tabular-nums',
							hasChanges
								? 'font-semibold text-slate-900'
								: 'text-slate-700'
						) }
					>
						{ signalStatusText( count ) }
					</span>
					{ breakdown.length > 0 && (
						<>
							{ ' · ' }
							{ breakdown.map( ( [ label, value ], index ) => (
								<Fragment key={ label }>
									{ index > 0 && ' · ' }
									{ label }{ ' ' }
									<span className="tabular-nums text-slate-800">
										{ formatCount( value ) }
									</span>
								</Fragment>
							) ) }
						</>
					) }
					{ unavailable && (
						<>
							{ ' · ' }
							{ unavailable }
						</>
					) }
				</p>
			</div>
			<Icon
				name="arrow-right"
				className="size-4 text-slate-400 transition-transform duration-150 group-hover:translate-x-0.5 group-hover:text-primary"
			/>
		</li>
	);
}

/**
 * Non-zero change counts of an available signal (Added 3, Changed 2), from
 * its summary only.
 *
 * @param provider Signal.
 * @param data     Phase data of every signal.
 */
function signalBreakdown(
	provider: Provider,
	data: ReportPhase
): Array< [ string, number ] > {
	const items: Array< [ string, number ] > = [];
	if ( provider === 'options' && data.options.available ) {
		const summary = data.options.summary;
		items.push(
			[ __( 'Added', 'updatelens' ), summary.added_count ],
			[ __( 'Removed', 'updatelens' ), summary.removed_count ],
			[ __( 'Changed', 'updatelens' ), summary.changed_count ]
		);
	}
	if ( provider === 'cron' && data.cron.available ) {
		const summary = data.cron.summary;
		items.push(
			[ __( 'Added', 'updatelens' ), summary.added_count ],
			[
				/* translators: Summary count of WP-Cron events present before and absent after (removed or no longer scheduled). */
				__( 'No longer present', 'updatelens' ),
				summary.removed_count,
			],
			[ __( 'Changed', 'updatelens' ), summary.changed_count ],
			[ __( 'Rescheduled', 'updatelens' ), summary.rescheduled_count ]
		);
	}
	if ( provider === 'action_scheduler' && data.action_scheduler.available ) {
		const summary = data.action_scheduler.summary;
		items.push(
			[ __( 'Added', 'updatelens' ), summary.added_count ],
			[
				/* translators: Summary count of Action Scheduler actions active before and not active after (ran, canceled or otherwise left the queue). */
				__( 'No longer active', 'updatelens' ),
				summary.removed_count,
			],
			[ __( 'Changed', 'updatelens' ), summary.changed_count ],
			[ __( 'Rescheduled', 'updatelens' ), summary.rescheduled_count ]
		);
	}
	return items.filter( ( [ , value ] ) => value > 0 );
}
