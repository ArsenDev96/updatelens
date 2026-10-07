/**
 * Types of the UpdateLens Reports API (docs/rest-api.md).
 *
 * They mirror the PHP read models (Report\AnalysisReadModel) exactly; the UI
 * consumes nothing else.
 */

export type AnalysisStatus =
	| 'captured'
	| 'awaiting_settle'
	| 'completed'
	| 'failed'
	| 'incompatible'
	| 'abandoned'
	| 'unknown';

export type SettleOutcome =
	'admin_shutdown' | 'next_update' | 'expired' | 'not_applicable' | 'unknown';

export type PhaseKey = 'during_update' | 'post_update' | 'final';

export type PhaseAssociation =
	'update_request' | 'observed_after_update' | 'net_across_phases';

/** Signals observed per phase. */
export type Provider = 'options' | 'cron' | 'action_scheduler';

/** Why an options phase is unavailable. */
export type OptionsUnavailableReason =
	| 'update_in_progress'
	| 'awaiting_settle'
	| 'settle_expired'
	| 'update_failed'
	| 'analysis_failed'
	| 'fingerprint_context_changed'
	| 'analysis_abandoned'
	| 'data_corrupt'
	| 'not_recorded';

/** Why a WP-Cron phase is unavailable (stored Cron reasons plus read-model reasons). */
export type CronUnavailableReason =
	| 'update_in_progress'
	| 'awaiting_settle'
	| 'malformed_cron_state'
	| 'snapshot_unavailable'
	| 'fingerprint_context_changed'
	| 'not_captured'
	| 'storage_failed'
	| 'analysis_failed'
	| 'settle_expired'
	| 'update_failed'
	| 'analysis_abandoned'
	| 'analysis_ended'
	| 'data_corrupt'
	| 'not_recorded'
	| 'unknown';

/**
 * Why an Action Scheduler phase is unavailable (stored Action Scheduler
 * reasons plus read-model reasons). `not_installed` is a normal state: the
 * site had no supported Action Scheduler for this phase.
 */
export type ActionSchedulerUnavailableReason =
	| 'update_in_progress'
	| 'awaiting_settle'
	| 'not_installed'
	| 'unsupported_store'
	| 'unsupported_schema'
	| 'unsupported_schedule'
	| 'malformed_action_scheduler_state'
	| 'snapshot_unavailable'
	| 'fingerprint_context_changed'
	| 'not_captured'
	| 'storage_failed'
	| 'analysis_failed'
	| 'settle_expired'
	| 'update_failed'
	| 'analysis_abandoned'
	| 'analysis_ended'
	| 'data_corrupt'
	| 'not_recorded'
	| 'unknown';

export interface PluginInfo {
	/** Plugin basename (`dir/file.php`); null if the stored value was invalid. */
	file: string | null;
	name: string;
	version_before: string;
	/** Null until the update finished. */
	version_after: string | null;
}

/** UTC ISO 8601 strings (`2026-10-05T17:46:23Z`) or null. */
export interface AnalysisTimestamps {
	started_at: string | null;
	settle_deadline: string | null;
	completed_at: string | null;
}

export interface AnalysisError {
	/** Sanitized identifier, e.g. `update_not_completed` or a WordPress updater code. */
	code: string;
}

interface AnalysisBase {
	id: number;
	plugin: PluginInfo;
	status: AnalysisStatus;
	/** Null while the analysis is open. */
	settle_outcome: SettleOutcome | null;
	timestamps: AnalysisTimestamps;
	error: AnalysisError | null;
}

/** History facts of one signal in one phase (computed without decoding the diff). */
export interface HistorySignal {
	/** Whether a diff is stored. */
	recorded: boolean;
	/** Whether the stored diff has any record; null if not recorded. */
	has_changes: boolean | null;
}

export interface HistoryPhase {
	options: HistorySignal;
	cron: HistorySignal;
	action_scheduler: HistorySignal;
}

/** Row of GET /updatelens/v1/analyses. */
export interface AnalysisHistoryItem extends AnalysisBase {
	/** Whether an options diff is stored per phase. */
	has_during_update: boolean;
	has_post_update: boolean;
	has_final: boolean;
	phases: Record< PhaseKey, HistoryPhase >;
}

