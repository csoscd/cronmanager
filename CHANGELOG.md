# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased] – branch: `agentless` (continued)

### Added

- **Maintenance page** (`/maintenance`, admin only) – New "Maintenance" nav entry with three operational tools:
  - **Crontab Sync** – Re-writes all crontab entries from the database in one click. Active jobs are synced (entries added/updated); inactive jobs have lingering entries removed. Solves the post-migration crontab re-population that previously required re-saving every job manually.
  - **Stuck Executions** – Lists executions that have been in the "running" state longer than a configurable threshold (default 2 hours). Per-row actions: "Mark Finished" (sets `exit_code=-1`, `finished_at=NOW()`, appends a note to output) and "Delete" (permanently removes the record). The threshold is adjustable via an inline form without leaving the page.
  - **History Cleanup** – Bulk-deletes finished execution records older than a configurable number of days (default 90). Only records with a non-NULL `finished_at` are eligible; running executions are never deleted.
- **5 new agent endpoints** supporting the maintenance page:
  - `POST /maintenance/crontab/resync`
  - `GET  /maintenance/executions/stuck?hours=N`
  - `POST /maintenance/executions/{id}/finish`
  - `DELETE /maintenance/executions/{id}`
  - `POST /maintenance/history/cleanup`

---

## [Unreleased] – branch: `agentless`

### Added

- **Docker-only deployment mode** – New deployment option where the Cronmanager agent runs in a Docker container (`cs1711/cs_cronmanageragent:latest`) alongside the web app and MariaDB, instead of being installed directly on the host as a systemd service. No PHP installation on the host is required in this mode.
- **`docker/agent/entrypoint.sh`** – Container entrypoint script that fixes SSH key permissions, starts the cron daemon in the background, reads bind address and port from `config.json`, and starts the PHP built-in server as the foreground process.
- **`simple_debian_setup.sh`: deployment type selection** – New interactive step (Step 1b) asks whether to install in `host-agent` mode (classic, PHP CLI + systemd) or `docker-only` mode. All subsequent steps adapt accordingly.

### Changed

- **`simple_debian_setup.sh`: conditional prerequisites** – In docker-only mode, PHP 8.4 CLI and PHP extension checks on the host are skipped (PHP runs inside the container). Composer installation on the host is also skipped.
- **`simple_debian_setup.sh`: agent path patching** – In docker-only mode, hardcoded paths in agent files are patched to the fixed container path `/opt/cronmanager/agent` instead of the host `AGENT_DIR`, since the agent always runs from that path inside the container.
- **`simple_debian_setup.sh`: agent `config.json`** – In docker-only mode the database host is set to `cronmanager-db` (Docker service name) and log/wrapper paths use the container path. In host-agent mode the database host remains `127.0.0.1`.
- **`simple_debian_setup.sh`: web `config.json`** – Agent URL is set to `http://cronmanager-agent:${AGENT_PORT}` in docker-only mode (container-to-container) and `http://host.docker.internal:${AGENT_PORT}` in host-agent mode.
- **`simple_debian_setup.sh`: docker-compose.yml** – In docker-only mode a `cronmanager-agent` service is added to the compose file. The agent source directory, config, log, SSH keys, and entrypoint are mounted as volumes. The web container no longer needs `extra_hosts` in docker-only mode. In host-agent mode the compose file is unchanged.
- **`simple_debian_setup.sh`: systemd step** – Skipped in docker-only mode; informational message shown instead.
- **`simple_debian_setup.sh`: start agent step** – In docker-only mode the agent health check uses `docker exec` instead of a host curl call. systemctl commands are not shown in the final summary.

---

## [1.3.0] – branch: `misc_optimisations`

### Changed

- **Cron list: actions replaced by single "Open" button** – The four per-row admin action buttons (Edit, Copy, Delete, Run) on the cron list have been removed. All rows now show a single "Open" button (visible to all users, not admin-only) that navigates to the job detail page. The actions column is always rendered regardless of role.
- **Job detail page: full action toolbar** – Monitor, Edit, Copy, Delete, and Run Now buttons are all available on the detail page. Edit, Copy, Delete, and Run Now are shown to admin users only; Monitor is accessible to all users. The Copy button was previously missing from the detail page.
- **`cron_run_now` label changed from "Run" to "Run Now"** (EN) / "Jetzt ausführen" (DE) for clarity on the detail page.

