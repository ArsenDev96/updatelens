import { _n, __, sprintf } from '@wordpress/i18n';

import type {
	PotentialImpact,
	SiteCheckAttempt,
	SiteCheckComparison,
	SiteCheckFinding,
	SiteCheckId,
	SiteCheckImpactCheck,
	SiteCheckSide,
	SiteChecks,
} from '../types/api';
import {
	formatCount,
	formatDateTime,
	formatDuration,
	keepTogether,
} from './format';
import type { CheckCategory } from './impact';

/*
 * Wording of site checks in Potential Impact: the API's comparison states,
 * reasons and browser-observed facts mapped to localized text. Nothing is
 * decided here: which checks were compared and which are findings come from
 * the API (`potential_impact.site_checks`).
 *
 * Site checks are observations taken by the administrator's browser around
 * the update, not part of any phase. The wording reports what was observed
 * (statuses, timeouts, markers) and never what caused it: no attribution to
 * WordPress, PHP, the host or the updated plugin, and an HTTP 200 never
 * stands for "everything works".
 */

/** Check identifiers in display order. */
export const SITE_CHECKS: SiteCheckId[] = [ 'home', 'rest' ];

/** Failure reasons described by the HTTP status the browser saw. */
const HTTP_FAILURES = new Set( [
	'server_error',
	'not_found',
	'refused',
	'client_error',
] );

/**
 * Name of a check: "Homepage check", "REST API check".
 *
 * @param check Check.
 */
export function siteCheckName( check: SiteCheckId ): string {
	return check === 'home'
		? __( 'Homepage check', 'updatelens' )
		: __( 'REST API check', 'updatelens' );
}

/**
 * What a check requests, for a finding's source line: "Homepage", "REST API".
 *
 * @param check Check.
 */
export function siteCheckSubject( check: SiteCheckId ): string {
	return check === 'home'
		? __( 'Homepage', 'updatelens' )
		: __( 'REST API', 'updatelens' );
}

/**
 * Site check findings of a Potential Impact evaluation (none for responses
 * from before site checks were evaluated).
 *
 * @param impact API evaluation.
 */
export function siteCheckFindings(
	impact: PotentialImpact
): SiteCheckFinding[] {
	return impact.site_checks?.findings ?? [];
}

/**
 * Whether site checks contributed to Potential Impact: at least one check
 * was compared.
 *
 * @param impact API evaluation.
 */
export function siteChecksEvaluated( impact: PotentialImpact ): boolean {
	const status = impact.site_checks?.status;
	return (
		status === 'evaluated' ||
		status === 'partial' ||
		( status === 'pending' &&
			SITE_CHECKS.some(
				( check ) =>
					impact.site_checks.checks[ check ]?.status === 'evaluated'
			) )
	);
}

/**
 * Whether site checks were part of this update at all (not turned off, not
 * recorded before UpdateLens had them).
 *
 * @param impact API evaluation.
 */
export function siteChecksApplicable( impact: PotentialImpact ): boolean {
	return (
		impact.site_checks !== undefined &&
		impact.site_checks.status !== 'not_applicable'
	);
}

/**
 * The failure kind shared by every attempt of a result, or `mixed`.
 *
 * @param side AFTER side.
 */
function failureKind( side: SiteCheckSide ): string {
	const attempts = side.result?.attempts ?? [];
	const reasons = new Set( attempts.map( ( attempt ) => attempt.reason ) );
	if ( reasons.size !== 1 ) {
		return 'mixed';
	}
	const [ reason ] = reasons;
	if ( reason !== null && HTTP_FAILURES.has( reason ) ) {
		return httpStatus( side ) === null ? 'mixed' : 'http';
	}
	return reason ?? 'mixed';
}

/**
 * The HTTP status of every attempt when they agree, else null.
 *
 * @param side Side.
 */
function httpStatus( side: SiteCheckSide ): string | null {
	const statuses = new Set(
		( side.result?.attempts ?? [] ).map( ( attempt ) => attempt.status )
	);
	if ( statuses.size !== 1 ) {
		return null;
	}
	const [ status ] = statuses;
	return status === null ? null : String( status );
}

/**
 * Whether no attempt of a side came back with WordPress verified as its
 * origin although a response was readable.
 *
 * @param side Side.
 */
function unverified( side: SiteCheckSide ): boolean {
	const attempts = side.result?.attempts ?? [];
	return (
		attempts.length > 0 &&
		attempts.every( ( attempt ) => attempt.origin === 'unverified' )
	);
}

