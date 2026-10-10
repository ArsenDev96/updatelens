/**
 * What one site check request observes, normalized to the facts PHP's
 * ReportValidator accepts. Nothing here keeps or sends response content:
 * bodies are scanned in chunks (homepage) or parsed in a bounded buffer
 * (REST) and dropped; only fixed codes, the status, the duration, and for
 * REST the `id` and error `code` of a JSON object are kept.
 */
import {
	END_MARKER_ATTRIBUTE,
	HEADER,
	MAX_DURATION_MS,
	MAX_ERROR_CODE_LENGTH,
	MAX_HOME_BYTES,
	MAX_REST_BYTES,
	QUERY_ARG,
	type AttemptFacts,
	type CheckId,
	type EndMarkerFact,
	type HomeFacts,
	type IssuedCheck,
	type MarkerFact,
	type RestBodyFact,
	type RestFacts,
} from './contract';

/** Why a server-issued target is not checked. */
export type TargetProblem = 'invalid_target' | 'cross_origin' | 'mixed_content';

/** The page the runner runs on. */
export interface PageLocation {
	origin: string;
	protocol: string;
}

/**
 * Whether a server-issued URL may be requested from this page: an http(s)
 * URL without credentials on the page's own origin (a cross-origin
 * response's headers and body are not readable, and an http target from an
 * https page is blocked as mixed content). The homepage URL must carry this
 * session's marker.
 *
 * @param issued Issued check.
 * @param page   The page's location.
 */
export function targetProblem(
	issued: IssuedCheck,
	page: PageLocation
): TargetProblem | null {
	let url: URL;
	try {
		url = new URL( issued.url );
	} catch {
		return 'invalid_target';
	}
	if (
		( url.protocol !== 'http:' && url.protocol !== 'https:' ) ||
		url.username !== '' ||
		url.password !== '' ||
		url.hash !== ''
	) {
		return 'invalid_target';
	}
	if ( page.protocol === 'https:' && url.protocol === 'http:' ) {
		return 'mixed_content';
	}
	if ( url.origin !== page.origin ) {
		return 'cross_origin';
	}
	if ( issued.check === 'home' ) {
		const markers = url.searchParams.getAll( QUERY_ARG );
		if ( markers.length !== 1 || markers[ 0 ] !== issued.session ) {
			return 'invalid_target';
		}
	} else if ( url.href.includes( issued.session ) ) {
		return 'invalid_target';
	}

	return null;
}

/**
 * The verification header compared with this session's marker.
 *
 * @param value  Header value (null when absent).
 * @param marker Session marker.
 */
export function markerFact( value: string | null, marker: string ): MarkerFact {
	if ( value === null ) {
		return 'absent';
	}
	return value.trim() === marker ? 'current' : 'other';
}

/** End-of-page marker attribute, tolerant of minified HTML (quotes optional). */
const END_MARKER = new RegExp(
	`${ END_MARKER_ATTRIBUTE }\\s*=\\s*["']?([a-f0-9]{32})`,
	'g'
);

/** Characters kept between chunks so a marker split across them is found. */
const CARRY = END_MARKER_ATTRIBUTE.length + 40;

/**
 * Read a body in chunks, at most `limit` bytes, calling `visit` with each
 * decoded piece. Stops early when `visit` returns true. Never keeps more
 * than one chunk.
 *
 * @param body  Response body.
 * @param limit Byte limit.
 * @param visit Receives decoded text; true stops reading.
 * @return False when the limit was reached before the end.
 */
async function readBounded(
	body: ReadableStream< Uint8Array >,
	limit: number,
	visit: ( text: string ) => boolean
): Promise< boolean > {
	const reader = body.getReader();
	const decoder = new TextDecoder();
	let bytes = 0;
	try {
		for (;;) {
			const { done, value } = await reader.read();
			if ( done ) {
				visit( decoder.decode() );
				return true;
			}
			bytes += value.byteLength;
			if ( bytes > limit ) {
				return false;
			}
			if ( visit( decoder.decode( value, { stream: true } ) ) ) {
				return true;
			}
		}
	} finally {
		reader.cancel().catch( () => undefined );
	}
}

/**
 * Scan a homepage body for end-of-page markers: `current` as soon as this
 * session's marker is seen, else `other` when only another session's
 * marker was seen, `absent` when none, `unread` when the page is larger
 * than `limit` bytes (it could not be inspected).
 *
 * @param body   Response body (null for bodiless responses).
 * @param marker Session marker.
 * @param limit  Byte limit.
 */
