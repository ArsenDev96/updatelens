import type { PhaseKey, Provider, ReportPhase } from '../types/api';
import {
	cronPhaseNote,
	noChangesText,
	observedChangesText,
	phaseNote,
	signalUnavailableText,
} from '../utils/labels';
import { ActionSchedulerChanges } from './ActionSchedulerDiff';
import { CronDiffList, CronSummary } from './CronDiff';
import { OptionDiffList } from './OptionDiffList';
import { OptionsSummary } from './PhaseSummary';

/**
 * One signal in one phase, for inspection: with changes its total, counts,
 * site totals, notes and every change row; otherwise a calm empty or
 * unavailable state.
 *
 * @param props               Props.
 * @param props.provider      Signal.
 * @param props.phase         Phase.
 * @param props.data          Phase data of every signal.
 * @param props.count         Change count of the signal, null if unavailable.
 * @param props.windowSeconds Observation window length from the API.
 */
export function SignalDetail( {
	provider,
	phase,
	data,
	count,
	windowSeconds,
}: {
	provider: Provider;
	phase: PhaseKey;
	data: ReportPhase;
	count: number | null;
	windowSeconds: number;
} ) {
	const empty =
		count === null
			? signalUnavailableText( provider, data, windowSeconds )
			: count === 0
				? noChangesText( provider )
				: null;

	return (
		<div className="space-y-5">
			<p className="text-sm text-muted-foreground">
				{ phaseNote( phase ) }
			</p>
			{ empty ? (
				<div className="space-y-1 rounded-lg border border-dashed px-4 py-5 text-sm">
					<p className="font-medium">{ empty.title }</p>
					<p className="text-muted-foreground">
						{ empty.description }
					</p>
				</div>
			) : (
				<>
					<p className="text-3xl font-semibold tabular-nums tracking-tight">
						{ observedChangesText( count ) }
					</p>
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
							<ActionSchedulerChanges
								phase={ data.action_scheduler }
							/>
						) }
				</>
			) }
		</div>
	);
}
