# Host-Agent Installation

This document describes running Cronmanager in **host-agent mode**: the PHP agent runs as
a systemd service directly on the Docker host, while the web UI and MariaDB still run as
Docker containers.

For the simpler recommended installation using pre-built Docker images for all three
components, see the [Docker Hub section in README.md](README.md#docker-hub--recommended-installation).

---

## Table of Contents

1. [Architecture](#architecture)
2. [Prerequisites](#prerequisites)
3. [Deployment with deploy.sh](#deployment-with-deploysh)
4. [Step 1 – Install PHP and shared libraries](#step-1--install-php-and-shared-libraries-on-the-host)
5. [Step 2 – Deploy the files](#step-2--deploy-the-files)
6. [Step 3 – Configure the host agent](#step-3--configure-the-host-agent)
7. [Step 4 – Start the host agent service](#step-4--start-the-host-agent-service)
8. [Step 5 – Configure the web application](#step-5--configure-the-web-application)
9. [Step 6 – Start the Docker stack](#step-6--start-the-docker-stack)
10. [Step 7 – First login](#step-7--first-login)
11. [Configuration Reference](#configuration-reference)
12. [Reading the Crontab](#reading-the-crontab)
13. [Updating](#updating)
14. [Migrating to Docker mode](#migrating-to-docker-mode)

---

## Architecture

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
Communication is encrypted with TLS; see [Agent TLS](README.md#agent-tls) for details.

---

## Prerequisites

| Component | Requirement |
|---|---|
| Docker + Docker Compose | v2.0 or later |
| PHP on the **host** | 8.4 with extensions: `cli`, `json`, `pdo_mysql`, `openssl`, `mbstring` |
| Composer | 2.x (to install shared PHP libraries) |
| curl | For the cron wrapper script |
| openssl | For HMAC-SHA256 signing in the wrapper |
| SSH client | Required only for remote job execution |

---

## Deployment with deploy.sh

`deploy.sh` automates the file deployment, directory creation, systemd service installation,
and database initialisation. It supports local and SSH (remote) targets.

### Configure deployment

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

### Configure database credentials

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

### Run the full deployment

```bash
./deploy.sh --host-agent full
```

The script will:
- Create all required directories on the target
- Sync all application files via rsync
- Deploy the example configuration files (only if no config exists yet)
- Generate the MariaDB init script from your credentials
- Install and enable the systemd service and start the agent

---

## Step 1 – Install PHP and shared libraries on the host

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

## Step 2 – Deploy the files

After completing Step 1 and configuring `deploy.env` and `db.credentials` as shown above,
run the deployment:

```bash
./deploy.sh --host-agent full
```

The script creates all required directories, syncs all application files, deploys the
example configuration files (only if no config exists yet), generates the MariaDB init
script, and installs the systemd service.

> Deployment paths are fixed: agent → `/opt/cronmanager/agent`, web → `/opt/cronmanager/www`, DB → `/opt/cronmanager/db`.

---

## Step 3 – Configure the host agent

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

## Step 4 – Start the host agent service

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

## Step 5 – Configure the web application

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

`host.docker.internal` resolves to the Docker host from within the web container and is
configured automatically via the `extra_hosts` entry in `docker/docker-compose.yml`.

`ssl_verify: false` is correct for the default self-signed certificate. Set it to `true`
and supply `ssl_ca_bundle` when using a certificate signed by a private CA.

---

## Step 6 – Start the Docker stack

**Option A – docker compose directly on the host:**

```bash
cd /opt/cronmanager/www

export DB_NAME=cronmanager
export DB_USER=cronmanager
export DB_PASSWORD=<your-password>
export DB_ROOT_PASSWORD=<your-root-password>

docker compose up -d
```

**Option B – Portainer:**

1. Open Portainer → Stacks → Add Stack
2. Paste the contents of `docker/docker-compose.yml`
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

## Step 7 – First login

Open `http://<your-host>:8880/` in your browser.

If no users exist in the database yet, you are automatically redirected to the
**Setup wizard**:

1. Enter a username for the initial admin account
2. Enter and confirm a password (minimum 8 characters)
3. Click **Create admin account**

You are then redirected to the login page. Log in with the credentials you just created.

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
| `agent.url` | `https://host.docker.internal:8865` | Host agent base URL |
| `agent.hmac_secret` | | Shared HMAC secret (must match agent) |
| `agent.timeout` | `10` | HTTP timeout in seconds |
| `agent.ssl_verify` | `false` | `false` = accept self-signed cert; `true` = require trusted CA; path string = use custom CA bundle |
| `agent.ssl_ca_bundle` | `""` | Path to a PEM CA bundle inside the container |
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
| `mail.host` | `""` | SMTP hostname for web UI emails (invitations, password reset); empty = disabled |
| `mail.port` | `587` | SMTP port |
| `mail.username` | `""` | SMTP username |
| `mail.password` | `""` | SMTP password |
| `mail.from` | `""` | Sender address |
| `mail.from_name` | `Cronmanager` | Sender display name |
| `mail.encryption` | `tls` | `tls` (STARTTLS) or `ssl` (SMTPS) |

### Agent config

| Key | Default | Description |
|---|---|---|
| `agent.bind_address` | `0.0.0.0` | Bind address for the internal PHP server |
| `agent.port` | `8865` | External port (nginx TLS) |
| `agent.tls_enabled` | `true` | Enable nginx TLS terminator; read by `cron-wrapper.sh` to decide HTTP vs HTTPS |
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

## Reading the Crontab

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

---

## Updating

Deploy only changed files (configuration files are never overwritten):

```bash
./deploy.sh --host-agent update
```

Restart the host agent to load code changes:

```bash
sudo systemctl restart cronmanager-agent
```

Apply database migrations when indicated in the release notes:

```bash
ssh myserver 'docker exec -i cronmanager-db mariadb \
    -u cronmanager -p<password> cronmanager \
    < /opt/cronmanager/agent/sql/migrations/<migration-file>.sql'
```

---

## Migrating to Docker mode

To move from host-agent mode to the fully-containerised Docker mode (agent in a container),
run the migration helper:

```bash
./deploy.sh --host-agent migrate
```

The script will:
1. Stop and disable the systemd service
2. Remove managed crontab entries from all host user crontabs
3. Patch `agent/config/config.json` (database host, wrapper path, log path)
4. Patch `www/conf/config.json` (agent URL → Docker service name)

After the script completes, follow the remaining manual steps printed in the summary.
