# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased] – branch: `monitor`

### Added

- **Per-job monitor page** – New route `GET /crons/{id}/monitor` (web + agent) that shows interactive statistics for a single cron job over a selectable time window. A "Monitor" button with a bar-chart icon is added to the job detail page, accessible to all users (not admin-only).
  - **Period selector**: 1h, 6h, 12h, 24h, 7d, 30d (default), 3m, 6m, 1y — tab-style buttons that reload the page.
  - **KPI cards**: Success Rate (colour-coded ≥ 95 % green / ≥ 80 % yellow / < 80 % red), Average Duration (with min/max), Execution Count, Alert Count.
  - **Execution duration line chart** (Chart.js 4): individual execution times plotted chronologically; points coloured green (success) / red (failure); dashed orange average line.
  - **Activity stacked bar chart** (Chart.js 4): success/failure counts per time bucket; bucket granularity adapts to the selected period (5 min → 1 y).
  - **Recent executions table**: last 20 finished executions with timestamp, duration, target, exit code; failed rows highlighted in red.
  - **Dark mode support**: chart colours adapt to the current Tailwind dark mode state on page load.
- **`MonitorEndpoint`** (agent) – new `GET /crons/{id}/monitor?period=…` endpoint; returns job metadata, aggregated stats (success rate, avg/min/max duration, alert count), duration series, bucketed bar data and recent executions. Alert count is derived from `exit_code != 0 AND notify_on_failure = 1` (no `alert_sent` column in schema).
- **Chart.js self-hosted** – `deploy.sh` downloads Chart.js 4 UMD build to `assets/js/chart.min.js` during deployment (CSP blocks CDN scripts). Skipped if the file already exists.
- **i18n** – New translation keys `monitor_*` added to both `en.php` and `de.php`.

---

## [Unreleased] – branch: `consistency`

### Added

- **Crontab consistency check in job list** – Each active job now carries a `crontab_ok` flag returned by the agent. The list endpoint reads each unique Linux user's crontab once and cross-checks all active jobs against it. Jobs that are active in the database but are missing one or more crontab entries show a red "No crontab entry" warning badge (with a tooltip) in the status column. The check is per-target: a multi-target job is only considered consistent if ALL expected targets have a crontab entry — removing a single target's entry triggers the warning. Inactive jobs are always considered consistent. The legacy single-target crontab format is handled transparently.

---

## [Unreleased] – branch: `delete_consistency`

### Fixed

- **Orphaned crontab entries on job delete** – `CronDeleteEndpoint` called the non-existent method `removeEntry()` on `CrontabManager`; PHP threw a fatal `Error` which was silently caught, and the job was deleted from the database while its crontab entry remained. Fixed by calling the correct method `removeAllEntries()`. Additionally, the catch block now returns HTTP 500 and aborts the operation instead of continuing — the DB row is only deleted after the crontab entry has been successfully removed. The `active` flag guard was also removed so that stale entries left by previous bugs are cleaned up even for inactive jobs.

---

## [Unreleased] – branch: `notify_fix`

### Fixed

