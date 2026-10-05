/**
 * Kinds of failure the UI distinguishes. Server messages are never shown.
 */
export type ApiErrorKind = 'not_found' | 'forbidden' | 'failed';

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
	if ( status === 401 || status === 403 || code === 'rest_forbidden' ) {
		return new ApiError( 'forbidden', status );
	}
	return new ApiError( 'failed', status );
}