### Fixed

- **Run Now: schedule computed in UTC instead of host system timezone** – `new \DateTime('+1 minute')` used PHP's `date.timezone` (defaults to UTC when unset), so the computed `{min} {hour}` fields could be off by the UTC offset, causing cron to fire at the wrong time or not at all. Fixed by resolving the host timezone explicitly before constructing the `DateTime`: checks the `TZ` environment variable first, then `/etc/timezone`, then falls back to `date_default_timezone_get()`.

### Added

- **Run Now: target selection modal for multi-target jobs** – When a job has more than one configured target, clicking "Run Now" on the detail page now opens a modal dialog instead of directly confirming. The modal shows a checkbox for each target (all checked by default). The user can deselect individual targets before scheduling. At least one target must remain checked. Single-target jobs continue to use the previous simple confirm dialog. The agent's `ExecuteNowEndpoint` accepts an optional `targets` array in the JSON body and schedules only the specified subset (unknown values are silently ignored; falls back to all targets when the body is empty).
- **"Last Result" filter on cron list** – New dropdown filter with options All / Ok / Failed / Not run. "Ok" shows jobs whose last finished execution exited with code 0; "Failed" shows jobs with a non-zero last exit code; "Not run" shows jobs that have never been started. Filter value is persisted via cookie (`cronmgr_crons_result`).
- **"Last Result" filter on timeline** – Same Ok / Failed / Not run filter added to the timeline page. In the timeline context "Not run" maps to executions that are still running (no `finished_at` yet). Backed by a new `?result=` query parameter on the agent's `/history` endpoint. Filter value is persisted via cookie (`cronmgr_tl_result`).

---

## [1.3.0] – branch: `simple_setup_fix`

### Fixed

- **Package installation fails with "command not found"** – `IFS=$'\n\t'` removed the space character from the field separator. `${MISSING_PKGS[*]}` therefore joined array elements with a newline instead of a space, causing `bash -c "apt-get install -y pkg1\npkg2"` to try to execute each subsequent package name as a standalone command (e.g. `php8.4-mbstring: command not found`). Fixed by removing the non-standard `IFS` assignment and changing the array-to-string join to `printf '%s ' "${MISSING_PKGS[@]}"`.
- **Prerequisites checked on local machine instead of target** – The script now asks for the target host (local or remote SSH) as the very first step. All subsequent checks and operations (package installs, Composer, file deployment, systemd, Docker, DB schema) run on the selected target via `target_exec` / `target_copy` / `target_write` / `target_script` helpers.
- **ANSI color codes shown as raw escape sequences** – Color variables were defined with single quotes (`'\033[1m'`), storing the literal 4-character sequence instead of the actual ESC byte. Changed to ANSI-C quoting (`$'\033[1m'`) so codes render correctly in both `echo -e` and `read -p` prompts.
- **Hard failures on recoverable errors** – All operational steps (package install, Composer, PHP libraries, directory creation, file deployment, path patching, config generation, systemd service, web app copy, credential files, Docker Compose start) previously called `die()` on failure, aborting the setup immediately with no recourse. Replaced with a new `warn_continue()` helper that displays the error and prompts the user whether to continue or abort. Fatal pre-flight checks (SSH connectivity, root access, repository clone integrity, user-chosen cancellation) remain as hard `die()` failures.
- **`Access denied` when applying database schema** – `docker exec mariadb` always resolves to `@'localhost'` in MariaDB privilege checking (even with `-h 127.0.0.1`, as MariaDB reverse-resolves the loopback address back to `localhost`). The Docker image creates the app user as `cronmanager@'%'`, which does not match `@'localhost'`. Fixed by running schema and migration imports as `root` (using `DB_ROOT_PASSWORD`), which always has unconditional `@'localhost'` access inside the container.
- **db.credentials and .env not shown during setup** – The generated credential files were written silently. Added a display block (identical in style to the existing docker-compose.yml display) that prints `db.credentials` and `.env` to the terminal before the `docker-compose.yml` preview, so the user can review all generated files in one place.
- **Agent started before database was available** – The host agent was started (Step 13) before the Docker stack containing MariaDB was launched (Step 14), causing the agent to fail its health check on every fresh install. Fixed by reordering to: Docker stack start → DB schema → agent start, so MariaDB is guaranteed to be running before the agent tries to connect.
- **CTRL-C left the script in an inconsistent state** – A single `trap cleanup EXIT INT TERM` silently removed the temp clone directory on interrupt without any user feedback. Added a dedicated `interrupted()` trap on `INT`/`TERM` that prints a clear "Setup interrupted" warning before cleaning up, so users know the installation did not complete.

