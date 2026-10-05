import apiFetch from '@wordpress/api-fetch';

import type {
	AnalysesPage,
	AnalysisHistoryItem,
	AnalysisReport,
} from '../types/api';
import { toApiError } from './errors';

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
		const items = ( await response.json() ) as AnalysisHistoryItem[];

		return {
			items: Array.isArray( items ) ? items : [],
			total: headerInt( response, 'X-WP-Total' ),
			totalPages: headerInt( response, 'X-WP-TotalPages' ),
		};
	} catch ( error ) {
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
		return await apiFetch< AnalysisReport >( {
			path: `${ BASE_PATH }/${ id }`,
		} );
	} catch ( error ) {
		throw toApiError( error );
	}
}
