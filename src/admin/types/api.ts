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

export type UnavailableReason =
	| 'update_in_progress'
	| 'awaiting_settle'
	| 'settle_expired'
	| 'update_failed'
	| 'analysis_failed'
	| 'fingerprint_context_changed'
	| 'analysis_abandoned'
	| 'data_corrupt'
	| 'not_recorded';

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

/** Row of GET /updatelens/v1/analyses. Flags say whether a phase diff is stored. */
export interface AnalysisHistoryItem extends AnalysisBase {
	has_during_update: boolean;
	has_post_update: boolean;
	has_final: boolean;
}

/** Totals and signed deltas (`after - before`) in raw counts and bytes. */
export interface DiffSummary {
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

export interface AvailablePhase {
	available: true;
	association: PhaseAssociation;
	summary: DiffSummary;
	added: AddedOption[];
	removed: RemovedOption[];
	changed: ChangedOption[];
}

export interface UnavailablePhase {
	available: false;
	association: PhaseAssociation;
	reason: UnavailableReason;
}

export type AnalysisPhase = AvailablePhase | UnavailablePhase;

/** GET /updatelens/v1/analyses/{id}. */
export interface AnalysisReport extends AnalysisBase {
	phases: Record< PhaseKey, AnalysisPhase >;
}

/** One history page with the pagination headers. */
export interface AnalysesPage {
	items: AnalysisHistoryItem[];
	/** X-WP-Total. */
	total: number;
	/** X-WP-TotalPages. */
	totalPages: number;
}
