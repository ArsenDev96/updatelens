/**
 * API responses for tests, based on real Reports API output for the
 * disposable "UpdateLens Fixture A" plugin (docs/rest-api.md).
 */
import { vi } from 'vitest';

import type {
	AnalysisHistoryItem,
	AnalysisReport,
	AvailablePhase,
	UnavailablePhase,
} from '@/admin/types/api';

export const DURING_UPDATE: AvailablePhase = {
	available: true,
	association: 'update_request',
	summary: {
		before_option_count: 154,
		after_option_count: 155,
		option_count_delta: 1,
		before_total_bytes: 30459,
		after_total_bytes: 30462,
		total_bytes_delta: 3,
		before_autoloaded_count: 128,
		after_autoloaded_count: 128,
		autoloaded_count_delta: 0,
		before_autoloaded_bytes: 15467,
		after_autoloaded_bytes: 15467,
		autoloaded_bytes_delta: 0,
		added_count: 1,
		removed_count: 0,
		changed_count: 0,
		value_changed_count: 0,
		autoload_value_changed_count: 0,
		autoload_behavior_changed_count: 0,
	},
	added: [
		{
			name: 'ulfx_a_needs_migration',
			size: 3,
			autoload: 'off',
			is_autoloaded: false,
		},
	],
	removed: [],
	changed: [],
};

const CHANGED = [
	{
		name: 'ulfx_a_api_key',
		value_changed: true,
		before_size: 37,
		after_size: 40,
		size_delta: 3,
		before_autoload: 'off',
		after_autoload: 'off',
		autoload_value_changed: false,
		before_is_autoloaded: false,
		after_is_autoloaded: false,
		autoload_behavior_changed: false,
	},
	{
		name: 'ulfx_a_db_version',
		value_changed: true,
		before_size: 5,
		after_size: 5,
		size_delta: 0,
		before_autoload: 'auto',
		after_autoload: 'auto',
		autoload_value_changed: false,
		before_is_autoloaded: true,
		after_is_autoloaded: true,
		autoload_behavior_changed: false,
	},
	{
		name: 'ulfx_a_setting',
		value_changed: true,
		before_size: 3,
		after_size: 3,
		size_delta: 0,
		before_autoload: 'auto',
		after_autoload: 'auto',
		autoload_value_changed: false,
		before_is_autoloaded: true,
		after_is_autoloaded: true,
		autoload_behavior_changed: false,
	},
];

export const POST_UPDATE: AvailablePhase = {
	available: true,
	association: 'observed_after_update',
	summary: {
		before_option_count: 155,
		after_option_count: 155,
		option_count_delta: 0,
		before_total_bytes: 30462,
		after_total_bytes: 28450,
		total_bytes_delta: -2012,
		before_autoloaded_count: 128,
		after_autoloaded_count: 129,
		autoloaded_count_delta: 1,
		before_autoloaded_bytes: 15467,
		after_autoloaded_bytes: 15497,
		autoloaded_bytes_delta: 30,
		added_count: 2,
		removed_count: 2,
		changed_count: 3,
		value_changed_count: 3,
		autoload_value_changed_count: 0,
		autoload_behavior_changed_count: 0,
	},
	added: [
		{
			name: 'recently_activated',
			size: 6,
			autoload: 'off',
			is_autoloaded: false,
		},
		{
			name: 'ulfx_a_feature_flags',
			size: 30,
			autoload: 'on',
			is_autoloaded: true,
		},
	],
	removed: [
		{
			name: 'ulfx_a_legacy_cache',
			size: 2048,
			autoload: 'off',
			is_autoloaded: false,
		},
		{
			name: 'ulfx_a_needs_migration',
			size: 3,
			autoload: 'off',
			is_autoloaded: false,
		},
	],
	changed: CHANGED,
};

export const FINAL: AvailablePhase = {
	available: true,
	association: 'net_across_phases',
	summary: {
		before_option_count: 154,
		after_option_count: 155,
		option_count_delta: 1,
		before_total_bytes: 30459,
		after_total_bytes: 28450,
		total_bytes_delta: -2009,
		before_autoloaded_count: 128,
		after_autoloaded_count: 129,
		autoloaded_count_delta: 1,
		before_autoloaded_bytes: 15467,
		after_autoloaded_bytes: 15497,
		autoloaded_bytes_delta: 30,
		added_count: 2,
		removed_count: 1,
		changed_count: 3,
		value_changed_count: 3,
		autoload_value_changed_count: 0,
		autoload_behavior_changed_count: 0,
	},
	added: POST_UPDATE.added,
	removed: [ POST_UPDATE.removed[ 0 ] ],
	changed: CHANGED,
};