- **`mb_strlen` / `mb_substr` namespace error in `MailNotifier`** – When a job failed with `notify_on_failure` enabled and mail was active, the agent logged `Call to undefined function Cronmanager\Agent\Notification\mb_strlen()`. PHP resolves unqualified function calls in the current namespace first; since no such function exists there, the call failed. Fixed by prefixing both calls with `\` (`\mb_strlen`, `\mb_substr`) to force resolution in the global namespace.

---

## [Unreleased] – branch: `copy`

### Added

- **Copy job** – Each job row in the cron list now has a "Copy" link (green, next to Edit). Clicking it opens the Add Job form with all fields (user, schedule, command, description, tags, targets, active, notify) pre-filled from the source job. A blue notice banner explains that the form is pre-filled and saving creates a new independent job. The `_return` URL is preserved so the user lands back on the same filtered list after saving.

---

## [Unreleased] – branch: `filter_improve`

### Added

- **Timeline: target filter** – New "Execution Target" dropdown on the timeline filter bar. Filters execution history by the target the job ran on (e.g. `local` or SSH host). Only shown when more than one unique target exists. Backed by `el.target = :target` in `HistoryEndpoint`.
- **Timeline: configurable page size** – Replaced the hidden `limit` input with a visible page size selector offering 10 / 25 / 50 / 100 / 500 entries per page. Selection is persisted via cookie.
- **Timeline: target column in results table** – Each history row now shows the execution target as a badge.
- **Timeline: reset filters link** – A "×" link appears when any filter is active; it navigates to `?_reset=1` which clears all stored filter cookies.
- **Persistent filter cookies (crons, timeline)** – All filter selections (tag, user, target, search, page size) are stored in 30-day cookies (`cronmgr_crons_*`, `cronmgr_tl_*`). On page load the cookie values are used as defaults when no GET parameters are present, so filter state survives navigation. Browsers that block cookies fall back to the previous stateless behaviour.
- **Persistent filter cookies (swimlane)** – All swimlane filter controls (from/to hour, day of week, tag, target) are persisted in 30-day cookies (`cronmgr_sl_*`) via JavaScript. A "×" reset button clears all swimlane cookies and resets the controls to their defaults.
- **`BaseController::filterParam()`** – New protected helper that resolves a filter value from GET → cookie fallback, writes the result back to the cookie, and handles `?_reset=1` to expire cookies.

### Changed

- **Crons list: reset filters link** – The "×" clear-filters link now navigates to `/crons?_reset=1` instead of bare `/crons`, so stored filter cookies are properly cleared.

---

## [Unreleased] – branch: `small_fix`

### Fixed

- **Dashboard date language** – Date on the dashboard was always rendered in English regardless of the selected language. Replaced the unavailable `IntlDateFormatter` extension with manual translation using `day_*` and `month_N` keys from the language files.
- **Timeline pagination label** – "Showing X–Y of Z executions" was hardcoded English. Replaced with the translatable `timeline_showing` key supporting `{from}`, `{to}`, and `{total}` placeholders.
- **Swimlane UI strings hardcoded in English** – Info bar and tooltip strings (time prefix, day names, job count labels, next-run formatting, etc.) were hardcoded JavaScript strings. Fixed by injecting a translated `I18N` object from PHP into the JavaScript IIFE.
- **Tag filter showing unused tags** – Tag filter dropdowns on the Timeline, Swimlane, and Export pages showed tags that had no jobs assigned. Fixed by filtering to tags with `job_count > 0` in `TimelineController`, `SwimlaneController`, and `ExportController`.
- **Role badge showing "Viewer" for admin users** – `layout.php` resolved `$user` conditionally via `isset($user)`, which was polluted by `foreach ($users as $user)` loops in sub-templates, leaving `$user` as a plain string with no `role` key. Fixed by always calling `SessionManager::getUser()` unconditionally in `layout.php`.
- **Filter lost after editing a cron job** – After saving an edit the user was redirected to the unfiltered cron list. Fixed with a `_return` URL pattern: the current filtered list URL is URL-encoded into the edit link, passed through the form as a hidden field, and used as the redirect target after a successful save.
- **Filter lost after adding a new cron job** – Same issue as above, applied to the Add Job flow.
- **Import success message not displayed** – After a successful import `importStore()` redirected to `/crons` (the list page), but the flash message is only rendered on the import template. Fixed by redirecting back to `/crons/import?user=…` so the message is shown and consumed correctly.
- **Import rejects `@`-style and named-field cron schedules (HTTP 422)** – The agent's `isValidCronSchedule()` used a hand-rolled regex that only accepted five or six purely numeric/special-character fields. This rejected valid expressions such as `0 5 * * sat`, `0 5 * jan *`, `@monthly`, `@daily`, etc. Replaced the regex with `dragonmantank/cron-expression` for full semantic validation. `@reboot` is accepted as a special case (it is a valid vixie-cron directive but is not a time expression and is therefore not supported by the library). Applied to both `CronCreateEndpoint` and `CronUpdateEndpoint`.

---

## [speedup] – branch: `speedup` – merged via PR #2

### Added

- **APCu caching for Swimlane page** – `SwimlaneController` now caches the results of `getMultipleRunDates()` and `translateCron()` per expression using APCu (`cronmgr_sched_<md5>` / `cronmgr_trans_<md5>`, TTL 86400 s). Falls back gracefully when APCu is unavailable.
- **Timing instrumentation** – `hrtime(true)` checkpoints measure agent call, compute loop, and JSON encode phases. Timings are logged at DEBUG level and rendered as an HTML comment when `?debug=1` is passed.
- **Swimlane tag filter** – Tags with no assigned jobs are now excluded from the Swimlane tag filter dropdown.

---

## [jobview] – branch: `jobview` – merged via PR #1

### Added

- **Swimlane view** – New visual timeline page showing all active cron jobs across a 24-hour swimlane grid with per-job schedule visualisation, tag filtering, and tooltips.

---

## [Initial release] – branch: `main` (initial commit)

### Added

- Initial project implementation including host agent, web UI, authentication, cron job CRUD, timeline, export/import, tag management, and i18n (de/en).
