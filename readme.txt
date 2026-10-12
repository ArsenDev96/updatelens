=== UpdateLens ===
Contributors: arsenmanukyan
Tags: updates, plugins, diagnostics, options, cron
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Observe what changes around WordPress plugin updates: options, autoload data, WP-Cron events and Action Scheduler actions.

== Description ==

UpdateLens observes what changes around a WordPress plugin update.

When you update a single plugin from wp-admin, UpdateLens records which options (names, sizes and autoload state), which WP-Cron events (hooks, times and schedules) and, on sites that use Action Scheduler, which pending or in-progress Action Scheduler actions (hooks, groups, times and schedules) changed:

* During update: inside WordPress's plugin-update request.
* After update: until a short follow-up request your browser sends once the update has finished (or, if that does not happen, the next wp-admin page you open), within 5 minutes. Other site activity can also appear here.
* Net result: before the update compared with the end of the observation.

The UpdateLens admin menu lists the analyzed updates and shows a report for each one. Reports show observed changes, not proof of what the plugin caused.

UpdateLens observes updates from the moment it is activated; earlier updates are never reconstructed. Before the first analysis, the UpdateLens screen shows when monitoring started and which plugins were installed then.

= What is analyzed =

Analyzed: manual single-plugin updates from wp-admin (Update now on the Plugins screen, or Dashboard → Updates with one plugin selected).

Not analyzed: bulk updates of several plugins, automatic/background updates, WP-CLI updates, ZIP uploads over an installed plugin, themes, core, translations, Multisite and UpdateLens itself. These updates still run normally.

Known limitations: only options, WP-Cron and Action Scheduler are analyzed. WP-Cron and Action Scheduler arguments are hidden, so events or actions with the same hook can look alike. A one-time WP-Cron event that is no longer scheduled may have run or been unscheduled. Action Scheduler is compared as active (pending or in-progress) state, not execution history: an action that is no longer active may have run or been canceled, and a very short-lived action queued and completed between observation points may not appear. Hook and group names do not prove which plugin caused an action.

= Optional site checks =

Site checks are off by default. To use them, open UpdateLens → Settings, select “Check site responses around plugin updates” and click Save settings. Clear it and save again to turn them off.

When they are on, your browser requests two addresses of your own site before and after a supported manual plugin update: the homepage as an anonymous visitor (without cookies) and the REST API endpoint `/wp/v2/users/me` as the signed-in administrator. A request that does not pass is repeated once. The earlier checks run in the background on the Plugins and Updates screens while plugin updates are available; the later checks run in the same tab right after a successful Update now on the Plugins screen. Plugin updates never wait for a site check. A check runs only when its address uses the same scheme, host and port as wp-admin.

A report's Potential Impact section shows when a check that passed before the update failed or started to redirect afterwards, and lists checks that could not be compared. Results are observations, not proof that the plugin caused a problem. Page caches, firewalls, login restrictions and maintenance mode can prevent a comparison. Site checks are not uptime monitoring.

Hosts can keep site checks off with `add_filter( 'updatelens_site_checks_enabled', '__return_false' );`. The filter cannot turn them on.

= Privacy and stored data =

UpdateLens keeps its data in your WordPress database. It has no telemetry and does not send monitoring data to UpdateLens or anyone else; the plugin itself makes no requests to other servers.

Site check requests go from the administrator's browser to your own site. Your hosting, security, CDN or analytics tools may log them like any other visit, including the browser's IP address and user agent.

What UpdateLens stores:

