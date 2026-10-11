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

UpdateLens observes updates from the moment it is activated; earlier updates were not observed and are never reconstructed. Before the first analysis, the UpdateLens screen shows when monitoring started and which plugins were installed at that moment.

= What is analyzed =

Analyzed: manual single-plugin updates from wp-admin (Update now on the Plugins screen, or Dashboard → Updates with one plugin selected).

Not analyzed: bulk updates of several plugins, automatic/background updates, WP-CLI updates, updates by uploading a ZIP over an installed plugin, themes, core, translations, Multisite and UpdateLens itself. These updates still run normally; UpdateLens simply does not record them.

Known limitations: only options, WP-Cron and Action Scheduler are analyzed; WP-Cron and Action Scheduler arguments are hidden, so events or actions with the same hook can look alike; a one-time WP-Cron event that is no longer scheduled may have run or been unscheduled; Action Scheduler is compared as active (pending or in-progress) state, not execution history, so an action that is no longer active may have run or been canceled, and a very short-lived action that is queued and completed entirely between observation points may not appear. Hook and group names are observed metadata and do not prove which plugin caused an action.

= Optional site checks =

Site checks are off by default. To use them, open UpdateLens → Settings, select “Check site responses around plugin updates” and click Save settings. Clear the box and save again to turn them off; existing reports keep their results.

When they are on, UpdateLens compares two responses of your own site from before and after a supported manual plugin update:

* Homepage: a request from your browser as an anonymous visitor (without cookies).
* REST API: a signed-in request from your browser to a standard WordPress endpoint (`/wp/v2/users/me`, as the current administrator).

A request that does not pass is repeated once. The earlier checks run in the background when an administrator who can update plugins opens the Plugins or Updates screen while plugin updates are available, and are repeated while that screen stays open so they stay recent. The later checks run in the same browser tab right after a successful update of a single plugin with Update now on the Plugins screen, as long as the tab stays open and the site can record the result. Plugin updates never wait for a site check. Other updates (bulk, automatic, WP-CLI, Dashboard → Updates) get no later check, and Multisite is not supported.

A report's Potential Impact section then shows when a check that passed before the update failed or started to redirect afterwards, and lists every check that could not be compared. Results are observations, not proof that the updated plugin caused a problem. Page caches, firewalls, login restrictions, maintenance mode and browser differences can prevent a comparison. Site checks do not test your whole site and are not uptime monitoring. The extra requests can appear in hosting, security and analytics logs.

Hosts and site owners can keep site checks off with the `updatelens_site_checks_enabled` filter, for example `add_filter( 'updatelens_site_checks_enabled', '__return_false' );`. The Settings screen then says that the site's configuration keeps them off. The filter cannot turn site checks on.

= Privacy and stored data =