export async function scanEndMarker(
	body: ReadableStream< Uint8Array > | null,
	marker: string,
	limit: number = MAX_HOME_BYTES
): Promise< EndMarkerFact > {
	if ( body === null ) {
		return 'absent';
	}
	let carry = '';
	let other = false;
	let current = false;
	const complete = await readBounded( body, limit, ( text ) => {
		const window = carry + text;
		for ( const match of window.matchAll( END_MARKER ) ) {
			if ( match[ 1 ] === marker ) {
				current = true;
				return true;
			}
			other = true;
		}
		carry = window.slice( -CARRY );
		return false;
	} );
	if ( current ) {
		return 'current';
	}
	if ( ! complete ) {
		return 'unread';
	}
	return other ? 'other' : 'absent';
}

/**
 * Read a REST body as text, at most `limit` bytes.
 *
 * @param body  Response body.
 * @param limit Byte limit.
 * @return The text, or null when the body is larger than the limit.
 */
async function readRestText(
	body: ReadableStream< Uint8Array > | null,
	limit: number
): Promise< string | null > {
	if ( body === null ) {
		return '';
	}
	let text = '';
	const complete = await readBounded( body, limit, ( piece ) => {
		text += piece;
		return false;
	} );

	return complete ? text : null;
}

/** REST body facts. */
export interface RestBody {
	body: RestBodyFact;
	user_id: number | null;
	error_code: string | null;
}

/** Most `{` positions tried when looking for JSON after other output. */
const MAX_SUFFIX_TRIES = 64;

const isObject = ( value: unknown ): value is Record< string, unknown > =>
	typeof value === 'object' && value !== null && ! Array.isArray( value );

/**
 * Classify a REST body: a JSON object (with its integer `id`, or a
 * WordPress error object's `code`), other JSON, a JSON object after other
 * output (e.g. PHP notices), invalid JSON or empty. Nothing else is kept.
 *
 * @param text Body text (null when too large to inspect).
 */
export function classifyRestBody( text: string | null ): RestBody {
	const none = { user_id: null, error_code: null };
	if ( text === null ) {
		return { body: 'unread', ...none };
	}
	if ( text.trim() === '' ) {
		return { body: 'empty', ...none };
	}

	let json: unknown;
	try {
		json = JSON.parse( text );
	} catch {
		let position = text.lastIndexOf( '{' );
		for (
			let tries = 0;
			position > 0 && tries < MAX_SUFFIX_TRIES;
			tries++, position = text.lastIndexOf( '{', position - 1 )
		) {
			try {
				if ( isObject( JSON.parse( text.slice( position ) ) ) ) {
					return { body: 'json_after_output', ...none };
				}
			} catch {
				// Not the start of the trailing JSON; try an earlier brace.
			}
		}
		return { body: 'invalid_json', ...none };
	}

	if ( ! isObject( json ) ) {
		return { body: 'json_other', ...none };
	}
	const id = json.id;
	const code = json.code;
	const isError =
		typeof code === 'string' && typeof json.message === 'string';

	return {
		body: 'json_object',
		user_id:
			! isError && Number.isSafeInteger( id ) && ( id as number ) > 0
				? ( id as number )
				: null,
		error_code: isError
			? code.length >= 1 && code.length <= MAX_ERROR_CODE_LENGTH
				? code
				: 'other'
			: null,
	};
}

/** How one attempt ended without a readable response. */
export type AttemptFailure = 'timeout' | 'network_error' | 'cancelled';

/** Request options for one attempt. */
export interface AttemptRequest {
	fetch: typeof fetch;
	signal: AbortSignal;
	/** Why the signal aborted, read after an error. */
	aborted: () => AttemptFailure | null;
	now: () => number;
}

/** Thrown when the caller cancelled the run. */
export class CancelledError extends Error {
	constructor() {
		super( 'Site check cancelled.' );
		this.name = 'CancelledError';
	}
}

const duration = ( request: AttemptRequest, started: number ) =>
	Math.min(
		MAX_DURATION_MS,
		Math.max( 0, Math.round( request.now() - started ) )
	);

/**
 * The facts of an attempt that ended in an error.
 *
 * @param request Attempt request.
 * @param started Start time.
 */
function failed(
	request: AttemptRequest,
	started: number
): { kind: 'timeout' | 'network_error'; duration_ms: number } {
	const reason = request.aborted();
	if ( reason === 'cancelled' ) {
		throw new CancelledError();
	}

	return {
		kind: reason === 'timeout' ? 'timeout' : 'network_error',
		duration_ms: duration( request, started ),
	};
}

