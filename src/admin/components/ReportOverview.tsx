import { _n, __, sprintf } from '@wordpress/i18n';
import { Fragment, useId, useMemo } from 'react';

import { cn } from '@/lib/utils';

import type {
	AnalysisReport,
	PhaseKey,
	Provider,
	ReportPhase,
} from '../types/api';
import type { PhaseChangeCounts } from '../utils/changes';
import { EmbeddedLabelProvider, EmbeddedProvider } from '../utils/embedded';
import { formatCount } from '../utils/format';
import { coverageLine } from '../utils/glance';
import { matchedRows, MatchedRowsProvider } from '../utils/highlight';
import type { ImpactView } from '../utils/impact';
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
import { SummaryExtraProvider } from './SignalParts';
import { SignalDetail } from './SignalDetail';

/*
 * The "Configuration changes" tab of a report: the three phases as a
 * segmented control, and per phase one row per source (Options & autoload,
 * WP-Cron, Action Scheduler) with its count and availability. A row with
 * changes expands in place to its change lists (the signal's details,
 * without repeating its summary); rows without changes or data only say so.
 * Every row also links to the source's signal page.
 */

/** Key of an expanded source in the report's ImpactView. */
const sourceViewKey = ( provider: Provider ) => `configuration:${ provider }`;

/**
 * The tab's content.
 *
 * @param props               Props.
 * @param props.report        Report.
 * @param props.counts        Change counts per phase.
 * @param props.selected      Selected phase, null if no phase is available.
 * @param props.onSelectPhase Selects a phase.
 * @param props.view          Expanded sources (shared report view state).
 * @param props.onViewChange  Updates them.
 * @param props.signalHref    Address of a source's signal page.
 * @param props.onOpenSignal  Opens a source's signal page in the app.
 */
export function ConfigurationChanges( {
	report,
	counts,
	selected,
	onSelectPhase,
	view,
	onViewChange,
	signalHref,
	onOpenSignal,
}: {
	report: AnalysisReport;
	counts: Record< PhaseKey, PhaseChangeCounts >;
	selected: PhaseKey | null;
	onSelectPhase: ( phase: PhaseKey ) => void;
	view: ImpactView;
	onViewChange: ( view: ImpactView ) => void;
	signalHref: ( provider: Provider ) => string;
	onOpenSignal: ( provider: Provider ) => void;
} ) {
	const toggle = ( provider: Provider ) => {
		const open = { ...view.open };
		const key = sourceViewKey( provider );
		if ( open[ key ] === undefined ) {
			open[ key ] = 1;
		} else {
			delete open[ key ];
		}
		onViewChange( { ...view, open } );
	};

	const heading = (
		<div className="min-w-0">
			<h3
				id="updatelens-configuration-title"
				tabIndex={ -1 }
				className="text-section-title font-semibold tracking-tight text-slate-950 focus:outline-none"
			>
				{ __( 'Configuration changes', 'updatelens' ) }
			</h3>
			<p className="mt-1 max-w-2xl text-section-description text-muted-foreground">
				{ __(
					'Changes observed around the update, by source. Observed changes are not proof that the updated plugin made them.',
					'updatelens'
				) }
			</p>
		</div>
	);

	return (
		<section
			aria-labelledby="updatelens-configuration-title"
			className="space-y-4"
		>
			{ selected ? (
				<PhaseTabs
					counts={ {
						during_update: counts.during_update.total,
						post_update: counts.post_update.total,
						final: counts.final.total,
					} }
					selected={ selected }
					onSelect={ onSelectPhase }
					header={ heading }
				>
					<PhaseSources
						report={ report }
						phase={ selected }
						counts={ counts[ selected ] }
						view={ view }
						onToggle={ toggle }
						signalHref={ signalHref }
						onOpenSignal={ onOpenSignal }
					/>
				</PhaseTabs>
			) : (
				// No phase at all (the update failed): why, in one line.
				<>
					{ heading }
					<NoPhases report={ report } counts={ counts } />
				</>
			) }
		</section>
	);
}

