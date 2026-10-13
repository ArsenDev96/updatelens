import { __, sprintf } from '@wordpress/i18n';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
	type RefObject,
} from 'react';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

import { getAnalysis } from '../api/analyses';
import type { ApiError } from '../api/errors';
import { initialReport } from '../api/initial-data';
import { AppLink } from '../components/AppLink';
import {
	findingListKind,
	locate,
	LocatedProvider,
	type Located,
} from '../utils/highlight';
import { LoadError } from '../components/LoadError';
import { PhaseTabs } from '../components/PhaseTabs';
import { ReportGlance } from '../components/ReportGlance';
import {
	DEFAULT_VISIBLE_FINDINGS,
	ReportOverview,
} from '../components/ReportOverview';
import { SiteResponseChecks } from '../components/SiteResponseChecks';
import { SignalDetail } from '../components/SignalDetail';
import { StatusBadge } from '../components/StatusBadge';
import { Versions } from '../components/Versions';
import { Icon, type IconName } from '../components/Icon';
import {
	usePendingRefresh,
	type ManualRefresh,
	type PendingRefresh,
} from '../hooks/use-pending-refresh';
import { usePageLoadFallback } from '../hooks/use-page-load-fallback';
import { useRequest } from '../hooks/use-request';
import type {
	AnalysisReport,
	ImpactFinding,
	PhaseKey,
	Provider,
	SiteCheckId,
} from '../types/api';
import { reportChangeCounts } from '../utils/changes';
import { formatCount, formatDateTime } from '../utils/format';
import { COLLAPSED_IMPACT_VIEW, findingSubject } from '../utils/impact';
import {
	defaultPhase,
	PHASE_KEYS,
	phaseLabel,
	phaseNote,
	providerLabel,
	reportNotice,
	reportTooLargeText,
	requestFailureText,
	settleOutcomeText,
	type ReportNotice,
	type Tone,
} from '../utils/labels';
import { pluginName } from '../utils/plugin';
import type { ReportRoute, Route, RouteItem } from '../utils/route';
import {
	siteCheckButtonId,
	siteCheckFindingButtonId,
	siteCheckViewKey,
} from '../utils/siteChecks';
import { TONE_ICON } from '../utils/tone';

interface ReportPageProps {
	/** Report, phase and signal from the URL. */
	route: ReportRoute;
	/** Link target of a route. */
	href: ( route: Route ) => string;
	/** In-app navigation; `replace` keeps the current history entry. */
	onNavigate: ( route: Route, replace?: boolean ) => void;
	/** Move focus to the title once loaded (after in-app navigation). */
	focusHeading: boolean;
	/**
	 * Whether this is the view the page was loaded for: then the report the
	 * admin page printed is used (no REST needed). Default true.
	 */
	initialView?: boolean;
}

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
	initialView = true,
}: ReportPageProps ) {
	const { id } = route;
	// Decided when the report opens: moving between its overview and signal
	// pages (in-app navigation) never reads it again.
	const [ fromPage ] = useState( initialView );
	// The report the page was loaded with first; REST after in-app navigation.
	const load = useCallback(
		() => ( fromPage ? initialReport( id ) : null ) ?? getAnalysis( id ),
		[ id, fromPage ]
	);
	const [ request, reload ] = useRequest( load );
	// Opened in the app while REST fails: load the page again (it prints the
	// report itself) instead of showing the error.
	const reloading = usePageLoadFallback(
		request.status === 'error' ? request.error : null,
		! fromPage
	);
	const history: Route = { view: 'history', page: route.page };
	// A newer report read in the background while results were pending, or by
	// Refresh. It replaces the loaded one in place (the report stays mounted,
	// so the selected phase, expanded findings and focus are kept), and only
	// for the load it was read for.
	const loaded = request.status === 'ready' ? request.data : null;
	const [ latest, setLatest ] = useState< {
		base: AnalysisReport;
		report: AnalysisReport;
	} | null >( null );
	const report =
		latest !== null && latest.base === loaded ? latest.report : loaded;
	const refresh = usePendingRefresh( id, report, ( next ) => {
		if ( loaded !== null && next.id === loaded.id ) {
			setLatest( { base: loaded, report: next } );
		}
	} );

	return (
		<div className="space-y-5">
			{ ( request.status !== 'ready' || route.signal === null ) && (
				<AppLink
					href={ href( history ) }
					onNavigate={ () => onNavigate( history ) }
					className="group -ml-1 inline-flex items-center gap-1.5 rounded px-1 py-0.5 text-sm font-medium text-slate-600 no-underline transition-colors hover:text-primary focus:shadow-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
				>
					<Icon
						name="arrow-left"
						className="size-4 transition-transform duration-150 group-hover:-translate-x-0.5"
					/>
					{ __( 'Update History', 'updatelens' ) }
				</AppLink>
			) }
			{ ( request.status === 'loading' || reloading ) && (
				<ReportSkeleton />
			) }
			{ request.status === 'error' && ! reloading && (
				<LoadError
					error={ request.error }
					message={ __(
						"This report couldn't be loaded.",
						'updatelens'
					) }
					onRetry={ reload }
				/>
			) }
			{ report !== null && (
				<Report
					report={ report }
					route={ route }
					href={ href }
					onNavigate={ onNavigate }
					refresh={ refresh.manual }
					pendingRefresh={ refresh }
					focusHeading={ focusHeading }
				/>
			) }
			{ report !== null && refresh.manual.status !== 'idle' && (
				// Present from the first Refresh on, so its result is announced.
				<p role="status" className="sr-only">
					{ refresh.manual.status === 'refreshing' &&
						__( 'Refreshing report…', 'updatelens' ) }
					{ refresh.manual.status === 'done' &&
						__( 'Report refreshed.', 'updatelens' ) }
				</p>
			) }
		</div>
	);
}

