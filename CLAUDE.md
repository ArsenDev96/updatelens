# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Product

UpdateLens is a WordPress plugin that shows what changes when **one** plugin update runs:

1. Capture WordPress state before a plugin update.
2. Let WordPress run the normal plugin update.
3. Capture state afterwards.
4. Calculate a diff.
5. Show a report in wp-admin.

Initial snapshot sources:

- `wp_options` incl. autoload size / autoloaded options — implemented (`Snapshot\OptionsSnapshotProvider`)
- WP-Cron events — implemented and observed in every analysis (`Snapshot\CronSnapshotProvider`, `Diff\CronDiffBuilder`, `Update\CronObservation`), stored in the analyses table and shown in reports next to Options
- Action Scheduler actions — snapshot and diff engine (`Snapshot\ActionSchedulerSnapshotProvider`, `Diff\ActionSchedulerDiffBuilder`) observed in every analysis (`Update\ActionSchedulerObservation`), stored in the analyses table and shown in reports as the third signal

Current state: foundation (admin screen + status REST route), the `wp_options` snapshot and diff engines, the WP-Cron snapshot and diff engines (observed, stored and reported with every analysis), the Action Scheduler snapshot and diff engines (observed, stored and reported with every analysis), the plugin update analysis lifecycle (`Update\PluginUpdateTracker` → `Update\PluginUpdateAnalyzer` → `Storage\AnalysisRepository`, table `{prefix}updatelens_analyses`) and the read-only reports API (`Rest\AnalysesController` → `Report\AnalysisReports` → `Report\AnalysisReadModel`; see `docs/rest-api.md`), the monitoring baseline (`BaselineMonitoringBaseline`, `GET /updatelens/v1/baseline` via `RestBaselineController` → `ReportMonitoringStart`), and the admin UI: first-run screen, Update History and Analysis Report screens on the top-level UpdateLens menu (`admin.php?page=updatelens`, `src/admin/`).

## Architecture boundaries

