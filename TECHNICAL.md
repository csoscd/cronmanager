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
9. [Configuration Reference](#configuration-reference)
10. [Deployment Script](#deployment-script)
11. [Logging](#logging)
12. [Internationalisation](#internationalisation)
13. [Adding a New Language](#adding-a-new-language)
14. [Adding a New Agent Endpoint](#adding-a-new-agent-endpoint)
15. [Database Migrations](#database-migrations)

---

## System Overview

Cronmanager consists of three runtime components:

| Component | Runtime | Location on host |
|---|---|---|
| **Web UI** | PHP-FPM 8.4 + Nginx, Docker container | `/opt/websites/cronmanager/www` |
| **Host Agent** | PHP 8.4 CLI built-in server, systemd service | `/opt/phpscripts/cronmanager/agent` |
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

The web container reaches the host agent via `host.docker.internal:8865`, which is
provided by Docker's `extra_hosts: host-gateway` mechanism.

---

## Directory Layout

```
/opt/dev/cronmanager/          ← development root
├── composer.json              ← shared PHP dependencies
├── deploy.sh                  ← deployment script
├── deploy.env[.example]       ← deployment configuration
├── db.credentials[.example]   ← database passwords (not in VCS)
├── docker-compose.yml
├── README.md
├── TECHNICAL.md
│
├── agent/                     ← host agent source
│   ├── agent.php              ← CLI entry point
│   ├── config/config.json     ← agent configuration
│   ├── bin/
│   │   ├── cron-wrapper.sh    ← injected into every crontab entry
│   │   ├── start-agent.sh     ← manual start helper
│   │   └── create-admin.php   ← CLI tool: create first admin
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
│           ├── TagListEndpoint.php
│           ├── TagCreateEndpoint.php
│           ├── TagDeleteEndpoint.php
│           ├── SshHostsEndpoint.php
│           ├── HistoryEndpoint.php
│           └── ExportEndpoint.php
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
    │   ├── export.php
    │   ├── error.php
    │   ├── cron/
    │   │   ├── list.php
    │   │   ├── detail.php
    │   │   ├── form.php
    │   │   └── import.php
    │   └── users/list.php
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
            ├── ExportController.php
            └── UserController.php
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
| Frontend | Tailwind CSS (local copy, no build step) | 3.4.x |
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
6. Execute command:
     target = "local"          → eval "<command>"
     target = "<ssh-alias>"    → ssh -o BatchMode=yes <alias> -- <command>
7. Capture stdout + stderr combined, truncate at 50,000 bytes
8. POST /execution/finish  → report exit_code, output, finished_at
9. Exit with the original command's exit code
```

The wrapper logs to stderr (which is delivered by cron as a mail to the Linux user if
cron mail is configured). It does not abort if the agent is unreachable — the command
runs regardless and a best-effort finish report is sent.

**Dependencies:** `bash 4+`, `curl`, `openssl`, `php`

### MailNotifier

`src/Notification/MailNotifier.php` sends failure alerts via SMTP using PHPMailer.
It is invoked by `ExecutionFinishEndpoint` when:
- The job's `notify_on_failure` flag is `1`, and
- The exit code is non-zero, and
- `mail.enabled` is `true` in the agent config

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
*/5 * * * *  /opt/phpscripts/cronmanager/agent/bin/cron-wrapper.sh  42  local
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
`/opt/websites/cronmanager/conf/config.json`) and a `Monolog\Logger`.

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
| `CronController` | `GET /crons`, `GET /crons/{id}`, `GET/POST /crons/new`, `GET/POST /crons/{id}/edit`, `POST /crons/{id}/delete`, `GET/POST /crons/import` | view/admin |
| `TimelineController` | `GET /timeline` | view |
| `ExportController` | `GET /export`, `GET /export/download` | view |
| `UserController` | `GET /users`, `POST /users/{id}/role`, `POST /users/{id}/delete` | admin |

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
| `notify_on_failure` | TINYINT(1) | Send email on failure |
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
| `exit_code` | INT NULL | NULL while running |
| `output` | TEXT NULL | Truncated at 50,000 bytes |
| `target` | VARCHAR(255) NULL | `"local"`, SSH alias, or NULL (pre-migration rows) |

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

### OIDC PKCE + state

- `code_verifier` is a cryptographically random 64-byte base64url string
- `code_challenge = base64url(sha256(code_verifier))`
- `state` is a cryptographically random 16-byte hex string
- Both are stored in the PHP session and verified during callback processing

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

`deploy.sh` reads `deploy.env` and `db.credentials` and supports two transport modes
and two deployment modes.

### Transport modes (set in `deploy.env`)

| `DEPLOY_TYPE` | Behaviour |
|---|---|
| `SSH` | All file transfers via `rsync -e ssh` and `scp`; commands via `ssh HOST "cmd"` |
| `LOCAL` | All operations run locally; no SSH involved |

### Deployment modes (CLI argument)

| Argument | Behaviour |
|---|---|
| `full` | Creates directories + full rsync mirror (`--delete`); deploys example configs if absent |
| `update` | rsync with `--checksum` (only changed files); config files are never overwritten |

### Selective deployment

```bash
./deploy.sh update --agent   # deploy only the host agent
./deploy.sh update --web     # deploy only the web application
./deploy.sh full             # deploy both (default)
```

### Composer handling

On every deployment, `deploy.sh` checks whether `${DEPLOY_COMPOSER}/composer.json`
exists on the target:

- **Absent:** the project's `composer.json` is copied there, and a reminder to run
  `composer install` is printed
- **Present:** the required libraries from the project's `composer.json` are printed
  as informational output so the operator can verify they are installed

### SSH host override

If `DEPLOY_TYPE=SSH`, the SSH host from `deploy.env` can be overridden on the command line:

```bash
./deploy.sh update staging   # deploy to the "staging" SSH host alias
```

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
**Agent log path (on host):** `/opt/phpscripts/log/cronmanager-agent.log`

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
    < /opt/phpscripts/cronmanager/agent/sql/migrations/NNN_description.sql'
```

**Existing migrations:**

| File | Change |
|---|---|
| `001_add_cron_list_page_size.sql` | Added `cron_list_page_size` column to `users` |
| `002_add_job_targets.sql` | Added `job_targets` table for multi-host support |
| `003_add_target_to_execution_log.sql` | Added `target` column to `execution_log` |

Always apply migrations in order. The full schema in `agent/sql/schema.sql` reflects
the current state after all migrations and is used for fresh installations.