function Report( {
	report,
	route,
	href,
	onNavigate,
	refresh,
	pendingRefresh,
	focusHeading,
}: Omit< ReportPageProps, 'route' | 'initialView' > & {
	report: AnalysisReport;
	route: ReportRoute;
	refresh: ManualRefresh;
	pendingRefresh: PendingRefresh;
} ) {
	const heading = useRef< HTMLHeadingElement >( null );
	// An open, failed, expired or otherwise incomplete analysis explains the
	// results (and offers Refresh while open) before them. A completed one
	// needs no status line: the header badge says so, and how the observation
	// ended is in the technical details.
	const notice = reportNotice( report );
	const showStatus = notice.open || notice.tone !== 'positive';
	// A refresh that completes the analysis removes the status line with its
	// button: if it had focus, focus moves to the title instead of being lost.
	const statusFocused = useRef( false );
	useEffect( () => {
		if ( ! showStatus && statusFocused.current ) {
			statusFocused.current = false;
			if (
				document.activeElement === null ||
				document.activeElement === document.body
			) {
				heading.current?.focus();
			}
		}
	}, [ showStatus ] );
	const onStatusFocus = () => {
		statusFocused.current = true;
	};
	// Counts come from the summaries only; computed once per loaded report.
	const counts = useMemo(
		() => reportChangeCounts( report.phases ),
		[ report.phases ]
	);
	// The default phase is the report's, so overview and details agree. It
	// is kept once shown: a background update never switches the phase.
	const initial = defaultPhase( report.phases, counts );
	const [ shown, setShown ] = useState( initial );
	if ( shown === null && initial !== null ) {
		setShown( initial );
	}
	const selected =
		initial === null ? null : ( route.phase ?? shown ?? initial );
	const { signal } = route;

	// A located item (from a Potential Impact link) applies to the Net result
	// of a signal's details only.
	const item =
		signal !== null && selected === 'final' ? ( route.item ?? null ) : null;

	// Expanded Potential Impact groups survive a visit to a signal page.
	const [ impactView, setImpactView ] = useState( COLLAPSED_IMPACT_VIEW );
	// The finding last opened from the overview (its index): back on the
	// overview, focus returns to its link instead of the title.
	const returnTo = useRef< number | null >( null );
	// Site response checks and their Potential Impact findings link to each
	// other: the target is expanded and receives focus.
	const expand = ( key: string, visible: number, focus: string ) => {
		if ( impactView.open[ key ] === undefined ) {
			setImpactView( {
				...impactView,
				open: { ...impactView.open, [ key ]: visible },
			} );
		}
		focusSoon( focus );
	};
	const showSiteCheck = ( check: SiteCheckId ) =>
		expand( siteCheckViewKey( check ), 1, siteCheckButtonId( check ) );
	const showSiteFinding = ( check: SiteCheckId ) =>
		expand(
			`site_check:${ check }`,
			DEFAULT_VISIBLE_FINDINGS,
			siteCheckFindingButtonId( check )
		);

	// After in-app navigation (also between overview and details), focus
	// moves to the view's title. Switching phases keeps focus on the tab.
	// With a located item, its first row is scrolled into view.
	useEffect( () => {
		if ( signal === null && returnTo.current !== null ) {
			const link = heading.current
				?.closest( 'article' )
				?.querySelector< HTMLElement >(
					`[data-updatelens-finding="${ returnTo.current }"]`
				);
			returnTo.current = null;
			if ( link ) {
				link.focus( { preventScroll: true } );
				link.scrollIntoView?.( { block: 'center' } );
				return;
			}
		}
		if ( focusHeading ) {
			heading.current?.focus( { preventScroll: item !== null } );
		}
		if ( item !== null ) {
			heading.current
				?.closest( 'article' )
				?.querySelector( '[data-updatelens-located]' )
				?.scrollIntoView?.( { block: 'center' } );
		}
	}, [ focusHeading, signal, item ] );

	const at = ( next: Partial< ReportRoute > ): ReportRoute => ( {
		...route,
		...next,
	} );
	const selectPhase = ( phase: PhaseKey ) =>
		onNavigate( at( { phase, item: null } ), true );
	const findingRoute = ( finding: ImpactFinding ): ReportRoute =>
		at( {
			signal: finding.signal,
			phase: 'final',
			item: {
				name: findingSubject( finding ),
				group:
					finding.signal === 'action_scheduler'
						? finding.group
						: null,
				// Options findings may be added or changed rows; the others
				// are about one list.
				kind: findingListKind( finding ),
				// Lets the signal page identify the finding's own rows.
				finding: report.potential_impact.findings.indexOf( finding ),
			},
		} );

	if ( signal !== null ) {
		return (
			<RefinedSignalView
				report={ report }
				signal={ signal }
				selected={ selected }
				counts={ counts }
				heading={ heading }
				item={ item }
				historyRoute={ { view: 'history', page: route.page } }
				overviewRoute={ at( { signal: null, item: null } ) }
				href={ href }
				onNavigate={ onNavigate }
				onSelectPhase={ selectPhase }
				notice={ showStatus ? notice : null }
				refresh={ refresh }
				onStatusFocus={ onStatusFocus }
				onClearItem={ () => onNavigate( at( { item: null } ), true ) }
			/>
		);
	}

	return (
		<article
			aria-labelledby="updatelens-report-title"
			className="space-y-6"
		>
			<ReportHeader report={ report } heading={ heading } />
			{ showStatus && (
				<StatusLine
					notice={ notice }
					refresh={ refresh }
					onFocus={ onStatusFocus }
				/>
			) }
			<ReportGlance
				report={ report }
				counts={ counts }
				onShowImpact={ () => focusSoon( 'updatelens-impact-title' ) }
			/>
			{ /* Its own evidence, the same whatever phase is selected below. */ }
			<SiteResponseChecks
				siteChecks={ report.site_checks }
				impact={ report.potential_impact.site_checks }
				pendingRefresh={ pendingRefresh }
				view={ impactView }
				onViewChange={ setImpactView }
				onShowFinding={ showSiteFinding }
			/>
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
						data={ report.phases[ selected ] }
						phase={ selected }
						counts={ counts[ selected ] }
						windowSeconds={ report.observation_window_seconds }
						signalHref={ ( provider: Provider ) =>
							href( at( { signal: provider } ) )
						}
						onOpenSignal={ ( provider: Provider ) =>
							onNavigate( at( { signal: provider } ) )
						}
						impact={ report.potential_impact }
						siteChecks={ report.site_checks }
						onShowSiteCheck={ showSiteCheck }
						findingHref={ ( finding ) =>
							href( findingRoute( finding ) )
						}
						onOpenFinding={ ( finding ) => {
							returnTo.current =
								report.potential_impact.findings.indexOf(
									finding
								);
							onNavigate( findingRoute( finding ) );
						} }
						impactView={ impactView }
						onImpactViewChange={ setImpactView }
						onShowNetResult={
							// Offered only when the Net result has data to show.
							selected === 'final' || counts.final.total === null
								? null
								: () => selectPhase( 'final' )
						}
					/>
				</PhaseTabs>
			) }
			<TechnicalDetails
				report={ report }
				signal={ null }
				phase={ selected }
			/>
		</article>
	);
}

