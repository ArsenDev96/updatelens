# UpdateLens REST API (v0.1)

Endpoints for analysis history, reports, the monitoring start (all read-only) and the one setting (site checks), namespace `updatelens/v1`. The admin app calls them through `wp.apiFetch` (cookie authentication + `wp_rest` nonce).

- **Permission:** `manage_options` (`Core\Plugin::CAPABILITY`) on every route. Not logged in → `401 rest_forbidden`; logged in without the capability → `403 rest_forbidden`.
- **Never cached:** every response of these routes, errors included, is `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` (`RestNoStoreResponses`; WordPress itself sends `no-store, private` for signed-in REST requests only since 6.8).
- **Single site only.** The analysis, baseline and settings routes are not registered on Multisite.
- **Read-only reports.** Only `POST /updatelens/v1/settings` changes anything. Before every history or report read, analyses past their settle deadline are expired (`completed`, `settle_outcome: "expired"`, no settled snapshot), and the analyses table is recreated if it is missing. Nothing else is changed.
- **Errors:** if storage cannot be read (or the table cannot be recreated): `500 updatelens_reports_unavailable` with a generic message. Database errors are never part of a response. A single report whose stored diffs would not fit in the request's memory (measured before they are read, `AnalysisReports::BYTES_PER_STORED_BYTE` per stored byte) is refused with `500 updatelens_report_too_large` instead of a PHP fatal error; the history and other reports are unaffected.
- **Types:** IDs, counts, byte sizes and deltas are JSON integers; flags are booleans. Timestamps are UTC ISO 8601 (`2026-10-05T17:46:23Z`) or `null`, never site-local time.
- **Wording:** phases describe _when_ changes were observed, not what caused them.

`GET /updatelens/v1/status` is unchanged (`{"version":"…"}`).

- **Without the REST API:** the UpdateLens screen also receives the report or History page its URL asks for with the admin page itself (`Admin\InitialData`, `window.updatelensInitialData`): the same read models and fields as `GET /analyses/{id}` and `GET /analyses` (plus `/baseline` where the screen shows it), the same capability, `{ "view": "report", "id": 42, "status": "ok" | "not_found" | "too_large" | "unavailable", "report": {…} }` or `{ "view": "history", "page": 1, "per_page": 20, "status": "ok" | "unavailable", "items": […], "total": 45, "total_pages": 3, "monitoring": {…} | null }`. It is not an API: it is printed only into the authenticated UpdateLens screen, never read through REST, and needs a working admin page and database. Refreshes and in-app navigation keep using the endpoints below.

## `GET /updatelens/v1/analyses`

History, newest first (`ORDER BY id DESC`).

| Parameter  | Type    | Default | Rules   |
| ---------- | ------- | ------- | ------- |
| `page`     | integer | 1       | ≥ 1     |
| `per_page` | integer | 20      | 1 – 100 |

Invalid values → `400 rest_invalid_param`. A page past the end returns `[]`.

Headers: `X-WP-Total` (number of analyses), `X-WP-TotalPages`.

Body: an array of history items. Diffs are never decoded or returned for the list; the flags are computed in SQL:

