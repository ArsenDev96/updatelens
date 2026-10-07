import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, type MouseEvent } from 'react';

import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

import { getAnalyses, PER_PAGE } from '../api/analyses';
import { FirstRun } from '../components/FirstRun';
import { LoadError } from '../components/LoadError';
import { MonitoringBoundary } from '../components/MonitoringBoundary';
import { Pagination } from '../components/Pagination';
import { StatusBadge } from '../components/StatusBadge';
import { Versions } from '../components/Versions';
import { useRequest } from '../hooks/use-request';
import type { AnalysisHistoryItem } from '../types/api';
import { historyPhaseState } from '../utils/changes';
import { formatDateTime } from '../utils/format';
import { historyPhaseText, PHASE_KEYS, phaseLabel } from '../utils/labels';
import { pluginName } from '../utils/plugin';

interface HistoryPageProps {
	page: number;
	/** Link target of a report, for real links (open in new tab, copy). */
	reportHref: ( id: number ) => string;
	onOpenReport: ( id: number ) => void;
	onPageChange: ( page: number ) => void;
	/** Move focus to the heading (after in-app navigation). */
	focusHeading: boolean;
	/** Plugins screen URL for the first-run screen; empty if the user cannot open it. */
	pluginsUrl?: string;
}

export function HistoryPage( {
	page,
	reportHref,
	onOpenReport,
	onPageChange,
	focusHeading,
	pluginsUrl = '',
}: HistoryPageProps ) {
	const load = useCallback( () => getAnalyses( page, PER_PAGE ), [ page ] );
	const [ request, retry ] = useRequest( load );
	const heading = useRef< HTMLHeadingElement >( null );
	// Before the first analysis, onboarding replaces the history.
	const firstRun = request.status === 'ready' && request.data.total === 0;

	useEffect( () => {
		if ( focusHeading ) {
			heading.current?.focus();
		}
	}, [ focusHeading, page, firstRun ] );

	if ( firstRun ) {
		return <FirstRun pluginsUrl={ pluginsUrl } headingRef={ heading } />;
	}

	return (
		<section
			aria-labelledby="updatelens-history-title"
			className="space-y-4"
		>
			<div className="space-y-1">
				<h2
					id="updatelens-history-title"
					ref={ heading }
					tabIndex={ -1 }
					className="text-lg font-semibold outline-none"
				>
					{ __( 'Update History', 'updatelens' ) }
				</h2>
				<p className="text-sm text-muted-foreground">
					{ __(
						'Plugin updates analyzed by UpdateLens.',
						'updatelens'
					) }
				</p>
			</div>

			{ request.status === 'loading' && <HistorySkeleton /> }

			{ request.status === 'error' && (
				<LoadError
					error={ request.error }
					message={ __(
						"Update history couldn't be loaded. Try again.",
						'updatelens'
					) }
					onRetry={ retry }
				/>
			) }

			{ request.status === 'ready' && request.data.items.length === 0 && (
				<p className="text-sm text-muted-foreground">
					{ __(
						'There are no analyses on this page.',
						'updatelens'
					) }
				</p>
			) }

			{ request.status === 'ready' && request.data.items.length > 0 && (
				<ul className="divide-y overflow-hidden rounded-lg border bg-card">
					{ request.data.items.map( ( item ) => (
						<li key={ item.id }>
							<HistoryRow
								item={ item }
								href={ reportHref( item.id ) }
								onOpen={ onOpenReport }
							/>
						</li>
					) ) }
				</ul>
			) }

			{ /* The oldest analyses are on the last page: monitoring began before them. */ }
			{ request.status === 'ready' &&
				request.data.items.length > 0 &&
				page >= request.data.totalPages && <MonitoringBoundary /> }

			{ request.status === 'ready' && (
				<Pagination
					page={ page }
					totalPages={ request.data.totalPages }
					onChange={ onPageChange }
				/>
			) }
		</section>
	);
}

function HistoryRow( {
	item,
	href,
	onOpen,
}: {
	item: AnalysisHistoryItem;
	href: string;
	onOpen: ( id: number ) => void;
} ) {
	const onClick = ( event: MouseEvent< HTMLAnchorElement > ) => {
		// Let the browser handle new-tab/window clicks.
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
		onOpen( item.id );
	};
	const date = formatDateTime( item.timestamps.started_at );

	return (
		<a
			href={ href }
			onClick={ onClick }
			className="group grid gap-3 px-4 py-3 text-foreground no-underline outline-none transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
		>
			<div className="min-w-0 space-y-1">
				<div className="flex flex-wrap items-center gap-x-3 gap-y-1">
					<span className="break-words font-medium">
						{ pluginName( item.plugin ) }
					</span>
					<StatusBadge status={ item.status } />
				</div>
				<p className="font-mono text-xs text-muted-foreground">
					<Versions plugin={ item.plugin } />
				</p>
				<PhaseChanges item={ item } />
			</div>
			<div className="flex items-center justify-between gap-4 text-sm sm:flex-col sm:items-end sm:justify-center sm:gap-1">
				{ date && (
					<time
						dateTime={ item.timestamps.started_at ?? undefined }
						className="text-muted-foreground"
					>
						{ date }
					</time>
				) }
				<span className="font-medium text-primary group-hover:underline">
					{ __( 'View report', 'updatelens' ) }{ ' ' }
					<span aria-hidden="true">→</span>
				</span>
			</div>
		</a>
	);
}

/**
 * Per phase: whether changes were observed, none were, or nothing was
 * recorded. Recorded without changes is never shown like a change.
 *
 * @param props      Props.
 * @param props.item History item.
 */
function PhaseChanges( { item }: { item: AnalysisHistoryItem } ) {
	return (
		<ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
			{ PHASE_KEYS.map( ( phase ) => {
				const state = historyPhaseState( item.phases[ phase ] );
				return (
					<li
						key={ phase }
						className="inline-flex items-baseline gap-1.5"
					>
						<span className="text-muted-foreground">
							{ phaseLabel( phase ) }
							<span className="sr-only">:</span>
						</span>
						<span
							className={ cn(
								state === 'changes'
									? 'font-medium text-foreground'
									: 'text-muted-foreground'
							) }
						>
							{ historyPhaseText( state ) }
						</span>
					</li>
				);
			} ) }
		</ul>
	);
}

function HistorySkeleton() {
	return (
		<div aria-busy="true" className="divide-y rounded-lg border bg-card">
			<span role="status" className="sr-only">
				{ __( 'Loading update history…', 'updatelens' ) }
			</span>
			{ [ 0, 1, 2 ].map( ( row ) => (
				<div key={ row } className="space-y-2 px-4 py-3">
					<Skeleton className="h-4 w-48" />
					<Skeleton className="h-3 w-24" />
					<Skeleton className="h-3 w-64 max-w-full" />
				</div>
			) ) }
		</div>
	);
}