UpdateLens never stores raw option values, WP-Cron arguments or Action Scheduler arguments. It compares them through keyed fingerprints (HMAC-SHA256, keyed from the site's salts) that are never shown in reports and are deleted with the temporary snapshots when an analysis finishes. Action Scheduler action IDs, claim IDs and serialized schedules are never stored or shown.

UpdateLens has no telemetry or analytics, uses no external UpdateLens service and makes no external HTTP requests. Its data stays in your WordPress database: its own table, `{prefix}updatelens_analyses`, the `updatelens_db_version`, `updatelens_monitoring_baseline` and `updatelens_unread_origin_id` options, and per administrator the `updatelens_last_seen_analysis_id` user meta (the newest report ID they have seen, for the count of new reports in the menu).

Each analysis also stores the ID of the WordPress user who ran the update. The user ID is not shown in reports and not returned by UpdateLens's REST API.

The monitoring baseline holds only the time monitoring started and the name, version, file and active state of each plugin installed then; it is never updated afterwards.

Site checks store only facts about each response, never response bodies, cookies, headers, credentials, URLs or login session tokens: the HTTP status, timing, whether WordPress was verified as the origin and whether the page or REST response was complete, together with the administrator's user ID and keyed fingerprints of the checked address and login session (to match the earlier and later checks). They are kept in each analysis, in each administrator's latest earlier checks (options starting with `updatelens_site_check_baseline_`), in an update counter that tells updates apart (`updatelens_update_activity`, no user or plugin data), in the `updatelens_site_checks` setting, in the `updatelens_site_check_starts` user meta (recent start times, to limit how often checks start) and in short-lived sessions (transients that expire within two minutes). The requests go from your browser to your own site; nothing is sent to an UpdateLens service.

Deactivating UpdateLens keeps the history, the monitoring baseline and the new-report counts. Deleting the plugin from the Plugins screen removes the table, the options and the user meta.

= Source code and build =

The admin screen is a React app written in TypeScript. Its readable source ships with the plugin in `src/`, together with the build configuration (`package.json`, `package-lock.json`, `vite.config.ts`, `tsconfig.json`, `tailwind.config.js`, `postcss.config.cjs`). The built files in `assets/admin/dist/` are generated from it with Node.js 20.19 or later:

`npm ci`
`npm run build`

The PHP autoloader in `vendor/` is generated by Composer from `composer.json` (the plugin has no third-party PHP dependencies):

`composer install --no-dev --optimize-autoloader`

Bundled third-party libraries and their licenses are listed in `THIRD-PARTY-NOTICES.txt`.

== Installation ==

1. In WordPress admin, go to Plugins → Add New.
2. Search for “UpdateLens”.
3. Install and activate UpdateLens.
4. Open UpdateLens from the admin menu.
5. Update supported plugins one at a time and review the resulting reports.
6. Optional: turn on site checks under UpdateLens → Settings.

To install manually instead, go to Plugins → Add New → Upload Plugin, choose the UpdateLens ZIP file, then click Install Now and Activate.

== Frequently Asked Questions ==

= Does UpdateLens prove that the updated plugin caused every reported change? =

No. Reports are observations of what changed while the update ran and shortly afterwards. WordPress core, other plugins and scheduled background jobs can change options, WP-Cron events or Action Scheduler actions in the same window, so a reported change is not proof of what the updated plugin did. Changes observed during the update request are the most closely associated with it.

= Does UpdateLens store option values or job arguments? =

No. Option values, WP-Cron arguments and Action Scheduler arguments are compared through keyed fingerprints (HMAC-SHA256, keyed from your site's salts) and are never stored, logged or shown. The fingerprints are kept only while an analysis is open and are deleted when it finishes. Reports show names, sizes, autoload state, hooks, groups, times and schedules.

= Which updates are analyzed? =

Manual updates of a single plugin from WordPress admin: Update now on the Plugins screen, or Dashboard → Updates with one plugin selected. Bulk updates of several plugins, automatic background updates, WP-CLI updates, ZIP uploads over an installed plugin, themes, core and translations are not analyzed.

= What are site checks, and are they on by default? =

They are off by default. When an administrator turns them on under UpdateLens → Settings, UpdateLens compares your homepage and a WordPress REST API response from before and after supported manual plugin updates, and reports a check that passed before and failed or started to redirect afterwards. They are observations, not proof of a plugin conflict, and they are not uptime monitoring: they check two responses around an update, not your whole site.

= Why might a short-lived Action Scheduler job be missing from a report? =

UpdateLens compares the active (pending or in-progress) Action Scheduler actions at its observation points; it does not record execution history. An action that is queued and completed entirely between two observation points is in neither comparison, so it does not appear.

= When does the After update phase end? =

After a successful update from the Plugins screen, your browser sends one short follow-up request to your own site once the update queue is idle. That request runs the updated plugin's new code for the first time, so changes made then are included; you do not need to open another page. If the follow-up does not arrive (for example, you closed the tab before the update finished), the next wp-admin page you open within 5 minutes ends the phase instead. If neither happens within that window, the During update phase is still reported. The follow-up is one observation, not proof that all of the plugin's migrations or background tasks have finished.

== Changelog ==

= 0.2.0 =
* First public release.
* Analyzes options and autoload data, WP-Cron events and Action Scheduler actions around manual single-plugin updates from wp-admin.
* Reports each update in three phases: During update, After update and Net result. Each report opens with an overview of the observed changes; Options & autoload, WP-Cron and Action Scheduler each have their own detail page.
* The UpdateLens menu shows each administrator how many new reports were added since their last visit.
* Shows when monitoring began and which plugins were installed then (first-run monitoring baseline); earlier updates are never reconstructed.
* Privacy-safe matching: option values and WP-Cron and Action Scheduler arguments are compared through keyed fingerprints and never stored or shown.