### Added

- **README: Guided Setup section with one-command download and run example** – New `## Guided Setup (Recommended)` section added before Quick Start. Includes a single `curl … | sudo bash` command to download and execute the setup script directly from GitHub, a "review before run" alternative, and a step table describing what the script does.

---

## [1.3.0] – branch: `pathfix`

### Fixed

- **Agent config path not found after installation** – Path patching only replaced hardcoded `/opt/phpscripts/cronmanager/agent` in a small explicit list of files (`agent.php`, `bin/*`). Files under `src/` (e.g. `src/Database/Connection.php`, `src/Bootstrap.php`) and SQL migrations were skipped, so the agent still referenced the old default path at runtime. Fixed by replacing the explicit file list with a `find` across all `*.php`, `*.sh`, and `*.sql` files in the agent directory.

---

## [1.3.0] – branch: `portfix`

### Fixed

- **Wrong internal container port in docker-compose.yml** – The `cs1711/cs_php-nginx-fpm:latest-alpine` image runs nginx on port 8080, not 80. The generated port mapping was `${WEB_PORT}:80`; corrected to `${WEB_PORT}:8080`.

---

## [1.3.0] – branch: `permfix`

### Fixed

- **Web log/conf directories and config.json not accessible by container** – The `${WEB_LOG}`, `${WEB_CONF}` directories and `${WEB_CONF}/config.json` were created as root, but the PHP-FPM process inside the `cs_php-nginx-fpm` container runs as `nobody`. Added `chown nobody:nogroup` on all three so the container can write logs and read its configuration.

---

## [1.3.0] – branch: `execute_now`

### Added

- **Run Now button on cron list** – Each job row has a new "Run" button (yellow, next to Delete). Clicking it shows a confirmation dialog and then immediately schedules the job for execution at the next clock minute without leaving the current list page (filter state is preserved via `_return`).
  - The button submits a `POST /crons/{id}/execute` form. The web controller forwards the request to the host agent and redirects back to the filtered list.
  - The host agent `ExecuteNowEndpoint` builds a full-date one-time schedule (`{min} {hour} {dom} {month} *`) for the next minute and injects a `# cronmanager-once:{id}:{target}` + command line into the crontab of every configured target for the job. Using a full-date schedule means that a missed cleanup at most causes one spurious re-run per year (not once per hour).
  - After the job finishes, `cron-wrapper.sh` detects the `--once` flag (third argument) and calls the new `POST /crons/{id}/execute/cleanup` agent endpoint, which removes the marker and command lines from the crontab. The cleanup call is fire-and-forget; a failure only means the entry expires naturally in at most one year.
  - New agent endpoints: `ExecuteNowEndpoint` (`POST /crons/{id}/execute`) and `ExecuteCleanupEndpoint` (`POST /crons/{id}/execute/cleanup`).
  - New `CrontabManager` methods: `addOnceEntry()` and `removeOnceEntries()`.
  - New translation keys: `cron_run_now`, `cron_run_confirm` (EN + DE).

---

## [1.3.0] – branch: `monitor_filter`

### Added

- **Target filter on the monitor page** – When a job has more than one configured target, a target selector row is now shown on the monitor page. Users can switch between "All" (aggregated across all targets) and any individual target (e.g. `local`, `webserver01`). The selected target is preserved when switching time periods. The filter has no effect and is not shown for single-target jobs.
  - `MonitorEndpoint`: accepts optional `?target=` query parameter; validates the value against `job_targets`, adds `AND el.target = :target` to both the stats and executions queries when a target is selected; returns `targets` (list of all configured targets) and `selected_target` (active filter or `null`) in the response.
  - `CronController::monitor()`: reads `?target=` from the request, forwards it to the agent, passes `targets` and `selectedTarget` to the template.
  - `monitor.php`: renders a target filter bar (styled identically to the period selector) when `count($targets) > 1`; period-selector links preserve the active target parameter.
  - New translation keys: `monitor_target`, `monitor_all_targets` (EN + DE).

