import { __, sprintf } from '@wordpress/i18n';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
	type MouseEvent,
} from 'react';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

import { getAnalysis } from '../api/analyses';
import { ActionSchedulerChanges } from '../components/ActionSchedulerDiff';
import { CronDiffList, CronSummary } from '../components/CronDiff';
import { LoadError } from '../components/LoadError';
import { OptionDiffList } from '../components/OptionDiffList';
import { OptionsSummary } from '../components/PhaseSummary';
import { PhaseTabs } from '../components/PhaseTabs';
import { StatusBadge } from '../components/StatusBadge';
import { Versions } from '../components/Versions';
import { useRequest } from '../hooks/use-request';
import type {
	AnalysisReport,
	PhaseKey,
	Provider,
	ReportPhase,
} from '../types/api';
import { reportChangeCounts, type PhaseChangeCounts } from '../utils/changes';
import { formatDateTime } from '../utils/format';
import {
	actionSchedulerUnavailableReasonText,
	changeCountNoun,
	cronPhaseNote,
	cronUnavailableReasonText,
	defaultPhase,
	observedChangesText,
	PHASE_KEYS,
	phaseLabel,
	phaseNote,
	PROVIDERS,
	providerLabel,
	reportNotice,
	signalStatusText,
	unavailableReasonText,
} from '../utils/labels';
import { pluginName } from '../utils/plugin';
import { TONE_STRIP } from '../utils/tone';

interface ReportPageProps {
	id: number;
	historyHref: string;
	onBack: () => void;
	/** Move focus to the title once loaded (after in-app navigation). */
	focusHeading: boolean;
}

export function ReportPage( {
	id,
	historyHref,
	onBack,
	focusHeading,
}: ReportPageProps ) {
	const load = useCallback( () => getAnalysis( id ), [ id ] );
	const [ request, reload ] = useRequest( load );

	const onBackClick = ( event: MouseEvent< HTMLAnchorElement > ) => {
		if (
			event.button !== 0 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}
		event.preventDefault();
		onBack();
	};

	const backLink = (
		<a
			href={ historyHref }
			onClick={ onBackClick }
			className="inline-flex items-center gap-1 rounded text-sm font-medium text-primary no-underline hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
		>
			<span aria-hidden="true">←</span>
			{ __( 'Update History', 'updatelens' ) }
		</a>
	);

	return (
		<div className="space-y-5">
			{ backLink }
			{ request.status === 'loading' && <ReportSkeleton /> }
			{ request.status === 'error' && (
				<LoadError
					error={ request.error }
					message={ __(
						"This report couldn't be loaded. Try again.",
						'updatelens'
					) }
					onRetry={ reload }
				/>
			) }
			{ request.status === 'ready' && (
				<Report
					report={ request.data }
					onRefresh={ reload }
					focusHeading={ focusHeading }
				/>
			) }
		</div>
	);
}

function Report( {
	report,
	onRefresh,
	focusHeading,
}: {
	report: AnalysisReport;
	onRefresh: () => void;
	focusHeading: boolean;
} ) {
	const heading = useRef< HTMLHeadingElement >( null );
	const [ chosen, setChosen ] = useState< PhaseKey | null >( null );
	// Counts come from the summaries only; computed once per loaded report.
	const counts = useMemo(
		() => reportChangeCounts( report.phases ),
		[ report.phases ]
	);
	const initial = defaultPhase( report.phases, counts );
	const selected = chosen ?? initial;

	useEffect( () => {
		if ( focusHeading ) {
			heading.current?.focus();
		}
	}, [ focusHeading ] );

	const updated = formatDateTime( report.timestamps.started_at );

	return (
		<article
			aria-labelledby="updatelens-report-title"
			className="space-y-5"
		>
			<header className="space-y-1">
				<div className="flex flex-wrap items-center gap-x-3 gap-y-1">
					<h2
						id="updatelens-report-title"
						ref={ heading }
						tabIndex={ -1 }
						className="break-words text-2xl font-semibold outline-none"
					>
						{ pluginName( report.plugin ) }
					</h2>
					<StatusBadge status={ report.status } />
				</div>
				<p className="font-mono text-base font-medium">
					<Versions plugin={ report.plugin } />
				</p>
				{ updated && (
					<p className="text-sm text-muted-foreground">
						{ sprintf(
							/* translators: %s: date and time of the update. */
							__( 'Updated %s', 'updatelens' ),
							updated
						) }
					</p>
				) }
				{ report.plugin.file && (
					<p className="break-all font-mono text-xs text-muted-foreground">
						{ report.plugin.file }
					</p>
				) }
			</header>

			<Notice report={ report } onRefresh={ onRefresh } />

			{ selected && (
				<PhaseTabs
					counts={ counts }
					selected={ selected }
					onSelect={ setChosen }
				>
					<PhasePanel
						phase={ selected }
						data={ report.phases[ selected ] }
						counts={ counts[ selected ] }
						windowSeconds={ report.observation_window_seconds }
					/>
				</PhaseTabs>
			) }

			<TechnicalDetails report={ report } />
		</article>
	);
}