/**
 * Why no phase is available.
 *
 * @param props        Props.
 * @param props.report Report.
 * @param props.counts Change counts per phase.
 */
function NoPhases( {
	report,
	counts,
}: {
	report: AnalysisReport;
	counts: Record< PhaseKey, PhaseChangeCounts >;
} ) {
	const coverage = coverageLine( report, counts );
	return (
		<p className="flex gap-2 text-[13px] leading-5 text-slate-700">
			<Icon
				name={ coverage.icon }
				className={ cn(
					'mt-0.5 size-4 shrink-0',
					TONE_ICON[ coverage.tone ]
				) }
			/>
			<span>{ coverage.text }</span>
		</p>
	);
}

/**
 * One phase: a row per source, always in the order Options & autoload,
 * WP-Cron, Action Scheduler. Without any available source, the phase's
 * reason (from Options, the signal that drives the analysis) first.
 *
 * @param props          Props.
 * @param props.report   Report.
 * @param props.phase    Phase.
 * @param props.counts   Change counts of the phase.
 * @param props.view         Expanded sources.
 * @param props.onToggle     Expands or collapses a source.
 * @param props.signalHref   Address of a source's signal page.
 * @param props.onOpenSignal Opens a source's signal page in the app.
 */
function PhaseSources( {
	report,
	phase,
	counts,
	view,
	onToggle,
	signalHref,
	onOpenSignal,
}: {
	report: AnalysisReport;
	phase: PhaseKey;
	counts: PhaseChangeCounts;
	view: ImpactView;
	onToggle: ( provider: Provider ) => void;
	signalHref: ( provider: Provider ) => string;
	onOpenSignal: ( provider: Provider ) => void;
} ) {
	const data = report.phases[ phase ];
	const unavailable =
		counts.total === null
			? signalUnavailableText(
					'options',
					data,
					report.observation_window_seconds
				)?.description
			: null;

	return (
		<div className="space-y-2">
			{ unavailable && (
				<p className="text-sm leading-6 text-slate-700">
					<span className="font-semibold text-slate-900">
						{ observedChangesText( null ) }
					</span>
					<span className="block">{ unavailable }</span>
				</p>
			) }
			<ul
				aria-label={ __( 'Signals', 'updatelens' ) }
				className="space-y-3"
			>
				{ PROVIDERS.map( ( provider ) => (
					<SourceRow
						key={ provider }
						report={ report }
						provider={ provider }
						phase={ phase }
						count={ counts[ provider ] }
						expanded={
							view.open[ sourceViewKey( provider ) ] !== undefined
						}
						onToggle={ () => onToggle( provider ) }
						href={ signalHref( provider ) }
						onOpen={ () => onOpenSignal( provider ) }
					/>
				) ) }
			</ul>
		</div>
	);
}

/**
 * One source of the phase: its name with the number of changes, the
 * non-zero counts, or why it is not available. With changes, the name is a
 * button that shows the change lists in place (rendered only while
 * expanded); otherwise the row is static. Every row links to the source's
 * signal page, whatever its state.
 *
 * @param props          Props.
 * @param props.report   Report.
 * @param props.provider Source.
 * @param props.phase    Phase.
 * @param props.count    Change count of the source, null if unavailable.
 * @param props.expanded Whether its changes are shown.
 * @param props.onToggle Shows or hides them.
 * @param props.href     Address of the source's signal page.
 * @param props.onOpen   Opens it in the app.
 */
