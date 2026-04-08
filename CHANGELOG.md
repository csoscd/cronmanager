# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [2.5.3] – branch: `version-info`

### Added

- **Version footer** – A slim footer strip on every page shows two pieces of information for each component (web and agent): the **app version** (from a `VERSION` file deployed alongside the source, e.g. `2.5.3`) and the **container version** (from the `APP_VERSION` env var baked into the Docker image at build time, `unknown` when running outside Docker). Example: `Web: 2.5.3, Container: 2.5.3 | Agent: 2.5.3, Container: 2.5.3`.
- **`VERSION` files** – `web/VERSION` and `agent/VERSION` are added to the repository and contain the current release version. These are deployed by `deploy.sh` and read at runtime so direct (non-Docker) deployments also display the correct app version.
- **`APP_VERSION` build argument** – Both `docker/web/Dockerfile` and `docker/agent/Dockerfile` accept an `APP_VERSION` build argument (defaulting to `unknown`) and expose it as the `APP_VERSION` environment variable for the container version label.
- **CI: build-arg injection** – `.github/workflows/docker-release.yml` passes `APP_VERSION=${{ steps.meta.outputs.version }}` to the Docker buildx step so every released image carries the correct semver container version.
- **Agent `/health` version fields** – The `GET /health` response now includes `"version"` (from `VERSION` file) and `"container_version"` (from `APP_VERSION` env). The web UI fetches these on the first page load and caches them in the session for 5 minutes.

---

## [2.5.2] – branch: `error_navigation`

### Added

- **Job search on Timeline** – A free-text search field (first filter in the bar) lets users filter the execution history by job description or command. The query is forwarded to the agent as `?search=<term>`, where it matches against `j.description LIKE %…%` or `j.command LIKE %…%`.
- **Deep-link from Dashboard failures to Timeline** – Clicking a failed job in the "Recent Failures" table on the Dashboard now navigates directly to the Timeline pre-filtered by `job_id`, `target`, and `status=failed`. This makes the specific failed execution immediately visible regardless of how many successful runs have occurred since.
- **Target column on Dashboard failures table** – The "Recent Failures" table now shows which execution target the failure occurred on, matching the information that is carried in the Timeline deep-link URL.
- **`search` parameter on agent `GET /history`** – The history endpoint now accepts `?search=<term>` and applies a `LIKE` condition on `j.description` and `j.command`.
- **`job_id` filter in TimelineController** – The timeline controller reads `?job_id=` from the query string (integer only, not cookie-persisted) and forwards it to the agent. Pagination preserves the `job_id` via a hidden form field.

### Changed

- **Filter bar order standardised** – Both the Cron Jobs list and the Timeline now use the same left-to-right order: **Search → Tag → User → Target → [page-specific filters] → Per page**. On the Timeline the page-specific filter is Status; on the Cron Jobs list it is Last Result.
- **"Last Result" filter removed from Timeline** – The `result` filter on the Timeline page was redundant with the `status` filter (both produced identical SQL). It has been removed from the template, controller, and agent endpoint to avoid user confusion.

---

## [2.5.1] – branch: `39-testfunction-for-notification-e-mail-and-telegram`

### Added

- **Notification test** – A new "Notification Test" section on the Maintenance page lets admins send a test message through the configured E-Mail or Telegram channel with a single button click. The result (success, disabled, or send-failed with the exact error detail) is shown inline via AJAX — no page reload required.
- **Agent endpoint `POST /maintenance/notification/test`** – Accepts `channel=mail|telegram`, checks whether the channel is enabled in the agent config, and dispatches a synthetic test message through the existing `MailNotifier` / `TelegramNotifier` infrastructure. Returns `{ "success": true }`, `{ "success": false, "reason": "disabled" }`, or `{ "success": false, "reason": "send_failed", "message": "<actual error>" }`.
- **`MailNotifier::sendTest()` / `TelegramNotifier::sendTest()`** – New dedicated test methods on both notifiers that return a structured `['success' => bool, 'message' => string]` result instead of swallowing errors, so failure details surface all the way to the UI.

