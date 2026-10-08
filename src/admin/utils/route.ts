import type { PhaseKey, Provider } from '../types/api';

/**
 * Screen state kept in the wp-admin URL, so reports can be bookmarked:
 *
 *   admin.php?page=updatelens                                   History
 *   admin.php?page=updatelens&paged=2                           History, page 2
 *   admin.php?page=updatelens&analysis=42                       Report 42, overview
 *   admin.php?page=updatelens&analysis=42&signal=cron           Report 42, WP-Cron details
 *   admin.php?page=updatelens&analysis=42&phase=during_update   …with a chosen phase
 *
 * `paged` is kept on reports for the way back. Without `phase` the report
 * opens its default phase; unknown values fall back to the defaults.
 */
export type Route =
	| { view: 'history'; page: number }
	| {
			view: 'report';
			id: number;
			page: number;
			/** Explicitly chosen phase; null opens the report's default phase. */
			phase: PhaseKey | null;
			/** Signal whose details are shown; null shows the overview. */
			signal: Provider | null;
	  };

export type ReportRoute = Extract< Route, { view: 'report' } >;

export const ANALYSIS_PARAM = 'analysis';
export const PAGE_PARAM = 'paged';
export const PHASE_PARAM = 'phase';
export const SIGNAL_PARAM = 'signal';

const PHASES: readonly PhaseKey[] = [ 'during_update', 'post_update', 'final' ];
const SIGNALS: readonly Provider[] = [ 'options', 'cron', 'action_scheduler' ];

function positiveInt( value: string | null ): number | null {
	if ( ! value || ! /^\d+$/.test( value ) ) {
		return null;
	}
	const number = Number.parseInt( value, 10 );
	return number > 0 && Number.isSafeInteger( number ) ? number : null;
}

function oneOf< T extends string >(
	values: readonly T[],
	value: string | null
): T | null {
	return values.find( ( candidate ) => candidate === value ) ?? null;
}

/**
 * Route from a query string; invalid values fall back to History page 1,
 * the report overview or the default phase.
 *
 * @param search `location.search`.
 */
export function parseRoute( search: string ): Route {
	const params = new URLSearchParams( search );
	const page = positiveInt( params.get( PAGE_PARAM ) ) ?? 1;
	const id = positiveInt( params.get( ANALYSIS_PARAM ) );

	if ( id === null ) {
		return { view: 'history', page };
	}
	return {
		view: 'report',
		id,
		page,
		phase: oneOf( PHASES, params.get( PHASE_PARAM ) ),
		signal: oneOf( SIGNALS, params.get( SIGNAL_PARAM ) ),
	};
}

/**
 * URL (path + query + hash) for a route, keeping other query args such as `page=updatelens`.
 *
 * @param route   Route.
 * @param current Current location href.
 */
export function routeHref( route: Route, current: string ): string {
	const url = new URL( current );

	for ( const param of [
		ANALYSIS_PARAM,
		PAGE_PARAM,
		PHASE_PARAM,
		SIGNAL_PARAM,
	] ) {
		url.searchParams.delete( param );
	}
	if ( route.page > 1 ) {
		url.searchParams.set( PAGE_PARAM, String( route.page ) );
	}
	if ( route.view === 'report' ) {
		url.searchParams.set( ANALYSIS_PARAM, String( route.id ) );
		if ( route.signal ) {
			url.searchParams.set( SIGNAL_PARAM, route.signal );
		}
		if ( route.phase ) {
			url.searchParams.set( PHASE_PARAM, route.phase );
		}
	}

	return url.pathname + url.search + url.hash;
}