/**
 * Title of a site check finding.
 *
 * @param finding Finding.
 */
export function siteCheckFindingTitle( finding: SiteCheckFinding ): string {
	if ( finding.code === 'site_check_newly_failing' ) {
		return finding.check === 'home'
			? __( 'Homepage check newly failing', 'updatelens' )
			: __( 'REST API check newly failing', 'updatelens' );
	}
	// The only change finding: a verified pass became a redirect.
	return __( 'Homepage now redirects', 'updatelens' );
}

/**
 * One short line for a collapsed finding: what the later requests saw and
 * one next step.
 *
 * @param finding Finding.
 */
export function siteCheckSummary( finding: SiteCheckFinding ): string {
	if ( finding.code === 'site_check_changed' ) {
		return __(
			'The homepage redirected twice after the update; it loaded directly before. Check where it leads now.',
			'updatelens'
		);
	}
	return finding.check === 'home'
		? homeFailureLine( finding.after )
		: restFailureLine( finding.after );
}

/**
 * Collapsed line of a homepage failure.
 *
 * @param after AFTER side.
 */
function homeFailureLine( after: SiteCheckSide ): string {
	switch ( failureKind( after ) ) {
		case 'http':
			return sprintf(
				/* translators: %s: HTTP status code, e.g. "500". */
				__(
					'Homepage returned HTTP %s twice after the update. Check whether it is accessible now.',
					'updatelens'
				),
				httpStatus( after ) ?? ''
			);
		case 'timeout':
			return __(
				'Two homepage requests timed out after the update. Check whether it loads now.',
				'updatelens'
			);
		case 'incomplete':
			return __(
				'The homepage did not reach its completion marker in two requests after the update. Check whether the whole page loads.',
				'updatelens'
			);
		default:
			return __(
				'Two homepage requests failed after the update. Check whether it is accessible now.',
				'updatelens'
			);
	}
}

/**
 * Collapsed line of a REST API failure.
 *
 * @param after AFTER side.
 */
function restFailureLine( after: SiteCheckSide ): string {
	switch ( failureKind( after ) ) {
		case 'http':
			return sprintf(
				/* translators: %s: HTTP status code, e.g. "500". */
				__(
					'The REST API returned HTTP %s twice after the update. Check whether the block editor works.',
					'updatelens'
				),
				httpStatus( after ) ?? ''
			);
		case 'timeout':
			return __(
				'Two REST API requests timed out after the update. Check whether the block editor works.',
				'updatelens'
			);
		case 'invalid_json':
			return __(
				'The REST API returned invalid JSON twice after the update. Check whether the block editor works.',
				'updatelens'
			);
		case 'extra_output':
			return __(
				'The REST API returned other output before its JSON twice after the update. Check whether the block editor works.',
				'updatelens'
			);
		case 'unexpected_json':
			return __(
				'The REST API returned an unexpected response twice after the update. Check whether the block editor works.',
				'updatelens'
			);
		case 'wrong_user':
			return __(
				'The REST API answered for a different user twice after the update. Check whether the block editor works.',
				'updatelens'
			);
		case 'redirected':
			return __(
				'REST API requests were redirected twice after the update. Check whether the block editor works.',
				'updatelens'
			);
		default:
			return __(
				'Two REST API requests failed after the update. Check whether the block editor works.',
				'updatelens'
			);
	}
}

/**
 * "What changed": the earlier observation, then the later one.
 *
 * @param finding Finding.
 */
export function siteCheckWhatChanged( finding: SiteCheckFinding ): string {
	// Every finding starts from a verified pass.
	const before =
		finding.check === 'home'
			? __(
					'The homepage responded successfully during the earlier check.',
					'updatelens'
				)
			: __(
					'The REST API responded as expected during the earlier check.',
					'updatelens'
				);
	return `${ before } ${ laterText( finding ) }`;
}

/**
 * What the later requests saw.
 *
 * @param finding Finding.
 */
