# UpdateLens REST API (v0.1)

Read-only endpoints for analysis history, reports and the monitoring start, namespace `updatelens/v1`. The admin app calls them through `wp.apiFetch` (cookie authentication + `wp_rest` nonce).

- **Permission:** `manage_options` (`Core\Plugin::CAPABILITY`) on every route. Not logged in → `401 rest_forbidden`; logged in without the capability → `403 rest_forbidden`.
- **Single site only.** The analysis and baseline routes are not registered on Multisite.
- **Read-only.** Before every history or report read, analyses past their settle deadline are expired (`completed`, `settle_outcome: "expired"`, no settled snapshot), and the analyses table is recreated if it is missing. Nothing else is changed.
- **Errors:** if storage cannot be read (or the table cannot be recreated): `500 updatelens_reports_unavailable` with a generic message. Database errors are never part of a response.
- **Types:** IDs, counts, byte sizes and deltas are JSON integers; flags are booleans. Timestamps are UTC ISO 8601 (`2026-10-05T17:46:23Z`) or `null`, never site-local time.
- **Wording:** phases describe _when_ changes were observed, not what caused them.

`GET /updatelens/v1/status` is unchanged (`{"version":"…"}`).

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
- `settle_outcome`: `null` while open, else `admin_shutdown`, `follow_up`, `next_update`, `expired`, `not_applicable`, or `unknown`. `follow_up`: the post-update observation was taken by the follow-up request the updating administrator's browser sends once the WordPress update queue is idle; `admin_shutdown`: at the end of a later wp-admin page request.
- `error`: `null` or `{"code": "…"}`, a sanitized identifier (e.g. `update_not_completed`, `fingerprint_context_changed`, or a WordPress updater code such as `incompatible_archive`). No messages, paths or URLs.
- `observation_window_seconds`: length of the post-update observation window. Presentation derives its wording ("within 5 minutes") from it.
- `potential_impact`: review rules evaluated on the Net result, with per-signal evaluation status (see [`potential_impact`](#potential_impact)). Added later; every other field is unchanged.
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

Three fixed review rules evaluated on the **Net result** (`phases.final`) of the report, from the diffs already decoded for it (`Report\PotentialImpact`). Computed on every read; never stored, no extra data is collected. A finding is an observation that may be worth checking, not a confirmed problem, and never says that the updated plugin caused it. There are no severities or scores.

- `phase`: always `final`.
- `status`: `evaluated` (every applicable signal evaluated, at least one), `partial` (some evaluated, some not), `not_evaluated` (none). A `not_applicable` signal does not count either way. An evaluated report with no findings has `findings: []` and `finding_count: 0`; a signal that could not be checked has `finding_count: null`.
- `signals.<signal>` (`options`, `cron`, `action_scheduler`): `status` (`evaluated`, `not_evaluated`, or for Action Scheduler only `not_applicable`), `reason` (the reason of `phases.final.<signal>`, see the tables above, e.g. `settle_expired`, `awaiting_settle`, `malformed_cron_state`, `not_installed`, `data_corrupt`, `not_captured`; `null` if evaluated), `rules` (the rule codes that signal runs), `finding_count`.
- **Action Scheduler `not_applicable`**: only when Action Scheduler is known to have been absent at all three captures (BEFORE, IMMEDIATE and SETTLED): every phase recorded absence at both of its captures. There was no Action Scheduler state whose recurring schedules could change. Its `reason` is `not_installed`. Anything else is `not_evaluated` with the Net result's reason: Action Scheduler appearing (`newly_detected`) or disappearing (`no_longer_detected`) during the observation, capture or read failures, corrupt data, expired, failed or open observations, and analyses recorded before absence was resolved per phase (their `not_installed` cannot show absence at every capture). `not_evaluated` with `not_installed` therefore means "not detected in the Net result, absence throughout not shown".
- `findings`: ordered by signal (`options`, `cron`, `action_scheduler`), then rule, then subject (option name or hook, byte-wise; schedule changes in the diff's order). Every finding has `code`, `signal`, its subject (`option`, or `hook` and for Action Scheduler `group`), `evidence`, `before` and `after`. Field sets are fixed per code.

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
      "after": { "size": 182340, "autoload": "on", "is_autoloaded": true }
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
      "after": { "added_recurring": [] }
    }
  ]
}
```

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