/**
 * A signal's page in the redesigned look (Options & autoload, WP-Cron):
 * breadcrumb, the signal as title with the report's context, the phases
 * with this signal's counts, and its details.
 *
 * @param props               Props.
 * @param props.report        Report.
 * @param props.signal        Signal of the page.
 * @param props.selected      Selected phase, null if no phase is available.
 * @param props.counts        Change counts of the report.
 * @param props.heading       Ref of the page title (focus after navigation).
 * @param props.item          Rows located from Potential Impact, if any.
 * @param props.historyRoute  Route of the History.
 * @param props.overviewRoute Route of the report's overview.
 * @param props.href          Link target of a route.
 * @param props.onNavigate    In-app navigation.
 * @param props.onSelectPhase Selects a phase (keeps the signal).
 * @param props.notice        Status line, if shown.
 * @param props.refresh       Refresh of the report (open analyses).
 * @param props.onStatusFocus Notes that focus is in the status line.
 * @param props.onClearItem   Removes the located item from the URL.
 */
function RefinedSignalView( {
	report,
	signal,
	selected,
	counts,
	heading,
	item,
	historyRoute,
	overviewRoute,
	href,
	onNavigate,
	onSelectPhase,
	notice,
	refresh,
	onStatusFocus,
	onClearItem,
}: {
	report: AnalysisReport;
	signal: Provider;
	selected: PhaseKey | null;
	counts: ReturnType< typeof reportChangeCounts >;
	heading: RefObject< HTMLHeadingElement >;
	item: RouteItem | null;
	historyRoute: Route;
	overviewRoute: Route;
	href: ( route: Route ) => string;
	onNavigate: ( route: Route, replace?: boolean ) => void;
	onSelectPhase: ( phase: PhaseKey ) => void;
	notice: ReportNotice | null;
	refresh: ManualRefresh;
	onStatusFocus: () => void;
	onClearItem: () => void;
} ) {
	const located = useMemo(
		() => ( item === null ? null : locate( report, signal, item ) ),
		[ report, signal, item ]
	);
	const crumb =
		'rounded font-medium text-primary no-underline hover:underline focus:shadow-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
	const separator = (
		<span aria-hidden="true" className="text-slate-600">
			›
		</span>
	);

	return (
		<div className="space-y-5">
			<nav aria-label={ __( 'Breadcrumb', 'updatelens' ) }>
				<ol className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
					<li className="flex items-center gap-2">
						<AppLink
							href={ href( historyRoute ) }
							onNavigate={ () => onNavigate( historyRoute ) }
							className={ crumb }
						>
							{ __( 'Update History', 'updatelens' ) }
						</AppLink>
						{ separator }
					</li>
					<li className="flex min-w-0 items-center gap-2">
						<AppLink
							href={ href( overviewRoute ) }
							onNavigate={ () => onNavigate( overviewRoute ) }
							className={ cn( crumb, 'break-words' ) }
						>
							{ pluginName( report.plugin ) }
						</AppLink>
						{ separator }
					</li>
					<li
						aria-current="page"
						className="font-medium text-slate-700"
					>
						{ providerLabel( signal ) }
					</li>
				</ol>
			</nav>
			<article
				aria-labelledby="updatelens-signal-title"
				className="space-y-6"
			>
				<header className="flex items-start gap-4">
					<span className="hidden size-12 shrink-0 place-items-center rounded-xl border border-tint-border bg-tint text-primary sm:grid">
						<Icon name={ signal } className="size-6" />
					</span>
					<div className="min-w-0 flex-1">
						<h2
							id="updatelens-signal-title"
							ref={ heading }
							tabIndex={ -1 }
							className="text-[1.75rem] font-semibold leading-tight tracking-tight text-slate-900 outline-none"
						>
							{ providerLabel( signal ) }
						</h2>
						<p className="mt-1.5 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-sm text-muted-foreground">
							<span className="break-words font-medium text-slate-800">
								{ pluginName( report.plugin ) }
							</span>
							<span className="tabular-nums text-slate-600 [&>[aria-hidden=true]]:mx-0.5">
								<Versions plugin={ report.plugin } />
							</span>
							<StatusBadge
								status={ report.status }
								settleOutcome={ report.settle_outcome }
								dot
							/>
						</p>
					</div>
				</header>
				{ notice && (
					<StatusLine
						notice={ notice }
						refresh={ refresh }
						onFocus={ onStatusFocus }
					/>
				) }
				{ located && (
					<LocatedItem
						located={ located }
						signal={ signal }
						onClear={ onClearItem }
					/>
				) }
				{ selected && (
					<LocatedProvider value={ located }>
						<PhaseTabs
							counts={ {
								during_update: counts.during_update[ signal ],
								post_update: counts.post_update[ signal ],
								final: counts.final[ signal ],
							} }
							selected={ selected }
							onSelect={ onSelectPhase }
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
					</LocatedProvider>
				) }
				<TechnicalDetails
					report={ report }
					signal={ signal }
					phase={ selected }
				/>
			</article>
		</div>
	);
}

