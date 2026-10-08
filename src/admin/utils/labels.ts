import { _n, __, sprintf } from '@wordpress/i18n';

import type {
	ActionSchedulerUnavailableReason,
	ActionScheduleType,
	AnalysisReport,
	AnalysisStatus,
	CronUnavailableReason,
	OptionsUnavailableReason,
	PhaseKey,
	Provider,
	ReportPhase,
	SettleOutcome,
} from '../types/api';
import {
	reportChangeCounts,
	type HistoryPhaseState,
	type PhaseChangeCounts,
} from './changes';
import { formatCount, formatDuration } from './format';

/*
 * Human text for the API's codes. Wording is observational: phases say when
 * a change was observed, never that a plugin caused it.
 */

export type Tone = 'neutral' | 'positive' | 'progress' | 'caution' | 'negative';

export function statusLabel( status: AnalysisStatus | string ): string {
	switch ( status ) {
		case 'captured':
			return __( 'Updating', 'updatelens' );
		case 'awaiting_settle':
			return __( 'Observing', 'updatelens' );
		case 'completed':
			return __( 'Completed', 'updatelens' );
		case 'failed':
			return __( 'Failed', 'updatelens' );
		case 'incompatible':
			return __( 'Incompatible', 'updatelens' );
		case 'abandoned':
			return __( 'Incomplete', 'updatelens' );
		default:
			return __( 'Unknown', 'updatelens' );
	}
}

export function statusTone( status: AnalysisStatus | string ): Tone {
	switch ( status ) {
		case 'completed':
			return 'positive';
		case 'captured':
		case 'awaiting_settle':
			return 'progress';
		case 'failed':
			return 'negative';
		case 'incompatible':
			return 'caution';
		default:
			return 'neutral';
	}
}

/** Phases in chronological order. */
export const PHASE_KEYS: PhaseKey[] = [
	'during_update',
	'post_update',
	'final',
];

export function phaseLabel( phase: PhaseKey ): string {
	switch ( phase ) {
		case 'during_update':
			return __( 'During update', 'updatelens' );
		case 'post_update':
			return __( 'After update', 'updatelens' );
		case 'final':
			return __( 'Net result', 'updatelens' );
	}
}

export function phaseNote( phase: PhaseKey ): string {
	switch ( phase ) {
		case 'during_update':
			return __(
				'Changes observed while WordPress was performing this plugin update.',
				'updatelens'
			);
		case 'post_update':
			return __(
				'Changes observed shortly after the update, until the next admin page loaded. Other site activity may also contribute.',
				'updatelens'
			);
		case 'final':
			return __(
				'Net difference between the state before the update and the end of the observation window.',
				'updatelens'
			);
	}
}

/**
 * Phase shown first: the first phase with changes (Net result, After
 * update, During update), else the first available one (Net result, During
 * update, After update). Null if no phase is available.
 *
 * @param phases Report phases.
 * @param counts Change counts of the phases (computed if omitted).
 */
export function defaultPhase(
	phases: Record< PhaseKey, ReportPhase >,
	counts: Record< PhaseKey, PhaseChangeCounts > = reportChangeCounts( phases )
): PhaseKey | null {
	const withChanges: PhaseKey[] = [ 'final', 'post_update', 'during_update' ];
	const available: PhaseKey[] = [ 'final', 'during_update', 'post_update' ];
	return (
		withChanges.find( ( key ) => ( counts[ key ].total ?? 0 ) > 0 ) ??
		available.find( ( key ) => counts[ key ].total !== null ) ??
		null
	);
}

/** Signals in display order (the stacked sections of a phase). */
export const PROVIDERS: Provider[] = [ 'options', 'cron', 'action_scheduler' ];

export function providerLabel( provider: Provider ): string {
	switch ( provider ) {
		case 'options':
			return __( 'Options & autoload', 'updatelens' );
		case 'cron':
			return __( 'WP-Cron', 'updatelens' );
		case 'action_scheduler':
			return __( 'Action Scheduler', 'updatelens' );
	}
}