---

## [2.5.0] – branch: `maintenance_window`

### Added

- **Maintenance windows** – Admins can now define scheduled maintenance windows per target (local host or SSH alias). During an active window the agent evaluates each incoming execution-start request against the window schedule and either skips the job or executes it silently depending on a new per-job flag.
- **New `maintenance_windows` table** – Stores windows with a 5-field cron expression (window start), a duration in minutes, an optional description, and an `active` flag. Multiple windows can be defined for the same target (e.g. daily at 02:00 for 60 min and daily at 14:00 for 30 min).
- **Per-job `run_in_maintenance` flag** – When `run_in_maintenance = 1` the job executes normally during a maintenance window but failure notifications are suppressed. When `run_in_maintenance = 0` (default) the execution is skipped: a record is inserted with `exit_code = -4` and the agent returns HTTP 423 so `cron-wrapper.sh` exits cleanly without running the command.
- **Sentinel exit code −4** – `exit_code = -4` in `execution_log` means the execution was skipped due to a maintenance window. The execution detail and history views show a grey "Skipped (maintenance)" badge for these rows.
- **`during_maintenance` flag on `execution_log`** – When a job with `run_in_maintenance = 1` runs inside a maintenance window this flag is set to `1`. The history view annotates these rows with a blue "Maintenance" badge and `ExecutionFinishEndpoint` skips all failure/limit-exceeded notifications for them.
- **Maintenance-window conflict detection** – The cron-job create/edit form queries the agent for upcoming run-time conflicts with active maintenance windows for the selected targets. A yellow warning banner is shown when conflicts are detected and `run_in_maintenance` is not enabled.
- **Cron-list warning badge** – Jobs that are active, do not have `run_in_maintenance` set, and target a host that has at least one active maintenance window defined, receive a yellow ⚠ badge in the job list. Hovering the badge explains that the job may be skipped during maintenance windows.
- **Targets page** – A new admin-only "Targets" page (`/targets`) lists all known targets grouped by name and shows their maintenance windows with add/edit/delete actions.
- **DB migration 006** – `agent/sql/migrations/006_maintenance_windows.sql` adds the new table and columns to existing installations.
- **Agent REST endpoints** – Six new endpoints for maintenance-window management:
  - `GET  /maintenance/windows` – list all windows (optional `?target=` filter)
  - `GET  /maintenance/windows/{id}` – get a single window
  - `POST /maintenance/windows` – create a window
  - `PUT  /maintenance/windows/{id}` – update a window
  - `DELETE /maintenance/windows/{id}` – delete a window
  - `GET  /maintenance/windows/conflict?schedule=…&target=…` – check upcoming conflicts
- **`cron-wrapper.sh` HTTP 423 handling** – The wrapper now exits cleanly on HTTP 423 (maintenance skip), mirroring the existing HTTP 409 (singleton skip) behaviour.

---

## [2.4.1] – branch: `job_exec_fix`

### Fixed

- **Remote job execution silent failure** – SSH remote jobs (execution mode `remote`) completed in ~1 second with exit code 0 and no output. Root cause: `setsid` in `cron-wrapper.sh` was used before `sh -s` to create a new process group on the remote host. `setsid` (util-linux ≥ 2.41) detects that the calling process is already a process-group leader (SSH always guarantees PGID == PID == SID for the remote command), forks a child, and the parent exits immediately. SSH interprets the parent exit as command completion, closes stdin before the child's `sh -s` can read the job command, and the job runs with empty stdin — executing nothing, exiting 0, with no output. Fix: removed `setsid` from the remote SSH invocation entirely. SSH already provides the PGID == PID guarantee required by `kill -TERM -$PID`, so `setsid` was redundant and actively harmful there.
- **Limit-exceeded alert email showed misleading exit code and timestamp** – When `check-limits.php` sends a notification for a job that is still running, the email displayed `Exit Code: -3` (an internal sentinel value) and labelled the timestamp as "Finished", both implying the job had already exited. Fixed in `MailNotifier`: exit code `-3` now renders as "N/A – job still running" and the timestamp row is labelled "Notified At" instead of "Finished".

