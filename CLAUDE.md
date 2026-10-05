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
- WP-Cron events — planned
- Action Scheduler actions — planned

Current state: foundation (admin screen + status REST route), the `wp_options` snapshot and diff engines, the plugin update analysis lifecycle (`Update\PluginUpdateTracker` → `Update\PluginUpdateAnalyzer` → `Storage\AnalysisRepository`, table `{prefix}updatelens_analyses`) and the read-only reports API (`Rest\AnalysesController` → `Report\AnalysisReports` → `Report\AnalysisReadModel`; see `docs/rest-api.md`). No report UI yet.

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
  - `Update/` – WordPress updater hooks (`PluginUpdateTracker`) and lifecycle rules (`PluginUpdateAnalyzer`, `UpdateClassifier`)
  - `Storage/` – schema (`Schema`, `dbDelta`), SQL (`AnalysisRepository`), JSON persistence formats (`*Codec`)
  - `Report/` – safe read models of stored analyses for REST (`AnalysisReadModel`) and read access with lifecycle maintenance (`AnalysisReports`)
- The admin app is TypeScript + React + Tailwind + shadcn/ui in `src/admin/`, built by Vite into `assets/admin/dist/`. There is no public/frontend app.

## WordPress.org compatibility

The plugin must stay distributable on WordPress.org:

- GPL-2.0-or-later compatible code and dependencies only.
- Minimums: PHP 7.4, WordPress 6.2 (the vendored Vite loader needs both). Don't use newer APIs without a guard or a deliberate minimum bump in `updatelens.php`, `readme.txt` and `phpcs.xml.dist`.
- Prefix all globals (`updatelens_` / `UPDATELENS_` / `UpdateLens\`). Text domain is `updatelens`.
- Translate user-facing strings: PHP via `__()`/`esc_html__()` etc.; JS via `__()` from `@wordpress/i18n` (mapped to `wp.i18n`).
- Don't bundle WordPress-provided packages. Any `@wordpress/*` import must be added to `WP_GLOBALS` in `vite.config.ts` **and** to the script dependencies in `Admin\AdminPage::enqueue_assets()`.
- No obfuscated code; `src/` ships in the release ZIP.

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

## Update lifecycle invariants

- **UpdateLens observes the WordPress updater; it never owns it.** No custom updater, and never alter packages, files, activation state, update metadata, credentials or responses. Upgrader filters return their input unchanged.
- **Analysis failure must never block an update.** Hook callbacks catch every `Throwable`; never return a `WP_Error` from an upgrader filter because of UpdateLens.
- **V0.1 attributes single-plugin updates only** (`UpdateClassifier`): `Plugin_Upgrader::upgrade()` or `bulk_upgrade()` with exactly one plugin ("Update now" is a one-plugin `bulk_upgrade`, so `is_multi` cannot tell single from bulk — use `bulk` + `update_count` from `upgrader_pre_download`). Ignored: multi-plugin bulk updates, installs, themes, core, translations, cron/WP-CLI updates, UpdateLens itself, and Multisite (the tracker is not registered there).
- Flow: `upgrader_pre_download` → BEFORE (`captured`); `upgrader_install_package_result` + `upgrader_process_complete` → IMMEDIATE + during-update diff (`awaiting_settle`) or `failed`; `shutdown` of a **later** wp-admin page request within the settle window → SETTLED + post-update and final diffs (`completed`). States: `Update\AnalysisStatus`; settle outcomes: `Update\SettleOutcome`.
- **The update request's own shutdown never settles** (the analyzer remembers the analyses it created in this request). Ajax, REST, cron, CLI and frontend requests never settle. A request that activates/deactivates the analysed plugin does not settle it.
- Before any other update starts, analyses awaiting settle from earlier requests are settled first (or expired, if past their deadline), so another update's changes never appear in their phases. Another update in the same request abandons that request's analysis.
- **BEFORE and IMMEDIATE snapshots are temporary**: cleared on every final state. Final records keep metadata, status/error, settle outcome and the phase diffs — never option values or snapshots.
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
- **Stored diffs are decoded only through `Storage\OptionsDiffCodec::decode()`**, which validates everything; never `json_decode()` stored data elsewhere. An unreadable diff becomes an unavailable phase (`data_corrupt`), never an error with the stored data.
- **Report reads expire overdue analyses first** (`PluginUpdateAnalyzer::expire_overdue()`, same inclusive deadline rule, no late snapshot). Apart from that and `Schema::repair()`, report endpoints are read-only.
- History lists read metadata and `IS NOT NULL` phase flags only, ordered by primary key; full diffs are decoded only for a single report.
- **API timestamps are UTC ISO 8601** (`2026-10-05T17:46:23Z`) or `null`; never convert to site-local time in PHP. Counts, sizes and deltas are JSON integers; flags are booleans.
- Unknown stored statuses/outcomes become `unknown`; never reinterpret them. Unavailable phases always carry a `Report\UnavailableReason` code.
- Post-update observations keep non-causal wording in the API, docs and UI (association `observed_after_update`, never "caused by").
- Database errors never reach REST responses: reads fail with the generic `updatelens_reports_unavailable` (500).

## Working rules

- Make focused changes that do what the task asks. Don't add features outside the requested task.
- Avoid unrelated refactors, renames or dependency upgrades.
- Add PHPUnit tests for business logic as features are introduced (`tests/Unit/`, mirroring `includes/`). Unit tests don't boot WordPress: keep logic in pure classes that take plain data, and keep `$wpdb`/WordPress calls in thin wrappers.
- Run the relevant checks before finishing (below).

## Commands

```bash
npm install && composer install
npm run dev            # Vite dev server + HMR (writes assets/admin/dist/vite-dev-server.json)
npm run dev:server     # dev server + WordPress Playground
npm run build          # production build → assets/admin/dist/
npm run typecheck      # tsc
npm run lint           # ESLint
npm run format:check   # Prettier (wp-prettier); format:fix to apply
npm run check          # typecheck + lint + format:check
composer lint          # PHPCS (WordPress + PHPCompatibilityWP)
composer test          # PHPUnit 9.6 unit tests (no WordPress needed)
npm run release        # build + release/updatelens-<version>.zip (needs Composer; COMPOSER_BIN to override)
npm run i18n           # languages/updatelens.pot (needs WP-CLI)
```

## Gotchas

- CSS is scoped: `postcss.config.cjs` prefixes every selector with `#updatelens-root` (`:root`/`html`/`body` become the root itself). Radix/shadcn components that portal to `document.body` lose their styles — portal into an element inside the root.
- The root id `updatelens-root` is shared by `Admin\AdminPage::ROOT_ID`, `src/admin/main.tsx` and `postcss.config.cjs`.
- A stale `assets/admin/dist/vite-dev-server.json` (dev server killed uncleanly) makes the site load from `localhost:5173`. Delete it or run `npm run build`.
- Bump versions together: `package.json`, `updatelens.php` (header + `UPDATELENS_VERSION`), `readme.txt` Stable tag. The release script enforces this.
- `libs/assets.php` is vendored third-party code; it is excluded from WordPress PHPCS rules. Avoid editing it.