/**
 * "0 changes", "1 change", "4 changes", with WordPress plural forms.
 *
 * @param count Number of observed records.
 */
export function changeCountNoun( count: number ): string {
	return sprintf(
		/* translators: %s: number of observed changes. */
		_n( '%s change', '%s changes', count, 'updatelens' ),
		formatCount( count )
	);
}

/**
 * Indicator after a phase tab label ("4 changes"). The tab's accessible name
 * is its visible text ("Net result, 4 changes"). A null count means the
 * phase is unavailable.
 *
 * @param count Change count, or null if unavailable.
 */
export function changeIndicator( count: number | null ): {
	text: string;
	tone: 'changes' | 'none' | 'unavailable';
} {
	return {
		text:
			count === null
				? __( 'Not available', 'updatelens' )
				: changeCountNoun( count ),
		tone: count === null ? 'unavailable' : count > 0 ? 'changes' : 'none',
	};
}

/**
 * Headline of a phase: "3 observed changes" across its available signals,
 * "No tracked changes…" for none, or "Not available…" if no signal is.
 *
 * @param count Phase change count, or null if no signal is available.
 */
export function observedChangesText( count: number | null ): string {
	if ( count === null ) {
		return __( 'Not available for this phase', 'updatelens' );
	}
	if ( count === 0 ) {
		return __(
			'No tracked changes observed during this phase.',
			'updatelens'
		);
	}
	return sprintf(
		/* translators: %s: number of observed changes across all signals of a phase. */
		_n( '%s observed change', '%s observed changes', count, 'updatelens' ),
		formatCount( count )
	);
}

/**
 * State of one signal in a phase overview: "3 changes", "No changes" or
 * "Not available".
 *
 * @param count Signal change count, or null if unavailable.
 */
export function signalStatusText( count: number | null ): string {
	if ( count === null ) {
		return __( 'Not available', 'updatelens' );
	}
	return count === 0
		? __( 'No changes', 'updatelens' )
		: changeCountNoun( count );
}

/**
 * Signal names as a list: "A", "A and B", "A, B and C".
 *
 * @param items Signal names (at most three).
 */
function listText( items: string[] ): string {
	if ( items.length === 1 ) {
		return items[ 0 ];
	}
	if ( items.length === 2 ) {
		return sprintf(
			/* translators: 1: signal name, e.g. "Options & autoload". 2: signal name, e.g. "WP-Cron". */
			__( '%1$s and %2$s', 'updatelens' ),
			items[ 0 ],
			items[ 1 ]
		);
	}
	return sprintf(
		/* translators: 1, 2, 3: signal names, e.g. "Options & autoload", "WP-Cron", "Action Scheduler". */
		__( '%1$s, %2$s and %3$s', 'updatelens' ),
		items[ 0 ],
		items[ 1 ],
		items[ 2 ]
	);
}

/**
 * Plain sentences under a phase headline: in which signals changes were
 * observed, which were compared without changes and which were not
 * available. Observational only. Empty when no signal is available (the
 * signals explain why) or when nothing changed (the headline says so).
 *
 * @param counts Change counts of the phase.
 */
export function phaseSummarySentences( counts: PhaseChangeCounts ): string[] {
	const named = ( test: ( count: number | null ) => boolean ) =>
		PROVIDERS.filter( ( provider ) => test( counts[ provider ] ) ).map(
			providerLabel
		);
	const changed = named( ( count ) => ( count ?? 0 ) > 0 );
	const unchanged = named( ( count ) => count === 0 );
	const unavailable = named( ( count ) => count === null );

	if ( counts.total === null ) {
		return [];
	}
	const sentences: string[] = [];
	if ( changed.length > 0 ) {
		sentences.push(
			sprintf(
				/* translators: %s: one or more signal names, e.g. "Options & autoload and WP-Cron". */
				__( 'Changes were observed in %s.', 'updatelens' ),
				listText( changed )
			)
		);
		if ( unchanged.length > 0 ) {
			sentences.push(
				sprintf(
					/* translators: %s: one or more signal names, e.g. "Action Scheduler". */
					__( 'No changes were observed in %s.', 'updatelens' ),
					listText( unchanged )
				)
			);
		}
	}
	if ( unavailable.length > 0 ) {
		sentences.push(
			sprintf(
				/* translators: %s: one or more signal names, e.g. "Action Scheduler". */
				_n(
					'%s was not available for this phase.',
					'%s were not available for this phase.',
					unavailable.length,
					'updatelens'
				),
				listText( unavailable )
			)
		);
	}
	return sentences;
}