---

## [2.4.0] – branch: `36-feature-request-send-information-via-telegram`

### Added

- **Telegram notifications** – The agent can now send failure and limit-exceeded alerts via Telegram in addition to (or instead of) e-mail. A new `TelegramNotifier` class (`agent/src/Notification/TelegramNotifier.php`) sends HTML-formatted messages to a configured bot/chat using the Telegram Bot API via Guzzle HTTP. The notifier respects the same sentinel exit codes as `MailNotifier`: exit code `-3` (limit exceeded, job still running) shows "N/A – job still running" as the exit code and "Notified At" as the timestamp label; exit code `-2` indicates auto-kill. Job output is pre-truncated to 2 000 characters and the entire message is capped at Telegram's 4 096-character hard limit.
- **Telegram configuration keys** – Four new keys are recognised under the `telegram.*` namespace in `config.json`:
  - `telegram.enabled` – master on/off switch (default: `false`)
  - `telegram.bot_token` – Telegram Bot API token obtained from @BotFather
  - `telegram.chat_id` – target chat, channel, or group ID
  - `telegram.timeout` – HTTP request timeout in seconds (default: `15`)
- **Dual-channel dispatch** – `send-notification.php`, `check-limits.php`, and `ExecutionFinishEndpoint` all call both `MailNotifier` and `TelegramNotifier` so each enabled channel fires independently. A notification is considered dispatched if at least one channel succeeds.
- **Development Docker image builds** – A new GitHub Actions workflow (`.github/workflows/docker-dev.yml`) automatically builds and pushes a `:dev` tag to Docker Hub on every push to any non-main branch. The `:dev` tag is always overwritten and is intended for testing unreleased features. The existing `docker-release.yml` continues to produce `:latest` and versioned tags (`2.4.0`, etc.) from published GitHub releases. README updated with an available-image-tags table and a warning against using `:dev` in production.

---

## [2.3.0] – branch: `gen_improve`

### Added

- **Singleton job mode** – Each cron job can be flagged as "singleton". When enabled, the agent checks whether a previous instance of the same job is still running (i.e. has no `finished_at` timestamp) before inserting a new execution log row. If a running instance is found, the agent returns HTTP `409 Conflict` and the wrapper script exits silently without recording a failure. The flag is configurable on the create/edit form and visible on the job detail page. Database migration `005_singleton.sql` adds the `singleton` column to `cronjobs`.

- **Kill running execution** – Admins can now terminate a running cron job mid-flight via the job detail page. A "Kill Job" button appears next to every in-progress execution in the history table. Clicking it sends `POST /execution/{id}/kill` to the web layer, which proxies to the new agent endpoint. The agent kills the process (local: `SIGTERM` to process group via `posix_kill`; remote SSH: reads a PID file written by the wrapper, sends `kill -TERM -$PID` over SSH). The execution is marked finished with exit code **-2** ("killed by operator") and an annotation is appended to the output.
- **Execution limit per job** – Each job now has an optional `execution_limit_seconds` field. When a job runs longer than the configured limit:
  - A **notification** is dispatched (if `notify_on_failure` / "Notify on failure / limit exceeded" is enabled) with exit code **-3** as context.
  - If **Auto-kill on limit exceeded** is also enabled, the process is terminated automatically and the execution is finished with exit code **-2**.
  - A `notified_limit_exceeded` flag prevents duplicate notifications when both the periodic checker and the finish endpoint encounter the same exceeded execution.
- **`check-limits.php` checker script** – A new PHP CLI script (`agent/bin/check-limits.php`) runs every minute via a system cron entry (`/etc/cron.d/cronmanager-limits`) installed by `simple_debian_setup.sh`. It queries all running executions that have exceeded their configured limit and dispatches notifications / auto-kills as appropriate.
- **PID tracking in execution_log** – The wrapper script now records the local process PID (or a remote PID file path for SSH targets) via the new agent endpoint `POST /execution/{id}/pid` immediately after launching the job. This enables the kill endpoint to locate the process reliably.
- **New agent endpoints**:
  - `POST /execution/{id}/pid` – stores the process PID or PID file for a running execution.
  - `POST /execution/{id}/kill` – terminates a running execution and marks it finished.
