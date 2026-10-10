/**
 * Site check contract shared with PHP (includes/SiteCheck): the fixed
 * checks, the start response of `updatelens_site_check_start`, and the
 * attempt facts `ReportValidator` accepts. No strings for people, no React.
 */

/** The two fixed checks, in their fixed order. */
export const CHECKS = [ 'home', 'rest' ] as const;

export type CheckId = ( typeof CHECKS )[ number ];

export type Purpose = 'before' | 'after';

/** admin-ajax actions (AjaxEndpoints in PHP). */
export const START_ACTION = 'updatelens_site_check_start';
export const REPORT_ACTION = 'updatelens_site_check_report';

/** Definition versions this runner implements (CheckDefinition::VERSIONS). */
export const SUPPORTED_DEFINITIONS: Readonly< Record< CheckId, number > > = {
	home: 1,
	rest: 1,
};

/** Verification header and end-of-page marker attribute (FrontendMarker). */
export const HEADER = 'X-UpdateLens-Check';
export const END_MARKER_ATTRIBUTE = 'data-updatelens-check';

/** Homepage query argument carrying the marker (FrontendMarker::QUERY_ARG). */
export const QUERY_ARG = 'updatelens_check';

/** Accepted ranges for the timing the server sends (CheckDefinition). */
export const TIMING_BOUNDS = {
	timeoutMs: [ 1000, 30000 ],
	retryDelayMs: [ 0, 30000 ],
	maxAttempts: [ 1, 2 ],
	expiresInSeconds: [ 10, 600 ],
} as const;

/**
 * Most bytes read from a response. A larger homepage cannot be inspected
 * for its end-of-page marker and is reported as `unread` (inconclusive),
 * never as a failure; the body is scanned in chunks and never kept.
 */
export const MAX_HOME_BYTES = 5 * 1024 * 1024;
export const MAX_REST_BYTES = 64 * 1024;

/** Upper bound of a reported duration (Attempt::MAX_DURATION_MS). */
export const MAX_DURATION_MS = 60000;

/** Longest REST error code sent (ReportValidator::MAX_ERROR_CODE_BYTES). */
export const MAX_ERROR_CODE_LENGTH = 128;

/** A check as issued by the start endpoint. */
export interface IssuedCheck {
	check: CheckId;
	definition: number;
	/** Public marker (session ID). */
	session: string;
	/** Private report secret: only ever sent in the report POST body. */
	secret: string;
	/** Server-built target URL. */
	url: string;
	/** REST only: the `wp_rest` nonce. */
	restNonce?: string;
	/** REST only: the user the response must name. */
	user?: number;
}

/**
 * Seconds until each check's BEFORE baseline is due (0 = due now), as
 * decided by PHP (Baselines). Only `before` starts carry it.
 */
export type RefreshIn = Partial< Record< CheckId, number > >;

/** Accepted range of a `refresh_in` value (Baselines::MAX_AGE_SECONDS and margin). */
export const REFRESH_IN_BOUNDS = [ 0, 3600 ] as const;

/** A parsed, validated start response. */
export interface StartedRun {
	purpose: Purpose;
	expiresInSeconds: number;
	timeoutMs: number;
	retryDelayMs: number;
	maxAttempts: number;
	checks: Partial< Record< CheckId, IssuedCheck > >;
	refreshIn?: RefreshIn;
}

/** A `before` start that opened no session (`code` `not_needed`). */
export interface NotNeededRun {
	/**
	 * `fresh`: every baseline is recent enough; `no_updates`: nothing to
	 * update; `update_in_progress`: an update is running, so a baseline taken
	 * now could never be used (ask again after `refreshIn`).
	 */
	reason: 'fresh' | 'no_updates' | 'update_in_progress';
	refreshIn?: RefreshIn;
}

export type MarkerFact = 'current' | 'absent' | 'other';
export type EndMarkerFact = MarkerFact | 'unread';
export type RestBodyFact =
	| 'json_object'
	| 'json_other'
	| 'json_after_output'
	| 'invalid_json'
	| 'empty'
	| 'unread';

/** An attempt without a readable response. */
export interface NoResponseFacts {
	kind: 'redirect' | 'timeout' | 'network_error';
	duration_ms: number;
}

export interface HomeResponseFacts {
	kind: 'response';
	status: number;
	duration_ms: number;
	header: MarkerFact;
	end_marker: EndMarkerFact;
	retry_after: boolean;
	challenge: boolean;
}

export interface RestResponseFacts {
	kind: 'response';
	status: number;
	duration_ms: number;
	header: MarkerFact;
	retry_after: boolean;
	challenge: boolean;
	body: RestBodyFact;
	user_id: number | null;
	error_code: string | null;
}

export type HomeFacts = HomeResponseFacts | NoResponseFacts;
export type RestFacts = RestResponseFacts | NoResponseFacts;
export type AttemptFacts = HomeFacts | RestFacts;