/**
 * Which rows a Potential Impact link located on this page, with a way to
 * clear the marks. The rows themselves are marked in their lists: as the
 * finding's own only when its evidence identifies them, else neutrally as
 * entries of the same hook.
 *
 * @param props         Props.
 * @param props.located Located rows.
 * @param props.signal  Signal of the page.
 * @param props.onClear Removes the marks.
 */
function LocatedItem( {
	located,
	signal,
	onClear,
}: {
	located: Located;
	signal: Provider;
	onClear: () => void;
} ) {
	const { item, precision, rows, ambiguous } = located;
	const count = rows.size;
	let text: string;
	if ( precision === 'exact' ) {
		if ( signal === 'options' ) {
			text = __(
				'Showing the Net result with this option highlighted:',
				'updatelens'
			);
		} else {
			text =
				count === 1
					? __(
							'Showing the Net result with the entry of this finding highlighted:',
							'updatelens'
						)
					: sprintf(
							/* translators: %s: number of entries (2 or more). */
							__(
								'Showing the Net result with the %s entries of this finding highlighted:',
								'updatelens'
							),
							formatCount( count )
						);
		}
	} else if ( ambiguous ) {
		text = sprintf(
			/* translators: %s: number of entries (2 or more). */
			__(
				'Showing the Net result. %s entries of this hook match this finding. Arguments are not shown, so UpdateLens cannot tell which one the finding refers to; they are marked as entries of the same hook:',
				'updatelens'
			),
			formatCount( count )
		);
	} else if ( count > 0 ) {
		text = __(
			'Showing the Net result. The exact entry of this finding could not be identified; entries of the same hook are marked:',
			'updatelens'
		);
	} else {
		text = __(
			'Showing the Net result. No entry of this finding was found in it:',
			'updatelens'
		);
	}

	return (
		<section
			aria-label={ __( 'Located from Potential Impact', 'updatelens' ) }
			data-precision={ precision }
			className={ cn(
				'flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border px-4 py-3 text-sm',
				precision === 'exact'
					? 'border-tint-border bg-tint'
					: 'bg-card/70'
			) }
		>
			<p className="min-w-0 flex-1 basis-64 leading-relaxed text-slate-700">
				{ text }{ ' ' }
				<code className="break-all bg-transparent p-0 font-mono text-[13px] font-medium text-slate-900">
					{ item.name }
				</code>
				{ item.group !== null && item.group !== '' && (
					<span className="text-muted-foreground">
						{ ' · ' }
						{ sprintf(
							/* translators: %s: Action Scheduler group slug. */
							__( 'Group: %s', 'updatelens' ),
							item.group
						) }
					</span>
				) }
			</p>
			<Button variant="outline" size="sm" onClick={ onClear }>
				{ __( 'Clear highlight', 'updatelens' ) }
			</Button>
		</section>
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
		<header className="flex items-start gap-4">
			{ /* A generic plugin glyph, never a plugin's own logo. */ }
			<span className="hidden size-12 shrink-0 place-items-center rounded-xl border border-tint-border bg-tint text-primary sm:grid">
				<Icon name="plugin" className="size-6" />
			</span>
			<div className="min-w-0 flex-1">
				<div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
					<h2
						id="updatelens-report-title"
						ref={ heading }
						tabIndex={ -1 }
						className="break-words text-[1.75rem] font-semibold leading-tight tracking-tight text-slate-900 outline-none"
					>
						{ pluginName( report.plugin ) }
					</h2>
					<StatusBadge
						status={ report.status }
						settleOutcome={ report.settle_outcome }
						dot
					/>
				</div>
				<p className="mt-1.5 text-[1.0625rem] font-medium tabular-nums text-slate-800 [&>[aria-hidden=true]]:mx-0.5">
					<Versions plugin={ report.plugin } />
				</p>
				{ /* The plugin file is in the technical details. */ }
				{ updated && (
					<p className="mt-1.5 text-sm text-muted-foreground">
						{ sprintf(
							/* translators: %s: date and time of the update. */
							__( 'Updated %s', 'updatelens' ),
							updated
						) }
					</p>
				) }
			</div>
		</header>
	);
}

