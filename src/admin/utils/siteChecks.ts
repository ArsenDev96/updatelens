import { __, sprintf } from '@wordpress/i18n';

import type { IconName } from '../components/Icon';
import type {
	SiteCheckAttempt,
	SiteCheckComparison,
	SiteCheckState,
	SiteCheckId,
	SiteCheckImpact,
	SiteCheckImpactCheck,
	SiteChecks,
	SiteCheckSide,
} from '../types/api';
import { formatRequestTimes } from './format';
import type { Tone } from './labels';
import {
	afterReasonText,
	beforeReasonText,
	comparisonReasonText,
	crossOriginText,
	laterText,
	routeMissing,
	sideSummaryText,
	SITE_CHECKS,
	siteCheckSubject,
} from './siteCheckImpact';

/*
 * Wording of the report overview's "Site response checks" section: per check
 * the comparison state from the API (`site_checks`), with the kind of change
 * and the reason a check was not used from `potential_impact.site_checks`.
 * Nothing is decided here: a check reads as passed only when the API compared
 * it as passed; missing, pending, inconclusive and unsupported checks never do.
 */

/** Key of an expanded check of the section in the report's ImpactView. */
export const siteCheckViewKey = ( check: SiteCheckId ) =>
	`site_response:${ check }`;

/** Id of a check's toggle button in the section (a link target). */
export const siteCheckButtonId = ( check: SiteCheckId ) =>
	`updatelens-site-check-${ check }`;

/** Id of a site check finding's toggle button in Potential Impact (a link target). */
export const siteCheckFindingButtonId = ( check: SiteCheckId ) =>
	`updatelens-site-finding-${ check }`;

/** How one check is shown: tone, icon, a short status and one sentence. */
export interface SiteCheckRowText {
	tone: Tone;
	icon: IconName;
	status: string;
	detail: string | null;
	/** Whether Potential Impact lists this check as a finding. */
	finding: boolean;
}

/**
 * Name of a check in the section: "Homepage", "WordPress REST API".
 *
 * @param check Check.
 */
export function siteCheckTitle( check: SiteCheckId ): string {
	return check === 'home'
		? __( 'Homepage', 'updatelens' )
		: __( 'WordPress REST API', 'updatelens' );
}

/**
 * How one check ended, from the API's comparison state.
 *
 * @param check      Check.
 * @param comparison The check in the report's `site_checks`.
 * @param evaluation The check in `potential_impact.site_checks`, if present.
 */
export function siteCheckRow(
	check: SiteCheckId,
	comparison: SiteCheckComparison,
	evaluation: SiteCheckImpactCheck | undefined
): SiteCheckRowText {
	const finding = ( evaluation?.finding_count ?? 0 ) > 0;
	const row = (
		tone: Tone,
		icon: IconName,
		status: string,
		detail: string | null
	): SiteCheckRowText => ( { tone, icon, status, detail, finding } );

	if ( evaluation?.status === 'not_applicable' ) {
		return notUsedRow( check, evaluation.reason );
	}
	switch ( comparison.state ) {
		case 'passed_both':
			// What a pass requires is in the expanded details.
			return row(
				'positive',
				'check',
				__( 'Passed before and after', 'updatelens' ),
				null
			);
		case 'newly_failing':
			return row(
				'negative',
				'failure',
				__( 'Passed before, failed after', 'updatelens' ),
				laterText( {
					code: 'site_check_newly_failing',
					after: comparison.after,
				} )
			);
		case 'failing_both':
			return row(
				'negative',
				'failure',
				__( 'Failed before and after', 'updatelens' ),
				__(
					'It already failed before the update, so it is not a new finding.',
					'updatelens'
				)
			);
		case 'recovered':
			return row(
				'positive',
				'check',
				__( 'Failed before, passed after', 'updatelens' ),
				null
			);
		case 'changed':
			return changedRow( evaluation?.change ?? null, finding );
		case 'pending':
			return row(
				'progress',
				'progress',
				__( 'Waiting for the check after the update', 'updatelens' ),
				__(
					'The browser that ran the update is still checking. Results appear here when they arrive.',
					'updatelens'
				)
			);
		case 'not_comparable':
			return row(
				'caution',
				'caution',
				__( 'Could not be compared', 'updatelens' ),
				comparisonReasonText( comparison.reason ?? '', comparison )
			);
		default:
			return comparison.before.available
				? row(
						'caution',
						'caution',
						__(
							'Not compared: no check after the update',
							'updatelens'
						),
						afterReasonText( comparison.after.reason ?? '' )
					)
				: row(
						'caution',
						'caution',
						__(
							'Not compared: no check before the update',
							'updatelens'
						),
						beforeReasonText( comparison.before.reason ?? '' )
					);
	}
}

