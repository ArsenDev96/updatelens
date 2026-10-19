=== UpdateLens ===
Contributors: arsenmanukyan
Tags: updates, plugins, diagnostics, options, cron
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Observe what changes around WordPress plugin updates: options, autoload data, WP-Cron events and Action Scheduler actions.

== Description ==

When you update a single plugin from wp-admin, UpdateLens records the state before the update, right after it, and once the updated plugin's code has run: in a follow-up request your browser sends when the update queue is idle or, failing that, the next wp-admin page you open within 5 minutes (otherwise only During update is reported). That is not proof that all migrations or background tasks have finished. It compares options (names, sizes and autoload state), WP-Cron events (hooks, times and schedules) and, on sites that use Action Scheduler, pending or in-progress actions (hooks, groups, times and schedules):

* During update: inside WordPress's plugin-update request.
* After update: from the end of the update to the follow-up. Other site activity can appear here.
* Net result: before the update compared with the end of the observation.

The UpdateLens menu lists analyzed updates in an Update History, with a count of new reports. Each report has three tabs: Update results (findings and anything that could not be assessed), Website checks and Configuration changes (Net result first, each source with its own detail page).

Each report's Update results flag a few Net result patterns that need review: autoloaded options larger than 150,000 bytes that were added, became autoloaded or grew past that size, recurring WP-Cron events no longer observed and changed recurring schedules. Changes that match none of them are counted as informational. Findings are observations to review, not proof of a problem or conflict.

Reports show observed changes, not proof of what the plugin caused. UpdateLens observes updates from the moment it is activated; earlier updates are never reconstructed.

= What is analyzed =

Analyzed: manual single-plugin updates from wp-admin (Update now on the Plugins screen, or Dashboard → Updates with one plugin selected).

Not analyzed: updates of several plugins in one request (Dashboard → Updates with more than one plugin selected), automatic/background updates, WP-CLI updates, ZIP uploads over an installed plugin, themes, core, translations, Multisite and UpdateLens itself. These updates still run normally. Selecting several plugins on Installed Plugins and choosing Update analyzes each plugin separately, but their website checks are not compared.

Known limitations: only options, WP-Cron and Action Scheduler are compared. WP-Cron and Action Scheduler arguments are hidden, so events or actions with the same hook can look alike. A one-time WP-Cron event that is no longer scheduled may have run or been unscheduled. Action Scheduler is compared as active state, not execution history: an action that is no longer active may have run or been canceled, and a short-lived action queued and completed between observations may not appear. Hook and group names do not prove which plugin caused an action.

= Optional website checks =

Website checks are off by default. To use them, open UpdateLens → Settings, select “Check website responses around plugin updates” and click Save settings. Clear it and save again to turn them off.

When they are on, your browser requests two addresses of your own site: the homepage as an anonymous visitor (without cookies) and the REST API endpoint `/wp/v2/users/me` as the signed-in administrator. A request that does not pass is repeated once. A check runs only when its address uses the same scheme, host and port as wp-admin.

* Checks before an update run in the background on the Plugins and Updates screens while plugin updates are available. Turning website checks on does not test your site right away. On Installed Plugins, a short status next to the screen title says when they are ready to compare ("Ready to compare website responses"); ready means a comparison is possible, not that the checks will pass.
* Checks after an update run only after a successful update of a single plugin, in the same tab: Update now on Installed Plugins, or Dashboard → Updates with one plugin selected. They run only for checks with a result from the last 10 minutes before the update. Keep that tab open for a few seconds after the update finishes ("Updated!" on Installed Plugins). If another plugin answers UpdateLens's request after the update with its own page (some plugins show a setup screen after they update), no check runs after that update. Other analyzed updates (for example several plugins selected on Installed Plugins) get no check after the update; their report says the checks could not be compared.

Plugin updates never wait for a website check. Each report shows the results in its Website checks tab: for the homepage and the REST API, the result before and after the update, what changed, or why they could not be compared. A check that passed before the update and failed afterwards is a high-priority finding; a homepage that started to redirect needs review. Missing, inconclusive and unsupported checks are never counted as passed. Results are observations, not proof that the plugin caused a problem. Page caches, firewalls, login restrictions and maintenance pages answered with HTTP 503 can prevent a comparison. A coming-soon or maintenance page answered with HTTP 200 ends without UpdateLens's completion marker: if it was turned on between the checks, the report shows a high-priority homepage finding. A page that still runs WordPress's page footer passes, because the checks do not compare page content. Website checks are not uptime monitoring.

Hosts can keep website checks off with `add_filter( 'updatelens_site_checks_enabled', '__return_false' );`. The filter cannot turn them on.

