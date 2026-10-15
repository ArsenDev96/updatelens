# WIP checkpoint: Site Checks investigation and report UX

Branch `wip/site-checks-report-ux`, based on `main` at `9ef346d` (UpdateLens 0.3.0, schema 7). Work in progress: **not reviewed, not approved, not for release.** UpdateLens 0.3.0 is not approved for WordPress.org submission.

Constraints that still apply: no version bump, no schema change (still 7), no release tag, no submission, the validated release ZIP (`release/updatelens-0.3.0.zip`, SHA-256 `18b19d34…936a`, built from `9ef346d`) is untouched and does **not** contain this work.

## 1. Missing AFTER site checks: root cause

Report: Yoast Duplicate Post 4.5 → 4.7, updated from Plugins → Installed Plugins; both BEFORE checks passed (HTTP 200), neither AFTER check ran; the report said the update "finished another way, for example from Dashboard → Updates".

That sentence is the AFTER reason `not_followed_up`: the analysis was settled by something other than the updating browser's follow-up request (`settle_outcome` ≠ `follow_up`), and AFTER checks start only from that follow-up.

Reproduced on a disposable WordPress 7.1.2 / PHP 8.3 site with the real Duplicate Post 4.5 → 4.7 package, Chrome via Playwright, every admin-ajax request logged:

| Workflow                                       | Settled by       | AFTER checks | Report said before this branch         |
| ---------------------------------------------- | ---------------- | ------------ | -------------------------------------- |
| Installed Plugins, one "Update now"            | `follow_up`      | ran, passed  | Passed before and after                |
| Plugin details modal → "Update Now"            | `follow_up`      | ran, passed  | Passed before and after                |
| Two "Update now" clicks in a row (queued)      | `next_update`    | none         | "finished another way…" (wrong)        |
| Bulk action "Update" with two plugins selected | `next_update`    | none         | "finished another way…" (wrong)        |
| Page left while it shows "Updating…"           | `admin_shutdown` | none         | "finished another way…" (partly wrong) |
| Page left right after "Updated!"               | `follow_up`      | not started  | "page was closed or left" (correct)    |
| Dashboard → Updates                            | `admin_shutdown` | none         | "finished another way…" (correct)      |

Conclusion: a single supported "Update now" works with Duplicate Post; the plugin is not the cause. In the supported Installed Plugins workflow the AFTER checks are (correctly) impossible when another update starts right after this one, because the next update ends the first observation (`next_update`) and a check afterwards would measure both updates. The defect is the **reporting**: it blamed an unsupported route. Most likely cause on the real site: a second update queued right after Duplicate Post (or a bulk update). To confirm on that site: the report's Technical details → observation outcome. "Observation completed before another plugin update began." means a queued update; "…on the next admin request." means the page was left during the update (or Dashboard → Updates, or a lost follow-up).

Not reproduced, kept as hypotheses (not changed): the follow-up request (admin-ajax) does not get WordPress's admin memory raise, so a site near its memory limit could answer `capture_failed` and be settled later by an admin page; a firewall blocking the follow-up POST; another admin tab settling first.

Smallest fix (implemented, reporting only, no capture or schema change): a new read-only derived AFTER reason `next_update` (`SiteCheck\AfterReason::NEXT_UPDATE`, from `settle_outcome = next_update` in `Update\SiteCheckAfter::derived_reason()`), its own sentence, a corrected `not_followed_up` sentence (Dashboard → Updates, page left while updating, or follow-up lost), and one "how to compare next time" sentence (`siteCheckNextTimeText()`). Nothing offers to reconstruct a missing comparison.

Not implemented (needs a decision): AFTER checks for Dashboard → Updates (the outer `update-core.php` page could send the follow-up when the update iframe loads); raising the memory limit in the follow-up request.

## 2. Impact classification (PHP owns it, evaluated on every read, never stored)

- `Report\PotentialImpact::PRIORITIES`: every finding carries `priority`: `high` only for `site_check_newly_failing` (verified pass before, failure confirmed by both requests after); `review` for `site_check_changed` (new homepage redirect), `large_autoloaded_option`, `recurring_cron_event_removed`, `recurring_schedule_changed`.
- `PotentialImpact::assess()` → `potential_impact.assessment` (additive API field): `high_priority`, `needs_review`, `informational` (Net result changes no finding covers: summary record counts minus the records each finding is about; `null` when nothing was evaluated; never "safe"), `not_assessed` (signals and site checks `not_evaluated`, with reason), `pending` (still observing / AFTER may still arrive). `not_applicable` (Action Scheduler absent throughout, site checks off/unsupported/not recorded) is in neither list.
- Rescheduling (e.g. a WP-Cron next run moved by one minute) is informational, never a finding.
- Changed tests: `tests/Unit/Report/PotentialImpactAssessmentTest.php` (new), priority added to expected findings, `tests/fixtures/site-check-impact-cases.json` regenerated (only `priority` added).
- Approved in principle (2026-10-14); CLAUDE.md and `docs/rest-api.md` now describe it (see section 6).

