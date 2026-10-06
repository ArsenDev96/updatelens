=== UpdateLens ===
Tags: updates, plugins, diagnostics, options, cron
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0-beta.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Observe what changes around WordPress plugin updates: options, autoload data and WP-Cron events.

== Description ==

UpdateLens observes what changes around a WordPress plugin update. This is Private Beta 1.

When you update a single plugin from wp-admin, UpdateLens records which options (names, sizes and autoload state) and which WP-Cron events (hooks, times and schedules) changed:

* During update: inside WordPress's plugin-update request.
* After update: until the next wp-admin page you open, within 5 minutes. Other site activity can also appear here.
* Net result: before the update compared with the end of the observation.

Tools → UpdateLens lists the analyzed updates and shows a report for each one. Reports show observed changes, not proof of what the plugin caused.

Analyzed in this beta: manual single-plugin updates from wp-admin (Update now, or Dashboard → Updates with one plugin selected). Not analyzed in this beta: bulk updates of several plugins, automatic/background updates, WP-CLI updates, updates by uploading a ZIP, themes, core, translations, Multisite and UpdateLens itself.

Known limitations: only options and WP-Cron are analyzed (Action Scheduler jobs are not yet analyzed); WP-Cron arguments are hidden, so events with the same hook can look alike; a one-time WP-Cron event that is no longer scheduled may have run or been unscheduled.

== Privacy ==

UpdateLens never stores raw option values or WP-Cron arguments. It compares them through keyed fingerprints (HMAC-SHA256, keyed from the site's salts) that are never shown in reports and are deleted with the temporary snapshots when an analysis finishes.

UpdateLens makes no external HTTP requests and has no telemetry. Its data stays in its own database table, `{prefix}updatelens_analyses`, and the `updatelens_db_version` option.

Deactivating UpdateLens keeps the history. Deleting the plugin from the Plugins screen removes the table and the option.

== Installation ==

1. Plugins → Add Plugin → Upload Plugin, choose the ZIP, then Install Now and Activate.
2. Open Tools → UpdateLens.
3. Update a single plugin from wp-admin, open any wp-admin page, then return to Tools → UpdateLens.

== Changelog ==

= 0.2.0-beta.1 =
* Private Beta 1: options, autoload and WP-Cron changes around single-plugin updates, in three observation phases.

= 0.1.0 =
* Initial development foundation.
