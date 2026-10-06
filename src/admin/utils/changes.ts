import type {
	ActionSchedulerPhase,
	CronPhase,
	HistoryPhase,
	OptionsPhase,
	PhaseKey,
	ReportPhase,
} from '../types/api';

/*
 * Change counts derived from the Reports API summaries. A count is the
 * number of observed records (added + removed + changed, plus rescheduled
 * for WP-Cron and Action Scheduler), never a summary delta. Null means the signal is unavailable,
 * which is not the same as zero changes.
 */

export interface PhaseChangeCounts {
	options: number | null;
	cron: number | null;
	action_scheduler: number | null;
	/** Sum of the available signals; null if none is available. */
	total: number | null;
}

/**
 * Observed option records of a phase, or null if unavailable.
 *
 * @param phase Options phase.
 */
export function optionsChangeCount( phase: OptionsPhase ): number | null {
	if ( ! phase.available ) {
		return null;
	}
	const { added_count, removed_count, changed_count } = phase.summary;
	return added_count + removed_count + changed_count;
}

/**
 * Observed WP-Cron records of a phase, or null if unavailable.
 *
 * @param phase Cron phase.
 */
export function cronChangeCount( phase: CronPhase ): number | null {
	if ( ! phase.available ) {
		return null;
	}
	const { added_count, removed_count, changed_count, rescheduled_count } =
		phase.summary;
	return added_count + removed_count + changed_count + rescheduled_count;
}

/**
 * Observed Action Scheduler records of a phase, or null if unavailable.
 *
 * @param phase Action Scheduler phase.
 */
export function actionSchedulerChangeCount(
	phase: ActionSchedulerPhase
): number | null {
	if ( ! phase.available ) {
		return null;
	}
	const { added_count, removed_count, changed_count, rescheduled_count } =
		phase.summary;
	return added_count + removed_count + changed_count + rescheduled_count;
}

/**
 * Counts of one phase per signal and in total.
 *
 * @param phase Report phase.
 */
export function phaseChangeCounts( phase: ReportPhase ): PhaseChangeCounts {
	const signals = {
		options: optionsChangeCount( phase.options ),
		cron: cronChangeCount( phase.cron ),
		action_scheduler: actionSchedulerChangeCount( phase.action_scheduler ),
	};
	const available = Object.values( signals ).filter(
		( count ): count is number => count !== null
	);
	return {
		...signals,
		total:
			available.length === 0
				? null
				: available.reduce( ( sum, count ) => sum + count, 0 ),
	};
}

/**
 * Counts of every phase of a report.
 *
 * @param phases Report phases.
 */
export function reportChangeCounts(
	phases: Record< PhaseKey, ReportPhase >
): Record< PhaseKey, PhaseChangeCounts > {
	return {
		during_update: phaseChangeCounts( phases.during_update ),
		post_update: phaseChangeCounts( phases.post_update ),
		final: phaseChangeCounts( phases.final ),
	};
}

/** What the history can say about a phase: changes, none, or no recorded signal. */
export type HistoryPhaseState = 'changes' | 'none' | 'unavailable';

/**
 * State of a phase in the history: changes if any recorded signal has
 * changes, none if at least one signal was recorded without changes.
 *
 * @param phase History phase flags.
 */
export function historyPhaseState( phase: HistoryPhase ): HistoryPhaseState {
	const signals = [
		phase.options,
		phase.cron,
		phase.action_scheduler,
	].filter( ( signal ) => signal.recorded );
	if ( signals.length === 0 ) {
		return 'unavailable';
	}
	return signals.some( ( signal ) => signal.has_changes === true )
		? 'changes'
		: 'none';
}
