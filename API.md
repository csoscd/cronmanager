# Cronmanager – External REST API

This document describes the external REST API introduced in version 4.1.0.
External applications authenticate with **API keys** (Bearer tokens) generated
in the web UI.  The API is served by the **web container** — the internal host
agent is never exposed to external callers.

---

## Table of Contents

1. [Authentication](#1-authentication)
2. [API Key Management (Web UI)](#2-api-key-management-web-ui)
3. [Scopes](#3-scopes)
4. [Base URL & Versioning](#4-base-url--versioning)
5. [Request & Response Format](#5-request--response-format)
6. [Error Responses](#6-error-responses)
7. [Quick Start](#7-quick-start)
8. [Endpoints – Jobs](#8-endpoints--jobs)
9. [Endpoints – Tags & Targets](#9-endpoints--tags--targets)
10. [Endpoints – Export](#10-endpoints--export)
11. [Endpoints – Maintenance](#11-endpoints--maintenance)
12. [Endpoints – Settings](#12-endpoints--settings)
13. [Endpoints – Timeline](#13-endpoints--timeline)
14. [Endpoints – Agents](#14-endpoints--agents)
15. [Endpoints – Audit Log](#15-endpoints--audit-log)
16. [Rate Limiting](#16-rate-limiting)
17. [What the API does NOT cover](#17-what-the-api-does-not-cover)
18. [Troubleshooting](#18-troubleshooting)
19. [Changelog](#19-changelog)

---

## 1. Authentication

Every API request must carry an `Authorization` header with the Bearer token:

```http
Authorization: Bearer cm_<your-api-key>
```

Keys are generated in the web UI under **API Keys** (available to every user).
The plain-text key is shown **exactly once** after creation — store it securely.
Only the SHA-256 hash of the key is persisted in the database.

### Key format

```
cm_<40 random URL-safe base64 characters>
```

Example:
```
cm_YOUR_API_KEY_HERE
```

### What causes a 401

| Condition | Error message |
|---|---|
| `Authorization` header missing | `"Missing API key"` |
| Key not found in database | `"Invalid API key"` |
| Key has expired (`expires_at` in the past) | `"API key expired"` |
| Caller IP not in IP whitelist | `"IP address not allowed"` |
| Required scope not granted | `"Insufficient scope"` (403) |

---

## 2. API Key Management (Web UI)

Keys are managed at `/api-keys` in the web UI.

| Route | Method | Description |
|---|---|---|
| `/api-keys` | GET | List all own API keys |
| `/api-keys/create` | GET | Show creation form |
| `/api-keys` | POST | Create a new key |
| `/api-keys/{id}/delete` | POST | Delete a key |

### Create form fields

| Field | Required | Description |
|---|---|---|
| `name` | yes | Human-readable label (e.g. "Grafana Monitor") |
| `scopes[]` | yes | One or more scope checkboxes (see §3) |
| `expires_at` | no | ISO-8601 date; empty = no expiry |
| `agent_ids[]` | no | Restrict to specific agents; empty = all agents |
| `ip_whitelist` | no | Comma-separated CIDR blocks, e.g. `10.0.0.0/8, 192.168.1.5/32` |

### Scope inheritance

A user can only grant scopes they are allowed to use themselves:

| User role | Grantable scopes |
|---|---|
| `view` | `jobs:read`, `maintenance:read`, `export:read`, `settings:read` |
| `admin` | all 8 scopes |

---

## 3. Scopes

| Scope | What it grants |
|---|---|
| `jobs:read` | List/view jobs, tags, execution history, monitor, timeline, swimlane |
| `jobs:write` | Create, edit, delete jobs; bulk activate/deactivate/delete/re-tag |
| `jobs:execute` | Trigger "Run Now"; kill a running execution |
| `export:read` | Download crontab as JSON, CSV, or cron format |
| `maintenance:read` | List maintenance windows; conflict check |
| `maintenance:write` | Create, edit, delete maintenance windows |
| `settings:read` | Read agent settings (mail, Telegram, InfluxDB, notifications) |
| `settings:write` | Update agent settings; resync crontab; cleanup operations |
| `audit:read` | Read the audit log — who changed what and when (admin-only scope) |
| `executions:acknowledge` | Acknowledge / unacknowledge failed executions |

### Pre-defined profiles

| Profile | Included scopes |
|---|---|
| `read-only` | `jobs:read`, `maintenance:read`, `export:read` |
| `operator` | `jobs:read`, `jobs:execute`, `maintenance:read`, `executions:acknowledge` |
| `developer` | `jobs:read`, `jobs:write`, `jobs:execute`, `export:read` |
| `full-admin` | all 10 scopes |

---

## 4. Base URL & Versioning

All API endpoints are prefixed with `/api/v1/`.

```
https://<your-cronmanager-host>/api/v1/
```

The version segment (`v1`) will be incremented for breaking changes.

### Agent scope

If a key is restricted to specific agents (`agent_ids`), requests that would
target a different agent return:

```json
{ "error": "Agent not permitted", "code": 403 }
```

The current agent is determined by the `X-Agent-Id` request header (optional).
If omitted, the default agent is used.

---

## 5. Request & Response Format

- All request bodies must be `application/json`.
- All responses are `application/json` with `charset=utf-8`.
- Timestamps are **ISO-8601** strings in UTC: `"2026-06-22T14:30:00Z"`.
- Boolean values are JSON booleans (`true` / `false`), not `0`/`1`.

### Pagination

Endpoints that return lists support optional query parameters:

| Parameter | Default | Description |
|---|---|---|
| `limit` | `100` | Maximum number of items (max: `500`) |
| `offset` | `0` | Skip this many items |

Paginated response envelope:

```json
{
  "agent_id": 1,
  "data": [ ... ],
  "count": 42,
  "limit": 100,
  "offset": 0
}
```

### `agent_id` field (since v4.6.1)

Every response from an agent-specific endpoint includes `"agent_id"` as the **first field**.
This resolves the ambiguity that job IDs are only unique per agent — in multi-agent setups,
the same numeric ID may refer to different jobs on different agents.

Endpoints that are not agent-specific (`GET /api/v1/agents`) do not carry `agent_id`.

---

## 6. Error Responses

All errors follow the same envelope:

```json
{
  "error": "Short error type",
  "message": "Human-readable explanation.",
  "code": 422
}
```

Validation errors include a `fields` object:

```json
{
  "error": "Validation failed",
  "fields": {
    "schedule": "Invalid cron expression.",
    "command": "Command must not be empty."
  },
  "code": 422
}
```

| HTTP status | Meaning |
|---|---|
| 200 | OK |
| 201 | Created |
| 400 | Bad request (malformed JSON, invalid parameter) |
| 401 | Unauthenticated (missing/invalid/expired key) |
| 403 | Forbidden (insufficient scope or agent restriction) |
| 404 | Resource not found |
| 409 | Conflict (e.g. duplicate) |
| 422 | Validation error |
| 500 | Internal server error |
| 502 | Agent unreachable (web container cannot reach host agent) |

---

## 7. Quick Start

This section shows the most common API calls end-to-end.

### Step 1 – Create an API key in the web UI

Open **API Keys** in the sidebar, click **Create new key**, choose a name and the scopes
you need, then click **Save**. Copy the displayed `cm_…` token — it will not be shown again.

### Step 2 – Set an environment variable

```bash
export CM_URL=https://cronmanager.example.com
export CM_KEY=cm_YOUR_API_KEY_HERE
```

### Step 3 – Try it

**List all active jobs:**
```bash
curl -s -H "Authorization: Bearer $CM_KEY" \
  "$CM_URL/api/v1/jobs?active=1" | jq '.data[] | {id, description, schedule}'
```

**Create a new job:**
```bash
curl -s -X POST \
  -H "Authorization: Bearer $CM_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "linux_user": "deploy",
    "schedule": "0 3 * * *",
    "command": "/opt/scripts/backup.sh",
    "description": "Nightly backup",
    "active": true,
    "targets": ["local"]
  }' \
  "$CM_URL/api/v1/jobs"
```

**Trigger a job immediately:**
```bash
curl -s -X POST \
  -H "Authorization: Bearer $CM_KEY" \
  "$CM_URL/api/v1/jobs/42/execute"
```

**Export all jobs as JSON:**
```bash
curl -s -H "Authorization: Bearer $CM_KEY" \
  "$CM_URL/api/v1/export?format=json" | jq .
```

**Target a specific agent (multi-agent setup):**
```bash
curl -s \
  -H "Authorization: Bearer $CM_KEY" \
  -H "X-Agent-Id: 2" \
  "$CM_URL/api/v1/jobs"
```

---

## 8. Endpoints – Jobs

Required scope for read operations: **`jobs:read`**
Required scope for write operations: **`jobs:write`**
Required scope for execute operations: **`jobs:execute`**

---

### GET /api/v1/jobs

List all cron jobs.

**Query parameters:**

| Parameter | Description |
|---|---|
| `tag` | Filter by tag name |
| `user` | Filter by Linux user |
| `target` | Filter by execution target |
| `active` | `1` = active only, `0` = inactive only |
| `limit` | Page size (default: 100, max: 500) |
| `offset` | Pagination offset |

**Response 200:**

```json
{
  "agent_id": 1,
  "data": [
    {
      "id": 1,
      "linux_user": "deploy",
      "schedule": "*/5 * * * *",
      "command": "/opt/scripts/backup.sh",
      "description": "Backup database",
      "active": true,
      "notify_on_failure": true,
      "notify_on_recovery": false,
      "notify_on_silence": false,
      "silence_grace_minutes": null,
      "last_silence_alert_at": null,
      "execution_limit_seconds": 300,
      "auto_kill_on_limit": false,
      "singleton": false,
      "run_in_maintenance": false,
      "retention_days": 30,
      "retry_count": 0,
      "retry_delay_minutes": 5,
      "restart_on_exitcodes": [],
      "notify_after_failures": 3,
      "notify_after_limit_exceeded": false,
      "execution_mode": "local",
      "ssh_host": null,
      "targets": ["local"],
      "tags": ["backup", "daily"],
      "created_at": "2026-01-15T08:00:00Z"
    }
  ],
  "count": 1,
  "limit": 100,
  "offset": 0
}
```

---

### GET /api/v1/jobs/{id}

Get a single cron job by ID.

**Response 200:** Single job object (same structure as in the list, without envelope, but with `agent_id` as the first field).

**Response 404:**

```json
{ "error": "Not Found", "message": "Cron job with ID 99 does not exist.", "code": 404 }
```

---

### POST /api/v1/jobs

Create a new cron job.  Scope: **`jobs:write`**

**Request body:**

```json
{
  "linux_user":                "deploy",
  "schedule":                  "0 3 * * *",
  "command":                   "/opt/scripts/backup.sh",
  "description":               "Nightly backup",
  "active":                    true,
  "targets":                   ["local"],
  "tags":                      ["backup"],
  "notify_on_failure":         true,
  "notify_on_recovery":        false,
  "notify_on_silence":         false,
  "silence_grace_minutes":     null,
  "execution_limit_seconds":   0,
  "auto_kill_on_limit":        false,
  "singleton":                 false,
  "run_in_maintenance":        false,
  "retention_days":            30,
  "retry_count":               0,
  "retry_delay_minutes":       5,
  "restart_on_exitcodes":      [],
  "notify_after_failures":     3,
  "notify_after_limit_exceeded": false
}
```

Required fields: `linux_user`, `schedule`, `command`, `targets` (non-empty array).

**Response 201:** Created job object.

---

### PUT /api/v1/jobs/{id}

Update a cron job.  Scope: **`jobs:write`**

Request body: same structure as POST (all fields optional except those noted).
Only fields present in the body are updated.

**Response 200:** Updated job object.

---

### DELETE /api/v1/jobs/{id}

Delete a cron job and remove it from the crontab.  Scope: **`jobs:write`**

**Response 200:**

```json
{ "agent_id": 1, "success": true }
```

---

### POST /api/v1/jobs/{id}/execute

Trigger an immediate one-time execution of the job.  Scope: **`jobs:execute`**

**Response 200:**

```json
{ "agent_id": 1, "success": true, "message": "Job queued for immediate execution." }
```

---

### POST /api/v1/executions/{id}/kill

Kill a running execution by its execution log ID.  Scope: **`jobs:execute`**

**Response 200:**

```json
{ "agent_id": 1, "success": true }
```

---

### POST /api/v1/executions/{id}/acknowledge

Mark a finished execution as acknowledged. Acknowledged failures are suppressed from
the dashboard's error indicators while the historical record stays intact.
Scope: **`executions:acknowledge`**

**Response 200:**

```json
{ "agent_id": 1, "acknowledged": true }
```

---

### DELETE /api/v1/executions/{id}/acknowledge

Clear the acknowledgement on a previously acknowledged execution. Scope: **`executions:acknowledge`**

**Response 200:**

```json
{ "agent_id": 1, "acknowledged": false }
```

---

### GET /api/v1/jobs/{id}/history

Execution history for a specific job.

**Query parameters:**

| Parameter | Description |
|---|---|
| `limit` | Page size (default: 50) |
| `offset` | Pagination offset |
| `status` | Filter: `success`, `failed`, `running` |

**Response 200:**

```json
{
  "data": [
    {
      "execution_id": 101,
      "job_id": 1,
      "linux_user": "deploy",
      "description": "Backup database",
      "schedule": "*/5 * * * *",
      "tags": ["backup"],
      "started_at": "2026-06-22T03:00:01Z",
      "finished_at": "2026-06-22T03:00:47Z",
      "exit_code": 0,
      "output": "Backup completed: 1.2 GB",
      "target": "local",
      "during_maintenance": false,
      "retry_attempt": 0,
      "retry_root_execution_id": null,
      "duration_seconds": 46,
      "acknowledged_at": null,
      "acknowledged_by_user_id": null
    }
  ],
  "count": 1,
  "limit": 50,
  "offset": 0
}
```

---

## 9. Endpoints – Tags & Targets

Required scope: **`jobs:read`**

---

### GET /api/v1/tags

List all tags.

**Response 200:**

```json
{
  "agent_id": 1,
  "data": [
    { "id": 1, "name": "backup" },
    { "id": 2, "name": "monitoring" }
  ],
  "count": 2
}
```

---

### GET /api/v1/linux-users

List all Linux users available for cron job scheduling on the selected agent,
together with a flag indicating whether the agent is running in a Docker
container.

In Docker mode only `root` is a valid user (container isolation). In host
mode the list contains all users from `/etc/passwd` with a valid username.

**Query parameters (all optional):**

| Parameter | Description |
|---|---|
| `agent_id` | Select a specific agent (defaults to the first enabled agent) |

**Response 200:**

```json
{
  "agent_id":    1,
  "docker_mode": false,
  "data":        ["deploy", "root"],
  "count":       2
}
```

---

### GET /api/v1/targets

List all distinct execution targets (e.g. `"local"` and SSH host aliases)
configured across cronjobs, with the count of jobs using each target.

**Query parameters (all optional):**

| Parameter | Description |
|---|---|
| `active` | `1` = only targets of active jobs, `0` = inactive only; omit for all |
| `agent_id` | Select a specific agent (defaults to the first enabled agent) |

**Response 200:**

```json
{
  "agent_id": 1,
  "data": [
    { "target": "local",    "job_count": 12 },
    { "target": "myserver", "job_count":  3 }
  ],
  "count": 2
}
```

---

## 10. Endpoints – Export

Required scope: **`export:read`**

---

### GET /api/v1/export

Export all cron jobs in the requested format.

**Query parameters:**

| Parameter | Values | Default |
|---|---|---|
| `format` | `json`, `csv`, `cron` | `json` |

**Query parameters (all optional):**

| Parameter | Description |
|---|---|
| `format` | `json` (default), `csv`, `cron` |
| `user` | Export only jobs for this Linux user |
| `tag` | Export only jobs carrying this tag |

**Response 200 (`format=json`):**

```json
{
  "export": {
    "generated_at": "2026-06-22T14:00:00Z",
    "user_filter": null,
    "tag_filter": null,
    "job_count": 6
  },
  "data": [ ... ],
  "count": 6
}
```

**Response 200 (`format=csv`):**

`Content-Type: text/csv` — CSV download with header row.  
Columns: `id`, `linux_user`, `schedule`, `command`, `description`, `active`, `tags` (pipe-separated), `targets` (pipe-separated), `notify_on_failure`, `execution_limit_seconds`, `retry_count`, `retry_delay_minutes`, `singleton`, `run_in_maintenance`, `retention_days`, `created_at`.

**Response 200 (`format=cron`):**

`Content-Type: text/plain` — ready-to-import crontab lines, grouped by Linux user.  
SSH-target jobs are wrapped as `ssh -o BatchMode=yes <host> '<command>'`.

---

## 11. Endpoints – Maintenance

Required scope for read: **`maintenance:read`**
Required scope for write: **`maintenance:write`**

### Maintenance Windows

---

### GET /api/v1/maintenance/windows

List all maintenance windows.

**Response 200:**

```json
{
  "agent_id": 1,
  "data": [
    {
      "id": 1,
      "target": "host1",
      "cron_schedule": "0 2 * * 0",
      "duration_minutes": 60,
      "description": "Weekly Sunday maintenance",
      "active": true,
      "created_at": "2026-03-01T00:00:00Z"
    }
  ],
  "count": 1
}
```

---

### GET /api/v1/maintenance/windows/{id}

Get a single maintenance window.

**Response 200:** Single window object (with `agent_id` as the first field).

---

### POST /api/v1/maintenance/windows

Create a maintenance window.  Scope: **`maintenance:write`**

**Request body:**

```json
{
  "target":           "host1",
  "cron_schedule":    "0 2 * * 0",
  "duration_minutes": 60,
  "description":      "Weekly Sunday maintenance",
  "active":           true
}
```

Required fields: `target`, `cron_schedule`, `duration_minutes`.

**Response 201:** Created window object.

---

### PUT /api/v1/maintenance/windows/{id}

Update a maintenance window.  Scope: **`maintenance:write`**

**Response 200:** Updated window object.

---

### DELETE /api/v1/maintenance/windows/{id}

Delete a maintenance window.  Scope: **`maintenance:write`**

**Response 200:**

```json
{ "agent_id": 1, "success": true }
```

---

### Maintenance Operations

Scope: **`maintenance:write`**

These endpoints trigger the same cleanup actions as the corresponding buttons
in the web UI under **Settings → Wartung**.

---

### POST /api/v1/maintenance/logs/purge

Immediately deletes finished `execution_log` rows that exceed the per-job or
global log retention period.  Equivalent to the "Logs jetzt bereinigen"
button.  Running executions are never deleted.

No request body required.

**Response 200:**

```json
{
  "agent_id": 1,
  "deleted_logs": 42,
  "deleted_retry_state": 0,
  "message": "Deleted 42 log row(s) and 0 retry state entr(y|ies)."
}
```

---

### POST /api/v1/maintenance/history/cleanup

Permanently deletes all finished `execution_log` rows older than the given
number of days.  Equivalent to the "Historien-Bereinigung" action.  Running
executions are never deleted.

**Request body (JSON, optional):**

```json
{ "older_than_days": 90 }
```

| Field | Type | Required | Default | Constraint |
|---|---|---|---|---|
| `older_than_days` | integer | no | 90 | ≥ 1 |

**Response 200:**

```json
{
  "agent_id": 1,
  "deleted": 1234,
  "older_than_days": 90,
  "message": "Deleted 1234 history record(s) older than 90 days."
}
```

**Error 400** — when `older_than_days` is provided but < 1:

```json
{ "error": "Bad Request", "message": "older_than_days must be a positive integer (≥ 1).", "code": 400 }
```

---

### POST /api/v1/maintenance/once/cleanup

Removes stale "run-once" crontab entries left behind by Run Now jobs whose
automatic self-cleanup call failed (e.g. the agent was temporarily
unreachable).  Equivalent to the "Run-Now-Bereinigung" button.

No request body required.

**Response 200:**

```json
{
  "agent_id": 1,
  "removed": 4,
  "users_affected": 1,
  "message": "Removed 4 stale Run Now entry(s) across 1 user(s)."
}
```

---

## 12. Endpoints – Settings

Required scope for read: **`settings:read`**
Required scope for write: **`settings:write`**

Settings are grouped into sections: `mail`, `telegram`, `influxdb`, `notifications`, `performance_monitor`, `web`.

The `web` section is **read-only via the API** — it is populated automatically when the web container
pushes its identity to the agent (startup, agent create/update/select).

---

### GET /api/v1/settings

Read all agent settings.

**Response 200:**

```json
{
  "mail": {
    "enabled": true,
    "from": "cronmanager@example.com",
    "to": "admin@example.com",
    "host": "smtp.example.com",
    "port": 587,
    "username": "cronmanager@example.com",
    "encryption": "tls"
  },
  "telegram": {
    "enabled": false,
    "bot_token": "",
    "chat_id": ""
  },
  "influxdb": {
    "enabled": false,
    "url": "",
    "token": "",
    "org": "",
    "bucket": ""
  },
  "web": {
    "web_agent_id": 3,
    "web_url": "https://cronmanager.example.com"
  }
}
```

---

### GET /api/v1/settings/{section}

Read a single settings section (`mail`, `telegram`, `influxdb`, `notifications`, `web`).

**Response 200:** Section object.

---

### PUT /api/v1/settings/{section}

Update a settings section.  Scope: **`settings:write`**

Writable sections: `mail`, `telegram`, `influxdb`, `notifications`, `performance_monitor`.
The `web` section is silently skipped — use the agent identity push instead.

**Request body:** Partial or full section object (only provided keys are updated).

**Response 200:** Updated section object.

---

### POST /api/v1/settings/resync

Resync crontab from database.  Scope: **`settings:write`**

**Response 200:**

```json
{ "agent_id": 1, "success": true, "message": "Crontab resynced." }
```

---

## 13. Endpoints – Timeline

Required scope: **`jobs:read`**

---

### GET /api/v1/timeline

Execution history across all jobs.

**Query parameters:**

| Parameter | Description |
|---|---|
| `limit` | Page size (default: 100) |
| `offset` | Pagination offset |
| `status` | Filter: `success`, `failed`, `running` |
| `tag` | Filter by job tag |

**Response 200:**

```json
{
  "agent_id": 1,
  "data": [
    {
      "execution_id": 101,
      "job_id": 1,
      "description": "Nightly backup",
      "linux_user": "deploy",
      "schedule": "0 3 * * *",
      "tags": ["backup"],
      "started_at": "2026-06-22T03:00:01Z",
      "finished_at": "2026-06-22T03:00:47Z",
      "exit_code": 0,
      "target": "local",
      "during_maintenance": false,
      "retry_attempt": 0,
      "retry_root_execution_id": null,
      "duration_seconds": 46
    }
  ],
  "count": 1,
  "limit": 100,
  "offset": 0
}
```

---

## 14. Endpoints – Agents

Required scope: **`settings:read`**

If the API key has an `agent_ids` restriction, only those agents are returned.
Sensitive connection fields (`url`, `hmac_secret`, `ssl_ca_bundle`) are never included
in API responses.

---

### GET /api/v1/agents

List all agents visible to this API key.

**Response 200:**

```json
{
  "data": [
    {
      "id": 1,
      "name": "Default",
      "description": "Lokaler Agent",
      "enabled": true,
      "web_url": "https://cronmanager.example.com"
    },
    {
      "id": 2,
      "name": "Remote-Server",
      "description": "Agent auf server2.example.com",
      "enabled": true,
      "web_url": "https://cronmanager.example.com"
    }
  ],
  "count": 2
}
```

`web_url` is the public base URL of the web container (from `app.web_url` in the web config).
It is the same value for all agents — `null` when not configured.

**Response 401 / 403:** See §6 Error Responses.

---

## 15. Endpoints – Audit Log

Required scope: **`audit:read`**

This scope can only be granted by admin users. It provides read-only access to the audit log,
which records all create, update, and delete operations performed in the UI or via the API —
including before/after diffs for updates and snapshots for creates and deletes.

---

### GET /api/v1/audit

Return a paginated list of audit log entries with optional filters.

**Query parameters:**

| Parameter | Type | Default | Description |
|---|---|---|---|
| `limit` | int | `100` | Max entries per page (max: `500`) |
| `offset` | int | `0` | Skip this many entries |
| `username` | string | — | Filter by exact username |
| `action_prefix` | string | — | Filter by action prefix (e.g. `cron` matches `cron.create`, `cron.update`, …) |
| `date_from` | string | — | Lower bound on `created_at` (`YYYY-MM-DD` or `YYYY-MM-DD HH:MM:SS`) |
| `date_to` | string | — | Upper bound on `created_at` |

**Response 200:**

```json
{
  "agent_id": 1,
  "data": [
    {
      "id": 42,
      "user_id": 1,
      "username": "admin",
      "action": "cron.update",
      "resource_type": "cron",
      "resource_id": 17,
      "resource_label": "Nightly backup",
      "details": { "schedule": "0 2 * * * → 0 3 * * *" },
      "ip_address": "10.0.0.5",
      "created_at": "2026-06-24 14:30:00"
    }
  ],
  "total": 1,
  "limit": 100,
  "offset": 0
}
```

**Known action names:**

| Action | Trigger |
|---|---|
| `cron.create` | New job created (snapshot of initial settings) |
| `cron.update` | Job settings changed (only the changed fields, old → new) |
| `cron.delete` | Job deleted (snapshot of settings at deletion time) |
| `cron.bulk_status` | Bulk activate/deactivate |
| `cron.bulk_delete` | Bulk delete |
| `cron.bulk_tag` | Bulk re-tag |
| `cron.execute_now` | "Run Now" triggered |
| `cron.kill` | Running execution killed |
| `execution.acknowledged` | Execution marked as acknowledged |
| `execution.unacknowledged` | Acknowledgement cleared |
| `maintenance_window.create` | Maintenance window created (snapshot) |
| `maintenance_window.update` | Maintenance window settings changed (diff) |
| `maintenance_window.delete` | Maintenance window deleted (snapshot) |
| `tag.create` | Tag created |
| `tag.delete` | Tag deleted |
| `settings.update` | Agent settings changed (section names only; no credentials logged) |
| `user.update_role` | User role changed (`from`/`to` in `details`) |
| `user.delete` | User account deleted |

**Response 401 / 403:** See §6 Error Responses.

---

## 16. Rate Limiting

The external API currently applies **no rate limiting** at the application level. In production
deployments it is strongly recommended to configure rate limiting at the reverse proxy or
load-balancer tier (e.g. nginx `limit_req`, Caddy rate-limit middleware, or a WAF) to protect
against brute-force token guessing and unintended denial-of-service from misconfigured scripts.

---

## 17. What the API does NOT cover

The following operations are intentionally only available through the web UI and cannot be
performed via the REST API:

| Operation | Reason |
|---|---|
| Create / edit / delete users | User management is always session-based; admin intent must be explicit |
| Add / edit / delete agents | Agent configuration changes affect the entire installation |
| Manage API keys | An API key cannot create or revoke other API keys |
| Change login credentials | Password changes require the current password (security policy) |
| OIDC / SSO configuration | Sensitive IdP credentials are never exposed over the API |

---

## 18. Troubleshooting

### `401 Unauthorized` – "Missing API key"

The `Authorization` header is missing or malformed. Check that you are sending:
```http
Authorization: Bearer cm_<your-key>
```
Note: the `Bearer ` prefix (including the space) is required.

### `401 Unauthorized` – "Invalid API key"

The token does not match any stored hash. Possible causes:
- The key was deleted in the UI.
- A typo or truncation in the token value.
- The key was issued for a different Cronmanager instance.

### `401 Unauthorized` – "API key expired"

The key's `expires_at` date has passed. Create a new key in the web UI.

### `403 Forbidden` – "IP address not allowed"

Your client IP is not in the key's IP whitelist. Either update the whitelist in the UI or
add the calling machine's IP in CIDR notation (e.g. `192.168.1.10/32`).

### `403 Forbidden` – "Insufficient scope"

The key does not have the scope required by the endpoint. Delete the key and create a new
one with the appropriate scope (scopes cannot be edited after creation).

### `502 Bad Gateway` – "Agent unreachable"

The web container cannot reach the host agent. Check:
1. The agent container / service is running (`docker ps` or `systemctl status cronmanager-agent`).
2. The agent URL in the web config (`/var/www/conf/config.json`) is correct.
3. No firewall rule blocks the internal Docker network traffic.

### API calls succeed but jobs don't run

`POST /api/v1/jobs/{id}/execute` queues an immediate one-time cron entry. The entry is
executed by the cron daemon inside the agent container, not in real time. There may be up
to 60 seconds of delay before the daemon picks up the entry.

---

## 19. Changelog

| Version | Change |
|---|---|
| 4.8.0 | Added `GET /api/v1/targets` (§9) — distinct execution targets with job counts; optional `?active=` filter. Added `GET /api/v1/linux-users` (§9) — available Linux users for cron scheduling; includes `docker_mode` flag. Added three maintenance operation endpoints (§11): `POST /api/v1/maintenance/logs/purge`, `POST /api/v1/maintenance/history/cleanup` (optional `older_than_days`), `POST /api/v1/maintenance/once/cleanup`. All require `maintenance:write` scope. |
| 4.6.1 | Every agent-specific endpoint now includes `"agent_id"` as the first field in its response (jobs, maintenance, export/json, audit, settings, timeline, tags). Resolves ambiguity in multi-agent setups where the same numeric job ID may refer to different jobs on different agents. UI links (notifications, breadcrumbs, filter resets, pagination) now carry `?agent_id=X` throughout. |
| 4.6.0 | Added `web` section to `GET /api/v1/settings` (read-only, push-managed; contains `web_agent_id` and `web_url`); added `web_url` field to `GET /api/v1/agents` response; `PUT /api/v1/settings` silently ignores the `web` section |
| 4.5.0 | Added `notify_on_silence` (bool), `silence_grace_minutes` (int\|null), `last_silence_alert_at` (string\|null, read-only) to job objects; `GET /health` extended with `silent_jobs` (int\|null) and `last_execution_at` (string\|null) |
| 4.3.4 | Added `GET /api/v1/audit` endpoint (`audit:read` scope, admin-only); added §15 Audit Log |
| 4.2.0 | Added `GET /api/v1/agents` endpoint (`settings:read` scope; respects `agent_ids` restriction; omits sensitive fields) |
| 4.1.0 | Initial external REST API with API key authentication and scope-based authorization |
