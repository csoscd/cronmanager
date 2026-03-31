# Cronmanager – Technical Reference

This document describes the internal architecture, API, database schema, security model,
and development conventions for Cronmanager. For installation and user-facing features
see [README.md](README.md).

---

## Table of Contents

1. [System Overview](#system-overview)
2. [Directory Layout](#directory-layout)
3. [Technology Stack](#technology-stack)
4. [Host Agent](#host-agent)
   - [Entry point](#entry-point-agentphp)
   - [Bootstrap](#bootstrap)
   - [Router](#router)
   - [HMAC validation](#hmac-validation)
   - [CrontabManager](#crontabmanager)
   - [cron-wrapper.sh](#cron-wrappershell)
   - [MailNotifier](#mailnotifier)
   - [SshConfigParser](#sshconfigparser)
5. [Agent HTTP API](#agent-http-api)
6. [Web Application](#web-application)
   - [Entry point](#entry-point-indexphp)
   - [Bootstrap](#bootstrap-1)
   - [Router](#router-1)
   - [Controllers](#controllers)
   - [HostAgentClient](#hostagentclient)
   - [Authentication](#authentication)
   - [Session management](#session-management)
   - [Template engine](#template-engine)
   - [Translator](#translator)
7. [Database Schema](#database-schema)
8. [Security Model](#security-model)
9. [Performance](#performance)
10. [Configuration Reference](#configuration-reference)
11. [Deployment Script](#deployment-script)
12. [Docker Hub Images](#docker-hub-images)
13. [Logging](#logging)
13. [Internationalisation](#internationalisation)
14. [Adding a New Language](#adding-a-new-language)
15. [Adding a New Agent Endpoint](#adding-a-new-agent-endpoint)
16. [Database Migrations](#database-migrations)

---

## System Overview

Cronmanager consists of three runtime components:

| Component | Runtime | Location on host |
|---|---|---|
| **Web UI** | PHP-FPM 8.4 + Nginx, Docker container | `/opt/cronmanager/www/html` |
| **Host Agent** | PHP 8.4 CLI built-in server — systemd service (host-agent mode) or Docker container (docker mode) | `/opt/cronmanager/agent` |
| **Database** | MariaDB LTS, Docker container | `/opt/cronmanager/db` |

A fourth logical component is the **cron wrapper script** (`cron-wrapper.sh`), which
is injected into each managed crontab entry and bridges the Linux cron daemon with the
host agent.

### Communication paths

```
Browser ──HTTPS──► Web UI container ──HTTP+HMAC──► Host Agent
                                                        │
                   Host Agent ◄────────── cron-wrapper (via curl+openssl)
                        │
                        ├── reads/writes /var/spool/cron/crontabs/*
                        └── PDO ──► MariaDB container
```

In host-agent mode the web container reaches the agent via `host.docker.internal:8865` (Docker `extra_hosts: host-gateway`). In docker mode the agent runs in a separate container on the same `cronmanager-internal` network, reachable as `cronmanager-agent:8865`.

---

## Directory Layout

```
/opt/dev/cronmanager/          ← development root
├── composer.json              ← shared PHP dependencies
├── deploy.sh                  ← deployment script
├── deploy.env[.example]       ← deployment configuration
├── db.credentials[.example]   ← database passwords (not in VCS)
├── .github/
│   └── workflows/
│       ├── docker-release.yml          ← builds & pushes Docker Hub images on GitHub release
│       └── auto-patch-release.yml      ← increments patch version and creates a GitHub release (triggered on base image rebuild)
├── docker/
│   ├── docker-compose.yml          ← host-agent mode (web + MariaDB)
│   ├── docker-compose-agent.yml    ← docker mode (agent + web + MariaDB, file-mounted source)
│   ├── docker-compose-full.yml     ← Docker Hub mode (self-contained images, named volumes)
│   ├── agent/
│   │   ├── Dockerfile              ← self-contained agent image
│   │   └── entrypoint.sh           ← agent container entrypoint (generates config from env)
│   └── web/
│       ├── Dockerfile              ← self-contained web image
│       └── entrypoint.sh          ← web container entrypoint (generates config from env)
├── README.md
├── TECHNICAL.md
│
├── agent/                     ← host agent source
│   ├── agent.php              ← CLI entry point
│   ├── config/config.json     ← agent configuration
│   ├── bin/
│   │   ├── cron-wrapper.sh        ← injected into every crontab entry
│   │   ├── send-notification.php  ← background SMTP dispatcher (spawned by ExecutionFinishEndpoint)
│   │   ├── check-limits.php       ← execution limit checker (runs every minute via /etc/cron.d)
│   │   ├── start-agent.sh         ← manual start helper
│   │   └── create-admin.php       ← CLI tool: create first admin
│   ├── sql/
│   │   ├── schema.sql         ← full schema (run on first deploy)
│   │   └── migrations/        ← incremental SQL migrations
│   ├── systemd/
│   │   └── cronmanager-agent.service
│   └── src/
│       ├── Bootstrap.php
│       ├── Router.php
│       ├── Database/Connection.php
│       ├── Security/HmacValidator.php
│       ├── Cron/CrontabManager.php
│       ├── Notification/MailNotifier.php
│       ├── Ssh/SshConfigParser.php
│       └── Endpoints/
│           ├── CronListEndpoint.php
│           ├── CronGetEndpoint.php
│           ├── CronCreateEndpoint.php
│           ├── CronUpdateEndpoint.php
│           ├── CronDeleteEndpoint.php
│           ├── CronUsersEndpoint.php
│           ├── CronUnmanagedEndpoint.php
│           ├── ExecutionStartEndpoint.php
│           ├── ExecutionFinishEndpoint.php
│           ├── ExecutionUpdatePidEndpoint.php
│           ├── ExecutionKillEndpoint.php
│           ├── TagListEndpoint.php
│           ├── TagCreateEndpoint.php
│           ├── TagDeleteEndpoint.php
│           ├── SshHostsEndpoint.php
│           ├── HistoryEndpoint.php
│           ├── ExportEndpoint.php
│           ├── MonitorEndpoint.php
│           ├── MaintenanceCrontabResyncEndpoint.php
│           ├── MaintenanceStuckEndpoint.php
│           ├── MaintenanceResolveEndpoint.php
│           ├── MaintenanceDeleteExecutionEndpoint.php
│           └── MaintenanceHistoryCleanupEndpoint.php
│
└── web/                       ← web application source
    ├── index.php              ← front controller
    ├── config/config.json     ← web configuration (deployed to conf/)
    ├── lang/
    │   ├── en.php
    │   └── de.php
    ├── templates/
    │   ├── layout.php
    │   ├── login.php
    │   ├── setup.php
    │   ├── dashboard.php
    │   ├── timeline.php
    │   ├── swimlane.php
    │   ├── export.php
    │   ├── error.php
    │   ├── cron/
    │   │   ├── list.php
    │   │   ├── detail.php
    │   │   ├── form.php
    │   │   ├── import.php
    │   │   └── monitor.php
    │   ├── users/list.php
│   └── maintenance/
│       └── index.php
    └── src/
        ├── Bootstrap.php
        ├── Database/Connection.php
        ├── Session/SessionManager.php
        ├── I18n/Translator.php
        ├── Http/
        │   ├── Router.php
        │   ├── Request.php
        │   └── Response.php
        ├── Agent/HostAgentClient.php
        ├── Auth/
        │   ├── LocalAuthProvider.php
        │   └── OidcAuthProvider.php
        ├── Repository/UserPreferenceRepository.php
        └── Controller/
            ├── BaseController.php
            ├── AuthController.php
            ├── SetupController.php
            ├── DashboardController.php
            ├── CronController.php
            ├── TimelineController.php
            ├── SwimlaneController.php
            ├── ExportController.php
            ├── UserController.php
            └── MaintenanceController.php
```

---

## Technology Stack

| Layer | Library / Tool | Version |
|---|---|---|
| PHP | PHP 8.4 strict types | ≥ 8.4 |
| Configuration | `hassankhan/config` (Noodlehaus) | ^2.1 |
| Logging | `monolog/monolog` + `RotatingFileHandler` | ^3.6 |
| HTTP client | `guzzlehttp/guzzle` | ^7.8 |
| Email | `phpmailer/phpmailer` | ^6.8 |
| Database | MariaDB LTS via PDO | LTS |
| Cron parsing | `dragonmantank/cron-expression` | ^3.3 |
| Cron translation | `lorisleiva/cron-translator` | ^0.4 |
| In-memory cache | APCu (`php84-pecl-apcu`) | bundled |
| Frontend CSS | Tailwind CSS (local copy, no build step) | 3.4.x |
| Frontend charts | Chart.js 4 UMD build (self-hosted, downloaded by `deploy.sh`) | 4.x |
| Containerisation | Docker + Docker Compose v2 | — |

PHP libraries are installed into the shared directory `/opt/phplib/vendor` via Composer
and are available at `/opt/phplib/vendor/autoload.php` on the host and at
`/var/www/libs/vendor/autoload.php` inside the container (via volume mount).

---

## Host Agent

### Entry point (`agent/agent.php`)

The agent runs as a standard PHP CLI built-in HTTP server:

```
php -S 0.0.0.0:8865 agent.php
```

On each request the script:

1. Loads the autoloader and registers the `Cronmanager\Agent\*` PSR-4 namespace
2. Passes static file requests through (required for PHP built-in server)
3. Initialises `Bootstrap` (config + logger)
4. Parses the raw request: method, URI, body, `X-Agent-Signature` header
5. Skips HMAC validation for `GET /health`; validates all other requests
6. Instantiates `Router`, registers all endpoint handlers, dispatches the request
7. All output is JSON; unhandled exceptions produce `{"error":"..."}` with HTTP 500

### Bootstrap

`src/Bootstrap.php` is a singleton that provides a shared config and logger instance:

```php
Bootstrap::getInstance()->getConfig();  // Noodlehaus\Config
Bootstrap::getInstance()->getLogger();  // Monolog\Logger
```

Config is loaded from the JSON file at `config/config.json` (path resolved relative to
the script directory). The logger uses `RotatingFileHandler` with configurable path,
retention days, and minimum log level.

### Router

`src/Router.php` is a minimal regex-based router:

```php
$router->addRoute('GET',    '/crons',      [CronListEndpoint::class,   'handle']);
$router->addRoute('POST',   '/crons',      [CronCreateEndpoint::class, 'handle']);
$router->addRoute('GET',    '/crons/{id}', [CronGetEndpoint::class,    'handle']);
```

Path segments wrapped in `{name}` become entries in the `$params` array passed to
the handler. Routes are matched in registration order.

### HMAC validation

`src/Security/HmacValidator.php` validates the `X-Agent-Signature` header on every
non-health request.

**Signature algorithm:**

```
signature = hmac_sha256(hmac_secret, METHOD + PATH + BODY)
```

No separator is added between the three parts. The result is a lowercase hex string.

The web application's `HostAgentClient` computes and attaches the signature before every
request. The validator uses `hash_equals()` for constant-time comparison to prevent
timing attacks.

**To rotate the secret:** update the value in both config files and restart the agent.
No code changes or redeployment required.

### CrontabManager

`src/Cron/CrontabManager.php` manages crontab files for Linux users.

**Crontab file location:** `/var/spool/cron/crontabs/<username>`

The manager distinguishes between:
- **Managed entries** – lines whose command starts with the configured `wrapper_script` path
- **Unmanaged entries** – all other non-comment, non-blank lines

**Generated crontab entry format (single target):**
```
# Cronmanager: <description>  id:<job_id>
<schedule>  <wrapper_script>  <job_id>  <target>
```

**Generated crontab entry format (multi-target):**
```
# Cronmanager: <description>  id:<job_id>  target:<target>
<schedule>  <wrapper_script>  <job_id>  <target>
```

One crontab entry is written per (job, target) combination. When a job has targets
`[local, webserver01]`, two independent entries are written:

```
*/5 * * * *  /opt/.../cron-wrapper.sh  42  local
*/5 * * * *  /opt/.../cron-wrapper.sh  42  webserver01
```

After any create/update/delete operation on a job, `writeCrontab()` rebuilds the
complete crontab file from the current database state for the affected Linux user.

### cron-wrapper shell

`bin/cron-wrapper.sh` is invoked by the Linux cron daemon for every managed job run.

**Arguments:** `cron-wrapper.sh <job_id> <target>`

**Execution flow:**

```
1. Validate arguments (job_id must be a positive integer)
2. Read agent URL and HMAC secret from config.json via PHP
3. Compute HMAC signature for each request (openssl dgst -sha256 -hmac)
4. POST /execution/start  → receive execution_id
5. GET  /crons/{job_id}   → fetch command string
6. Launch command in background to enable PID capture:
     target = "local"          → bash -c "<command>" > tmp_output 2>&1 &
                                  LOCAL_JOB_PID=$!
                                  POST /execution/{id}/pid  {"pid": $LOCAL_JOB_PID}
                                  wait $LOCAL_JOB_PID
     target = "<ssh-alias>"    → wraps command as:
                                    bash -c 'echo $BASHPID > /tmp/.cmgr_EXEC_ID; exec COMMAND'
                                  runs SSH in background, captures SSH PID
                                  POST /execution/{id}/pid  {"pid_file": "/tmp/.cmgr_EXEC_ID"}
                                  wait $SSH_BG_PID
                                  removes remote PID file after wait
7. Capture stdout + stderr combined, truncate at 50,000 bytes
8. POST /execution/finish  → report exit_code, output, finished_at  (60 s timeout)
9. Exit with the original command's exit code
```

The wrapper logs to stderr (which is delivered by cron as a mail to the Linux user if
cron mail is configured). It does not abort if the agent is unreachable — the command
runs regardless and a best-effort finish report is sent.

**Dependencies:** `bash 4+`, `curl`, `openssl`, `php`

### check-limits.php

`bin/check-limits.php` is a CLI script that runs every minute via a system cron entry
installed at `/etc/cron.d/cronmanager-limits`.

**Purpose:** detect executions that have exceeded their configured `execution_limit_seconds`
and either notify, auto-kill, or both — handling the case where the job outlives a single
`check-limits.php` invocation.

**Logic per exceeded execution:**

```
1. Query all running executions (finished_at IS NULL) whose elapsed time
   exceeds the job's execution_limit_seconds
2. For each:
   a. If notify_on_failure=1 AND notified_limit_exceeded=0:
        dispatch notification (exit_code=-3) via send-notification.php
        set notified_limit_exceeded=1
   b. If auto_kill_on_limit=1:
        kill process (same logic as ExecutionKillEndpoint)
        mark execution finished with exit_code=-2
```

The `notified_limit_exceeded` flag ensures only one notification is sent even if the
job runs for multiple checker cycles. `ExecutionFinishEndpoint` performs the same
check at finish time to cover jobs that complete before the next checker run.

### MailNotifier

`src/Notification/MailNotifier.php` sends failure alerts via SMTP using PHPMailer.
It is invoked indirectly by `ExecutionFinishEndpoint` when:
- The job's `notify_on_failure` flag is `1`, and
- The exit code is non-zero, and
- `mail.enabled` is `true` in the agent config

**Async dispatch:** because the PHP built-in server is single-threaded, a blocking SMTP
call in the request handler would make the agent unresponsive for the duration of the
connection attempt. `ExecutionFinishEndpoint` therefore writes the notification payload
to a temporary file and spawns `bin/send-notification.php` as a detached background
process (`timeout 30 php send-notification.php <tempfile> &`). The HTTP response is
returned immediately; the child process handles SMTP independently. If `exec()` is
unavailable the endpoint falls back to synchronous sending.

**SMTP timeout:** `MailNotifier` applies `mail.smtp_timeout` (default: 15 s) via
`$mail->Timeout` as a secondary safeguard within the background process.

**Encryption:** use `"ssl"` for port 465 (SMTPS / implicit TLS) and `"tls"` for
port 587 (STARTTLS). Mixing these causes the connection to hang.

### SshConfigParser

`src/Ssh/SshConfigParser.php` parses `~/.ssh/config` for a given Linux user and
returns the list of named `Host` entries (excluding wildcard `*` entries).
These are offered in the web UI's target selector when creating or editing a job.

---

## Agent HTTP API

All endpoints require an `X-Agent-Signature` HMAC header (see [HMAC validation](#hmac-validation)).
All responses are `application/json`.

### GET /health

Public endpoint. No signature required.

**Response:**
```json
{ "status": "ok", "timestamp": "2026-03-18T10:00:00+00:00" }
```

---

### GET /crons

List all managed cron jobs.

**Query parameters:**

| Parameter | Type | Description |
|---|---|---|
| `user` | string | Filter by Linux user |
| `tag` | string | Filter by tag name |
| `target` | string | Filter by target (`local` or SSH alias) |
| `search` | string | Wildcard match on description and command |

**Response:**
```json
{
    "data": [
        {
            "id": 1,
            "linux_user": "deploy",
            "schedule": "*/5 * * * *",
            "command": "/usr/bin/php /opt/scripts/sync.php",
            "description": "Data sync",
            "active": 1,
            "notify_on_failure": 1,
            "tags": ["sync", "prod"],
            "targets": ["local", "webserver01"],
            "created_at": "2026-03-01 09:00:00"
        }
    ],
    "count": 1
}
```

---

### GET /crons/{id}

Retrieve a single job by ID.

**Response:** Single job object (same structure as above).

---

### POST /crons

Create a new managed cron job.

**Request body:**
```json
{
    "linux_user":        "deploy",
    "schedule":          "*/5 * * * *",
    "command":           "/usr/bin/php /opt/scripts/sync.php",
    "description":       "Data sync",
    "active":            1,
    "notify_on_failure": 0,
    "tags":              ["sync"],
    "targets":           ["local"]
}
```

**Response:**
```json
{ "id": 42 }
```

---

### PUT /crons/{id}

Update an existing job. Body is the same as POST /crons.

**Response:**
```json
{ "id": 42 }
```

---

### DELETE /crons/{id}

Delete a job and remove its crontab entries.

**Response:** HTTP 204 No Content.

---

### GET /crons/users

List all Linux users that have at least one managed crontab entry.

**Response:**
```json
{ "data": ["deploy", "root", "www-data"] }
```

---

### GET /crons/unmanaged

List unmanaged crontab lines for a Linux user.

**Query parameters:** `user` (required)

**Response:**
```json
{
    "data": [
        { "schedule": "0 2 * * *", "command": "/usr/bin/backup.sh", "raw": "0 2 * * * /usr/bin/backup.sh" }
    ]
}
```

---

### POST /execution/start

Called by `cron-wrapper.sh` when a job begins.

**Request body:**
```json
{
    "job_id":     42,
    "started_at": "2026-03-18T10:00:00+00:00",
    "target":     "local"
}
```

**Response:**
```json
{ "execution_id": 1001 }
```

---

### POST /execution/finish

Called by `cron-wrapper.sh` when a job completes.

**Request body:**
```json
{
    "execution_id": 1001,
    "job_id":       42,
    "exit_code":    0,
    "output":       "Sync completed: 42 records\n",
    "finished_at":  "2026-03-18T10:00:03+00:00",
    "target":       "local"
}
```

**Response:** HTTP 204 No Content.

---

### POST /execution/{id}/pid

Called by `cron-wrapper.sh` immediately after the job process is launched (before `wait`).
Stores the local process PID or the remote PID file path so the kill endpoint can locate the process.

**Request body (local):**
```json
{ "pid": 12345 }
```

**Request body (remote SSH):**
```json
{ "pid_file": "/tmp/.cmgr_1001" }
```

Either field may be omitted; both may be supplied simultaneously.

**Response:** HTTP 204 No Content, or HTTP 404 if the execution is not found / already finished.

---

### POST /execution/{id}/kill

Terminates a running execution. Admin-only via web UI.

- **Local targets**: sends `SIGTERM` to the entire process group (`-$pid`) using `posix_kill()`.
- **Remote SSH targets**: SSHes to the target host, reads the PID from the stored PID file (path validated against `^/tmp/\.cmgr_\d+$`), sends `kill -TERM -$PID`, then removes the file.

After killing, marks the execution finished with `exit_code = -2`, appends `[Job was killed by operator]` to the output, and clears `pid` / `pid_file`.

**Response:** HTTP 204 No Content, or an error JSON on failure.

| Status | Meaning |
|--------|---------|
| 204 | Kill signal sent; execution marked finished |
| 404 | Execution not found or already finished |
| 422 | No PID stored for this execution |
| 500 | Kill attempt failed (process not found, SSH error, etc.) |

---

### GET /tags

List all tags with their current job counts.

**Response:**
```json
{
    "data": [
        { "id": 1, "name": "prod",  "job_count": 5 },
        { "id": 2, "name": "sync",  "job_count": 2 }
    ]
}
```

---

### POST /tags

Create a new tag.

**Request body:** `{ "name": "backup" }`

**Response:** `{ "id": 3 }`

---

### DELETE /tags/{id}

Delete a tag. Removes all `cronjob_tags` associations.

**Response:** HTTP 204 No Content.

---

### GET /ssh-hosts

List SSH host aliases available to a Linux user (parsed from `~/.ssh/config`).

**Query parameters:** `user` (required)

**Response:**
```json
{ "data": ["webserver01", "db01", "backup01"] }
```

---

### GET /history

Paginated execution history.

**Query parameters:**

| Parameter | Type | Description |
|---|---|---|
| `job_id` | int | Filter by specific job |
| `tag` | string | Filter by tag name |
| `user` | string | Filter by Linux user |
| `status` | string | `success`, `failed`, or `running` |
| `from` | string | Start date (`YYYY-MM-DD`) |
| `to` | string | End date (`YYYY-MM-DD`) |
| `limit` | int | Page size (default: 25) |
| `offset` | int | Pagination offset (default: 0) |

**Response:**
```json
{
    "data": [
        {
            "id":           1001,
            "cronjob_id":   42,
            "description":  "Data sync",
            "linux_user":   "deploy",
            "schedule":     "*/5 * * * *",
            "tags":         ["sync"],
            "started_at":   "2026-03-18 10:00:00",
            "finished_at":  "2026-03-18 10:00:03",
            "exit_code":    0,
            "output":       "Sync completed: 42 records\n",
            "target":       "local"
        }
    ],
    "total": 1
}
```

---

### GET /crons/{id}/monitor

Per-job execution statistics for the monitor page.

**Query parameters:**

| Parameter | Type | Default | Description |
|---|---|---|---|
| `period` | string | `30d` | Time window: `1h`, `6h`, `12h`, `24h`, `7d`, `30d`, `3m`, `6m`, `1y` |

**Response:**
```json
{
    "job": {
        "id": 42,
        "description": "Data sync",
        "schedule": "*/5 * * * *",
        "linux_user": "deploy",
        "command": "/usr/bin/php /opt/scripts/sync.php",
        "active": 1,
        "notify_on_failure": 1,
        "tags": ["sync"],
        "targets": ["local"]
    },
    "stats": {
        "execution_count": 288,
        "success_count":   281,
        "failure_count":   7,
        "success_rate":    97.57,
        "alert_count":     7,
        "avg_duration":    2.34,
        "min_duration":    1.1,
        "max_duration":    9.8
    },
    "duration_series": [
        { "started_at": "2026-03-18 10:00:00", "duration_seconds": 2.1, "success": true },
        { "started_at": "2026-03-18 10:05:00", "duration_seconds": 3.4, "success": false }
    ],
    "bar_buckets": [
        { "label": "18 Mar 10:00", "success": 11, "failed": 1 },
        { "label": "18 Mar 11:00", "success": 12, "failed": 0 }
    ],
    "recent": [
        {
            "started_at":   "2026-03-18 10:25:00",
            "finished_at":  "2026-03-18 10:25:02",
            "duration_seconds": 2.0,
            "target":       "local",
            "exit_code":    0
        }
    ],
    "period": "30d",
    "from":   "2026-02-16 00:00:00",
    "to":     "2026-03-18 23:59:59"
}
```

**Notes:**

- `alert_count` is approximated as `failure_count` when `notify_on_failure = 1`; the `execution_log` table has no dedicated `alert_sent` column.
- `duration_series` contains at most 500 entries (most recent), ordered chronologically.
- `bar_buckets` use adaptive bucket intervals so approximately 12–30 bars are always shown regardless of the selected period (5 min for `1h`, ~30 days for `1y`).
- Only completed executions (non-NULL `finished_at`) are included in duration and bar data.

---

### GET /export

Export managed jobs in crontab or JSON format.

**Query parameters:**

| Parameter | Type | Description |
|---|---|---|
| `format` | string | `crontab` (default) or `json` |
| `user` | string | Filter by Linux user |
| `tag` | string | Filter by tag name |

**Response (crontab format):**
```
# Cronmanager export – 2026-03-18T10:00:00+00:00
# ─────────────────────────────────────────────
# Job: Data sync  [id:42]
# User: deploy  Tags: sync
# target: local
*/5 * * * *  /opt/cronmanager/agent/bin/cron-wrapper.sh  42  local
```

For remote targets the crontab line wraps the command in an SSH call:
```
*/5 * * * *  ssh -o BatchMode=yes webserver01 -- /opt/.../cron-wrapper.sh 42 webserver01
```

**Response (JSON format):**
```json
{
    "exported_at": "2026-03-18T10:00:00+00:00",
    "jobs": [ { ...job object including targets array... } ]
}
```

---

## Web Application

### Entry point (`web/index.php`)

All HTTP requests are routed through a single front controller.

**Bootstrap sequence:**

1. Load shared autoloader (`/var/www/libs/vendor/autoload.php` inside container)
2. Register `Cronmanager\Web\*` PSR-4 namespace
3. Initialise `Bootstrap` (config + logger)
4. Start PHP session via `SessionManager`
5. Create `Request` from `$_SERVER` / `$_POST` / `$_GET`
6. Check if first-run setup is needed; redirect to `/setup` if so
7. Register all routes on `Router` with optional role requirements
8. Dispatch request; authenticated and role checks run before any controller method

### Bootstrap

`src/Bootstrap.php` is a singleton providing a shared `Noodlehaus\Config` (loaded from
`/var/www/conf/config.json` inside the container, i.e. the host's
`/opt/cronmanager/www/conf/config.json`) and a `Monolog\Logger`.

### Router

`src/Http/Router.php` matches the request path against registered patterns and invokes
the appropriate controller method.

Route registration:

```php
$router->get('/crons',         [$cronCtrl, 'index'],   'view');
$router->post('/crons',        [$cronCtrl, 'store'],   'admin');
$router->get('/crons/{id}',    [$cronCtrl, 'show'],    'view');
```

The third argument is the **minimum required role** (`'view'` or `'admin'`). Unauthenticated
requests to protected routes redirect to `/login`. Authenticated users without the required
role receive a 403 response.

### Controllers

All controllers extend `BaseController` which provides:

```php
protected function render(string $template, string $title, array $data, string $currentPath): void
```

`render()` first captures the sub-template output (with `$data` variables extracted into
scope via `extract()`), then includes `layout.php` with the result as `$content`.

**Important:** Because both the sub-template and `layout.php` share the same variable scope
after `extract()`, avoid defining variables named `$user`, `$content`, `$title`, or
`$translator` in sub-templates, as they would shadow the layout's own variables.
The layout always uses `SessionManager::getUser()` / `SessionManager::hasRole()` directly
for security-sensitive checks such as navigation visibility.

| Controller | Routes | Min role |
|---|---|---|
| `AuthController` | `GET/POST /login`, `GET /logout`, `GET /auth/callback`, `GET /auth/oidc` | public |
| `SetupController` | `GET/POST /setup` | public |
| `DashboardController` | `GET /dashboard` | view |
| `CronController` | `GET /crons`, `GET /crons/{id}`, `GET /crons/{id}/monitor`, `GET/POST /crons/new`, `GET/POST /crons/{id}/edit`, `POST /crons/{id}/delete`, `GET/POST /crons/import` | view/admin |
| `TimelineController` | `GET /timeline` | view |
| `SwimlaneController` | `GET /swimlane`, `GET /swimlane?debug=1` | view |
| `ExportController` | `GET /export`, `GET /export/download` | view |
| `UserController` | `GET /users`, `POST /users/{id}/role`, `POST /users/{id}/delete` | admin |
| `MaintenanceController` | `GET /maintenance`, `POST /maintenance/crontab/resync`, `POST /maintenance/executions/{id}/finish`, `DELETE /maintenance/executions/{id}`, `POST /maintenance/executions/bulk`, `POST /maintenance/history/cleanup` | admin |

### HostAgentClient

`src/Agent/HostAgentClient.php` is the sole communication bridge between the web UI
and the host agent.

**Methods:**

```php
public function get(string $path, array $query = []): array
public function post(string $path, array $data = []): array
public function put(string $path, array $data = []): array
public function delete(string $path): void
```

Every call:
1. Serialises `$data` to a JSON body string
2. Computes `X-Agent-Signature: hmac_sha256(secret, METHOD + PATH + BODY)`
3. Sends the request via Guzzle with the configured timeout
4. Decodes the JSON response body and returns the result array
5. Throws `\RuntimeException` on any transport error or non-2xx HTTP status

### Authentication

**Local authentication** (`src/Auth/LocalAuthProvider.php`):

- Users are stored in the `users` table with bcrypt-hashed passwords
- `authenticate(username, password)` returns the user row or `null`
- `createUser(username, password, role)` is used by the setup wizard

**OIDC authentication** (`src/Auth/OidcAuthProvider.php`):

Implements the OAuth 2.0 **Authorization Code flow with PKCE**:

1. `getAuthorizationUrl()`:
   - Fetches the provider's discovery document (OpenID Configuration)
   - Generates a random `state` token and `code_verifier` (PKCE)
   - Stores them in the PHP session
   - Returns the authorization URL

2. `handleCallback(code, state)`:
   - Validates the `state` from session
   - Exchanges the `code` for tokens via the token endpoint
   - Fetches user info from the userinfo endpoint
   - Resolves or creates a local `users` row (lookup by `oauth_sub`, then by email,
     then create new with role `view`)
   - Returns the local user row

**Guzzle client for OIDC:** `OidcAuthProvider::createGuzzleClient()` reads
`auth.oidc_ssl_verify` and `auth.oidc_ssl_ca_bundle` from config and sets the
Guzzle `verify` option accordingly:

| Config | Guzzle `verify` |
|---|---|
| `oidc_ssl_ca_bundle` non-empty | Path string → custom CA bundle |
| `oidc_ssl_verify = false` | `false` → TLS verification disabled |
| Default | `true` → system CA bundle |

### Session management

`src/Session/SessionManager.php` wraps PHP's `$_SESSION` with typed helpers:

```php
SessionManager::login(array $user): void  // store user row in session
SessionManager::logout(): void
SessionManager::isLoggedIn(): bool
SessionManager::getUser(): ?array
SessionManager::hasRole(string $role): bool  // 'admin' implies 'view'
SessionManager::getUserId(): ?int
SessionManager::set(string $key, mixed $value): void
SessionManager::get(string $key, mixed $default = null): mixed
SessionManager::flash(string $key): mixed  // reads + removes key in one call
```

### Template engine

Templates are plain PHP files included via `require`. No Blade, Twig, or other engine.
Output is HTML-escaped with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` at every
output point.

`layout.php` receives:
- `$title` – page title string
- `$content` – captured HTML from the sub-template
- `$currentPath` – for nav-item active highlighting
- `$translator` – shared `Translator` instance
- `$user` – from `SessionManager::getUser()` (layout reads it directly to avoid variable shadowing)

### Translator

`src/I18n/Translator.php`:

```php
$t = $translator->t('key', ['placeholder' => 'value']);
```

Language files at `lang/{en,de}.php` return plain PHP arrays. The active language
is stored in `$_SESSION['lang']`. Fallback is English if a key is missing.

---

## Database Schema

### `users`

| Column | Type | Notes |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `username` | VARCHAR(128) UNIQUE | Email or chosen username |
| `password_hash` | VARCHAR(255) NULL | NULL for OIDC-only users |
| `role` | ENUM('view','admin') | Default: `view` |
| `oauth_sub` | VARCHAR(255) NULL UNIQUE | OIDC subject identifier |
| `created_at` | DATETIME | |
| `cron_list_page_size` | SMALLINT UNSIGNED NULL | Per-user preference; NULL = default |

### `cronjobs`

| Column | Type | Notes |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `linux_user` | VARCHAR(64) | Owner Linux user |
| `schedule` | VARCHAR(100) | Standard cron expression |
| `command` | TEXT | Command to execute |
| `description` | VARCHAR(255) | Human-readable name |
| `active` | TINYINT(1) | `1` = enabled |
| `notify_on_failure` | TINYINT(1) | Send email on failure or limit exceeded |
| `execution_limit_seconds` | INT UNSIGNED NULL | Maximum allowed runtime; NULL = no limit |
| `auto_kill_on_limit` | TINYINT(1) | `1` = auto-kill when limit is exceeded |
| `execution_mode` | ENUM('local','remote') | Legacy; superseded by `job_targets` |
| `ssh_host` | VARCHAR(255) NULL | Legacy; superseded by `job_targets` |
| `created_at` | DATETIME | |

### `tags`

| Column | Type | Notes |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `name` | VARCHAR(64) UNIQUE | |

### `cronjob_tags`

| Column | Type | Notes |
|---|---|---|
| `cronjob_id` | INT UNSIGNED FK → `cronjobs.id` | CASCADE DELETE |
| `tag_id` | INT UNSIGNED FK → `tags.id` | CASCADE DELETE |

PRIMARY KEY: `(cronjob_id, tag_id)`

### `job_targets`

| Column | Type | Notes |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `job_id` | INT UNSIGNED FK → `cronjobs.id` | CASCADE DELETE |
| `target` | VARCHAR(255) | `"local"` or SSH config alias |

UNIQUE KEY: `(job_id, target)`

### `execution_log`

| Column | Type | Notes |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `cronjob_id` | INT UNSIGNED FK → `cronjobs.id` | CASCADE DELETE |
| `started_at` | DATETIME | |
| `finished_at` | DATETIME NULL | NULL while running |
| `exit_code` | INT NULL | NULL while running; `-2` = killed by operator; `-3` = limit exceeded (still running) |
| `output` | TEXT NULL | Truncated at 50,000 bytes |
| `target` | VARCHAR(255) NULL | `"local"`, SSH alias, or NULL (pre-migration rows) |
| `pid` | INT UNSIGNED NULL | Process PID for local executions; cleared on finish |
| `pid_file` | VARCHAR(255) NULL | Remote PID file path for SSH executions; cleared on finish |
| `notified_limit_exceeded` | TINYINT(1) | `1` = limit-exceeded notification already sent |

### `schema_migrations`

| Column | Type | Notes |
|---|---|---|
| `filename` | VARCHAR(255) PK | Base filename of the applied migration (e.g. `004_kill_and_limits.sql`) |
| `applied_at` | DATETIME | Timestamp of application (DEFAULT CURRENT_TIMESTAMP) |

---

## Security Model

### Inter-service HMAC

Every request from the web container to the host agent carries:

```
X-Agent-Signature: <hex>
```

Where `<hex>` is:

```
HMAC-SHA256(hmac_secret, HTTP_METHOD + REQUEST_PATH + REQUEST_BODY)
```

The parts are concatenated without separators. An attacker who intercepts a request
cannot forge a different method, path, or body without knowing the secret.
Validation uses `hash_equals()` to prevent timing attacks.

The `hmac_secret` is stored only in the two config files (agent and web).
It is never logged and is not part of the response.

### cron-wrapper signing

`cron-wrapper.sh` reads the HMAC secret directly from the agent config file on disk
and computes signatures via `openssl dgst -sha256 -hmac`. The same algorithm is used
so the agent validates wrapper requests with the same validator.

### Role enforcement

Protected routes specify a minimum role in the router registration:

```php
$router->get('/users', [$userCtrl, 'index'], 'admin');
```

The router calls `SessionManager::hasRole($requiredRole)` before dispatch.
`hasRole('view')` returns `true` for both `view` and `admin` users.
`hasRole('admin')` returns `true` only for `admin` users.

### Self-protection

`UserController` prevents admins from modifying or deleting their own account:

```php
if ($id === SessionManager::getUserId()) {
    // reject with 403
}
```

This prevents accidental or malicious privilege loss.

### SQL injection prevention

All database queries use PDO prepared statements with named placeholders:

```php
$stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username');
$stmt->execute([':username' => $username]);
```

No raw string interpolation is used in SQL queries.

### Output escaping

All dynamic values rendered into HTML pass through:

```php
htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
```

No raw `echo` of user-controlled data occurs in templates.

### CSRF protection

Every state-changing request (POST / PUT / PATCH / DELETE) on a protected route is validated
against a per-session CSRF token.

**Token generation** (`SessionManager::getCsrfToken()`):
```php
$token = bin2hex(random_bytes(32));   // 64 hex characters
$_SESSION[self::KEY_CSRF] = $token;
```

**Validation** (`Router::dispatch()`):
```php
$submitted = (string) ($_POST['_csrf'] ?? '');
if (!SessionManager::validateCsrfToken($submitted)) {
    $this->render403();
    return;
}
```

`validateCsrfToken()` uses `hash_equals()` to prevent timing attacks.

All templates include the token as a hidden field:
```html
<input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
```

`BaseController::render()` injects `csrf_token` automatically into every template data array,
so individual controllers do not need to pass it explicitly.

---

### Login rate limiting

`SessionManager` tracks failed login attempts per IP address to limit brute-force attacks.

| Setting | Value | Constant |
|---|---|---|
| Max attempts | 5 | `RATE_MAX_ATTEMPTS` |
| Lockout duration | 900 s (15 min) | `RATE_LOCK_SECONDS` |

The IP is stored as `hash('sha256', $ip)` in the session to avoid logging raw addresses.

```php
if (!SessionManager::isLoginAllowed($ip)) {
    $remaining = (int) ceil(SessionManager::getLockoutRemaining($ip) / 60);
    // flash error + redirect /login
}
// ... after failed auth:
SessionManager::recordLoginFailure($ip);
// ... after successful auth:
SessionManager::clearLoginFailures($ip);
```

**Limitation**: Rate data is stored in the PHP session. An attacker opening a new browser
(new session) or rotating IPs bypasses the counter. A shared store (APCu / Redis / DB table)
is required for production-grade limiting.

---

### HTTP security response headers

Sent by `web/index.php` before any output on every request:

| Header | Value |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` |
| `Content-Security-Policy` | `default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'` |

`'unsafe-inline'` for scripts is necessary for the Tailwind dark-mode detection snippet and
`tailwind.config` block. For a stricter policy, move those snippets to external files and use
nonce-based CSP.

---

### Startup HMAC secret validation

On startup both `web/index.php` and `agent/agent.php` check the configured HMAC secret:

```php
if ($secret === '' || $secret === 'change-me-to-a-secure-random-string') {
    $logger->critical('SECURITY: hmac_secret is empty or default ...');
} elseif (strlen($secret) < 32) {
    $logger->warning('SECURITY: hmac_secret is shorter than 32 characters ...');
}
```

This ensures misconfigured instances are visible in the log immediately after start, before
any requests are processed.

---

### OIDC PKCE + state

- `code_verifier` is a cryptographically random 64-byte base64url string
- `code_challenge = base64url(sha256(code_verifier))`
- `state` is a cryptographically random 16-byte hex string
- Both are stored in the PHP session and verified during callback processing

---

## Performance

### Swimlane view caching (APCu)

The swimlane page is the most CPU-intensive page in the application because it
pre-computes weekly fire-time patterns for every managed job using
`dragonmantank/cron-expression`.  For a job running every minute, this
produces up to 10 080 `DateTime` objects per page load without caching.

APCu (PHP shared-memory cache) is used to eliminate redundant computation:

| What is cached | APCu key | TTL |
|---|---|---|
| `computeSchedule()` result for a cron expression | `cronmgr_sched_<md5(expr)>` | 86 400 s (24 h) |
| `translateCron()` result for a cron expression | `cronmgr_trans_<md5(expr)>` | 86 400 s (24 h) |

The cache key is derived from the expression string only.  Because fire-time
patterns are computed against a fixed reference week (`2024-01-01`), the cached
value is permanently valid until the APCu segment is reset (container restart).

**Fallback:** if APCu is unavailable (extension not loaded, `apc.enabled = 0`)
the controller falls back to computing every pattern on every request.  No error
is raised and the page still works — it is just slower.

**Required Alpine package:** `php84-pecl-apcu`

Verify that APCu is active:
```bash
docker exec <container> php -r "var_dump(extension_loaded('apcu'));"
# → bool(true)
```

### Timing instrumentation

Server-side timing is always written to the application log at `DEBUG` level:

```
[DEBUG] SwimlaneController::index timing {
    agent_ms: 8.4,   compute_ms: 142.1,  json_ms: 2.3,
    total_ms: 152.8, jobs: 12,            apcu: true,
    cache_hits: 10,  cache_miss: 2,       payload_b: 48320
}
```

To see the timing breakdown in the browser, append `?debug=1` to the URL:
```
https://your-host/swimlane?debug=1
```

This embeds the timing table as an HTML comment at the end of the page (visible
in browser DevTools → Sources → Ctrl+U, or `curl … | tail`).  The output is
only included when `debug=1` is present; normal requests are unaffected.

---

## Configuration Reference

For the full list of available options see [README.md – Configuration Reference](README.md#configuration-reference).

Config is loaded via `Noodlehaus\Config::load('/path/to/config.json')`.
Use dot-notation to access nested keys:

```php
$config->get('agent.hmac_secret');
$config->get('logging.max_days', 30);   // with default
```

---

## Deployment Script

`deploy.sh` reads `deploy.env` and `db.credentials` and supports two transport modes,
two target modes, and four deployment modes.

### Required argument: target mode

One of these flags is **required** on every invocation:

| Flag | Behaviour |
|---|---|
| `--host-agent` | Agent runs as a systemd service on the host; `deploy.sh` installs/restarts the service |
| `--docker` | Agent runs as a Docker container; systemd steps are skipped; on first deploy, configs are automatically patched with Docker service names |

### Transport modes (set in `deploy.env`)

| `DEPLOY_TYPE` | Behaviour |
|---|---|
| `SSH` | All file transfers via `rsync -e ssh` and `scp`; commands via `ssh HOST "cmd"` |
| `LOCAL` | All operations run locally; no SSH involved |

### Deployment modes (CLI argument)

| Argument | Behaviour |
|---|---|
| `full` | Creates directories + full rsync mirror (`--delete`); deploys example configs if absent; patches config values based on target mode |
| `update` | rsync with `--checksum` (only changed files); config files are never overwritten |
| `migrate` | Migrates a running host-agent installation to docker mode: deploys changed files, stops and disables the systemd service, removes managed crontab entries from all host users, patches `database.host` and `agent.url` in both config files |
| `undeploy` | `--host-agent` only: stops and removes the systemd service; PHP files and config are kept on the target |

### Fixed deployment paths

All paths are hardcoded — not configurable in `deploy.env`:

| Component | Path |
|---|---|
| Agent | `/opt/cronmanager/agent` |
| Web (html) | `/opt/cronmanager/www/html` |
| Web (conf) | `/opt/cronmanager/www/conf` |
| Web (log) | `/opt/cronmanager/www/log` |
| Database | `/opt/cronmanager/db` |

### Selective deployment

```bash
./deploy.sh --host-agent update --agent   # deploy only the host agent
./deploy.sh --docker update --web         # deploy only the web application
./deploy.sh --host-agent full             # deploy both (default)
```

### Config auto-patching on first deploy

When `deploy.sh` deploys the example config files for the first time (i.e., no config exists on the target yet), it automatically patches mode-specific values:

| Config file | Key | `--host-agent` | `--docker` |
|---|---|---|---|
| Agent `config.json` | `database.host` | `127.0.0.1` | `cronmanager-db` |
| Web `config.json` | `agent.url` | `http://host.docker.internal:8865` | `http://cronmanager-agent:8865` |

### Composer handling

On every deployment, `deploy.sh` checks whether `${DEPLOY_COMPOSER}/composer.json`
exists on the target:

- **Absent:** the project's `composer.json` is copied there, and a reminder to run
  `composer install` is printed
- **Present:** the required libraries from the project's `composer.json` are printed
  as informational output so the operator can verify they are installed

### Static asset downloads

During deployment `deploy.sh` automatically downloads static assets that are not
checked into the repository:

| Asset | Target path | Condition |
|---|---|---|
| Tailwind CSS | `assets/js/tailwind.min.js` | Downloaded if absent |
| Chart.js 4 UMD | `assets/js/chart.min.js` | Downloaded if absent |

Both files are excluded from `rsync --delete` so re-deployments never remove them.

### SSH host override

If `DEPLOY_TYPE=SSH`, the SSH host from `deploy.env` can be overridden on the command line:

```bash
./deploy.sh --host-agent update staging   # deploy to the "staging" SSH host alias
```

---

## Docker Hub Images

The `dockerfull` deployment mode publishes two self-contained images to Docker Hub on
every GitHub release.  Unlike the `docker` mode (which mounts source code from the host),
these images have all PHP source and Composer dependencies baked in.

### Image summary

| Image | Base | Entrypoint behaviour |
|---|---|---|
| `cs1711/cronmanager-agent` | `cs1711/cs_cronmanageragent:latest` (Debian Trixie, PHP 8.4 CLI, cron, openssh-client) | Generates `config.json`, waits for MariaDB, applies schema, starts cron daemon, then `exec php -S` |
| `cs1711/cronmanager-web` | `cs1711/cs_php-nginx-fpm:latest-alpine` (Alpine, PHP 8.4 FPM, Nginx, supervisord) | Generates `config.json`, fixes volume ownership (`/var/www/conf`, `/var/www/log`), then `exec /usr/bin/supervisord` as root; nginx worker and PHP-FPM pool drop to `nobody` internally |

### Build pipeline

`docker/agent/Dockerfile` and `docker/web/Dockerfile` both use a two-stage build:

1. **`composer:2` stage** – installs all PHP dependencies from `composer.json`.
2. **Runtime stage** – copies the vendor tree and the application source; no Composer or PHP dev tools remain in the final image.

The images are built and pushed by `.github/workflows/docker-release.yml` on every published
GitHub release.  The workflow uses `docker/metadata-action` to produce the following tags
automatically from the release version (e.g. `v2.1.0`):

| Tag | Example |
|---|---|
| Full semver | `2.1.0` |
| Major.minor | `2.1` |
| Major | `2` |
| Latest | `latest` |

Multi-platform builds target `linux/amd64` and `linux/arm64`.

### Automatic patch releases for base image updates

When the upstream base images (`cs1711/cs_cronmanageragent` or `cs1711/cs_php-nginx-fpm`) are
rebuilt, the `.github/workflows/auto-patch-release.yml` workflow can be triggered either via
`workflow_dispatch` (manually from the GitHub Actions UI) or via `repository_dispatch` (from a
CI machine using `curl` or `gh workflow run`).

The workflow reads the latest git tag, increments the patch digit (e.g. `2.1.0` → `2.1.1`),
and creates a new GitHub release.  This in turn fires `docker-release.yml` via the
`release: published` trigger, which rebuilds and pushes the Cronmanager images with the updated
base image baked in.

```
base image rebuild
       │
       ▼ (workflow_dispatch / repository_dispatch)
auto-patch-release.yml → gh release create v2.x.(n+1)
       │
       ▼ (release: published trigger)
docker-release.yml → docker build + push to Docker Hub
```

### Config-from-environment pattern

Both containers generate their `config.json` at every start — no config volume is required.

**Agent (`docker/agent/entrypoint.sh`):**

```
1. php -r "echo json_encode([...])"  →  /opt/cronmanager/agent/config/config.json
2. Wait for MariaDB (30 × 2 s retries)
3. Apply schema.sql if 'cronjobs' table missing
4. Fix /root/.ssh permissions if present
5. Start cron daemon (background)
6. exec php -S <bind>:<port> agent.php
```

**Web (`docker/web/entrypoint.sh`):**

```
1. php84 -r "echo json_encode([...])"  →  /var/www/conf/config.json
2. chown -R nobody:nobody /var/www/conf /var/www/log
3. exec /usr/bin/supervisord            (runs as root)
   ├── nginx:     worker user = nobody  (patched in Dockerfile)
   └── php-fpm:   pool user = nobody, listen.owner = nobody,
                  listen.group = nobody, listen.mode = 0660
                  (patched in Dockerfile)
```

> **Why root for supervisord?** Alpine's `cs_php-nginx-fpm` base image does not include
> `su-exec`. Supervisord must start as root so it can bind ports and manage child process
> lifecycle. nginx and PHP-FPM then drop to `nobody` via their own configuration, keeping
> the effective attack surface identical to a `su-exec` approach.

### Vendor path inside containers

| Container | Autoloader path |
|---|---|
| Agent | `/opt/phplib/vendor/autoload.php` |
| Web | `/var/www/libs/vendor/autoload.php` |

These paths match the paths used by the non-docker deployment modes, so the PHP source
files (`Bootstrap.php`, etc.) require no code changes between modes.

### Volumes

All persistent state uses **Docker-managed named volumes** by default.
Host-path mount alternatives are available as commented-out lines in
`docker/docker-compose-full.yml`.

| Volume | Container path | Contains |
|---|---|---|
| `db-data` | `/var/lib/mysql` (MariaDB) | All database files |
| `agent-log` | `/opt/cronmanager/agent/log` | Agent log files |
| `web-log` | `/var/www/log` | Web application log files |

---

## Logging

Both the agent and the web application use `Monolog\Logger` with a
`RotatingFileHandler`. Log files are rotated daily and old files are removed after
`max_days` days (default: 30).

**Log levels** (in ascending severity):
`debug` → `info` → `warning` → `error` → `critical`

The configured level is the minimum that gets written. Set `"level": "debug"` to log
all requests and SQL queries during development.

**Web log path (inside container):** `/var/www/log/cronmanager-web.log`
**Agent log path (on host / in container):** `/opt/cronmanager/agent/log/cronmanager-agent.log`

Both paths are configurable in the respective `config.json` files.

---

## Internationalisation

Language files are plain PHP arrays:

```php
// lang/en.php
return [
    'app_name'          => 'Cronmanager',
    'nav_dashboard'     => 'Dashboard',
    'dashboard_title'   => 'Dashboard',
    // ...
];
```

The active language is stored in `$_SESSION['lang']` and toggled via `GET /lang/{code}`.

Translation with placeholders:

```php
// In a translation file:
'import_success' => 'Successfully imported {count} job(s).',

// In PHP code:
$translator->t('import_success', ['count' => 5]);
// → "Successfully imported 5 job(s)."
```

---

## Adding a New Language

1. Copy `web/lang/en.php` to `web/lang/<code>.php`
2. Translate all values; do not change the keys
3. Add the code to `i18n.available` in `web/config/config.json`:
   ```json
   "available": ["en", "de", "fr"]
   ```
4. The language switcher in the nav bar cycles between available languages;
   update it in `templates/layout.php` if a multi-option dropdown is preferred

---

## Adding a New Agent Endpoint

1. Create `agent/src/Endpoints/MyEndpoint.php`:
   ```php
   <?php
   declare(strict_types=1);
   namespace Cronmanager\Agent\Endpoints;

   class MyEndpoint {
       public function handle(array $params): void {
           // read query/body params, query DB, call jsonResponse()
           jsonResponse(200, ['data' => []]);
       }
   }
   ```

2. Register the route in `agent/agent.php`:
   ```php
   $router->addRoute('GET', '/my-endpoint', [MyEndpoint::class, 'handle']);
   ```

3. Add a method to `HostAgentClient` if the web UI needs to call the endpoint:
   ```php
   public function getMyData(): array {
       return $this->get('/my-endpoint');
   }
   ```

---

## Database Migrations

Schema migrations are plain SQL files in `agent/sql/migrations/`.
They use `IF NOT EXISTS` / `IF EXISTS` guards so they are safe to re-run.

**Naming convention:** `NNN_short_description.sql` (e.g. `004_add_job_priority.sql`)

**Applying a migration manually:**

```bash
ssh myserver 'docker exec -i cronmanager-db mariadb \
    -u cronmanager -p<password> cronmanager \
    < /opt/cronmanager/agent/sql/migrations/NNN_description.sql'
```

**Migration tracking via `schema_migrations`:**

Every applied migration is recorded in the `schema_migrations` table (filename + timestamp).
Both the Docker entrypoint and `simple_debian_setup.sh` check this table before applying a file,
so re-running the installer on an existing deployment is always safe. On a fresh install, all
bundled migration filenames are seeded into the table immediately after `schema.sql` is applied,
since all their changes are already included in the baseline schema.

**Existing migrations:**

| File | Change |
|---|---|
| `001_add_cron_list_page_size.sql` | Added `cron_list_page_size` column to `users` |
| `002_add_job_targets.sql` | Added `job_targets` table for multi-host support |
| `003_add_target_to_execution_log.sql` | Added `target` column to `execution_log` |
| `004_kill_and_limits.sql` | Added `pid`, `pid_file`, `notified_limit_exceeded` to `execution_log`; added `execution_limit_seconds`, `auto_kill_on_limit` to `cronjobs` |

Always apply migrations in order. The full schema in `agent/sql/schema.sql` reflects
the current state after all migrations and is used for fresh installations.