/**
 * The comparison of one check in two or three words, for the compact row
 * (Before → After → Result). From the API's state only: "Passed before and after" only
 * for a check the API compared as passed.
 *
 * @param comparison The check in the report's `site_checks`.
 * @param evaluation The check in `potential_impact.site_checks`, if present.
 */
export function siteCheckResultLabel(
	comparison: SiteCheckComparison,
	evaluation: SiteCheckImpactCheck | undefined
): string {
	if ( evaluation?.status === 'not_applicable' ) {
		return evaluation.reason === 'cross_origin'
			? __( 'Not supported', 'updatelens' )
			: __( 'Not checked', 'updatelens' );
	}
	switch ( comparison.state ) {
		case 'passed_both':
			return __( 'Passed before and after', 'updatelens' );
		case 'newly_failing':
			return __( 'Newly failing', 'updatelens' );
		case 'failing_both':
			return __( 'Failed before and after', 'updatelens' );
		case 'recovered':
			return __( 'Recovered', 'updatelens' );
		case 'changed':
			switch ( evaluation?.change ) {
				case 'redirect_appeared':
					return __( 'Now redirects', 'updatelens' );
				case 'redirect_disappeared':
					return __( 'No longer redirects', 'updatelens' );
				case 'redirect_then_failure':
					return __( 'Now failing', 'updatelens' );
				default:
					return __( 'Changed', 'updatelens' );
			}
		case 'pending':
			return __( 'Waiting', 'updatelens' );
		default:
			return __( 'Not compared', 'updatelens' );
	}
}

/**
 * One side of a check in a few words: "Passed · HTTP 200", "Waiting" while
 * the later check may still arrive, or "Not run".
 *
 * @param side       Side.
 * @param purpose    `before` or `after`.
 * @param comparison The check's comparison.
 */
export function siteCheckSideCell(
	side: SiteCheckSide,
	purpose: 'before' | 'after',
	comparison: SiteCheckComparison
): string {
	if ( side.available && side.result !== null ) {
		return sideSummaryText( side );
	}
	return purpose === 'after' && comparison.state === 'pending'
		? __( 'Waiting', 'updatelens' )
		: __( 'Not run', 'updatelens' );
}

/** One side of the request time line: its time, or why it has none. */
export interface RequestTimeSide {
	/** "0.31 s", or "Not measured" / "Waiting" / "Not available". */
	text: string;
	measured: boolean;
}

/** A check's request time line (see siteCheckRequestTimes()). */
export interface RequestTimesView {
	before: RequestTimeSide;
	after: RequestTimeSide;
	/** The difference, or why there is none; null unless both were measured. */
	difference: RequestTimeDifference | null;
}

/**
 * `value`: signed ("+0.04 s"); `same`: both durations display alike ("No
 * measured difference", never "0.00 s"); `not_shown`: not equivalent
 * measurements ("Not shown: …").
 */
export interface RequestTimeDifference {
	text: string;
	kind: 'value' | 'same' | 'not_shown';
}

