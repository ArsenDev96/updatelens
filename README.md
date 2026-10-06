# UpdateLens

A WordPress plugin that will show what changes when a plugin update runs. It will capture site state before and after a single plugin update and report the difference in wp-admin.

**Status:** Private Beta 1 (`0.2.0-beta.1`, see [docs/private-beta.md](docs/private-beta.md) for testers and [docs/private-beta-release.md](docs/private-beta-release.md) for the release checklist). When a single plugin is updated from wp-admin, UpdateLens records the changes it observed during the update request and during the first admin request after it in its own database table, for two signals: **Options** (`wp_options` names, sizes and autoload state, never values) and **WP-Cron** (scheduled hooks, timing and recurrence, never event arguments). These are observed changes, not proven causes. **Tools → UpdateLens** shows the update history and a report per analysis (during update, after update, net result), backed by a read-only REST API ([docs/rest-api.md](docs/rest-api.md)).

## Requirements

- Node.js 20.19+ (or 22.12+) and npm
- PHP 7.4+ and Composer 2
- A local WordPress 6.2+ site (Local, wp-env, Docker, …) — or use `npm run dev:server` (WordPress Playground)

## Setup

Clone or symlink this directory into `wp-content/plugins/updatelens`, then:

```bash
npm install
composer install
```

Activate **UpdateLens** on the Plugins screen and open **Tools → UpdateLens**.

## Development

```bash
npm run dev          # Vite dev server with HMR on http://localhost:5173
npm run dev:server   # dev server + a throwaway WordPress Playground site with this plugin mounted
```

While `npm run dev` runs, Vite writes `assets/admin/dist/vite-dev-server.json`; the PHP loader sees it and loads the admin app from the dev server with React Fast Refresh. Without it, the plugin loads the production build from `assets/admin/dist/manifest.json`.

If you stop the dev server by killing the process, that file may be left behind and the site will keep trying to load from `localhost:5173` — delete it or run `npm run build`.

The dev server accepts module requests from `localhost`, `127.0.0.1`, `*.localhost`, `*.local` and `*.test` origins (see `server.cors` in `vite.config.ts`). An HTTPS site cannot load modules from the plain-HTTP dev server; use HTTP locally (for Local, set Router mode to `localhost`).

## Checks

```bash
npm run typecheck     # TypeScript
npm run lint          # ESLint
npm run format:check  # Prettier (wp-prettier) — `npm run format:fix` to apply
npm run check         # all three
npm test              # Vitest frontend tests (jsdom)
composer lint         # PHPCS: WordPress Coding Standards + PHPCompatibilityWP (PHP 7.4+)
composer lint:fix     # PHPCBF
composer test         # PHPUnit unit tests (pure PHP, no WordPress install needed)
```

PHP unit tests live in `tests/Unit/` and cover the pure snapshot, diff, persistence-format, update-lifecycle and report read-model logic; they do not boot WordPress. Frontend tests live in `tests/admin/` and render the admin screens against API-shaped fixtures.

## Build and package

```bash
npm run build     # production admin assets → assets/admin/dist/
npm run release   # build, then package release/updatelens-<version>.zip
```

`npm run release` copies an allowlist of files into `release/updatelens/`, runs `composer install --no-dev` there (your working `vendor/` is untouched), and zips it. It fails if the version in `package.json`, the plugin header, `UPDATELENS_VERSION` and the `readme.txt` Stable tag disagree. Set `COMPOSER_BIN` if Composer is not on your `PATH`, e.g.:

```bash
COMPOSER_BIN="docker run --rm -v $PWD/release/updatelens:/app -w /app composer composer" npm run release
```

`npm run i18n` generates `languages/updatelens.pot` (requires WP-CLI).

## Project structure

```text
updatelens.php           Plugin header, constants, autoloader, activation hooks
uninstall.php            Data cleanup on plugin deletion (drops the analyses table)
includes/                PHP, PSR-4 namespace UpdateLens\
  Core/                  Plugin (hook wiring), Activator, Deactivator
  Admin/AdminPage.php    Tools → UpdateLens screen; enqueues the admin app
  Rest/                  REST controllers (namespace updatelens/v1)
  Snapshot/              wp_options and WP-Cron snapshots (safe metadata only: fingerprints, sizes, autoload;
                         hooks, timing, recurrence — never option values or Cron arguments)
  Diff/                  wp_options and WP-Cron diffs between two snapshots (no values, arguments or fingerprints)
  Update/                Plugin update analysis lifecycle (WordPress updater hooks + rules); WP-Cron is observed
                         and stored alongside wp_options as an independent signal
  Storage/               Analyses table, SQL and JSON persistence formats
  Report/                Safe, provider-aware read models for the report REST endpoints (Options, WP-Cron)
libs/assets.php          Vendored Vite ↔ WordPress asset loader (kucrut/vite-for-wp)
src/
  admin/                 React + TypeScript admin app (entry: main.tsx)
  components/ui/         shadcn/ui components
  lib/                   Shared frontend utilities
assets/admin/dist/       Build output (git-ignored)
scripts/release.mjs      ZIP packaging
tests/                   PHPUnit (Unit/) and Vitest (admin/) tests (not shipped)
docs/                    REST API reference, private-beta guide and release checklist
```

### How the pieces connect

- PHP renders the admin page shell with a `#updatelens-root` element; React mounts into it.
- React calls the plugin's REST API through `@wordpress/api-fetch`, which WordPress provides as `wp.apiFetch` with the REST nonce already configured. `@wordpress/i18n` is likewise taken from WordPress (`wp.i18n`) so translations work. React itself is bundled.
- All CSS (including Tailwind's Preflight) is prefixed with `#updatelens-root` at build time, so it cannot restyle the rest of wp-admin. Components that portal outside the root (dialogs, popovers) must be given a container inside it.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