* Update history (table `{prefix}updatelens_analyses`): per analyzed update the plugin's name, file and versions, times and status, the observed changes and the user ID of the administrator who ran it. Raw option values and WP-Cron or Action Scheduler arguments are never stored. They are compared through keyed fingerprints (HMAC-SHA256, keyed from your site's salts), which are kept only while an analysis is open. History is kept until you delete the plugin; there is no automatic expiry.
* Site check results, in the history and as each administrator's latest earlier check (options `updatelens_site_check_baseline_…`): HTTP status, timing and verification facts, the administrator's user ID and keyed fingerprints of the checked address and of their login session. Never response bodies, cookies, headers, URLs or session tokens. Each is replaced by the next one; outdated ones are removed when new checks are saved, any others when the plugin is deleted.
* Short-lived site check records: sessions (transients, 2 minutes), single-use claim records (`updatelens_site_check_claim_…`, about 10 minutes) and recent start times per administrator (user meta `updatelens_site_check_starts`).
* An update activity record (`updatelens_update_activity`): a counter that every update run through WordPress's updater on a single site increases (plus a random token and start time while an update runs), including updates UpdateLens does not analyze and while site checks are off. It contains no user, plugin or package information and is not an analysis.
* Settings and bookkeeping: `updatelens_site_checks`, `updatelens_db_version` (and `updatelens_db_upgrade_retry_at` while a database update keeps failing), `updatelens_monitoring_baseline` (when monitoring started and each plugin's name, file, version and active state then), `updatelens_unread_origin_id` and per administrator the user meta `updatelens_last_seen_analysis_id` (for the count of new reports).

Deleting a WordPress user does not remove their user ID from UpdateLens's history.

Deactivating UpdateLens keeps all of this. Deleting it from the Plugins screen removes the table, options, transients and user meta listed above (sessions held in a persistent object cache expire on their own within 2 minutes). Multisite is not supported: activating UpdateLens on a network site creates an empty history table and a version option there. Deleting the plugin removes UpdateLens's data from every site of the network.

= Source code and build =

The admin screen is a React app written in TypeScript. Its source ships in `src/` with the build configuration (`package.json`, `package-lock.json`, `vite.config.ts`, `tsconfig.json`, `tailwind.config.js`, `postcss.config.cjs`). Build `assets/admin/dist/` with Node.js 20.19 or later:

`npm ci`
`npm run build`

The PHP autoloader in `vendor/` is generated by Composer (no third-party PHP dependencies):

`composer install --no-dev --optimize-autoloader`

Bundled third-party libraries and their licenses are listed in `THIRD-PARTY-NOTICES.txt`.

== Installation ==

1. In WordPress admin, go to Plugins → Add New.
2. Search for “UpdateLens”.
3. Install and activate UpdateLens.
4. Open UpdateLens from the admin menu.
5. Update supported plugins one at a time and review the resulting reports.
6. Optional: turn on site checks under UpdateLens → Settings.

To install manually, go to Plugins → Add New → Upload Plugin, choose the UpdateLens ZIP file, then click Install Now and Activate.

== Frequently Asked Questions ==

= Does UpdateLens prove that the updated plugin caused every reported change? =

No. WordPress core, other plugins and background jobs can change options, WP-Cron events or Action Scheduler actions in the same window. Changes observed during the update request are the most closely associated with it.

= Does UpdateLens store option values or job arguments? =

No. They are compared through keyed fingerprints that are deleted when an analysis finishes. Reports show names, sizes, autoload state, hooks, groups, times and schedules.

= When does the After update phase end? =

After a successful update from the Plugins screen, your browser sends one follow-up request once the update queue is idle; it runs the updated plugin's new code, so changes made then are included. If it does not arrive, the next wp-admin page you open within 5 minutes ends the phase. If neither happens, the During update phase is still reported. The follow-up is not proof that all migrations or background tasks have finished.

== Changelog ==

= 0.2.0 =
* First public release.
* Analyzes options and autoload data, WP-Cron events and Action Scheduler actions around manual single-plugin updates from wp-admin.
* Reports each update in three phases: During update, After update and Net result. Each report opens with an overview of the observed changes; Options & autoload, WP-Cron and Action Scheduler each have their own detail page.
* The UpdateLens menu shows each administrator how many new reports were added since their last visit.
* Shows when monitoring began and which plugins were installed then (first-run monitoring baseline); earlier updates are never reconstructed.
* Privacy-safe matching: option values and WP-Cron and Action Scheduler arguments are compared through keyed fingerprints and never stored or shown.
