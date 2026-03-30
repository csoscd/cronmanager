# Cronmanager

A modern, web-based cron job management UI for Linux systems. Cronmanager lets you create,
edit, monitor, and export cron jobs through a clean browser interface, with full execution
history, email failure alerts, multi-host support, and SSO integration.

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
   - [Step 1 – Install PHP and shared libraries on the host](#step-1--install-php-and-shared-libraries-on-the-host)
   - [Step 2 – Deploy the files](#step-2--deploy-the-files)
   - [Step 3 – Configure the host agent](#step-3--configure-the-host-agent)
   - [Step 4 – Start the host agent service](#step-4--start-the-host-agent-service)
   - [Step 5 – Configure the web application](#step-5--configure-the-web-application)
   - [Step 6 – Start the Docker stack](#step-6--start-the-docker-stack)
   - [Step 7 – First login and initial setup](#step-7--first-login-and-initial-setup)
8. [OIDC / SSO Setup with Authentik](#oidc--sso-setup-with-authentik)
9. [Configuration Reference](#configuration-reference)
   - [Web application config](#web-application-config)
   - [Agent config](#agent-config)
10. [Email Failure Alerts](#email-failure-alerts)
11. [Multi-Host Execution](#multi-host-execution)
12. [Crontab Import](#crontab-import)
13. [Maintenance](#maintenance)
14. [Export](#export)
15. [User Management](#user-management)
16. [Updating](#updating)
17. [Troubleshooting](#troubleshooting)

---

## Features

| Feature | Description |
|---|---|
| **Job management** | Create, edit, and delete cron jobs with schedule, description, and tags |
| **Execution tracking** | Every job run is recorded: start time, end time, exit code, and captured output |
| **Job monitor** | Per-job statistics page with KPI cards (success rate, avg/min/max duration, alerts), an execution duration line chart, and a stacked bar chart – selectable time window from 1 hour to 1 year |
| **Dashboard** | At-a-glance view of total jobs, active/inactive counts, and recent failures |
| **Timeline** | Filterable, paginated history of all executions across all jobs |
| **Swimlane** | Visual schedule overview: planned fire times per job across a time-of-day axis, filterable by hour range, day of week, tag, and target |
| **Multi-host execution** | A single job can run on multiple targets (local + remote SSH) in parallel |
| **Tags** | Label jobs to enable filtering and grouped export |
| **Crontab import** | Detect and import existing unmanaged crontab entries |
| **Export** | Download a ready-to-use crontab file or JSON for all managed jobs |
| **Email alerts** | Receive an email when a job exits with a non-zero status |
| **Local & SSO auth** | Username/password accounts or OAuth 2.0 / OpenID Connect (OIDC) via Authentik |
| **Role-based access** | Admin (full access) and Viewer (read-only) roles |
| **User management** | Admins can promote, demote, or remove users |
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
             │ HMAC-signed HTTP (host.docker.internal:8865)
             ▼
┌──────────────────────────┐
│  Host Agent              │  PHP CLI server  ·  Port 8865
│  /opt/cronmanager/agent  │  systemd service on the Docker host
└────────────┬─────────────┘
             │ reads/writes crontab files
             │ reports execution results via PDO
             ▼
     Linux cron daemon          MariaDB container (cronmanager-db)
```

The agent runs directly on the Docker host. The web container reaches it via
`host.docker.internal:8865` (provided by Docker's `extra_hosts: host-gateway` mechanism).

### Docker mode

```
Browser
  │
  ▼
┌──────────────────────────┐
│  Web UI (Docker)         │  PHP-FPM + Nginx  ·  Port 8880
│  /opt/cronmanager/www    │
└────────────┬─────────────┘
             │ HMAC-signed HTTP (cronmanager-agent:8865)
             ▼
┌──────────────────────────┐
│  Agent container         │  PHP CLI server  ·  Port 8865
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
All privileged operations are delegated to the agent via HMAC-secured HTTP calls.

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
| `AGENT_PORT` | `8865` | Listening port |
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

#### Web container optional variables

| Variable | Default | Description |
|---|---|---|
| `AGENT_URL` | `http://cronmanager-agent:8865` | Agent base URL |
| `AGENT_TIMEOUT` | `10` | HTTP timeout in seconds |
| `DB_HOST` | `cronmanager-db` | MariaDB hostname |
| `LOG_PATH` | `/var/www/log/cronmanager-web.log` | Log file path |
| `LOG_LEVEL` | `info` | Monolog level |
| `LOG_MAX_DAYS` | `30` | Log retention in days |
| `SESSION_LIFETIME` | `3600` | PHP session lifetime in seconds |
| `SESSION_NAME` | `cronmanager_sess` | PHP session cookie name |
| `I18N_LANGUAGE` | `en` | Default UI language (`en` or `de`) |
| `OIDC_ENABLED` | `false` | Enable OIDC / SSO login |
| `OIDC_PROVIDER_URL` | _(empty)_ | OIDC provider discovery URL |
| `OIDC_CLIENT_ID` | _(empty)_ | OAuth 2.0 client ID |
| `OIDC_CLIENT_SECRET` | _(empty)_ | OAuth 2.0 client secret |
| `OIDC_REDIRECT_URI` | _(empty)_ | Callback URL registered at the provider |
| `OIDC_SSL_VERIFY` | `true` | Verify TLS certificate of the OIDC provider |
| `OIDC_SSL_CA_BUNDLE` | _(empty)_ | Path to custom CA bundle (inside container) |

### Updating to a new release

```bash
docker compose -f docker-compose-full.yml pull
docker compose -f docker-compose-full.yml up -d
```

The agent container automatically applies any new SQL migrations on startup.

### SSH keys for remote job execution and crontab import

The `docker-compose-full.yml` mounts `/root/.ssh` from the Docker host into the agent
container by default:

```yaml
- /root/.ssh:/root/.ssh:ro
```

This gives the agent access to all SSH host aliases and key pairs configured for the
host's root user, which is the simplest setup.

> **Security note:** Mounting `/root/.ssh` exposes **every** SSH key and host alias
> configured for root — including connections to systems unrelated to Cronmanager.
> If the agent container were ever compromised, all those keys would be at risk.

#### Alternative: agent-specific SSH directory

Create a dedicated directory with only the keys and hosts Cronmanager needs:

```bash
mkdir -p /opt/cronmanager/.ssh
chmod 700 /opt/cronmanager/.ssh

# Generate a dedicated key pair (no passphrase for unattended use)
ssh-keygen -t ed25519 -C "cronmanager-agent" -N "" \
    -f /opt/cronmanager/.ssh/id_ed25519

# Create a config file listing only the hosts Cronmanager manages
cat > /opt/cronmanager/.ssh/config <<'EOF'
Host myserver1
    HostName 192.168.1.10
    User root
    IdentityFile ~/.ssh/id_ed25519
    BatchMode yes
    ConnectTimeout 10

Host myserver2
    HostName 192.168.1.11
    User root
    IdentityFile ~/.ssh/id_ed25519
    BatchMode yes
    ConnectTimeout 10
EOF
chmod 600 /opt/cronmanager/.ssh/config
```

Then in `docker-compose-full.yml`, replace the default mount with:

```yaml
- /opt/cronmanager/.ssh:/root/.ssh:ro
```

#### Reaching the Docker host itself

If you want to import or run cron jobs on the **Docker host** itself (the machine running
the containers), the agent container must be able to SSH back to it.

1. **Add the host to the SSH config** (`/root/.ssh/config` or `/opt/cronmanager/.ssh/config`):

   ```
   Host dockerhost
       HostName host.docker.internal
       User root
       IdentityFile ~/.ssh/id_ed25519
       BatchMode yes
       ConnectTimeout 10
   ```

   > `host.docker.internal` resolves to the Docker host's gateway IP inside the container.
   > Add it to the agent service in `docker-compose-full.yml` if not already present:
   > ```yaml
   > extra_hosts:
   >   - "host.docker.internal:host-gateway"
   > ```

2. **Authorise the agent's public key on the Docker host:**

   ```bash
   # Append the public key to root's authorized_keys on the Docker host
   cat /opt/cronmanager/.ssh/id_ed25519.pub >> /root/.ssh/authorized_keys
   chmod 600 /root/.ssh/authorized_keys

   # Ensure the SSH daemon permits key-based root login
   grep -i permitrootlogin /etc/ssh/sshd_config
   # Should be: PermitRootLogin prohibit-password
   ```

3. **Add `StrictHostKeyChecking accept-new` to the SSH config:**

   Without this, SSH will silently refuse to connect when the host key is not
   yet in `known_hosts` (because `BatchMode yes` suppresses all interactive prompts).
   Add the line to the `dockerhost` block in `/root/.ssh/config`:

   ```
   Host dockerhost
       HostName host.docker.internal
       User root
       IdentityFile ~/.ssh/id_ed25519
       BatchMode yes
       ConnectTimeout 10
       StrictHostKeyChecking accept-new
   ```

4. **Add the host key to `known_hosts`:**

   `host.docker.internal` only resolves **inside** Docker containers — not on the
   Docker host itself — so `ssh-keyscan` cannot be run directly on the host.
   Instead, run it from **inside** the agent container and redirect the output
   to the host's `known_hosts` file (the `>>` executes in the host shell):

   ```bash
   docker exec cronmanager-agent ssh-keyscan -H host.docker.internal \
       >> /root/.ssh/known_hosts
   ```

   > **Why this works:** `ssh-keyscan` runs inside the container where
   > `host.docker.internal` resolves correctly to the Docker gateway IP.
   > The `>>` redirect runs in the host shell and writes directly to the
   > host's `/root/.ssh/known_hosts`. The container sees the updated file
   > immediately via the read-only mount — no container restart required.

5. **Verify:**

   ```bash
   docker exec cronmanager-agent ssh dockerhost 'crontab -l -u root'
   ```

   You should see the root crontab output. If it works here, the Cronmanager
   import page will list the Docker host as a target and show its crontab entries.

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

The Docker image used for the web container (`cs1711/cs_php-nginx-fpm:latest-alpine`)
includes PHP-FPM 8.4 and Nginx.

> **Alternative images**: Any Docker image that bundles PHP-FPM **8.4** (or later 8.x) with
> Nginx (or Apache) and the required PHP extensions (`pdo_mysql`, `json`, `mbstring`, `openssl`,
> `curl`) is supported. Official images such as `php:8.4-fpm-alpine` combined with a separate
> Nginx container, or community images like `webdevops/php-nginx:8.4-alpine`, are equally valid.
> Update the `image:` field in `docker-compose.yml` accordingly.

> **APCu extension**: The swimlane view uses APCu for in-memory caching of pre-computed cron
> fire-time patterns.  Install the Alpine package `php84-pecl-apcu` in your Docker image for
> best performance.  The swimlane view works without APCu but will recompute all patterns on
> every page load.  Verify with:
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
| **Web application deployment** | Deploys web files, downloads Tailwind CSS and Chart.js, writes `conf/config.json`. |
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

# Verify the agent is reachable
curl http://127.0.0.1:8865/health
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
        "url": "http://host.docker.internal:8865",
        "hmac_secret": "<same-secret-as-in-agent-config>",
        "timeout": 10
    }
}
```

> **Docker mode:** set `agent.url` to `http://cronmanager-agent:8865` instead. `deploy.sh --docker full` patches this automatically on the first deployment.

`host.docker.internal` resolves to the Docker host from within the container and is
configured automatically via the `extra_hosts` entry in `docker/docker-compose.yml` (host-agent mode). In docker mode, use `cronmanager-agent` as the hostname instead — this is the Docker service name on the shared internal network.

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

## OIDC / SSO Setup with Authentik

Cronmanager supports Single Sign-On via any OpenID Connect provider.
The following instructions use **Authentik** as the identity provider.

### 1. Create a provider in Authentik

1. Go to **Applications → Providers → Create**
2. Choose **OAuth2/OpenID Connect Provider**
3. Configure the provider:
   - **Name:** `Cronmanager`
   - **Client type:** Confidential
   - **Redirect URIs:** `https://cronmanager.example.com/auth/callback`
     (replace with your actual domain — must match `oidc_redirect_uri` exactly)
   - **Scopes:** `openid`, `email`, `profile`
4. After saving, note the **Client ID** and **Client Secret**

### 2. Create an Application in Authentik

1. Go to **Applications → Applications → Create**
2. Set a name and slug (e.g. `cronmanager`)
3. Assign the provider created above
4. Save

### 3. Find the Provider URL

Open the provider detail page and look for the
**OpenID Configuration URL** — it resembles:
```
https://auth.example.com/application/o/cronmanager/.well-known/openid-configuration
```

The value you need for `oidc_provider_url` is everything **before** `.well-known`:
```
https://auth.example.com/application/o/cronmanager/
```

### 4. Configure Cronmanager

Edit `/opt/cronmanager/www/conf/config.json`:

```json
{
    "auth": {
        "oidc_enabled":       true,
        "oidc_provider_url":  "https://auth.example.com/application/o/cronmanager/",
        "oidc_client_id":     "<client-id-from-authentik>",
        "oidc_client_secret": "<client-secret-from-authentik>",
        "oidc_redirect_uri":  "https://cronmanager.example.com/auth/callback",
        "oidc_ssl_verify":    true,
        "oidc_ssl_ca_bundle": ""
    }
}
```

Restart the web container to apply:

```bash
docker restart cronmanager-web
```

The login page now shows a **"Login with SSO"** button alongside the local login form.

### 5. Private CA certificates (homelab)

If your Authentik instance uses a certificate issued by an internal CA:

```bash
# Copy the CA certificate (PEM format) to the config directory
cp root_ca.crt /opt/cronmanager/www/conf/root_ca.crt
chmod 644 /opt/cronmanager/www/conf/root_ca.crt
```

Then set in `config.json`:

```json
"oidc_ssl_ca_bundle": "/var/www/conf/root_ca.crt"
```

The `conf/` directory is already mounted as `/var/www/conf` inside the container.

To disable certificate verification entirely (**not recommended**):
```json
"oidc_ssl_verify": false
```

### 6. SSO user provisioning

When an SSO user logs in for the first time, Cronmanager automatically creates a local
record with the **Viewer** role. An admin can promote them via the User Management page.

Deleting an SSO user's Cronmanager account does **not** revoke access on the OIDC
provider — the account will be re-created on the next login.

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
| `agent.url` | `http://host.docker.internal:8865` (host-agent) / `http://cronmanager-agent:8865` (docker) | Host agent base URL |
| `agent.hmac_secret` | | Shared HMAC secret (must match agent) |
| `agent.timeout` | `10` | HTTP timeout in seconds |
| `logging.path` | `/var/www/log/cronmanager-web.log` | Log file path |
| `logging.level` | `info` | `debug`, `info`, `warning`, `error`, `critical` |
| `logging.max_days` | `30` | Log file retention in days |
| `session.lifetime` | `3600` | Session timeout in seconds |
| `session.name` | `cronmanager_sess` | Session cookie name |
| `i18n.default_language` | `en` | Default language (`en` or `de`) |
| `auth.oidc_enabled` | `false` | Enable OIDC SSO |
| `auth.oidc_provider_url` | | OIDC provider base URL (with trailing slash) |
| `auth.oidc_client_id` | | OAuth 2.0 Client ID |
| `auth.oidc_client_secret` | | OAuth 2.0 Client Secret |
| `auth.oidc_redirect_uri` | | Callback URL (`https://your-domain/auth/callback`) |
| `auth.oidc_ssl_verify` | `true` | `true` = system CA, `false` = disable, or path to CA bundle |
| `auth.oidc_ssl_ca_bundle` | `""` | Path to custom PEM CA bundle (empty = system CA) |

### Agent config

| Key | Default | Description |
|---|---|---|
| `agent.bind_address` | `0.0.0.0` | Listen address (`127.0.0.1` to restrict to localhost) |
| `agent.port` | `8865` | Listen port |
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
| `cron.wrapper_script` | `/opt/cronmanager/agent/bin/cron-wrapper.sh` | Wrapper script path |

---

## Email Failure Alerts

Cronmanager can send an email when a cron job exits with a non-zero status code.

**To enable alerts:**

1. Set `mail.enabled = true` and fill in your SMTP credentials in the agent config
2. Restart the agent: `sudo systemctl restart cronmanager-agent`
3. Per job: check **"Notify on failure"** when creating or editing the job

Alerts are dispatched by the host agent asynchronously after the job completes — mail
sending runs in a background process so a slow or unreachable SMTP server cannot block
the agent.

**Encryption settings:**
- Port **465** (SMTPS / implicit TLS) → set `mail.encryption` to `ssl`
- Port **587** (STARTTLS) → set `mail.encryption` to `tls`

Mixing these will cause the connection to hang until the SMTP timeout is reached.

---

## Multi-Host Execution

A single cron job can execute on multiple targets simultaneously:

- **local** – Runs on the host where the agent is installed
- **SSH alias** – Runs on a remote host via an alias from `~/.ssh/config`
  of the Linux user whose crontab is managed

When a job has multiple targets, one independent crontab entry is created per target.
They all fire at the same scheduled time, run in parallel via SSH (BatchMode=yes),
and each reports its execution result back to the agent separately.

**Prerequisite for SSH targets:** the Linux user must have key-based SSH access
configured for the target host in `~/.ssh/config`. Password prompts are not supported.

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

> **Note:** After migrating from host-agent to docker mode, use **Maintenance → Crontab Sync** in the web UI to write all active jobs into the container's crontab. Without this step the container crontab will be empty and no jobs will execute.

> **Linux user requirement:** In docker mode all jobs run as `root` inside the container. Ensure every job's **Linux user** is set to `root` before running Crontab Sync.

---

## Maintenance

The **Maintenance** page (`/maintenance`, admin only) provides three operational tools for keeping the system healthy.

### Crontab Sync

Re-writes all crontab entries from the database in one click. Active jobs have their entries added or updated; inactive jobs have any lingering entries removed. Use this after migrating from host-agent to docker mode, or whenever crontab entries get out of sync with the database.

### Stuck Executions

Lists executions that have been in the "running" state longer than a configurable threshold (default: 2 hours). This happens when the agent restarted mid-execution, leaving records without a finish timestamp.

**Per-row actions:**
- **Mark Finished** – sets `exit_code = -1`, records `finished_at = NOW()`, appends a note to the output
- **Delete** – permanently removes the execution record

**Bulk actions:** rows can be selected individually or all at once with the "Select All" checkbox. The bulk toolbar appears when at least one row is selected and provides the same two actions for all selected rows at once.

The lookback threshold is adjustable with an inline hour selector without leaving the page.

### History Cleanup

Bulk-deletes finished execution records older than a configurable number of days (default: 90). Only records with a non-NULL `finished_at` are eligible; running executions are never deleted. Use this to reclaim database space on long-running installations.

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

Admins can manage accounts via **Users** in the navigation bar.

| Action | Notes |
|---|---|
| **Make Admin** | Promotes a Viewer to Admin |
| **Make Viewer** | Demotes an Admin to Viewer |
| **Delete** | Permanently removes the account |

- You cannot modify or delete your own account
- SSO users are auto-created as Viewer on first login and can be promoted by an admin
- Deleting an SSO user does not revoke their OIDC provider access

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

### "Agent unavailable" error in the web UI

**Host-agent mode:**
1. Check the agent is running:
   ```bash
   sudo systemctl status cronmanager-agent
   curl http://127.0.0.1:8865/health
   ```
2. Verify `agent.url` in the web config points to `http://host.docker.internal:8865`
3. Verify the HMAC secret matches in both config files
4. Inspect agent logs:
   ```bash
   sudo journalctl -u cronmanager-agent -n 100
   # or
   tail -f /opt/cronmanager/agent/log/cronmanager-agent.log
   ```

**Docker mode:**
1. Check the agent container is running and healthy:
   ```bash
   docker ps | grep cronmanager-agent
   docker exec cronmanager-agent curl -s http://localhost:8865/health
   ```
2. Verify `agent.url` in the web config points to `http://cronmanager-agent:8865`
3. Verify the HMAC secret matches in both config files
4. Inspect agent container logs:
   ```bash
   docker logs cronmanager-agent
   ```

### Jobs are not executing

**Host-agent mode:**
1. Verify the wrapper script is executable:
   ```bash
   chmod +x /opt/cronmanager/agent/bin/cron-wrapper.sh
   ```
2. Check the crontab for the affected user:
   ```bash
   crontab -u <linux-user> -l
   ```
3. Check the system cron log:
   ```bash
   grep CRON /var/log/syslog | tail -50
   ```
4. Test the wrapper manually:
   ```bash
   /opt/cronmanager/agent/bin/cron-wrapper.sh <job-id> local
   ```

**Docker mode:**
1. Verify the container crontab has entries (use Maintenance → Crontab Sync if empty):
   ```bash
   docker exec cronmanager-agent crontab -l
   ```
2. Verify jobs have `linux_user = root` (required in docker mode)
3. Check the cron log inside the container:
   ```bash
   docker exec cronmanager-agent grep CRON /var/log/syslog 2>/dev/null | tail -50
   # or check the agent log for execution events:
   docker logs cronmanager-agent | tail -50
   ```
4. Test the wrapper manually inside the container:
   ```bash
   docker exec cronmanager-agent /opt/cronmanager/agent/bin/cron-wrapper.sh <job-id> local
   ```

### OIDC login fails with SSL error

| Error | Cause | Fix |
|---|---|---|
| `cURL error 60` | Server certificate not trusted | Set `oidc_ssl_ca_bundle` to your CA cert path |
| `cURL error 77` | CA cert file not readable | `chmod 644 /opt/cronmanager/www/conf/root_ca.crt` |

Check the web log for details:
```bash
tail -f /opt/cronmanager/www/log/cronmanager-web.log
```

### Database connection fails

1. Check the MariaDB container health:
   ```bash
   docker inspect --format='{{.State.Health.Status}}' cronmanager-db
   ```
2. Test connectivity:
   ```bash
   docker exec cronmanager-db mariadb -u cronmanager -p<password> -e "SELECT 1"
   ```
3. Confirm passwords match across `db.credentials`, agent config, and web config

### 403 Forbidden for non-admin users

Actions like creating, editing, or deleting jobs require the Admin role.
An existing admin must promote the user at **Users → Make Admin**.