function SourceRow( {
	report,
	provider,
	phase,
	count,
	expanded,
	onToggle,
	href,
	onOpen,
}: {
	report: AnalysisReport;
	provider: Provider;
	phase: PhaseKey;
	count: number | null;
	expanded: boolean;
	onToggle: () => void;
	href: string;
	onOpen: () => void;
} ) {
	const id = useId();
	const nameId = `${ id }-name`;
	const statusId = `${ id }-status`;
	const panelId = `${ id }-panel`;
	const data = report.phases[ phase ];
	const hasChanges = ( count ?? 0 ) > 0;
	const open = hasChanges && expanded;
	const breakdown = hasChanges ? signalBreakdown( provider, data ) : [];
	// Not detected is worded by its evidence, like the review rules.
	const notDetected =
		provider === 'action_scheduler' &&
		! data.action_scheduler.available &&
		data.action_scheduler.reason === 'not_installed';
	let unavailable: string | null | undefined = null;
	if ( count === null ) {
		unavailable = notDetected
			? actionSchedulerNotDetectedText(
					report.potential_impact.signals.action_scheduler.status ===
						'not_applicable'
				)
			: signalUnavailableText(
					provider,
					data,
					report.observation_window_seconds
				)?.title;
	}

	return (
		<li
			data-signal={ provider }
			className={ cn(
				'overflow-hidden rounded-lg border bg-card shadow-surface transition-colors',
				hasChanges && ! open && 'hover:border-slate-400',
				open && 'border-slate-400'
			) }
		>
			<div
				className={ cn(
					'relative flex items-center gap-3 px-3 py-3 sm:gap-4 sm:px-4',
					hasChanges && ! open && 'hover:bg-slate-50'
				) }
			>
				<span
					className={ cn(
						'grid size-10 shrink-0 place-items-center rounded-lg',
						hasChanges
							? 'bg-tint-strong text-primary'
							: 'bg-slate-100 text-slate-600'
					) }
				>
					<Icon name={ provider } />
				</span>
				<div className="min-w-0 flex-1">
					<h4 className="flex flex-wrap items-center gap-x-2 text-[15px] font-semibold leading-6 text-slate-950">
						{ hasChanges ? (
							<button
								type="button"
								id={ nameId }
								aria-expanded={ open }
								aria-controls={ panelId }
								aria-describedby={ statusId }
								onClick={ onToggle }
								className="text-left after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:rounded-[inherit] focus-visible:after:ring-2 focus-visible:after:ring-inset focus-visible:after:ring-ring"
							>
								{ providerLabel( provider ) }
							</button>
						) : (
							<span>{ providerLabel( provider ) }</span>
						) }
						{ hasChanges && (
							<span
								aria-hidden="true"
								className="inline-flex min-w-6 justify-center rounded-full bg-tint-strong px-2 text-xs font-semibold tabular-nums leading-5 text-tint-foreground"
							>
								{ formatCount( count ?? 0 ) }
							</span>
						) }
					</h4>
					<p
						id={ statusId }
						className="text-[13px] leading-5 text-slate-700"
					>
						<span
							className={ cn(
								hasChanges
									? 'sr-only'
									: 'tabular-nums text-slate-800'
							) }
						>
							{ signalStatusText( count ) }
							{ /* The total is for screen readers; the badge shows it. */ }
							{ hasChanges && ' ·' }
						</span>
						{ hasChanges && ' ' }
						{ breakdown.map( ( [ label, value ], index ) => (
							<Fragment key={ label }>
								{ index > 0 && ' · ' }
								{ label }{ ' ' }
								<span className="font-semibold tabular-nums text-slate-900">
									{ formatCount( value ) }
								</span>
							</Fragment>
						) ) }
						{ unavailable && (
							<>
								{ ' · ' }
								{ unavailable }
							</>
						) }
					</p>
				</div>
				{ /* Above the row's stretched toggle, so it stays a link of its own. */ }
				<AppLink
					href={ href }
					onNavigate={ onOpen }
					data-updatelens-signal-link={ provider }
					aria-label={ sprintf(
						/* translators: %s: signal name, e.g. "WP-Cron". */
						__( 'Open page: %s', 'updatelens' ),
						providerLabel( provider )
					) }
					className="relative z-10 inline-flex shrink-0 items-center gap-1 rounded text-[13px] font-medium text-primary no-underline hover:underline focus:shadow-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
				>
					{ __( 'Open page', 'updatelens' ) }
					<Icon
						name="arrow-right"
						className="hidden size-4 sm:block"
					/>
				</AppLink>
				{ hasChanges && (
					<Icon
						name="chevron-down"
						className={ cn(
							'size-4 text-slate-600 transition-transform',
							open && 'rotate-180'
						) }
					/>
				) }
			</div>
			{ hasChanges && (
				<div id={ panelId } hidden={ ! open }>
					{ open && (
						<div className="border-t bg-slate-50 px-3 py-4 sm:px-4">
							<SourceChanges
								report={ report }
								provider={ provider }
								phase={ phase }
								count={ count }
								nameId={ nameId }
							/>
						</div>
					) }
				</div>
			) }
		</li>
	);
}