- **Database migration `004_kill_and_limits.sql`** – Adds five new columns: `execution_log.pid`, `execution_log.pid_file`, `execution_log.notified_limit_exceeded`, `cronjobs.execution_limit_seconds`, `cronjobs.auto_kill_on_limit`.
- **Exit code badges** – The cron list and detail pages now display distinct badges for exit code `-2` (orange "Killed") and exit code `-3` (yellow "Limit exceeded"). The list also shows a small blue time indicator for any job that has an execution limit configured.

### Fixed

- **Dashboard and swimlane: empty job name for jobs without description** – Both views used the `??` null-coalescing operator to fall back to a default label, which does not trigger on empty strings (`""`). Jobs created/edited with a blank description field store `""` in the database rather than `NULL`, causing both views to display a blank name. Fixed with an explicit `!== ''` check using `"Job #N"` as the fallback, consistent with the list and timeline views. The swimlane previously fell back to the raw command string (which can be very long); it now uses `"Job #N"` for consistency.
- **`cron-wrapper.sh` – SSH remote auto-kill never worked (process group not created)** – The same process-group issue that affected local jobs also affected SSH jobs: the remote `sh -c '...; exec sh -s'` started by sshd was not guaranteed to be its own process-group leader on all configurations, so `kill -TERM -$PID` on the remote sent the signal to a non-existent or wrong process group and silently failed. Fixed by prefixing the remote command with `setsid`, mirroring the local fix.
- **`cron-wrapper.sh` – local auto-kill never worked (process group not created)** – `bash script.sh &` inherits the wrapper's process group, so `kill -TERM -$PID` (targeting the process group by PID) always failed silently because `$PID ≠ PGID`. The job ran to natural completion regardless of the configured execution limit. Fixed by launching the job subprocess via `setsid bash script.sh &`, which creates a new session making bash its own process-group leader (`PGID == PID == $!`). `kill -TERM -$PID` now correctly terminates bash and all its children (e.g. a spawned `sleep`).
- **`ExecutionFinishEndpoint` overwrote auto-killed execution records** – When auto-kill succeeded, `check-limits.php` set `finished_at` and `exit_code = -2` on the execution row. Immediately after, the wrapper's `wait` returned (exit 143 from SIGTERM) and called `POST /execution/finish`, which executed an unconditional `UPDATE … WHERE id = :id` — overwriting the stored `-2` and the correct `finished_at` with the wrapper's values and showing exit code `0` (or `143`) in the UI. Fixed by adding `AND finished_at IS NULL` to the `WHERE` clause: the update is a no-op when the row was already closed by the auto-killer, and the finish endpoint returns early without dispatching a duplicate notification.
- **`check-limits.php` – parse error prevented execution-limit checker from ever running** – PHP named arguments do not accept a `$` prefix on the parameter name in the call site. The calls to `killRemote()` and `killLocal()` used invalid syntax (`$sshHost:`, `$pidFile:`, `$logger:`, etc.), causing a fatal parse error on every invocation. Because the system cron entry redirected stderr to `/dev/null`, the error was completely silent — no log output, no kills, no notifications. Fixed by removing the erroneous `$` prefix (`sshHost:`, `pidFile:`, etc.).
- **Alert emails for auto-killed and limit-exceeded jobs lacked context** – All alert emails used the same generic subject ("FAILED (exit -3)") and intro text regardless of whether the job failed normally, exceeded its time limit, or was automatically terminated. Added distinct subject lines, headings, intro text, and accent colours for exit code `-2` (auto-killed, orange) and `-3` (limit exceeded while running, purple) to make the cause immediately clear.
- **`cron-wrapper.sh` – SSH: commands with single quotes or compound operators broken** – The kill feature wrapped the remote command as `sh -c 'echo $$ > ...; exec ${COMMAND}'`, embedding the command string inside a single-quoted shell argument. Any command containing single quotes (e.g. `curl -H 'Authorization: token ...'`) terminated the `sh -c '...'` argument prematurely on the remote side, causing garbled argument parsing and errors such as "curl: (2) no URL specified". Additionally, `exec ${COMMAND}` does not invoke a shell, so `&&`, `||`, and pipe operators in the command were passed as literal arguments instead of being interpreted as shell operators — only the first component of a compound command ever ran. Fixed by passing the command via stdin (here-string) to `sh -s` on the remote host. The command is treated as script data rather than embedded shell syntax, so no quoting or escaping is required regardless of what the command contains.
- **`cron-wrapper.sh` – local: `set -uo pipefail` inherited by job subprocess** – `bash -c "${COMMAND}"` inherits the wrapper's `SHELLOPTS` (exported by bash automatically), including `nounset` and `pipefail`. In edge cases this caused job commands to abort unexpectedly. Fixed by writing the command to a temporary script file and invoking `bash <script_file>`, which starts bash with clean default options.
- **Export page – dark mode: poor contrast on selected format option** – When a radio button was selected in dark mode, the label's `has-[:checked]:bg-blue-50` (light blue) background could take precedence over the `dark:has-[:checked]:bg-blue-900/20` override, resulting in near-white text on a near-white background. Fixed by replacing the semi-transparent overlay with a solid `dark:has-[:checked]:bg-gray-700`, matching the hover colour and ensuring readable contrast at all times.
- **Maintenance page – poor contrast on number inputs** – The "Stuck executions" hours input and "History cleanup" days input used the `cm-input` CSS variable class, which renders with low contrast in dark mode. Applied the same explicit Tailwind utility classes used by the cron list search field (`border`, `bg-white dark:bg-gray-700`, `text-gray-900 dark:text-white`, `focus:ring-blue-500`).