/**
 * Why a signal is unavailable in a phase, or null if it is available.
 *
 * @param provider      Signal.
 * @param data          Phase data of every signal.
 * @param windowSeconds Observation window length from the API.
 */
export function signalUnavailableText(
	provider: Provider,
	data: ReportPhase,
	windowSeconds: number
): { title: string; description: string } | null {
	switch ( provider ) {
		case 'options':
			return data.options.available
				? null
				: unavailableReasonText( data.options.reason, windowSeconds );
		case 'cron':
			return data.cron.available
				? null
				: cronUnavailableReasonText( data.cron.reason, windowSeconds );
		case 'action_scheduler':
			return data.action_scheduler.available
				? null
				: actionSchedulerUnavailableReasonText(
						data.action_scheduler.reason,
						windowSeconds
					);
	}
}

/**
 * Empty state of a signal that was captured without changes in a phase.
 *
 * @param provider Signal.
 */
export function noChangesText( provider: Provider ): {
	title: string;
	description: string;
} {
	switch ( provider ) {
		case 'options':
			return {
				title: __( 'No option changes observed', 'updatelens' ),
				description: __(
					'UpdateLens captured this phase successfully, but the tracked option state did not change.',
					'updatelens'
				),
			};
		case 'cron':
			return {
				title: __( 'No WP-Cron changes observed', 'updatelens' ),
				description: __(
					'UpdateLens captured this phase successfully, but no scheduled events changed.',
					'updatelens'
				),
			};
		case 'action_scheduler':
			return {
				title: __(
					'No Action Scheduler changes observed',
					'updatelens'
				),
				description: __(
					'UpdateLens captured this phase successfully, but no active scheduled actions changed.',
					'updatelens'
				),
			};
	}
}

/**
 * History wording for a phase.
 *
 * @param state History phase state.
 */
export function historyPhaseText( state: HistoryPhaseState ): string {
	switch ( state ) {
		case 'changes':
			return __( 'Changes observed', 'updatelens' );
		case 'none':
			return __( 'No changes', 'updatelens' );
		case 'unavailable':
			return __( 'Not available', 'updatelens' );
	}
}

/**
 * The observation window as a phrase, e.g. "5 minutes".
 *
 * @param seconds Window length from the API.
 */
export function windowText( seconds: number ): string {
	return formatDuration( seconds );
}