= Privacy and stored data =

UpdateLens keeps its data in your WordPress database. It has no telemetry and does not send monitoring data to UpdateLens or anyone else; the plugin itself makes no requests to other servers.

Website check requests go from the administrator's browser to your own site. Your hosting, security, CDN or analytics tools may log them like any other visit, including the browser's IP address and user agent.

What UpdateLens stores:

* Update history (table `{prefix}updatelens_analyses`): per analyzed update the plugin's name, file and versions, times and status, the observed changes and the user ID of the administrator who ran it. Raw option values and WP-Cron or Action Scheduler arguments are never stored. They are compared through keyed fingerprints (HMAC-SHA256, keyed from your site's salts), which are kept only while an analysis is open. History is kept until you delete the plugin; there is no automatic expiry.
* Website check results, in the history and as each administrator's latest earlier check (options `updatelens_site_check_baseline_…`): HTTP status, timing and verification facts, the administrator's user ID and keyed fingerprints of the checked address and of their login session. Never response bodies, cookies, headers, URLs or session tokens. Each is replaced by the next one; outdated ones are removed when new checks are saved, any others when the plugin is deleted.
* Short-lived website check records: sessions (transients, 2 minutes), single-use claim records (`updatelens_site_check_claim_…`, about 10 minutes) and recent start times per administrator (user meta `updatelens_site_check_starts`).
* An update activity record (`updatelens_update_activity`): a counter that every update run through WordPress's updater on a single site increases (plus a random token and start time while an update runs), including updates UpdateLens does not analyze and while website checks are off. It contains no user, plugin or package information.
* Settings and bookkeeping: `updatelens_site_checks`, `updatelens_db_version` (and `updatelens_db_upgrade_retry_at` while a database update keeps failing), `updatelens_monitoring_baseline` (when monitoring started and each plugin's name, file, version and active state then), `updatelens_unread_origin_id` and per administrator the user meta `updatelens_last_seen_analysis_id` (for the count of new reports).

Deleting a WordPress user does not remove their user ID from UpdateLens's history.

Deactivating UpdateLens keeps all of this. Deleting it from the Plugins screen removes the table, options, transients and user meta listed above (sessions held in a persistent object cache expire on their own within 2 minutes). Multisite is not supported: activating UpdateLens on a network site creates an empty history table and a version option there. Deleting the plugin removes UpdateLens's data from every site of the network.

= Source code and build =

The admin screen is a React app written in TypeScript. Its source ships in `src/` with the build configuration (`package.json`, `package-lock.json`, `vite.config.ts`, `tsconfig.json`, `tailwind.config.js`, `postcss.config.cjs`). Build `assets/admin/dist/` with Node.js 20.19 or later: `npm ci`, then `npm run build`. The PHP autoloader in `vendor/` is generated by Composer (no third-party PHP dependencies): `composer install --no-dev --optimize-autoloader`. Bundled third-party libraries and their licenses are listed in `THIRD-PARTY-NOTICES.txt`.

== Installation ==

1. In WordPress admin, go to Plugins → Add New and search for “UpdateLens”.
2. Install and activate UpdateLens, then open UpdateLens from the admin menu.
3. Update supported plugins one at a time and review the resulting reports.
4. Optional: turn on website checks under UpdateLens → Settings.

To install manually, go to Plugins → Add New → Upload Plugin, choose the UpdateLens ZIP file, then click Install Now and Activate.

== Frequently Asked Questions ==

= Does UpdateLens prove that the updated plugin caused every reported change? =

No. WordPress core, other plugins and background jobs can change options, WP-Cron events or Action Scheduler actions in the same window. Changes observed during the update request are the most closely associated with it. On busy sites, expect entries from other plugins: for example a plugin that reacts to every update, a background queue runner, or the follow-up work of a plugin updated just before.

= Does UpdateLens store option values or job arguments? =

No. They are compared through keyed fingerprints that are deleted when an analysis finishes. Reports show names, sizes, autoload state, hooks, groups, times and schedules. So a report can show that an option such as `wp_user_roles` changed and by how many bytes, but not what changed inside it, for example which capabilities were added.

== Changelog ==

= 0.3.0 =
* First public release.
* Analyzes manual single-plugin updates from wp-admin before the update, right after it and once the updated code has run (During update, After update, Net result).
* Compares options and autoload data, WP-Cron events and Action Scheduler actions.
* Findings for a few Net result patterns and website check results, marked high priority or needs review; other changes are informational.
* Optional homepage and REST API website checks around an Update now on the Plugins screen (off by default; UpdateLens → Settings).
* Update History with a count of new reports; reports with Update results, Website checks and Configuration changes tabs, and a detail page per signal.