### Changed

- **`cron_notify_on_failure` label** updated to "Notify on failure / limit exceeded" in both English and German to reflect the expanded scope of the notification flag.
- **`cron-wrapper.sh`** updated to background the job command (local and SSH), capture the PID, report it to the agent, and then wait for completion — enabling reliable kill support without changing the wrapper's overall sequential semantics.
- **`simple_debian_setup.sh`** now installs `/etc/cron.d/cronmanager-limits` (Step 16) to activate the execution limit checker after the agent service is started.
- **`docker/agent/entrypoint.sh`** now writes `/etc/cron.d/cronmanager-limits` dynamically before starting the in-container cron daemon, so the checker is active in the Docker image setup without any additional configuration.
- **Automatic crontab resync on container start** – A new `bin/resync-crontab.php` CLI script rebuilds all crontab entries from the database. The Docker agent entrypoint calls it as step 3 on every startup (before the cron daemon launches), so crontab entries are automatically restored after a container recreation, Portainer stack redeploy, or image update. No manual resync is ever needed.
- **Schema migration tracking** – A new `schema_migrations` table records each applied migration file with a timestamp. The Docker entrypoint and `simple_debian_setup.sh` both consult this table before applying any migration file, making re-runs of the installer safe and idempotent. Fresh installs seed the table with all bundled migration filenames so that changes already present in `schema.sql` are never re-executed.

---

## [2.2.0] – branch: `dockerfull`

### Fixed

