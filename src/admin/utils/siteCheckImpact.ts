import { _n, __, sprintf } from '@wordpress/i18n';

import type {
	PotentialImpact,
	SiteCheckAttempt,
	SiteCheckComparison,
	SiteCheckFinding,
	SiteCheckId,
	SiteCheckImpactCheck,
	SiteCheckSide,
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
 * Whether WordPress itself said, in every attempt of a side, that the REST
 * route does not exist: HTTP 404 with the `rest_no_route` error code and
 * this run's verification marker. Unverified 404s are not counted, since
 * another part of the setup may have sent them.
 *
 * @param side Side.
 */
export function routeMissing( side: SiteCheckSide ): boolean {
	const attempts = side.result?.attempts ?? [];
	return (
		attempts.length > 0 &&
		attempts.every(
			( attempt ) =>
				attempt.status === 404 &&
				attempt.error_code === 'rest_no_route' &&
				attempt.origin === 'verified'
		)
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
				'In two requests after the update, the homepage ended without its completion marker. Check what signed-out visitors see now.',
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
	if ( routeMissing( after ) ) {
		return __(
			'In both requests after the update, WordPress reported that the REST API route /wp/v2/users/me does not exist. Check whether a setting now hides it.',
			'updatelens'
		);
	}
	switch ( failureKind( after ) ) {
		case 'http':
			return sprintf(
				/* translators: %s: HTTP status code, e.g. "500". */
				__(
					'The REST API route /wp/v2/users/me returned HTTP %s twice after the update. Check the features that use it, such as the block editor.',
					'updatelens'
				),
				httpStatus( after ) ?? ''
			);
		case 'timeout':
			return __(
				'Two requests to the REST API route /wp/v2/users/me timed out after the update. Check the features that use it, such as the block editor.',
				'updatelens'
			);
		case 'invalid_json':
			return __(
				'The REST API route /wp/v2/users/me returned invalid JSON twice after the update. Check the features that use it, such as the block editor.',
				'updatelens'
			);
		case 'extra_output':
			return __(
				'The REST API route /wp/v2/users/me returned other output before its JSON twice after the update. Check the features that use it, such as the block editor.',
				'updatelens'
			);
		case 'unexpected_json':
			return __(
				'The REST API route /wp/v2/users/me returned an unexpected response twice after the update. Check the features that use it, such as the block editor.',
				'updatelens'
			);
		case 'wrong_user':
			return __(
				'The REST API route /wp/v2/users/me answered for a different user twice after the update. Check the features that use it, such as the block editor.',
				'updatelens'
			);
		case 'redirected':
			return __(
				'Requests to the REST API route /wp/v2/users/me were redirected twice after the update. Check the features that use it, such as the block editor.',
				'updatelens'
			);
		default:
			return __(
				'Two requests to the REST API route /wp/v2/users/me failed after the update. Check the features that use it, such as the block editor.',
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
					'The homepage responded successfully before the update.',
					'updatelens'
				)
			: __(
					'The REST API route /wp/v2/users/me responded as expected before the update.',
					'updatelens'
				);
	return `${ before } ${ laterText( finding ) } ${ observedOnce(
		finding.after
	) }`;
}

/**
 * When the requests after the update ran, and that they ran once: a finding
 * is an observation at that time, not a statement about the site now.
 *
 * @param after AFTER side.
 */
function observedOnce( after: SiteCheckSide ): string {
	const when = formatDateTime( after.started_at );
	return when === null
		? __(
				'These requests ran once, right after the update; UpdateLens did not check again.',
				'updatelens'
			)
		: sprintf(
				/* translators: %s: date and time, e.g. "Oct 17, 2026, 2:04 PM". */
				__(
					'These requests ran at %s, right after the update; UpdateLens did not check again.',
					'updatelens'
				),
				when
			);
}

/**
 * What the later requests saw: a redirect (the change finding) or how they
 * failed.
 *
 * @param finding Finding, or a later side described like one.
 */
export function laterText(
	finding: Pick< SiteCheckFinding, 'code' | 'after' >
): string {
	if ( finding.code === 'site_check_changed' ) {
		return __(
			'Both requests after the update were redirected. The browser cannot see where a redirect leads.',
			'updatelens'
		);
	}
	if ( routeMissing( finding.after ) ) {
		return __(
			'In both requests after the update, WordPress answered HTTP 404 with the error code rest_no_route: no REST API route matched /wp/v2/users/me.',
			'updatelens'
		);
	}
	switch ( failureKind( finding.after ) ) {
		case 'http':
			return sprintf(
				/* translators: %s: HTTP status code, e.g. "500". */
				__(
					'Both requests after the update returned HTTP %s.',
					'updatelens'
				),
				httpStatus( finding.after ) ?? ''
			);
		case 'timeout':
			return __(
				'Both requests after the update timed out: no response arrived within the time limit.',
				'updatelens'
			);
		case 'incomplete':
			return __(
				'WordPress answered both requests after the update, but the page ended without the completion marker that WordPress’s page footer prints.',
				'updatelens'
			);
		case 'invalid_json':
			return __(
				'Both requests after the update returned a response that was not valid JSON.',
				'updatelens'
			);
		case 'extra_output':
			return __(
				'Both requests after the update returned other output before the JSON.',
				'updatelens'
			);
		case 'unexpected_json':
			return __(
				'Both requests after the update returned JSON other than the expected user data.',
				'updatelens'
			);
		case 'wrong_user':
			return __(
				'Both requests after the update returned data for a different user.',
				'updatelens'
			);
		case 'redirected':
			return __(
				'Both requests after the update were redirected.',
				'updatelens'
			);
		default:
			return __(
				'Both requests after the update failed, in different ways (see each request).',
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
				'Visitors opening the homepage were sent to another address at that time. This may be intended, for example a language, login or maintenance redirect.',
				'updatelens'
			),
			__(
				'A redirect is a changed response, not a failure.',
				'updatelens'
			),
		];
	}

	const kind = failureKind( finding.after );
	if ( finding.check === 'rest' && routeMissing( finding.after ) ) {
		return [
			__(
				'WordPress itself answered, so this route was not registered on the site at that time. Security or REST API settings can hide user routes on purpose.',
				'updatelens'
			),
			__(
				'Features that request this route, such as the block editor, may not work as expected while it is missing.',
				'updatelens'
			),
			noCause,
		];
	}
	if ( finding.check === 'rest' ) {
		const sentences: string[] = [
			__(
				'Features that request this route, such as the block editor, may not have worked as expected at that time.',
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
					'The homepage may have been slow or unreachable for visitors at that time.',
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
					'If the page stopped before WordPress’s page footer, for example because of a PHP error, visitors may have seen only part of the homepage at that time.',
					'updatelens'
				),
				__(
					'A page that replaces the homepage for signed-out visitors, such as a coming-soon or maintenance page, also ends without this marker; the check cannot tell the two apart.',
					'updatelens'
				),
				noCause,
			];
		default:
			return [
				__(
					'Visitors may have seen an error instead of the homepage at that time.',
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
	if ( finding.check === 'rest' && routeMissing( finding.after ) ) {
		return [
			__(
				'Check whether a security or REST API setting hides user routes; if it does, this may be intended.',
				'updatelens'
			),
			__(
				'Open the block editor and try opening or saving a draft.',
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
					'Open the homepage in a private window. If it shows a coming-soon or maintenance page that you turned on, this is expected.',
					'updatelens'
				),
				__(
					'Otherwise, check whether the whole page appears, including the footer, and review PHP warnings and error logs from around the time of the update.',
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
 * A side for a compact cell: the verdict in one word ("Passed", "Failed",
 * "Redirected", "Inconclusive"), and for a failure or redirect how many
 * requests showed it ("in both requests"), with what every request showed
 * ("HTTP 500"). The same facts as sideSummaryText().
 *
 * @param side Side with a result.
 */
export function sideCellParts( side: SiteCheckSide ): {
	verdict: string;
	detail: string | null;
} {
	const count = side.result?.attempts.length ?? 0;
	const { response } = sideSummaryParts( side );
	let verdict: string;
	let requests: string | null = null;
	switch ( side.result?.verdict ) {
		case 'pass':
			verdict = __( 'Passed', 'updatelens' );
			break;
		case 'fail':
		case 'redirect':
			verdict =
				side.result.verdict === 'fail'
					? __( 'Failed', 'updatelens' )
					: __( 'Redirected', 'updatelens' );
			requests =
				count === 2
					? __( 'in both requests', 'updatelens' )
					: sprintf(
							/* translators: %s: number of requests. */
							_n(
								'in %s request',
								'in %s requests',
								count,
								'updatelens'
							),
							formatCount( count )
						);
			break;
		default:
			verdict = __( 'Inconclusive', 'updatelens' );
	}
	const detail = [ requests, response ].filter( Boolean ).join( ' · ' );
	return { verdict, detail: detail === '' ? null : detail };
}

/**
 * The verdict with what every request showed, for scanning: "Passed · HTTP
 * 200", "Failed in both requests · HTTP 500", "… · no response". Only when
 * the requests agree; each request is still listed below it.
 *
 * @param side Side with a result.
 */
export function sideSummaryText( side: SiteCheckSide ): string {
	const { verdict, response } = sideSummaryParts( side );
	return response === null ? verdict : `${ verdict } · ${ response }`;
}

/**
 * The parts of sideSummaryText(): the verdict ("Failed in both requests")
 * and what every request showed ("HTTP 500", "no response"), null when the
 * requests do not agree.
 *
 * @param side Side with a result.
 */
export function sideSummaryParts( side: SiteCheckSide ): {
	verdict: string;
	response: string | null;
} {
	const verdict = sideVerdictText( side );
	const attempts = side.result?.attempts ?? [];
	const status = httpStatus( side );
	if ( status !== null ) {
		return {
			verdict,
			response: keepTogether(
				sprintf(
					/* translators: %s: HTTP status code, e.g. "200". */
					__( 'HTTP %s', 'updatelens' ),
					status
				)
			),
		};
	}
	if (
		attempts.length > 0 &&
		attempts.every( ( attempt ) => attempt.outcome === 'timeout' )
	) {
		return { verdict, response: __( 'no response', 'updatelens' ) };
	}
	return { verdict, response: null };
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

/** One request as observed by the browser, for the expanded details. */
export interface AttemptView {
	/** "HTTP 500 · 0.2 seconds", "No response within 10 seconds". */
	summary: string;
	/** Labelled facts: response source, completion marker or body. */
	fields: Array< [ string, string ] >;
}

/**
 * One request as labelled fields. The response source says whether
 * UpdateLens's one-time marker came back (WordPress verified as the sender),
 * also for a failed response; nothing here says what caused a failure.
 *
 * @param check   Check.
 * @param attempt Attempt.
 */
export function attemptView(
	check: SiteCheckId,
	attempt: SiteCheckAttempt
): AttemptView {
	const duration = formatDuration( attempt.duration_ms / 1000 );
	if ( attempt.status === null ) {
		switch ( attempt.outcome ) {
			case 'timeout':
				return {
					summary: sprintf(
						/* translators: %s: how long the browser waited, e.g. "10 seconds". */
						__( 'No response within %s', 'updatelens' ),
						duration
					),
					fields: [],
				};
			case 'redirect':
				return {
					summary: __(
						'Redirected (the browser cannot see where)',
						'updatelens'
					),
					fields: [],
				};
			default:
				return {
					summary: __( 'Network error, no response', 'updatelens' ),
					fields: [],
				};
		}
	}

	const fields: Array< [ string, string ] > = [
		[ __( 'Source', 'updatelens' ), sourceText( attempt ) ],
	];
	const form =
		check === 'home' ? endMarkerText( attempt ) : bodyText( attempt );
	if ( form ) {
		fields.push( [
			check === 'home'
				? __( 'Completion marker', 'updatelens' )
				: __( 'Body', 'updatelens' ),
			form,
		] );
	}
	return {
		summary: `${ statusText( attempt ) } · ${ duration }`,
		fields,
	};
}

/**
 * Whether the response was verified as coming from WordPress: UpdateLens's
 * one-time marker came back with it. A failed response can be verified too.
 *
 * @param attempt Attempt with a status.
 */
function sourceText( attempt: SiteCheckAttempt ): string {
	// What "verified" means is said once, below the requests.
	if ( attempt.origin !== 'verified' ) {
		return __( 'Not verified as WordPress', 'updatelens' );
	}
	return attempt.status !== null && attempt.status >= 400
		? __( 'Verified as WordPress, also for this error', 'updatelens' )
		: __( 'Verified as WordPress', 'updatelens' );
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
			return __( 'Present', 'updatelens' );
		case 'absent':
			return __( 'Missing', 'updatelens' );
		case 'stale':
			return __( 'From an earlier check (cached page)', 'updatelens' );
		case 'not_read':
			return __( 'Not inspected', 'updatelens' );
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
			return __( 'Expected user data', 'updatelens' );
		case 'other_user':
			return __( 'Data for a different user', 'updatelens' );
		case 'wp_error':
			return attempt.error_code && attempt.error_code !== 'other'
				? sprintf(
						/* translators: %s: WordPress REST error code, e.g. "rest_forbidden". */
						__( 'WordPress error %s', 'updatelens' ),
						attempt.error_code
					)
				: __( 'WordPress error', 'updatelens' );
		case 'unexpected_json':
			return __( 'Unexpected JSON', 'updatelens' );
		case 'extra_output':
			return __( 'Other output before the JSON', 'updatelens' );
		case 'invalid_json':
			return __( 'Invalid JSON', 'updatelens' );
		case 'empty':
			return __( 'Empty response', 'updatelens' );
		case 'not_read':
			return __( 'Not fully inspected', 'updatelens' );
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
					'Waiting for the check after the update from the browser that ran it.',
					'updatelens'
				),
			};
		case 'not_applicable':
			return {
				category: 'not_applicable',
				...notUsedText( evaluation.reason, check ),
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
				status: __( 'Already failing before the update', 'updatelens' ),
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
				'The homepage redirected before the update and failed in both requests after it. The check before the update was a redirect, not a verified pass, so this is not reported as a newly failing check.',
				'updatelens'
			);
		default:
			return null;
	}
}

/**
 * Why site checks were not part of an update.
 *
 * @param reason `disabled`, `cross_origin` or `not_captured`.
 * @param check  The check, or null for both.
 */
function notUsedText(
	reason: string | null,
	check: SiteCheckId | null
): {
	status: string;
	detail: string | null;
} {
	if ( reason === 'cross_origin' ) {
		return {
			status: __( 'Not supported on this site', 'updatelens' ),
			detail: crossOriginText( check ),
		};
	}
	return reason === 'disabled'
		? {
				status: __( 'Not used', 'updatelens' ),
				detail: __(
					'Website checks were disabled for this update.',
					'updatelens'
				),
			}
		: {
				status: __( 'Not recorded', 'updatelens' ),
				detail: __(
					'This update was observed before UpdateLens recorded website checks.',
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
	const reasons = SITE_CHECKS.map(
		( check ) => impact.site_checks?.checks[ check ]?.reason
	);
	if ( reasons.every( ( reason ) => reason === 'cross_origin' ) ) {
		return notUsedText( 'cross_origin', null );
	}
	return notUsedText(
		reasons.includes( 'disabled' ) ? 'disabled' : 'not_captured',
		null
	);
}

/**
 * Site checks that were used but not compared, or are still pending, in one
 * line for Potential Impact: names and state only ("Homepage and REST API
 * not compared · details in the section above"); the reasons are in "Site
 * response checks".
 *
 * @param checks Checks to name (pending or not evaluated).
 * @param impact API evaluation.
 */
export function siteChecksIncompleteText(
	checks: SiteCheckId[],
	impact: PotentialImpact
): string {
	const names = ( list: SiteCheckId[] ) =>
		list.length > 1
			? __( 'Homepage and REST API', 'updatelens' )
			: siteCheckSubject( list[ 0 ] );
	const pending = checks.filter(
		( check ) => impact.site_checks.checks[ check ]?.status === 'pending'
	);
	const other = checks.filter( ( check ) => ! pending.includes( check ) );
	const parts: string[] = [];
	if ( pending.length > 0 ) {
		parts.push(
			sprintf(
				/* translators: %s: check names, e.g. "Homepage" or "Homepage and REST API". */
				__( '%s waiting for results', 'updatelens' ),
				names( pending )
			)
		);
	}
	if ( other.length > 0 ) {
		parts.push(
			sprintf(
				/* translators: %s: check names, e.g. "Homepage" or "Homepage and REST API". */
				__( '%s not compared', 'updatelens' ),
				names( other )
			)
		);
	}
	return sprintf(
		/* translators: %s: e.g. "Homepage not compared". */
		__( '%s · details in the section above', 'updatelens' ),
		parts.join( __( '; ', 'updatelens' ) )
	);
}

/**
 * Why a check on another origin than wp-admin did not run.
 *
 * @param check The check, or null for both.
 */
export function crossOriginText( check: SiteCheckId | null ): string {
	switch ( check ) {
		case 'home':
			return __(
				'The homepage is on a different address than wp-admin (another scheme, host or port), so the browser cannot check it from the update screens. It was not checked and is not counted as passed.',
				'updatelens'
			);
		case 'rest':
			return __(
				'The REST API is on a different address than wp-admin (another scheme, host or port), so the browser cannot check it from the update screens. It was not checked and is not counted as passed.',
				'updatelens'
			);
		default:
			return __(
				'The homepage and the REST API are on a different address than wp-admin (another scheme, host or port), so the browser cannot check them from the update screens. They were not checked and are not counted as passed.',
				'updatelens'
			);
	}
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
export function beforeReasonText( reason: string ): string {
	switch ( reason ) {
		case 'no_login_session':
			return __(
				'The update request had no WordPress login session to match a check before the update with.',
				'updatelens'
			);
		case 'update_order_unknown':
			return __(
				'UpdateLens could not record the order of updates, so no check before the update could be matched to it.',
				'updatelens'
			);
		case 'overlapping_update':
			return __(
				'Another update was running when this one started.',
				'updatelens'
			);
		case 'missing':
			return __(
				'No check from this login session had finished when the update started. These checks run in the background on Installed Plugins; wait until UpdateLens shows “Ready to compare website responses” there before clicking “Update now”.',
				'updatelens'
			);
		case 'corrupt':
		case 'data_corrupt':
			return __(
				'The check before the update could not be read.',
				'updatelens'
			);
		case 'definition_changed':
			return __(
				'The check before the update used a different version of the check.',
				'updatelens'
			);
		case 'target_changed':
			return __(
				'The site address changed after the check before the update.',
				'updatelens'
			);
		case 'not_completed_before_update':
			return __(
				'The update started before the check before it had finished.',
				'updatelens'
			);
		case 'expired':
			return __(
				'The check before the update was too old when the update started.',
				'updatelens'
			);
		case 'taken_during_update':
			return __(
				'The check before the update ran while another update was in progress.',
				'updatelens'
			);
		case 'intervening_update':
			return __(
				'Another update ran between the check and this update.',
				'updatelens'
			);
		case 'selection_failed':
			return __(
				'The check before the update could not be selected because of an unexpected error.',
				'updatelens'
			);
		default:
			return __(
				'No check before the update was available.',
				'updatelens'
			);
	}
}

/**
 * A missing later check.
 *
 * @param reason AFTER reason.
 */
export function afterReasonText( reason: string ): string {
	switch ( reason ) {
		case 'overlapping_update':
			return __(
				'Another update ran after this one, before the check after the update could start.',
				'updatelens'
			);
		case 'update_order_unknown':
			return __(
				'UpdateLens could not confirm that no other update ran in between, so the check after the update did not run.',
				'updatelens'
			);
		case 'rate_limited':
			return __(
				'The check after the update did not start because too many checks had run recently.',
				'updatelens'
			);
		case 'session_failed':
			return __(
				'The check after the update could not be started.',
				'updatelens'
			);
		case 'analysis_ended':
			return __(
				'The update did not complete normally, so there was no check after it.',
				'updatelens'
			);
		case 'settle_expired':
			return __(
				'The observation after the update expired, so no check ran after it.',
				'updatelens'
			);
		case 'next_update':
			return __(
				'Another plugin update started right after this one (a second “Update now” or a bulk update), so the check after the update could not run for this update on its own.',
				'updatelens'
			);
		case 'not_followed_up':
			return __(
				'The check after the update runs in the tab that clicked “Update now” on Installed Plugins, right after “Updated!”. No follow-up from that tab reached UpdateLens for this update. That happens when the update ran from Dashboard → Updates, when the page was left or closed during the update, when the site did not answer the browser’s follow-up request, or when another plugin answered that request with its own page, such as a setup screen shown after the plugin updates.',
				'updatelens'
			);
		case 'no_baseline':
			return __(
				'There was no check before the update to compare with, so none ran after it.',
				'updatelens'
			);
		case 'not_started':
			return __(
				'The check after the update did not start. That happens when the page was closed or left right after the update, or when the request that starts the check did not succeed.',
				'updatelens'
			);
		case 'not_reported':
			return __(
				'The check after the update started, but its result did not reach UpdateLens. That happens when the page was closed while it ran, or when the request that reports the result did not succeed.',
				'updatelens'
			);
		case 'data_corrupt':
			return __(
				'The check after the update could not be read.',
				'updatelens'
			);
		default:
			return __(
				'No check after the update was available.',
				'updatelens'
			);
	}
}

/**
 * How to get a comparison for a future update, when a missing check after
 * the update is explained by how the update ran: one sentence, or null. The
 * missing comparison itself cannot be made afterwards, and this never offers
 * to.
 *
 * @param reason AFTER reason (or comparison reason).
 */
export function siteCheckNextTimeText( reason: string | null ): string | null {
	switch ( reason ) {
		case 'next_update':
		case 'overlapping_update':
		case 'queued_update':
			return __(
				'For a comparison next time, update one plugin at a time: on Installed Plugins, wait for “Ready to compare website responses”, click “Update now”, and wait a few seconds after “Updated!” before starting the next update.',
				'updatelens'
			);
		case 'not_followed_up':
			return __(
				'For a comparison next time, click “Update now” on Installed Plugins and keep that tab open for a few seconds after “Updated!”. If the tab stayed open, another plugin may have answered UpdateLens’s request; this can repeat until any setup screen that plugin shows is completed.',
				'updatelens'
			);
		case 'not_started':
		case 'not_reported':
			return __(
				'For a comparison next time, click “Update now” on Installed Plugins and keep that tab open for a few seconds after “Updated!”.',
				'updatelens'
			);
		default:
			return null;
	}
}

/**
 * Both checks exist, but may not be compared.
 *
 * @param reason     Comparison reason.
 * @param comparison The report's comparison (for inconclusive details).
 */
export function comparisonReasonText(
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
				'Another update ran after this one finished, before the check after it was reported.',
				'updatelens'
			);
		case 'update_order_unknown':
			return __(
				'UpdateLens could not confirm that no other update ran before the check after the update.',
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
				'The update started before the check before it had finished.',
				'updatelens'
			);
		case 'baseline_expired':
			return __(
				'The check before the update was too old when the update started.',
				'updatelens'
			);
		case 'after_during_update':
			return __(
				'The check after the update started before the update had finished.',
				'updatelens'
			);
		case 'before_inconclusive':
			return sprintf(
				/* translators: %s: why the check gave no reliable result, e.g. "a security challenge was returned." */
				__(
					'The check before the update was inconclusive: %s',
					'updatelens'
				),
				inconclusiveText( comparison?.before )
			);
		case 'after_inconclusive':
			return sprintf(
				/* translators: %s: why the check gave no reliable result, e.g. "one request failed and the retry did not confirm it." */
				__(
					'The check after the update was inconclusive: %s',
					'updatelens'
				),
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
			return __(
				'no response could be observed, for example because of a network error or because the browser was suspended.',
				'updatelens'
			);
		case 'not_inspected':
			return __(
				'the response could not be fully inspected (too large, or not received completely within the time limit).',
				'updatelens'
			);
		default:
			return __( 'it gave no reliable result.', 'updatelens' );
	}
}

/** The site check patterns, for "Patterns checked". */
export function siteCheckPatterns(): string[] {
	return [
		__(
			'Website checks: a homepage or REST API check that passed before the update and failed in both requests after it, and a homepage that passed before the update and redirected after it.',
			'updatelens'
		),
	];
}

/** What site check results do not show, for "What this does not show". */
export function siteCheckLimitations(): string[] {
	return [
		__(
			'Website checks are separate requests from your browser before and after the update. They are not part of any phase, and are compared only when both ran under the same conditions.',
			'updatelens'
		),
		__(
			'A passing check shows that one page or request responded as expected at that moment, not that every page or feature works.',
			'updatelens'
		),
	];
}