/** States in which the API compared the two sides of a check. */
const COMPARED: ReadonlySet< SiteCheckState > = new Set< SiteCheckState >( [
	'passed_both',
	'newly_failing',
	'failing_both',
	'recovered',
	'changed',
] );

/**
 * Stored reasons (comparison or AFTER side) that say another plugin update
 * ran around this one: it started right after it, was queued with it or
 * overlapped its check after the update.
 */
const OTHER_UPDATE_REASONS: ReadonlySet< string > = new Set( [
	'next_update',
	'queued_update',
	'overlapping_update',
] );

/**
 * The first request of a side, when it measured a complete response: a
 * status arrived and the body was read to its end. Timeouts and network
 * errors (the time until the browser gave up), redirects (the time until the
 * redirect, not a page) and bodies not read completely are not response
 * times.
 *
 * @param side Side.
 */
function measuredRequest( side: SiteCheckSide ): SiteCheckAttempt | null {
	const first = side.available ? side.result?.attempts[ 0 ] : undefined;
	if (
		first === undefined ||
		first.status === null ||
		first.end_marker === 'not_read' ||
		first.body === 'not_read'
	) {
		return null;
	}
	return first;
}

/**
 * How long each side's first request took, and their difference: a
 * presentation of the recorded durations, never a finding, score or
 * threshold. Null when neither side measured a complete response (missing,
 * pending, unsupported, timed out, …): no time is ever made up. The signed
 * difference is shown only for equivalent measurements: a check the API
 * compared, whose first requests got the same HTTP status and the same
 * verdict (a failed or unverified page is not the same response as a
 * verified one); otherwise a short reason replaces it. Every request stays in
 * the row's details.
 *
 * @param comparison The check in the report's `site_checks`.
 * @param locale     Locale for the numbers.
 */
export function siteCheckRequestTimes(
	comparison: SiteCheckComparison,
	locale?: string
): RequestTimesView | null {
	const before = measuredRequest( comparison.before );
	const after = measuredRequest( comparison.after );
	if ( before === null && after === null ) {
		return null;
	}
	const times = formatRequestTimes(
		before?.duration_ms ?? null,
		after?.duration_ms ?? null,
		locale
	);
	const side = (
		attempt: SiteCheckAttempt | null,
		text: string | null,
		purpose: 'before' | 'after'
	): RequestTimeSide => {
		if ( attempt !== null && text !== null ) {
			return { text, measured: true };
		}
		const current = comparison[ purpose ];
		if ( current.available && current.result !== null ) {
			return {
				text: __( 'Not measured', 'updatelens' ),
				measured: false,
			};
		}
		return {
			text:
				purpose === 'after' && comparison.state === 'pending'
					? __( 'Waiting', 'updatelens' )
					: __( 'Not available', 'updatelens' ),
			measured: false,
		};
	};

	let difference: RequestTimesView[ 'difference' ] = null;
	if ( before !== null && after !== null ) {
		if ( ! COMPARED.has( comparison.state ) ) {
			difference = {
				text: __( 'Not shown: check not compared', 'updatelens' ),
				kind: 'not_shown',
			};
		} else if (
			before.status !== after.status ||
			before.verdict !== after.verdict
		) {
			difference = {
				text: __( 'Not shown: different responses', 'updatelens' ),
				kind: 'not_shown',
			};
		} else if ( times.before === times.after ) {
			// Same displayed duration (one shared precision): not "0.00 s".
			difference = {
				text: __( 'No measured difference', 'updatelens' ),
				kind: 'same',
			};
		} else if ( times.difference !== null ) {
			difference = { text: times.difference, kind: 'value' };
		}
	}

	return {
		before: side( before, times.before, 'before' ),
		after: side( after, times.after, 'after' ),
		difference,
	};
}

/**
 * Why a check was not compared, in a few words, for the report summary:
 * "another update ran at the same time" (only when the stored reasons say
 * so), "no check before the update", "no check after the update" or "could
 * not be compared". From the API's comparison state and reasons and which
 * side is missing; the full reasons are in the Site checks section.
 *
 * @param comparison The check in the report's `site_checks`.
 */