- **Web container: Alpine shell compatibility** – `docker/web/entrypoint.sh` used `#!/bin/bash` and bash-specific syntax (`[[ ]]`). The runtime base image (`cs_php-nginx-fpm:latest-alpine`) is Alpine Linux which has no bash. Changed shebang to `#!/bin/sh`, replaced all `[[ ]]` test constructs with POSIX `[ ]`, and removed bash-specific constructs throughout.
- **Web container: supervisord permission errors (EACCES)** – The previous entrypoint used `su-exec nobody supervisord`, causing supervisord to start as `nobody`. supervisord requires root to create its dispatcher sockets and manage child processes; it emitted `EACCES` and failed to start nginx and PHP-FPM. Removed `su-exec`; supervisord now starts as root and nginx/PHP-FPM drop privileges internally via their own configuration (see below).
- **Web container: PHP-FPM pool user not defined** – The base image expected the PHP-FPM master to already be running as `nobody`; without an explicit `user=` line in `www.conf` the pool failed with `[pool www] user has not been defined`. The `docker/web/Dockerfile` now appends `user=nobody`, `group=nobody`, `listen.owner=nobody`, `listen.group=nobody`, and `listen.mode=0660` to `www.conf` at build time.
- **Web container: nginx socket permission denied** – When supervisord ran as root the PHP-FPM socket was owned by root; nginx workers ran as `nginx` (a different user) and received `Permission denied` on the socket. The `docker/web/Dockerfile` now patches `nginx.conf` to set `user nobody` so that nginx workers match the PHP-FPM socket owner.
- **Web container: missing Tailwind CSS and Chart.js assets** – The web image had no `assets/js/` directory; Tailwind CSS and Chart.js were neither committed to the repository nor downloaded during the image build, causing broken page layout and missing charts. The `docker/web/Dockerfile` now downloads both assets via `wget` during the build stage so they are always present in the final image.
- **`docker-compose-full.yml`: `mariadb:latest` replaced with `mariadb:lts`** – Using `:latest` on the MariaDB image risks unexpected major-version upgrades on `docker pull`. Changed to `:lts` to track the Long-Term Support release line.

### Added

- **`.github/workflows/auto-patch-release.yml`** – New workflow that automates patch version bumps when the upstream base images are rebuilt. Triggered by `workflow_dispatch` (manual, GitHub Actions UI) or `repository_dispatch` (programmatic, via `curl` or `gh workflow run` from the build machine). Reads the latest git tag, increments the patch digit (e.g. `v2.1.0` → `v2.1.1`), and creates a GitHub release. The existing `docker-release.yml` fires on the new release and rebuilds and pushes both Docker Hub images with the updated base image baked in.
- **GitHub Actions: Node.js 24 compatibility** – Added `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24: true` as a workflow-level environment variable in `docker-release.yml` to silence Node.js 20 deprecation warnings emitted by the GitHub Actions runner. Bumped `docker/build-push-action` to `@v6`.

### Changed

- **`docker/docker-compose-full.yml`: SSH key mount added by default** – The agent service now includes a `/root/.ssh:/root/.ssh:ro` volume mount. Without this mount the agent container has no SSH keys or known-hosts file, so crontab import and remote job execution against SSH targets silently fail. An agent-specific directory alternative (`/opt/cronmanager/.ssh`) is documented in `README.md` for installations that require key isolation.
- **`simple_debian_setup.sh` v2.0.0: docker-only mode removed** – The interactive `docker-only` deployment path (Step 1b, all associated conditionals) has been removed. The script now exclusively supports host-agent mode (PHP CLI + systemd service). This simplifies the guided setup considerably and avoids the overlap with the recommended Docker Hub installation path. Users who want a fully containerised agent should follow the Docker Hub setup described in `README.md`.

---

## [2.1.0] – branch: `dockerfull`

### Added

- **Self-contained Docker Hub images** – Two ready-to-use images published on Docker Hub:
  - `cs1711/cronmanager-agent` – contains the PHP agent source, all Composer dependencies, the database schema, and the container entrypoint.  No host PHP installation or Composer run required.
  - `cs1711/cronmanager-web` – contains the web application source and all Composer dependencies baked in.  Includes `su-exec` for privilege dropping.