export function unavailableReasonText(
	reason: OptionsUnavailableReason | string,
	windowSeconds: number
): {
	title: string;
	description: string;
} {
	switch ( reason ) {
		case 'update_in_progress':
			return {
				title: __( 'Not available yet', 'updatelens' ),
				description: __(
					"The plugin update hasn't reported back yet.",
					'updatelens'
				),
			};
		case 'awaiting_settle':
			return {
				title: __( 'Not captured yet', 'updatelens' ),
				description: __(
					'Waiting for the first eligible admin request after the update.',
					'updatelens'
				),
			};
		case 'settle_expired':
			return {
				title: __( 'Not captured', 'updatelens' ),
				description: sprintf(
					/* translators: %s: observation window length, e.g. "5 minutes". */
					__(
						'No eligible admin request occurred within %s of the update.',
						'updatelens'
					),
					windowText( windowSeconds )
				),
			};
		case 'update_failed':
			return {
				title: __( 'Not available', 'updatelens' ),
				description: __(
					'Not available because the plugin update failed.',
					'updatelens'
				),
			};
		case 'analysis_failed':
			return {
				title: __( 'Not available', 'updatelens' ),
				description: __(
					"Not available because UpdateLens couldn't complete the analysis.",
					'updatelens'
				),
			};
		case 'fingerprint_context_changed':
			return {
				title: __( 'Comparison stopped', 'updatelens' ),
				description: __(
					"Comparison stopped because the site's fingerprint context changed.",
					'updatelens'
				),
			};
		case 'analysis_abandoned':
			return {
				title: __( 'Not available', 'updatelens' ),
				description: __(
					"Not available because the analysis didn't finish.",
					'updatelens'
				),
			};
		case 'data_corrupt':
			return {
				title: __( 'Unreadable', 'updatelens' ),
				description: __(
					"This stored phase couldn't be read safely.",
					'updatelens'
				),
			};
		default:
			return {
				title: __( 'Not available', 'updatelens' ),
				description: __(
					'No data was recorded for this phase.',
					'updatelens'
				),
			};
	}
}

/**
 * Why a WP-Cron phase is unavailable, in plain text.
 *
 * @param reason        API reason.
 * @param windowSeconds Observation window length from the API.
 */
export function cronUnavailableReasonText(
	reason: CronUnavailableReason | string,
	windowSeconds: number
): { title: string; description: string } {
	const unavailable = __( 'WP-Cron analysis unavailable', 'updatelens' );

	switch ( reason ) {
		case 'malformed_cron_state':
			return {
				title: unavailable,
				description: __(
					"The site's Cron state contained data UpdateLens could not safely normalize.",
					'updatelens'
				),
			};
		case 'snapshot_unavailable':
			return {
				title: unavailable,
				description: __(
					"UpdateLens couldn't capture or read the WP-Cron state this phase needs.",
					'updatelens'
				),
			};
		case 'fingerprint_context_changed':
			return {
				title: __( 'Comparison stopped', 'updatelens' ),
				description: __(
					"WP-Cron comparison stopped because the site's fingerprint context changed.",
					'updatelens'
				),
			};
		case 'settle_expired':
			return {
				title: __( 'Not captured', 'updatelens' ),
				description: sprintf(
					/* translators: %s: observation window length, e.g. "5 minutes". */
					__(
						'Post-update WP-Cron state was not captured within %s of the update.',
						'updatelens'
					),
					windowText( windowSeconds )
				),
			};
		case 'storage_failed':
			return {
				title: unavailable,
				description: __(
					'WP-Cron analysis could not be stored safely for this phase.',
					'updatelens'
				),
			};
		case 'not_captured':
			return {
				title: __( 'Not captured', 'updatelens' ),
				description: __(
					'WP-Cron was not captured for this phase.',
					'updatelens'
				),
			};
		case 'analysis_failed':
			return {
				title: unavailable,
				description: __(
					"UpdateLens couldn't compare the WP-Cron state for this phase.",
					'updatelens'
				),
			};
		case 'update_failed':
			return {
				title: __( 'Not available', 'updatelens' ),
				description: __(
					'WP-Cron analysis is unavailable because the plugin update failed.',
					'updatelens'
				),
			};
		case 'analysis_abandoned':
			return {
				title: __( 'Not available', 'updatelens' ),
				description: __(
					"WP-Cron analysis is unavailable because the analysis didn't finish.",
					'updatelens'
				),
			};
		case 'analysis_ended':
			return {
				title: __( 'Not available', 'updatelens' ),
				description: __(
					'WP-Cron was not observed for this phase because the analysis stopped early.',
					'updatelens'
				),
			};
		case 'update_in_progress':
			return {
				title: __( 'Not available yet', 'updatelens' ),
				description: __(
					"The plugin update hasn't reported back yet.",
					'updatelens'
				),
			};
		case 'awaiting_settle':
			return {
				title: __( 'Not captured yet', 'updatelens' ),
				description: __(
					'Waiting for the first eligible admin request after the update.',
					'updatelens'
				),
			};
		case 'data_corrupt':
			return {
				title: __( 'Unreadable', 'updatelens' ),
				description: __(
					"This stored WP-Cron phase couldn't be read safely.",
					'updatelens'
				),
			};
		default:
			return {
				title: __( 'Not available', 'updatelens' ),
				description: __(
					'No WP-Cron data was recorded for this phase.',
					'updatelens'
				),
			};
	}
}