export function siteCheckGapText( comparison: SiteCheckComparison ): string {
	if (
		OTHER_UPDATE_REASONS.has( comparison.reason ?? '' ) ||
		OTHER_UPDATE_REASONS.has( comparison.after.reason ?? '' )
	) {
		return __( 'another update ran at the same time', 'updatelens' );
	}
	if ( comparison.state === 'not_comparable' ) {
		return __( 'could not be compared', 'updatelens' );
	}
	return comparison.before.available
		? __( 'no check after the update', 'updatelens' )
		: __( 'no check before the update', 'updatelens' );
}

/**
 * The reason a check was not compared that says how to compare next time
 * (siteCheckNextTimeText()), or null.
 *
 * @param comparison The check's comparison.
 */
export function siteCheckGuidanceReason(
	comparison: SiteCheckComparison
): string | null {
	if ( comparison.state === 'not_comparable' ) {
		return comparison.reason;
	}
	if ( comparison.state === 'not_run' && comparison.before.available ) {
		return comparison.after.reason;
	}
	return null;
}

/**
 * A `changed` comparison (redirects), worded as what was observed.
 *
 * @param change  Kind from the API.
 * @param finding Whether it is a finding.
 */
function changedRow(
	change: SiteCheckImpactCheck[ 'change' ],
	finding: boolean
): SiteCheckRowText {
	switch ( change ) {
		case 'redirect_appeared':
			// A finding that needs review: the same amber as its finding.
			return {
				tone: finding ? 'caution' : 'neutral',
				icon: 'changed',
				status: __( 'Passed before, redirected after', 'updatelens' ),
				detail: __(
					'A redirect is a changed response, not a failure. The browser cannot see where it leads.',
					'updatelens'
				),
				finding,
			};
		case 'redirect_disappeared':
			return {
				tone: 'neutral',
				icon: 'changed',
				status: __( 'Redirected before, passed after', 'updatelens' ),
				detail: null,
				finding,
			};
		case 'redirect_then_failure':
			return {
				tone: 'negative',
				icon: 'failure',
				status: __( 'Redirected before, failed after', 'updatelens' ),
				detail: __(
					'The check before the update was a redirect, not a verified pass, so this is not reported as a newly failing check.',
					'updatelens'
				),
				finding,
			};
		default:
			return {
				tone: 'neutral',
				icon: 'changed',
				status: __( 'Changed', 'updatelens' ),
				detail: null,
				finding,
			};
	}
}

/**
 * A check that was not part of the update.
 *
 * @param check  Check.
 * @param reason `disabled`, `cross_origin` or `not_captured`.
 */
function notUsedRow(
	check: SiteCheckId,
	reason: string | null
): SiteCheckRowText {
	const quiet = ( status: string, detail: string ): SiteCheckRowText => ( {
		tone: 'neutral',
		icon: 'info',
		status,
		detail,
		finding: false,
	} );
	if ( reason === 'cross_origin' ) {
		return quiet(
			__( 'Not supported on this site', 'updatelens' ),
			crossOriginText( check )
		);
	}
	return reason === 'disabled'
		? quiet(
				__( 'Not checked', 'updatelens' ),
				__(
					'Website checks were turned off when this update ran.',
					'updatelens'
				)
			)
		: quiet(
				__( 'Not recorded', 'updatelens' ),
				__(
					'This update was observed before UpdateLens recorded website checks.',
					'updatelens'
				)
			);
}

/**
 * Site checks that were not part of the update at all, as one sentence
 * instead of rows; null when at least one check was used.
 *
 * @param impact     `potential_impact.site_checks`, if present.
 * @param siteChecks The report's `site_checks`, if present.
 */
