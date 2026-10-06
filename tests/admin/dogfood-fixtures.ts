/**
 * Reports shaped like the dogfooding study's real outcomes (October 2026,
 * WordPress 7.1.2): names, sizes and counts as observed, never values or
 * Cron arguments. Summaries are derived from the lists, like the API's.
 */
import type {
	AddedCronEvent,
	AnalysisReport,
	AvailableCronPhase,
	AvailableOptionsPhase,
	ChangedOption,
	OptionState,
	PhaseAssociation,
	RemovedCronEvent,
	RescheduledCronEvent,
} from '@/admin/types/api';

import { COMPLETED, phases, T } from './fixtures';

/**
 * Options phase from its lists; totals start at `options` options.
 *
 * @param association Phase association.
 * @param lists       Added, removed and changed options.
 * @param lists.added
 * @param lists.removed
 * @param lists.changed
 * @param options     Option count before.
 */
export function optionsPhase(
	association: PhaseAssociation,
	{
		added = [],
		removed = [],
		changed = [],
	}: {
		added?: OptionState[];
		removed?: OptionState[];
		changed?: ChangedOption[];
	},
	options = 140
): AvailableOptionsPhase {
	const size = ( list: OptionState[] ) =>
		list.reduce( ( sum, o ) => sum + o.size, 0 );
	const autoloaded = ( list: OptionState[] ) =>
		list.filter( ( o ) => o.is_autoloaded );
	const sizeDelta =
		size( added ) -
		size( removed ) +
		changed.reduce( ( sum, o ) => sum + o.size_delta, 0 );
	const autoloadedDelta =
		size( autoloaded( added ) ) -
		size( autoloaded( removed ) ) +
		changed
			.filter( ( o ) => o.after_is_autoloaded && o.before_is_autoloaded )
			.reduce( ( sum, o ) => sum + o.size_delta, 0 );
	const countDelta = added.length - removed.length;
	const autoloadedCountDelta =
		autoloaded( added ).length - autoloaded( removed ).length;
	const bytes = 6124;

	return {
		available: true,
		association,
		summary: {
			before_option_count: options,
			after_option_count: options + countDelta,
			option_count_delta: countDelta,
			before_total_bytes: 9400,
			after_total_bytes: 9400 + sizeDelta,
			total_bytes_delta: sizeDelta,
			before_autoloaded_count: 126,
			after_autoloaded_count: 126 + autoloadedCountDelta,
			autoloaded_count_delta: autoloadedCountDelta,
			before_autoloaded_bytes: bytes,
			after_autoloaded_bytes: bytes + autoloadedDelta,
			autoloaded_bytes_delta: autoloadedDelta,
			added_count: added.length,
			removed_count: removed.length,
			changed_count: changed.length,
			value_changed_count: changed.filter( ( o ) => o.value_changed )
				.length,
			autoload_value_changed_count: changed.filter(
				( o ) => o.autoload_value_changed
			).length,
			autoload_behavior_changed_count: changed.filter(
				( o ) => o.autoload_behavior_changed
			).length,
		},
		added,
		removed,
		changed,
	};
}

/**
 * Cron phase from its lists; `events` events before.
 *
 * @param association       Phase association.
 * @param lists             Event lists.
 * @param lists.added
 * @param lists.removed
 * @param lists.rescheduled
 * @param events            Event count before.
 */
export function cronPhase(
	association: PhaseAssociation,
	{
		added = [],
		removed = [],
		rescheduled = [],
	}: {
		added?: AddedCronEvent[];
		removed?: RemovedCronEvent[];
		rescheduled?: RescheduledCronEvent[];
	},
	events = 16
): AvailableCronPhase {
	const single = ( list: AddedCronEvent[] ) =>
		list.filter( ( e ) => ! e.is_recurring ).length;
	const delta = added.length - removed.length;
	const singleDelta = single( added ) - single( removed );

	return {
		available: true,
		association,
		summary: {
			before_event_count: events,
			after_event_count: events + delta,
			event_count_delta: delta,
			before_recurring_count: events - 1,
			after_recurring_count: events - 1 + delta - singleDelta,
			recurring_count_delta: delta - singleDelta,
			before_single_count: 1,
			after_single_count: 1 + singleDelta,
			single_count_delta: singleDelta,
			before_unique_hook_count: events,
			after_unique_hook_count: events + delta,
			unique_hook_count_delta: delta,
			added_count: added.length,
			removed_count: removed.length,
			rescheduled_count: rescheduled.length,
			changed_count: 0,
		},
		added,
		removed,
		rescheduled,
		changed: [],
	};
}

