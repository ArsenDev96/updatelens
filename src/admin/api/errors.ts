/**
 * Kinds of failure the UI distinguishes. Server messages are never shown.
 *
 * - `forbidden`: UpdateLens's own permission check refused (`rest_forbidden`).
 * - `session`: the REST nonce or login session was refused.
 * - `blocked`: another 401/403 from the REST API (a security plugin or host
 *   rule in front of it), not UpdateLens's permission check.
 * - `unreadable`: the answer was not REST JSON (PHP output before the data,
 *   an HTML error or firewall page); its HTTP status is unknown then.
 * - `offline`: the request did not reach the site.
 * - `failed`: any other error, with its HTTP status if known.
 */
export type ApiErrorKind =
	| 'not_found'
	| 'forbidden'
	| 'session'
	| 'blocked'
	| 'unreadable'
	| 'offline'
	| 'too_large'
	| 'failed';

export class ApiError extends Error {
	readonly kind: ApiErrorKind;

	/** HTTP status, if known. */
	readonly status: number | null;

	constructor( kind: ApiErrorKind, status: number | null ) {
		super( `UpdateLens request failed (${ kind }).` );
		this.name = 'ApiError';
		this.kind = kind;
		this.status = status;
	}
}

function statusOf( error: object ): number | null {
	// apiFetch with `parse: false` rejects with the Response itself.
	if ( 'status' in error && typeof error.status === 'number' ) {
		return error.status;
	}
	// Otherwise with the REST error body: { code, message, data: { status } }.
	if (
		'data' in error &&
		error.data &&
		typeof error.data === 'object' &&
		'status' in error.data &&
		typeof error.data.status === 'number'
	) {
		return error.data.status;
	}
	return null;
}

/**
 * Normalize whatever apiFetch rejected with (REST error body, Response,
 * network error) into an ApiError.
 *
 * @param error Rejection value.
 */
export function toApiError( error: unknown ): ApiError {
	if ( error instanceof ApiError ) {
		return error;
	}
	if ( ! error || typeof error !== 'object' ) {
		return new ApiError( 'failed', null );
	}

	const status = statusOf( error );
	const code =
		'code' in error && typeof error.code === 'string' ? error.code : '';

	if ( status === 404 || code === 'updatelens_analysis_not_found' ) {
		return new ApiError( 'not_found', status );
	}
	if ( code === 'rest_forbidden' ) {
		return new ApiError( 'forbidden', status );
	}
	if (
		code === 'rest_cookie_invalid_nonce' ||
		code === 'rest_not_logged_in'
	) {
		return new ApiError( 'session', status );
	}
	if ( status === 401 || status === 403 ) {
		return new ApiError( 'blocked', status );
	}
	// apiFetch's codes for an answer that is not JSON, and for no answer.
	if ( code === 'invalid_json' ) {
		return new ApiError( 'unreadable', status );
	}
	if ( code === 'fetch_error' ) {
		return new ApiError( 'offline', status );
	}
	// A report whose stored details would not fit in the memory a request may
	// use (`ReportTooLargeException`): retrying does not help.
	if ( code === 'updatelens_report_too_large' ) {
		return new ApiError( 'too_large', status );
	}
	return new ApiError( 'failed', status );
}

/**
 * The ApiError of a failed Response (apiFetch with `parse: false` rejects
 * with it): the REST error code from its JSON body when there is one, else
 * an answer that is not REST data, with the HTTP status either way.
 *
 * @param response Failed response.
 */
export async function responseError( response: Response ): Promise< ApiError > {
	let code = '';
	try {
		const body: unknown = await response.json();
		if ( body && typeof body === 'object' && 'code' in body ) {
			const value = ( body as { code: unknown } ).code;
			code = typeof value === 'string' ? value : '';
		}
	} catch {
		code = 'invalid_json';
	}
	return toApiError( { code, data: { status: response.status } } );
}
