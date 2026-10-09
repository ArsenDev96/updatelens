import { __ } from '@wordpress/i18n';
import { Fragment } from 'react';

import { cn } from '@/lib/utils';

import type {
	ImpactFinding,
	PhaseKey,
	PotentialImpact as PotentialImpactData,
	Provider,
	ReportPhase,
} from '../types/api';
import type { PhaseChangeCounts } from '../utils/changes';
import type { ImpactView } from '../utils/impact';
import { formatCount } from '../utils/format';
import {
	observedChangesText,
	phaseSummarySentences,
	PROVIDERS,
	providerLabel,
	signalStatusText,
	signalUnavailableText,
} from '../utils/labels';
import { AppLink } from './AppLink';
import { Icon } from './Icon';
import { PotentialImpact } from './PotentialImpact';

/*
 * The overview of one phase: a summary panel with its total of observed
 * changes and plain sentences, Potential Impact (always the Net result),
 * then one card per signal that opens the signal's details. No change rows
 * here: they belong to the detail views.
 */

/**
 * Overview panel of the selected phase.
 *
 * @param props               Props.
 * @param props.data          Phase data of every signal.
 * @param props.counts        Change counts of the phase.
 * @param props.windowSeconds Observation window length from the API.
 * @param props.signalHref      Link target of a signal's details.
 * @param props.onOpenSignal    Opens a signal's details in the app.
 * @param props.impact          Potential Impact of the report (Net result).
 * @param props.findingHref     Link target of a finding's signal details.
 * @param props.onOpenFinding   Opens a finding's signal details in the app.
 * @param props.onShowNetResult Selects the Net result phase; null if selected.
 * @param props.phase           Selected phase (Potential Impact says when it is not the Net result).
 * @param props.impactView      Expanded Potential Impact groups and disclosures.
 * @param props.onImpactViewChange Updates them.
 */