- **Environment-variable configuration** – Both containers generate their `config.json` at each startup from environment variables.  The minimum required variables are `AGENT_HMAC_SECRET` and `DB_PASSWORD`; all other settings have sensible defaults.
- **Automatic database schema initialisation** – The agent container waits for MariaDB to become ready (up to 30 retries, 2 s apart) and applies `schema.sql` automatically on the first start if the `cronjobs` table does not yet exist.
- **`docker/agent/Dockerfile`** – Multi-stage Dockerfile: stage 1 installs Composer dependencies; stage 2 builds the final runtime image from `cs1711/cs_cronmanageragent:latest`.  Vendor tree is baked in at `/opt/phplib/vendor`.
- **`docker/web/Dockerfile`** – Multi-stage Dockerfile: stage 1 installs Composer dependencies; stage 2 builds the final runtime image from `cs1711/cs_php-nginx-fpm:latest-alpine`.  Vendor tree is baked in at `/var/www/libs/vendor`.
- **`docker/web/entrypoint.sh`** – Web container entrypoint: generates `config.json`, fixes volume ownership, then drops to `nobody` via `su-exec` before starting supervisord (nginx + PHP-FPM).
- **`docker/docker-compose-full.yml`** – Minimal Docker Compose file for the Docker Hub deployment.  Uses Docker-managed named volumes (`db-data`, `agent-log`, `web-log`) by default — no host path mounts required.  Host path mount alternatives are provided as commented-out lines.  Only mandatory host mount is `/etc/localtime`.
- **`.github/workflows/docker-release.yml`** – GitHub Actions workflow that builds and pushes both Docker images on every published GitHub release.  Uses `docker/metadata-action` to generate `v2.1.0`, `2.1`, `2`, and `latest` tags.  Supports `linux/amd64` and `linux/arm64` (multi-platform).

### Changed

- **`docker/agent/entrypoint.sh`** – Extended to generate `config.json` from environment variables before starting, and to wait for MariaDB and apply the schema on first run.

---

## [2.0.0] – branch: `agentless`

### Added

