import type { PhaseKey, Provider, ReportPhase } from '../types/api';
import { ActionSchedulerDetail } from './ActionSchedulerDiff';
import { CronDetail } from './CronDiff';
import { OptionsDetail } from './OptionsDetail';

const DETAILS = {
	options: OptionsDetail,
	cron: CronDetail,
	action_scheduler: ActionSchedulerDetail,
};

/**
 * One signal in one phase, for inspection: with changes its summary, every
 * change row, notes and site totals; otherwise a calm empty or unavailable
 * state.
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
	const Detail = DETAILS[ provider ];
	return (
		<Detail
			phase={ phase }
			data={ data }
			count={ count }
			windowSeconds={ windowSeconds }
		/>
	);
}