/**
 * Whether a response is a redirect the browser did not follow.
 *
 * @param response Response.
 */
const isRedirect = ( response: Response ) =>
	response.type === 'opaqueredirect' ||
	( response.status >= 300 && response.status < 400 );

/**
 * Request the homepage anonymously and observe it.
 *
 * @param issued  Issued homepage check.
 * @param request Attempt request.
 */
export async function observeHome(
	issued: IssuedCheck,
	request: AttemptRequest
): Promise< HomeFacts > {
	const started = request.now();
	try {
		const response = await request.fetch( issued.url, {
			method: 'GET',
			credentials: 'omit',
			cache: 'no-store',
			redirect: 'manual',
			signal: request.signal,
		} );
		if ( isRedirect( response ) ) {
			response.body?.cancel().catch( () => undefined );
			return {
				kind: 'redirect',
				duration_ms: duration( request, started ),
			};
		}
		if ( response.status < 200 || response.status > 599 ) {
			response.body?.cancel().catch( () => undefined );
			return failed( request, started );
		}
		const header = markerFact(
			response.headers.get( HEADER ),
			issued.session
		);
		const endMarker = await scanEndMarker( response.body, issued.session );

		return {
			kind: 'response',
			status: response.status,
			duration_ms: duration( request, started ),
			header,
			end_marker: endMarker,
			retry_after: response.headers.has( 'retry-after' ),
			challenge: isChallenge( response ),
		};
	} catch ( error ) {
		if ( error instanceof CancelledError ) {
			throw error;
		}
		return failed( request, started );
	}
}

/**
 * Request the REST check as the signed-in user and observe it.
 *
 * @param issued  Issued REST check.
 * @param request Attempt request.
 */
export async function observeRest(
	issued: IssuedCheck,
	request: AttemptRequest
): Promise< RestFacts > {
	const started = request.now();
	try {
		const response = await request.fetch( issued.url, {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			redirect: 'manual',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': issued.restNonce ?? '',
				[ HEADER ]: issued.session,
			},
			signal: request.signal,
		} );
		if ( isRedirect( response ) ) {
			response.body?.cancel().catch( () => undefined );
			return {
				kind: 'redirect',
				duration_ms: duration( request, started ),
			};
		}
		if ( response.status < 200 || response.status > 599 ) {
			response.body?.cancel().catch( () => undefined );
			return failed( request, started );
		}
		const header = markerFact(
			response.headers.get( HEADER ),
			issued.session
		);
		const body = classifyRestBody(
			await readRestText( response.body, MAX_REST_BYTES )
		);

		return {
			kind: 'response',
			status: response.status,
			duration_ms: duration( request, started ),
			header,
			retry_after: response.headers.has( 'retry-after' ),
			challenge: isChallenge( response ),
			...body,
		};
	} catch ( error ) {
		if ( error instanceof CancelledError ) {
			throw error;
		}
		return failed( request, started );
	}
}

/**
 * Known bot/firewall challenge indicators: Cloudflare's `cf-mitigated`
 * response header (set on challenge pages). Other services are not
 * recognised; their pages usually lack the verification marker and stay
 * inconclusive anyway.
 *
 * @param response Response.
 */
export function isChallenge( response: Response ): boolean {
	return response.headers.has( 'cf-mitigated' );
}

/**
 * Whether an attempt passes, only to decide if a confirming retry is
 * needed. Mirrors PHP's pass rule (ResponseClassifier): PHP still
 * classifies every reported attempt, and its verdict is the result.
 *
 * - homepage: a 2xx response, no challenge, with this session's
 *   end-of-page marker (which also verifies the origin);
 * - REST: a 2xx response, no challenge, this session's verification
 *   header, and a JSON object naming exactly the expected user.
 *
 * @param check        Check.
 * @param facts        Attempt facts.
 * @param expectedUser REST: the user the response must name.
 */
export function attemptPassed(
	check: CheckId,
	facts: AttemptFacts,
	expectedUser?: number
): boolean {
	if (
		facts.kind !== 'response' ||
		facts.status < 200 ||
		facts.status > 299 ||
		facts.challenge
	) {
		return false;
	}
	if ( check === 'home' ) {
		return 'end_marker' in facts && facts.end_marker === 'current';
	}

	return (
		'body' in facts &&
		facts.header === 'current' &&
		facts.body === 'json_object' &&
		facts.error_code === null &&
		expectedUser !== undefined &&
		facts.user_id === expectedUser
	);
}
