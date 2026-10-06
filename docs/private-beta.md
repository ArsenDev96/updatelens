# UpdateLens Private Beta 2

Version `0.2.0-beta.2`. Thank you for trying UpdateLens before its first release.

UpdateLens observes what changes around WordPress plugin updates. When you update one plugin from wp-admin, it records which **options** (including autoload state and size), which **WP-Cron events** and which **Action Scheduler actions** changed, and shows a report under **Tools → UpdateLens**.

Reports show what UpdateLens _observed_ around the update. They are not proof of what the plugin caused: WordPress core and other plugins can change things at the same time.

## What's new in Beta 2

- Action Scheduler (the background-job queue used by WooCommerce and many other plugins) is now included in update reports, as a third signal next to Options and WP-Cron.
- Background jobs can appear as added, no longer active, rescheduled or schedule-changed.
- Duration text ("5 minutes", "Every 1 day") now follows the WordPress admin language instead of the browser language.

Reports remain observational: activity from other plugins or WordPress core may appear in the same observation window.

Upgrading from Beta 1: upload the new ZIP and choose **Replace current with uploaded**. Your existing reports stay; they show Action Scheduler as "Not captured", because Beta 1 did not observe it.

## Install

1. Download `updatelens-0.2.0-beta.2.zip`.
2. In WordPress admin, go to **Plugins → Add Plugin → Upload Plugin**.
3. Choose the ZIP, click **Install Now**, then **Activate** (or **Replace current with uploaded** when upgrading from Beta 1).
4. Open **Tools → UpdateLens**. The history is empty until the first update.
5. Update a plugin the normal way: **Update now** on the Plugins screen, or **Dashboard → Updates** with one plugin selected.
6. Open any other wp-admin page within 5 minutes (for example the Dashboard), then return to **Tools → UpdateLens** and open the report.

Use a staging or test site if you have one. UpdateLens never changes how WordPress installs updates, but it is beta software.

## What Beta 2 analyzes

Analyzed: **manual single-plugin updates from WordPress admin**. Reports are visible to users who can manage options (administrators).

