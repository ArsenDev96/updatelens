import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useMemo, useRef, type RefObject } from 'react';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

import { getAnalysis } from '../api/analyses';
import { AppLink } from '../components/AppLink';
import { LoadError } from '../components/LoadError';
import { PhaseTabs } from '../components/PhaseTabs';
import { ReportOverview } from '../components/ReportOverview';
import { SignalDetail } from '../components/SignalDetail';
import { StatusBadge } from '../components/StatusBadge';
import { Versions } from '../components/Versions';
import { useRequest } from '../hooks/use-request';
import type { AnalysisReport, PhaseKey, Provider } from '../types/api';
import { reportChangeCounts } from '../utils/changes';
import { formatDateTime } from '../utils/format';
import {
	defaultPhase,
	PHASE_KEYS,
	phaseLabel,
	providerLabel,
	reportNotice,
} from '../utils/labels';
import { pluginName } from '../utils/plugin';
import type { ReportRoute, Route } from '../utils/route';
import { TONE_STRIP } from '../utils/tone';

interface ReportPageProps {
	/** Report, phase and signal from the URL. */
	route: ReportRoute;
	/** Link target of a route. */
	href: ( route: Route ) => string;
	/** In-app navigation; `replace` keeps the current history entry. */
	onNavigate: ( route: Route, replace?: boolean ) => void;
	/** Move focus to the title once loaded (after in-app navigation). */
	focusHeading: boolean;
}

const LINK =
	'rounded font-medium text-primary no-underline hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

/**
 * One report: its overview, or one signal's details (`&signal=`). Both
 * views share the loaded report and the selected phase.
 *
 * @param props Props.
 */
export function ReportPage( {
	route,
	href,
	onNavigate,
	focusHeading,
}: ReportPageProps ) {
	const { id } = route;
	const load = useCallback( () => getAnalysis( id ), [ id ] );
	const [ request, reload ] = useRequest( load );
	const history: Route = { view: 'history', page: route.page };

	return (
		<div className="space-y-5">
			{ ( request.status !== 'ready' || route.signal === null ) && (
				<AppLink
					href={ href( history ) }
					onNavigate={ () => onNavigate( history ) }
					className={ cn(
						LINK,
						'inline-flex items-center gap-1 text-sm'
					) }
				>
					<span aria-hidden="true">←</span>
					{ __( 'Update History', 'updatelens' ) }
				</AppLink>
			) }
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
					route={ route }
					href={ href }
					onNavigate={ onNavigate }
					onRefresh={ reload }
					focusHeading={ focusHeading }
				/>
			) }
		</div>
	);
}

