import apiFetch from '@wordpress/api-fetch';

import type { BaselinePlugin, MonitoringBaseline } from '../types/api';
import { toApiError } from './errors';

const PATH = '/updatelens/v1/baseline';

function isPlugin( value: unknown ): value is BaselinePlugin {
	if ( ! value || typeof value !== 'object' ) {
		return false;
	}
	const plugin = value as Record< string, unknown >;
	return (
		typeof plugin.file === 'string' &&
		typeof plugin.name === 'string' &&
		typeof plugin.version === 'string' &&
		typeof plugin.active === 'boolean'
	);
}

/**
 * Response fields of the expected types; anything else is unknown (null), so
 * an unexpected response shows the fallback instead of breaking the page.
 *
 * @param data Response body.
 */
function normalize( data: unknown ): MonitoringBaseline {
	const body =
		data && typeof data === 'object'
			? ( data as Record< string, unknown > )
			: {};
	const plugins =
		Array.isArray( body.plugins ) && body.plugins.every( isPlugin )
			? body.plugins
			: null;

	return {
		started_at:
			typeof body.started_at === 'string' ? body.started_at : null,
		plugin_count:
			plugins && typeof body.plugin_count === 'number'
				? body.plugin_count
				: null,
		plugins:
			plugins && typeof body.plugin_count === 'number' ? plugins : null,
	};
}

/**
 * Monitoring start with the plugins installed then.
 *
 * @throws {ApiError}
 */
export async function getBaseline(): Promise< MonitoringBaseline > {
	try {
		return normalize( await apiFetch( { path: PATH } ) );
	} catch ( error ) {
		throw toApiError( error );
	}
}

/**
 * Monitoring start only, without the plugin list (`_fields`).
 *
 * @throws {ApiError}
 */
export async function getMonitoringStart(): Promise< string | null > {
	try {
		return normalize(
			await apiFetch( { path: `${ PATH }?_fields=started_at` } )
		).started_at;
	} catch ( error ) {
		throw toApiError( error );
	}
}
