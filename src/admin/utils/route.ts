/**
 * Screen state kept in the wp-admin URL, so reports can be bookmarked:
 *
 *   tools.php?page=updatelens              History
 *   tools.php?page=updatelens&paged=2      History, page 2
 *   tools.php?page=updatelens&analysis=42  Report 42 (`paged` is kept for the way back)
 */
export type Route =
	| { view: 'history'; page: number }
	| { view: 'report'; id: number; page: number };

export const ANALYSIS_PARAM = 'analysis';
export const PAGE_PARAM = 'paged';

function positiveInt( value: string | null ): number | null {
	if ( ! value || ! /^\d+$/.test( value ) ) {
		return null;
	}
	const number = Number.parseInt( value, 10 );
	return number > 0 && Number.isSafeInteger( number ) ? number : null;
}

/**
 * Route from a query string; invalid values fall back to History page 1.
 *
 * @param search `location.search`.
 */
export function parseRoute( search: string ): Route {
	const params = new URLSearchParams( search );
	const page = positiveInt( params.get( PAGE_PARAM ) ) ?? 1;
	const id = positiveInt( params.get( ANALYSIS_PARAM ) );

	return id === null
		? { view: 'history', page }
		: { view: 'report', id, page };
}

/**
 * URL (path + query + hash) for a route, keeping other query args such as `page=updatelens`.
 *
 * @param route   Route.
 * @param current Current location href.
 */
export function routeHref( route: Route, current: string ): string {
	const url = new URL( current );

	url.searchParams.delete( ANALYSIS_PARAM );
	url.searchParams.delete( PAGE_PARAM );
	if ( route.page > 1 ) {
		url.searchParams.set( PAGE_PARAM, String( route.page ) );
	}
	if ( route.view === 'report' ) {
		url.searchParams.set( ANALYSIS_PARAM, String( route.id ) );
	}

	return url.pathname + url.search + url.hash;
}