export function siteChecksUnused(
	impact: SiteCheckImpact | undefined,
	siteChecks: SiteChecks | undefined
): {
	reason: 'disabled' | 'cross_origin' | 'not_captured';
	text: string;
} | null {
	if ( siteChecks === undefined || impact === undefined ) {
		// Reports from before site checks existed in the API.
		return {
			reason: 'not_captured',
			text: __(
				'Website checks were not recorded for this update: it was observed before UpdateLens had them.',
				'updatelens'
			),
		};
	}
	if ( impact.status !== 'not_applicable' ) {
		return null;
	}
	const reasons = SITE_CHECKS.map(
		( check ) => impact.checks[ check ]?.reason
	);
	if ( reasons.every( ( reason ) => reason === 'cross_origin' ) ) {
		return { reason: 'cross_origin', text: crossOriginText( null ) };
	}
	return reasons.includes( 'disabled' )
		? {
				reason: 'disabled',
				text: __(
					'Website checks were turned off when this update ran, so the homepage and the REST API were not checked.',
					'updatelens'
				),
			}
		: {
				reason: 'not_captured',
				text: __(
					'Website checks were not recorded for this update: it was observed before UpdateLens had them.',
					'updatelens'
				),
			};
}

/**
 * How to read a check's details, shown only when it is expanded: what a
 * pass requires, when a failure counts, and what is never counted as passed.
 *
 * @param check Check.
 */
export function siteCheckReadingNote( check: SiteCheckId ): string {
	return check === 'home'
		? __(
				'A pass needs HTTP 200, a response verified as WordPress (the one-time UpdateLens marker came back) and the completion marker (the page loaded to its end). A failure counts only when both requests agree. Missing, inconclusive and unsupported checks never count as passed.',
				'updatelens'
			)
		: __(
				'A pass needs HTTP 200, a response verified as WordPress (the one-time UpdateLens marker came back) and the signed-in user’s data from /wp/v2/users/me. A failure counts only when both requests agree. UpdateLens loads its reports through its own REST routes, so this check can fail while reports still open. Missing, inconclusive and unsupported checks never count as passed.',
				'updatelens'
			);
}

/**
 * The reason both checks share, shown once for the section instead of in
 * each row; null when they differ or a check has none.
 *
 * @param rows Texts of the checks.
 */
export function sharedReason( rows: SiteCheckRowText[] ): string | null {
	const [ first, ...others ] = rows;
	// Why both were not compared, or that both are still waiting.
	const shareable = ( row: SiteCheckRowText ) =>
		row.tone === 'caution' || row.tone === 'progress';
	return first &&
		others.length > 0 &&
		shareable( first ) &&
		first.detail !== null &&
		others.every(
			( other ) =>
				other.tone === first.tone && other.detail === first.detail
		)
		? first.detail
		: null;
}

/**
 * Why one side has no result, for the expanded details.
 *
 * @param side       Side without a result.
 * @param purpose    `before` or `after`.
 * @param comparison The check's comparison (a pending later check).
 */
export function missingSideText(
	side: SiteCheckSide,
	purpose: 'before' | 'after',
	comparison: SiteCheckComparison
): string {
	if ( purpose === 'after' && comparison.state === 'pending' ) {
		return __( 'Still running or not reported yet.', 'updatelens' );
	}
	return purpose === 'before'
		? beforeReasonText( side.reason ?? '' )
		: afterReasonText( side.reason ?? '' );
}

/**
 * A site check evaluation as History and the report both have it: the
 * report's `potential_impact.site_checks`, or a history item's
 * `site_checks` (the same without findings).
 */
export type SiteCheckEvaluation = Pick< SiteCheckImpact, 'status' | 'checks' >;

