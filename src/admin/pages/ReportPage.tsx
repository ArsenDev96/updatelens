import { __, sprintf } from '@wordpress/i18n';
import {
	useCallback,
	useEffect,
	useRef,
	useState,
	type MouseEvent,
} from 'react';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

import { getAnalysis } from '../api/analyses';
import { LoadError } from '../components/LoadError';
import { OptionDiffList } from '../components/OptionDiffList';
import { PhaseSummary } from '../components/PhaseSummary';
import { PhaseTabs } from '../components/PhaseTabs';
import { StatusBadge } from '../components/StatusBadge';
import { Versions } from '../components/Versions';
import { useRequest } from '../hooks/use-request';
import type { AnalysisPhase, AnalysisReport, PhaseKey } from '../types/api';
import { formatDateTime } from '../utils/format';
import {
	defaultPhase,
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
	const initial = defaultPhase( report.phases );
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
					phases={ report.phases }
					selected={ selected }
					onSelect={ setChosen }
				>
					<PhasePanel
						phase={ selected }
						data={ report.phases[ selected ] }
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
}: {
	phase: PhaseKey;
	data: AnalysisPhase;
} ) {
	return (
		<div className="space-y-4">
			<p className="text-sm text-muted-foreground">
				{ phaseNote( phase ) }
			</p>
			{ data.available ? (
				<>
					<PhaseSummary summary={ data.summary } />
					<OptionDiffList phase={ data } />
				</>
			) : (
				<UnavailablePhase reason={ data.reason } />
			) }
		</div>
	);
}

function UnavailablePhase( { reason }: { reason: string } ) {
	const text = unavailableReasonText( reason );
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