/**
 * Why an Action Scheduler phase is unavailable, in plain text. A site
 * without Action Scheduler (`not_installed`) is a normal state, never
 * worded as an error.
 *
 * @param reason        API reason.
 * @param windowSeconds Observation window length from the API.
 */
export function actionSchedulerUnavailableReasonText(
	reason: ActionSchedulerUnavailableReason | string,
	windowSeconds: number
): { title: string; description: string } {
	const unavailable = __(
		'Action Scheduler analysis unavailable',
		'updatelens'
	);
	const unsupported = __( 'Action Scheduler not supported', 'updatelens' );

	switch ( reason ) {
		case 'not_installed':
			return {
				title: __( 'Action Scheduler not detected', 'updatelens' ),
				description: __(
					'Action Scheduler was not active for this phase.',
					'updatelens'
				),
			};
		case 'unsupported_store':
			return {
				title: unsupported,
				description: __(
					'Action Scheduler uses a storage configuration UpdateLens does not currently support.',
					'updatelens'
				),
			};
		case 'unsupported_schema':
			return {
				title: unsupported,
				description: __(
					'This Action Scheduler database schema is not supported by this UpdateLens version.',
					'updatelens'
				),
			};
		case 'unsupported_schedule':
			return {
				title: unavailable,
				description: __(
					'An Action Scheduler schedule type could not be normalized safely.',
					'updatelens'
				),
			};
		case 'malformed_action_scheduler_state':
			return {
				title: unavailable,
				description: __(
					'Action Scheduler contained data UpdateLens could not safely normalize.',
					'updatelens'
				),
			};
		case 'snapshot_unavailable':
			return {
				title: unavailable,
				description: __(
					'Action Scheduler state could not be captured for this phase.',
					'updatelens'
				),
			};
		case 'fingerprint_context_changed':
			return {
				title: __( 'Comparison stopped', 'updatelens' ),
				description: __(
					"Action Scheduler comparison stopped because the site's fingerprint context changed.",
					'updatelens'
				),
			};
		case 'settle_expired':
			return {
				title: __( 'Not captured', 'updatelens' ),
				description: sprintf(
					/* translators: %s: observation window length, e.g. "5 minutes". */
					__(
						'Post-update Action Scheduler state was not captured within %s of the update.',
						'updatelens'
					),
					windowText( windowSeconds )
				),
			};
		case 'storage_failed':
			return {
				title: unavailable,
				description: __(
					'Action Scheduler analysis could not be stored safely for this phase.',
					'updatelens'
				),
			};
		case 'not_captured':
			return {
				title: __( 'Not captured', 'updatelens' ),
				description: __(
					'Action Scheduler was not captured for this phase.',
					'updatelens'
				),
			};
		case 'analysis_failed':
			return {
				title: unavailable,
				description: __(
					"UpdateLens couldn't compare the Action Scheduler state for this phase.",
					'updatelens'
				),
			};
		case 'update_failed':
			return {
				title: __( 'Not available', 'updatelens' ),
				description: __(
					'Action Scheduler analysis is unavailable because the plugin update failed.',
					'updatelens'
				),
			};
		case 'analysis_abandoned':
			return {
				title: __( 'Not available', 'updatelens' ),
				description: __(
					"Action Scheduler analysis is unavailable because the analysis didn't finish.",
					'updatelens'
				),
			};
		case 'analysis_ended':
			return {
				title: __( 'Not available', 'updatelens' ),
				description: __(
					'Action Scheduler was not observed for this phase because the analysis stopped early.',
					'updatelens'
				),
			};
		case 'update_in_progress':
			return {
				title: __( 'Not available yet', 'updatelens' ),
				description: __(
					"The plugin update hasn't reported back yet.",
					'updatelens'
				),
			};
		case 'awaiting_settle':
			return {
				title: __( 'Not captured yet', 'updatelens' ),
				description: __(
					'Waiting for the first eligible admin request after the update.',
					'updatelens'
				),
			};
		case 'data_corrupt':
			return {
				title: __( 'Unreadable', 'updatelens' ),
				description: __(
					"This stored Action Scheduler phase couldn't be read safely.",
					'updatelens'
				),
			};
		default:
			return {
				title: __( 'Not available', 'updatelens' ),
				description: __(
					'No Action Scheduler data was recorded for this phase.',
					'updatelens'
				),
			};
	}
}

