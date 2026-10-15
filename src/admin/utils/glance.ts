import { __, _n, sprintf } from '@wordpress/i18n';

import type { IconName } from '../components/Icon';
import type {
	AnalysisReport,
	NotAssessedCategory,
	PhaseKey,
} from '../types/api';
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
import { siteCheckName, siteCheckSubject } from './siteCheckImpact';
import {
	siteCheckGapText,
	siteCheckOutcome,
	type SiteCheckOutcome,
} from './siteChecks';

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
 * What the API could not assess for this report (`assessment.not_assessed`),
 * one line per category the API gave, naming what is affected; never a
 * priority, never "no changes" or "passed". The category is the API's:
 *
 * - `expired`:      "Observation expired: Options & autoload, … and REST API check"
 *                   (why is said once, in the status at the top);
 * - `not_recorded`: "Not recorded for this report: WP-Cron and Action Scheduler";
 * - `unavailable`:  each source with its reason's title;
 * - `not_compared`: "Site checks not compared: Homepage and REST API (no check
 *                   after the update)", the reasons themselves are in the
 *                   Site checks section.
 *
 * Site checks the summary's Site checks row already names as not compared
 * (siteChecksGlance(): its value or each check's state) are left out, so the
 * summary says it once; the API's entries are unchanged.
 *
 * Empty when everything applicable was assessed or is still being observed.
 *
 * @param report Report.
 */
export function notAssessedLines( report: AnalysisReport ): string[] {
	const evaluation =
		report.site_checks === undefined
			? undefined
			: report.potential_impact.site_checks;
	const entries = report.potential_impact.assessment.not_assessed.filter(
		( entry ) =>
			entry.type !== 'site_check' ||
			evaluation === undefined ||
			evaluation.status === 'not_applicable' ||
			evaluation.checks[ entry.name ]?.status !== 'not_evaluated'
	);
	const name = ( entry: ( typeof entries )[ number ] ) =>
		entry.type === 'signal'
			? providerLabel( entry.name )
			: siteCheckName( entry.name );
	const of = ( category: NotAssessedCategory ) =>
		entries.filter( ( entry ) => entry.category === category );
	const lines: string[] = [];

	const expired = of( 'expired' );
	if ( expired.length > 0 ) {
		// Configuration sources and site checks are listed apart (listText()
		// takes at most three names).
		const parts: string[] = [];
		const sources = expired.filter( ( entry ) => entry.type === 'signal' );
		if ( sources.length > 0 ) {
			parts.push( listText( sources.map( name ) ) );
		}
		const checks = expired.flatMap( ( entry ) =>
			entry.type === 'site_check'
				? [ siteCheckSubject( entry.name ) ]
				: []
		);
		if ( checks.length > 0 ) {
			parts.push(
				sprintf(
					/* translators: %s: checks, e.g. "Homepage and REST API". */
					__( '%s checks after the update', 'updatelens' ),
					listText( checks )
				)
			);
		}
		lines.push(
			sprintf(
				/* translators: %s: what was not assessed, e.g. "Options & autoload, WP-Cron and Action Scheduler · Homepage and REST API checks after the update". */
				__( 'Observation expired: %s', 'updatelens' ),
				parts.join( ' · ' )
			)
		);
	}
	const historical = of( 'not_recorded' );
	if ( historical.length > 0 ) {
		lines.push(
			sprintf(
				/* translators: %s: sources the report predates, e.g. "WP-Cron and Action Scheduler". */
				__( 'Not recorded for this report: %s', 'updatelens' ),
				listText( historical.map( name ) )
			)
		);
	}
	for ( const entry of of( 'unavailable' ) ) {
		if ( entry.type !== 'signal' ) {
			continue;
		}
		lines.push(
			entry.name === 'action_scheduler' &&
				entry.reason === 'not_installed'
				? // Absent at one capture only: not confirmed for the whole update.
					actionSchedulerNotDetectedText( false )
				: sprintf(
						/* translators: 1: a label, e.g. an observation phase ("Net result") or a check ("Homepage"). 2: what it says, e.g. "3 changes". */
						__( '%1$s: %2$s', 'updatelens' ),
						name( entry ),
						signalUnavailableText(
							entry.name,
							report.phases.final,
							report.observation_window_seconds
						)?.title ?? __( 'Not available', 'updatelens' )
					)
		);
	}
	// Checks that share how they could not be compared are named together.
	const gaps = new Map< string, string[] >();
	for ( const entry of of( 'not_compared' ) ) {
		const comparison =
			entry.type === 'site_check'
				? report.site_checks?.checks[ entry.name ]
				: undefined;
		if ( entry.type !== 'site_check' || ! comparison ) {
			continue;
		}
		const gap = siteCheckGapText( comparison );
		gaps.set( gap, [
			...( gaps.get( gap ) ?? [] ),
			siteCheckSubject( entry.name ),
		] );
	}
	gaps.forEach( ( checks, gap ) =>
		lines.push(
			sprintf(
				/* translators: 1: checks, e.g. "Homepage and REST API". 2: why, e.g. "no check after the update". */
				__( 'Site checks not compared: %1$s (%2$s)', 'updatelens' ),
				listText( checks ),
				gap
			)
		)
	);
	return lines;
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
				'During update only: the observation after the update expired.',
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
		// A source the report predates (the API's `not_recorded` category) is
		// a historical gap, not a warning.
		if (
			report.potential_impact.assessment.not_assessed.some(
				( entry ) =>
					entry.type === 'signal' &&
					entry.name === provider &&
					entry.category === 'not_recorded'
			)
		) {
			parts.push(
				sprintf(
					/* translators: %s: source, e.g. "WP-Cron". */
					__( '%s not recorded for this report', 'updatelens' ),
					providerLabel( provider )
				)
			);
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