function laterText( finding: SiteCheckFinding ): string {
	if ( finding.code === 'site_check_changed' ) {
		return __(
			'Two later requests were redirected. The browser cannot see where a redirect leads.',
			'updatelens'
		);
	}
	switch ( failureKind( finding.after ) ) {
		case 'http':
			return sprintf(
				/* translators: %s: HTTP status code, e.g. "500". */
				__( 'Two later requests returned HTTP %s.', 'updatelens' ),
				httpStatus( finding.after ) ?? ''
			);
		case 'timeout':
			return __(
				'Two later requests timed out: no response arrived within the time limit.',
				'updatelens'
			);
		case 'incomplete':
			return __(
				'WordPress answered two later requests, but the page did not reach the expected completion marker.',
				'updatelens'
			);
		case 'invalid_json':
			return __(
				'Two later requests returned a response that was not valid JSON.',
				'updatelens'
			);
		case 'extra_output':
			return __(
				'Two later requests returned other output before the JSON.',
				'updatelens'
			);
		case 'unexpected_json':
			return __(
				'Two later requests returned JSON other than the expected user data.',
				'updatelens'
			);
		case 'wrong_user':
			return __(
				'Two later requests returned data for a different user.',
				'updatelens'
			);
		case 'redirected':
			return __( 'Two later requests were redirected.', 'updatelens' );
		default:
			return __(
				'Two later requests failed in different ways (see each request).',
				'updatelens'
			);
	}
}

/**
 * Why a site check finding might matter.
 *
 * @param finding Finding.
 */
export function siteCheckWhyItMatters( finding: SiteCheckFinding ): string[] {
	const noCause = __(
		'The check does not establish what caused the change.',
		'updatelens'
	);
	if ( finding.code === 'site_check_changed' ) {
		return [
			__(
				'Visitors opening the homepage are now sent to another address. This may be intended, for example a language, login or maintenance redirect.',
				'updatelens'
			),
			__(
				'A redirect is a changed response, not a failure.',
				'updatelens'
			),
		];
	}

	const kind = failureKind( finding.after );
	if ( finding.check === 'rest' ) {
		const sentences: string[] = [
			__(
				'The block editor and other features that use the REST API may not work as expected.',
				'updatelens'
			),
		];
		if ( kind === 'extra_output' || kind === 'invalid_json' ) {
			sentences.push(
				__(
					'Output that is not JSON, such as PHP warnings printed into a response, can break features that read these responses.',
					'updatelens'
				)
			);
		}
		if ( kind === 'timeout' ) {
			sentences.push(
				__(
					'Two timeouts show that no response arrived within the time limit; they do not show that the site was down.',
					'updatelens'
				)
			);
		}
		return [ ...sentences, noCause, ...originNote( finding.after ) ];
	}

	switch ( kind ) {
		case 'timeout':
			return [
				__(
					'The homepage may be slow or unreachable for visitors.',
					'updatelens'
				),
				__(
					'Two timeouts show that no response arrived within the time limit; they do not show that the site was down.',
					'updatelens'
				),
				noCause,
			];
		case 'incomplete':
			return [
				__(
					'A page that stops before its end can leave parts of the homepage missing for visitors.',
					'updatelens'
				),
				noCause,
			];
		default:
			return [
				__(
					'Visitors may see an error instead of the homepage.',
					'updatelens'
				),
				noCause,
				...originNote( finding.after ),
			];
	}
}

/**
 * A note when the failed responses could not be verified as WordPress's.
 *
 * @param after AFTER side.
 */
function originNote( after: SiteCheckSide ): string[] {
	return unverified( after )
		? [
				__(
					'These responses did not carry the UpdateLens verification marker, so the check cannot tell which part of the setup produced them.',
					'updatelens'
				),
			]
		: [];
}

/**
 * What to check next for a site check finding.
 *
 * @param finding Finding.
 */
export function siteCheckWhatToCheck( finding: SiteCheckFinding ): string[] {
	if ( finding.code === 'site_check_changed' ) {
		return [
			__(
				'Open the homepage in a private window and check where it leads.',
				'updatelens'
			),
			__(
				'If the redirect is not intended, review redirect, language, maintenance or security settings.',
				'updatelens'
			),
		];
	}
	if ( finding.check === 'rest' ) {
		return [
			__(
				'Open the block editor and try opening or saving a draft.',
				'updatelens'
			),
			__(
				'Review PHP warnings and error logs from around the time of the update.',
				'updatelens'
			),
		];
	}
	return homeSteps( finding.after );
}

/**
 * Next steps for a homepage failure.
 *
 * @param after AFTER side.
 */