/**
 * Note shown above Action Scheduler changes: the signal is active state,
 * not execution history, and other plugins queue actions too.
 */
export function actionSchedulerPhaseNote(): string {
	return __(
		'Changes to active (pending or in-progress) Action Scheduler actions observed during this phase. This is scheduled state, not execution history. Other plugins may also queue, run or reschedule actions during the observation window.',
		'updatelens'
	);
}

/**
 * Name of a normalized Action Scheduler schedule type.
 *
 * @param type Schedule type.
 */
export function actionScheduleTypeLabel( type: ActionScheduleType ): string {
	switch ( type ) {
		case 'single':
			return __( 'One-time', 'updatelens' );
		case 'async':
			return __( 'Async', 'updatelens' );
		case 'interval':
			return __( 'Interval', 'updatelens' );
		case 'cron':
			return __( 'Cron schedule', 'updatelens' );
	}
}

/**
 * Note shown above WP-Cron changes: other activity schedules jobs too.
 */
export function cronPhaseNote(): string {
	return __(
		'WP-Cron changes observed during this phase. WordPress core and other plugins may also schedule or reschedule jobs during the observation window.',
		'updatelens'
	);
}

/**
 * How the observation window ended, or null if there is nothing useful to say.
 *
 * @param outcome       Settle outcome.
 * @param windowSeconds Observation window length from the API.
 */
export function settleOutcomeText(
	outcome: SettleOutcome | string | null,
	windowSeconds: number
): string | null {
	switch ( outcome ) {
		case 'admin_shutdown':
			return __(
				'Observation completed on the next admin request.',
				'updatelens'
			);
		case 'next_update':
			return __(
				'Observation completed before another plugin update began.',
				'updatelens'
			);
		case 'expired':
			return sprintf(
				/* translators: %s: observation window length, e.g. "5 minutes". */
				__(
					'Post-update observation was not captured within %s.',
					'updatelens'
				),
				windowText( windowSeconds )
			);
		default:
			return null;
	}
}

/** Error codes that mean UpdateLens, not the update, failed. */
const ANALYSIS_ERROR_CODES = [ 'analysis_error', 'snapshot_corrupt' ];

/**
 * Human text for a stored error code. Unknown (e.g. WordPress updater) codes get a generic text.
 *
 * @param code   Error code.
 * @param status Analysis status.
 */
export function errorText(
	code: string,
	status: AnalysisStatus | string
): string {
	switch ( code ) {
		case 'update_not_completed':
			return __( 'The plugin update did not complete.', 'updatelens' );
		case 'analysis_error':
			return __(
				"UpdateLens couldn't analyze this update.",
				'updatelens'
			);
		case 'snapshot_corrupt':
			return __( "A stored snapshot couldn't be read.", 'updatelens' );
		case 'fingerprint_context_changed':
			return __(
				"The site's fingerprint context changed.",
				'updatelens'
			);
		case 'stale':
			return __(
				'The update never reported completion, so the analysis was abandoned.',
				'updatelens'
			);
		case 'another_update_started':
			return __(
				"Another update ran in the same request, so later observations couldn't be separated from it.",
				'updatelens'
			);
		default:
			return status === 'abandoned'
				? __( "The analysis didn't finish.", 'updatelens' )
				: __( 'The plugin update failed.', 'updatelens' );
	}
}

