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
7. [Endpoints – Jobs](#7-endpoints--jobs)
8. [Endpoints – Tags](#8-endpoints--tags)
9. [Endpoints – Export](#9-endpoints--export)
10. [Endpoints – Maintenance Windows](#10-endpoints--maintenance-windows)
11. [Endpoints – Settings](#11-endpoints--settings)
12. [Endpoints – Timeline](#12-endpoints--timeline)
13. [Changelog](#13-changelog)

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
cm_aB3xQ7rLmP2kZw9sYvUd1nEjTfHgCo6R8iN0pA4e
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

### Pre-defined profiles

| Profile | Included scopes |
|---|---|
| `read-only` | `jobs:read`, `maintenance:read`, `export:read` |
| `operator` | `jobs:read`, `jobs:execute`, `maintenance:read` |
| `developer` | `jobs:read`, `jobs:write`, `jobs:execute`, `export:read` |
| `full-admin` | all 8 scopes |

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
  "data": [ ... ],
  "count": 42,
  "limit": 100,
  "offset": 0
}
```

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

## 7. Endpoints – Jobs

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

**Response 200:** Single job object (same structure as in the list, without envelope).

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
{ "success": true }
```

---

### POST /api/v1/jobs/{id}/execute

Trigger an immediate one-time execution of the job.  Scope: **`jobs:execute`**

**Response 200:**

```json
{ "success": true, "message": "Job queued for immediate execution." }
```

---

### POST /api/v1/executions/{id}/kill

Kill a running execution by its execution log ID.  Scope: **`jobs:execute`**

**Response 200:**

```json
{ "success": true }
```

---

### GET /api/v1/jobs/{id}/history

Execution history for a specific job.

**Query parameters:**

| Parameter | Description |
|---|---|
| `limit` | Page size (default: 50) |
| `offset` | Pagination offset |
| `status` | Filter: `success`, `failure`, `running` |

**Response 200:**

```json
{
  "data": [
    {
      "id": 101,
      "cronjob_id": 1,
      "started_at": "2026-06-22T03:00:01Z",
      "finished_at": "2026-06-22T03:00:47Z",
      "exit_code": 0,
      "output": "Backup completed: 1.2 GB",
      "target": "local",
      "pid": 12345,
      "during_maintenance": false,
      "retry_attempt": 0
    }
  ],
  "count": 1,
  "limit": 50,
  "offset": 0
}
```

---

## 8. Endpoints – Tags

Required scope: **`jobs:read`**

---

### GET /api/v1/tags

List all tags.

**Response 200:**

```json
{
  "data": [
    { "id": 1, "name": "backup" },
    { "id": 2, "name": "monitoring" }
  ],
  "count": 2
}
```

---

## 9. Endpoints – Export

Required scope: **`export:read`**

---

### GET /api/v1/export

Export all cron jobs in the requested format.

**Query parameters:**

| Parameter | Values | Default |
|---|---|---|
| `format` | `json`, `csv`, `cron` | `json` |

**Response 200 (`format=json`):**

```json
{
  "exported_at": "2026-06-22T14:00:00Z",
  "jobs": [ ... ]
}
```

**Response 200 (`format=csv`):**

`Content-Type: text/csv` with a CSV download.

**Response 200 (`format=cron`):**

`Content-Type: text/plain` — raw crontab lines.

---

## 10. Endpoints – Maintenance Windows

Required scope for read: **`maintenance:read`**
Required scope for write: **`maintenance:write`**

---

### GET /api/v1/maintenance/windows

List all maintenance windows.

**Response 200:**

```json
{
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

**Response 200:** Single window object.

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
{ "success": true }
```

---

## 11. Endpoints – Settings

Required scope for read: **`settings:read`**
Required scope for write: **`settings:write`**

Settings are grouped into sections: `mail`, `telegram`, `influxdb`, `notifications`.

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
  "notifications": {
    "web_url": "https://cronmanager.example.com"
  }
}
```

---

### GET /api/v1/settings/{section}

Read a single settings section (`mail`, `telegram`, `influxdb`, `notifications`).

**Response 200:** Section object.

---

### PUT /api/v1/settings/{section}

Update a settings section.  Scope: **`settings:write`**

**Request body:** Partial or full section object (only provided keys are updated).

**Response 200:** Updated section object.

---

### POST /api/v1/settings/resync

Resync crontab from database.  Scope: **`settings:write`**

**Response 200:**

```json
{ "success": true, "message": "Crontab resynced." }
```

---

## 12. Endpoints – Timeline

Required scope: **`jobs:read`**

---

### GET /api/v1/timeline

Execution history across all jobs.

**Query parameters:**

| Parameter | Description |
|---|---|
| `limit` | Page size (default: 100) |
| `offset` | Pagination offset |
| `status` | Filter: `success`, `failure`, `running` |
| `tag` | Filter by job tag |

**Response 200:**

```json
{
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
      "during_maintenance": false
    }
  ],
  "count": 1,
  "limit": 100,
  "offset": 0
}
```

---

## 13. Changelog

| Version | Change |
|---|---|
| 4.1.0 | Initial external REST API with API key authentication and scope-based authorization |
