import apiFetch from '@wordpress/api-fetch';

import type {
	AnalysesPage,
	AnalysisHistoryItem,
	AnalysisReport,
} from '../types/api';
import { ApiError, responseError, toApiError } from './errors';

const BASE_PATH = '/updatelens/v1/analyses';

/** History page size used by the UI. */
export const PER_PAGE = 20;

function headerInt( response: Response, name: string ): number {
	const value = Number.parseInt( response.headers.get( name ) ?? '', 10 );
	return Number.isFinite( value ) && value > 0 ? value : 0;
}

/**
 * One page of the update history, newest first.
 *
 * Reads the pagination headers from the same response (`parse: false`).
 *
 * @param page    1-based page.
 * @param perPage Page size.
 * @throws {ApiError}
 */
export async function getAnalyses(
	page: number,
	perPage: number = PER_PAGE
): Promise< AnalysesPage > {
	try {
		const response = await apiFetch< Response, false >( {
			path: `${ BASE_PATH }?page=${ page }&per_page=${ perPage }`,
			parse: false,
		} );
		let items: unknown;
		try {
			items = await response.json();
		} catch {
			// A 200 that is not JSON: PHP output before the data, an HTML page.
			throw new ApiError( 'unreadable', response.status );
		}

		return {
			items: Array.isArray( items )
				? ( items as AnalysisHistoryItem[] )
				: [],
			total: headerInt( response, 'X-WP-Total' ),
			totalPages: headerInt( response, 'X-WP-TotalPages' ),
		};
	} catch ( error ) {
		if ( error instanceof Response ) {
			throw await responseError( error );
		}
		throw toApiError( error );
	}
}

/**
 * One analysis report.
 *
 * @param id Analysis ID.
 * @throws {ApiError}
 */
export async function getAnalysis( id: number ): Promise< AnalysisReport > {
	try {
		// Like the History: the response itself, so a failure keeps its
		// HTTP status (apiFetch drops it for an answer that is not JSON).
		const response: unknown = await apiFetch< Response, false >( {
			path: `${ BASE_PATH }/${ id }`,
			parse: false,
		} );
		// A middleware may answer with the parsed data instead.
		if ( ! ( response instanceof Response ) ) {
			return response as AnalysisReport;
		}
		try {
			return ( await response.json() ) as AnalysisReport;
		} catch {
			throw new ApiError( 'unreadable', response.status );
		}
	} catch ( error ) {
		if ( error instanceof Response ) {
			throw await responseError( error );
		}
		throw toApiError( error );
	}
}
