# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased] – branch: `debian_simple_setup`

### Added

- **Guided Debian setup script** (`simple_debian_setup.sh`) – interactive, single-command installation assistant for Debian 12+ / Ubuntu 22.04+ hosts. Covers the full installation in one session:
  - Prerequisite check (PHP 8.4, required extensions, Docker, Composer, git, openssl, rsync, python3, jq) with optional `apt` install of missing packages.
  - Repository clone to a temporary directory.
  - Composer installation check with optional global install via the official installer.
  - PHP library check against the shared vendor directory (`/opt/phplib/vendor`); missing packages are added to `composer.json` and installed automatically.
  - Full configuration interview (paths, database credentials, agent and web settings) with defaults; HMAC secret generated via `openssl rand -hex 32`.
  - Host agent deployment with path patching, `config/config.json` generation, systemd service installation/enable/start, and health check.
  - Web application deployment with Tailwind CSS and Chart.js asset download, `conf/config.json` generation.
  - Customised `docker-compose.yml` generated from collected values, displayed to the user, with optional `docker compose up -d`.
  - Database schema and migrations applied via `docker exec` after MariaDB health confirmation.
  - Optional OIDC/SSO configuration (provider URL, client credentials, redirect URI, SSL/CA cert path).
  - Optional email failure notification configuration (SMTP host, port, credentials, encryption).
  - Summary with all paths, management commands, web UI URL and the generated HMAC secret.
- README: added *Guided Setup (Recommended)* section with step-by-step table, directly before the existing Quick Start section.

---

## [Unreleased] – branch: `monitor_filter`

### Added

- **Target filter on the monitor page** – When a job has more than one configured target, a target selector row is now shown on the monitor page. Users can switch between "All" (aggregated across all targets) and any individual target (e.g. `local`, `webserver01`). The selected target is preserved when switching time periods. The filter has no effect and is not shown for single-target jobs.
  - `MonitorEndpoint`: accepts optional `?target=` query parameter; validates the value against `job_targets`, adds `AND el.target = :target` to both the stats and executions queries when a target is selected; returns `targets` (list of all configured targets) and `selected_target` (active filter or `null`) in the response.
  - `CronController::monitor()`: reads `?target=` from the request, forwards it to the agent, passes `targets` and `selectedTarget` to the template.
  - `monitor.php`: renders a target filter bar (styled identically to the period selector) when `count($targets) > 1`; period-selector links preserve the active target parameter.
  - New translation keys: `monitor_target`, `monitor_all_targets` (EN + DE).

---

## [Unreleased] – branch: `bugfix_jobcommand`

### Fixed

- **Job stuck in "running" state when command contains `exit` or `kill $$`** – `cron-wrapper.sh` executed the job command via `eval` in the current shell process. Any command that called `exit` (e.g. `echo "Test" && exit 1`) terminated the wrapper itself before the `/execution/finish` notification could be sent, leaving the execution record permanently in the `running` state. Additionally, `$$` inside a bash subshell resolves to the parent shell's PID, so a command containing `kill $$` would also kill the wrapper. Fixed by replacing `eval` with `bash -c "${COMMAND}"`: the command runs in a dedicated child process where `$$` is that child's own PID and `exit` only terminates the child; the wrapper captures the exit code and continues normally to report the finished execution.
- **Agent hanging on SMTP timeout when `notify_on_failure` is enabled** – `MailNotifier` did not set a timeout on the PHPMailer SMTP connection, and mail was sent synchronously inside the HTTP request handler. PHPMailer's default socket timeout is 300 seconds; an unreachable or slow SMTP server would block the single-threaded PHP CLI agent for up to 5 minutes, making it unresponsive to all other requests. Fixed with two layers of protection: (1) `ExecutionFinishEndpoint` now writes the notification payload to a temporary file and spawns `agent/bin/send-notification.php` as a detached background process (`timeout 30 php send-notification.php &`), so the HTTP response is returned immediately and SMTP can never block the agent; (2) `MailNotifier` still applies a configurable `mail.smtp_timeout` (default: 15 s) via `$mail->Timeout` as a secondary safeguard within the background process. Falls back to synchronous sending when `exec()` is unavailable. The `/execution/finish` curl call in `cron-wrapper.sh` was also increased from 10 to 60 seconds for environments where synchronous fallback is active.

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