/** Options totals and signed deltas (`after - before`) in raw counts and bytes. */
export interface OptionsDiffSummary {
	before_option_count: number;
	after_option_count: number;
	option_count_delta: number;
	before_total_bytes: number;
	after_total_bytes: number;
	total_bytes_delta: number;
	before_autoloaded_count: number;
	after_autoloaded_count: number;
	autoloaded_count_delta: number;
	before_autoloaded_bytes: number;
	after_autoloaded_bytes: number;
	autoloaded_bytes_delta: number;
	added_count: number;
	removed_count: number;
	changed_count: number;
	value_changed_count: number;
	autoload_value_changed_count: number;
	autoload_behavior_changed_count: number;
}

/** An option's state on one side of a diff. Never contains its value. */
export interface OptionState {
	name: string;
	/** Value size in bytes. */
	size: number;
	/** Raw `autoload` column value, e.g. `on`, `auto-off`, `yes`. */
	autoload: string;
	/** Whether WordPress effectively autoloads the option. */
	is_autoloaded: boolean;
}

/** State after the change. */
export type AddedOption = OptionState;

/** State before the change. */
export type RemovedOption = OptionState;

export interface ChangedOption {
	name: string;
	value_changed: boolean;
	before_size: number;
	after_size: number;
	size_delta: number;
	before_autoload: string;
	after_autoload: string;
	autoload_value_changed: boolean;
	before_is_autoloaded: boolean;
	after_is_autoloaded: boolean;
	autoload_behavior_changed: boolean;
}

export interface AvailableOptionsPhase {
	available: true;
	association: PhaseAssociation;
	summary: OptionsDiffSummary;
	added: AddedOption[];
	removed: RemovedOption[];
	changed: ChangedOption[];
}

export interface UnavailableOptionsPhase {
	available: false;
	association: PhaseAssociation;
	reason: OptionsUnavailableReason;
}

export type OptionsPhase = AvailableOptionsPhase | UnavailableOptionsPhase;

/** WP-Cron totals and signed deltas (`after - before`) in event counts. */
export interface CronDiffSummary {
	before_event_count: number;
	after_event_count: number;
	event_count_delta: number;
	before_recurring_count: number;
	after_recurring_count: number;
	recurring_count_delta: number;
	before_single_count: number;
	after_single_count: number;
	single_count_delta: number;
	before_unique_hook_count: number;
	after_unique_hook_count: number;
	unique_hook_count_delta: number;
	added_count: number;
	removed_count: number;
	rescheduled_count: number;
	changed_count: number;
}

/**
 * A scheduled event on one side of a diff. Arguments are never included:
 * events of one hook with different arguments look the same.
 */
export interface CronEventState {
	hook: string;
	/** Unix timestamp (UTC seconds) of the next run. */
	timestamp: number;
	/** Recurrence name, null for a one-time event. */
	schedule: string | null;
	/** Stored interval in seconds, null for a one-time event or when not stored. */
	interval: number | null;
	is_recurring: boolean;
}

/** State after the change. */
export type AddedCronEvent = CronEventState;

/** State before the change. */
export type RemovedCronEvent = CronEventState;

/** The same event (hook + arguments) with the same recurrence at another time. */
export interface RescheduledCronEvent {
	hook: string;
	before_timestamp: number;
	after_timestamp: number;
	/** Signed seconds (after - before), never 0. */
	timestamp_delta: number;
	schedule: string | null;
	interval: number | null;
	is_recurring: boolean;
}

/** The same event (hook + arguments) with another recurrence. */
export interface ChangedCronEvent {
	hook: string;
	before_timestamp: number;
	after_timestamp: number;
	timestamp_changed: boolean;
	before_schedule: string | null;
	after_schedule: string | null;
	before_interval: number | null;
	after_interval: number | null;
	before_is_recurring: boolean;
	after_is_recurring: boolean;
}

export interface AvailableCronPhase {
	available: true;
	association: PhaseAssociation;
	summary: CronDiffSummary;
	added: AddedCronEvent[];
	removed: RemovedCronEvent[];
	rescheduled: RescheduledCronEvent[];
	changed: ChangedCronEvent[];
}

export interface UnavailableCronPhase {
	available: false;
	association: PhaseAssociation;
	reason: CronUnavailableReason;
}

export type CronPhase = AvailableCronPhase | UnavailableCronPhase;

/**
 * Action Scheduler totals and signed deltas (`after - before`) in counts of
 * active (pending or in-progress) actions.
 */