export function changedOption(
	name: string,
	before: number,
	after: number,
	autoload = 'auto'
): ChangedOption {
	const on = autoload !== 'off' && autoload !== 'no';
	return {
		name,
		value_changed: true,
		before_size: before,
		after_size: after,
		size_delta: after - before,
		before_autoload: autoload,
		after_autoload: autoload,
		autoload_value_changed: false,
		before_is_autoloaded: on,
		after_is_autoloaded: on,
		autoload_behavior_changed: false,
	};
}

export function addedOption(
	name: string,
	size: number,
	autoload = 'auto'
): OptionState {
	return {
		name,
		size,
		autoload,
		is_autoloaded: autoload !== 'off' && autoload !== 'no',
	};
}

export function oneTime( hook: string, timestamp: number ): AddedCronEvent {
	return {
		hook,
		timestamp,
		schedule: null,
		interval: null,
		is_recurring: false,
	};
}

export function recurring(
	hook: string,
	timestamp: number,
	schedule: string,
	interval: number
): AddedCronEvent {
	return { hook, timestamp, schedule, interval, is_recurring: true };
}

export function moved(
	event: AddedCronEvent,
	delta: number
): RescheduledCronEvent {
	return {
		hook: event.hook,
		before_timestamp: event.timestamp,
		after_timestamp: event.timestamp + delta,
		timestamp_delta: delta,
		schedule: event.schedule,
		interval: event.interval,
		is_recurring: event.is_recurring,
	};
}

/** Phases where during update is captured but nothing else changed. */
function report(
	id: number,
	name: string,
	versions: [ string, string ],
	post: { options: AvailableOptionsPhase; cron: AvailableCronPhase },
	final = post,
	during = {
		options: optionsPhase( 'update_request', {} ),
		cron: cronPhase( 'update_request', {} ),
	}
): AnalysisReport {
	return {
		...COMPLETED,
		id,
		plugin: {
			file: `${ name.toLowerCase().replace( /\W+/g, '-' ) }/plugin.php`,
			name,
			version_before: versions[ 0 ],
			version_after: versions[ 1 ],
		},
		phases: phases(
			{
				during_update: during.options,
				post_update: post.options,
				final: final.options,
			},
			{
				during_update: during.cron,
				post_update: post.cron,
				final: final.cron,
			}
		),
	};
}

/** Rank Math 1.0.278 → 1.0.279: nothing changed in any phase or signal. */
export const RANK_MATH = report(
	21,
	'Rank Math SEO',
	[ '1.0.278', '1.0.279' ],
	{
		options: optionsPhase( 'observed_after_update', {}, 144 ),
		cron: cronPhase( 'observed_after_update', {} ),
	}
);

const ELEMENTOR_OPTIONS = {
	added: [
		addedOption( 'elementor_connect_site_key', 32 ),
		addedOption( 'elementor_elementor_updater_completed', 1 ),
	],
	changed: [
		changedOption( 'elementor_install_history', 31, 56 ),
		changedOption( 'elementor_log', 790, 3252, 'off' ),
		changedOption( 'elementor_version', 5, 5 ),
	],
};

/** Elementor 4.3.3 → 4.3.4: option changes after the update, no Cron changes. */
export const ELEMENTOR = report( 22, 'Elementor', [ '4.3.3', '4.3.4' ], {
	options: optionsPhase( 'observed_after_update', ELEMENTOR_OPTIONS, 137 ),
	cron: cronPhase( 'observed_after_update', {}, 13 ),
} );

const WORDFENCE_DAILY = recurring(
	'wordfence_daily_cron',
	T + 86400,
	'daily',
	86400
);
const WORDFENCE_HOURLY = recurring(
	'wordfence_hourly_cron',
	T + 3600,
	'hourly',
	3600
);