- **Remote crontab import** – The Import page now has a **Target** selector (local + all SSH host aliases from candidate users' `~/.ssh/config`). Selecting a remote target fetches the crontab user list and unmanaged entries from that host via SSH (BatchMode, 10 s timeout). Imported jobs are created with the selected target in their `targets` array instead of `['local']`. New agent endpoint `GET /import/ssh-targets` returns all available SSH aliases; `GET /crons/users` and `GET /crons/unmanaged` now accept an optional `?target=` query parameter.
- **Run Now Cleanup** (`POST /maintenance/once/cleanup`, admin only) – New maintenance section that removes stale "Run Now" (once-only) crontab entries across all cron users. These `# cronmanager-once:…` marker lines are normally self-cleaned by `cron-wrapper.sh` after execution, but can linger if the agent was unreachable during the cleanup call. The agent iterates all users with a crontab, strips every `# cronmanager-once:` marker and its command line, and returns the count of removed entries. The web UI shows a "Run Now Cleanup" card with a confirmation dialog and a success/none flash banner after the action.
- **Bulk selection for stuck executions** – Each stuck execution row now has a checkbox. A "Select All" header checkbox selects/deselects all visible rows. A bulk toolbar (hidden until at least one row is selected) shows the selection count and two actions: "Mark Finished" and "Delete Selected". Both actions trigger a confirmation dialog before submitting. Flash banners confirm how many records were affected.
- **`POST /maintenance/executions/bulk` endpoint** – New protected admin route that dispatches to `MaintenanceController::bulkAction()`. Accepts `ids[]` and `_action` (finish or delete) form fields; calls the corresponding per-record agent endpoint for each ID.
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
- **Docker-only deployment mode** – New deployment option where the Cronmanager agent runs in a Docker container (`cs1711/cs_cronmanageragent:latest`) alongside the web app and MariaDB, instead of being installed directly on the host as a systemd service. No PHP installation on the host is required in this mode.
- **`docker/agent/entrypoint.sh`** – Container entrypoint script that fixes SSH key permissions, starts the cron daemon in the background, reads bind address and port from `config.json`, and starts the PHP built-in server as the foreground process.
- **`simple_debian_setup.sh`: deployment type selection** – New interactive step (Step 1b) asks whether to install in `host-agent` mode (classic, PHP CLI + systemd) or `docker-only` mode. All subsequent steps adapt accordingly.

### Changed

- **`deploy.sh --docker` patches configs on first deploy** – When deploying example config files for the first time with `--docker`: agent `database.host` is set to `cronmanager-db` and web `agent.url` is set to `http://cronmanager-agent:8865`, both using Docker service names instead of the `host.docker.internal` / `127.0.0.1` defaults.
- **`deploy.sh` requires `--host-agent` or `--docker`** – The deployment target mode is now a mandatory argument. Without it the script exits with a usage message. `--host-agent` installs/restarts the systemd service after deploying; `--docker` skips all systemd steps (use docker-compose to manage the stack).
- **`deploy.sh --host-agent undeploy`** – New mode that stops and disables the systemd service and removes the unit file, then exits. PHP files and config are kept on the target system.
- **Standardised deployment paths** – All deployment target paths are now fixed constants (no longer configurable in `deploy.env`): agent at `/opt/cronmanager/agent`, web at `/opt/cronmanager/www` (sub-dirs: `html/`, `conf/`, `log/`), database at `/opt/cronmanager/db`. `DEPLOY_DB`, `DEPLOY_WEB`, and `DEPLOY_AGENT` variables removed from `deploy.env.example`.
- **`docker/docker-compose.yml`** – Web volume mounts updated to the fixed paths (`/opt/cronmanager/www/conf`, `/opt/cronmanager/www/html`, `/opt/cronmanager/www/log`).
- **`docker/docker-compose-agent.yml`** – Agent volume mounts updated from `/opt/phpscripts/cronmanager/agent` to `/opt/cronmanager/agent`. Web volume mounts updated to match standardised paths.
- **`deploy.sh` migrate mode** – Crontab cleanup now matches any `cron-wrapper.sh` path (not just the old `/opt/phpscripts/…` path), and migration manual-steps note simplified since paths are already standardised.
- **`simple_debian_setup.sh`: conditional prerequisites** – In docker-only mode, PHP 8.4 CLI and PHP extension checks on the host are skipped (PHP runs inside the container). Composer installation on the host is also skipped.
- **`simple_debian_setup.sh`: agent path patching** – In docker-only mode, hardcoded paths in agent files are patched to the fixed container path `/opt/cronmanager/agent` instead of the host `AGENT_DIR`, since the agent always runs from that path inside the container.
- **`simple_debian_setup.sh`: agent `config.json`** – In docker-only mode the database host is set to `cronmanager-db` (Docker service name) and log/wrapper paths use the container path. In host-agent mode the database host remains `127.0.0.1`.
- **`simple_debian_setup.sh`: web `config.json`** – Agent URL is set to `http://cronmanager-agent:${AGENT_PORT}` in docker-only mode (container-to-container) and `http://host.docker.internal:${AGENT_PORT}` in host-agent mode.
- **`simple_debian_setup.sh`: docker-compose.yml** – In docker-only mode a `cronmanager-agent` service is added to the compose file. The agent source directory, config, log, SSH keys, and entrypoint are mounted as volumes. The web container no longer needs `extra_hosts` in docker-only mode. In host-agent mode the compose file is unchanged.
- **`simple_debian_setup.sh`: systemd step** – Skipped in docker-only mode; informational message shown instead.
- **`simple_debian_setup.sh`: start agent step** – In docker-only mode the agent health check uses `docker exec` instead of a host curl call. systemctl commands are not shown in the final summary.

### Fixed

- **Stuck executions 500 error** – SQL query used non-existent column `el.job_id`; corrected to `el.cronjob_id` with matching JOIN on `cronjobs.id`.
- **`$t` undefined in maintenance template** – Added the `$t` lambda at the top of `web/templates/maintenance/index.php`, consistent with all other templates.
- **`templates/maintenance/` directory missing on deploy** – Added `mkdir_on_target` call in `deploy.sh` for the maintenance template directory.

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