export function unavailable(
	association: UnavailablePhase[ 'association' ],
	reason: UnavailablePhase[ 'reason' ]
): UnavailablePhase {
	return { available: false, association, reason };
}

export const COMPLETED: AnalysisReport = {
	id: 1,
	plugin: {
		file: 'updatelens-fixture-a/updatelens-fixture-a.php',
		name: 'UpdateLens Fixture A',
		version_before: '1.0.0',
		version_after: '1.1.0',
	},
	status: 'completed',
	settle_outcome: 'admin_shutdown',
	timestamps: {
		started_at: '2026-10-05T18:46:06Z',
		settle_deadline: '2026-10-05T18:51:08Z',
		completed_at: '2026-10-05T18:46:09Z',
	},
	phases: {
		during_update: DURING_UPDATE,
		post_update: POST_UPDATE,
		final: FINAL,
	},
	error: null,
};

export const EXPIRED: AnalysisReport = {
	...COMPLETED,
	id: 2,
	settle_outcome: 'expired',
	timestamps: {
		...COMPLETED.timestamps,
		completed_at: '2026-10-05T19:10:41Z',
	},
	phases: {
		during_update: DURING_UPDATE,
		post_update: unavailable( 'observed_after_update', 'settle_expired' ),
		final: unavailable( 'net_across_phases', 'settle_expired' ),
	},
};

export const FAILED: AnalysisReport = {
	...COMPLETED,
	id: 3,
	plugin: { ...COMPLETED.plugin, version_after: null },
	status: 'failed',
	settle_outcome: 'not_applicable',
	error: { code: 'update_not_installed' },
	phases: {
		during_update: unavailable( 'update_request', 'update_failed' ),
		post_update: unavailable( 'observed_after_update', 'update_failed' ),
		final: unavailable( 'net_across_phases', 'update_failed' ),
	},
};

export const INCOMPATIBLE: AnalysisReport = {
	...COMPLETED,
	id: 4,
	status: 'incompatible',
	error: { code: 'fingerprint_context_changed' },
	phases: {
		during_update: DURING_UPDATE,
		post_update: unavailable(
			'observed_after_update',
			'fingerprint_context_changed'
		),
		final: unavailable(
			'net_across_phases',
			'fingerprint_context_changed'
		),
	},
};

export const CORRUPT_POST: AnalysisReport = {
	...COMPLETED,
	id: 5,
	phases: {
		during_update: DURING_UPDATE,
		post_update: unavailable( 'observed_after_update', 'data_corrupt' ),
		final: FINAL,
	},
};

export const AWAITING: AnalysisReport = {
	...COMPLETED,
	id: 6,
	status: 'awaiting_settle',
	settle_outcome: null,
	timestamps: { ...COMPLETED.timestamps, completed_at: null },
	phases: {
		during_update: DURING_UPDATE,
		post_update: unavailable( 'observed_after_update', 'awaiting_settle' ),
		final: unavailable( 'net_across_phases', 'awaiting_settle' ),
	},
};

export function historyItem(
	report: AnalysisReport,
	overrides: Partial< AnalysisHistoryItem > = {}
): AnalysisHistoryItem {
	return {
		id: report.id,
		plugin: report.plugin,
		status: report.status,
		settle_outcome: report.settle_outcome,
		timestamps: report.timestamps,
		error: report.error,
		has_during_update: report.phases.during_update.available,
		has_post_update: report.phases.post_update.available,
		has_final: report.phases.final.available,
		...overrides,
	};
}

/** A Response like the history endpoint's, with WordPress pagination headers. */
export function listResponse(
	items: AnalysisHistoryItem[],
	total = items.length,
	totalPages = total === 0 ? 0 : 1
): Response {
	return new Response( JSON.stringify( items ), {
		status: 200,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Total': String( total ),
			'X-WP-TotalPages': String( totalPages ),
		},
	} );
}

/** REST error body, as apiFetch rejects with when parsing. */
export function restError( code: string, status: number ) {
	return { code, message: `Server says ${ code }`, data: { status } };
}

/** Promise that never settles (loading states). */
export function pending< T >(): Promise< T > {
	return new Promise< T >( vi.fn() );
}