export interface ReportNotice {
	title: string;
	description: string;
	/** Extra sentences (settle outcome, error). */
	details: string[];
	tone: Tone;
	/** The analysis is still open; a refresh may show more. */
	open: boolean;
}

/**
 * High-level state of a report, shown before the phases.
 *
 * @param report Report.
 */
export function reportNotice( report: AnalysisReport ): ReportNotice {
	const error = report.error
		? errorText( report.error.code, report.status )
		: null;
	const base = { details: [] as string[], open: false };
	const windowLength = windowText( report.observation_window_seconds );

	switch ( report.status ) {
		case 'completed': {
			if ( report.settle_outcome === 'expired' ) {
				return {
					...base,
					tone: 'caution',
					title: __(
						'Post-update observation expired',
						'updatelens'
					),
					description: sprintf(
						/* translators: %s: observation window length, e.g. "5 minutes". */
						__(
							'The update itself was analyzed, but no eligible admin request occurred within %s of the update.',
							'updatelens'
						),
						windowLength
					),
				};
			}
			const outcome = settleOutcomeText(
				report.settle_outcome,
				report.observation_window_seconds
			);
			return {
				...base,
				tone: 'positive',
				title: __( 'Analysis completed', 'updatelens' ),
				description: __(
					'UpdateLens captured changes during the update and shortly afterward.',
					'updatelens'
				),
				details: outcome ? [ outcome ] : [],
			};
		}
		case 'awaiting_settle':
			return {
				...base,
				open: true,
				tone: 'progress',
				title: __( 'Observing post-update activity…', 'updatelens' ),
				description: sprintf(
					/* translators: %s: observation window length, e.g. "5 minutes". */
					__(
						'UpdateLens is waiting for the first eligible admin page within %s of the update. Changes observed during the update are already available.',
						'updatelens'
					),
					windowLength
				),
			};
		case 'captured':
			return {
				...base,
				open: true,
				tone: 'progress',
				title: __( 'Update in progress', 'updatelens' ),
				description: __(
					"The plugin update hasn't reported back yet.",
					'updatelens'
				),
			};
		case 'failed': {
			const analysisFailed =
				!! report.error &&
				ANALYSIS_ERROR_CODES.includes( report.error.code );
			return {
				...base,
				tone: 'negative',
				title: analysisFailed
					? __( "Analysis couldn't be completed", 'updatelens' )
					: __( 'Plugin update failed', 'updatelens' ),
				description: analysisFailed
					? __(
							'UpdateLens stopped before the observation was complete.',
							'updatelens'
						)
					: __(
							'No completed change analysis is available.',
							'updatelens'
						),
				details: error ? [ error ] : [],
			};
		}
		case 'incompatible':
			return {
				...base,
				tone: 'caution',
				title: __( "Analysis couldn't be completed", 'updatelens' ),
				description: __(
					"UpdateLens stopped comparison because the site's fingerprint context changed while the update was being observed. This prevents unrelated value fingerprints from being reported as changes.",
					'updatelens'
				),
			};
		case 'abandoned':
			return {
				...base,
				tone: 'neutral',
				title: __( 'Analysis incomplete', 'updatelens' ),
				description:
					error ?? __( "The analysis didn't finish.", 'updatelens' ),
			};
		default:
			return {
				...base,
				tone: 'neutral',
				title: __( 'Unknown analysis state', 'updatelens' ),
				description: __(
					"This analysis is in a state this version of UpdateLens doesn't recognize.",
					'updatelens'
				),
			};
	}
}
