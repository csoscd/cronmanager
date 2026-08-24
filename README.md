# Cronmanager

A modern, web-based cron job management UI for Linux systems. Cronmanager lets you create,
edit, monitor, and export cron jobs through a clean browser interface, with full execution
history, email failure alerts, execution limits, multi-host support, and SSO integration.

---

## Support me

[![ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/O5O21U13R9)

---

## Table of Contents

1. [Features](#features)
2. [Architecture Overview](#architecture-overview)
3. [Docker Hub – Recommended Installation](#docker-hub--recommended-installation)
   - [Environment variables reference](#environment-variables-reference)
4. [Prerequisites](#prerequisites)
5. [Guided Setup (Alternative)](#guided-setup-alternative)
6. [Quick Start](#quick-start)
7. [Detailed Installation](#detailed-installation)
8. [OIDC / SSO Setup](#oidc--sso-setup) → [SSO.md](SSO.md)
9. [Configuration Reference](#configuration-reference)
10. [Agent TLS](#agent-tls)
11. [Failure Alerts](#failure-alerts-email--telegram) → [ALERTS.md](ALERTS.md)
12. [InfluxDB Metrics](#influxdb-metrics) → [INFLUXDB.md](INFLUXDB.md)
13. [Multi-Host Execution](#multi-host-execution) → [MULTI-HOST.md](MULTI-HOST.md)
14. [Crontab Import](#crontab-import)
15. [Reading the Crontab](#reading-the-crontab)
16. [Settings](#settings)
17. [Multi-Agent Setup](#multi-agent-setup) → [MULTI-AGENT.md](MULTI-AGENT.md)
18. [Maintenance Windows](#maintenance-windows)
19. [Export](#export)
20. [User Management](#user-management)
21. [External REST API](#external-rest-api)
22. [Updating](#updating)
23. [Troubleshooting](#troubleshooting) → [TROUBLESHOOTING.md](TROUBLESHOOTING.md)

---

## Features

| Feature | Description |
|---|---|
| **Job management** | Create, edit, copy, and delete cron jobs with schedule, command, description, and tags |
| **Execution tracking** | Every job run is recorded: start time, end time, exit code, and captured stdout/stderr output |
| **Execution limits** | Optional maximum runtime per job; alert and/or auto-kill when the limit is exceeded |
| **Kill running execution** | Admins can terminate a running job mid-flight from the detail page (local: SIGTERM; SSH: remote kill) |
| **Singleton mode** | Flag a job so that new executions are silently skipped while a previous instance is still running |
| **Job monitor** | Per-job statistics page with KPI cards (success rate, avg/min/max duration, alerts), an execution duration line chart, and a stacked bar chart – selectable time window from 1 hour to 1 year; period and target switching updates in-place via AJAX with auto-refresh for short windows |
| **Dashboard** | At-a-glance view of total jobs, active/inactive counts, and recent failures; KPI cards refresh every 60 s via AJAX |
| **Bulk operations** | Select multiple jobs on the list page to activate, deactivate, delete, or re-tag them in a single action; running executions block bulk delete with a clear error message |
| **Timeline** | Filterable, paginated history of all executions across all jobs |
| **Swimlane** | Visual schedule overview: planned fire times per job across a time-of-day axis, filterable by hour range, day of week, tag, and target |
| **Multi-host execution** | A single job can run on multiple targets (local + remote SSH) in parallel |
| **Tags** | Label jobs to enable filtering and grouped export |
| **Crontab import** | Detect and import existing unmanaged crontab entries |
| **Export** | Download a ready-to-use crontab file or JSON for all managed jobs |
| **Auto-retry on failure** | Automatically re-run a failed job up to N times with a configurable delay between attempts; notification is suppressed until all retries are exhausted |
| **Exit-code filter for restart** | Optionally restrict which exit codes trigger an automatic retry using a flexible expression such as `1-5,10,255`; empty (default) means any non-zero code |
| **Email alerts** | Receive an email when a job exits with a non-zero status or exceeds its execution limit |
| **Telegram alerts** | Receive a Telegram message for the same events via the Bot API |
| **Recovery notifications** | Optionally receive an email and/or Telegram message when a job succeeds again after a failure streak that triggered an alert |
| **Silence detection** | Opt-in per job: `check-limits.php` uses the cron schedule to calculate the last expected start time and alerts (email + Telegram) if no real execution has been recorded within the schedule interval plus a configurable grace period. Three maintenance-window guards prevent false positives. `GET /health` exposes a `silent_jobs` counter for external monitors |
| **Maintenance Windows** | Define per-target scheduled maintenance windows; jobs are either skipped (exit code −4) or executed silently depending on the per-job setting. A special **"Cronmanager Agent"** target blocks all executions host-wide (useful for VM maintenance cycles). Conflict icons (⚠ amber / ✕ red) appear in the job list and detail view |
| **SSH connectivity test** | A **Test** button on the Maintenance Windows page verifies that the agent can reach an SSH target via key-based auth (`BatchMode=yes`, 10 s timeout). The result (Connected / Failed) is shown inline without a page reload |
| **Startup orphan cleanup** | On agent restart, executions still marked as "running" with no live process are automatically resolved to exit code −5 ("Interrupted by system restart") |
| **Multi-agent** | Manage cron jobs across multiple agents (different hosts) from a single web UI; switch the active agent per user session via a sidebar dropdown |
| **Settings** | Agent management, crontab sync, stuck-execution cleanup, and history bulk-delete |
| **Local & SSO auth** | Username/password accounts or OAuth 2.0 / OpenID Connect (OIDC) via Authentik |
| **Role-based access** | Four roles: `admin` (full access), `operator` (manage jobs, no user/settings admin), `viewer` (read-only), `api-only` (API access only, no web UI login) |
| **User management** | Admins can create, edit, deactivate, and delete users; invite new users via email; restrict each user to specific agents |
| **Invitation flow** | Send a one-time invite link via SMTP; the invited user sets their own password on first login |
| **Password reset** | Self-service password reset via email (requires SMTP configuration on the web container) |
| **Profile page** | Every user can change their own email and password at `/profile` (SSO users manage credentials through their IdP) |
| **Audit log** | Every create, update, and delete operation is recorded with actor, timestamp, and a before/after diff or snapshot; viewable in the web UI (`/audit`, admin-only) and via the REST API (`audit:read` scope) |
| **External REST API** | Scope-based JSON API for external applications; authenticated via Bearer tokens generated in the web UI — see [API.md](API.md) |
| **Performance Monitor** | Optionally persist per-request and per-query timing data to a `performance_log` table; optionally display the last API and DB durations in the UI footer — both toggles are independent and configurable under Settings → Agent Settings |
| **Internationalisation** | English and German out of the box; easy to extend |
| **Dark mode** | System-preference aware, toggle in the nav bar |

---

## Architecture Overview

Cronmanager supports two deployment modes.

### Host-agent mode

```
Browser
  │
  ▼
┌──────────────────────────┐
│  Web UI (Docker)         │  PHP-FPM + Nginx  ·  Port 8880
│  /opt/cronmanager/www    │
└────────────┬─────────────┘
             │ HMAC-signed HTTPS (host.docker.internal:8865)
             ▼
┌──────────────────────────┐
│  Host Agent              │  nginx (TLS) → PHP CLI server  ·  Port 8865
│  /opt/cronmanager/agent  │  systemd service on the Docker host
└────────────┬─────────────┘
             │ reads/writes crontab files
             │ reports execution results via PDO
             ▼
     Linux cron daemon          MariaDB container (cronmanager-db)
```

The agent runs directly on the Docker host. The web container reaches it via
`host.docker.internal:8865` (provided by Docker's `extra_hosts: host-gateway` mechanism).
Communication is encrypted with TLS; see [Agent TLS](#agent-tls) for details.

### Docker mode

```
Browser
  │
  ▼
┌──────────────────────────┐
│  Web UI (Docker)         │  PHP-FPM + Nginx  ·  Port 8880
│  /opt/cronmanager/www    │
└────────────┬─────────────┘
             │ HMAC-signed HTTPS (cronmanager-agent:8865)
             ▼
┌──────────────────────────┐
│  Agent container         │  nginx (TLS) → PHP CLI server  ·  Port 8865
│  cs1711/cs_cronmanageragent  (internal Docker network)
└────────────┬─────────────┘
             │ manages container's crontab (root)
             │ reports execution results via PDO
             ▼
     Container cron daemon     MariaDB container (cronmanager-db)
```

In docker mode the agent runs in its own container alongside the web UI.
All three services share a private `cronmanager-internal` Docker network.
No PHP installation is required on the host.

The web container never touches crontab files directly.
All privileged operations are delegated to the agent via HMAC-secured HTTPS calls.

A MariaDB container (`cronmanager-db`) stores users, job metadata, tags, and execution logs.

---

## Docker Hub – Recommended Installation

The simplest way to run Cronmanager is to pull the pre-built images directly from Docker Hub.
No cloning, no Composer, no PHP on the host — just Docker.

### What you need

| Requirement | Notes |
|---|---|
| Docker + Docker Compose v2 | Any recent Linux host |
| 5 environment variables | See table below |

### Three-step setup

**Step 1 – Create a working directory and a `.env` file**

```bash
mkdir cronmanager && cd cronmanager
cat > .env <<'EOF'
DB_NAME=cronmanager
DB_USER=cronmanager
DB_PASSWORD=change-me
DB_ROOT_PASSWORD=change-me-root
AGENT_HMAC_SECRET=$(openssl rand -hex 32)
EOF
```

> Tip: run `openssl rand -hex 32` separately and paste the output into `AGENT_HMAC_SECRET`.

**Step 2 – Download the Compose file**

```bash
curl -fsSL https://raw.githubusercontent.com/csoscd/cronmanager/main/docker/docker-compose-full.yml \
    -o docker-compose-full.yml
```

**Step 3 – Start the stack**

```bash
docker compose -f docker-compose-full.yml up -d
```

Open `http://<your-host>:8880/` — the setup wizard appears on first visit and
lets you create the initial admin account.

### What the stack creates

| Container | Image | Purpose |
|---|---|---|
| `cronmanager-db` | `mariadb:lts` | Stores users, jobs, and execution history |
| `cronmanager-agent` | `cs1711/cronmanager-agent:latest` | Manages crontabs, runs jobs, exposes HMAC API |
| `cronmanager-web` | `cs1711/cronmanager-web:latest` | PHP-FPM + Nginx web UI |

All persistent data lives in **Docker-managed named volumes** (`db-data`, `agent-log`, `web-log`).

> **Note:** With the default named volumes, log files live inside Docker-managed storage and are not directly readable on the host filesystem. To access them at a regular host path (e.g. for log forwarding or `tail -f`), replace the named volume with a bind mount. `docker-compose-full.yml` contains the required lines as commented-out alternatives — see the `volumes:` section of `cronmanager-agent` and `cronmanager-web`.

### Available image tags

| Tag | Built from | Use for |
|---|---|---|
| `latest` | `main` branch (on every release) | Production — always stable |
| `2.5.0`, `2.4.0`, … | Git tag on `main` | Pinning to a specific release |
| `dev` | Latest development branch push | Testing unreleased features |

> **Warning:** The `:dev` tag is overwritten on every push to any active development
> branch. It may contain incomplete features, breaking changes, or unstable code.
> **Never use `:dev` in production.**

To use a specific version, replace `:latest` in `docker-compose-full.yml`:
```yaml
image: cs1711/cronmanager-agent:2.5.0
image: cs1711/cronmanager-web:2.5.0
```
No host directory mounts are needed.  Optional host-path alternatives are available as
commented-out lines in `docker-compose-full.yml`.

### Environment variables reference

#### Required (both containers share `AGENT_HMAC_SECRET` and `DB_PASSWORD`)

| Variable | Description |
|---|---|
| `AGENT_HMAC_SECRET` | Shared HMAC-SHA256 signing secret (generate with `openssl rand -hex 32`) |
| `DB_PASSWORD` | MariaDB application user password |
| `DB_ROOT_PASSWORD` | MariaDB root password (MariaDB container only) |
| `DB_NAME` | Database name (default: `cronmanager`) |
| `DB_USER` | Database user (default: `cronmanager`) |

#### Agent container optional variables

| Variable | Default | Description |
|---|---|---|
| `AGENT_BIND_ADDRESS` | `0.0.0.0` | Bind address for the PHP HTTP server |
| `AGENT_PORT` | `8865` | Listening port (used by nginx TLS terminator) |
| `AGENT_TLS_ENABLED` | `true` | Enable nginx TLS reverse proxy (set `false` only for trusted internal networks) |
| `TLS_CERT_FILE` | `/opt/cronmanager/agent/tls/cert.pem` | Path to TLS certificate inside the container (auto-generated self-signed if absent) |
| `TLS_KEY_FILE` | `/opt/cronmanager/agent/tls/key.pem` | Path to TLS private key inside the container |
| `DB_HOST` | `cronmanager-db` | MariaDB hostname |
| `LOG_PATH` | `/opt/cronmanager/agent/log/cronmanager-agent.log` | Log file path |
| `LOG_LEVEL` | `info` | Monolog level (`debug`, `info`, `warning`, `error`) |
| `LOG_MAX_DAYS` | `30` | Log retention in days |
| `MAIL_ENABLED` | `false` | Enable email failure alerts |
| `MAIL_HOST` | `smtp.example.com` | SMTP server hostname |
| `MAIL_PORT` | `587` | SMTP port |
| `MAIL_USERNAME` | _(empty)_ | SMTP username |
| `MAIL_PASSWORD` | _(empty)_ | SMTP password |
| `MAIL_FROM` | `alerts@example.com` | Sender address |
| `MAIL_FROM_NAME` | `Cronmanager` | Sender display name |
| `MAIL_TO` | `admin@example.com` | Alert recipient |
| `MAIL_ENCRYPTION` | `tls` | `tls` or `ssl` |
| `TELEGRAM_ENABLED` | `false` | Enable Telegram failure alerts |
| `TELEGRAM_BOT_TOKEN` | _(empty)_ | Bot API token from @BotFather |
| `TELEGRAM_CHAT_ID` | _(empty)_ | Target chat, channel, or group ID |
| `TELEGRAM_TIMEOUT` | `15` | HTTP request timeout in seconds |
| `WEB_URL` | _(empty)_ | Base URL of the web UI (e.g. `https://cronmanager.example.com`) — appended to alert notification links |
| `INFLUXDB_ENABLED` | `false` | Enable InfluxDB 2.x metrics export |
| `INFLUXDB_URL` | `http://influxdb:8086` | InfluxDB base URL |
| `INFLUXDB_TOKEN` | _(empty)_ | InfluxDB API token |
| `INFLUXDB_ORG` | _(empty)_ | InfluxDB organisation name |
| `INFLUXDB_BUCKET` | `cronmanager` | InfluxDB bucket name |
| `INFLUXDB_TIMEOUT` | `10` | HTTP write timeout in seconds |
| `AGENT_SETTINGS_KEY` | _(empty)_ | When set, `mail.password`, `telegram.bot_token` and `influxdb.token` are encrypted with AES-256-CBC before being stored in the `agent_settings` DB table. Use at least 32 random characters (`openssl rand -hex 32`). If unset, values are stored as plaintext. Removing the key after setting it makes stored credentials unreadable until re-saved via the web UI. |

#### Web container optional variables

| Variable | Default | Description |
|---|---|---|
| `AGENT_URL` | `https://cronmanager-agent:8865` | Agent base URL |
| `AGENT_TIMEOUT` | `10` | HTTP timeout in seconds |
| `AGENT_SSL_VERIFY` | `false` | Verify agent TLS certificate (`false` = accept self-signed; `true` = require trusted CA) |
| `AGENT_SSL_CA_BUNDLE` | _(empty)_ | Path to a custom CA bundle PEM inside the container (used when `AGENT_SSL_VERIFY=true` with a private CA) |
| `DB_HOST` | `cronmanager-db` | MariaDB hostname |
| `LOG_PATH` | `/var/www/log/cronmanager-web.log` | Log file path |
| `LOG_LEVEL` | `info` | Monolog level |
| `LOG_MAX_DAYS` | `30` | Log retention in days |
| `SESSION_LIFETIME` | `3600` | Session cookie max-age in seconds |
| `SESSION_IDLE_TIMEOUT` | `3600` | Server-side idle expiry in seconds (user is logged out after this many seconds of inactivity) |
| `SESSION_NAME` | `cronmanager_sess` | PHP session cookie name |
| `I18N_LANGUAGE` | `en` | Default UI language (`en` or `de`) |
| `OIDC_ENABLED` | `false` | Enable OIDC / SSO login |
| `OIDC_PROVIDER_URL` | _(empty)_ | OIDC provider discovery URL |
| `OIDC_CLIENT_ID` | _(empty)_ | OAuth 2.0 client ID |
| `OIDC_CLIENT_SECRET` | _(empty)_ | OAuth 2.0 client secret |
| `OIDC_REDIRECT_URI` | _(empty)_ | Callback URL registered at the provider |
| `OIDC_SSL_VERIFY` | `true` | Verify TLS certificate of the OIDC provider |
| `OIDC_SSL_CA_BUNDLE` | _(empty)_ | Path to custom CA bundle (inside container) |
| `OIDC_AUTO_PROVISION` | `auto` | SSO auto-provisioning mode: `auto` = create new users automatically; `disabled` = only pre-existing local accounts can log in via SSO; `group` = role determined by group claim (see `OIDC_GROUP_*`) |
| `OIDC_GROUP_CLAIM` | `groups` | Name of the OIDC claim containing the user's group list (used when `OIDC_AUTO_PROVISION=group`) |
| `OIDC_GROUP_ADMIN` | _(empty)_ | Group name mapped to the `admin` role |
| `OIDC_GROUP_OPERATOR` | _(empty)_ | Group name mapped to the `operator` role |
| `OIDC_GROUP_VIEWER` | _(empty)_ | Group name mapped to the `viewer` role |
| `OIDC_DEFAULT_ROLE` | _(empty)_ | Fallback role when no group claim matches (`viewer`, `operator`, or `admin`); empty = deny login for unmatched users |
| `WEB_MAIL_HOST` | _(empty)_ | SMTP hostname for web UI emails (user invitations, password reset); leave empty to disable email features |
| `WEB_MAIL_PORT` | `587` | SMTP port for web UI emails |
| `WEB_MAIL_USERNAME` | _(empty)_ | SMTP username |
| `WEB_MAIL_PASSWORD` | _(empty)_ | SMTP password |
| `WEB_MAIL_FROM` | _(empty)_ | Sender address for web UI emails |
| `WEB_MAIL_FROM_NAME` | `Cronmanager` | Sender display name |
| `WEB_MAIL_ENCRYPTION` | `tls` | `tls` (STARTTLS, port 587) or `ssl` (SMTPS, port 465) |

### Updating to a new release

```bash
docker compose -f docker-compose-full.yml pull
docker compose -f docker-compose-full.yml up -d
```

The agent container automatically applies any new SQL migrations on startup.

### SSH keys for remote job execution and crontab import

The `docker-compose-full.yml` mounts `/root/.ssh` from the Docker host into the agent
container by default, giving it access to all configured SSH key pairs and host aliases.

For SSH key setup, using a dedicated agent-specific SSH directory, reaching the Docker
host from inside the agent container, and troubleshooting SSH, see **[MULTI-HOST.md](MULTI-HOST.md)**.

---

## Prerequisites

| Component | Requirement |
|---|---|
| Docker + Docker Compose | v2.0 or later |
| PHP on the **host** | 8.4 with extensions: `cli`, `json`, `pdo_mysql`, `openssl`, `mbstring` — **host-agent mode only**; not required for docker mode |
| Composer | 2.x (to install shared PHP libraries) |
| curl | For the cron wrapper script |
| openssl | For HMAC-SHA256 signing in the wrapper |
| SSH client | Required only for remote job execution |

The Docker image used for the web container (`cs1711/cs_cronmanagerweb:latest`) is a
Debian-based image (derived from `cs_php-nginx-fpm:latest-debian`) that includes
PHP-FPM 8.4, Nginx, and supervisord with production PHP settings pre-configured.

> **Alternative images**: Any Docker image that bundles PHP-FPM **8.4** (or later 8.x) with
> Nginx (or Apache) and the required PHP extensions (`pdo_mysql`, `json`, `mbstring`, `openssl`,
> `curl`) is supported. Official images such as `php:8.4-fpm` combined with a separate
> Nginx container, or community images like `webdevops/php-nginx:8.4`, are equally valid.
> Update the `image:` field in `docker-compose.yml` accordingly.

> **APCu extension**: The swimlane view uses APCu for in-memory caching of pre-computed cron
> fire-time patterns.  The `cs1711/cs_cronmanagerweb` image includes `php8.4-apcu` out of
> the box.  If you use a custom base image, install the `php8.4-apcu` package (Debian/Ubuntu)
> for best performance.  The swimlane view works without APCu but will recompute all patterns
> on every page load.  Verify with:
> ```bash
> docker exec <container> php -r "var_dump(extension_loaded('apcu'));"
> ```

---

## Guided Setup (Alternative)

For a fresh installation on a Debian or Ubuntu host, the easiest path is the
interactive setup script included in the repository.  It guides you through
every step in a single session — no manual config file editing required.

### One-command download and run

```bash
curl -fsSL https://raw.githubusercontent.com/csoscd/cronmanager/main/simple_debian_setup.sh | sudo bash
```

> **Note:** Piping directly into `bash` is convenient but means you trust the
> content of the script at that URL.  If you prefer to review it first:
> ```bash
> curl -fsSL https://raw.githubusercontent.com/csoscd/cronmanager/main/simple_debian_setup.sh \
>     -o simple_debian_setup.sh
> less simple_debian_setup.sh          # review
> sudo bash simple_debian_setup.sh     # run
> ```

### What the script covers

| Step | What happens |
|---|---|
| **Target host** | Choose local installation or a remote server via SSH. SSH connectivity and root access are verified before anything else. |
| **Prerequisites** | Checks for PHP 8.4, required extensions, Docker, Composer, git, openssl, rsync and more — **on the target host**. Lists any missing packages and offers to install them via `apt`. |
| **Repository clone** | Clones the repository locally, then deploys files to the target. |
| **Composer / PHP libraries** | Verifies that all required third-party libraries are present on the target. Offers to add missing packages to `composer.json` and run `composer install`. |
| **Configuration interview** | Collects all settings interactively — paths, database credentials, agent and web settings — before touching anything on disk. |
| **HMAC secret** | Generates a cryptographically random 64-character secret with `openssl rand -hex 32`. Both the agent and web application receive the same value automatically. |
| **Host agent deployment** | Deploys agent files, patches paths, writes `config/config.json`, installs the systemd service, starts it, and runs a health check. |
| **Web application deployment** | Deploys web files (including the pre-built Tailwind stylesheet), downloads Chart.js, writes `conf/config.json`. |
| **Docker Compose** | Generates a customised `docker-compose.yml` from your settings, displays it, and optionally runs `docker compose up -d`. |
| **Database schema** | Waits for MariaDB to become healthy, then applies `schema.sql` and all migrations via `docker exec`. |
| **Optional: OIDC** | Asks for provider URL, client credentials, redirect URI, and SSL/CA settings. |
| **Optional: Email alerts** | Asks for SMTP host, port, credentials and encryption. |
| **Summary** | Prints all paths, management commands, the web UI URL, and the generated HMAC secret. |

> **Requirements:** Debian 12+ or Ubuntu 22.04+, internet access on the target, root access.

---

## Quick Start

```bash
# 1. Clone the repository on your development / deployment machine
git clone <repo-url> cronmanager
cd cronmanager

# 2. Configure deployment
cp deploy.env.example deploy.env          # edit SSH host and target paths
cp db.credentials.example db.credentials # set database passwords

# 3. Full deployment to the target host
# Use --host-agent if the agent runs as a systemd service on the host,
# or --docker if the agent runs as a Docker container.
./deploy.sh --host-agent full    # host-agent mode
# or:
./deploy.sh --docker full        # docker mode

# 4. Open the web UI
http://<your-host>:8880/
# → First visit shows the setup wizard to create the initial admin account
```

---

## Detailed Installation

### Step 1 – Install PHP and shared libraries on the host

Cronmanager uses a shared vendor directory (`/opt/phplib/vendor`) that is loaded by
both the host agent (directly on the filesystem) and the web container (via Docker volume mount).

**Install PHP 8.4 on the host (Debian/Ubuntu):**

```bash
sudo apt-get install -y php8.4-cli php8.4-mysql php8.4-mbstring curl openssl
```

**Install Composer (if not already present):**

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

**Install PHP dependencies into the shared vendor directory:**

```bash
# Create the shared library directory
sudo mkdir -p /opt/phplib

# Copy the project's composer.json there
# (deploy.sh does this automatically on the first full deployment if the file is absent)
sudo cp composer.json /opt/phplib/composer.json

# Install packages
cd /opt/phplib
sudo composer install --no-dev --optimize-autoloader
```

The resulting `/opt/phplib/vendor/autoload.php` is used by both the host agent and the
web container.

---

### Step 2 – Deploy the files

**Configure deployment:**

```bash
cp deploy.env.example deploy.env
```

Edit `deploy.env`:

```bash
DEPLOY_TYPE=SSH                           # SSH (remote host) or LOCAL (same machine)
DEPLOY_SSH=myserver                       # Host alias from ~/.ssh/config
DEPLOY_COMPOSER=/opt/phplib/
DEPLOY_COMPOSER_VENDOR=/opt/phplib/vendor/
```

> Deployment paths are fixed: agent → `/opt/cronmanager/agent`, web → `/opt/cronmanager/www`, DB → `/opt/cronmanager/db`. These are not configurable in `deploy.env`.

**Configure database credentials:**

```bash
cp db.credentials.example db.credentials
```

Edit `db.credentials`:

```bash
DB_NAME=cronmanager
DB_USER=cronmanager
DB_PASSWORD=<strong-password>
DB_ROOT_USER=root
DB_ROOT_PASSWORD=<strong-root-password>
```

> `db.credentials` contains plain-text passwords. Keep it out of version control.

**Run the deployment:**

```bash
./deploy.sh --host-agent full   # host-agent mode
# or:
./deploy.sh --docker full       # docker mode
```

The script will:
- In `--host-agent` mode: installs and enables the systemd service for the host agent
- In `--docker` mode: skips systemd; use docker-compose to start the agent container
- Create all required directories on the target
- Sync all application files via rsync
- Deploy the example configuration files (only if no config exists yet)
- Generate the MariaDB init script from your credentials
- Attempt to apply the database schema (once the container is running)

---

### Step 3 – Configure the host agent

The agent configuration is at `/opt/cronmanager/agent/config/config.json`.
On the first deployment, the example configuration is placed there automatically.

**Minimum required changes:**

```json
{
    "agent": {
        "bind_address": "0.0.0.0",
        "port": 8865,
        "hmac_secret": "<generate-a-random-32-char-string>"
    },
    "database": {
        "host": "127.0.0.1",
        "port": 3306,
        "name": "cronmanager",
        "user": "cronmanager",
        "password": "<same-as-DB_PASSWORD-in-db.credentials>"
    }
}
```

Generate a strong HMAC secret:

```bash
openssl rand -hex 32
```

> The same `hmac_secret` value must appear in both the agent config and the web app config.

---

### Step 4 – Start the host agent service

The deployment script installs and starts the systemd service automatically.
You can manage it with standard systemd commands:

```bash
# Check service status
sudo systemctl status cronmanager-agent

# View live logs
sudo journalctl -u cronmanager-agent -f

# Restart after a config change
sudo systemctl restart cronmanager-agent

# Verify the agent is reachable (TLS with self-signed cert)
curl -k https://127.0.0.1:8865/health
# → {"status":"ok","timestamp":"2026-03-18T10:00:00+00:00"}
```

---

### Step 5 – Configure the web application

The web configuration is at `/opt/cronmanager/www/conf/config.json`.
On the first deployment, the example configuration is placed there automatically.

**Minimum required changes:**

```json
{
    "database": {
        "host": "cronmanager-db",
        "port": 3306,
        "name": "cronmanager",
        "user": "cronmanager",
        "password": "<same-as-DB_PASSWORD-in-db.credentials>"
    },
    "agent": {
        "url": "https://host.docker.internal:8865",
        "hmac_secret": "<same-secret-as-in-agent-config>",
        "timeout": 10,
        "ssl_verify": false,
        "ssl_ca_bundle": ""
    }
}
```

> **Docker mode:** set `agent.url` to `https://cronmanager-agent:8865` instead. `deploy.sh --docker full` patches this automatically on the first deployment.

`host.docker.internal` resolves to the Docker host from within the container and is
configured automatically via the `extra_hosts` entry in `docker/docker-compose.yml` (host-agent mode). In docker mode, use `cronmanager-agent` as the hostname instead — this is the Docker service name on the shared internal network.

`ssl_verify: false` is correct for the default self-signed certificate. Set it to `true` and supply `ssl_ca_bundle` when using a certificate signed by a private CA.

---

### Step 6 – Start the Docker stack

**Option A – docker compose directly on the host:**

```bash
# Host-agent mode: web + MariaDB only
cd /opt/cronmanager/www   # place docker-compose.yml here, or use the file from docker/docker-compose.yml

# Docker mode: agent + web + MariaDB
cd /opt/cronmanager/www   # use docker/docker-compose-agent.yml

export DB_NAME=cronmanager
export DB_USER=cronmanager
export DB_PASSWORD=<your-password>
export DB_ROOT_PASSWORD=<your-root-password>

docker compose up -d
```

**Option B – Portainer:**

1. Open Portainer → Stacks → Add Stack
2. Paste the contents of `docker-compose.yml`
3. Add the following environment variables:
   - `DB_NAME` = `cronmanager`
   - `DB_USER` = `cronmanager`
   - `DB_PASSWORD` = your password
   - `DB_ROOT_PASSWORD` = your root password
4. Deploy the stack

**Apply the database schema** (first deployment only, once the MariaDB container is healthy):

```bash
ssh myserver 'docker exec -i cronmanager-db mariadb \
    -u cronmanager -p<password> cronmanager \
    < /opt/cronmanager/agent/sql/schema.sql'
```

---

### Step 7 – First login and initial setup

Open `http://<your-host>:8880/` in your browser.

If no users exist in the database yet, you are automatically redirected to the
**Setup wizard**:

1. Enter a username for the initial admin account
2. Enter and confirm a password (minimum 8 characters)
3. Click **Create admin account**

You are then redirected to the login page. Log in with the credentials you just created.

---

## OIDC / SSO Setup

Cronmanager supports Single Sign-On via any OpenID Connect 1.0 provider (Authentik,
Keycloak, Dex, Google Workspace, …). Three provisioning modes control how SSO users
are handled: `auto` (default), `disabled`, and `group` (role derived from OIDC group claim).

For the complete setup guide including the step-by-step Authentik configuration and a
full group-mapping example (provider side + Cronmanager side), see **[SSO.md](SSO.md)**.

---

## Configuration Reference

### Web application config

| Key | Default | Description |
|---|---|---|
| `database.host` | `cronmanager-db` | MariaDB hostname (Docker service name) |
| `database.port` | `3306` | MariaDB port |
| `database.name` | `cronmanager` | Database name |
| `database.user` | `cronmanager` | Database user |
| `database.password` | | Database password |
| `agent.url` | `https://host.docker.internal:8865` (host-agent) / `https://cronmanager-agent:8865` (docker) | Host agent base URL |
| `agent.hmac_secret` | | Shared HMAC secret (must match agent) |
| `agent.timeout` | `10` | HTTP timeout in seconds |
| `agent.ssl_verify` | `false` | `false` = accept self-signed cert; `true` = require trusted CA; path string = use custom CA bundle |
| `agent.ssl_ca_bundle` | `""` | Path to a PEM CA bundle inside the container (used when `ssl_verify` is `true` with a private CA) |
| `logging.path` | `/var/www/log/cronmanager-web.log` | Log file path |
| `logging.level` | `info` | `debug`, `info`, `warning`, `error`, `critical` |
| `logging.max_days` | `30` | Log file retention in days |
| `session.lifetime` | `3600` | Session cookie max-age in seconds |
| `session.idle_timeout` | `3600` | Server-side idle expiry in seconds |
| `session.name` | `cronmanager_sess` | Session cookie name |
| `i18n.default_language` | `en` | Default language (`en` or `de`) |
| `auth.oidc_enabled` | `false` | Enable OIDC SSO |
| `auth.oidc_provider_url` | | OIDC provider base URL (with trailing slash) |
| `auth.oidc_client_id` | | OAuth 2.0 Client ID |
| `auth.oidc_client_secret` | | OAuth 2.0 Client Secret |
| `auth.oidc_redirect_uri` | | Callback URL (`https://your-domain/auth/callback`) |
| `auth.oidc_ssl_verify` | `true` | `true` = system CA, `false` = disable, or path to CA bundle |
| `auth.oidc_ssl_ca_bundle` | `""` | Path to custom PEM CA bundle (empty = system CA) |
| `auth.oidc_auto_provision` | `auto` | SSO provisioning mode: `auto`, `disabled`, or `group` |
| `auth.oidc_group_claim` | `groups` | OIDC claim containing the user's group list |
| `auth.oidc_group_admin` | `""` | Group name → `admin` role |
| `auth.oidc_group_operator` | `""` | Group name → `operator` role |
| `auth.oidc_group_viewer` | `""` | Group name → `viewer` role |
| `auth.oidc_default_role` | `""` | Fallback role for unmatched SSO users; empty = deny login |
| `mail.host` | `""` | SMTP hostname for web UI emails (invitations, password reset); empty = email features disabled |
| `mail.port` | `587` | SMTP port |
| `mail.username` | `""` | SMTP username |
| `mail.password` | `""` | SMTP password |
| `mail.from` | `""` | Sender address |
| `mail.from_name` | `Cronmanager` | Sender display name |
| `mail.encryption` | `tls` | `tls` (STARTTLS) or `ssl` (SMTPS) |

### Agent config

| Key | Default | Description |
|---|---|---|
| `agent.bind_address` | `0.0.0.0` | Bind address for the internal PHP server (nginx handles the external port) |
| `agent.port` | `8865` | External port (nginx TLS) |
| `agent.tls_enabled` | `true` | Written automatically from `AGENT_TLS_ENABLED`; read by `cron-wrapper.sh` to decide HTTP vs HTTPS |
| `agent.hmac_secret` | | Shared HMAC secret (must match web config) |
| `database.host` | `127.0.0.1` | MariaDB hostname |
| `database.port` | `3306` | MariaDB port |
| `database.name` | `cronmanager` | Database name |
| `database.user` | `cronmanager` | Database user |
| `database.password` | | Database password |
| `logging.path` | `/opt/cronmanager/agent/log/cronmanager-agent.log` | Log file path |
| `logging.level` | `info` | Log level |
| `logging.max_days` | `30` | Log file retention in days |
| `mail.enabled` | `false` | Enable email failure alerts |
| `mail.host` | | SMTP server hostname |
| `mail.port` | `587` | SMTP port |
| `mail.username` | | SMTP username |
| `mail.password` | | SMTP password |
| `mail.from` | | Sender address |
| `mail.from_name` | `Cronmanager` | Sender display name |
| `mail.to` | | Recipient address for alerts |
| `mail.encryption` | `tls` | `tls` (STARTTLS, port 587) or `ssl` (SMTPS, port 465) |
| `mail.smtp_timeout` | `15` | SMTP connection timeout in seconds |
| `telegram.enabled` | `false` | Enable Telegram failure alerts |
| `telegram.bot_token` | | Bot API token from @BotFather |
| `telegram.chat_id` | | Target chat, channel, or group ID |
| `telegram.timeout` | `15` | HTTP request timeout in seconds |
| `cron.wrapper_script` | `/opt/cronmanager/agent/bin/cron-wrapper.sh` | Wrapper script path |

---

## Agent TLS

All communication between the web container and the host agent is encrypted with TLS.
The agent container runs an **nginx reverse proxy** that terminates TLS on port **8865**
and forwards plain HTTP internally to the PHP built-in server on port **18865**.

### Certificate

By default a **self-signed RSA-2048 certificate** (valid 10 years) is generated
automatically on the first container start and stored in a Docker-managed named volume
(`agent-tls`) so it persists across container recreations.

To use your own certificate (Let's Encrypt, private CA, etc.), mount the cert and key
files into the container and set the corresponding environment variables:

```yaml
environment:
  TLS_CERT_FILE: /opt/cronmanager/agent/tls/cert.pem
  TLS_KEY_FILE:  /opt/cronmanager/agent/tls/key.pem
volumes:
  - /path/to/your/cert.pem:/opt/cronmanager/agent/tls/cert.pem:ro
  - /path/to/your/key.pem:/opt/cronmanager/agent/tls/key.pem:ro
```

### Web container – certificate verification

Set `agent.ssl_verify` (or `AGENT_SSL_VERIFY`) on the web container:

| Value | When to use |
|---|---|
| `false` | Self-signed certificate (default) |
| `true` | Certificate from a public/trusted CA |
| `/path/to/ca.pem` | Certificate from a private CA – provide the CA bundle path |

When using a custom CA bundle, also set `agent.ssl_ca_bundle` to the path of the PEM
file **inside the web container**.

### Disabling TLS

TLS can be disabled by setting `AGENT_TLS_ENABLED=false` on the agent container and
changing `agent.url` back to `http://` in the web config. This is only recommended
for isolated internal networks where encryption is provided at another layer.

---

## Failure Alerts (Email & Telegram)

Cronmanager sends failure alerts (non-zero exit codes, execution limits, silence detection)
via email and/or Telegram. Both channels can be enabled independently via **Settings →
Agent Settings** in the web UI or via environment variables.

Recovery notifications and silence detection alerts are also covered.

For the full configuration guide including env vars, SMTP encryption settings, and
troubleshooting, see **[ALERTS.md](ALERTS.md)**.

---

## InfluxDB Metrics

Cronmanager can write per-execution metrics to **InfluxDB 2.x** for dashboards in
Grafana. An importable dashboard is included at `grafana/cronmanager-overview.json`.

For the measurement schema, env var reference, Grafana import steps, and troubleshooting,
see **[INFLUXDB.md](INFLUXDB.md)**.

---

## Multi-Host Execution

A single cron job can execute on multiple targets simultaneously — `local` and any number
of SSH aliases defined in `~/.ssh/config`. Each target gets its own crontab entry and
reports results independently.

For SSH key setup, reaching the Docker host from inside the agent container, and
troubleshooting SSH connectivity, see **[MULTI-HOST.md](MULTI-HOST.md)**.

---

## Crontab Import

Existing crontab entries not managed by Cronmanager can be imported:

1. Go to **Cron Jobs → Import** (admin only)
2. Select the Linux user whose crontab to scan
3. Click **Load entries** – unmanaged lines are displayed
4. Select entries to import; optionally add a description and tags
5. Click **Import selected**

After import, the original unmanaged lines are commented out in the crontab file
and replaced with managed wrapper-script entries.

---

## Reading the Crontab

### Host-agent mode

The agent manages crontab files directly on the host for each configured Linux user:

```bash
# View the crontab for a specific user
crontab -u <linux-user> -l

# View the raw crontab file
cat /var/spool/cron/crontabs/<linux-user>
```

Managed entries are prefixed with a `# Cronmanager:` comment line and call the wrapper script:
```
# Cronmanager: My job  id:42
*/5 * * * *  /opt/cronmanager/agent/bin/cron-wrapper.sh  42  local
```

### Docker mode

In docker mode the agent runs inside the `cronmanager-agent` container and cron jobs run as `root` inside that container. The crontab is the container root user's crontab.

```bash
# View the crontab inside the agent container
docker exec cronmanager-agent crontab -l

# View the raw crontab file inside the container
docker exec cronmanager-agent cat /var/spool/cron/crontabs/root
```

> **Note:** After migrating from host-agent to docker mode, use **Housekeeping → Crontab Sync** in the web UI to write all active jobs into the container's crontab. Without this step the container crontab will be empty and no jobs will execute.

> **Linux user requirement:** In docker mode all jobs run as `root` inside the container. Ensure every job's **Linux user** is set to `root` before running Crontab Sync.

---

## Settings

The **Settings** page (`/settings`, admin only) provides operational tools for keeping the system healthy.

### Crontab Sync

Re-writes all crontab entries from the database in one click. Active jobs have their entries added or updated; inactive jobs have any lingering entries removed. Use this after migrating from host-agent to docker mode, or whenever crontab entries get out of sync with the database.

### Stuck Executions

Lists executions that have been in the "running" state longer than a configurable threshold (default: 2 hours). This can occur when the agent restarted mid-execution, leaving records without a finish timestamp.

> **Tip:** The [Startup Orphan Cleanup](#startup-orphan-cleanup) feature automatically resolves most of these cases on agent restart. The Stuck Executions panel handles any edge cases that slip through (e.g. very recent restarts within the 2-minute grace period).

**Per-row actions:**
- **Mark Finished** – sets `exit_code = -1`, records `finished_at = NOW()`, appends a note to the output
- **Delete** – permanently removes the execution record

**Bulk actions:** rows can be selected individually or all at once with the "Select All" checkbox. The bulk toolbar appears when at least one row is selected and provides the same two actions for all selected rows at once.

The lookback threshold is adjustable with an inline hour selector without leaving the page.

### History Cleanup

Bulk-deletes finished execution records older than a configurable number of days (default: 90). Only records with a non-NULL `finished_at` are eligible; running executions are never deleted. Use this to reclaim database space on long-running installations.

### Performance Monitor

Two independently configurable options under **Settings → Agent Settings**:

| Option | Description |
|---|---|
| **Persist performance data** | Writes request duration, aggregated DB query time, and query count to the `performance_log` table after every agent request. Useful for identifying slow endpoints over time. |
| **Show performance info in frontend** | Enriches every agent JSON response with a `_perf` field containing `request_ms`, `db_ms`, and `db_queries`. The web UI footer displays these values for the most recent agent call. Works independently of the persist option. |

### Agents

Lists all configured remote agents with name, URL, live connection status, and edit/delete actions. See [Multi-Agent Setup](#multi-agent-setup) for details.

---

## Multi-Agent Setup

Since v4.0.0, a single Cronmanager web UI can manage cron jobs across **multiple agents**
on different hosts. Each agent has its own MariaDB and crontab; users switch between them
via a sidebar dropdown.

For the deployment steps, registration form reference, per-user agent restrictions, and
upgrade notes, see **[MULTI-AGENT.md](MULTI-AGENT.md)**.

---

## Maintenance Windows

Maintenance windows let you mark scheduled time slots as off-limits for job execution.
They are managed via **Maintenance** in the navigation bar (admin only).

### Startup Orphan Cleanup

Every time the agent service starts, a cleanup script (`startup-cleanup.php`) runs before the HTTP server accepts requests. It scans `execution_log` for records still in the "running" state whose process is no longer alive and resolves them automatically:

| Target type | How checked |
|---|---|
| `local` with a stored PID | `posix_kill($pid, 0)` — process existence verified; alive processes are left untouched |
| `local` without a PID | Assumed dead after a restart — marked interrupted |
| Remote SSH targets | PID is on the remote host; assumed dead — marked interrupted |

Cleaned-up executions receive `exit_code = -5` ("Interrupted by system restart") and appear in the timeline and job detail view with a gray **Interrupted** badge. A 2-minute grace period prevents false positives for jobs that happened to start right as the agent restarted.

### Per-target windows

The normal use-case: a window defined for `local` or an SSH host alias blocks job execution on that specific target during the configured period.

### Defining a maintenance window

Each window has:

| Field | Description |
|---|---|
| **Target** | `local` or an SSH host alias — the target this window applies to |
| **Schedule** | Standard 5-field cron expression for when the window **starts** |
| **Duration** | Length of the window in minutes (default: 60) |
| **Description** | Optional human-readable label |
| **Active** | Whether this window is currently evaluated |

### Per-job behaviour (`run_in_maintenance` flag)

| Setting | Behaviour |
|---|---|
| Off (default) | The job is **skipped**. The cron wrapper reports exit code `−4` and the execution is recorded as `during_maintenance = 1` |
| On | The job **runs**. Failures are still reported normally |

### Conflict detection

The job list and job detail pages perform an asynchronous conflict check per target:

- The next **50** upcoming run times for the job/target pair are fetched from the agent
- If **90 % or more** of those runs fall inside a maintenance window, the target badge turns **red ✕** ("will not be executed")
- Otherwise, if any conflict exists, the badge is **amber ⚠** ("some runs may fall in a maintenance window")

### Dashboard filtering

Executions skipped because of a maintenance window (exit code `−4`) are excluded from the "recent failures" list on the dashboard. They are still visible in the Timeline and on the detail page.

---

## Export

Managed cron jobs can be exported from the **Export** page:

| Format | Description |
|---|---|
| **Crontab** | Plain text, one line per job/target — ready to paste into a crontab file |
| **JSON** | Structured data including all job fields, tags, and targets |

Both formats support filtering by Linux user and/or tag.
Large exports are streamed directly to the browser without buffering in memory.

---

## User Management

Since v5.0.0 Cronmanager has a full four-role user management system. Admins manage
accounts via **Users** in the navigation bar.

### Roles

| Role | Web UI | Create/Edit Jobs | Admin (Users, Settings, Agents) |
|---|---|---|---|
| `admin` | ✓ | ✓ | ✓ |
| `operator` | ✓ | ✓ | — |
| `viewer` | ✓ (read-only) | — | — |
| `api-only` | — (no web login) | — | — |

`api-only` accounts can only authenticate via API keys — they cannot log in to the web UI.

### Creating users (local accounts)

1. Go to **Users → Create user** (admin only)
2. Fill in **Username**, **Email** (optional), **Role**, and optionally a **Password**
3. If SMTP is configured on the web container and an email address is provided, check
   **Send invitation email** — the user receives a one-time link to set their own password
4. Click **Create**

### Invitation flow

When the **Send invitation email** option is selected:
- The user receives an email with a one-time invite link (valid 72 hours)
- Following the link opens a form to set a password; on success the user is logged in
- If the link expires, an admin can resend it via the **Resend invite** button on the user list
- The "Resend invite" button is shown only for local accounts that have an email address and where mail is enabled

### Self-service password reset

If SMTP is configured, a **"Forgot password?"** link appears on the login page:
1. The user enters their email address
2. A reset link (valid 72 hours) is sent to that address
3. Following the link opens a form to set a new password
4. The response is identical whether the email exists or not (prevents user enumeration)

SSO-authenticated users cannot use password reset — their credentials are managed by the IdP.

### Self-service profile (`/profile`)

Every authenticated user can access `/profile` from the sidebar (Tools section) to:
- Change their **email address**
- Change their **password**

SSO users see a note that their credentials are managed by the identity provider and cannot change their password here.

### Deactivating users

Admins can deactivate a user account (toggle via the user list). Deactivated accounts:
- Cannot log in
- Have any active sessions invalidated at the next request

The account is not deleted and can be reactivated at any time.

### Per-user agent restrictions

Each user can be restricted to a subset of configured agents (the same mechanism available
for API keys). When restrictions are set, the user's sidebar agent switcher only shows
the permitted agents. When no restriction is set, the user can access all agents.

Configure this on the user's create/edit form via the **Agent restriction** multi-select.

### Notes

- You cannot modify or delete your own account (self-action protection)
- SSO users show a **SSO** badge in the user list; their role depends on the `OIDC_AUTO_PROVISION` setting (see [SSO user provisioning](#6-sso-user-provisioning))
- Deleting an SSO user does not revoke their OIDC provider access — use `OIDC_AUTO_PROVISION=disabled` to block re-creation on next login
- All user management actions are recorded in the **Audit Log**

---

## External REST API

Since version 4.1.0, Cronmanager exposes a versioned REST API at `/api/v1/*` for external
applications. Every request must carry a **Bearer token** generated in the web UI under
**API Keys** (available to every logged-in user):

```http
Authorization: Bearer cm_<your-api-key>
```

Access is controlled by **scopes**: each key is granted only the permissions it needs
(e.g. `jobs:read` for read-only access, `jobs:write` to create and edit jobs).
Keys can also be restricted by expiry date, IP whitelist, and the set of agents they may target.

For the full API reference including all endpoints, request/response examples, and security
details, see **[API.md](API.md)**.

---

## Updating

Deploy only changed files (configuration files are never overwritten):

```bash
./deploy.sh --host-agent update   # host-agent mode
# or:
./deploy.sh --docker update       # docker mode
```

Restart the host agent to load code changes:

```bash
sudo systemctl restart cronmanager-agent
```

In docker mode, restart the agent container instead:
```bash
docker restart cronmanager-agent
```

Apply database migrations when indicated in the release notes:

```bash
ssh myserver 'docker exec -i cronmanager-db mariadb \
    -u cronmanager -p<password> cronmanager \
    < /opt/cronmanager/agent/sql/migrations/<migration-file>.sql'
```

---

## Troubleshooting

For the full troubleshooting guide (agent unavailable, jobs not executing, stuck
executions, auto-kill issues, database connection failures, and more),
see **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)**.