/**
 * The analysis status: one restrained line with an icon, and Refresh while
 * the analysis is open. Refresh reads the report again in place; while it
 * runs the button stays focusable (`aria-disabled`), and a failure is
 * reported here while the report shown stays.
 *
 * @param props         Props.
 * @param props.notice  Report notice.
 * @param props.refresh Refresh of the report (open analyses).
 * @param props.onFocus Notes that focus is inside.
 */
function StatusLine( {
	notice,
	refresh,
	onFocus,
}: {
	notice: ReportNotice;
	refresh: ManualRefresh;
	onFocus: () => void;
} ) {
	const refreshing = refresh.status === 'refreshing';
	return (
		<section
			aria-label={ __( 'Analysis status', 'updatelens' ) }
			onFocus={ onFocus }
			className="flex flex-wrap items-center gap-x-4 gap-y-3 rounded-xl border bg-card/70 px-4 py-3"
		>
			<div className="flex min-w-0 flex-1 basis-64 gap-3">
				<Icon
					name={ TONE_ICON_NAME[ notice.tone ] }
					className={ cn( 'mt-0.5', TONE_ICON[ notice.tone ] ) }
				/>
				<div className="min-w-0 space-y-0.5 text-sm">
					<p className="font-semibold text-slate-900">
						{ notice.title }
					</p>
					<p className="leading-relaxed text-muted-foreground">
						<span>{ notice.description }</span>
						{ notice.details.map( ( detail ) => (
							<span key={ detail }> { detail }</span>
						) ) }
					</p>
				</div>
			</div>
			{ notice.open && (
				<Button
					variant="outline"
					aria-disabled={ refreshing }
					onClick={ () => {
						if ( ! refreshing ) {
							refresh.refresh();
						}
					} }
				>
					<Icon
						name="progress"
						className={ cn(
							'size-4',
							refreshing && 'motion-safe:animate-spin'
						) }
					/>
					{ refreshing
						? __( 'Refreshing…', 'updatelens' )
						: __( 'Refresh', 'updatelens' ) }
				</Button>
			) }
			{ refresh.status === 'failed' && refresh.error && (
				<p
					role="alert"
					className="basis-full text-sm font-medium leading-relaxed text-slate-800"
				>
					{ refreshErrorText( refresh.error ) }
				</p>
			) }
		</section>
	);
}