- **PHP owns all WordPress integration and business logic.** Snapshots, diffing, update hooks, storage and any rule about what is "significant" live in PHP under `includes/`.
- **React is presentation only.** It renders data returned by PHP REST endpoints. No UpdateLens business rule may exist only in React.
- React talks to PHP only through REST routes in the `updatelens/v1` namespace (`includes/Rest/`), extending `WP_REST_Controller`. Register them from `Core\Plugin::register_rest_routes()`.
- Prefer standard WordPress APIs (`register_rest_route`, `$wpdb`, `dbDelta`, options, transients, `wp_*` functions) over custom framework layers. No ORM.
- `Core\Plugin` is a thin composition root: it wires hooks and delegates. Keep it small.
- Namespace layout (`UpdateLens\`, PSR-4 from `includes/`):
  - `Core/` – bootstrap, activation, deactivation
  - `Admin/` – wp-admin screens and asset loading
  - `Rest/` – REST controllers
  - `Snapshot/` – capture safe state; `Diff/` – compare snapshots
  - `Update/` – WordPress updater hooks (`PluginUpdateTracker`) and lifecycle rules (`PluginUpdateAnalyzer`, `CronObservation`, `ActionSchedulerObservation`, `UpdateClassifier`)
  - `Storage/` – schema (`Schema`, `dbDelta`), SQL (`AnalysisRepository`), JSON persistence formats (`*Codec`)
  - `Report/` – safe read models of stored analyses for REST (`AnalysisReadModel`) and read access with lifecycle maintenance (`AnalysisReports`); the monitoring start (`MonitoringStart`)
  - `Baseline/` – monitoring baseline: when monitoring started and the plugin inventory then (`PluginInventory` pure, `MonitoringBaseline` option wrapper)
- The admin app is TypeScript + React + Tailwind + shadcn/ui in `src/admin/`, built by Vite into `assets/admin/dist/`. There is no public/frontend app.

## WordPress.org compatibility

The plugin must stay distributable on WordPress.org:

- GPL-2.0-or-later compatible code and dependencies only.
- Minimums: PHP 7.4, WordPress 6.2 (`%i` placeholders and `WP_HTML_Tag_Processor` need 6.2). Don't use newer APIs without a guard or a deliberate minimum bump in `updatelens.php`, `readme.txt` and `phpcs.xml.dist`.
- Stay clean on the newest PHP too: explicit nullable types (`?callable $now = null`, never `callable $now = null`, deprecated since PHP 8.4 and reported whenever the class loads).
- Prefix all globals (`updatelens_` / `UPDATELENS_` / `UpdateLens\`). Text domain is `updatelens`.
- Translate user-facing strings: PHP via `__()`/`esc_html__()` etc.; JS via `__()` from `@wordpress/i18n` (mapped to `wp.i18n`).
- Don't bundle WordPress-provided packages. Any `@wordpress/*` import must be added to `WP_GLOBALS` in `vite.config.ts` **and** to the script dependencies in `Admin\AdminAssets::DEPENDENCIES`.
- No obfuscated code; `src/` ships in the release ZIP. The build keeps `translators:` comments (Babel + Terser instead of esbuild in `vite.config.ts`), because translate.wordpress.org extracts JS strings from the built bundle; same string, same translator comment.
- **The release runtime is production-only.** `AdminAdminAssets` (UpdateLens's own code) enqueues the build from `assets/admin/dist/manifest.json`: no dev-server discovery, other origins or loader filters. The Vite dev-server helper is the must-use plugin `dev/updatelens-vite-dev-server.php`, which is never shipped. No heredoc/nowdoc (Plugin Check error).
- Ship every third-party notice: list distributed third-party code in `THIRD-PARTY-NOTICES.txt`.
- Keep Plugin Check (run natively, never in Playground) at zero errors and warnings: SQL is written out as literal `prepare()` templates; only values and identifiers are placeholders.

## Security and privacy rules

- Admin screens and REST routes require `Core\Plugin::CAPABILITY` (`manage_options`). Every REST route needs a real `permission_callback` — never `__return_true`.
- Sanitize and validate all input (REST `args` with `sanitize_callback`/`validate_callback`, `sanitize_*`, `absint`, …).
- Escape all server-rendered output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- Use nonces for state-changing requests. REST calls from the admin app go through `wp.apiFetch`, which sends the `wp_rest` nonce.
- Use `$wpdb->prepare()` for every custom SQL query with variable input.
- Never expose or persist sensitive `wp_options` values unnecessarily (credentials, keys, tokens, salts, session data). Snapshot code must minimise and/or mask values; reports should show what changed without leaking secrets.
- **No telemetry.** No external HTTP requests or data transmission. No external SaaS/backend in V1.

## Snapshot invariants

- **Raw `option_value` strings never leave `Snapshot\OptionsSnapshotBuilder`.** They are never persisted, logged, returned from REST or held in snapshot objects. Snapshots keep only name, keyed fingerprint, byte size and autoload state; tests assert a fake secret appears in no serialized form.
- **Option values are compared by fingerprint**: HMAC-SHA256 (`Snapshot\OptionValueHasher`) keyed from `wp_salt( 'auth' )`. Never use an unkeyed hash. Fingerprints are only comparable within one site and one set of salts. Don't expose fingerprints via REST unless a task asks for it.
- Every fingerprint domain derives its own key through `Snapshot\KeyedHasher` with its own label (`updatelens:option-value:v1`, `updatelens:cron-args:v1`, `updatelens:action-scheduler-args:v1`), so identical bytes never fingerprint alike across domains. Never reuse a label for another domain.
- Read the stored strings via `$wpdb` (no `get_option()`, no unserializing); size is `strlen()` bytes. Snapshot code is read-only.
- Autoload state: keep the raw column value and normalize via `Snapshot\AutoloadPolicy` (core's `wp_autoload_values_to_autoload()` on 6.6+, `yes` only before). Don't hardcode `yes`/`no`.
- Snapshot content must be deterministic: sorted byte-wise by name, no timestamps inside the compared data.
- **Snapshot sources must not overlap.** Each piece of state belongs to exactly one provider. `wp_options` excludes `cron` because WP-Cron gets its own provider.
- **Noise filtering stays conservative and tested** (`Snapshot\OptionNoiseFilter`): only transients (`_transient_*`, `_site_transient_*`), UpdateLens's own options and `cron`. Don't exclude persistent options just because they are large or change often (e.g. `rewrite_rules`). Every rule needs a test.
- Every option UpdateLens stores must start with `Core\Plugin::OPTION_PREFIX` (`updatelens_`) so it never shows up in its own snapshots.
- Each snapshot carries a non-secret, versioned fingerprint context (`OptionValueHasher::get_context()`, `hmac-sha256-v1:<hex>`). Bump `OptionValueHasher::SCHEME` whenever fingerprinting changes.

## Diff invariants

- Diffs work only on `OptionsSnapshot` objects; the diff engine never reads the database.
- **Never compare snapshots with different fingerprint contexts** (rotated salts, other scheme): `OptionsDiffBuilder` throws `IncompatibleSnapshotsException` instead of reporting every option as changed.
- **Fingerprints are internal.** The builder compares them; `OptionsDiff` and its records never contain fingerprints, contexts or option values. Tests assert a fake secret appears in no serialized form.
- Raw autoload storage changes (`autoload_value_changed`, e.g. `yes` → `auto-on`) and effective autoload behavior changes (`autoload_behavior_changed`, `is_autoloaded` flipped) are distinct and reported separately.
- Value changes are detected by fingerprint, never inferred from size. An option appears only if value, size, raw autoload or effective autoload differs.
- All deltas are signed `after - before`, in raw bytes/counts. Aggregate deltas come from the two snapshot summaries, not from summing changed records.
- Diff results are deterministic: added/removed/changed sorted byte-wise by name, no timestamps or random ids.

## WP-Cron snapshot and diff invariants

- **Cron is its own snapshot source**, separate from `wp_options` (which keeps excluding `cron`): `Snapshot\CronSnapshotProvider` → `CronSnapshotBuilder` (pure) → `CronSnapshot`; `Diff\CronDiffBuilder` → `CronDiff`.
- **Raw cron arguments never leave `Snapshot\CronSnapshotBuilder`**: never persisted, logged, returned from REST or held in snapshot/diff objects. Only the keyed `args_fingerprint` (`Snapshot\CronArgsHasher`: HMAC of `serialize( $args )`, the canonical form of core's `md5( serialize( $args ) )` event key, so order, keys and types count) is kept. Core's unkeyed md5 key is never stored. Tests assert a fake secret appears in no serialized form.
- **Snapshotting never mutates WP-Cron.** Read with `get_option( 'cron' )`; never `_get_cron_array()` (it rewrites a version-less option) and never any schedule/unschedule/clear function.
- Event records: `hook`, `timestamp` (int), `schedule` (string|null), `interval` (int|null: null for one-time events or when not stored), `is_recurring`, `args_fingerprint`. The `version` marker is not an event.
- **Logical identity = hook + args fingerprint.** Timestamp and recurrence (schedule + interval) are attributes. Several instances per identity are real state (`wp_schedule_event()` has no duplicate check): snapshots keep a sorted list, never a map that could overwrite.
- Matching within one identity, in timestamp order: same timestamp + recurrence → unchanged; same recurrence → **`rescheduled`, never removed + added** (otherwise every normal cron run is noise); other recurrence (`daily` → `hourly`, one-time ↔ recurring, interval) → `changed`; leftovers → added/removed. Different arguments are a different identity → removed + added; never guess they belong together.
- **Malformed cron state fails the whole snapshot** (`Snapshot\MalformedCronStateException`: fixed message, fixed reason code, never data) instead of skipping entries, which would show up as false additions/removals. What core accepts is not malformed: non-array option (= no events), unregistered schedule names, recurring events without interval, empty or numeric hook names. Arrays without the `version` 2 marker are `unsupported_format`. Hook names must be valid UTF-8.
- Deterministic order: snapshots by hook (byte-wise), args fingerprint, timestamp, schedule, interval; diff lists by their array form (hook first). No capture time in snapshots. Different fingerprint contexts → `IncompatibleSnapshotsException`.
- Cron changes are observations, not causes (same wording rules as options); a rescheduled event is often just a normal cron run.

## Action Scheduler snapshot and diff invariants

- **Action Scheduler is its own signal**, separate from `wp_options` and WP-Cron: `Snapshot\ActionSchedulerSnapshotProvider` (WordPress/DB) → `ActionSchedulerSnapshotBuilder` (pure) → `ActionSchedulerSnapshot`; `Diff\ActionSchedulerDiffBuilder` → `ActionSchedulerDiff`. Observed in the lifecycle, stored and reported (see below).
- **A snapshot is the active state only**: `pending` and `in-progress` actions (what Action Scheduler itself treats as existing: `as_has_scheduled_action()`, unique scheduling). Complete, failed and canceled rows are history (often hundreds of thousands, purged by Action Scheduler after 31 days), and logs and claims are operational data: never read them into snapshots or counts. An action that ran, failed or was canceled shows as removed; a recurring run (Action Scheduler stores the next run as a new row) shows as rescheduled.
- **Bounded reads**: only the active rows, in `action_id`-cursor batches (`ActionSchedulerSnapshotProvider::BATCH_SIZE`), never OFFSET, never all rows; rows stream through a generator into the builder. Use Action Scheduler's existing indexes; never add indexes or other schema to third-party tables. Batches are not atomic (like the options snapshot); document rather than lock.
- **Third-party tables are read-only**: only SELECT/SHOW. Never schedule, cancel, claim or run actions, call Action Scheduler's store or query API, initialize or migrate its schema, or call `ActionScheduler_Store::instance()` before `ActionScheduler::is_initialized()`.
- **Unavailable is not empty**: the provider throws `ActionSchedulerUnavailableException` (`not_installed`, `not_initialized`, `unsupported_store`, `unsupported_schema`, `read_failed`) instead of returning an empty snapshot. Supported stores: exactly `ActionScheduler_DBStore`, and `ActionScheduler_HybridStore` only while no `scheduled-action` post is pending/in-progress (Action Scheduler switches to it after a fresh install and after any plugin deactivation). Custom stores and subclasses are unsupported, never guessed. Supported tables: Action Scheduler 3.1.6–4.2.0 (schema 3–9), checked by the columns read.
- **Malformed active rows fail the whole snapshot** (`MalformedActionSchedulerStateException`: `invalid_action`, `invalid_args`, `invalid_schedule`, `unsupported_schedule`; fixed messages, never data) — distinct from unavailability. Never skip rows.
- **Raw arguments never leave `ActionSchedulerSnapshotBuilder`**: never persisted, logged, exposed or held in snapshot/diff objects. Only `args_fingerprint` (`Snapshot\ActionSchedulerArgsHasher`, label `updatelens:action-scheduler-args:v1`): an HMAC of the stored JSON text (`extended_args` when set, else `args`), the same text Action Scheduler compares. Diffs contain no fingerprints; no action, claim or group IDs anywhere.
- **Schedules are never instantiated**: `ActionSchedulerScheduleParser` reads the serialized schedule with `unserialize( …, allowed_classes false, max_depth )` under a length limit and normalizes Simple → `single`, Null → `async`, Interval → `interval` (seconds), Cron → `cron` (expression, fields joined by single spaces, cron characters only). Other classes are `unsupported_schedule`. The scheduled time comes from `scheduled_date_gmt`.
- **Logical identity = hook + group slug + args fingerprint** (Action Scheduler's own uniqueness key). Group slugs are developer-defined identifiers like hooks and are kept; no group → `''`. Several instances per identity are real state: sorted lists, never maps. Matching per identity in time order: same time + schedule → unchanged; same schedule → rescheduled; other schedule (type, interval, cron expression) → changed; leftovers → added/removed. Other arguments or group → removed + added. The status is an attribute, not compared (pending → in-progress is execution).
- Observations, not ownership: never attribute actions to a plugin by hook, group or prefix; WooCommerce and Action Scheduler's own jobs stay in the data.

## WP-Cron in the analysis lifecycle

- **Options and WP-Cron are independent analysis signals.** `wp_options` drives the lifecycle and the global status; Cron (`Update\CronObservation`) is captured at the same moments, right after options, and never changes the status, throws, or blocks the update.
- **A Cron failure never invalidates an options analysis.** Capture errors, malformed state, codec errors, incompatible contexts and Cron storage failures only make the affected Cron phases unavailable. Options incompatibility/failure keeps its global behavior.
- **Malformed Cron state disables Cron** for the phases that need that snapshot (`malformed_cron_state`); never skip records instead.
- **Cron phase availability is independent.** during = BEFORE+IMMEDIATE, post = IMMEDIATE+SETTLED, final = BEFORE+SETTLED; a missing snapshot affects only its dependents (malformed IMMEDIATE → during/post unavailable, final still computed). A fingerprint-context mismatch affects only the pair compared.
- `cron_*_reason` holds an `Update\CronPhaseReason` code (fixed codes, never messages). **Every Cron phase of a terminal analysis has exactly one of a diff or a reason**; in an open analysis a phase with neither is pending. The first cause wins: later lifecycle events never overwrite a resolved phase. Ending states map to `update_failed`, `analysis_abandoned`, `analysis_ended` (options failed/incompatible) or `settle_expired`; analyses from before schema 3 are `not_captured`.
- Cron columns are written in the same statement as the options transition. If it fails, it is retried without signal payload, one signal at a time (`PluginUpdateAnalyzer::attempts()`: all → without Action Scheduler → without Cron → without both; each signal's `without_payload()` → `storage_failed` for its own phases only), so neither signal's storage can hold the options analysis back or drop the other signal.
- A failed update captures no IMMEDIATE Cron state; an expired settle window takes no late Cron snapshot.
- **The backend preserves every observed Cron change**, including normal and core rescheduling (e.g. `wp_version_check` moving in the post-update phase): no ignore lists, no ownership filtering. De-emphasis belongs to presentation.
- **Raw Cron arguments are never stored, logged or exposed.** Stored Cron snapshots keep `args_fingerprint` (internal); stored Cron diffs contain no fingerprints. Reports read Cron diffs and reasons, never Cron snapshots; History reads only SQL-computed recorded/has-changes flags of the Cron diff columns, never their content or the reasons.

## Action Scheduler in the analysis lifecycle

- **A third independent signal** (`Update\ActionSchedulerObservation`), captured right after Cron at BEFORE, IMMEDIATE and SETTLED. Same rules as Cron: never changes the global status, throws or blocks the update; failures only affect the phases that need the failing capture. No extra settle request or window.
- **Absence is normal**: no Action Scheduler → every phase `not_installed`, never a failure. Provider reasons stay distinct (`Update\ActionSchedulerPhaseReason::from_unavailable()/from_malformed()`): `not_installed`, `unsupported_store`, `unsupported_schema`, `unsupported_schedule`, `malformed_action_scheduler_state`; `not_initialized` and `read_failed` are `snapshot_unavailable`. Keep the reason set finite (`ActionSchedulerPhaseReason::ALL`).
- **A phase is available only if both of its captures are readable snapshots**; otherwise it gets the reason of the first capture that was not (first cause wins). Availability changes (e.g. not installed at BEFORE, available after) never produce invented added/removed diffs. Context mismatch affects only the pair compared (`fingerprint_context_changed`); options incompatibility stays global and unresolved Action Scheduler phases get `analysis_ended`.
- `action_scheduler_*_reason` + diff: every phase of a terminal analysis has exactly one. Endings: `update_failed`, `analysis_abandoned`, `analysis_ended`, `settle_expired` (during diff kept, no late snapshot); analyses from before schema 4 are `not_captured` (open ones resolve the same way, no snapshots are fabricated).
- Persist only via `Storage\ActionSchedulerSnapshotCodec` (temporary; records + context, never arguments, schedules, logs, claims or IDs) and `Storage\ActionSchedulerDiffCodec` (no fingerprints or context; counts and deltas validated). Reports read only the Action Scheduler diff and reason columns, History only flags of the diff columns; never the snapshot columns.
- Snapshot diffs cannot see an action that was queued and completed between two captures (it is in neither snapshot).

## Monitoring baseline invariants

- **Never invent old history.** UpdateLens observes from activation on. Updates before it are never reconstructed (no file dates, update transients, changelogs, logs, plugin metadata, Action Scheduler logs or guesses from current state), and no synthetic analyses are created. The UI says earlier updates were not observed.
- The baseline is an inventory marker, not a snapshot: option `updatelens_monitoring_baseline` (not autoloaded), JSON via `StorageMonitoringBaselineCodec` (`schema`, UTC `started_at`, plugins with only `file`, `name`, `version`, `active`). Never settings, option values, arguments, URIs, update/license data or user data. UpdateLens itself is excluded (its updates are not analyzed). Inventory comes from `get_plugins()`/`is_plugin_active()`.
- **Written once, never rewritten**: `MonitoringBaseline::ensure()` adds it (`add_option`) on activation and, as a retry or for upgraded sites, on load of the UpdateLens screen; never on other requests. An existing value, even unreadable, is never replaced; updates, activations and new reports never change it; new plugins are not appended. `ensure()` never throws, so activation never fails because of it. Single site only.
- **Visible start = earlier of baseline time and the first analysis** (`ReportMonitoringStart`). A baseline recorded after existing analyses (sites upgraded from Beta 2) is not shown as the plugins at the start (`plugins: null`). Missing/corrupt baseline → `null` fields (HTTP 200), never an error.
- Deactivation keeps the baseline; `uninstall.php` deletes it.

## Update lifecycle invariants

- **UpdateLens observes the WordPress updater; it never owns it.** No custom updater, and never alter packages, files, activation state, update metadata, credentials or responses. Upgrader filters return their input unchanged.
- **Analysis failure must never block an update.** Hook callbacks catch every `Throwable`; never return a `WP_Error` from an upgrader filter because of UpdateLens.
- **V0.1 attributes single-plugin updates only** (`UpdateClassifier`): `Plugin_Upgrader::upgrade()` or `bulk_upgrade()` with exactly one plugin ("Update now" is a one-plugin `bulk_upgrade`, so `is_multi` cannot tell single from bulk — use `bulk` + `update_count` from `upgrader_pre_download`). Ignored: multi-plugin bulk updates, installs, themes, core, translations, cron/WP-CLI updates, UpdateLens itself, and Multisite (the tracker is not registered there).
- Flow: `upgrader_pre_download` → BEFORE (`captured`); `upgrader_install_package_result` + `upgrader_process_complete` → IMMEDIATE + during-update diff (`awaiting_settle`) or `failed`; `shutdown` of a **later** wp-admin page request within the settle window → SETTLED + post-update and final diffs (`completed`). States: `Update\AnalysisStatus`; settle outcomes: `Update\SettleOutcome`.
- **The update request's own shutdown never settles** (the analyzer remembers the analyses it created in this request). Ajax, REST, cron, CLI and frontend requests never settle. A request that activates/deactivates the analysed plugin does not settle it.
- Before any other update starts, analyses awaiting settle from earlier requests are settled first (or expired, if past their deadline), so another update's changes never appear in their phases. Another update in the same request abandons that request's analysis.
- **BEFORE and IMMEDIATE snapshots are temporary** (`options_*_snapshot`, `cron_*_snapshot`, `action_scheduler_*_snapshot`): cleared on every final state, in the same write. Final records keep metadata, status/error, settle outcome, the phase diffs and Cron/Action Scheduler phase reasons — never option values, Cron or Action Scheduler arguments, fingerprints or snapshots.
- Columns are prefixed by signal (`options_*`, `cron_*`, `action_scheduler_*`); the generic v1/v2 names are renamed by `Schema::RENAMES`.
- Persist snapshots/diffs only as versioned JSON via `Storage\*Codec` (never PHP `serialize()`); decoding validates everything. Stored errors use fixed messages and sanitized codes — never WordPress error messages (paths, URLs).
- Every non-final analysis has a non-NULL `active_plugin` (unique), so there is at most one open analysis per plugin while history is kept. Transitions are compare-and-set on `status`.
- Timestamps are UTC (`gmdate()`). Stale `captured` analyses (> `PluginUpdateAnalyzer::STALE_AFTER_SECONDS`) are abandoned on a later admin page request; no cron.
- Bump `Storage\Schema::VERSION` when the table changes; `Schema::maybe_upgrade()` runs `dbDelta()` on bootstrap only when the stored version differs. dbDelta cannot rename: renames run before it (`Schema::install()`).
- A table missing despite a current version is recreated by `Schema::repair()`, which runs only in bounded contexts (the UpdateLens screen and report REST reads), never on every request.

## Observation and attribution rules

- **UpdateLens reports observed changes, not guaranteed causality.** Names and labels describe _when_ a change was observed, never that the plugin caused it (no `plugin_changes`, `caused_by`, …).
- Phases are distinct and all kept (`Update\ObservationPhase`): `during_update` = BEFORE → IMMEDIATE (inside the update request; strongest association); `post_update` = IMMEDIATE → SETTLED (first eligible admin request after it; other site activity may contribute); `final` = BEFORE → SETTLED (net).
- **The settle window is bounded**: `PluginUpdateAnalyzer::SETTLE_WINDOW_SECONDS` (300, inclusive) from the persisted `settle_deadline`. After it, the analysis completes with `settle_outcome = expired`: no late settled snapshot, post-update and final diffs stay NULL. Late settling must never collect unrelated site changes indefinitely.
- `settle_outcome` explains every NULL phase diff: `admin_shutdown`, `next_update`, `expired`, or `not_applicable` (ended before settling).
- Any incompatible comparison in any phase → `incompatible`; never store partial phase diffs.
- Don't hide core/system options (e.g. `theme_mods_*`, `recently_activated`) from the stored diffs; classification belongs to presentation.

## Reports API rules

- **REST reports expose safe read models, never database rows.** Controllers call `Report\AnalysisReports`; only `Report\AnalysisReadModel` turns rows into API arrays, by whitelisting fields. Controllers never write SQL.
- **Snapshot and fingerprint internals are never API fields**: no snapshots, fingerprints, fingerprint contexts, option values, raw diff JSON, user data or stored error messages. Errors expose a sanitized `code` only.
- **Stored diffs are decoded only through `Storage\OptionsDiffCodec::decode()` / `Storage\CronDiffCodec::decode()` / `Storage\ActionSchedulerDiffCodec::decode()`**, which validates everything; never `json_decode()` stored data elsewhere. An unreadable diff becomes an unavailable phase (`data_corrupt`), never an error with the stored data.
- **Report reads expire overdue analyses first** (`PluginUpdateAnalyzer::expire_overdue()`, same inclusive deadline rule, no late snapshot). Apart from that and `Schema::repair()`, report endpoints are read-only.
- **Reports are provider-aware**: `phases.<phase>.options`, `phases.<phase>.cron` and `phases.<phase>.action_scheduler`, each with its own `available`/`reason`. Never couple one signal's availability to another's; a phase is unavailable only if all are. Adding a signal is additive: existing signal objects never change. **Provider corruption stays isolated**: an unreadable stored diff makes only that signal's phase `data_corrupt`; the report is still HTTP 200.
- Cron REST output is only `CronDiffCodec::decode()` output (hooks, timestamps, schedules, intervals, flags, counts). **Never Cron arguments, args fingerprints, md5 event keys, the Cron fingerprint context, snapshots or raw JSON.** Stored Cron reasons are whitelisted (`AnalysisReadModel::CRON_REASONS`), anything else is `unknown`; Cron phases without data are explained from the status (`update_in_progress`, `awaiting_settle`) or `not_recorded`.
- **Action Scheduler is the third independent report signal**, read like Cron (`AnalysisReadModel::signal_phase()`): REST output is only `ActionSchedulerDiffCodec::decode()` output (hooks, groups, statuses, timestamps, normalized schedules, flags, counts). **Never arguments, args fingerprints, the fingerprint context, action/claim/group IDs, serialized schedules, logs, snapshots or raw JSON.** Stored reasons are whitelisted (`AnalysisReadModel::ACTION_SCHEDULER_REASONS` = `ActionSchedulerPhaseReason::ALL`), anything else is `unknown`. Pre-schema-4 analyses are `not_captured`, never a zero-change diff.
- History lists read metadata plus per-phase, per-signal flags computed in SQL (the literal query in `AnalysisRepository::find_page()`, in `HISTORY_DIFFS` order): `IS NOT NULL` (recorded) and `NOT LIKE` the codec's escaped `EMPTY_PREFIX` passed as a placeholder (has changes), ordered by primary key. Diff JSON is never selected or decoded for the history; full diffs are decoded only for a single report. If a codec's encoding changes, its `EMPTY_PREFIX` must change with it (codec tests pin both).
- The API exposes facts (`recorded`, `has_changes`), never wording like "4 changes" or "No changes"; presentation belongs in React.
- **Observation-window wording comes from the API** (`observation_window_seconds`); presentation never hard-codes the window length.
- **API timestamps are UTC ISO 8601** (`2026-10-05T17:46:23Z`) or `null`; never convert to site-local time in PHP. Counts, sizes and deltas are JSON integers; flags are booleans.
- Unknown stored statuses/outcomes become `unknown`; never reinterpret them. Unavailable phases always carry a `Report\UnavailableReason` code.
- Post-update observations keep non-causal wording in the API, docs and UI (association `observed_after_update`, never "caused by").
- Database errors never reach REST responses: reads fail with the generic `updatelens_reports_unavailable` (500).

## Admin UI rules

- **React displays backend semantics; it does not recreate business logic.** Statuses, outcomes, phase availability and reasons come from the Reports API; React maps them to text (`src/admin/utils/labels.ts`) and never derives them from other fields (e.g. no deadline arithmetic, no recomputing diffs).
- **Phase wording stays observational/non-causal**: "During update", "After update", "Net result", "observed". Never "caused by", "created by <plugin>", "definitely".
- **API internals are never shown** as main text: raw status/reason/outcome codes appear at most under "Technical details". Server error messages are never rendered; `api/errors.ts` maps failures to fixed texts.
- **No option values exist in the UI** — only names, sizes and autoload state. Render only fields of the typed API contract (`src/admin/types/api.ts`).
- **Reports show signals as second-level tabs** under the phase tabs, in the order Options, WP-Cron, Action Scheduler (never abbreviated "AS"). An explicit signal choice is kept across phases. Each signal's availability is independent; a phase is unavailable only if all signals are.
- **Recorded does not mean changed.** Phase tabs show a pluralized count ("Net result 4 changes", `_n()`), signal tabs a bare count (accessible name e.g. "Options, 4 changes"), or "Not available"; History shows "Changes observed", "No changes" or "Not available" per phase, never a checkmark for "recorded". Counts are derived only from summary record counts (`utils/changes.ts`: added + removed + changed, plus rescheduled for WP-Cron and Action Scheduler), never from deltas; an unavailable signal is `null`, not 0. Phase counts sum the available signals; History's phase state includes Action Scheduler (changes if any recorded signal has changes).
- **Defaults prefer changes**: the first phase with changes (Net result, After update, During update), else the first available (Net result, During update, After update); within it the first signal with changes in the order Options, Action Scheduler, WP-Cron, else the first available in the same order. Never open an empty view when another available one has changes.
- **Successful analyses without changes use compact states** ("No option changes observed", "No WP-Cron changes observed", "No Action Scheduler changes observed", "No tracked changes observed during this phase"): no all-zero cards, empty sections or notes. Empty lists are left out of signals with changes (the summary shows the zeros). Never hide a phase (During update stays, even when usually empty).
- **Long diff lists collapse, not virtualize**: `DEFAULT_VISIBLE_DIFF_ROWS` (10) rows per list, then a real button ("Show 37 more changed options" / "Show fewer …", `aria-expanded`, focus stays on it). Totals stay in the titles. No pagination inside a report.
- Added/removed option rows show "Autoload: On/Off" plus the stored value only when it says more (`auto`, `auto-on`, `auto-off`); explicit `on`/`yes`/`off`/`no` would only repeat the label.
- **Changed options are dense**: one "Value changed · size" line; autoload setting/behavior rows only when `autoload_value_changed` or `autoload_behavior_changed`. Byte pairs that would round alike ("6 KB → 6 KB") get two decimals (`formatBytesPair`); the exact signed delta stays visible.
- **Ownership is never guessed.** No "likely plugin-related"/"other activity" grouping, filters or prefix heuristics; every observed record is shown, including cache-like options (e.g. `_wpforms_transient_*`), other plugins' jobs and core jobs.
- **WP-Cron in the UI**: hooks in monospace, never arguments or fingerprints. Rescheduled events are shown by default but with less emphasis than added/removed/changed, never as a warning or problem. WordPress core and unrelated jobs are never hidden or filtered; no ignore lists, no ownership, no risk wording.
- **A removed one-time WP-Cron event is "No longer scheduled"** ("Previously scheduled for …"): it may have run or been unscheduled, which UpdateLens cannot tell apart, so never "ran", "executed", "completed" or "deleted". Recurring removals stay "Removed". The summary card counting both says "No longer present". This is wording only; the API category stays `removed`.
- **WP-Cron notes appear only when relevant**: the hidden-arguments note when events are listed; "One-time jobs may disappear…" only with a one-time disappearance; the moved-one-time note only with a rescheduled one-time event.
- **Action Scheduler in the UI** (`components/ActionSchedulerDiff.tsx`, the only place that renders its diffs):
  - **`not_installed` is not an error**: "Action Scheduler not detected / Action Scheduler was not active for this phase." Never "error", "failed" or "broken" for it.
  - **Active-state diffs are not execution history**: wording says the signal compares active (pending or in-progress) actions. No UI for completed actions, logs, claims or execution history.
  - **Removed actions are "No longer active"** (section, row and summary card; "Previously scheduled for …"): they may have run, been canceled or left the queue otherwise, so never "completed", "canceled", "deleted" or "ran". The API category stays `removed`.
  - Hooks in monospace, group as secondary metadata ("Group: woocommerce"). **A group is never ownership**: no "WooCommerce action", "owned by", grouping or filtering by group or prefix. Other plugins' actions in the window are always shown.
  - Rescheduled actions are quiet, never warnings. Durations use `formatDuration()` (UpdateLens translations), never browser-locale unit words. Cron expressions are shown as stored, never paraphrased.
  - **Arguments and fingerprints never reach the UI**. With rows listed, the hidden-arguments note and the between-capture note are shown ("Very short-lived actions that are queued and completed between captures may not appear."); the leave-the-queue note only with a no-longer-active action. No notes in the compact no-changes state.
- **UTC API timestamps are localized only in presentation** (`Intl.DateTimeFormat`, browser time zone); **backend byte counts are formatted only in presentation** (`utils/format.ts`, 1 KB = 1024 B). Never change the values sent by PHP.
- **No risk classification** (impact levels, "safe"/"dangerous", size thresholds) without an explicitly designed model.
- Navigation is URL state on the admin page (`&analysis=<id>`, `&paged=<n>`) via the History API; no router dependency. The screen is a top-level menu page (`add_menu_page()`, `dashicons-visibility`, slug `updatelens`) at `AdminPage::MENU_POSITION` 65.5, directly below Plugins (65) and above Users (70); WordPress resolves collisions, `$menu` is never touched.
- **First run** (no analyses): onboarding replaces the History (`components/FirstRun.tsx`): steps, one "Go to Plugins" link (URL from PHP, only for users who can open it), the three signals (Options & autoload, WP-Cron, Action Scheduler), privacy and observation notes, and the monitoring start with the baseline plugin list (alphabetical, collapsed after 10, Active/Inactive as text). No "scan"/"start" buttons: nothing is started manually. If baseline data is unavailable, a fallback sentence replaces it without an error. Once an analysis exists, onboarding disappears.
- The History boundary ("Monitoring began on … Updates before this point were not observed by UpdateLens.") appears only on the last History page, after the oldest analysis, and only when the start is known.
- Frontend tests (Vitest + Testing Library, jsdom) live in `tests/admin/` (not `src/`, which ships in the release ZIP); mock `@wordpress/api-fetch` with API-shaped fixtures.

## Working rules

- Make focused changes that do what the task asks. Don't add features outside the requested task.
- Avoid unrelated refactors, renames or dependency upgrades.
- Add PHPUnit tests for business logic as features are introduced (`tests/Unit/`, mirroring `includes/`). Unit tests don't boot WordPress: keep logic in pure classes that take plain data, and keep `$wpdb`/WordPress calls in thin wrappers.
- Run the relevant checks before finishing (below).

## Commands

```bash
npm install && composer install
npm run dev            # Vite dev server + HMR (writes assets/admin/dist/vite-dev-server.json; needs dev/updatelens-vite-dev-server.php as a must-use plugin and one prior build)
npm run dev:server     # dev server + WordPress Playground (mounts the dev helper)
npm run build          # production build → assets/admin/dist/
npm run typecheck      # tsc
npm run lint           # ESLint
npm run format:check   # Prettier (wp-prettier); format:fix to apply
npm run check          # typecheck + lint + format:check
npm test               # Vitest frontend tests (tests/admin/)
composer lint          # PHPCS (WordPress + PHPCompatibilityWP)
composer test          # PHPUnit 9.6 unit tests (no WordPress needed)
npm run release        # build + release/updatelens-<version>.zip (needs Composer; COMPOSER_BIN to override)
npm run i18n           # build + languages/updatelens.pot from PHP and the built bundle (needs WP-CLI; git-ignored, not shipped)
```

## Gotchas

- CSS is scoped: `postcss.config.cjs` prefixes every selector with `#updatelens-root` (`:root`/`html`/`body` become the root itself). Radix/shadcn components that portal to `document.body` lose their styles — portal into an element inside the root.
- The root id `updatelens-root` is shared by `Admin\AdminPage::ROOT_ID`, `src/admin/main.tsx` and `postcss.config.cjs`.
- A stale `assets/admin/dist/vite-dev-server.json` (dev server killed uncleanly) makes a site with the dev helper load from `localhost:5173`. Delete it or run `npm run build`.
- Bump versions together: `package.json` (+ `package-lock.json`), `updatelens.php` (header + `UPDATELENS_VERSION`), `readme.txt` Stable tag. The release script enforces this. Public releases use plain `X.Y.Z` (WordPress.org Stable tag: numbers and periods only); betas were pre-releases of the next minor (`0.2.0-beta.1`, see `docs/private-beta-release.md`). The plugin version is independent of `StorageSchema::VERSION`.