/** What the site response checks of one update came to, in a few words. */
export interface SiteCheckOutcome {
	kind:
		| 'newly_failing'
		| 'pending'
		| 'passed'
		| 'not_compared'
		| 'partial'
		| 'failing'
		| 'changed'
		| 'compared'
		| 'disabled'
		| 'unsupported'
		| 'not_recorded';
	tone: Tone;
	icon: IconName;
	value: string;
	/**
	 * Each check's own state ("Homepage: newly failing · REST API: …"); null
	 * when both are in the same state, which the value already says.
	 */
	detail: string | null;
	/** Checks listed as findings (newly failing, or now redirecting). */
	findings: SiteCheckId[];
}

/**
 * Check names as a list: "Homepage", "REST API", "Homepage and REST API".
 *
 * @param checks Checks.
 */
function checkNames( checks: SiteCheckId[] ): string {
	return checks.length > 1
		? __( 'Homepage and REST API', 'updatelens' )
		: siteCheckSubject( checks[ 0 ] );
}

/**
 * One check in a few words.
 *
 * @param evaluation The check's evaluation.
 */
function shortState( evaluation: SiteCheckImpactCheck ): string {
	if ( evaluation.status === 'not_applicable' ) {
		return __( 'not supported', 'updatelens' );
	}
	switch ( evaluation.state ) {
		case 'passed_both':
			return __( 'passed before and after', 'updatelens' );
		case 'newly_failing':
			return __( 'newly failing', 'updatelens' );
		case 'failing_both':
			return __( 'failing before and after', 'updatelens' );
		case 'recovered':
			return __( 'failed before, passed after', 'updatelens' );
		case 'changed':
			switch ( evaluation.change ) {
				case 'redirect_appeared':
					return __( 'now redirects', 'updatelens' );
				case 'redirect_disappeared':
					return __(
						'redirected before, passed after',
						'updatelens'
					);
				case 'redirect_then_failure':
					return __(
						'redirected before, failed after',
						'updatelens'
					);
				default:
					return __( 'response changed', 'updatelens' );
			}
		case 'pending':
			return __( 'waiting for results', 'updatelens' );
		default:
			return __( 'not compared', 'updatelens' );
	}
}

/**
 * The outcome of an update's site response checks, for the report summary
 * and History alike: newly failing checks first, then pending results, then
 * whether both passed, none or only some were compared, or they were
 * compared with another result. Checks that were turned off, unsupported on
 * the site or never recorded have their own quiet outcome. A check reads as
 * passed only when the API compared it as passed. Nothing is decided here
 * beyond ordering the API's own states.
 *
 * @param evaluation The evaluation, undefined from an API without it.
 */