/**
 * Why Refresh failed, in fixed words (never the server's message). The
 * report shown is the one loaded before.
 *
 * @param error Failure.
 */
function refreshErrorText( error: ApiError ): string {
	if ( error.kind === 'too_large' ) {
		return reportTooLargeText();
	}
	const cause = requestFailureText( error );
	const text = __(
		"The report couldn't be refreshed. The details shown are from the last successful read.",
		'updatelens'
	);
	return cause ? `${ text } ${ cause }` : text;
}

/**
 * Focus an element once the view that expands it has rendered, and bring it
 * into view.
 *
 * @param id Element id.
 */
function focusSoon( id: string ) {
	setTimeout( () => {
		const element = document.getElementById( id );
		element?.focus( { preventScroll: true } );
		element?.scrollIntoView?.( { block: 'center' } );
	}, 0 );
}

const TONE_ICON_NAME: Record< Tone, IconName > = {
	neutral: 'info',
	positive: 'check',
	progress: 'progress',
	caution: 'caution',
	negative: 'failure',
};

/**
 * Raw codes and timestamps, collapsed. On a signal's details only that
 * signal's phase reasons are listed. On the overview and the redesigned
 * signal pages, the selected phase's description and how the observation
 * ended come first, in plain words.
 *
 * @param props        Props.
 * @param props.report Report.
 * @param props.signal Signal of the detail view; null on the overview.
 * @param props.phase  Selected phase of the overview, if any.
 */
