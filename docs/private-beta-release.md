# Private beta release checklist

Maintainer notes for packaging and running UpdateLens private betas. The tester-facing guide is [private-beta.md](private-beta.md).

## Distribution

- Private ZIP distribution only: no WordPress.org submission, SVN, public release assets, licence keys, activation server or telemetry.
- Build with `npm run release` → `release/updatelens-<version>.zip` (git-ignored; never committed). Send testers the ZIP, its SHA-256 and [private-beta.md](private-beta.md).
- Versions are pre-releases of the next minor version (`0.2.0-beta.1`, `0.2.0-beta.2`, … then `0.2.0`), so WordPress's `version_compare()` orders them correctly. A `0.1.0-beta.N` version would sort below the `0.1.0` development builds, and WordPress would warn "You are uploading an older version" when replacing one.
- Bump `package.json` (`npm version <v> --no-git-tag-version`, which also updates `package-lock.json`), the plugin header, `UPDATELENS_VERSION` and the `readme.txt` Stable tag together; the release script refuses mismatches. Add a `readme.txt` changelog entry.

## Release-package audit

The `INCLUDE` allowlist in `scripts/release.mjs` is the source of truth. The ZIP must contain the plugin PHP, `vendor/` (production autoloader only), the built admin app and the unminified `src/` with its build config. It must not contain `tests/`, `phpunit.xml.dist`, `phpcs.xml.dist`, `vendor/bin`, dev Composer packages, source maps, `vite-dev-server.json`, `docs/`, `node_modules/`, scratch or browser-test scripts, `.env`/local files or secrets.

## Test matrix

Run every row against the packaged ZIP on a disposable site (never a real site).

| Area             | Check                                                                                                                                                                                                                             |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Install          | Clean install from ZIP via Upload Plugin; activation; table and `updatelens_db_version` created; Tools → UpdateLens loads with an empty History; status and analyses endpoints answer 200.                                        |
| Upgrade          | Install the previous packaged build, create several reports, upload the new ZIP ("Replace current with uploaded"); schema version unchanged/upgraded, no duplicate tables or options, old reports readable (Options and WP-Cron). |
| Supported update | Real single-plugin **Update now**; report created; phase and signal counts match the API; Options and WP-Cron views work.                                                                                                         |
| Zero change      | An update without observed changes shows the compact "no changes" states.                                                                                                                                                         |
| Failed update    | A failing update (e.g. unreachable package) fails normally in WordPress; UpdateLens records a safe failed state.                                                                                                                  |
| Cron unavailable | Malformed WP-Cron state during an update: the update succeeds, WP-Cron is "not available" with a plain reason, Options stay usable.                                                                                               |
| Long lists       | Lists over 10 rows collapse with "Show N more …" buttons.                                                                                                                                                                         |
| Navigation       | Direct report URL, Back/Forward, reload; narrow wp-admin layout without horizontal overflow; keyboard tabs.                                                                                                                       |
| Uninstall        | Deactivation keeps the table; deleting the plugin removes the table and `updatelens_db_version`.                                                                                                                                  |
| Privacy          | A fake secret stored in an option value and in WP-Cron arguments never appears in REST responses, the admin HTML or the stored analysis rows.                                                                                     |
| Hygiene          | `WP_DEBUG` on: no PHP warnings/notices from UpdateLens; no console errors on the UpdateLens screen; no outbound requests from UpdateLens.                                                                                         |

Automated checks before packaging: `composer validate --strict`, `composer lint`, `composer test` (on PHP 7.4), `php -l`, `npm run check`, `npm test`, `npm run build`, `npm run release`.

## Compatibility

Declared minimums (header and `readme.txt`): WordPress 6.2, PHP 7.4. PHP 7.4 syntax and APIs are checked statically (PHPCompatibilityWP) and the unit tests run on PHP 7.4; WordPress 6.2 is the floor of the WordPress APIs used. Record the runtime matrix that was actually exercised for each beta in its release notes, separately from the declared minimums; don't raise the minimums or the "Tested up to" value without a test on that version.

Beta 1 (`0.2.0-beta.1`) was exercised on:

| WordPress | PHP    | Database  | Covered                                                                                                                                          |
| --------- | ------ | --------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| 7.1.2     | 8.3.35 | MySQL 8.0 | Clean install, upgrade from the `0.1.0` build, real updates (Classic Editor, Wordfence), failed update, malformed WP-Cron, long lists, uninstall |
| 7.1.2     | 8.4.26 | MySQL 8.0 | Clean install, fixture update (Options + WP-Cron), uninstall                                                                                     |
| 6.2.2     | 8.0.30 | MySQL 8.0 | Clean install, fixture update (Options + WP-Cron), uninstall                                                                                     |
| —         | 7.4    | —         | Unit tests, PHPCS (PHPCompatibilityWP 7.4+), `php -l`; no WordPress runtime                                                                      |
| —         | 8.5.4  | —         | Unit tests, `php -l` with all deprecations reported                                                                                              |

Not tested at runtime: PHP 7.4 inside WordPress, WordPress 6.3–7.0, MySQL 5.7 and MariaDB, shared hosting, object caches.

PHP 8.4 deprecated implicitly nullable parameters (`callable $x = null`). They emit a deprecation notice whenever the class is loaded (on every request with `WP_DEBUG`), so always write `?callable $x = null`. Check with `php -l` on the newest PHP: compile-time deprecations show up there.

## Triage categories

Label each piece of beta feedback with one category:

- **Bug**: something breaks or errors.
- **Incorrect report**: a change is missing, wrong or in the wrong phase.
- **Missing analysis**: a supported single-plugin update was not recorded.
- **Confusing UI**: wording or layout that testers misread.
- **Too much noise**: correct but unhelpful records (caches, other plugins' activity).
- **Feature request**: new signals, filters, update flows.
- **Performance**: slow updates, slow reports, large tables.
- **Compatibility**: specific WordPress/PHP versions, hosts or plugins.
