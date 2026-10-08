import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';

import type { PhaseKey, Provider, ReportPhase } from '../types/api';
import type { PhaseChangeCounts } from '../utils/changes';
import { formatCount } from '../utils/format';
import {
	observedChangesText,
	phaseNote,
	phaseSummarySentences,
	PROVIDERS,
	providerLabel,
	signalStatusText,
	signalUnavailableText,
} from '../utils/labels';
import { AppLink } from './AppLink';

/*
 * The overview of one phase: what it covers, its total of observed changes,
 * a plain summary and one card per signal that opens the signal's details.
 * No change rows here: they belong to the detail views.
 */

/**
 * Overview panel of the selected phase.
 *
 * @param props               Props.
 * @param props.phase         Phase.
 * @param props.data          Phase data of every signal.
 * @param props.counts        Change counts of the phase.
 * @param props.windowSeconds Observation window length from the API.
 * @param props.signalHref    Link target of a signal's details.
 * @param props.onOpenSignal  Opens a signal's details in the app.
 */
export function ReportOverview( {
	phase,
	data,
	counts,
	windowSeconds,
	signalHref,
	onOpenSignal,
}: {
	phase: PhaseKey;
	data: ReportPhase;
	counts: PhaseChangeCounts;
	windowSeconds: number;
	signalHref: ( signal: Provider ) => string;
	onOpenSignal: ( signal: Provider ) => void;
} ) {
	// Without any available signal, the phase's reason comes from Options,
	// the signal that drives the analysis lifecycle.
	const sentences =
		counts.total === null
			? [
					signalUnavailableText( 'options', data, windowSeconds )
						?.description ?? '',
				].filter( Boolean )
			: phaseSummarySentences( counts );

	return (
		<div className="space-y-5">
			<div className="space-y-1.5">
				<p className="text-sm text-muted-foreground">
					{ phaseNote( phase ) }
				</p>
				<p
					className={ cn(
						'tabular-nums',
						( counts.total ?? 0 ) > 0
							? 'text-3xl font-semibold tracking-tight'
							: 'text-lg font-medium'
					) }
				>
					{ observedChangesText( counts.total ) }
				</p>
				{ sentences.length > 0 && (
					<p className="text-sm">{ sentences.join( ' ' ) }</p>
				) }
			</div>
			<ul
				aria-label={ __( 'Signals', 'updatelens' ) }
				className="grid gap-3 sm:grid-cols-3"
			>
				{ PROVIDERS.map( ( provider ) => (
					<SignalCard
						key={ provider }
						provider={ provider }
						data={ data }
						count={ counts[ provider ] }
						windowSeconds={ windowSeconds }
						href={ signalHref( provider ) }
						onOpen={ () => onOpenSignal( provider ) }
					/>
				) ) }
			</ul>
		</div>
	);
}

/**
 * One signal of the phase: its state and a short breakdown, as an entry
 * point to its details. The whole card is the link's target; its name is the
 * signal and the state is its description.
 *
 * @param props               Props.
 * @param props.provider      Signal.
 * @param props.data          Phase data of every signal.
 * @param props.count         Change count of the signal, null if unavailable.
 * @param props.windowSeconds Observation window length from the API.
 * @param props.href          Link target of the signal's details.
 * @param props.onOpen        Opens the details in the app.
 */
function SignalCard( {
	provider,
	data,
	count,
	windowSeconds,
	href,
	onOpen,
}: {
	provider: Provider;
	data: ReportPhase;
	count: number | null;
	windowSeconds: number;
	href: string;
	onOpen: () => void;
} ) {
	const statusId = `updatelens-card-${ provider }-status`;
	const detail =
		count === null
			? signalUnavailableText( provider, data, windowSeconds )?.title
			: count > 0
				? signalBreakdown( provider, data )
				: null;

	return (
		<li className="group relative flex flex-col gap-1 rounded-lg border bg-card p-4 transition-colors hover:border-slate-400 hover:bg-muted/40">
			<h3 className="text-sm font-semibold">
				<AppLink
					href={ href }
					onNavigate={ onOpen }
					aria-describedby={ statusId }
					className="text-foreground no-underline outline-none after:absolute after:inset-0 after:rounded-lg focus:shadow-none focus-visible:after:ring-2 focus-visible:after:ring-ring"
				>
					{ providerLabel( provider ) }
				</AppLink>
			</h3>
			<p id={ statusId } className="space-y-0.5">
				<span
					className={ cn(
						'block tabular-nums',
						( count ?? 0 ) > 0
							? 'text-xl font-semibold'
							: 'text-sm font-medium text-muted-foreground'
					) }
				>
					{ signalStatusText( count ) }
				</span>{ ' ' }
				{ detail && (
					<span className="block text-xs text-muted-foreground">
						{ detail }
					</span>
				) }
			</p>
			<span
				aria-hidden="true"
				className="mt-auto pt-2 text-sm font-medium text-primary group-hover:underline"
			>
				{ __( 'View details', 'updatelens' ) } →
			</span>
		</li>
	);
}

/**
 * Non-zero change counts of an available signal ("Added 3 · Changed 2"),
 * from its summary only.
 *
 * @param provider Signal.
 * @param data     Phase data of every signal.
 */
function signalBreakdown( provider: Provider, data: ReportPhase ): string {
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
	return items
		.filter( ( [ , value ] ) => value > 0 )
		.map( ( [ label, value ] ) => `${ label } ${ formatCount( value ) }` )
		.join( ' · ' );
}