function Notice( {
	report,
	onRefresh,
}: {
	report: AnalysisReport;
	onRefresh: () => void;
} ) {
	const notice = reportNotice( report );

	return (
		<section
			aria-label={ __( 'Analysis status', 'updatelens' ) }
			className={ cn(
				'flex flex-wrap items-center gap-x-4 gap-y-2 rounded-md border-l-4 bg-muted/50 px-3 py-2 text-sm',
				TONE_STRIP[ notice.tone ]
			) }
		>
			<div className="min-w-0 flex-1 space-y-0.5">
				<p className="font-medium">{ notice.title }</p>
				<p className="text-muted-foreground">
					<span>{ notice.description }</span>
					{ notice.details.map( ( detail ) => (
						<span key={ detail }> { detail }</span>
					) ) }
				</p>
			</div>
			{ notice.open && (
				<Button variant="outline" size="sm" onClick={ onRefresh }>
					{ __( 'Refresh', 'updatelens' ) }
				</Button>
			) }
		</section>
	);
}

function PhasePanel( {
	phase,
	data,
	counts,
	windowSeconds,
}: {
	phase: PhaseKey;
	data: ReportPhase;
	counts: PhaseChangeCounts;
	windowSeconds: number;
} ) {
	return (
		<div className="space-y-6">
			<PhaseOverview phase={ phase } counts={ counts } />
			{ PROVIDERS.map( ( provider ) => (
				<SignalSection
					key={ provider }
					provider={ provider }
					data={ data }
					count={ counts[ provider ] }
					windowSeconds={ windowSeconds }
				/>
			) ) }
		</div>
	);
}

/**
 * What the phase covers, its total of observed changes (the tab's count) and,
 * when there are changes, each signal's state. Without changes the compact
 * signal sections say the rest.
 *
 * @param props        Props.
 * @param props.phase  Phase.
 * @param props.counts Change counts of the phase.
 */
function PhaseOverview( {
	phase,
	counts,
}: {
	phase: PhaseKey;
	counts: PhaseChangeCounts;
} ) {
	return (
		<div className="space-y-2">
			<p className="text-sm text-muted-foreground">
				{ phaseNote( phase ) }
			</p>
			<p
				className={ cn(
					'tabular-nums',
					( counts.total ?? 0 ) > 0
						? 'text-2xl font-semibold'
						: 'text-base font-medium'
				) }
			>
				{ observedChangesText( counts.total ) }
			</p>
			{ ( counts.total ?? 0 ) > 0 && (
				<dl
					aria-label={ __( 'Observed signals', 'updatelens' ) }
					className="flex flex-wrap gap-x-6 gap-y-1 text-sm"
				>
					{ PROVIDERS.map( ( provider ) => (
						<div
							key={ provider }
							className="flex items-baseline gap-1.5"
						>
							<dt className="text-muted-foreground">
								{ providerLabel( provider ) }
							</dt>
							<dd
								className={
									( counts[ provider ] ?? 0 ) > 0
										? 'font-medium'
										: 'text-muted-foreground'
								}
							>
								{ signalStatusText( counts[ provider ] ) }
							</dd>
						</div>
					) ) }
				</dl>
			) }
		</div>
	);
}

/**
 * One signal of the phase as a section: in full with its changes, or as a
 * single muted line without changes or when unavailable.
 *
 * @param props               Props.
 * @param props.provider      Signal.
 * @param props.data          Phase data of every signal.
 * @param props.count         Change count of the signal, null if unavailable.
 * @param props.windowSeconds Observation window length from the API.
 */
function SignalSection( {
	provider,
	data,
	count,
	windowSeconds,
}: {
	provider: Provider;
	data: ReportPhase;
	count: number | null;
	windowSeconds: number;
} ) {
	const headingId = `updatelens-signal-${ provider }-heading`;
	const label = providerLabel( provider );

	if ( count === null || count === 0 ) {
		const reason =
			count === null
				? unavailableText( provider, data, windowSeconds )
				: null;
		return (
			<section
				id={ `updatelens-signal-${ provider }` }
				aria-labelledby={ headingId }
				className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 border-t pt-3 text-sm"
			>
				<h3 id={ headingId } className="font-medium">
					{ label }
				</h3>
				<p className="text-muted-foreground">
					{ reason ? (
						<>
							<span className="font-medium">
								{ reason.title }
							</span>
							{ ' · ' }
							<span>{ reason.description }</span>
						</>
					) : (
						__( 'No changes observed.', 'updatelens' )
					) }
				</p>
			</section>
		);
	}

	return (
		<section
			id={ `updatelens-signal-${ provider }` }
			aria-labelledby={ headingId }
			className="space-y-4 border-t pt-5"
		>
			<h3 id={ headingId } className="text-lg font-semibold">
				{ label }{ ' ' }
				<span className="font-normal text-muted-foreground">
					· { changeCountNoun( count ) }
				</span>
			</h3>
			{ provider === 'options' && data.options.available && (
				<>
					<OptionsSummary summary={ data.options.summary } />
					<OptionDiffList phase={ data.options } />
				</>
			) }
			{ provider === 'cron' && data.cron.available && (
				<>
					<CronSummary summary={ data.cron.summary } />
					<p className="text-xs text-muted-foreground">
						{ cronPhaseNote() }
					</p>
					<CronDiffList phase={ data.cron } />
				</>
			) }
			{ provider === 'action_scheduler' &&
				data.action_scheduler.available && (
					<ActionSchedulerChanges phase={ data.action_scheduler } />
				) }
		</section>
	);
}

