/**
 * API responses for tests, based on real Reports API output for the
 * disposable "UpdateLens Fixture A" plugin (options) and the
 * "UL Cron Fixture" update 1.4.0 → 1.5.0 (WP-Cron) (docs/rest-api.md).
 */
import { vi } from 'vitest';

import type {
	ActionSchedulerPhase,
	AnalysisHistoryItem,
	AnalysisReport,
	AvailableCronPhase,
	AvailableOptionsPhase,
	CronPhase,
	HistorySignal,
	ImpactFinding,
	MonitoringBaseline,
	OptionsPhase,
	PhaseKey,
	PotentialImpact,
	Provider,
	ReportPhase,
	UnavailableActionSchedulerPhase,
	UnavailableCronPhase,
	UnavailableOptionsPhase,
} from '@/admin/types/api';

export const DURING_UPDATE: AvailableOptionsPhase = {
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

export const POST_UPDATE: AvailableOptionsPhase = {
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

export const FINAL: AvailableOptionsPhase = {
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
	association: UnavailableOptionsPhase[ 'association' ],
	reason: UnavailableOptionsPhase[ 'reason' ]
): UnavailableOptionsPhase {
	return { available: false, association, reason };
}

export function cronUnavailable(
	association: UnavailableCronPhase[ 'association' ],
	reason: UnavailableCronPhase[ 'reason' ]
): UnavailableCronPhase {
	return { available: false, association, reason };
}

export function asUnavailable(
	association: UnavailableActionSchedulerPhase[ 'association' ],
	reason: UnavailableActionSchedulerPhase[ 'reason' ]
): UnavailableActionSchedulerPhase {
	return { available: false, association, reason };
}

/** Every Action Scheduler phase unavailable for the same reason. */
export function asEverywhere(
	reason: UnavailableActionSchedulerPhase[ 'reason' ]
): Record< PhaseKey, ActionSchedulerPhase > {
	return {
		during_update: asUnavailable( 'update_request', reason ),
		post_update: asUnavailable( 'observed_after_update', reason ),
		final: asUnavailable( 'net_across_phases', reason ),
	};
}

/** 2026-10-06T10:00:00Z. */
export const T = 1791280800;

/** Cron hook names repeat across lists; the core job is shown, never hidden. */
export const CRON_DURING: AvailableCronPhase = {
	available: true,
	association: 'update_request',
	summary: {
		before_event_count: 12,
		after_event_count: 13,
		event_count_delta: 1,
		before_recurring_count: 11,
		after_recurring_count: 11,
		recurring_count_delta: 0,
		before_single_count: 1,
		after_single_count: 2,
		single_count_delta: 1,
		before_unique_hook_count: 11,
		after_unique_hook_count: 11,
		unique_hook_count_delta: 0,
		added_count: 1,
		removed_count: 0,
		rescheduled_count: 0,
		changed_count: 0,
	},
	added: [
		{
			hook: 'ul_fixture_update_once',
			timestamp: T + 600,
			schedule: null,
			interval: null,
			is_recurring: false,
		},
	],
	removed: [],
	rescheduled: [],
	changed: [],
};

export const CRON_POST: AvailableCronPhase = {
	available: true,
	association: 'observed_after_update',
	summary: {
		before_event_count: 13,
		after_event_count: 13,
		event_count_delta: 0,
		before_recurring_count: 11,
		after_recurring_count: 11,
		recurring_count_delta: 0,
		before_single_count: 2,
		after_single_count: 2,
		single_count_delta: 0,
		before_unique_hook_count: 11,
		after_unique_hook_count: 11,
		unique_hook_count_delta: 0,
		added_count: 1,
		removed_count: 1,
		rescheduled_count: 2,
		changed_count: 0,
	},
	added: [
		{
			hook: 'ul_fixture_task_1.5.0',
			timestamp: T + 1800,
			schedule: 'hourly',
			interval: 3600,
			is_recurring: true,
		},
	],
	removed: [
		{
			hook: 'ul_fixture_task_1.4.0',
			timestamp: T + 1200,
			schedule: 'hourly',
			interval: 3600,
			is_recurring: true,
		},
	],
	rescheduled: [
		{
			hook: 'ul_fixture_cleanup',
			before_timestamp: T + 3600,
			after_timestamp: T + 10800,
			timestamp_delta: 7200,
			schedule: 'daily',
			interval: 86400,
			is_recurring: true,
		},
		{
			hook: 'wp_privacy_delete_old_export_files',
			before_timestamp: T,
			after_timestamp: T + 3600,
			timestamp_delta: 3600,
			schedule: 'hourly',
			interval: 3600,
			is_recurring: true,
		},
	],
	changed: [],
};

export const CRON_FINAL: AvailableCronPhase = {
	available: true,
	association: 'net_across_phases',
	summary: {
		before_event_count: 12,
		after_event_count: 13,
		event_count_delta: 1,
		before_recurring_count: 11,
		after_recurring_count: 11,
		recurring_count_delta: 0,
		before_single_count: 1,
		after_single_count: 2,
		single_count_delta: 1,
		before_unique_hook_count: 11,
		after_unique_hook_count: 11,
		unique_hook_count_delta: 0,
		added_count: 2,
		removed_count: 1,
		rescheduled_count: 2,
		changed_count: 0,
	},
	added: [ CRON_POST.added[ 0 ], CRON_DURING.added[ 0 ] ],
	removed: CRON_POST.removed,
	rescheduled: CRON_POST.rescheduled,
	changed: [],
};

/**
 * Report phases from options, Cron and Action Scheduler phases per phase.
 * Action Scheduler defaults to a site without it (`not_installed`).
 *
 * @param options         Options phases.
 * @param cron            Cron phases.
 * @param actionScheduler Action Scheduler phases.
 */
export function phases(
	options: Record< PhaseKey, OptionsPhase >,
	cron: Record< PhaseKey, CronPhase >,
	actionScheduler: Record< PhaseKey, ActionSchedulerPhase > = asEverywhere(
		'not_installed'
	)
): Record< PhaseKey, ReportPhase > {
	return {
		during_update: {
			options: options.during_update,
			cron: cron.during_update,
			action_scheduler: actionScheduler.during_update,
		},
		post_update: {
			options: options.post_update,
			cron: cron.post_update,
			action_scheduler: actionScheduler.post_update,
		},
		final: {
			options: options.final,
			cron: cron.final,
			action_scheduler: actionScheduler.final,
		},
	};
}

const OPTIONS_ALL = {
	during_update: DURING_UPDATE,
	post_update: POST_UPDATE,
	final: FINAL,
};

const CRON_ALL = {
	during_update: CRON_DURING,
	post_update: CRON_POST,
	final: CRON_FINAL,
};

/** Every phase of one signal unavailable for the same reason. */
function cronEverywhere(
	reason: UnavailableCronPhase[ 'reason' ]
): Record< PhaseKey, CronPhase > {
	return {
		during_update: cronUnavailable( 'update_request', reason ),
		post_update: cronUnavailable( 'observed_after_update', reason ),
		final: cronUnavailable( 'net_across_phases', reason ),
	};
}

/**
 * Potential Impact as the API reports it for these phases: every signal
 * with a Net result evaluated (with the given findings), Action Scheduler
 * absent at every phase not applicable, everything else not evaluated with
 * its Net result reason. Fixture shape only; the rules are the API's.
 *
 * @param reportPhases Report phases.
 * @param findings     Findings of the evaluated signals.
 */
export function impactOf(
	reportPhases: Record< PhaseKey, ReportPhase >,
	findings: ImpactFinding[] = []
): PotentialImpact {
	const providers: Provider[] = [ 'options', 'cron', 'action_scheduler' ];
	const rules = {
		options: [ 'large_autoloaded_option' ],
		cron: [ 'recurring_cron_event_removed', 'recurring_schedule_changed' ],
		action_scheduler: [ 'recurring_schedule_changed' ],
	} as const;
	const absent = (
		[ 'during_update', 'post_update', 'final' ] as const
	 ).every( ( key ) => {
		const signal = reportPhases[ key ].action_scheduler;
		return ! signal.available && signal.reason === 'not_installed';
	} );
	const signals = Object.fromEntries(
		providers.map( ( provider ) => {
			const final = reportPhases.final[ provider ];
			if ( final.available ) {
				return [
					provider,
					{
						status: 'evaluated',
						reason: null,
						rules: [ ...rules[ provider ] ],
						finding_count: findings.filter(
							( finding ) => finding.signal === provider
						).length,
					},
				];
			}
			return [
				provider,
				{
					status:
						provider === 'action_scheduler' && absent
							? 'not_applicable'
							: 'not_evaluated',
					reason: final.reason,
					rules: [ ...rules[ provider ] ],
					finding_count: null,
				},
			];
		} )
	) as PotentialImpact[ 'signals' ];
	const statuses = providers.map(
		( provider ) => signals[ provider ].status
	);
	const evaluated = statuses.filter(
		( status ) => status === 'evaluated'
	).length;
	const missing = statuses.filter(
		( status ) => status === 'not_evaluated'
	).length;
	let status: PotentialImpact[ 'status' ] = 'partial';
	if ( evaluated === 0 ) {
		status = 'not_evaluated';
	} else if ( missing === 0 ) {
		status = 'evaluated';
	}
	return {
		phase: 'final',
		status,
		signals,
		findings: status === 'not_evaluated' ? [] : findings,
	};
}

/**
 * A report with Potential Impact derived from its phases (impactOf()).
 *
 * @param report   Report without (or with a stale) Potential Impact.
 * @param findings Findings of the evaluated signals.
 */
export function withImpact(
	report: Omit< AnalysisReport, 'potential_impact' > & {
		potential_impact?: PotentialImpact;
	},
	findings: ImpactFinding[] = []
): AnalysisReport {
	return {
		...report,
		potential_impact: impactOf( report.phases, findings ),
	};
}

export const COMPLETED: AnalysisReport = withImpact( {
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
	observation_window_seconds: 300,
	phases: phases( OPTIONS_ALL, CRON_ALL ),
	error: null,
} );

export const EXPIRED: AnalysisReport = withImpact( {
	...COMPLETED,
	id: 2,
	settle_outcome: 'expired',
	timestamps: {
		...COMPLETED.timestamps,
		completed_at: '2026-10-05T19:10:41Z',
	},
	phases: phases(
		{
			during_update: DURING_UPDATE,
			post_update: unavailable(
				'observed_after_update',
				'settle_expired'
			),
			final: unavailable( 'net_across_phases', 'settle_expired' ),
		},
		{
			during_update: CRON_DURING,
			post_update: cronUnavailable(
				'observed_after_update',
				'settle_expired'
			),
			final: cronUnavailable( 'net_across_phases', 'settle_expired' ),
		}
	),
} );

export const FAILED: AnalysisReport = withImpact( {
	...COMPLETED,
	id: 3,
	plugin: { ...COMPLETED.plugin, version_after: null },
	status: 'failed',
	settle_outcome: 'not_applicable',
	error: { code: 'update_not_installed' },
	phases: phases(
		{
			during_update: unavailable( 'update_request', 'update_failed' ),
			post_update: unavailable(
				'observed_after_update',
				'update_failed'
			),
			final: unavailable( 'net_across_phases', 'update_failed' ),
		},
		cronEverywhere( 'update_failed' )
	),
} );

export const INCOMPATIBLE: AnalysisReport = withImpact( {
	...COMPLETED,
	id: 4,
	status: 'incompatible',
	error: { code: 'fingerprint_context_changed' },
	phases: phases(
		{
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
		{
			during_update: CRON_DURING,
			post_update: cronUnavailable(
				'observed_after_update',
				'analysis_ended'
			),
			final: cronUnavailable( 'net_across_phases', 'analysis_ended' ),
		}
	),
} );

export const CORRUPT_POST: AnalysisReport = withImpact( {
	...COMPLETED,
	id: 5,
	phases: phases(
		{
			during_update: DURING_UPDATE,
			post_update: unavailable( 'observed_after_update', 'data_corrupt' ),
			final: FINAL,
		},
		CRON_ALL
	),
} );

export const AWAITING: AnalysisReport = withImpact( {
	...COMPLETED,
	id: 6,
	status: 'awaiting_settle',
	settle_outcome: null,
	timestamps: { ...COMPLETED.timestamps, completed_at: null },
	phases: phases(
		{
			during_update: DURING_UPDATE,
			post_update: unavailable(
				'observed_after_update',
				'awaiting_settle'
			),
			final: unavailable( 'net_across_phases', 'awaiting_settle' ),
		},
		{
			during_update: CRON_DURING,
			post_update: cronUnavailable(
				'observed_after_update',
				'awaiting_settle'
			),
			final: cronUnavailable( 'net_across_phases', 'awaiting_settle' ),
		}
	),
} );

/** Options complete; WP-Cron after-update lost (partial availability). */
export const PARTIAL_CRON: AnalysisReport = withImpact( {
	...COMPLETED,
	id: 7,
	phases: phases( OPTIONS_ALL, {
		during_update: CRON_DURING,
		post_update: cronUnavailable(
			'observed_after_update',
			'snapshot_unavailable'
		),
		final: CRON_FINAL,
	} ),
} );

/** Options complete; the site's Cron state was malformed. */
export const MALFORMED_CRON: AnalysisReport = withImpact( {
	...COMPLETED,
	id: 8,
	phases: phases( OPTIONS_ALL, cronEverywhere( 'malformed_cron_state' ) ),
} );

/** Options unreadable in every phase, WP-Cron available. */
export const CRON_ONLY: AnalysisReport = withImpact( {
	...COMPLETED,
	id: 9,
	phases: phases(
		{
			during_update: unavailable( 'update_request', 'data_corrupt' ),
			post_update: unavailable( 'observed_after_update', 'data_corrupt' ),
			final: unavailable( 'net_across_phases', 'data_corrupt' ),
		},
		CRON_ALL
	),
} );

/** A report from before WP-Cron observation (migrated history). */
export const PRE_CRON: AnalysisReport = withImpact( {
	...COMPLETED,
	id: 10,
	phases: phases( OPTIONS_ALL, cronEverywhere( 'not_captured' ) ),
} );

/**
 * History flags of one signal, from the report's lists (the API computes
 * them in SQL; a stored but unreadable diff would still count as recorded).
 *
 * @param signal Report signal.
 */
function historySignal(
	signal: OptionsPhase | CronPhase | ActionSchedulerPhase
): HistorySignal {
	if ( ! signal.available ) {
		return { recorded: false, has_changes: null };
	}
	const lists: unknown[][] = [ signal.added, signal.removed, signal.changed ];
	if ( 'rescheduled' in signal ) {
		lists.push( signal.rescheduled );
	}
	return {
		recorded: true,
		has_changes: lists.some( ( list ) => list.length > 0 ),
	};
}

export function historyItem(
	report: AnalysisReport,
	overrides: Partial< AnalysisHistoryItem > = {}
): AnalysisHistoryItem {
	const phase = ( key: PhaseKey ) => ( {
		options: historySignal( report.phases[ key ].options ),
		cron: historySignal( report.phases[ key ].cron ),
		action_scheduler: historySignal(
			report.phases[ key ].action_scheduler
		),
	} );
	return {
		id: report.id,
		plugin: report.plugin,
		status: report.status,
		settle_outcome: report.settle_outcome,
		timestamps: report.timestamps,
		error: report.error,
		has_during_update: report.phases.during_update.options.available,
		has_post_update: report.phases.post_update.options.available,
		has_final: report.phases.final.options.available,
		phases: {
			during_update: phase( 'during_update' ),
			post_update: phase( 'post_update' ),
			final: phase( 'final' ),
		},
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

/** GET /updatelens/v1/baseline of a fresh install (UpdateLens itself is never listed). */
export const BASELINE: MonitoringBaseline = {
	started_at: '2026-10-07T22:32:00Z',
	plugin_count: 4,
	plugins: [
		{
			file: 'classic-editor/classic-editor.php',
			name: 'Classic Editor',
			version: '1.7.0',
			active: false,
		},
		{
			file: 'elementor/elementor.php',
			name: 'Elementor',
			version: '4.3.4',
			active: true,
		},
		{
			file: 'newsletter/plugin.php',
			name: 'Newsletter',
			version: '9.4.7',
			active: true,
		},
		{
			file: 'woocommerce/woocommerce.php',
			name: 'WooCommerce',
			version: '11.1.2',
			active: true,
		},
	],
};

/** Baseline fields when no readable baseline exists. */
export const UNKNOWN_BASELINE: MonitoringBaseline = {
	started_at: null,
	plugin_count: null,
	plugins: null,
};
