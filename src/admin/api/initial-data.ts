import type {
	AnalysesPage,
	AnalysisHistoryItem,
	AnalysisReport,
} from '../types/api';
import { ApiError } from './errors';

/*
 * Data the admin page printed for the URL it was opened with
 * (includes/Admin/InitialData.php, `window.updatelensInitialData`): the
 * stored report or History page, read by PHP with the same read models as
 * the REST endpoints, so they open even when the site's REST API does not
 * answer. Screens use it only for the view the page was loaded for (App's
 * `initialView`): views reached by in-app navigation and live refreshes read
 * over REST, and when such a read fails, the screen loads the page again
 * (usePageLoadFallback), which prints the new view's data.
 */

type Raw = Record< string, unknown >;

const isRecord = ( value: unknown ): value is Raw =>
	typeof value === 'object' && value !== null && ! Array.isArray( value );

/** The printed data, if any. */
function printed(): Raw | null {
	const data = ( window as unknown as Record< string, unknown > )
		.updatelensInitialData;
	return isRecord( data ) ? data : null;
}

/**
 * The printed report for `id`: its data, or the failure PHP saw (not found,
 * too large); null when there is none (another view or report, or storage
 * unreadable when the page loaded), so the caller reads over REST.
 *
 * @param id Analysis ID.
 */
export function initialReport( id: number ): Promise< AnalysisReport > | null {
	const data = printed();
	if ( ! data || data.view !== 'report' || data.id !== id ) {
		return null;
	}
	switch ( data.status ) {
		case 'ok':
			return isRecord( data.report )
				? Promise.resolve( data.report as unknown as AnalysisReport )
				: null;
		case 'not_found':
			return Promise.reject( new ApiError( 'not_found', 404 ) );
		case 'too_large':
			return Promise.reject( new ApiError( 'too_large', 500 ) );
		default:
			return null;
	}
}

/**
 * The printed History page `page`, or null (read over REST).
 *
 * @param page    1-based page.
 * @param perPage Page size the screen asks for.
 */
export function initialHistory(
	page: number,
	perPage: number
): Promise< AnalysesPage > | null {
	const data = printed();
	if (
		! data ||
		data.view !== 'history' ||
		data.status !== 'ok' ||
		data.page !== page ||
		data.per_page !== perPage ||
		! Array.isArray( data.items ) ||
		typeof data.total !== 'number' ||
		typeof data.total_pages !== 'number'
	) {
		return null;
	}
	return Promise.resolve( {
		items: data.items as AnalysisHistoryItem[],
		total: data.total,
		totalPages: data.total_pages,
	} );
}

/**
 * The printed monitoring start (the `/baseline` response's fields), when a
 * History page printed it; null otherwise. It does not change once
 * recorded, so it serves later History pages too.
 */
export function initialMonitoring(): unknown {
	const data = printed();
	return data && data.view === 'history' && isRecord( data.monitoring )
		? data.monitoring
		: null;
}
