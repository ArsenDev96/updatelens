import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, type MouseEvent } from 'react';

import { Skeleton } from '@/components/ui/skeleton';

import { getAnalyses, PER_PAGE } from '../api/analyses';
import { LoadError } from '../components/LoadError';
import { Pagination } from '../components/Pagination';
import { StatusBadge } from '../components/StatusBadge';
import { Versions } from '../components/Versions';
import { useRequest } from '../hooks/use-request';
import type { AnalysisHistoryItem, PhaseKey } from '../types/api';
import { formatDateTime } from '../utils/format';
import { PHASE_KEYS, phaseLabel } from '../utils/labels';
import { pluginName } from '../utils/plugin';

interface HistoryPageProps {
	page: number;
	/** Link target of a report, for real links (open in new tab, copy). */
	reportHref: ( id: number ) => string;
	onOpenReport: ( id: number ) => void;
	onPageChange: ( page: number ) => void;
	/** Move focus to the heading (after in-app navigation). */
	focusHeading: boolean;
}

const HAS_PHASE: Record<
	PhaseKey,
	keyof Pick<
		AnalysisHistoryItem,
		'has_during_update' | 'has_post_update' | 'has_final'
	>
> = {
	during_update: 'has_during_update',
	post_update: 'has_post_update',
	final: 'has_final',
};

export function HistoryPage( {
	page,
	reportHref,
	onOpenReport,
	onPageChange,
	focusHeading,
}: HistoryPageProps ) {
	const load = useCallback( () => getAnalyses( page, PER_PAGE ), [ page ] );
	const [ request, retry ] = useRequest( load );
	const heading = useRef< HTMLHeadingElement >( null );

	useEffect( () => {
		if ( focusHeading ) {
			heading.current?.focus();
		}
	}, [ focusHeading, page ] );

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

			{ request.status === 'ready' &&
				request.data.items.length === 0 &&
				( request.data.total === 0 ? (
					<EmptyHistory />
				) : (
					<p className="text-sm text-muted-foreground">
						{ __(
							'There are no analyses on this page.',
							'updatelens'
						) }
					</p>
				) ) }

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
				<PhaseAvailability item={ item } />
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

function PhaseAvailability( { item }: { item: AnalysisHistoryItem } ) {
	return (
		<ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
			{ PHASE_KEYS.map( ( phase ) => {
				const stored = item[ HAS_PHASE[ phase ] ];
				return (
					<li
						key={ phase }
						className="inline-flex items-center gap-1"
					>
						<span>{ phaseLabel( phase ) }</span>
						<span
							aria-hidden="true"
							className={ stored ? 'text-emerald-700' : '' }
						>
							{ stored ? '✓' : '–' }
						</span>
						<span className="sr-only">
							{ stored
								? __( 'recorded', 'updatelens' )
								: __( 'not recorded', 'updatelens' ) }
						</span>
					</li>
				);
			} ) }
		</ul>
	);
}

function EmptyHistory() {
	return (
		<div className="rounded-lg border border-dashed p-6 text-sm">
			<p className="font-medium">
				{ __( 'No plugin updates analyzed yet.', 'updatelens' ) }
			</p>
			<p className="mt-1 text-muted-foreground">
				{ __(
					'UpdateLens will automatically analyze supported single-plugin updates made from WordPress admin.',
					'updatelens'
				) }
			</p>
		</div>
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
