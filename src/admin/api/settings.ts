import apiFetch from '@wordpress/api-fetch';

import type { UpdateLensSettings } from '../types/api';
import { ApiError, toApiError } from './errors';

const PATH = '/updatelens/v1/settings';

/**
 * Settings from a response body. An unexpected shape is a failure, never a
 * guessed state: the screen must show the value the server stored.
 *
 * @param data Response body.
 * @throws {ApiError}
 */
function normalize( data: unknown ): UpdateLensSettings {
	const siteChecks =
		data && typeof data === 'object' && 'site_checks' in data
			? ( data.site_checks as Record< string, unknown > | null )
			: null;
	if (
		! siteChecks ||
		typeof siteChecks !== 'object' ||
		typeof siteChecks.enabled !== 'boolean' ||
		typeof siteChecks.effective !== 'boolean' ||
		( siteChecks.restriction !== null &&
			siteChecks.restriction !== 'filter' )
	) {
		throw new ApiError( 'failed', null );
	}

	return {
		site_checks: {
			enabled: siteChecks.enabled,
			effective: siteChecks.effective,
			restriction: siteChecks.restriction,
		},
	};
}

/**
 * The stored settings.
 *
 * @throws {ApiError}
 */
export async function getSettings(): Promise< UpdateLensSettings > {
	try {
		return normalize( await apiFetch( { path: PATH } ) );
	} catch ( error ) {
		throw toApiError( error );
	}
}

/**
 * Save the site check preference; resolves with the settings stored now.
 *
 * @param enabled Whether site checks should be on.
 * @throws {ApiError}
 */
export async function saveSiteChecks(
	enabled: boolean
): Promise< UpdateLensSettings > {
	try {
		return normalize(
			await apiFetch( {
				path: PATH,
				method: 'POST',
				data: { site_checks: { enabled } },
			} )
		);
	} catch ( error ) {
		throw toApiError( error );
	}
}