function homeSteps( after: SiteCheckSide ): string[] {
	switch ( failureKind( after ) ) {
		case 'timeout':
			return [
				__(
					'Open the homepage in a private window and see whether it loads, and how long it takes.',
					'updatelens'
				),
				__(
					'If it is slow or does not load, review your hosting and PHP error logs.',
					'updatelens'
				),
			];
		case 'incomplete':
			return [
				__(
					'Open the homepage in a private window and check whether the whole page appears, including the footer.',
					'updatelens'
				),
				__(
					'Review PHP warnings and error logs from around the time of the update.',
					'updatelens'
				),
			];
		default: {
			const steps: string[] = [
				__(
					'Open the homepage in a private window to see whether the problem continues.',
					'updatelens'
				),
				__(
					'If the problem continues, review your hosting and PHP error logs.',
					'updatelens'
				),
			];
			if ( unverified( after ) ) {
				steps.push(
					__(
						'If the error page does not look like your site, also check any proxy, CDN or firewall in front of it.',
						'updatelens'
					)
				);
			}
			return steps;
		}
	}
}

/**
 * The verdict of a side in a few words.
 *
 * @param side Side with a result.
 */
export function sideVerdictText( side: SiteCheckSide ): string {
	const count = side.result?.attempts.length ?? 0;
	switch ( side.result?.verdict ) {
		case 'pass':
			return __( 'Passed', 'updatelens' );
		case 'fail':
			// A failure is confirmed by both requests of a run.
			return count === 2
				? __( 'Failed in both requests', 'updatelens' )
				: sprintf(
						/* translators: %s: number of requests. */
						_n(
							'Failed in %s request',
							'Failed in %s requests',
							count,
							'updatelens'
						),
						formatCount( count )
					);
		case 'redirect':
			return count === 2
				? __( 'Redirected in both requests', 'updatelens' )
				: sprintf(
						/* translators: %s: number of requests. */
						_n(
							'Redirected in %s request',
							'Redirected in %s requests',
							count,
							'updatelens'
						),
						formatCount( count )
					);
		default:
			return __( 'Inconclusive', 'updatelens' );
	}
}

/**
 * The verdict with what every request showed, for scanning: "Passed · HTTP
 * 200", "Failed in both requests · HTTP 500", "… · no response". Only when
 * the requests agree; each request is still listed below it.
 *
 * @param side Side with a result.
 */
export function sideSummaryText( side: SiteCheckSide ): string {
	const verdict = sideVerdictText( side );
	const attempts = side.result?.attempts ?? [];
	const status = httpStatus( side );
	if ( status !== null ) {
		return `${ verdict } · ${ keepTogether(
			sprintf(
				/* translators: %s: HTTP status code, e.g. "200". */
				__( 'HTTP %s', 'updatelens' ),
				status
			)
		) }`;
	}
	if (
		attempts.length > 0 &&
		attempts.every( ( attempt ) => attempt.outcome === 'timeout' )
	) {
		return `${ verdict } · ${ __( 'no response', 'updatelens' ) }`;
	}
	return verdict;
}

/**
 * When a side ran, relative to the update: "Oct 11, 2026, 2:02 PM · 4.2
 * minutes before the update started" (BEFORE: completion; AFTER: start).
 * The times are the check's own, never those of a phase.
 *
 * @param side    Side.
 * @param purpose `before` or `after`.
 */
export function sideTimingText(
	side: SiteCheckSide,
	purpose: 'before' | 'after'
): string {
	const parts: string[] = [];
	const at = formatDateTime(
		purpose === 'before' ? side.completed_at : side.started_at
	);
	if ( at ) {
		parts.push( at );
	}
	const seconds =
		purpose === 'before' ? side.age_seconds : side.delay_seconds;
	if ( seconds !== null && seconds !== undefined ) {
		parts.push( relativeText( seconds, purpose ) );
	}
	return parts.join( ' · ' );
}

/**
 * "4.2 minutes before the update started", "3 seconds after the update
 * finished".
 *
 * @param seconds Whole seconds.
 * @param purpose `before` or `after`.
 */
function relativeText( seconds: number, purpose: 'before' | 'after' ): string {
	if ( seconds < 1 ) {
		return purpose === 'before'
			? __( 'less than a second before the update started', 'updatelens' )
			: __(
					'less than a second after the update finished',
					'updatelens'
				);
	}
	return purpose === 'before'
		? sprintf(
				/* translators: %s: duration, e.g. "4.2 minutes". */
				__( '%s before the update started', 'updatelens' ),
				formatDuration( seconds )
			)
		: sprintf(
				/* translators: %s: duration, e.g. "3 seconds". */
				__( '%s after the update finished', 'updatelens' ),
				formatDuration( seconds )
			);
}

/**
 * One request as observed by the browser: "HTTP 500 · WordPress verified ·
 * 0.2 seconds", "No response within 10 seconds".
 *
 * @param check   Check.
 * @param attempt Attempt.
 */