export function siteCheckOutcome(
	evaluation: SiteCheckEvaluation | undefined
): SiteCheckOutcome {
	const quiet = (
		kind: SiteCheckOutcome[ 'kind' ],
		value: string
	): SiteCheckOutcome => ( {
		kind,
		tone: 'neutral',
		icon: 'info',
		value,
		detail: null,
		findings: [],
	} );
	if ( evaluation === undefined || evaluation.status === 'not_applicable' ) {
		const reasons = SITE_CHECKS.map(
			( check ) => evaluation?.checks[ check ]?.reason
		);
		if ( reasons.every( ( reason ) => reason === 'cross_origin' ) ) {
			return quiet(
				'unsupported',
				__( 'Not supported on this site', 'updatelens' )
			);
		}
		return reasons.includes( 'disabled' )
			? quiet( 'disabled', __( 'Off for this update', 'updatelens' ) )
			: quiet(
					'not_recorded',
					__( 'Not recorded for this update', 'updatelens' )
				);
	}

	const checks = SITE_CHECKS.filter(
		( check ) => evaluation.checks[ check ] !== undefined
	);
	const of = ( check: SiteCheckId ) => evaluation.checks[ check ];
	// Each check's own state, only when they differ: the same state for both
	// would repeat the outcome ("Passed before and after").
	const states = checks.map( ( check ) => shortState( of( check ) ) );
	const detail =
		new Set( states ).size > 1
			? checks
					.map( ( check, index ) =>
						sprintf(
							/* translators: 1: a label, e.g. an observation phase ("Net result") or a check ("Homepage"). 2: what it says, e.g. "3 changes". */
							__( '%1$s: %2$s', 'updatelens' ),
							siteCheckSubject( check ),
							states[ index ]
						)
					)
					.join( ' · ' )
			: null;
	const findings = checks.filter(
		( check ) => ( of( check ).finding_count ?? 0 ) > 0
	);
	const result = (
		kind: SiteCheckOutcome[ 'kind' ],
		tone: Tone,
		icon: IconName,
		value: string
	): SiteCheckOutcome => ( { kind, tone, icon, value, detail, findings } );

	const failing = checks.filter(
		( check ) => of( check ).state === 'newly_failing'
	);
	if ( failing.length > 0 ) {
		return result(
			'newly_failing',
			'negative',
			'failure',
			sprintf(
				/* translators: %s: check names, e.g. "Homepage" or "Homepage and REST API". */
				__( 'Newly failing right after the update: %s', 'updatelens' ),
				checkNames( failing )
			)
		);
	}
	if ( checks.some( ( check ) => of( check ).status === 'pending' ) ) {
		return result(
			'pending',
			'progress',
			'progress',
			__( 'Waiting for results', 'updatelens' )
		);
	}
	if (
		checks.length > 0 &&
		checks.every( ( check ) => of( check ).state === 'passed_both' )
	) {
		return result(
			'passed',
			'positive',
			'check',
			__( 'Passed before and after', 'updatelens' )
		);
	}
	const compared = checks.filter(
		( check ) => of( check ).status === 'evaluated'
	);
	const notCompared = checks.filter(
		( check ) => of( check ).status === 'not_evaluated'
	);
	if ( compared.length === 0 ) {
		return result(
			'not_compared',
			'caution',
			'caution',
			__( 'Not compared', 'updatelens' )
		);
	}
	if ( notCompared.length > 0 ) {
		return result(
			'partial',
			'caution',
			'caution',
			sprintf(
				/* translators: 1: number of compared checks, 2: number of checks. */
				__( '%1$s of %2$s compared', 'updatelens' ),
				String( compared.length ),
				String( checks.length )
			)
		);
	}
	if ( findings.length > 0 ) {
		return result(
			'changed',
			'caution',
			'changed',
			sprintf(
				/* translators: %s: check names, e.g. "Homepage". */
				__( 'Now redirects: %s', 'updatelens' ),
				checkNames( findings )
			)
		);
	}
	const stillFailing = checks.filter(
		( check ) =>
			of( check ).state === 'failing_both' ||
			of( check ).change === 'redirect_then_failure'
	);
	if ( stillFailing.length > 0 ) {
		return result(
			'failing',
			'caution',
			'failure',
			sprintf(
				/* translators: %s: check names, e.g. "Homepage". */
				__( 'Failing after the update: %s', 'updatelens' ),
				checkNames( stillFailing )
			)
		);
	}
	return result(
		'compared',
		'neutral',
		'site_checks',
		__( 'Compared before and after', 'updatelens' )
	);
}

/**
 * The next step for a check listed as a finding, worded as something to
 * check, never as a cause.
 *
 * @param check      Check.
 * @param evaluation Its evaluation.
 * @param after      Its AFTER side, when known: a route WordPress reported
 *                   as missing gets the same next step as its finding.
 */
export function siteCheckNextStep(
	check: SiteCheckId,
	evaluation: SiteCheckImpactCheck | undefined,
	after?: SiteCheckSide
): string {
	if ( evaluation?.state === 'changed' ) {
		return __( 'Check where the homepage leads now.', 'updatelens' );
	}
	if ( check === 'rest' && after && routeMissing( after ) ) {
		return __(
			'Check whether a setting now hides this route.',
			'updatelens'
		);
	}
	return check === 'home'
		? __( 'Check whether the homepage is accessible now.', 'updatelens' )
		: __(
				'Check the features that use this route, such as the block editor.',
				'updatelens'
			);
}