function TechnicalDetails( {
	report,
	signal,
	phase = null,
}: {
	report: AnalysisReport;
	signal: Provider | null;
	phase?: PhaseKey | null;
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

	const visible = rows.filter(
		( [ , value ] ) => value !== null && value !== ''
	);

	const outcome = settleOutcomeText(
		report.settle_outcome,
		report.observation_window_seconds
	);
	const plain: Array< [ string, string ] > = [];
	if ( phase ) {
		plain.push( [
			__( 'Selected phase', 'updatelens' ),
			sprintf(
				/* translators: 1: a label, e.g. an observation phase ("Net result") or a check ("Homepage"). 2: what it says, e.g. "3 changes". */
				__( '%1$s: %2$s', 'updatelens' ),
				phaseLabel( phase ),
				phaseNote( phase )
			),
		] );
	}
	if ( outcome ) {
		plain.push( [ __( 'Observation', 'updatelens' ), outcome ] );
	}

	return (
		<details className="group rounded-xl border bg-card/70 text-sm">
			<summary className="flex cursor-pointer select-none list-none items-center gap-2 rounded-xl px-4 py-3 font-medium text-slate-600 transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&::-webkit-details-marker]:hidden">
				<Icon
					name="chevron-down"
					className="size-4 -rotate-90 text-slate-400 transition-transform duration-150 group-open:rotate-0"
				/>
				{ __( 'Technical details', 'updatelens' ) }
			</summary>
			<TechnicalRows
				plain={ plain }
				rows={ visible }
				className="mx-4 gap-x-8 gap-y-1.5 border-t py-3"
			/>
		</details>
	);
}

/**
 * Rows of the technical details.
 *
 * @param props           Props.
 * @param props.plain     Label and sentence per row, before the codes.
 * @param props.rows      Label, display value and ISO timestamp per row.
 * @param props.className Spacing.
 */
function TechnicalRows( {
	plain = [],
	rows,
	className,
}: {
	plain?: Array< [ string, string ] >;
	rows: Array< [ string, string | null, string | null ] >;
	className: string;
} ) {
	return (
		<dl
			className={ cn(
				'grid sm:grid-cols-[auto_minmax(0,1fr)]',
				className
			) }
		>
			{ plain.map( ( [ label, text ] ) => (
				<div key={ label } className="contents">
					<dt className="text-muted-foreground">{ label }</dt>
					<dd className="leading-5 text-slate-700">{ text }</dd>
				</div>
			) ) }
			{ rows.map( ( [ label, value, iso ] ) => (
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
	);
}

function ReportSkeleton() {
	return (
		<div aria-busy="true" className="space-y-4">
			<span role="status" className="sr-only">
				{ __( 'Loading report…', 'updatelens' ) }
			</span>
			<Skeleton className="h-8 w-64" />
			<Skeleton className="h-4 w-40" />
			<Skeleton className="h-11 w-full max-w-md rounded-xl" />
			<Skeleton className="h-44 w-full rounded-xl" />
			<div className="grid gap-4 md:grid-cols-3">
				{ [ 0, 1, 2 ].map( ( n ) => (
					<Skeleton key={ n } className="h-44 rounded-xl" />
				) ) }
			</div>
		</div>
	);
}