/**
 * Why a signal is unavailable in this phase.
 *
 * @param provider      Signal.
 * @param data          Phase data.
 * @param windowSeconds Observation window length from the API.
 */
function unavailableText(
	provider: Provider,
	data: ReportPhase,
	windowSeconds: number
): { title: string; description: string } | null {
	switch ( provider ) {
		case 'options':
			return data.options.available
				? null
				: unavailableReasonText( data.options.reason, windowSeconds );
		case 'cron':
			return data.cron.available
				? null
				: cronUnavailableReasonText( data.cron.reason, windowSeconds );
		case 'action_scheduler':
			return data.action_scheduler.available
				? null
				: actionSchedulerUnavailableReasonText(
						data.action_scheduler.reason,
						windowSeconds
					);
	}
}

function TechnicalDetails( { report }: { report: AnalysisReport } ) {
	const rows: Array< [ string, string | null, string | null ] > = [
		[ __( 'Analysis ID', 'updatelens' ), String( report.id ), null ],
		[ __( 'Plugin file', 'updatelens' ), report.plugin.file, null ],
		[ __( 'Status', 'updatelens' ), report.status, null ],
		[
			__( 'Observation outcome', 'updatelens' ),
			report.settle_outcome,
			null,
		],
		[ __( 'Error code', 'updatelens' ), report.error?.code ?? null, null ],
		[
			__( 'Started', 'updatelens' ),
			formatDateTime( report.timestamps.started_at ),
			report.timestamps.started_at,
		],
		[
			__( 'Observation deadline', 'updatelens' ),
			formatDateTime( report.timestamps.settle_deadline ),
			report.timestamps.settle_deadline,
		],
		[
			__( 'Completed', 'updatelens' ),
			formatDateTime( report.timestamps.completed_at ),
			report.timestamps.completed_at,
		],
		...PHASE_KEYS.map( ( phase ): [ string, string | null, null ] => {
			const cron = report.phases[ phase ].cron;
			return [
				sprintf(
					/* translators: %s: observation phase, e.g. "Net result". */
					__( 'WP-Cron reason (%s)', 'updatelens' ),
					phaseLabel( phase )
				),
				cron.available ? null : cron.reason,
				null,
			];
		} ),
		...PHASE_KEYS.map( ( phase ): [ string, string | null, null ] => {
			const actionScheduler = report.phases[ phase ].action_scheduler;
			return [
				sprintf(
					/* translators: %s: observation phase, e.g. "Net result". */
					__( 'Action Scheduler reason (%s)', 'updatelens' ),
					phaseLabel( phase )
				),
				actionScheduler.available ? null : actionScheduler.reason,
				null,
			];
		} ),
	];

	return (
		<details className="group rounded-lg border px-4 py-2 text-sm">
			<summary className="cursor-pointer select-none rounded font-medium text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
				{ __( 'Technical details', 'updatelens' ) }
			</summary>
			<dl className="mt-2 grid gap-x-6 gap-y-1 pb-1 sm:grid-cols-[auto_minmax(0,1fr)]">
				{ rows
					.filter( ( [ , value ] ) => value !== null && value !== '' )
					.map( ( [ label, value, iso ] ) => (
						<div key={ label } className="contents">
							<dt className="text-muted-foreground">{ label }</dt>
							<dd className="break-all font-mono text-xs leading-5">
								{ iso ? (
									<time dateTime={ iso }>{ value }</time>
								) : (
									value
								) }
							</dd>
						</div>
					) ) }
			</dl>
		</details>
	);
}

function ReportSkeleton() {
	return (
		<div aria-busy="true" className="space-y-4">
			<span role="status" className="sr-only">
				{ __( 'Loading report…', 'updatelens' ) }
			</span>
			<Skeleton className="h-6 w-56" />
			<Skeleton className="h-4 w-32" />
			<Skeleton className="h-16 w-full" />
			<div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
				{ [ 0, 1, 2, 3 ].map( ( n ) => (
					<Skeleton key={ n } className="h-16" />
				) ) }
			</div>
		</div>
	);
}
