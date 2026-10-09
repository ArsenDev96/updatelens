import type { PhaseKey, Provider } from '../types/api';

/**
 * Screen state kept in the wp-admin URL, so reports can be bookmarked:
 *
 *   admin.php?page=updatelens                                   History
 *   admin.php?page=updatelens&paged=2                           History, page 2
 *   admin.php?page=updatelens&analysis=42                       Report 42, overview
 *   admin.php?page=updatelens&analysis=42&signal=cron           Report 42, WP-Cron details
 *   admin.php?page=updatelens&analysis=42&phase=during_update   …with a chosen phase
 *   …&signal=cron&phase=final&item=acme_sync                    …with rows of a hook located
 *   …&signal=action_scheduler&phase=final&item=acme&group=wc    …of a hook in a group
 *   …&signal=cron&phase=final&item=acme_sync&kind=removed       …only its removed rows
 *   …&item=acme_sync&kind=changed&finding=3                     …the rows of finding 3
 *
 * `paged` is kept on reports for the way back. Without `phase` the report
 * opens its default phase; unknown values fall back to the defaults. `item`
 * (an option name or hook, from a Potential Impact finding), `group`
 * (Action Scheduler; may be empty) and `kind` (which list: added, removed
 * or changed) only apply to a signal's details; `finding` (index in the
 * report's Potential Impact findings) lets it identify the exact rows.
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
			/** Rows to locate on the signal's details; null for none. */
			item?: RouteItem | null;
	  };

/** An option name or hook to locate, and for Action Scheduler its group. */
export interface RouteItem {
	name: string;
	/** Action Scheduler group (may be empty); null matches any group. */
	group: string | null;
	/** Only rows of this change list; null or absent matches every list. */
	kind?: RouteItemKind | null;
	/**
	 * Index of the finding in the report's `potential_impact.findings`, to
	 * identify its rows from its evidence; null or absent: by name only.
	 */
	finding?: number | null;
}

/** Change lists a located item can be limited to. */
export type RouteItemKind = 'added' | 'removed' | 'changed';

const ITEM_KINDS: readonly RouteItemKind[] = [ 'added', 'removed', 'changed' ];

export type ReportRoute = Extract< Route, { view: 'report' } >;

export const ANALYSIS_PARAM = 'analysis';
export const PAGE_PARAM = 'paged';
export const PHASE_PARAM = 'phase';
export const SIGNAL_PARAM = 'signal';
export const ITEM_PARAM = 'item';
export const GROUP_PARAM = 'group';
export const KIND_PARAM = 'kind';
export const FINDING_PARAM = 'finding';

const PHASES: readonly PhaseKey[] = [ 'during_update', 'post_update', 'final' ];
const SIGNALS: readonly Provider[] = [ 'options', 'cron', 'action_scheduler' ];

function positiveInt( value: string | null ): number | null {
	if ( ! value || ! /^\d+$/.test( value ) ) {
		return null;
	}
	const number = Number.parseInt( value, 10 );
	return number > 0 && Number.isSafeInteger( number ) ? number : null;
}

function nonNegativeInt( value: string | null ): number | null {
	if ( ! value || ! /^\d+$/.test( value ) ) {
		return null;
	}
	const number = Number.parseInt( value, 10 );
	return Number.isSafeInteger( number ) ? number : null;
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
	const signal = oneOf( SIGNALS, params.get( SIGNAL_PARAM ) );
	const name = params.get( ITEM_PARAM );
	return {
		view: 'report',
		id,
		page,
		phase: oneOf( PHASES, params.get( PHASE_PARAM ) ),
		signal,
		item:
			signal !== null && name
				? {
						name,
						group: params.get( GROUP_PARAM ),
						kind: oneOf( ITEM_KINDS, params.get( KIND_PARAM ) ),
						finding: nonNegativeInt( params.get( FINDING_PARAM ) ),
					}
				: null,
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
		ITEM_PARAM,
		GROUP_PARAM,
		KIND_PARAM,
		FINDING_PARAM,
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
		if ( route.signal && route.item ) {
			url.searchParams.set( ITEM_PARAM, route.item.name );
			if ( route.item.group !== null ) {
				url.searchParams.set( GROUP_PARAM, route.item.group );
			}
			if ( route.item.kind ) {
				url.searchParams.set( KIND_PARAM, route.item.kind );
			}
			if (
				route.item.finding !== undefined &&
				route.item.finding !== null
			) {
				url.searchParams.set(
					FINDING_PARAM,
					String( route.item.finding )
				);
			}
		}
	}

	return url.pathname + url.search + url.hash;
}