export interface ActionSchedulerDiffSummary {
	before_action_count: number;
	after_action_count: number;
	action_count_delta: number;
	before_recurring_count: number;
	after_recurring_count: number;
	recurring_count_delta: number;
	/** One-time actions: single and async. */
	before_single_count: number;
	after_single_count: number;
	single_count_delta: number;
	before_unique_hook_count: number;
	after_unique_hook_count: number;
	unique_hook_count_delta: number;
	added_count: number;
	removed_count: number;
	rescheduled_count: number;
	changed_count: number;
}

/**
 * Normalized schedule: `single` (one-time at a time), `async` (as soon as
 * possible), `interval` (every n seconds), `cron` (cron expression).
 */
export type ActionScheduleType = 'single' | 'async' | 'interval' | 'cron';

/** Active statuses; other statuses are history and never observed. */
export type ActionStatus = 'pending' | 'in-progress';

/**
 * An active action on one side of a diff. Arguments are never included:
 * actions of one hook and group with different arguments look the same.
 */
export interface ActionSchedulerActionState {
	hook: string;
	/** Group slug; empty if the action has no group. Observed metadata, not ownership. */
	group: string;
	status: ActionStatus;
	/** Unix timestamp (UTC seconds) of the scheduled run. */
	timestamp: number;
	schedule_type: ActionScheduleType;
	/** Seconds, only for `interval`. */
	interval: number | null;
	/** Only for `cron`. */
	cron_expression: string | null;
	is_recurring: boolean;
}

/** Active after, not before. */
export type AddedAction = ActionSchedulerActionState;

/** Active before, no longer active after (ran, canceled or otherwise left the queue). */
export type RemovedAction = ActionSchedulerActionState;

/** The same action (hook, group, arguments) with the same schedule at another time. */
export interface RescheduledAction {
	hook: string;
	group: string;
	before_timestamp: number;
	after_timestamp: number;
	/** Signed seconds (after - before), never 0. */
	timestamp_delta: number;
	schedule_type: ActionScheduleType;
	interval: number | null;
	cron_expression: string | null;
	is_recurring: boolean;
}

/** The same action (hook, group, arguments) with another schedule. */
export interface ChangedAction {
	hook: string;
	group: string;
	before_timestamp: number;
	after_timestamp: number;
	timestamp_changed: boolean;
	before_schedule_type: ActionScheduleType;
	after_schedule_type: ActionScheduleType;
	before_interval: number | null;
	after_interval: number | null;
	before_cron_expression: string | null;
	after_cron_expression: string | null;
	before_is_recurring: boolean;
	after_is_recurring: boolean;
}

export interface AvailableActionSchedulerPhase {
	available: true;
	association: PhaseAssociation;
	summary: ActionSchedulerDiffSummary;
	added: AddedAction[];
	removed: RemovedAction[];
	rescheduled: RescheduledAction[];
	changed: ChangedAction[];
}

export interface UnavailableActionSchedulerPhase {
	available: false;
	association: PhaseAssociation;
	reason: ActionSchedulerUnavailableReason;
}

export type ActionSchedulerPhase =
	AvailableActionSchedulerPhase | UnavailableActionSchedulerPhase;

/** One observation phase: each signal with its own availability. */
export interface ReportPhase {
	options: OptionsPhase;
	cron: CronPhase;
	action_scheduler: ActionSchedulerPhase;
}

/** GET /updatelens/v1/analyses/{id}. */
export interface AnalysisReport extends AnalysisBase {
	/** Length of the post-update observation window in seconds. */
	observation_window_seconds: number;
	phases: Record< PhaseKey, ReportPhase >;
}

/** One history page with the pagination headers. */
export interface AnalysesPage {
	items: AnalysisHistoryItem[];
	/** X-WP-Total. */
	total: number;
	/** X-WP-TotalPages. */
	totalPages: number;
}

/** A plugin installed when monitoring started (UpdateLens itself excluded). */
export interface BaselinePlugin {
	/** Plugin basename, e.g. `woocommerce/woocommerce.php`. */
	file: string;
	name: string;
	/** Empty if the plugin header had none. */
	version: string;
	active: boolean;
}

/**
 * GET /updatelens/v1/baseline: when UpdateLens started monitoring and which
 * plugins were installed then. Null fields are unknown (missing or unreadable
 * baseline, or a baseline recorded after earlier analyses).
 */
export interface MonitoringBaseline {
	/** UTC ISO 8601. Updates before this point were not observed. */
	started_at: string | null;
	plugin_count: number | null;
	/** Never updated after monitoring started. */
	plugins: BaselinePlugin[] | null;
}
