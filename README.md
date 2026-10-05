# UpdateLens

A WordPress plugin that will show what changes when a plugin update runs. It will capture site state before and after a single plugin update and report the difference in wp-admin.

**Status:** foundation only. The plugin activates, adds **Tools → UpdateLens**, and the admin screen confirms the React ↔ REST wiring. No snapshot, diff or update logic exists yet.

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
composer lint         # PHPCS: WordPress Coding Standards + PHPCompatibilityWP (PHP 7.4+)
composer lint:fix     # PHPCBF
composer test         # PHPUnit unit tests (pure PHP, no WordPress install needed)
```

Unit tests live in `tests/Unit/` and cover the pure snapshot and diff logic; they do not boot WordPress.

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
uninstall.php            Data cleanup on plugin deletion (nothing stored yet)
includes/                PHP, PSR-4 namespace UpdateLens\
  Core/                  Plugin (hook wiring), Activator, Deactivator
  Admin/AdminPage.php    Tools → UpdateLens screen; enqueues the admin app
  Rest/                  REST controllers (namespace updatelens/v1)
  Snapshot/              wp_options snapshot (safe metadata only: fingerprint, size, autoload)
  Diff/                  wp_options diff between two snapshots (no values or fingerprints)
  Update/ Storage/       Reserved for upcoming features (empty)
libs/assets.php          Vendored Vite ↔ WordPress asset loader (kucrut/vite-for-wp)
src/
  admin/                 React + TypeScript admin app (entry: main.tsx)
  components/ui/         shadcn/ui components
  lib/                   Shared frontend utilities
assets/admin/dist/       Build output (git-ignored)
scripts/release.mjs      ZIP packaging
tests/                   PHPUnit unit tests (not shipped)
```

### How the pieces connect

- PHP renders the admin page shell with a `#updatelens-root` element; React mounts into it.
- React calls the plugin's REST API through `@wordpress/api-fetch`, which WordPress provides as `wp.apiFetch` with the REST nonce already configured. `@wordpress/i18n` is likewise taken from WordPress (`wp.i18n`) so translations work. React itself is bundled.
- All CSS (including Tailwind's Preflight) is prefixed with `#updatelens-root` at build time, so it cannot restyle the rest of wp-admin. Components that portal outside the root (dialogs, popovers) must be given a container inside it.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