---

## [1.3.0] – branch: `bugfix_jobcommand`

### Fixed

- **Job stuck in "running" state when command contains `exit` or `kill $$`** – `cron-wrapper.sh` executed the job command via `eval` in the current shell process. Any command that called `exit` (e.g. `echo "Test" && exit 1`) terminated the wrapper itself before the `/execution/finish` notification could be sent, leaving the execution record permanently in the `running` state. Additionally, `$$` inside a bash subshell resolves to the parent shell's PID, so a command containing `kill $$` would also kill the wrapper. Fixed by replacing `eval` with `bash -c "${COMMAND}"`: the command runs in a dedicated child process where `$$` is that child's own PID and `exit` only terminates the child; the wrapper captures the exit code and continues normally to report the finished execution.
- **Agent hanging on SMTP timeout when `notify_on_failure` is enabled** – `MailNotifier` did not set a timeout on the PHPMailer SMTP connection, and mail was sent synchronously inside the HTTP request handler. PHPMailer's default socket timeout is 300 seconds; an unreachable or slow SMTP server would block the single-threaded PHP CLI agent for up to 5 minutes, making it unresponsive to all other requests. Fixed with two layers of protection: (1) `ExecutionFinishEndpoint` now writes the notification payload to a temporary file and spawns `agent/bin/send-notification.php` as a detached background process (`timeout 30 php send-notification.php &`), so the HTTP response is returned immediately and SMTP can never block the agent; (2) `MailNotifier` still applies a configurable `mail.smtp_timeout` (default: 15 s) via `$mail->Timeout` as a secondary safeguard within the background process. Falls back to synchronous sending when `exec()` is unavailable. The `/execution/finish` curl call in `cron-wrapper.sh` was also increased from 10 to 60 seconds for environments where synchronous fallback is active.

---

## [1.3.0] – branch: `monitor`

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

## [1.3.0] – branch: `consistency`

### Added

- **Crontab consistency check in job list** – Each active job now carries a `crontab_ok` flag returned by the agent. The list endpoint reads each unique Linux user's crontab once and cross-checks all active jobs against it. Jobs that are active in the database but are missing one or more crontab entries show a red "No crontab entry" warning badge (with a tooltip) in the status column. The check is per-target: a multi-target job is only considered consistent if ALL expected targets have a crontab entry — removing a single target's entry triggers the warning. Inactive jobs are always considered consistent. The legacy single-target crontab format is handled transparently.

---

## [1.3.0] – branch: `delete_consistency`

### Fixed

- **Orphaned crontab entries on job delete** – `CronDeleteEndpoint` called the non-existent method `removeEntry()` on `CrontabManager`; PHP threw a fatal `Error` which was silently caught, and the job was deleted from the database while its crontab entry remained. Fixed by calling the correct method `removeAllEntries()`. Additionally, the catch block now returns HTTP 500 and aborts the operation instead of continuing — the DB row is only deleted after the crontab entry has been successfully removed. The `active` flag guard was also removed so that stale entries left by previous bugs are cleaned up even for inactive jobs.

---

## [1.3.0] – branch: `notify_fix`

### Fixed

- **`mb_strlen` / `mb_substr` namespace error in `MailNotifier`** – When a job failed with `notify_on_failure` enabled and mail was active, the agent logged `Call to undefined function Cronmanager\Agent\Notification\mb_strlen()`. PHP resolves unqualified function calls in the current namespace first; since no such function exists there, the call failed. Fixed by prefixing both calls with `\` (`\mb_strlen`, `\mb_substr`) to force resolution in the global namespace.

---

## [1.3.0] – branch: `copy`

### Added

- **Copy job** – Each job row in the cron list now has a "Copy" link (green, next to Edit). Clicking it opens the Add Job form with all fields (user, schedule, command, description, tags, targets, active, notify) pre-filled from the source job. A blue notice banner explains that the form is pre-filled and saving creates a new independent job. The `_return` URL is preserved so the user lands back on the same filtered list after saving.

---

## [1.3.0] – branch: `filter_improve`

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

## [1.3.0] – branch: `small_fix`

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
