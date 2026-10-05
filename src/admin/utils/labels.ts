import { __ } from '@wordpress/i18n';

import type {
	AnalysisPhase,
	AnalysisReport,
	AnalysisStatus,
	PhaseKey,
	SettleOutcome,
	UnavailableReason,
} from '../types/api';

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
				'Changes observed during the first eligible admin lifecycle after the update. Other site activity may also contribute.',
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
 * Phase shown first: Net result, else During update, else After update.
 * Null if no phase is available.
 *
 * @param phases Report phases.
 */
export function defaultPhase(
	phases: Record< PhaseKey, AnalysisPhase >
): PhaseKey | null {
	const order: PhaseKey[] = [ 'final', 'during_update', 'post_update' ];
	return order.find( ( key ) => phases[ key ].available ) ?? null;
}

export function unavailableReasonText( reason: UnavailableReason | string ): {
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
				description: __(
					'No eligible admin request occurred within the 5-minute observation window.',
					'updatelens'
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
 * How the observation window ended, or null if there is nothing useful to say.
 *
 * @param outcome Settle outcome.
 */
export function settleOutcomeText(
	outcome: SettleOutcome | string | null
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
			return __(
				'Post-update observation was not captured within 5 minutes.',
				'updatelens'
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
					description: __(
						'The update itself was analyzed, but no eligible admin request occurred within the 5-minute observation window.',
						'updatelens'
					),
				};
			}
			const outcome = settleOutcomeText( report.settle_outcome );
			return {
				...base,
				tone: 'positive',
				title: __( 'Analysis completed', 'updatelens' ),
				description: __(
					'UpdateLens observed this update during the update request and the first eligible post-update admin lifecycle.',
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
				description: __(
					'UpdateLens is waiting for the first eligible admin page within the 5-minute observation window. Changes observed during the update are already available.',
					'updatelens'
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