export function attemptText(
	check: SiteCheckId,
	attempt: SiteCheckAttempt
): string {
	const duration = formatDuration( attempt.duration_ms / 1000 );
	if ( attempt.status === null ) {
		switch ( attempt.outcome ) {
			case 'timeout':
				return sprintf(
					/* translators: %s: how long the browser waited, e.g. "10 seconds". */
					__( 'No response within %s', 'updatelens' ),
					duration
				);
			case 'redirect':
				return __(
					'Redirected (the browser cannot see where)',
					'updatelens'
				);
			default:
				return __( 'Network error, no response', 'updatelens' );
		}
	}

	const parts = [ statusText( attempt ) ];
	if ( attempt.origin === 'verified' ) {
		parts.push( __( 'WordPress verified', 'updatelens' ) );
	} else {
		parts.push( __( 'Not verified as WordPress', 'updatelens' ) );
	}
	const form =
		check === 'home' ? endMarkerText( attempt ) : bodyText( attempt );
	if ( form ) {
		parts.push( form );
	}
	parts.push( duration );
	return parts.join( ' · ' );
}

/**
 * The HTTP status, with what else the browser recognized about it.
 *
 * @param attempt Attempt with a status.
 */
function statusText( attempt: SiteCheckAttempt ): string {
	switch ( attempt.outcome ) {
		case 'challenge':
			return sprintf(
				/* translators: %s: HTTP status code, e.g. "403". */
				__( 'HTTP %s, security challenge', 'updatelens' ),
				String( attempt.status )
			);
		case 'unavailable':
			return sprintf(
				/* translators: %s: HTTP status code, e.g. "503". */
				__( 'HTTP %s with Retry-After', 'updatelens' ),
				String( attempt.status )
			);
		default:
			return sprintf(
				/* translators: %s: HTTP status code, e.g. "200". */
				__( 'HTTP %s', 'updatelens' ),
				String( attempt.status )
			);
	}
}

/**
 * The homepage's completion marker, only where it tells something: for a
 * verified page.
 *
 * @param attempt Homepage attempt.
 */
function endMarkerText( attempt: SiteCheckAttempt ): string | null {
	if ( attempt.origin !== 'verified' || attempt.outcome !== 'success' ) {
		return null;
	}
	switch ( attempt.end_marker ) {
		case 'current':
			return __( 'page completion marker present', 'updatelens' );
		case 'absent':
			return __( 'page completion marker missing', 'updatelens' );
		case 'stale':
			return __(
				'marker of an earlier check (cached page)',
				'updatelens'
			);
		case 'not_read':
			return __( 'page too large to inspect', 'updatelens' );
		default:
			return null;
	}
}

/**
 * What the REST response contained.
 *
 * @param attempt REST attempt.
 */
function bodyText( attempt: SiteCheckAttempt ): string | null {
	switch ( attempt.body ) {
		case 'expected_user':
			return __( 'expected user data', 'updatelens' );
		case 'other_user':
			return __( 'data for a different user', 'updatelens' );
		case 'wp_error':
			return attempt.error_code && attempt.error_code !== 'other'
				? sprintf(
						/* translators: %s: WordPress REST error code, e.g. "rest_forbidden". */
						__( 'WordPress error %s', 'updatelens' ),
						attempt.error_code
					)
				: __( 'WordPress error', 'updatelens' );
		case 'unexpected_json':
			return __( 'unexpected JSON', 'updatelens' );
		case 'extra_output':
			return __( 'other output before the JSON', 'updatelens' );
		case 'invalid_json':
			return __( 'invalid JSON', 'updatelens' );
		case 'empty':
			return __( 'empty response', 'updatelens' );
		case 'not_read':
			return __( 'response too large to inspect', 'updatelens' );
		default:
			return null;
	}
}

/**
 * Label of a request: "Request 1".
 *
 * @param index Zero-based position.
 */
export function requestLabel( index: number ): string {
	return sprintf(
		/* translators: %s: request number, e.g. "1". */
		__( 'Request %s', 'updatelens' ),
		formatCount( index + 1 )
	);
}

/**
 * How one site check ended, for "What was checked" and the list of checks
 * that did not complete: a category (as for signals), a short status and a
 * detail sentence.
 *
 * @param check      Check.
 * @param evaluation The check in `potential_impact.site_checks`.
 * @param comparison The check in the report's `site_checks`, if present.
 */
