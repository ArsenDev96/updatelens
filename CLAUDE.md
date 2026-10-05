# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Product

UpdateLens is a WordPress plugin that shows what changes when **one** plugin update runs:

1. Capture WordPress state before a plugin update.
2. Let WordPress run the normal plugin update.
3. Capture state afterwards.
4. Calculate a diff.
5. Show a report in wp-admin.

Initial snapshot sources (planned, not implemented):

- `wp_options`
- autoload size / autoloaded options
- WP-Cron events
- Action Scheduler actions

Current state: foundation only (admin screen + one status REST route). Do not start Snapshot/Diff/Update/Storage work unless the task asks for it.

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
  - `Snapshot/`, `Diff/`, `Update/`, `Storage/` – reserved for the features above
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

## Working rules

- Make focused changes that do what the task asks. Don't add features outside the requested task.
- Avoid unrelated refactors, renames or dependency upgrades.
- Add tests for business logic as features are introduced (no PHP test runner is set up yet — add PHPUnit with the first business logic).
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
npm run release        # build + release/updatelens-<version>.zip (needs Composer; COMPOSER_BIN to override)
npm run i18n           # languages/updatelens.pot (needs WP-CLI)
```

## Gotchas

- CSS is scoped: `postcss.config.cjs` prefixes every selector with `#updatelens-root` (`:root`/`html`/`body` become the root itself). Radix/shadcn components that portal to `document.body` lose their styles — portal into an element inside the root.
- The root id `updatelens-root` is shared by `Admin\AdminPage::ROOT_ID`, `src/admin/main.tsx` and `postcss.config.cjs`.
- A stale `assets/admin/dist/vite-dev-server.json` (dev server killed uncleanly) makes the site load from `localhost:5173`. Delete it or run `npm run build`.
- Bump versions together: `package.json`, `updatelens.php` (header + `UPDATELENS_VERSION`), `readme.txt` Stable tag. The release script enforces this.
- `libs/assets.php` is vendored third-party code; it is excluded from WordPress PHPCS rules. Avoid editing it.
