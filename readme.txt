=== UpdateLens ===
Tags: updates, plugins, diagnostics, options, cron
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0-beta.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Observe what changes around WordPress plugin updates: options, autoload data, WP-Cron events and Action Scheduler actions.

== Description ==

UpdateLens observes what changes around a WordPress plugin update. This is Private Beta 2.

When you update a single plugin from wp-admin, UpdateLens records which options (names, sizes and autoload state), which WP-Cron events (hooks, times and schedules) and, on sites that use Action Scheduler, which pending or in-progress Action Scheduler actions (hooks, groups, times and schedules) changed:

* During update: inside WordPress's plugin-update request.
* After update: until the next wp-admin page you open, within 5 minutes. Other site activity can also appear here.
* Net result: before the update compared with the end of the observation.

Tools → UpdateLens lists the analyzed updates and shows a report for each one. Reports show observed changes, not proof of what the plugin caused.

Analyzed in this beta: manual single-plugin updates from wp-admin (Update now, or Dashboard → Updates with one plugin selected). Not analyzed in this beta: bulk updates of several plugins, automatic/background updates, WP-CLI updates, updates by uploading a ZIP over an installed plugin, themes, core, translations, Multisite and UpdateLens itself.

Known limitations: only options, WP-Cron and Action Scheduler are analyzed; WP-Cron and Action Scheduler arguments are hidden, so events or actions with the same hook can look alike; a one-time WP-Cron event that is no longer scheduled may have run or been unscheduled; Action Scheduler is compared as active (pending or in-progress) state, not execution history, so an action that is no longer active may have run or been canceled, and a very short-lived action that is queued and completed entirely between observation points may not appear. Hook and group names are observed metadata and do not prove which plugin caused an action.

== Privacy ==

UpdateLens never stores raw option values, WP-Cron arguments or Action Scheduler arguments. It compares them through keyed fingerprints (HMAC-SHA256, keyed from the site's salts) that are never shown in reports and are deleted with the temporary snapshots when an analysis finishes. Action Scheduler action IDs, claim IDs and serialized schedules are never stored or shown.

UpdateLens makes no external HTTP requests and has no telemetry. Its data stays in its own database table, `{prefix}updatelens_analyses`, and the `updatelens_db_version` option.

Deactivating UpdateLens keeps the history. Deleting the plugin from the Plugins screen removes the table and the option.

== Installation ==

1. Plugins → Add Plugin → Upload Plugin, choose the ZIP, then Install Now and Activate.
2. Open Tools → UpdateLens.
3. Update a single plugin from wp-admin, open any wp-admin page, then return to Tools → UpdateLens.

== Changelog ==

= 0.2.0-beta.2 =
* Private Beta 2: Action Scheduler is analyzed as a third report signal, next to options and WP-Cron, in During update, After update and Net result.
* Reports show Action Scheduler actions that were added, are no longer active, were rescheduled or changed schedule; arguments are never stored or shown.
* Fixed mixed-language duration text ("5 Minuten") when the browser language differs from the WordPress admin language.

= 0.2.0-beta.1 =
* Private Beta 1: options, autoload and WP-Cron changes around single-plugin updates, in three observation phases.

= 0.1.0 =
* Initial development foundation.
