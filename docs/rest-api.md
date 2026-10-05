# UpdateLens REST API (v0.1)

Read-only endpoints for analysis history and reports, namespace `updatelens/v1`. The admin app calls them through `wp.apiFetch` (cookie authentication + `wp_rest` nonce).

- **Permission:** `manage_options` (`Core\Plugin::CAPABILITY`) on every route. Not logged in → `401 rest_forbidden`; logged in without the capability → `403 rest_forbidden`.
- **Single site only.** The analysis routes are not registered on Multisite.
- **Read-only.** Before every read, analyses past their settle deadline are expired (`completed`, `settle_outcome: "expired"`, no settled snapshot), and the analyses table is recreated if it is missing. Nothing else is changed.
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

Body: an array of history items. Phase flags only say whether a diff is stored; diffs are not decoded for the list.

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
    "has_final": true
  }
]
```

## `GET /updatelens/v1/analyses/{id}`

One report. Unknown ID → `404 updatelens_analysis_not_found`.

```json
{
  "id": 5,
  "plugin": {
    "file": "updatelens-fixture-c/updatelens-fixture-c.php",
    "name": "UpdateLens Fixture C",
    "version_before": "1.0.0",
    "version_after": "1.1.0"
  },
  "status": "completed",
  "settle_outcome": "expired",
  "timestamps": {
    "started_at": "2026-10-05T18:50:00Z",
    "settle_deadline": "2026-10-05T18:55:02Z",
    "completed_at": "2026-10-05T19:10:41Z"
  },
  "phases": {
    "during_update": {
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
    "post_update": {
      "available": false,
      "association": "observed_after_update",
      "reason": "settle_expired"
    },
    "final": {
      "available": false,
      "association": "net_across_phases",
      "reason": "settle_expired"
    }
  },
  "error": null
}
```

### Fields

- `plugin.file`: plugin basename (`dir/file.php`), or `null` if the stored value is not a valid basename. `version_after` is `null` until the update finished.
- `status`: `captured`, `awaiting_settle`, `completed`, `failed`, `incompatible`, `abandoned`, or `unknown` (unrecognised stored value; never reinterpreted).
- `settle_outcome`: `null` while open, else `admin_shutdown`, `next_update`, `expired`, `not_applicable`, or `unknown`.
- `error`: `null` or `{"code": "…"}`, a sanitized identifier (e.g. `update_not_completed`, `fingerprint_context_changed`, or a WordPress updater code such as `incompatible_archive`). No messages, paths or URLs.
- No user information, snapshots, fingerprints or option values are returned.

### Phases

| Phase           | Compares            | `association`           |
| --------------- | ------------------- | ----------------------- |
| `during_update` | BEFORE → IMMEDIATE  | `update_request`        |
| `post_update`   | IMMEDIATE → SETTLED | `observed_after_update` |
| `final`         | BEFORE → SETTLED    | `net_across_phases`     |

An available phase holds the stored `wp_options` diff: `summary` (signed deltas, `after - before`), `added`, `removed`, `changed` (sorted by name). Other site activity can appear in `post_update` and `final`.

An unavailable phase is `{"available": false, "association": "…", "reason": "…"}` with `reason`:

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