## 3. Report redesign (state)

Overview order: header → status line (when open/partial) → **summary** (`components/ReportSummary.tsx`: rows Findings (priority counts, or the completeness headline, plus the informational count), Configuration, Site checks, Not assessed (only when a configuration source is missing); each row jumps to its section) → **Findings** (`components/PotentialImpact.tsx`, `h3` "Findings": priority badge and left rule per row, high priority first; expanded: What changed → Why it might matter → What to check next → Evidence; not rendered when no phase exists) → **Site checks** (`components/SiteResponseChecks.tsx`: two compact rows Check | Before | After | Result, shared reason once plus next-time guidance below, requests/verification behind each row's disclosure) → **Configuration changes** (`components/ReportOverview.tsx` `ConfigurationChanges`: coverage line, non-causal note, quieter phase tabs, one compact row per signal) → Technical details.

Removed: `ReportGlance.tsx` (three-column summary), the in-tab "You are viewing During update" callout, the large tinted phase panel and signal cards, the amber "checks that did not run" list inside Findings (moved to the summary's Not assessed row), `impactGlance()`.

Signal pages: compact summary (no large tinted panel), the line "Review rules matched N changes; they are marked below / N other changes matched no rule (informational)" inside it, a priority + rule label on each matched row (`matchedRows()` in `utils/highlight.ts`, same matching as finding links), rescheduled WP-Cron rows say "Only the next run moved (+1 minute); the recurrence is unchanged.", site totals as one quiet line. The located-row label is now "From Findings".

Page heights at 1280 px (before → after, same real reports): no changes 1762 → 1451, routine Cron 1823 → 1472, several findings 2217 → 1903, homepage 500 1975 → 1681, not compared (Duplicate Post) 1970 → 1586, WP-Cron page 1139 → 998. No horizontal overflow at 390 or 320 px.

## 4. Checks run on this branch

- `composer test` (PHPUnit, PHP 7.4 in Docker): OK, 1608 tests, 24126 assertions.
- `composer lint` (PHPCS): clean.
- `npm run check` (typecheck, ESLint, Prettier): clean.
- `npm test` (Vitest): 718 tests pass. Under parallel load `App.test.tsx` (privacy) and `Clarity.test.tsx` (long lists) once exceeded the 5 s timeout; both pass when run alone (known timing flake, not a regression).
- Real-browser scenarios (WordPress 7.1.2 / PHP 8.3, Chrome): the workflow table above; nine scenario reports with real updates (no changes + both passed, routine WP-Cron reschedule, new large autoloaded option, removed recurring WP-Cron event, homepage HTTP 200 → confirmed HTTP 500, site checks enabled but not compared (queued Duplicate Post), Dashboard → Updates, partial/expired report, several findings).

Not done: axe accessibility scan of the new views (the scan script was interrupted), keyboard walkthrough in a real browser (unit tests cover focus and `aria-expanded`), WordPress 6.2 / PHP 7.4 browser run, CLAUDE.md and `docs/rest-api.md` updates for `next_update`, `priority` and `assessment`, `languages/updatelens.pot` regeneration.

## 5. Known issues and next steps

1. Review the before/after screenshots (they are not in the repository; see below) and approve or adjust the layout and wording.
2. Approve or change the classification rules (section 2), then update CLAUDE.md (Potential Impact rules, Admin UI rules, Site check rules: AFTER reasons) and `docs/rest-api.md` (`assessment`, `priority`, `next_update`).
3. Run the axe scan, a keyboard pass, and a WordPress 6.2 / PHP 7.4 browser run.
4. Decide on Dashboard → Updates AFTER coverage and the follow-up memory raise (section 1).
5. Confirm on the real site which case ended the Duplicate Post report (Technical details → observation outcome).
6. Only after approval: rebuild and revalidate a release ZIP (the current validated ZIP predates this work).

## 6. Review fixes (2026-10-14, commit `767bcbd` on top of `c09091c`)

From the visual review (`E:\updatelens-review\2026-10-14-report-ux\REVIEW.md`; before/after in `round2/`):

- **Count disagreement fixed.** PHP's informational count was already exact; the signal page recomputed it in React (`count − rows located by evidence`), which missed a removed hook's re-added recurring instances (2 removed + 1 re-added: overview 1 informational, signal page 2). Now every configuration finding names its Net result records (`records`: list, index, role `subject`/`replacement`), `assess()` counts distinct records per signal (`assessment.net_result`, `assessed_changes`), and the signal page only displays those numbers and marks those records (`utils/highlight.ts` no longer matches evidence). Regression tests in PHP and Vitest.
- **Partial reports**: one full explanation in the status line ("Partial report: the observation after the update expired" …, "Missing does not mean unchanged"); the summary, Findings, Site checks and coverage only refer to it. `settle_expired` reads "Observation expired", `not_captured` "Not recorded for this report" (no longer both "Not captured").
- **Not assessed**: `not_assessed` entries carry a PHP `category` (`expired`, `not_recorded`, `unavailable`, `not_compared`); the summary row lists one line per category, site checks included ("Site checks not compared: Homepage and REST API (no check after the update)"). Historical gaps are no longer amber in the coverage line.
- **Cleanup**: priority badge only for High priority or a mixed list, no "1" count pill; "Passed before and after" / "Failed before and after" in the table as in the summary; a new redirect is amber like its finding; the newly failing row no longer repeats HTTP 500; "Inspect changes" only with changes; schedule shifts in whole units ("about 1 hour later").
- **History**: a rose "1 high priority" badge from the API's `high_priority` (site check evaluation only; a test fails if a configuration rule becomes high priority).

Checks at this commit (PHP 8.1.10 locally): PHPUnit 1612 tests OK, PHPCS clean, `npm run check` clean, Vitest 733 passed. Screenshots (before/after at 1440, 390, 768 and 320 px) and `REVIEW-ROUND2.md` are outside the repository (`E:\updatelens-review\2026-10-14-report-ux\round2\`, ZIP `updatelens-report-ux-round2-2026-10-14.zip`).

Items 1–3 that were open after the review fixes are done in section 7.

## 7. Final UX corrections (2026-10-15, on top of `767bcbd`)

The redesign direction was approved, subject to these changes:

- **"Not compared" once in the summary** (`notAssessedLines()` in `utils/glance.ts`): site checks the Site checks row already names as not compared (its value, or a check's state beside the other's) are left out of the "Not assessed" row. Configuration sources stay there. The API's `assessment.not_assessed` is unchanged, and both check rows in the Site checks section keep their own before/after results.
- **Shorter partial report status**: the visible text says the observation expired, the plugin update completed, what is missing and "Missing does not mean unchanged." The follow-up request and the 5-minute window are behind a closed `<details>`, "Why is this report incomplete?" (`ReportNotice.why`, rendered by `StatusLine` in `pages/ReportPage.tsx`). At 390 px the panel is 5 lines plus the disclosure, down from 8.
- **What a "no findings" headline is missing** (`impactGaps()` in `utils/impact.ts`, from the API's `status` and the site check status): "No findings in the completed observations; some configuration sources were not assessed." / "…; not all site checks were compared." / "…; some configuration sources were not assessed and not all site checks were compared." It replaces "some observations were not assessed", which pointed to a Not assessed row that is absent when only site checks are missing.
- **PHP 8.5 tests**: `RequestStartTest` and `AdminPageTest` call `ReflectionProperty::setAccessible()` only below PHP 8.1 (deprecated since 8.5). Production code is unchanged.
- CLAUDE.md (Admin UI rules, Potential Impact and site check wording) describes both UI changes.

Verified on WordPress 6.2.2 / PHP 7.4.33 / MariaDB 10.11 (React 18.2.0, no `react-jsx-runtime`), Chrome, with the working tree staged as the release would ship it:

- **Real "Update now" reports**:
  - no changes, both checks passed;
  - normal: a large autoloaded option and a recurring WP-Cron event, giving 1 Needs review finding and 1 informational change;
  - High priority: homepage HTTP 500 confirmed by both requests;
  - queued: two "Update now" clicks, `next_update`, not compared;
  - partial: follow-up blocked, expired after 5 minutes;
  - one check not compared ("1 of 2 compared").
- **Layout**: screenshots at 1440, 768, 390 and 320 px. No horizontal overflow (56 checks, disclosures closed and open), no page errors.
- **axe** (WCAG 2.0/2.1/2.2 A/AA and best practice): History, every overview collapsed and fully expanded, the three signal pages and Settings, at 1440 and 390 px (34 runs). No violations. The only "incomplete" items are color contrast on the decorative `→` (`aria-hidden`) and, at 390 px, backgrounds axe cannot resolve under wp-admin's mobile layout; the same elements pass at 1440 px.
- **Keyboard** at 1440 and 390 px: every overview's tab order has a visible focus indicator on every stop. 36 interaction steps passed:
  - the partial disclosure: Tab, Enter opens, Space closes and reopens;
  - "Site check details" moves focus to Site checks; a check row opens with Enter and closes with Space;
  - "Go to findings" moves focus to the Findings title; the finding opens with Enter; "Show the homepage requests" moves focus to the expanded check;
  - a signal row opens its page with focus on the title, and Back returns to the overview.
- **Checks**:
  - PHPUnit 1612 tests / 24,157 assertions OK on PHP 7.4.33, 8.1.34 and 8.5.4;
  - PHPCS clean;
  - `npm run check` clean;
  - Vitest 743 passed;
  - `languages/updatelens.pot` regenerated (819 strings, no translator comment conflicts).

Still open (not part of this task):

1. Dashboard → Updates AFTER coverage and the follow-up memory raise (section 1; deferred: no evidence of a memory failure).
2. The real Duplicate Post site's observation outcome (section 5 item 5).
3. A new release ZIP only after approval (the validated 0.3.0 ZIP predates this branch).