export function siteCheckCoverage(
	check: SiteCheckId,
	evaluation: SiteCheckImpactCheck,
	comparison: SiteCheckComparison | undefined
): { category: CheckCategory; status: string; detail: string | null } {
	switch ( evaluation.status ) {
		case 'evaluated':
			return {
				category: 'checked',
				...comparedText( check, evaluation ),
			};
		case 'pending':
			return {
				category: 'waiting',
				status: __( 'Still pending', 'updatelens' ),
				detail: __(
					'Waiting for the later check from the browser that ran the update.',
					'updatelens'
				),
			};
		case 'not_applicable':
			return {
				category: 'not_applicable',
				...notUsedText( evaluation.reason ),
			};
		default:
			return {
				category: 'unavailable',
				status: __( 'Could not be compared', 'updatelens' ),
				detail: notComparedText( evaluation, comparison ),
			};
	}
}

/**
 * A compared check without the finding wording: its state and what it
 * means here.
 *
 * @param check      Check.
 * @param evaluation Evaluation.
 */
function comparedText(
	check: SiteCheckId,
	evaluation: SiteCheckImpactCheck
): { status: string; detail: string | null } {
	const findings =
		evaluation.finding_count && evaluation.finding_count > 0
			? sprintf(
					/* translators: %s: number of findings. */
					_n(
						'%s finding',
						'%s findings',
						evaluation.finding_count,
						'updatelens'
					),
					formatCount( evaluation.finding_count )
				)
			: null;
	switch ( evaluation.state ) {
		case 'passed_both':
			return {
				status: __( 'Passed at both observation points', 'updatelens' ),
				detail:
					check === 'home'
						? __(
								'The homepage came back from WordPress with its completion marker before and after the update.',
								'updatelens'
							)
						: __(
								'The REST API returned the expected user data before and after the update.',
								'updatelens'
							),
			};
		case 'newly_failing':
			return {
				status: __( 'Newly failing', 'updatelens' ),
				detail: findings,
			};
		case 'changed':
			return {
				status: __( 'Changed', 'updatelens' ),
				detail: changedText( evaluation.change ) ?? findings,
			};
		case 'failing_both':
			return {
				status: __(
					'Already failing at the earlier observation',
					'updatelens'
				),
				detail: __(
					'It failed before and after the update, so it is not a new finding.',
					'updatelens'
				),
			};
		case 'recovered':
			return {
				status: __( 'Recovered', 'updatelens' ),
				detail: __(
					'It failed before the update and passed after it.',
					'updatelens'
				),
			};
		default:
			return { status: __( 'Compared', 'updatelens' ), detail: findings };
	}
}

/**
 * A `changed` comparison that is not a finding, as an observation; null for
 * the new redirect (a finding, counted instead).
 *
 * @param change Kind from the API.
 */
function changedText(
	change: SiteCheckImpactCheck[ 'change' ]
): string | null {
	switch ( change ) {
		case 'redirect_disappeared':
			return __(
				'The homepage redirected before the update and loaded directly after it.',
				'updatelens'
			);
		case 'redirect_then_failure':
			return __(
				'The homepage redirected before the update and failed in both requests after it. The earlier check was a redirect, not a verified pass, so this is not reported as a newly failing check.',
				'updatelens'
			);
		default:
			return null;
	}
}

/**
 * Why site checks were not part of an update.
 *
 * @param reason `disabled` or `not_captured`.
 */
function notUsedText( reason: string | null ): {
	status: string;
	detail: string | null;
} {
	return reason === 'disabled'
		? {
				status: __( 'Not used', 'updatelens' ),
				detail: __(
					'Site checks were disabled for this update.',
					'updatelens'
				),
			}
		: {
				status: __( 'Not captured', 'updatelens' ),
				detail: __(
					'This update was observed before UpdateLens recorded site checks.',
					'updatelens'
				),
			};
}

/**
 * The text of site checks that were not part of the update, for one row in
 * "What was checked" instead of one per check (null if any check applies).
 *
 * @param impact API evaluation.
 */
export function siteChecksNotUsed(
	impact: PotentialImpact
): { status: string; detail: string | null } | null {
	if ( siteChecksApplicable( impact ) ) {
		return null;
	}
	const disabled = SITE_CHECKS.some(
		( check ) => impact.site_checks?.checks[ check ]?.reason === 'disabled'
	);
	return notUsedText( disabled ? 'disabled' : 'not_captured' );
}

/**
 * Why a check was not compared: from the comparison (`not_comparable`) or
 * the missing side (`not_run`), worded for that side.
 *
 * @param evaluation Evaluation.
 * @param comparison The report's comparison of that check.
 */
