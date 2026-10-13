import { useEffect } from 'react';

import type { ApiError } from '../api/errors';

/** How the page is loaded again (replaceable in tests). */
export const pageLoad = {
	reload: () => window.location.reload(),
};

/** Failures of the site's REST API that a page load can work around. */
const REST_FAILURES: ReadonlyArray< ApiError[ 'kind' ] > = [
	'blocked',
	'unreadable',
	'failed',
];

/**
 * When a view reached by in-app navigation cannot be read because the
 * site's REST API failed, load the page again: the admin page then prints
 * the view's data itself (InitialData). Only after in-app navigation, so the
 * view the page was loaded for never reloads (no loop); never for errors a
 * reload cannot change (not found, too large, permissions, offline).
 *
 * @param error     The view's load error, or null.
 * @param navigated Whether the view was reached by in-app navigation.
 * @return Whether the page is being loaded again.
 */
export function usePageLoadFallback(
	error: ApiError | null,
	navigated: boolean
): boolean {
	const reload =
		error !== null && navigated && REST_FAILURES.includes( error.kind );

	useEffect( () => {
		if ( reload ) {
			pageLoad.reload();
		}
	}, [ reload ] );

	return reload;
}