export function ReportOverview( {
	data,
	counts,
	windowSeconds,
	signalHref,
	onOpenSignal,
	impact,
	findingHref,
	onOpenFinding,
	onShowNetResult,
	phase,
	impactView,
	onImpactViewChange,
}: {
	data: ReportPhase;
	counts: PhaseChangeCounts;
	windowSeconds: number;
	signalHref: ( signal: Provider ) => string;
	onOpenSignal: ( signal: Provider ) => void;
	impact: PotentialImpactData;
	findingHref: ( finding: ImpactFinding ) => string;
	onOpenFinding: ( finding: ImpactFinding ) => void;
	onShowNetResult: ( () => void ) | null;
	phase: PhaseKey;
	impactView: ImpactView;
	onImpactViewChange: ( view: ImpactView ) => void;
} ) {
	return (
		<div className="space-y-4">
			<UpdateSummary
				data={ data }
				counts={ counts }
				windowSeconds={ windowSeconds }
			/>
			<PotentialImpact
				impact={ impact }
				windowSeconds={ windowSeconds }
				findingHref={ findingHref }
				onOpenFinding={ onOpenFinding }
				onShowNetResult={ onShowNetResult }
				selectedPhase={ phase }
				view={ impactView }
				onViewChange={ onImpactViewChange }
			/>
			<ul
				aria-label={ __( 'Signals', 'updatelens' ) }
				className="grid gap-4 md:grid-cols-3"
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
 * The phase's result: its total of observed changes, where they were
 * observed, and that observations are not causes. The phase itself is named
 * by the selected tab; its description is in the technical details.
 *
 * @param props               Props.
 * @param props.data          Phase data of every signal.
 * @param props.counts        Change counts of the phase.
 * @param props.windowSeconds Observation window length from the API.
 */
function UpdateSummary( {
	data,
	counts,
	windowSeconds,
}: {
	data: ReportPhase;
	counts: PhaseChangeCounts;
	windowSeconds: number;
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
	const hasChanges = ( counts.total ?? 0 ) > 0;

	return (
		<section
			aria-labelledby="updatelens-summary-title"
			className="rounded-xl border border-tint-border bg-tint px-5 py-5 sm:px-8 sm:py-7"
		>
			<h3
				id="updatelens-summary-title"
				className="text-xs font-semibold uppercase tracking-[0.08em] text-tint-foreground"
			>
				{ __( 'Update summary', 'updatelens' ) }
			</h3>
			<p
				className={ cn(
					'mt-2 tabular-nums tracking-tight text-slate-900',
					hasChanges
						? 'text-[2rem] font-semibold leading-tight sm:text-[2.5rem]'
						: 'text-2xl font-semibold leading-snug'
				) }
			>
				{ observedChangesText( counts.total ) }
			</p>
			{ sentences.length > 0 && (
				<p className="mt-2 max-w-3xl text-base leading-relaxed text-slate-700 sm:mt-3">
					{ sentences.join( ' ' ) }
				</p>
			) }
			<p className="mt-5 flex gap-2 border-t border-tint-border pt-4 text-[13px] leading-5 text-slate-600 sm:mt-6">
				<Icon name="info" className="mt-0.5 size-4 text-slate-500" />
				<span>
					{ __(
						'Reports show changes observed during the update window. They do not necessarily come from the updated plugin.',
						'updatelens'
					) }
				</span>
			</p>
		</section>
	);
}

/**
 * What a signal compares, in a few words.
 *
 * @param provider Signal.
 */
function signalDescription( provider: Provider ): string {
	switch ( provider ) {
		case 'options':
			return __(
				'Stored settings in wp_options, including autoloaded data.',
				'updatelens'
			);
		case 'cron':
			return __( 'Events scheduled with WordPress cron.', 'updatelens' );
		case 'action_scheduler':
			return __(
				'Pending and in-progress background actions.',
				'updatelens'
			);
	}
}

/**
 * One signal of the phase: its state and a short breakdown, as an entry
 * point to its details. The whole card is the link's target; its name is the
 * signal and the state is its description. Cards without changes stay
 * quieter than cards with changes.
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
	const hasChanges = ( count ?? 0 ) > 0;
	const breakdown = hasChanges ? signalBreakdown( provider, data ) : [];
	const unavailable =
		count === null
			? signalUnavailableText( provider, data, windowSeconds )?.title
			: null;

	return (
		<li
			className={ cn(
				// Phones: the link text sits beside the state, no footer row. From
				// md up: a column with the link as the card's footer.
				'group relative grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 rounded-xl border p-4 transition-[border-color,box-shadow,background-color] duration-150 sm:p-5 md:flex md:flex-col',
				hasChanges
					? 'bg-card shadow-surface hover:border-tint-border hover:shadow-raised'
					: 'bg-card/60 hover:border-slate-300 hover:bg-card'
			) }
		>
			<div className="col-span-2 flex items-start gap-3">
				<span
					className={ cn(
						'grid size-10 shrink-0 place-items-center rounded-lg',
						hasChanges
							? 'bg-tint-strong text-primary'
							: 'bg-slate-100 text-slate-500'
					) }
				>
					<Icon name={ provider } />
				</span>
				<div className="min-w-0 pt-0.5">
					<h3 className="text-[15px] font-semibold leading-5">
						<AppLink
							href={ href }
							onNavigate={ onOpen }
							aria-describedby={ statusId }
							className="text-foreground no-underline outline-none after:absolute after:inset-0 after:rounded-xl focus:shadow-none focus-visible:after:ring-2 focus-visible:after:ring-ring focus-visible:after:ring-offset-2"
						>
							{ providerLabel( provider ) }
						</AppLink>
					</h3>
					<p className="mt-1 text-[13px] leading-5 text-muted-foreground md:min-h-10">
						{ signalDescription( provider ) }
					</p>
				</div>
			</div>
			<p id={ statusId } className="mt-3 md:mt-5">
				<span
					className={ cn(
						'block tabular-nums',
						hasChanges
							? 'text-2xl font-semibold tracking-tight text-slate-900'
							: 'text-base font-medium text-slate-600'
					) }
				>
					{ signalStatusText( count ) }
				</span>{ ' ' }
				{ breakdown.length > 0 && (
					<span className="mt-1.5 block text-sm text-muted-foreground">
						{ breakdown.map( ( [ label, value ], index ) => (
							<Fragment key={ label }>
								{ index > 0 && ' · ' }
								{ label }{ ' ' }
								<span className="font-semibold tabular-nums text-slate-800">
									{ formatCount( value ) }
								</span>
							</Fragment>
						) ) }
					</span>
				) }
				{ unavailable && (
					<span className="mt-1 block text-sm text-muted-foreground">
						{ unavailable }
					</span>
				) }
			</p>
			<span
				aria-hidden="true"
				className={ cn(
					'mt-3 block self-start md:mt-auto md:self-stretch md:pt-5',
					// Centered on the state's first line on phones.
					hasChanges ? 'pt-1.5' : 'pt-0.5'
				) }
			>
				<span
					className={ cn(
						'flex items-center gap-1.5 text-sm font-medium md:justify-between md:gap-2 md:border-t md:pt-3',
						hasChanges ? 'text-primary' : 'text-slate-600'
					) }
				>
					{ __( 'View details', 'updatelens' ) }
					<Icon
						name="arrow-right"
						className="size-4 transition-transform duration-150 group-hover:translate-x-0.5"
					/>
				</span>
			</span>
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