function notComparedText(
	evaluation: SiteCheckImpactCheck,
	comparison: SiteCheckComparison | undefined
): string {
	const reason = evaluation.reason ?? '';
	if ( evaluation.state === 'not_comparable' ) {
		return comparisonReasonText( reason, comparison );
	}
	const missingBefore = comparison ? ! comparison.before.available : true;
	return missingBefore
		? beforeReasonText( reason )
		: afterReasonText( reason );
}

/**
 * A missing earlier check.
 *
 * @param reason BEFORE reason.
 */
function beforeReasonText( reason: string ): string {
	switch ( reason ) {
		case 'no_login_session':
			return __(
				'The update request had no WordPress login session to match an earlier check with.',
				'updatelens'
			);
		case 'update_order_unknown':
			return __(
				'UpdateLens could not record the order of updates, so no earlier check could be matched to this one.',
				'updatelens'
			);
		case 'overlapping_update':
			return __(
				'Another update was running when this one started.',
				'updatelens'
			);
		case 'missing':
			return __(
				'No earlier check from this administrator’s login session was available when the update started. Earlier checks run on the Plugins and Updates screens.',
				'updatelens'
			);
		case 'corrupt':
		case 'data_corrupt':
			return __( 'The earlier check could not be read.', 'updatelens' );
		case 'definition_changed':
			return __(
				'The earlier check used a different version of the check.',
				'updatelens'
			);
		case 'target_changed':
			return __(
				'The site address changed after the earlier check.',
				'updatelens'
			);
		case 'not_completed_before_update':
			return __(
				'The update started before the earlier check finished.',
				'updatelens'
			);
		case 'expired':
			return __(
				'The earlier check was too old when the update started.',
				'updatelens'
			);
		case 'taken_during_update':
			return __(
				'The earlier check ran while another update was in progress.',
				'updatelens'
			);
		case 'intervening_update':
			return __(
				'Another update ran between the earlier check and this update.',
				'updatelens'
			);
		case 'selection_failed':
			return __(
				'The earlier check could not be selected because of an unexpected error.',
				'updatelens'
			);
		default:
			return __(
				'No earlier check was available for this update.',
				'updatelens'
			);
	}
}

/**
 * A missing later check.
 *
 * @param reason AFTER reason.
 */
function afterReasonText( reason: string ): string {
	switch ( reason ) {
		case 'overlapping_update':
			return __(
				'Another update ran after this one, before the later check could start.',
				'updatelens'
			);
		case 'update_order_unknown':
			return __(
				'UpdateLens could not confirm that no other update ran before the later check, so it did not run.',
				'updatelens'
			);
		case 'rate_limited':
			return __(
				'The later check did not start because too many checks had run recently.',
				'updatelens'
			);
		case 'session_failed':
			return __( 'The later check could not be started.', 'updatelens' );
		case 'analysis_ended':
			return __(
				'The update did not complete normally, so there was no later check.',
				'updatelens'
			);
		case 'settle_expired':
			return __(
				'The observation after the update was not captured in time, so there was no later check.',
				'updatelens'
			);
		case 'not_followed_up':
			return __(
				'The later check runs only in the browser that ran the update, right after it; this update was finished by a later page load.',
				'updatelens'
			);
		case 'not_started':
			return __(
				'The later check did not start, for example because the page was closed or left right after the update.',
				'updatelens'
			);
		case 'not_reported':
			return __(
				'The later check started, but its result was not reported, for example because the page was closed while it ran.',
				'updatelens'
			);
		case 'data_corrupt':
			return __( 'The later check could not be read.', 'updatelens' );
		default:
			return __(
				'No later check was available for this update.',
				'updatelens'
			);
	}
}

/**
 * Both checks exist, but may not be compared.
 *
 * @param reason     Comparison reason.
 * @param comparison The report's comparison (for inconclusive details).
 */
