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
import { PhaseSummary } from '../components/PhaseSummary';
import { PhaseTabs, ProviderTabs } from '../components/PhaseTabs';
import { StatusBadge } from '../components/StatusBadge';
import { Versions } from '../components/Versions';
import { useRequest } from '../hooks/use-request';
import type {
	ActionSchedulerPhase,
	AnalysisReport,
	CronPhase,
	OptionsPhase,
	PhaseKey,
	Provider,
	ReportPhase,
} from '../types/api';
import {
	actionSchedulerChangeCount,
	cronChangeCount,
	optionsChangeCount,
	reportChangeCounts,
	type PhaseChangeCounts,
} from '../utils/changes';
import { formatDateTime } from '../utils/format';
import {
	actionSchedulerUnavailableReasonText,
	cronPhaseNote,
	cronUnavailableReasonText,
	defaultPhase,
	defaultProvider,
	noChangesText,
	PHASE_KEYS,
	phaseLabel,
	phaseNote,
	reportNotice,
	unavailableReasonText,
} from '../utils/labels';
import { pluginName } from '../utils/plugin';
import { TONE_NOTICE } from '../utils/tone';

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
	// An explicit signal choice is kept across phases; otherwise each phase picks its default.
	const [ chosenProvider, setChosenProvider ] = useState< Provider | null >(
		null
	);
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
			<header className="space-y-1.5">
				<div className="flex flex-wrap items-center gap-x-3 gap-y-1">
					<h2
						id="updatelens-report-title"
						ref={ heading }
						tabIndex={ -1 }
						className="break-words text-xl font-semibold outline-none"
					>
						{ pluginName( report.plugin ) }
					</h2>
					<StatusBadge status={ report.status } />
				</div>
				<p className="font-mono text-sm">
					<Versions plugin={ report.plugin } />
				</p>
				<p className="flex flex-wrap gap-x-3 text-sm text-muted-foreground">
					{ updated && (
						<span>
							{ sprintf(
								/* translators: %s: date and time of the update. */
								__( 'Updated %s', 'updatelens' ),
								updated
							) }
						</span>
					) }
					{ report.plugin.file && (
						<code className="m-0 break-all rounded bg-muted px-1.5 py-0.5 font-mono text-xs">
							{ report.plugin.file }
						</code>
					) }
				</p>
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
						provider={
							chosenProvider ??
							defaultProvider(
								report.phases[ selected ],
								counts[ selected ]
							)
						}
						onProvider={ setChosenProvider }
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
				'space-y-1 rounded-lg border px-4 py-3 text-sm',
				TONE_NOTICE[ notice.tone ]
			) }
		>
			<p className="font-medium">{ notice.title }</p>
			<p className="text-muted-foreground">{ notice.description }</p>
			{ notice.details.map( ( detail ) => (
				<p key={ detail }>{ detail }</p>
			) ) }
			{ notice.open && (
				<div className="pt-2">
					<Button variant="outline" size="sm" onClick={ onRefresh }>
						{ __( 'Refresh', 'updatelens' ) }
					</Button>
				</div>
			) }
		</section>
	);
}

function PhasePanel( {
	phase,
	data,
	counts,
	provider,
	onProvider,
	windowSeconds,
}: {
	phase: PhaseKey;
	data: ReportPhase;
	counts: PhaseChangeCounts;
	provider: Provider;
	onProvider: ( provider: Provider ) => void;
	windowSeconds: number;
} ) {
	return (
		<div className="space-y-4">
			<p className="text-sm text-muted-foreground">
				{ phaseNote( phase ) }
			</p>
			{ counts.options === 0 &&
				counts.cron === 0 &&
				( counts.action_scheduler ?? 0 ) === 0 && (
					<p className="text-sm font-medium">
						{ __(
							'No tracked changes observed during this phase.',
							'updatelens'
						) }
					</p>
				) }
			<ProviderTabs
				counts={ counts }
				selected={ provider }
				onSelect={ onProvider }
			>
				{ provider === 'options' && (
					<OptionsPanel
						data={ data.options }
						windowSeconds={ windowSeconds }
					/>
				) }
				{ provider === 'cron' && (
					<CronPanel
						data={ data.cron }
						windowSeconds={ windowSeconds }
					/>
				) }
				{ provider === 'action_scheduler' && (
					<ActionSchedulerPanel
						data={ data.action_scheduler }
						windowSeconds={ windowSeconds }
					/>
				) }
			</ProviderTabs>
		</div>
	);
}

function OptionsPanel( {
	data,
	windowSeconds,
}: {
	data: OptionsPhase;
	windowSeconds: number;
} ) {
	if ( ! data.available ) {
		return (
			<UnavailablePhase
				text={ unavailableReasonText( data.reason, windowSeconds ) }
			/>
		);
	}
	if ( optionsChangeCount( data ) === 0 ) {
		return <NoChanges text={ noChangesText( 'options' ) } />;
	}
	return (
		<div className="space-y-4">
			<PhaseSummary summary={ data.summary } />
			<OptionDiffList phase={ data } />
		</div>
	);
}

function CronPanel( {
	data,
	windowSeconds,
}: {
	data: CronPhase;
	windowSeconds: number;
} ) {
	if ( ! data.available ) {
		return (
			<UnavailablePhase
				text={ cronUnavailableReasonText( data.reason, windowSeconds ) }
			/>
		);
	}
	if ( cronChangeCount( data ) === 0 ) {
		return <NoChanges text={ noChangesText( 'cron' ) } />;
	}
	return (
		<div className="space-y-4">
			<p className="text-sm text-muted-foreground">{ cronPhaseNote() }</p>
			<CronSummary summary={ data.summary } />
			<CronDiffList phase={ data } />
		</div>
	);
}

function ActionSchedulerPanel( {
	data,
	windowSeconds,
}: {
	data: ActionSchedulerPhase;
	windowSeconds: number;
} ) {
	if ( ! data.available ) {
		return (
			<UnavailablePhase
				text={ actionSchedulerUnavailableReasonText(
					data.reason,
					windowSeconds
				) }
			/>
		);
	}
	if ( actionSchedulerChangeCount( data ) === 0 ) {
		return <NoChanges text={ noChangesText( 'action_scheduler' ) } />;
	}
	return <ActionSchedulerChanges phase={ data } />;
}

/**
 * Compact state of a signal that was captured without changes.
 *
 * @param props      Props.
 * @param props.text Title and description.
 */
function NoChanges( {
	text,
}: {
	text: { title: string; description: string };
} ) {
	return (
		<div className="rounded-lg border bg-card px-4 py-3 text-sm">
			<p className="font-medium">{ text.title }</p>
			<p className="text-muted-foreground">{ text.description }</p>
		</div>
	);
}

function UnavailablePhase( {
	text,
}: {
	text: { title: string; description: string };
} ) {
	return (
		<div className="rounded-lg border border-dashed px-4 py-3 text-sm">
			<p className="font-medium">{ text.title }</p>
			<p className="text-muted-foreground">{ text.description }</p>
		</div>
	);
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