const MARKER = /^[a-f0-9]{32}$/;
const SECRET = /^[a-f0-9]{64}$/;
const NONCE = /^[A-Za-z0-9]{1,64}$/;

const isRecord = ( value: unknown ): value is Record< string, unknown > =>
	typeof value === 'object' && value !== null && ! Array.isArray( value );

const inRange = ( value: unknown, [ min, max ]: readonly [ number, number ] ) =>
	Number.isSafeInteger( value ) &&
	( value as number ) >= min &&
	( value as number ) <= max;

/**
 * Whether a value is a well-formed marker.
 *
 * @param value Value.
 */
export function isMarker( value: unknown ): value is string {
	return typeof value === 'string' && MARKER.test( value );
}

/**
 * The `data` of a successful start response, validated strictly. Anything
 * unexpected makes the whole response invalid (null): the runner never
 * guesses targets, tokens or timing.
 *
 * @param data `data` of `{"success":true,"data":…}`.
 */
export function parseStartResponse( data: unknown ): StartedRun | null {
	if (
		! isRecord( data ) ||
		data.code !== 'started' ||
		( data.purpose !== 'before' && data.purpose !== 'after' ) ||
		! inRange( data.expires_in, TIMING_BOUNDS.expiresInSeconds ) ||
		! inRange( data.timeout_ms, TIMING_BOUNDS.timeoutMs ) ||
		! inRange( data.retry_delay_ms, TIMING_BOUNDS.retryDelayMs ) ||
		! inRange( data.max_attempts, TIMING_BOUNDS.maxAttempts ) ||
		data.header !== HEADER ||
		data.end_marker_attribute !== END_MARKER_ATTRIBUTE ||
		! Array.isArray( data.checks ) ||
		data.checks.length > CHECKS.length
	) {
		return null;
	}

	const checks: Partial< Record< CheckId, IssuedCheck > > = {};
	for ( const entry of data.checks ) {
		if (
			! isRecord( entry ) ||
			! CHECKS.includes( entry.check as CheckId ) ||
			checks[ entry.check as CheckId ] ||
			! Number.isSafeInteger( entry.definition ) ||
			! isMarker( entry.session ) ||
			typeof entry.secret !== 'string' ||
			! SECRET.test( entry.secret ) ||
			typeof entry.url !== 'string' ||
			entry.url.includes( entry.secret )
		) {
			return null;
		}
		const issued: IssuedCheck = {
			check: entry.check as CheckId,
			definition: entry.definition as number,
			session: entry.session,
			secret: entry.secret,
			url: entry.url,
		};
		if ( issued.check === 'rest' ) {
			if (
				typeof entry.rest_nonce !== 'string' ||
				! NONCE.test( entry.rest_nonce ) ||
				! Number.isSafeInteger( entry.user ) ||
				( entry.user as number ) < 1
			) {
				return null;
			}
			issued.restNonce = entry.rest_nonce;
			issued.user = entry.user as number;
		}
		checks[ issued.check ] = issued;
	}

	const refreshIn = parseRefreshIn( data.refresh_in );
	if ( refreshIn === null ) {
		return null;
	}

	return {
		purpose: data.purpose,
		expiresInSeconds: data.expires_in as number,
		timeoutMs: data.timeout_ms as number,
		retryDelayMs: data.retry_delay_ms as number,
		maxAttempts: data.max_attempts as number,
		checks,
		...( refreshIn ? { refreshIn } : {} ),
	};
}

/**
 * The `data` of a `not_needed` start response, or null if it is not one.
 *
 * @param data `data` of `{"success":true,"data":…}`.
 */
export function parseNotNeeded( data: unknown ): NotNeededRun | null {
	if (
		! isRecord( data ) ||
		data.code !== 'not_needed' ||
		data.purpose !== 'before' ||
		! [ 'fresh', 'no_updates', 'update_in_progress' ].includes(
			data.reason as string
		)
	) {
		return null;
	}
	const refreshIn = parseRefreshIn( data.refresh_in );
	if ( refreshIn === null ) {
		return null;
	}

	return {
		reason: data.reason as NotNeededRun[ 'reason' ],
		...( refreshIn ? { refreshIn } : {} ),
	};
}

/**
 * A `refresh_in` object: undefined when absent, null when malformed.
 *
 * @param value Value.
 */
function parseRefreshIn( value: unknown ): RefreshIn | undefined | null {
	if ( value === undefined ) {
		return undefined;
	}
	if ( ! isRecord( value ) ) {
		return null;
	}
	const refreshIn: RefreshIn = {};
	for ( const [ check, seconds ] of Object.entries( value ) ) {
		if (
			! CHECKS.includes( check as CheckId ) ||
			! inRange( seconds, REFRESH_IN_BOUNDS )
		) {
			return null;
		}
		refreshIn[ check as CheckId ] = seconds as number;
	}

	return refreshIn;
}