function comparisonReasonText(
	reason: string,
	comparison: SiteCheckComparison | undefined
): string {
	switch ( reason ) {
		case 'queued_update':
			return __(
				'This update was one of several queued updates.',
				'updatelens'
			);
		case 'overlapping_update':
			return __(
				'Another update ran after this one finished, before the later check was reported.',
				'updatelens'
			);
		case 'update_order_unknown':
			return __(
				'UpdateLens could not confirm that no other update ran before the later check.',
				'updatelens'
			);
		case 'definition_changed':
			return __(
				'The two checks used different versions of the check.',
				'updatelens'
			);
		case 'target_changed':
			return __(
				'The site address changed between the two checks.',
				'updatelens'
			);
		case 'different_user':
			return __(
				'The two checks ran for different administrators.',
				'updatelens'
			);
		case 'different_login_session':
			return __(
				'The two checks ran in different login sessions.',
				'updatelens'
			);
		case 'baseline_after_update_start':
			return __(
				'The update started before the earlier check finished.',
				'updatelens'
			);
		case 'baseline_expired':
			return __(
				'The earlier check was too old when the update started.',
				'updatelens'
			);
		case 'after_during_update':
			return __(
				'The later check started before the update had finished.',
				'updatelens'
			);
		case 'before_inconclusive':
			return sprintf(
				/* translators: %s: why the check gave no reliable result, e.g. "a security challenge was returned." */
				__( 'The earlier check was inconclusive: %s', 'updatelens' ),
				inconclusiveText( comparison?.before )
			);
		case 'after_inconclusive':
			return sprintf(
				/* translators: %s: why the check gave no reliable result, e.g. "one request failed and the retry did not confirm it." */
				__( 'The later check was inconclusive: %s', 'updatelens' ),
				inconclusiveText( comparison?.after )
			);
		case 'redirected_both':
			return __(
				'The homepage redirected at both checks. The browser cannot see where a redirect leads, so they cannot be compared.',
				'updatelens'
			);
		default:
			return __( 'The two checks could not be compared.', 'updatelens' );
	}
}

/**
 * Why a run gave no reliable result.
 *
 * @param side Side with an inconclusive result.
 */
function inconclusiveText( side: SiteCheckSide | undefined ): string {
	switch ( side?.result?.reason ) {
		case 'unconfirmed':
			return __(
				'one request failed and the retry did not confirm it.',
				'updatelens'
			);
		case 'not_verified':
			return __(
				'WordPress could not be verified as the source of the response, for example because a cached page was returned.',
				'updatelens'
			);
		case 'stale_marker':
			return __(
				'a cached response from an earlier check came back.',
				'updatelens'
			);
		case 'challenge':
			return __( 'a security challenge was returned.', 'updatelens' );
		case 'blocked':
			return __(
				'the request was refused before WordPress could be verified, for example by a firewall or password protection.',
				'updatelens'
			);
		case 'rate_limited':
			return __( 'the request was rate limited.', 'updatelens' );
		case 'unavailable_retry_after':
			return __(
				'the site answered HTTP 503 with Retry-After, as in maintenance mode.',
				'updatelens'
			);
		case 'network_error':
			return __( 'a network error prevented a response.', 'updatelens' );
		case 'not_inspected':
			return __( 'the response was too large to inspect.', 'updatelens' );
		default:
			return __( 'it gave no reliable result.', 'updatelens' );
	}
}

/**
 * Timing of a compared check, for "What was checked": when the earlier and
 * later checks ran relative to the update.
 *
 * @param siteChecks The report's `site_checks`.
 * @param check      Check.
 */
export function comparedTimingText(
	siteChecks: SiteChecks | undefined,
	check: SiteCheckId
): string | null {
	const comparison = siteChecks?.checks[ check ];
	if ( ! comparison ) {
		return null;
	}
	const age = comparison.before.age_seconds;
	const delay = comparison.after.delay_seconds;
	if (
		age === null ||
		age === undefined ||
		delay === null ||
		delay === undefined
	) {
		return null;
	}
	return sprintf(
		/* translators: 1: e.g. "4.2 minutes before the update started". 2: e.g. "3 seconds after the update finished". */
		__( 'Earlier check %1$s; later check %2$s.', 'updatelens' ),
		relativeText( age, 'before' ),
		relativeText( delay, 'after' )
	);
}

/** The site check patterns, for "Patterns checked". */
export function siteCheckPatterns(): string[] {
	return [
		__(
			'Site checks: a homepage or REST API check that passed before the update and failed in both requests after it, and a homepage that passed before the update and redirected after it.',
			'updatelens'
		),
	];
}

/** What site check results do not show, for "What this does not show". */
export function siteCheckLimitations(): string[] {
	return [
		__(
			'Site checks are separate requests from your browser shortly before and after the update. They are not part of any phase, and are compared only when both ran under the same conditions.',
			'updatelens'
		),
		__(
			'A passing check shows that one page or request responded as expected at that moment, not that every page or feature works.',
			'updatelens'
		),
	];
}