function Report( {
	report,
	route,
	href,
	onNavigate,
	onRefresh,
	focusHeading,
}: Omit< ReportPageProps, 'route' > & {
	report: AnalysisReport;
	route: ReportRoute;
	onRefresh: () => void;
} ) {
	const heading = useRef< HTMLHeadingElement >( null );
	// Counts come from the summaries only; computed once per loaded report.
	const counts = useMemo(
		() => reportChangeCounts( report.phases ),
		[ report.phases ]
	);
	// The default phase is the report's, so overview and details agree.
	const initial = defaultPhase( report.phases, counts );
	const selected = initial === null ? null : ( route.phase ?? initial );
	const { signal } = route;

	// After in-app navigation (also between overview and details), focus
	// moves to the view's title. Switching phases keeps focus on the tab.
	useEffect( () => {
		if ( focusHeading ) {
			heading.current?.focus();
		}
	}, [ focusHeading, signal ] );

	const at = ( next: Partial< ReportRoute > ): ReportRoute => ( {
		...route,
		...next,
	} );
	const selectPhase = ( phase: PhaseKey ) =>
		onNavigate( at( { phase } ), true );

	if ( signal !== null ) {
		const overview = at( { signal: null } );
		const history: Route = { view: 'history', page: route.page };
		return (
			<div className="space-y-5">
				<nav aria-label={ __( 'Breadcrumb', 'updatelens' ) }>
					<ol className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
						<li className="flex items-center gap-2">
							<AppLink
								href={ href( history ) }
								onNavigate={ () => onNavigate( history ) }
								className={ LINK }
							>
								{ __( 'Update History', 'updatelens' ) }
							</AppLink>
							<span
								aria-hidden="true"
								className="text-muted-foreground"
							>
								›
							</span>
						</li>
						<li className="flex min-w-0 items-center gap-2">
							<AppLink
								href={ href( overview ) }
								onNavigate={ () => onNavigate( overview ) }
								className={ cn( LINK, 'break-words' ) }
							>
								{ pluginName( report.plugin ) }
							</AppLink>
							<span
								aria-hidden="true"
								className="text-muted-foreground"
							>
								›
							</span>
						</li>
						<li
							aria-current="page"
							className="text-muted-foreground"
						>
							{ providerLabel( signal ) }
						</li>
					</ol>
				</nav>
				<article
					aria-labelledby="updatelens-signal-title"
					className="space-y-6"
				>
					<header className="space-y-1.5">
						<h2
							id="updatelens-signal-title"
							ref={ heading }
							tabIndex={ -1 }
							className="text-2xl font-semibold outline-none"
						>
							{ providerLabel( signal ) }
						</h2>
						<p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
							<span className="break-words font-medium text-foreground">
								{ pluginName( report.plugin ) }
							</span>
							<span className="font-mono">
								<Versions plugin={ report.plugin } />
							</span>
							<StatusBadge status={ report.status } />
						</p>
					</header>
					{ selected ? (
						<PhaseTabs
							counts={ {
								during_update: counts.during_update[ signal ],
								post_update: counts.post_update[ signal ],
								final: counts.final[ signal ],
							} }
							selected={ selected }
							onSelect={ selectPhase }
						>
							<SignalDetail
								provider={ signal }
								phase={ selected }
								data={ report.phases[ selected ] }
								count={ counts[ selected ][ signal ] }
								windowSeconds={
									report.observation_window_seconds
								}
							/>
						</PhaseTabs>
					) : (
						<Notice report={ report } onRefresh={ onRefresh } />
					) }
					<TechnicalDetails report={ report } signal={ signal } />
				</article>
			</div>
		);
	}

	return (
		<article
			aria-labelledby="updatelens-report-title"
			className="space-y-6"
		>
			<ReportHeader report={ report } heading={ heading } />
			{ selected && (
				<PhaseTabs
					counts={ {
						during_update: counts.during_update.total,
						post_update: counts.post_update.total,
						final: counts.final.total,
					} }
					selected={ selected }
					onSelect={ selectPhase }
				>
					<ReportOverview
						phase={ selected }
						data={ report.phases[ selected ] }
						counts={ counts[ selected ] }
						windowSeconds={ report.observation_window_seconds }
						signalHref={ ( provider: Provider ) =>
							href( at( { signal: provider } ) )
						}
						onOpenSignal={ ( provider: Provider ) =>
							onNavigate( at( { signal: provider } ) )
						}
					/>
				</PhaseTabs>
			) }
			<Notice report={ report } onRefresh={ onRefresh } />
			<TechnicalDetails report={ report } signal={ null } />
		</article>
	);
}

function ReportHeader( {
	report,
	heading,
}: {
	report: AnalysisReport;
	heading: RefObject< HTMLHeadingElement >;
} ) {
	const updated = formatDateTime( report.timestamps.started_at );

	return (
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
	);
}

/**
 * The analysis status as a compact strip: secondary to the results.
 *
 * @param props           Props.
 * @param props.report    Report.
 * @param props.onRefresh Reloads the report (open analyses).
 */
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

/**
 * Raw codes and timestamps, collapsed. On a signal's details only that
 * signal's phase reasons are listed.
 *
 * @param props        Props.
 * @param props.report Report.
 * @param props.signal Signal of the detail view; null on the overview.
 */
function TechnicalDetails( {
	report,
	signal,
}: {
	report: AnalysisReport;
	signal: Provider | null;
} ) {
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
		...( signal === null || signal === 'cron'
			? PHASE_KEYS.map( ( phase ): [ string, string | null, null ] => {
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
				} )
			: [] ),
		...( signal === null || signal === 'action_scheduler'
			? PHASE_KEYS.map( ( phase ): [ string, string | null, null ] => {
					const actionScheduler =
						report.phases[ phase ].action_scheduler;
					return [
						sprintf(
							/* translators: %s: observation phase, e.g. "Net result". */
							__( 'Action Scheduler reason (%s)', 'updatelens' ),
							phaseLabel( phase )
						),
						actionScheduler.available
							? null
							: actionScheduler.reason,
						null,
					];
				} )
			: [] ),
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
			<div className="grid gap-3 sm:grid-cols-3">
				{ [ 0, 1, 2 ].map( ( n ) => (
					<Skeleton key={ n } className="h-24" />
				) ) }
			</div>
		</div>
	);
}
