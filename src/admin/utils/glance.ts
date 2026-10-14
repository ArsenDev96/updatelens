import { __, _n, sprintf } from '@wordpress/i18n';

import type { IconName } from '../components/Icon';
import type { AnalysisReport, PhaseKey } from '../types/api';
import type { PhaseChangeCounts } from './changes';
import { formatCount } from './format';
import {
	actionSchedulerNotDetectedText,
	listText,
	PROVIDERS,
	phaseLabel,
	providerLabel,
	signalUnavailableText,
	type Tone,
} from './labels';
import { siteCheckOutcome, type SiteCheckOutcome } from './siteChecks';

/*
 * Wording of the report overview's summary (ReportSummary) and of the
 * configuration section's coverage line: separate facts, never one verdict
 * about the update. Configuration changes (the Net result total), the site
 * checks (their outcome, shared with History), what could not be assessed
 * (from the API's assessment) and the observation coverage of the
 * configuration sources. Everything comes from the API's states and summary
 * counts; nothing here is a judgement of the update.
 */

/** One item of the summary. */
export interface GlanceItem {
	tone: Tone;
	icon: IconName;
	value: string;
	detail: string | null;
}

/**
 * Configuration changes: the Net result's total with the signals that
 * changed, or why the Net result is not available (with the changes of a
 * phase that is).
 *
 * @param report Report.
 * @param counts Change counts per phase.
 */
export function configurationGlance(
	report: AnalysisReport,
	counts: Record< PhaseKey, PhaseChangeCounts >
): GlanceItem {
	const total = counts.final.total;
	if ( total === null ) {
		const other = ( [ 'during_update', 'post_update' ] as PhaseKey[] ).find(
			( phase ) => counts[ phase ].total !== null
		);
		const reason = signalUnavailableText(
			'options',
			report.phases.final,
			report.observation_window_seconds
		)?.title;
		return {
			tone: 'neutral',
			icon: 'info',
			value: __( 'Net result not available', 'updatelens' ),
			detail:
				other !== undefined
					? sprintf(
							/* translators: 1: a label, e.g. an observation phase ("Net result") or a check ("Homepage"). 2: what it says, e.g. "3 changes". */
							__( '%1$s: %2$s', 'updatelens' ),
							phaseLabel( other ),
							changeCount( counts[ other ].total ?? 0 )
						)
					: ( reason ?? null ),
		};
	}
	const changed = PROVIDERS.filter(
		( provider ) => ( counts.final[ provider ] ?? 0 ) > 0
	);
	return {
		tone: 'neutral',
		icon: total > 0 ? 'changed' : 'info',
		value:
			total > 0
				? sprintf(
						/* translators: %s: number of observed changes. */
						_n(
							'%s change observed',
							'%s changes observed',
							total,
							'updatelens'
						),
						formatCount( total )
					)
				: __( 'No changes observed', 'updatelens' ),
		detail:
			changed.length > 0
				? sprintf(
						/* translators: %s: signals with changes, e.g. "Options & autoload and WP-Cron". */
						__( 'Net result · %s', 'updatelens' ),
						listText( changed.map( providerLabel ) )
					)
				: __(
						'Net result: before the update compared with after it',
						'updatelens'
					),
	};
}

/**
 * "3 changes".
 *
 * @param count Count.
 */
function changeCount( count: number ): string {
	return sprintf(
		/* translators: %s: number of observed changes. */
		_n( '%s change', '%s changes', count, 'updatelens' ),
		formatCount( count )
	);
}

/**
 * Site response checks: the shared outcome (siteCheckOutcome(), also used by
 * History) of the report's evaluation, with each check's state as detail.
 *
 * @param report Report.
 */
export function siteChecksGlance( report: AnalysisReport ): GlanceItem & {
	outcome: SiteCheckOutcome;
} {
	// An API without `site_checks` recorded none, whatever else it returned.
	const outcome = siteCheckOutcome(
		report.site_checks === undefined
			? undefined
			: report.potential_impact.site_checks
	);
	return {
		tone: outcome.tone,
		icon: outcome.icon,
		value: outcome.value,
		detail: outcome.detail,
		outcome,
	};
}