Not analyzed in this beta (these updates still run normally; UpdateLens simply doesn't record them):

- bulk updates of several plugins at once
- automatic/background plugin updates
- WP-CLI updates
- updates by uploading a plugin ZIP over an installed plugin
- theme, WordPress core and translation updates
- Multisite networks
- updates of UpdateLens itself

## Reading a report

Each report has three phases:

- **During update**: changes observed during WordPress's own plugin-update request.
- **After update**: changes observed between the end of the update and the next wp-admin page you open. This is where a plugin's upgrade routine usually runs, but background activity from WordPress core or other plugins (scheduled jobs, queued actions) can appear here too.
- **Net result**: the difference between the state before the update and the end of the observation, in one view.

The observation window is **5 minutes** (300 seconds). If no wp-admin page is opened within that time, the update is still recorded, but After update and Net result are not available for it. If you start another plugin update first, the earlier one is finished at that point, so changes from the second update don't appear in the first report.

Within each phase, **Options**, **WP-Cron** and **Action Scheduler** are shown separately. Tabs show how many changes were observed; a phase or signal can also be "Not available", and the report explains why.

### Action Scheduler

UpdateLens observes the active Action Scheduler queue (pending and in-progress actions) at the same observation points as options and WP-Cron, and shows:

- **Added**: actions that became active.
- **No longer active**: actions that were active before and are not anymore. They may have run, been canceled or left the queue otherwise; UpdateLens cannot tell which.
- **Rescheduled**: the same action with the same schedule at another time (recurring actions move whenever they run).
- **Changed**: the same action with another schedule (for example an interval that became a cron expression).

It is a comparison of queue state, not an execution log: completed, failed and canceled actions and Action Scheduler's logs are not shown. **Very short-lived Action Scheduler actions that are queued and completed entirely between observation points may not appear in the report.**

Hook and group names (for example `Group: woocommerce`) are observed metadata and do not prove which plugin caused an action. Actions of other plugins that happen in the same window are shown, not filtered.

Sites without Action Scheduler show "Action Scheduler not detected". That is normal, not an error.

## Privacy and stored data

**Option values.** UpdateLens never stores raw option values. It reads them only to compute a keyed fingerprint (HMAC-SHA256, keyed from your site's salts) that tells whether a value changed. Reports show option names, sizes and autoload state, never values.

**WP-Cron arguments.** UpdateLens never stores raw WP-Cron event arguments. It keeps a keyed fingerprint of them while it compares events, only to match events, and reports never contain these fingerprints. Reports show hook names, times and schedules.

**Action Scheduler arguments.** UpdateLens never stores raw Action Scheduler action arguments. Like WP-Cron, it uses keyed argument fingerprints internally to match actions; reports never show them. Action IDs, claim IDs and serialized schedules are not stored or shown. Reports show hook names, groups, status (pending or in progress), times and schedules.

**No external services.** UpdateLens makes no external HTTP requests: it sends no analysis data anywhere and has no telemetry, licence check or cloud service. Everything stays in your site's database. UpdateLens only reads WP-Cron and Action Scheduler; it never schedules, runs or cancels anything.

**What is stored.** UpdateLens creates one database table, `{prefix}updatelens_analyses`, and one option, `updatelens_db_version`. For each analyzed update it keeps:

- the plugin's file, name and versions before/after, and the ID of the user who ran the update
- the analysis status and timestamps (UTC)
- the observed changes per phase (option names, sizes and autoload state; WP-Cron hooks, times and schedules; Action Scheduler hooks, groups, statuses, times and schedules)

While an analysis is still open (normally only a few minutes), the table also holds temporary snapshots (option names, sizes, autoload state and fingerprints; WP-Cron and Action Scheduler hooks, times, schedules and argument fingerprints). They are deleted as soon as the analysis finishes, fails or is abandoned. On sites with many queued actions these temporary snapshots can be large (roughly half a megabyte per thousand pending actions) until the analysis finishes.

**Deactivate vs. delete.**

- _Deactivating_ UpdateLens stops recording new updates and keeps the history.
- _Deleting_ it from the Plugins screen removes the analyses table and the `updatelens_db_version` option, so the history is gone.

## Known limitations

- Reports are observations, not proof of causation. After update can include activity from WordPress core or other plugins.
- Only options, WP-Cron events and Action Scheduler actions are analyzed. Files, database tables and other settings are not.
- WP-Cron and Action Scheduler arguments are hidden on purpose, so two events or actions with the same hook but different arguments can look alike.
- When a one-time WP-Cron event is "No longer scheduled", UpdateLens cannot tell whether it ran or was unscheduled. An Action Scheduler action that is "No longer active" may have run or been canceled.
- Very short-lived Action Scheduler actions that are queued and completed entirely between observation points may not appear.
- Only the update flows listed above are analyzed; Multisite is not supported.
- Reports with very many changes (thousands of queued actions) are long; lists show 10 rows first, with a button for the rest.

## Sending feedback

Please tell us, for each update you report on:

- WordPress version and PHP version
- the plugin updated, and its version before → after
- whether the report looked correct
- anything confusing
- anything you expected to see but didn't
- any update UpdateLens did not record

For Beta 2 we'd especially like to know:

1. Did Action Scheduler show anything useful?
2. Did any background action look misleading?
3. Was "No longer active" understandable?
4. Did other plugins' or background activity in the same report cause confusion?
5. Did any update report miss something you expected?
6. Did UpdateLens fail to record any update?

Screenshots of the report help a lot. Please do **not** send passwords, API keys, licence keys, raw option values, job arguments or database dumps. If something more detailed is needed, we will ask and help you remove private data first. UpdateLens has no telemetry: feedback only reaches us when you send it.