/** Wordfence 9.0.1 → 9.0.2: version option, two one-time jobs, two jobs pulled forward. */
export const WORDFENCE = report(
	23,
	'Wordfence Security',
	[ '9.0.1', '9.0.2' ],
	{
		options: optionsPhase( 'observed_after_update', {
			changed: [ changedOption( 'wordfence_version', 5, 5 ) ],
		} ),
		cron: cronPhase( 'observed_after_update', {
			added: [
				oneTime( 'wordfence_completeCoreUpdateNotification', T + 60 ),
				oneTime( 'wordfence_version_check', T + 120 ),
			],
			rescheduled: [
				moved( WORDFENCE_DAILY, -86400 ),
				moved( WORDFENCE_HOURLY, -3480 ),
			],
		} ),
	}
);

/**
 * Mixed site, WooCommerce 11.1.1 → 11.1.2 settled after 4 minutes: its own
 * options next to unrelated ones; only unrelated Cron activity, including a
 * one-time job (scheduled by another plugin) that disappeared. The recurring
 * `acme_legacy_sync` removal is added for contrast (not observed).
 */
export const MIXED_WOOCOMMERCE = report(
	24,
	'WooCommerce',
	[ '11.1.1', '11.1.2' ],
	{
		options: optionsPhase(
			'observed_after_update',
			{
				added: [
					addedOption(
						'wc_remote_inbox_notifications_wca_updated',
						0,
						'off'
					),
				],
				changed: [
					changedOption(
						'_elementor_design_system_sync_css_meta',
						38,
						38
					),
					changedOption(
						'action_scheduler_lock_async-request-runner',
						34,
						34,
						'no'
					),
					changedOption( 'woocommerce_db_version', 6, 6 ),
					changedOption( 'woocommerce_version', 6, 6 ),
				],
			},
			403
		),
		cron: cronPhase(
			'observed_after_update',
			{
				removed: [
					oneTime(
						'wordfence_completeCoreUpdateNotification',
						T + 60
					),
					recurring( 'acme_legacy_sync', T + 600, 'hourly', 3600 ),
				],
				rescheduled: [
					moved(
						recurring(
							'action_scheduler_run_queue',
							T,
							'every_minute',
							60
						),
						300
					),
				],
			},
			31
		),
	}
);

/**
 * Jetpack-like cancellation: an option appears during the update and is
 * gone again after it, so Net result has no changes.
 */
export const CANCELLED = report(
	25,
	'Jetpack',
	[ '16.1.3', '16.2' ],
	{
		options: optionsPhase( 'observed_after_update', {
			removed: [ addedOption( 'jetpack_waf_needs_update', 1 ) ],
		} ),
		cron: cronPhase( 'observed_after_update', {} ),
	},
	{
		options: optionsPhase( 'net_across_phases', {} ),
		cron: cronPhase( 'net_across_phases', {} ),
	},
	{
		options: optionsPhase( 'update_request', {
			added: [ addedOption( 'jetpack_waf_needs_update', 1 ) ],
		} ),
		cron: cronPhase( 'update_request', {} ),
	}
);

/** Only WP-Cron changed (options captured without changes). */
export const CRON_CHANGES_ONLY = report( 26, 'Cron Only', [ '1.0', '1.1' ], {
	options: optionsPhase( 'observed_after_update', {} ),
	cron: cronPhase( 'observed_after_update', {
		added: [ oneTime( 'cron_only_once', T + 60 ) ],
	} ),
} );

/** Synthetic stress: hundreds of rows in every list (like the 1,500-option stress run). */
export const STRESS = report( 27, 'Elementor', [ '4.3.3', '4.3.4' ], {
	options: optionsPhase(
		'observed_after_update',
		{
			added: Array.from( { length: 470 }, ( _, i ) =>
				addedOption(
					`ul_stress_option_${ String( i + 1 ).padStart(
						4,
						'0'
					) }_with_a_fairly_long_descriptive_option_name`,
					50 + ( i % 400 ),
					i % 3 === 0 ? 'on' : 'off'
				)
			),
			changed: Array.from( { length: 11 }, ( _, i ) =>
				changedOption( `ul_stress_changed_${ i }`, 100, 120 + i )
			),
		},
		404
	),
	cron: cronPhase(
		'observed_after_update',
		{
			added: Array.from( { length: 300 }, ( _, i ) =>
				oneTime( 'ul_stress_same_hook', T + 3600 + i * 7 )
			),
		},
		30
	),
} );