/**
 * Configuration sources the API could not assess for this report (its
 * `assessment.not_assessed` signals), with why, as one line; null when every
 * applicable source was assessed or is still being observed. Site checks are
 * not listed: the summary's site check row is their own coverage.
 *
 * @param report Report.
 */
export function notAssessedText( report: AnalysisReport ): string | null {
	const parts = report.potential_impact.assessment.not_assessed.flatMap(
		( entry ) => {
			if ( entry.type !== 'signal' ) {
				return [];
			}
			if (
				entry.name === 'action_scheduler' &&
				entry.reason === 'not_installed'
			) {
				// Absent at one capture only: not confirmed for the whole update.
				return [ actionSchedulerNotDetectedText( false ) ];
			}
			return [
				sprintf(
					/* translators: 1: a label, e.g. an observation phase ("Net result") or a check ("Homepage"). 2: what it says, e.g. "3 changes". */
					__( '%1$s: %2$s', 'updatelens' ),
					providerLabel( entry.name ),
					signalUnavailableText(
						entry.name,
						report.phases.final,
						report.observation_window_seconds
					)?.title ?? __( 'Not available', 'updatelens' )
				),
			];
		}
	);
	return parts.length > 0 ? parts.join( ' · ' ) : null;
}

/** One line of the observation coverage. */
export interface CoverageLine {
	label: string;
	tone: Tone;
	icon: IconName;
	text: string;
}

/**
 * Observation coverage of the configuration sources: which were observed in
 * the Net result, which is not installed, which could not be observed and
 * why. Never one "complete". (The site responses' coverage is the site
 * response checks item itself.)
 *
 * @param report Report.
 * @param counts Change counts per phase.
 */
export function coverageLine(
	report: AnalysisReport,
	counts: Record< PhaseKey, PhaseChangeCounts >
): CoverageLine {
	const line = ( tone: Tone, icon: IconName, text: string ) => ( {
		label: __( 'Configuration sources', 'updatelens' ),
		tone,
		icon,
		text,
	} );
	if ( report.status === 'captured' || report.status === 'awaiting_settle' ) {
		return line(
			'progress',
			'progress',
			__(
				'Still observing: the state after the update is not captured yet.',
				'updatelens'
			)
		);
	}
	if ( report.status !== 'completed' ) {
		return line(
			'caution',
			'caution',
			__(
				'The observation did not finish; see the status above.',
				'updatelens'
			)
		);
	}
	if ( report.settle_outcome === 'expired' ) {
		return line(
			'caution',
			'caution',
			__(
				'Observed during the update only: the state after it was not captured in time.',
				'updatelens'
			)
		);
	}

	const final = report.phases.final;
	// Absent at every capture is what Potential Impact confirmed; an older
	// "not installed" from one capture is not confirmed.
	const absent =
		report.potential_impact.signals.action_scheduler.status ===
		'not_applicable';
	const observed = PROVIDERS.filter(
		( provider ) => counts.final[ provider ] !== null
	);
	const parts: string[] = [];
	if ( observed.length > 0 ) {
		parts.push(
			sprintf(
				/* translators: %s: observed sources, e.g. "Options & autoload and WP-Cron". */
				__( '%s observed', 'updatelens' ),
				listText( observed.map( providerLabel ) )
			)
		);
	}
	let gaps = 0;
	for ( const provider of PROVIDERS ) {
		if ( counts.final[ provider ] !== null ) {
			continue;
		}
		if ( provider === 'action_scheduler' && absent ) {
			parts.push( actionSchedulerNotDetectedText( true ) );
			continue;
		}
		gaps++;
		if (
			provider === 'action_scheduler' &&
			! final.action_scheduler.available &&
			final.action_scheduler.reason === 'not_installed'
		) {
			parts.push( actionSchedulerNotDetectedText( false ) );
			continue;
		}
		parts.push(
			sprintf(
				/* translators: 1: source, e.g. "WP-Cron". 2: why, e.g. "Snapshot unavailable". */
				__( '%1$s not available: %2$s', 'updatelens' ),
				providerLabel( provider ),
				signalUnavailableText(
					provider,
					final,
					report.observation_window_seconds
				)?.title ?? __( 'Not available', 'updatelens' )
			)
		);
	}
	return line(
		gaps > 0 ? 'caution' : 'neutral',
		gaps > 0 ? 'caution' : 'check',
		parts.join( ' · ' )
	);
}