- `has_<phase>`: whether a `wp_options` diff is stored for the phase.
- `phases.<phase>.<signal>` (`options`, `cron`, `action_scheduler`):
  - `recorded`: whether a diff is stored;
  - `has_changes`: whether it contains any added, removed, changed or rescheduled record (the stored diff does not begin with its codec's empty-diff prefix, `OptionsDiffCodec::EMPTY_PREFIX` / `CronDiffCodec::EMPTY_PREFIX` / `ActionSchedulerDiffCodec::EMPTY_PREFIX`); `null` if not recorded.

`recorded` without changes is a successful observation with nothing to show, not missing data. A site without Action Scheduler has `action_scheduler.recorded: false`; the phase is then described by the other signals. Only the report decodes diffs, so only the report can find a stored diff unreadable (`data_corrupt`). No Cron or Action Scheduler hooks, groups, reasons or arguments are part of the history.

- `site_checks`: the report's site check evaluation (`potential_impact.site_checks`, see below) without `findings`, computed from the same stored results on every read: `status` (`evaluated`, `partial`, `pending`, `not_evaluated`, `not_applicable`) and per check (`home`, `rest`) its `status`, comparison `state`, `change`, `reason` and `finding_count`. The list reads the site check result columns for this (small result facts and fixed reason codes), so History needs no request per row. Rows recorded before site checks existed read as `not_applicable` with reason `not_captured`.
- `high_priority`: the report's `potential_impact.assessment.high_priority` (see [Classification](#classification)). Only site check rules are high priority, so it comes from the same evaluation as `site_checks` and no diff is decoded for the list. Other findings are not part of the history. Added later; additive.

```json
[
  {
    "id": 1,
    "plugin": {
      "file": "updatelens-fixture-a/updatelens-fixture-a.php",
      "name": "UpdateLens Fixture A",
      "version_before": "1.0.0",
      "version_after": "1.1.0"
    },
    "status": "completed",
    "settle_outcome": "admin_shutdown",
    "timestamps": {
      "started_at": "2026-10-05T18:46:06Z",
      "settle_deadline": "2026-10-05T18:51:08Z",
      "completed_at": "2026-10-05T18:46:09Z"
    },
    "error": null,
    "has_during_update": true,
    "has_post_update": true,
    "has_final": true,
    "phases": {
      "during_update": {
        "options": { "recorded": true, "has_changes": true },
        "cron": { "recorded": true, "has_changes": false },
        "action_scheduler": { "recorded": true, "has_changes": true }
      },
      "post_update": {
        "options": { "recorded": true, "has_changes": true },
        "cron": { "recorded": false, "has_changes": null },
        "action_scheduler": { "recorded": true, "has_changes": false }
      },
      "final": {
        "options": { "recorded": true, "has_changes": true },
        "cron": { "recorded": true, "has_changes": true },
        "action_scheduler": { "recorded": true, "has_changes": true }
      }
    },
    "site_checks": {
      "status": "evaluated",
      "checks": {
        "home": {
          "status": "evaluated",
          "state": "newly_failing",
          "change": null,
          "reason": null,
          "finding_count": 1
        },
        "rest": {
          "status": "evaluated",
          "state": "passed_both",
          "change": null,
          "reason": null,
          "finding_count": 0
        }
      }
    }
  }
]
```

The admin UI counts changes per phase and signal from a report's `summary` fields (`added_count + removed_count + changed_count`, plus `rescheduled_count` for WP-Cron and Action Scheduler); a phase's count is the sum of its available signals. No extra count fields exist.

## `GET /updatelens/v1/analyses/{id}`

One report. Unknown ID → `404 updatelens_analysis_not_found`.

Reports are **provider-aware**: every phase holds one object per observed signal, `options` (`wp_options`), `cron` (WP-Cron) and `action_scheduler` (Action Scheduler), each with its own availability. Any signal can be available while the others are not; a corrupt stored diff of one signal makes only that signal's phase unavailable.

```json
{
  "id": 6,
  "plugin": {
    "file": "ul-cron-fixture/ul-cron-fixture.php",
    "name": "UL Cron Fixture",
    "version_before": "1.4.0",
    "version_after": "1.5.0"
  },
  "status": "completed",
  "settle_outcome": "admin_shutdown",
  "timestamps": {
    "started_at": "2026-10-06T09:59:41Z",
    "settle_deadline": "2026-10-06T10:04:42Z",
    "completed_at": "2026-10-06T10:00:12Z"
  },
  "observation_window_seconds": 300,
  "phases": {
    "during_update": {
      "options": {
        "available": true,
        "association": "update_request",
        "summary": {
          "before_option_count": 154,
          "after_option_count": 155,
          "option_count_delta": 1,
          "before_total_bytes": 30459,
          "after_total_bytes": 30462,
          "total_bytes_delta": 3,
          "before_autoloaded_count": 128,
          "after_autoloaded_count": 128,
          "autoloaded_count_delta": 0,
          "before_autoloaded_bytes": 15467,
          "after_autoloaded_bytes": 15467,
          "autoloaded_bytes_delta": 0,
          "added_count": 1,
          "removed_count": 0,
          "changed_count": 0,
          "value_changed_count": 0,
          "autoload_value_changed_count": 0,
          "autoload_behavior_changed_count": 0
        },
        "added": [
          {
            "name": "ulfx_c_needs_migration",
            "size": 3,
            "autoload": "off",
            "is_autoloaded": false
          }
        ],
        "removed": [],
        "changed": []
      },
      "cron": {
        "available": true,
        "association": "update_request",
        "summary": {
          "before_event_count": 12,
          "after_event_count": 13,
          "event_count_delta": 1,
          "before_recurring_count": 11,
          "after_recurring_count": 11,
          "recurring_count_delta": 0,
          "before_single_count": 1,
          "after_single_count": 2,
          "single_count_delta": 1,
          "before_unique_hook_count": 11,
          "after_unique_hook_count": 11,
          "unique_hook_count_delta": 0,
          "added_count": 1,
          "removed_count": 0,
          "rescheduled_count": 0,
          "changed_count": 0
        },
        "added": [
          {
            "hook": "ul_fixture_update_once",
            "timestamp": 1791281400,
            "schedule": null,
            "interval": null,
            "is_recurring": false
          }
        ],
        "removed": [],
        "rescheduled": [],
        "changed": []
      },
      "action_scheduler": {
        "available": true,
        "association": "update_request",
        "summary": {
          "before_action_count": 6,
          "after_action_count": 7,
          "action_count_delta": 1,
          "before_recurring_count": 4,
          "after_recurring_count": 5,
          "recurring_count_delta": 1,
          "before_single_count": 2,
          "after_single_count": 2,
          "single_count_delta": 0,
          "before_unique_hook_count": 6,
          "after_unique_hook_count": 7,
          "unique_hook_count_delta": 1,
          "added_count": 1,
          "removed_count": 0,
          "rescheduled_count": 0,
          "changed_count": 0
        },
        "added": [
          {
            "hook": "fetch_patterns",
            "group": "woocommerce",
            "status": "pending",
            "timestamp": 1791280805,
            "schedule_type": "interval",
            "interval": 86400,
            "cron_expression": null,
            "is_recurring": true
          }
        ],
        "removed": [],
        "rescheduled": [],
        "changed": []
      }
    },
    "post_update": {
      "options": {
        "available": true,
        "association": "observed_after_update",
        "summary": {},
        "added": [],
        "removed": [],
        "changed": []
      },
      "cron": {
        "available": false,
        "association": "observed_after_update",
        "reason": "snapshot_unavailable"
      },
      "action_scheduler": {
        "available": true,
        "association": "observed_after_update",
        "summary": {},
        "added": [],
        "removed": [],
        "rescheduled": [],
        "changed": []
      }
    },
    "final": {
      "options": {
        "available": true,
        "association": "net_across_phases",
        "summary": {},
        "added": [],
        "removed": [],
        "changed": []
      },
      "cron": {
        "available": true,
        "association": "net_across_phases",
        "summary": {},
        "added": [],
        "removed": [],
        "rescheduled": [],
        "changed": []
      },
      "action_scheduler": {
        "available": false,
        "association": "net_across_phases",
        "reason": "not_installed"
      }
    }
  },
  "error": null
}
```

(Shortened and combined for illustration: `{}` / `[]` stand for full summaries and lists.)

Before WP-Cron reporting, each phase _was_ the options object. It is now `phases.<phase>.options`, unchanged in content; `phases.<phase>.cron` was added next to it. UpdateLens had not shipped, so the unnamed default signal was not kept. `phases.<phase>.action_scheduler` was added later in the same way; `options` and `cron` did not change.

### Fields

- `plugin.file`: plugin basename (`dir/file.php`), or `null` if the stored value is not a valid basename. `version_after` is `null` until the update finished.
- `status`: `captured`, `awaiting_settle`, `completed`, `failed`, `incompatible`, `abandoned`, or `unknown` (unrecognised stored value; never reinterpreted).
- `settle_outcome`: `null` while open, else `admin_shutdown`, `follow_up`, `next_update`, `expired`, `not_applicable`, or `unknown`. `follow_up`: the post-update observation was taken by the follow-up request the updating administrator's browser sends once the WordPress update queue is idle (Installed Plugins) or once Dashboard → Updates' update page has loaded; `admin_shutdown`: at the end of a later wp-admin page request.
- `error`: `null` or `{"code": "…"}`, a sanitized identifier (e.g. `update_not_completed`, `fingerprint_context_changed`, or a WordPress updater code such as `incompatible_archive`). No messages, paths or URLs.
- `observation_window_seconds`: length of the post-update observation window. Presentation derives its wording ("within 5 minutes") from it.
- `potential_impact`: review rules evaluated on the Net result, with per-signal evaluation status, plus the site check comparison evaluated separately in `potential_impact.site_checks` (see [`potential_impact`](#potential_impact)). Added later; every other field is unchanged.
- `site_checks`: the homepage and REST API checks before and after the update, compared per check (see [`site_checks`](#site_checks)). Added later; every other field is unchanged. Not part of any phase; Potential Impact reads its comparison (`potential_impact.site_checks`).
- No user information, snapshots, fingerprints, fingerprint contexts, option values, WP-Cron arguments or Action Scheduler arguments are returned.

### Phases

| Phase           | Compares            | `association`           |
| --------------- | ------------------- | ----------------------- |
| `during_update` | BEFORE → IMMEDIATE  | `update_request`        |
| `post_update`   | IMMEDIATE → SETTLED | `observed_after_update` |
| `final`         | BEFORE → SETTLED    | `net_across_phases`     |

Each signal object is either available or `{"available": false, "association": "…", "reason": "…"}`. Other site activity can appear in `post_update` and `final`.

### `options`

An available `options` object holds the stored `wp_options` diff: `summary` (signed deltas, `after - before`), `added`, `removed`, `changed` (sorted by name). An unavailable one has `reason`:

| `reason`                      | Meaning                                                        |
| ----------------------------- | -------------------------------------------------------------- |
| `update_in_progress`          | The update has not reported back yet.                          |
| `awaiting_settle`             | Within the settle window; not settled yet.                     |
| `settle_expired`              | No eligible request within the settle window.                  |
| `update_failed`               | The WordPress update failed or did not report completion.      |
| `analysis_failed`             | UpdateLens could not analyse the update.                       |
| `fingerprint_context_changed` | Values could not be compared (e.g. rotated salts).             |
| `analysis_abandoned`          | Stale, or another update ran in the same request.              |
| `data_corrupt`                | A diff is stored but could not be read (it is never returned). |
| `not_recorded`                | No diff and no other explanation.                              |

### `cron`

An available `cron` object holds the stored WP-Cron diff. Lists are sorted by hook (byte-wise), then time; a hook can appear several times (the same job with different, hidden arguments, or several scheduled instances).

- `summary`: event totals before/after with signed deltas (`event`, `recurring`, `single`, `unique_hook` counts) and `added_count`, `removed_count`, `rescheduled_count`, `changed_count`.
- `added` (state after) / `removed` (state before): `{hook, timestamp, schedule, interval, is_recurring}`. `timestamp` is a Unix timestamp (UTC seconds) of the next run; `schedule` is the recurrence name or `null` for one-time events; `interval` is seconds or `null` (one-time, or not stored).
- `rescheduled`: the same event (hook + arguments) with the same recurrence at another time: `{hook, before_timestamp, after_timestamp, timestamp_delta, schedule, interval, is_recurring}`, `timestamp_delta` signed seconds. Normal WP-Cron runs move recurring events, so this is an observation, not a cause; a moved one-time event may also be a new equivalent event after execution.
- `changed`: the same event with another recurrence: `{hook, before_timestamp, after_timestamp, timestamp_changed, before_schedule, after_schedule, before_interval, after_interval, before_is_recurring, after_is_recurring}`.
- Never included: arguments, argument fingerprints, WordPress's md5 event keys, the fingerprint context, snapshots or raw stored JSON. WordPress core jobs are reported like any other hook.

An unavailable `cron` object has `reason`:

| `reason`                      | Meaning                                                                     |
| ----------------------------- | --------------------------------------------------------------------------- |
| `update_in_progress`          | The update has not reported back yet.                                       |
| `awaiting_settle`             | Within the settle window; not settled yet.                                  |
| `malformed_cron_state`        | The site's Cron state contained data UpdateLens could not safely normalize. |
| `snapshot_unavailable`        | A required Cron snapshot could not be captured, encoded or read back.       |
| `fingerprint_context_changed` | The two Cron snapshots could not be compared (e.g. rotated salts).          |
| `not_captured`                | Cron was not captured (the analysis predates WP-Cron observation).          |
| `storage_failed`              | The Cron result could not be stored with the analysis.                      |
| `analysis_failed`             | Comparing the Cron snapshots failed.                                        |
| `settle_expired`              | No eligible request within the settle window.                               |
| `update_failed`               | The WordPress update failed or did not report completion.                   |
| `analysis_abandoned`          | Stale, or another update ran in the same request.                           |
| `analysis_ended`              | The overall analysis stopped (failed or incompatible) before this phase.    |
| `data_corrupt`                | A Cron diff is stored but could not be read (it is never returned).         |
| `not_recorded`                | No Cron data and no other explanation.                                      |
| `unknown`                     | An unrecognised stored reason (never reinterpreted).                        |

### `action_scheduler`

An available `action_scheduler` object holds the stored Action Scheduler diff: what changed in the **active** queue (pending and in-progress actions) between two observation points. It is scheduled state, not execution history: completed, failed and canceled actions, logs and claims are never read. An action queued and completed entirely between two captures is in neither snapshot and does not appear. Lists are sorted by hook (byte-wise), then group and time; one hook and group can appear several times (different, hidden arguments, or several instances).

- `summary`: totals of active actions before/after with signed deltas (`action`, `recurring`, `single` — one-time, i.e. single and async — and `unique_hook` counts) and `added_count`, `removed_count`, `rescheduled_count`, `changed_count`.
- `added` (state after) / `removed` (state before): `{hook, group, status, timestamp, schedule_type, interval, cron_expression, is_recurring}`.
  - `group`: the action's group slug, `""` if none. Observed metadata, not ownership.
  - `status`: `pending` or `in-progress`.
  - `timestamp`: scheduled run, Unix timestamp (UTC seconds).
  - `schedule_type`: `single` (one time), `async` (as soon as possible), `interval` (`interval` seconds) or `cron` (`cron_expression`, normalized fields separated by single spaces). `interval` and `cron_expression` are `null` for the other types.
  - A removed action is no longer active: it may have run, been canceled or left the queue otherwise; UpdateLens cannot tell which.
- `rescheduled`: the same action (hook, group and arguments) with the same schedule at another time: `{hook, group, before_timestamp, after_timestamp, timestamp_delta, schedule_type, interval, cron_expression, is_recurring}`. Recurring actions move whenever they run (Action Scheduler stores the next run as a new action), so this is an observation, not a cause.
- `changed`: the same action with another schedule: `{hook, group, before_timestamp, after_timestamp, timestamp_changed, before_schedule_type, after_schedule_type, before_interval, after_interval, before_cron_expression, after_cron_expression, before_is_recurring, after_is_recurring}`.
- Never included: arguments (`args`, `extended_args`), argument fingerprints, the fingerprint context, action, claim or group IDs, serialized schedules, logs, snapshots or raw stored JSON. Actions of every plugin (and Action Scheduler's own) are reported alike; nothing is attributed to a plugin.

An unavailable `action_scheduler` object has `reason`. `not_installed` is a normal state, not an error: many sites have no Action Scheduler.

| `reason`                           | Meaning                                                                                        |
| ---------------------------------- | ---------------------------------------------------------------------------------------------- |
| `update_in_progress`               | The update has not reported back yet.                                                          |
| `awaiting_settle`                  | Within the settle window; not settled yet.                                                     |
| `not_installed`                    | No Action Scheduler was active at either capture of this phase (see below for older analyses). |
| `newly_detected`                   | No Action Scheduler at the phase's first capture; readable at its second.                      |
| `no_longer_detected`               | Action Scheduler was readable at the phase's first capture but not active at its second.       |
| `unsupported_store`                | Action Scheduler uses a data store UpdateLens does not support (custom or legacy posts).       |
| `unsupported_schema`               | The Action Scheduler tables are not a supported version.                                       |
| `unsupported_schedule`             | An active action has a schedule type that cannot be normalized safely.                         |
| `malformed_action_scheduler_state` | Active actions contained data UpdateLens could not safely normalize.                           |
| `snapshot_unavailable`             | A required snapshot could not be read (not initialized, read error, or unreadable stored).     |
| `fingerprint_context_changed`      | The two snapshots could not be compared (e.g. rotated salts).                                  |
| `not_captured`                     | Action Scheduler was not captured (the analysis predates Action Scheduler observation).        |
| `storage_failed`                   | The Action Scheduler result could not be stored with the analysis.                             |
| `analysis_failed`                  | Comparing the snapshots failed.                                                                |
| `settle_expired`                   | No eligible request within the settle window.                                                  |
| `update_failed`                    | The WordPress update failed or did not report completion.                                      |
| `analysis_abandoned`               | Stale, or another update ran in the same request.                                              |
| `analysis_ended`                   | The overall analysis stopped (failed or incompatible) before this phase.                       |
| `data_corrupt`                     | A diff is stored but could not be read (it is never returned).                                 |
| `not_recorded`                     | No Action Scheduler data and no other explanation.                                             |
| `unknown`                          | An unrecognised stored reason (never reinterpreted).                                           |

Analyses recorded before Action Scheduler observation report `not_captured` in every phase: their Options and WP-Cron data are unchanged.

Absence is resolved from both captures of a phase: a phase is `not_installed` only when neither found Action Scheduler; one that appeared or disappeared between them is `newly_detected` (or the second capture's failure, e.g. `unsupported_store`) or `no_longer_detected`, and a phase whose second capture was never taken gets the lifecycle reason (`update_failed`, `settle_expired`, …). Analyses recorded by earlier UpdateLens versions stored `not_installed` as soon as a phase's first capture found no Action Scheduler, so for them `not_installed` says nothing about the phase's second capture. They are reported unchanged.

### `potential_impact`

Three fixed review rules evaluated on the **Net result** (`phases.final`) of the report, from the diffs already decoded for it (`Report\PotentialImpact`). Computed on every read; never stored, no extra data is collected. A finding is an observation that may be worth checking, not a confirmed problem, and never says that the updated plugin caused it. There are no scores and no overall verdict; each finding has a fixed `priority` by its rule (see [Classification](#classification)).

- `phase`: always `final`.
- `status`: `evaluated` (every applicable signal evaluated, at least one), `partial` (some evaluated, some not), `not_evaluated` (none). A `not_applicable` signal does not count either way. An evaluated report with no findings has `findings: []` and `finding_count: 0`; a signal that could not be checked has `finding_count: null`.
- `signals.<signal>` (`options`, `cron`, `action_scheduler`): `status` (`evaluated`, `not_evaluated`, or for Action Scheduler only `not_applicable`), `reason` (the reason of `phases.final.<signal>`, see the tables above, e.g. `settle_expired`, `awaiting_settle`, `malformed_cron_state`, `not_installed`, `data_corrupt`, `not_captured`; `null` if evaluated), `rules` (the rule codes that signal runs), `finding_count`.
- **Action Scheduler `not_applicable`**: only when Action Scheduler is known to have been absent at all three captures (BEFORE, IMMEDIATE and SETTLED): every phase recorded absence at both of its captures. There was no Action Scheduler state whose recurring schedules could change. Its `reason` is `not_installed`. Anything else is `not_evaluated` with the Net result's reason: Action Scheduler appearing (`newly_detected`) or disappearing (`no_longer_detected`) during the observation, capture or read failures, corrupt data, expired, failed or open observations, and analyses recorded before absence was resolved per phase (their `not_installed` cannot show absence at every capture). `not_evaluated` with `not_installed` therefore means "not detected in the Net result, absence throughout not shown".
- `findings`: ordered by signal (`options`, `cron`, `action_scheduler`), then rule, then subject (option name or hook, byte-wise; schedule changes in the diff's order). Every finding has `code`, `signal`, its subject (`option`, or `hook` and for Action Scheduler `group`), `evidence`, `before`, `after`, `records` and `priority`. Field sets are fixed per code.
- `records`: the Net result records the finding covers, as `{list, index, role}`: `list` (`added`, `removed`, `changed`, `rescheduled`) and `index` point into `phases.final.<signal>` of the same response; `role` is `subject` (the change the finding is about) or `replacement` (`recurring_cron_event_removed` only: a recurring instance of the same hook added in the Net result, which the rule weighed against the removed ones). Records are never shared between findings. Clients mark these rows and never match rows to evidence themselves; the assessment counts the same records. Added later; additive.

| `code`                         | Signal                     | When                                                                                                                                                                                                        |
| ------------------------------ | -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `large_autoloaded_option`      | `options`                  | An option is effectively autoloaded after the update, larger than 150,000 bytes (advisory review size, not a WordPress limit), and got there by being added, becoming autoloaded, or growing past the size. |
| `recurring_cron_event_removed` | `cron`                     | More recurring instances of a hook were removed than recurring instances of that hook were added.                                                                                                           |
| `recurring_schedule_changed`   | `cron`, `action_scheduler` | The same job (hook + arguments, and group) was recurring before and its recurrence configuration differs after.                                                                                             |

**`large_autoloaded_option`**: `option`; `evidence.transition` (`added`, `became_autoloaded`, `grew_past_threshold`), `evidence.threshold_bytes`, `evidence.size_delta` (signed bytes, `null` if added), `evidence.value_changed` (`null` if added; `false` means only autoload changed); `before` (`null` if added) and `after`: `{size, autoload, is_autoloaded}` (raw autoload value and effective behavior). Options that were already large and autoloaded, removed options and options that are not autoloaded after are not findings. Site totals stay in `phases.final.options.summary`.

**`recurring_cron_event_removed`**: `hook`; `evidence.removed_recurring_count`, `added_recurring_count`, `not_replaced_count` (removed minus added), `other_recorded_changes` (`added_one_time`, `rescheduled`, `changed`: other entries of this hook in the Net result); `before.removed_recurring` and `after.added_recurring`: `[{timestamp, schedule, interval}]` (`timestamp` = the run that was scheduled, `interval` `null` if not stored). Instances that did not change are never part of a diff, so the evidence describes recorded changes only: it **never says whether the hook has any event left**. A `rescheduled` or `changed` count above 0 means at least one instance of the hook is still scheduled. Arguments are not in the diff: a recurring addition of the same hook counts as a replacement. Removed one-time events are never findings.

**`recurring_schedule_changed`**: `hook` (and `group` for Action Scheduler); `evidence.change`; `before` / `after` as in the signal's `changed` list: WP-Cron `{timestamp, schedule, interval, is_recurring}`, Action Scheduler `{timestamp, schedule_type, interval, cron_expression, is_recurring}`.

| `evidence.change`         | Meaning                                                                              |
| ------------------------- | ------------------------------------------------------------------------------------ |
| `no_longer_recurring`     | Recurring before, one-time (or async) after.                                         |
| `interval_changed`        | Both recurring by interval, both intervals known and different.                      |
| `schedule_type_changed`   | Action Scheduler only: interval ↔ cron expression.                                   |
| `cron_expression_changed` | Action Scheduler only: both cron, and the expressions differ in meaning (see below). |

Not findings: next-run rescheduling (a normal run), Action Scheduler status changes (pending → in-progress) and actions that left the queue, one-time → recurring, WP-Cron schedule renames with the same interval, WP-Cron changes where an interval is not stored, and Action Scheduler cron expressions that are written differently but mean the same: case, `?` for `*`, `*/1` for `*`, leading zeros, month and weekday names for their numbers, and a trailing year field `*`. Expressions are returned as stored.

Never included: option values, arguments, argument fingerprints, md5 event keys, fingerprint contexts, IDs, snapshots, raw stored JSON or explanatory text. Presentation turns the codes and evidence into localized wording (what changed, why it may matter, what to check next).

```json
"potential_impact": {
  "phase": "final",
  "status": "evaluated",
  "signals": {
    "options": { "status": "evaluated", "reason": null, "rules": ["large_autoloaded_option"], "finding_count": 1 },
    "cron": { "status": "evaluated", "reason": null, "rules": ["recurring_cron_event_removed", "recurring_schedule_changed"], "finding_count": 1 },
    "action_scheduler": { "status": "not_applicable", "reason": "not_installed", "rules": ["recurring_schedule_changed"], "finding_count": null }
  },
  "findings": [
    {
      "code": "large_autoloaded_option",
      "signal": "options",
      "option": "acme_feed_cache",
      "evidence": { "transition": "became_autoloaded", "threshold_bytes": 150000, "size_delta": 0, "value_changed": false },
      "before": { "size": 182340, "autoload": "off", "is_autoloaded": false },
      "after": { "size": 182340, "autoload": "on", "is_autoloaded": true },
      "records": [{ "list": "changed", "index": 0, "role": "subject" }],
      "priority": "review"
    },
    {
      "code": "recurring_cron_event_removed",
      "signal": "cron",
      "hook": "acme_sync_feeds",
      "evidence": {
        "removed_recurring_count": 1,
        "added_recurring_count": 0,
        "not_replaced_count": 1,
        "other_recorded_changes": { "added_one_time": 1, "rescheduled": 0, "changed": 0 }
      },
      "before": { "removed_recurring": [{ "timestamp": 1791223800, "schedule": "hourly", "interval": 3600 }] },
      "after": { "added_recurring": [] },
      "records": [{ "list": "removed", "index": 0, "role": "subject" }],
      "priority": "review"
    }
  ],
  "site_checks": { "status": "not_applicable", "checks": { … }, "findings": [] },
  "assessment": {
    "high_priority": 0,
    "needs_review": 2,
    "informational": 3,
    "assessed_changes": 5,
    "net_result": {
      "options": { "changes": 3, "matched": 1, "informational": 2 },
      "cron": { "changes": 2, "matched": 1, "informational": 1 },
      "action_scheduler": null
    },
    "not_assessed": [],
    "pending": []
  }
}
```

#### Classification

Every finding has a fixed `priority` by its rule (`PotentialImpact::PRIORITIES`), and `potential_impact.assessment` (`PotentialImpact::assess()`) summarizes the report. PHP is the only source of these classifications; clients display them and never recount them.

| Classification | Meaning                                                                                                                                                                                                                                |
| -------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| High priority  | `priority: "high"`: confirmed evidence that an observed response stopped working: `site_check_newly_failing` (a verified pass before the update, a failure both requests confirmed after it). Only site check rules are high priority. |
| Needs review   | `priority: "review"`: an evidence-based pattern worth a look: `large_autoloaded_option`, `recurring_cron_event_removed`, `recurring_schedule_changed`, `site_check_changed` (a new homepage redirect).                                 |
| Informational  | Net result change records no finding covers: observed, no review rule matched. A count, never a finding and never "safe".                                                                                                              |
| Not assessed   | Observations that could not be assessed (`not_assessed`), with their reason and category. Never a priority, never "no changes" or "passed".                                                                                            |

- `high_priority`, `needs_review`: findings by priority (configuration and site check findings).
- `net_result.<signal>`: for each configuration signal evaluated on the Net result, `changes` (its change records: one per entry of `added`, `removed`, `changed`, and `rescheduled` for WP-Cron and Action Scheduler; the same total as the phase's summary counts), `matched` (the distinct `records` of its findings) and `informational` (`changes - matched`); `null` for a signal that was not evaluated or is not applicable. Exact per record: a hook with two recurring instances removed and one re-added has 3 matched records, and a one-time instance of the same hook removed with them stays informational.
- `informational`, `assessed_changes`: the sums of `informational` and `changes` over the evaluated signals; `null` when none was evaluated. In a `partial` evaluation they cover the evaluated signals only.
- `not_assessed`: `[{type, name, reason, category}]`: `type` `signal` (`options`, `cron`, `action_scheduler`) or `site_check` (`home`, `rest`); `reason` the signal's Net result reason or the check's evaluation reason; `category`:

| `category`     | Meaning                                                                                                                                                                                     |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `expired`      | The observation after the update was not captured within the settle window (`settle_expired`): the Net result and the checks after the update are missing. Missing does not mean unchanged. |
| `not_recorded` | The report predates the source (`not_captured`, `not_recorded`): a historical gap.                                                                                                          |
| `unavailable`  | The source could not be collected, stored, read or compared (capture or storage failures, unsupported or malformed state, corrupt data, Action Scheduler not confirmed absent throughout).  |
| `not_compared` | A site check without a usable check before and after the update (missing, inconclusive, another update in between, not followed up, …); `reason` says which.                                |

- `pending`: `[{type, name}]` still being observed: the Net result while the analysis is open, site checks whose result may still arrive.
- Not applicable sources (Action Scheduler absent throughout, site checks turned off, unsupported or not recorded) are in neither list.

#### `potential_impact.site_checks`

The report's [`site_checks`](#site_checks) comparison evaluated beside the Net result (`PotentialImpact::evaluate_site_checks()`). Site checks are observations taken by the updating administrator's browser around the update, not part of any phase: they never change `potential_impact.status`, `signals` or `findings`, and the same result applies whichever phase a client shows. Added later; additive.

- `status`: `evaluated` (every applicable check compared), `partial` (some), `pending` (an AFTER result can still arrive), `not_evaluated` (none compared), `not_applicable` (no check applies: site checks were turned off for this update, both targets are on another origin than wp-admin, or it was recorded before UpdateLens had them).
- `checks.home`, `checks.rest`: `status` (`evaluated`, `pending`, `not_evaluated`, `not_applicable`), `state` (the comparison state from `site_checks`), `change` (for `changed`: `redirect_appeared`, `redirect_disappeared`, `redirect_then_failure`; else `null`), `reason` (`null` when evaluated or pending; for `not_comparable` the comparison's reason, e.g. `after_inconclusive`, `overlapping_update`; for `not_run` the reason of the missing side, BEFORE first, e.g. `missing`, `not_completed_before_update`, `not_reported`, `not_started`), `finding_count` (0 or 1 when evaluated, else `null`). `not_applicable` only for the reasons `disabled`, `cross_origin` and `not_captured`; every other unavailable check is `not_evaluated`, never "no findings".
- `findings` (homepage first): only from the comparison state, never inferred from other fields.

| `code`                     | When (comparison state)                                                                                                                                                     | `evidence`                                                                                                                                                                                                             |
| -------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `site_check_newly_failing` | `newly_failing`: passed before, and both requests after the update failed.                                                                                                  | `failure` (the AFTER result's reason: `server_error`, `not_found`, `refused`, `client_error`, `timeout`, `incomplete`, `wrong_user`, `unexpected_json`, `extra_output`, `invalid_json`, `redirected`), `attempt_count` |
| `site_check_changed`       | `changed` with `redirect_appeared`: the homepage passed (WordPress verified) before the update and redirected in both requests after it. A changed response, not a failure. | `change` (`redirect_appeared`), `attempt_count`                                                                                                                                                                        |

Each finding has `code`, `signal` (`site_checks`), `check` (`home`, `rest`), `evidence`, and `before` / `after`: the check's sides exactly as in `site_checks` (verdicts, browser-observed attempt facts, UTC times, `age_seconds`, `delay_seconds`). Not findings: `passed_both`, `failing_both` (already failing before the update), `recovered`, `changed` with `redirect_disappeared` or `redirect_then_failure` (the baseline was a redirect, not a verified pass, so nothing can be called newly failing; the comparison and its `change` stay in `checks`), `not_run`, `not_comparable` (including a failure followed by a passing retry, which is inconclusive `unconfirmed`), `pending`. A finding says what the browser observed; it never says what caused it, that WordPress or the updated plugin produced a response, or that a passing check means the site works.

```json
"site_checks": {
  "status": "evaluated",
  "checks": {
    "home": { "status": "evaluated", "state": "newly_failing", "change": null, "reason": null, "finding_count": 1 },
    "rest": { "status": "evaluated", "state": "passed_both", "change": null, "reason": null, "finding_count": 0 }
  },
  "findings": [
    {
      "code": "site_check_newly_failing",
      "signal": "site_checks",
      "check": "home",
      "evidence": { "failure": "server_error", "attempt_count": 2 },
      "before": { "available": true, "reason": null, "started_at": "…", "completed_at": "…", "result": { "verdict": "pass", … }, "age_seconds": 252 },
      "after": { "available": true, "reason": null, "started_at": "…", "completed_at": "…", "result": { "verdict": "fail", "reason": "server_error", … }, "delay_seconds": 3 }
    }
  ]
}
```

### `site_checks`

Opt-in site checks (`updatelens_site_checks` = `enabled`) observed by the updating administrator's browser: the anonymous homepage (`home`) and `wp/v2/users/me` as that administrator (`rest`), each with WordPress verified as the origin by a per-run marker. A BEFORE baseline taken on the Plugins or Updates screen is attached when the update starts; the AFTER run starts in the same browser right after the follow-up request settled the analysis. Computed on every read from the stored results (`Report\SiteCheckReport`); observations, never proof of a cause.

- `status`: `pending` (an AFTER result can still arrive), `complete` (both checks compared), `partial` (one compared), `unavailable` (none).
- `checks.home`, `checks.rest`, each:
  - `state`: compared — `passed_both`, `newly_failing` (passed before, failure confirmed by both attempts after), `failing_both`, `recovered`, `changed` (a homepage redirect appeared or disappeared); otherwise `not_run` (a side is missing), `not_comparable` (both exist but may not be compared), or `pending`.
  - `reason`: `null` when compared or pending; for `not_run`: `baseline_missing`, `after_missing`; for `not_comparable`: `overlapping_update` (other updater activity after this update ended, before the AFTER result was accepted), `update_order_unknown` (the updater activity ledger could not rule that out), `definition_changed`, `target_changed`, `different_user`, `different_login_session`, `baseline_after_update_start`, `baseline_expired`, `after_during_update`, `before_inconclusive`, `after_inconclusive` (e.g. a failure followed by a passing retry, maintenance mode, a cached page, a firewall), `redirected_both`.
  - `before` and `after`: `available`, `reason`, `started_at` / `completed_at` (the run's session start and the server's acceptance of its report, UTC, whole seconds), `result` (`verdict`: `pass`, `fail`, `redirect`, `inconclusive`; its `reason`; and per attempt the browser-observed facts: `outcome`, `status`, `duration_ms`, the marker codes `header` and `end_marker` (homepage) or `body` and `error_code` (REST), `origin`, the attempt's own `verdict` and `reason`), plus `before.age_seconds` (baseline age when the update started) and `after.delay_seconds` (from the settled observation to the AFTER run).
  - `before.reason` without a result: `disabled`, `no_login_session`, `cross_origin` (the check's target is on another origin — scheme, host or port — than wp-admin, so the browser cannot run it on this site; never a pass), `update_order_unknown`, `overlapping_update`, `missing`, `corrupt`, `definition_changed`, `target_changed`, `not_completed_before_update`, `expired`, `taken_during_update`, `intervening_update` (another update since the baseline, e.g. queued updates), `selection_failed`, `not_captured` (recorded before site checks), `data_corrupt`, `unknown`.
  - `after.reason` without a result: `no_baseline` (nothing to compare with, so not run), `analysis_ended` (failed, abandoned or incompatible), `settle_expired`, `next_update` (another plugin update started right after this one: a second "Update now" queued behind it, or a bulk update, so the observation ended there and no check could run after this update alone), `not_followed_up` (settled by a later page load, not the browser's follow-up: the page was left while it was updating, the follow-up request did not arrive, another plugin answered it with its own page, such as a redirect to a setup screen, or the update ran from Dashboard → Updates before its update page sent a follow-up), `not_started` (the AFTER run never started in time: tab closed, request failed, site checks turned off), `not_reported` (started, no result accepted before its session ended: tab closed, site or admin-ajax unreachable), `overlapping_update` / `update_order_unknown` (seen at the AFTER start; nothing ran), `rate_limited`, `session_failed`, `not_captured`, `data_corrupt`, `unknown`. With a result, `after.reason` is `null`, or `overlapping_update` / `update_order_unknown`: the facts are shown, the check is not compared.

A homepage that answers HTTP 500 twice after the update (REST unchanged; the REST check is shortened here as `{…}`, its sides have the same shape):

```json
"site_checks": {
  "status": "complete",
  "checks": {
    "home": {
      "state": "newly_failing",
      "reason": null,
      "before": {
        "available": true, "reason": null,
        "started_at": "2026-10-10T19:02:54Z", "completed_at": "2026-10-10T19:02:55Z",
        "result": { "verdict": "pass", "reason": null, "attempts": [
          { "outcome": "success", "status": 200, "header": "current", "end_marker": "current", "duration_ms": 284, "origin": "verified", "verdict": "pass", "reason": null }
        ] },
        "age_seconds": 0
      },
      "after": {
        "available": true, "reason": null,
        "started_at": "2026-10-10T19:02:56Z", "completed_at": "2026-10-10T19:03:02Z",
        "result": { "verdict": "fail", "reason": "server_error", "attempts": [
          { "outcome": "server_error", "status": 500, "header": "current", "end_marker": "absent", "duration_ms": 224, "origin": "verified", "verdict": "fail", "reason": "server_error" },
          { "outcome": "server_error", "status": 500, "header": "current", "end_marker": "absent", "duration_ms": 219, "origin": "verified", "verdict": "fail", "reason": "server_error" }
        ] },
        "delay_seconds": 0
      }
    },
    "rest": { "state": "passed_both", "reason": null, "before": {…}, "after": {…} }
  }
}
```

Incomplete: no REST baseline was attached, so only the homepage ran after the update:

```json
"site_checks": {
  "status": "partial",
  "checks": {
    "home": { "state": "passed_both", "reason": null, "before": {…}, "after": {…} },
    "rest": {
      "state": "not_run",
      "reason": "baseline_missing",
      "before": { "available": false, "reason": "missing", "started_at": null, "completed_at": null, "result": null, "age_seconds": null },
      "after": { "available": false, "reason": "no_baseline", "started_at": null, "completed_at": null, "result": null, "delay_seconds": null }
    }
  }
}
```

A missing or late result is never a pass. UpdateLens cannot detect a complete site outage that also takes down `admin-ajax.php`: the browser then cannot report, and the check reads `not_reported`. Never included: URLs, markers, nonces, secrets, cookies, response bodies, headers, user IDs, login-session or target fingerprints, updater generations.

## `GET /updatelens/v1/baseline`

When UpdateLens started monitoring, and which plugins were installed at that moment. Supportive data for the first-run screen and the History boundary; it is not an analysis and contains no snapshot data.

```json
{
  "started_at": "2026-10-07T22:32:00Z",
  "plugin_count": 2,
  "plugins": [
    {
      "file": "classic-editor/classic-editor.php",
      "name": "Classic Editor",
      "version": "1.7.0",
      "active": false
    },
    {
      "file": "woocommerce/woocommerce.php",
      "name": "WooCommerce",
      "version": "11.1.2",
      "active": true
    }
  ]
}
```

- `started_at`: UTC ISO 8601, or `null` if unknown. Updates before this point were not observed. It is the earlier of the stored baseline time and the first stored analysis (lowest ID), so sites that ran UpdateLens before the baseline existed start at their first analysis. Nothing is reconstructed from other data.
- `plugin_count`, `plugins`: the plugins installed when monitoring started, sorted by name; `null` unless the stored baseline is the monitoring start (missing or unreadable baseline, or a baseline recorded after earlier analyses). UpdateLens itself is never listed. Only file, name, version and active state are kept: no settings, plugin URIs, update data, license data or user information. The list is never updated after monitoring started: later installs, updates and activation changes appear only in reports.
- A missing or unreadable baseline is not an error: its fields are `null` (HTTP 200). If the analyses cannot be read: `500 updatelens_reports_unavailable`.
- The History boundary requests only the start time, with WordPress's `_fields=started_at`.

Storage: option `updatelens_monitoring_baseline` (not autoloaded), versioned JSON written by `Storage\MonitoringBaselineCodec`: `{"schema":1,"started_at":"2026-10-07 22:32:00","plugins":[{"file":…,"name":…,"version":…,"active":…}]}`. It is written once by `Baseline\MonitoringBaseline::ensure()`: on activation, or, if activation could not write it (e.g. sites upgraded from a version without a baseline), when the UpdateLens screen loads. An existing value, even an unreadable one, is never replaced. Deactivation keeps it; uninstall deletes it.

## `GET /updatelens/v1/settings` and `POST /updatelens/v1/settings`

The site check opt-in, shown on the UpdateLens Settings screen (`admin.php?page=updatelens-settings`). Both methods need `manage_options` (running site checks still needs `update_plugins`). WordPress's REST cookie authentication applies: a request without a `wp_rest` nonce is treated as signed out (`401 rest_forbidden`), an invalid nonce is `403 rest_cookie_invalid_nonce`.

```json
{
  "site_checks": {
    "enabled": true,
    "effective": false,
    "restriction": "filter"
  }
}
```

- `enabled`: the saved preference. `false` on a new installation; nothing but a save turns it on.
- `effective`: whether site checks may run: the preference is on and the site's configuration does not keep them off. The start and report endpoints, the update screens and the update request all use this value.
- `restriction`: `"filter"` when the `updatelens_site_checks_enabled` filter returns false (site checks stay off whatever is saved; the filter can never turn them on), else `null`.

`POST` accepts exactly one JSON body, `{"site_checks":{"enabled":true}}` or `{"site_checks":{"enabled":false}}`, and answers with the stored state read back (same shape as `GET`). Anything else is a `400` and changes nothing: other keys at either level (`updatelens_invalid_settings`, `rest_invalid_param`), strings, numbers or `null` for `enabled` (`rest_invalid_param`), no `site_checks` (`rest_missing_callback_param`), form-encoded or query-string values, invalid JSON (`rest_invalid_json`). Saving the stored value again is not an error. If the value cannot be written or read back: `500 updatelens_settings_not_saved`; if it cannot be read: `500 updatelens_settings_unavailable`. Database errors are never part of a response.

Storage: option `updatelens_site_checks` (not autoloaded): `enabled` or `disabled`; anything else, or no option, is off. Turning site checks off stops new check sessions at once (the start and report endpoints answer `disabled`). Sessions already started expire within two minutes, and a report sent after turning them off is not accepted, so that check stays unavailable in its report. Stored reports, baselines and history are not changed or deleted. Uninstall deletes the option.