/**
 * A source's change lists in one phase, embedded in its row: on the Net
 * result, the records review rules matched are marked and one line says
 * how many matched (the API's counts).
 *
 * @param props          Props.
 * @param props.report   Report.
 * @param props.provider Source.
 * @param props.phase    Phase.
 * @param props.count    Its change count.
 * @param props.nameId   ID of the row's source name (names its list regions).
 */
function SourceChanges( {
	report,
	provider,
	phase,
	count,
	nameId,
}: {
	report: AnalysisReport;
	provider: Provider;
	phase: PhaseKey;
	count: number | null;
	nameId: string;
} ) {
	const matched = useMemo(
		() => ( phase === 'final' ? matchedRows( report, provider ) : null ),
		[ report, provider, phase ]
	);
	return (
		<EmbeddedProvider value={ true }>
			<EmbeddedLabelProvider value={ nameId }>
				<MatchedRowsProvider value={ matched }>
					<SummaryExtraProvider
						value={
							<RulesLine
								report={ report }
								signal={ provider }
								phase={ phase }
								count={ count }
							/>
						}
					>
						<SignalDetail
							provider={ provider }
							phase={ phase }
							data={ report.phases[ phase ] }
							count={ count }
							windowSeconds={ report.observation_window_seconds }
						/>
					</SummaryExtraProvider>
				</MatchedRowsProvider>
			</EmbeddedLabelProvider>
		</EmbeddedProvider>
	);
}

/**
 * Whether review rules matched this signal's changes, in one quiet line
 * above them (only with changes). On the Net result the numbers are the
 * API's (`assessment.net_result`): the records findings cover, which are
 * marked on their rows, and the rest, which are informational. Other phases
 * are never checked by the rules. Never "safe": no rule matching does not
 * show that a change had no effect.
 *
 * @param props        Props.
 * @param props.report Report.
 * @param props.signal Signal of the page.
 * @param props.phase  Selected phase.
 * @param props.count  Changes of the signal in the phase, null if unavailable.
 */
export function RulesLine( {
	report,
	signal,
	phase,
	count,
}: {
	report: AnalysisReport;
	signal: Provider;
	phase: PhaseKey;
	count: number | null;
} ) {
	if ( count === null || count === 0 ) {
		return null;
	}
	const records = report.potential_impact.assessment.net_result[ signal ];
	let text: string;
	if ( phase !== 'final' ) {
		text = __(
			'Review rules are checked on the Net result only, not on this phase.',
			'updatelens'
		);
	} else if ( records === null ) {
		text = __(
			'Review rules could not be checked for these changes.',
			'updatelens'
		);
	} else if ( records.matched > 0 ) {
		const marked = records.matched;
		const others = records.informational;
		text = sprintf(
			/* translators: %s: number of changes a review rule matched. */
			_n(
				'Review rules matched %s change; it is marked below.',
				'Review rules matched %s changes; they are marked below.',
				marked,
				'updatelens'
			),
			formatCount( marked )
		);
		if ( others > 0 ) {
			text += ` ${ sprintf(
				/* translators: %s: number of changes no review rule matched. */
				_n(
					'%s other change matched no rule (informational).',
					'%s other changes matched no rule (informational).',
					others,
					'updatelens'
				),
				formatCount( others )
			) }`;
		}
	} else {
		text = __(
			'No review rule matched these changes (informational). That does not show they had no effect.',
			'updatelens'
		);
	}
	return (
		<p className="flex gap-2 text-[13px] leading-5 text-slate-700">
			<Icon
				name="info"
				className="mt-0.5 size-4 shrink-0 text-slate-600"
			/>
			<span>{ text }</span>
		</p>
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
