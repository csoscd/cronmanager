#!/bin/bash
# =============================================================================
# Cronmanager – Deployment Script
#
# Configuration is read from deploy.env in the same directory.
# Database credentials are read from db.credentials in the same directory.
#
# Usage:
#   ./deploy.sh <--host-agent|--docker> [full|update|migrate] [ssh-host] [--agent|--web]
#
#   --host-agent  – Target is a host-agent installation (installs/restarts systemd service)
#   --docker      – Target is a docker-agent installation (skips systemd; use docker-compose)
#
#   full     – Create folder structure + deploy all files (default)
#   update   – Deploy only changed files (rsync checksum comparison)
#   migrate  – Migrate host-agent installation → docker-agent:
#              deploys changed files, stops+disables systemd service,
#              patches agent and web config.json for docker-mode values.
#   undeploy – Remove systemd service only (--host-agent only);
#              PHP files and config are kept on the target system.
#   --agent  – Deploy agent only  (skip web app)
#   --web    – Deploy web app only (skip agent)
#   ssh-host – Override DEPLOY_SSH from deploy.env (SSH transport only)
#
# deploy.env variables:
#   DEPLOY_TYPE          – Transport: SSH or LOCAL
#   DEPLOY_SSH           – SSH host alias from ~/.ssh/config (SSH only)
#   DEPLOY_COMPOSER      – Target path for the shared composer.json
#   DEPLOY_COMPOSER_VENDOR – Target path for the shared vendor directory
#
# Fixed deployment paths (not configurable):
#   Agent : /opt/cronmanager/agent
#   Web   : /opt/cronmanager/www
#   DB    : /opt/cronmanager/db
#
# @author  Christian Schulz <technik@meinetechnikwelt.rocks>
# @license GNU General Public License version 3 or later
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Load deploy.env
# ---------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_ENV_FILE="${SCRIPT_DIR}/deploy.env"

if [[ ! -f "${DEPLOY_ENV_FILE}" ]]; then
    echo "[deploy] ERROR: deploy.env not found at ${DEPLOY_ENV_FILE}" >&2
    echo "[deploy]        Copy deploy.env.example to deploy.env and fill in your values." >&2
    exit 1
fi

# shellcheck source=/dev/null
source "${DEPLOY_ENV_FILE}"

# Validate required deploy.env variables
for var in DEPLOY_TYPE DEPLOY_COMPOSER DEPLOY_COMPOSER_VENDOR; do
    if [[ -z "${!var:-}" ]]; then
        echo "[deploy] ERROR: '${var}' is not set in ${DEPLOY_ENV_FILE}" >&2
        exit 1
    fi
done

if [[ "${DEPLOY_TYPE}" == "SSH" && -z "${DEPLOY_SSH:-}" ]]; then
    echo "[deploy] ERROR: DEPLOY_TYPE=SSH requires DEPLOY_SSH to be set in ${DEPLOY_ENV_FILE}" >&2
    exit 1
fi

if [[ "${DEPLOY_TYPE}" != "SSH" && "${DEPLOY_TYPE}" != "LOCAL" ]]; then
    echo "[deploy] ERROR: DEPLOY_TYPE must be 'SSH' or 'LOCAL' (got: '${DEPLOY_TYPE}')" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Parse command-line arguments
# ---------------------------------------------------------------------------

DEPLOY_MODE="full"           # full = mirror; update = changed files only
DEPLOY_TARGET=""             # host-agent or docker (required)
DEPLOY_AGENT_PART=true       # deploy the host agent?
DEPLOY_WEB_PART=true         # deploy the web application?
SSH_HOST="${DEPLOY_SSH:-}"   # may be overridden by positional CLI arg

for arg in "$@"; do
    case "${arg}" in
        --host-agent)
            DEPLOY_TARGET="host-agent"
            ;;
        --docker)
            DEPLOY_TARGET="docker"
            ;;
        full|update|migrate|undeploy)
            DEPLOY_MODE="${arg}"
            ;;
        --agent)
            DEPLOY_AGENT_PART=true
            DEPLOY_WEB_PART=false
            ;;
        --web)
            DEPLOY_AGENT_PART=false
            DEPLOY_WEB_PART=true
            ;;
        -*)
            echo "[deploy] ERROR: Unknown option '${arg}'." >&2
            echo "[deploy]        Usage: ./deploy.sh <--host-agent|--docker> [full|update|migrate] [ssh-host] [--agent|--web]" >&2
            exit 1
            ;;
        *)
            # Positional non-flag: treat as SSH host override (SSH mode only)
            SSH_HOST="${arg}"
            ;;
    esac
done

if [[ -z "${DEPLOY_TARGET}" ]]; then
    echo "[deploy] ERROR: --host-agent or --docker is required." >&2
    echo "[deploy]        Usage: ./deploy.sh <--host-agent|--docker> [full|update|migrate] [ssh-host] [--agent|--web]" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Resolve source and target paths
# ---------------------------------------------------------------------------

AGENT_SRC="${SCRIPT_DIR}/agent"
DOCKER_SRC="${SCRIPT_DIR}/docker"
WEB_SRC="${SCRIPT_DIR}/web"
COMPOSER_SRC="${SCRIPT_DIR}/composer.json"
CREDENTIALS_FILE="${SCRIPT_DIR}/db.credentials"

# Fixed deployment paths
AGENT_TARGET="/opt/cronmanager/agent"
WEB_TARGET="/opt/cronmanager/www"
WEB_WWW_TARGET="${WEB_TARGET}/html"
WEB_CONF_TARGET="${WEB_TARGET}/conf"
WEB_LOG_TARGET="${WEB_TARGET}/log"
DB_DIR="/opt/cronmanager/db"
COMPOSER_DIR="${DEPLOY_COMPOSER%/}"
COMPOSER_VENDOR_DIR="${DEPLOY_COMPOSER_VENDOR%/}"

# ---------------------------------------------------------------------------
# Transport helpers (SSH vs LOCAL)
# ---------------------------------------------------------------------------

# Run a shell command string on the target system
run_on_target() {
    if [[ "${DEPLOY_TYPE}" == "SSH" ]]; then
        ssh "${SSH_HOST}" "$@"
    else
        bash -c "$*"
    fi
}

# Create a directory (and parents) on the target
mkdir_on_target() {
    run_on_target "mkdir -p '$1' && chmod 755 '$1'"
}

# Return 0 if a file exists on the target, 1 otherwise
file_exists_on_target() {
    if [[ "${DEPLOY_TYPE}" == "SSH" ]]; then
        ssh "${SSH_HOST}" "test -f '$1'" 2>/dev/null
    else
        test -f "$1" 2>/dev/null
    fi
}

# Copy a single local file to a target path
copy_to_target() {
    # $1 = local source file, $2 = target destination path
    if [[ "${DEPLOY_TYPE}" == "SSH" ]]; then
        scp "$1" "${SSH_HOST}:$2"
    else
        cp "$1" "$2"
    fi
}

# Write a local temp Python script, scp it to the target, run it, clean up.
# Avoids the SSH+heredoc stdin-consumption problem entirely.
# Usage: _run_remote_python <script_body> <target_arg>
_run_remote_python() {
    local _script_body="$1"
    local _target_arg="$2"
    local _tmp
    _tmp="$(mktemp /tmp/cm_deploy_XXXXXX.py)"
    printf '%s\n' "${_script_body}" > "${_tmp}"
    copy_to_target "${_tmp}" "/tmp/cm_deploy_patch.py"
    rm -f "${_tmp}"
    run_on_target "python3 /tmp/cm_deploy_patch.py '${_target_arg}'; rm -f /tmp/cm_deploy_patch.py"
}

# Rsync options
RSYNC_BASE="rsync -az --no-owner --no-group"
RSYNC_UPDATE="${RSYNC_BASE} --checksum"   # update: transfer only changed files
RSYNC_FULL="${RSYNC_BASE} --delete"       # full:   mirror source to target

# Transport flag and prefix for rsync commands
if [[ "${DEPLOY_TYPE}" == "SSH" ]]; then
    RSYNC_TRANSPORT="-e ssh"
    TARGET_PREFIX="${SSH_HOST}:"
else
    RSYNC_TRANSPORT=""
    TARGET_PREFIX=""
fi

# ---------------------------------------------------------------------------
# Logging helpers
# ---------------------------------------------------------------------------

log()  { echo "[deploy] $*"; }
err()  { echo "[deploy] ERROR: $*" >&2; }
die()  { err "$*"; exit 1; }
info() { echo "[deploy] INFO:  $*"; }

# ---------------------------------------------------------------------------
# Load database credentials
# ---------------------------------------------------------------------------

if [[ ! -f "${CREDENTIALS_FILE}" ]]; then
    die "Credentials file not found: ${CREDENTIALS_FILE}
       Copy db.credentials.example to db.credentials and fill in your values."
fi

# shellcheck source=/dev/null
source "${CREDENTIALS_FILE}"

for var in DB_NAME DB_USER DB_PASSWORD DB_ROOT_USER DB_ROOT_PASSWORD; do
    if [[ -z "${!var:-}" ]]; then
        die "Variable '${var}' is not set in ${CREDENTIALS_FILE}"
    fi
done

log "Credentials loaded from ${CREDENTIALS_FILE}"

# ---------------------------------------------------------------------------
# Validate and log configuration
# ---------------------------------------------------------------------------

if [[ "${DEPLOY_MODE}" != "full" && "${DEPLOY_MODE}" != "update" && "${DEPLOY_MODE}" != "migrate" && "${DEPLOY_MODE}" != "undeploy" ]]; then
    die "Unknown deploy mode '${DEPLOY_MODE}'. Use 'full', 'update', 'migrate', or 'undeploy'."
fi

if [[ "${DEPLOY_MODE}" == "undeploy" && "${DEPLOY_TARGET}" != "host-agent" ]]; then
    die "'undeploy' mode is only valid with --host-agent."
fi

log "============================================================"
log "  Deploy mode  : ${DEPLOY_MODE}"
log "  Deploy target: ${DEPLOY_TARGET}"
log "  Transport    : ${DEPLOY_TYPE}"
if [[ "${DEPLOY_TYPE}" == "SSH" ]]; then
log "  SSH host     : ${SSH_HOST}"
fi
log "  Agent target : ${AGENT_TARGET}"
log "  Web target   : ${WEB_WWW_TARGET}"
log "  DB dir       : ${DB_DIR}"
log "  Composer dir : ${COMPOSER_DIR}"
log "  Vendor dir   : ${COMPOSER_VENDOR_DIR}"
log "============================================================"

# ---------------------------------------------------------------------------
# SSH: verify connectivity and ensure rsync is available on remote
# ---------------------------------------------------------------------------

if [[ "${DEPLOY_TYPE}" == "SSH" ]]; then
    log "Checking SSH connectivity to ${SSH_HOST}..."
    if ! ssh -q -o BatchMode=yes -o ConnectTimeout=10 "${SSH_HOST}" exit 2>/dev/null; then
        die "Cannot connect to '${SSH_HOST}' via SSH. Check your ~/.ssh/config."
    fi
    log "SSH connection OK."

    if ! run_on_target "command -v rsync >/dev/null 2>&1"; then
        log "rsync not found on remote – attempting to install..."
        if run_on_target "command -v apt-get >/dev/null 2>&1"; then
            run_on_target "apt-get install -y rsync" \
                && log "rsync installed via apt-get." \
                || die "Failed to install rsync. Install it manually: apt-get install -y rsync"
        elif run_on_target "command -v yum >/dev/null 2>&1"; then
            run_on_target "yum install -y rsync" \
                && log "rsync installed via yum." \
                || die "Failed to install rsync. Install it manually: yum install -y rsync"
        else
            die "rsync not found on remote and no known package manager available."
        fi
    else
        log "rsync available on remote."
    fi
fi

# ---------------------------------------------------------------------------
# Handle composer.json on target
# ---------------------------------------------------------------------------

if [[ ! -f "${COMPOSER_SRC}" ]]; then
    log "WARNING: ${COMPOSER_SRC} not found – skipping composer handling."
else
    COMPOSER_TARGET="${COMPOSER_DIR}/composer.json"

    if file_exists_on_target "${COMPOSER_TARGET}"; then
        info "composer.json already exists at ${COMPOSER_TARGET}."
        info "This project requires the following libraries (ensure they are installed there):"
        # List require entries from our local composer.json
        if command -v python3 >/dev/null 2>&1; then
            python3 - "${COMPOSER_SRC}" <<'PYEOF'
import json, sys
try:
    with open(sys.argv[1]) as f:
        data = json.load(f)
    for pkg, ver in data.get("require", {}).items():
        if not pkg.startswith("php"):
            print(f"[deploy] INFO:    {pkg}: {ver}")
except Exception as e:
    print(f"[deploy] INFO:    (could not parse composer.json: {e})")
PYEOF
        else
            # Fallback: simple grep
            grep -E '"[a-z].+/[a-z].+"' "${COMPOSER_SRC}" \
                | grep -v '"php"' \
                | sed 's/^/[deploy] INFO:    /' || true
        fi
        info "Run 'composer install' or 'composer require ...' in ${COMPOSER_DIR} if any are missing."
    else
        log "Deploying composer.json to ${COMPOSER_TARGET}..."
        mkdir_on_target "${COMPOSER_DIR}"
        copy_to_target "${COMPOSER_SRC}" "${COMPOSER_TARGET}"
        log "composer.json deployed."
        info "Run 'cd ${COMPOSER_DIR} && composer install' on the target to install dependencies."
    fi

    # Check vendor directory
    if ! run_on_target "test -d '${COMPOSER_VENDOR_DIR}'" 2>/dev/null; then
        log "WARNING: Vendor directory ${COMPOSER_VENDOR_DIR} does not exist on target."
        log "         Run 'composer install' in ${COMPOSER_DIR} after deployment."
    else
        log "Vendor directory ${COMPOSER_VENDOR_DIR} is present."
    fi
fi

# ---------------------------------------------------------------------------
# Undeploy: remove systemd service, keep files
# ---------------------------------------------------------------------------

if [[ "${DEPLOY_MODE}" == "undeploy" ]]; then
    log "============================================================"
    log "Undeploy: removing cronmanager-agent systemd service"
    log "============================================================"
    log "Stopping and disabling cronmanager-agent..."
    run_on_target "systemctl stop    cronmanager-agent 2>/dev/null || true"
    run_on_target "systemctl disable cronmanager-agent 2>/dev/null || true"
    run_on_target "rm -f /etc/systemd/system/cronmanager-agent.service"
    run_on_target "systemctl daemon-reload"
    log "Systemd service removed."
    log "PHP files and config kept at ${AGENT_TARGET}."
    log "============================================================"
    log "Undeploy complete."
    log "============================================================"
    exit 0
fi

# ---------------------------------------------------------------------------
# Create target folder structure (always, even on update)
# ---------------------------------------------------------------------------

log "Creating target folder structure..."

if [[ "${DEPLOY_AGENT_PART}" == "true" ]]; then
    mkdir_on_target "${AGENT_TARGET}"
    mkdir_on_target "${AGENT_TARGET}/bin"
    mkdir_on_target "${AGENT_TARGET}/config"
    mkdir_on_target "${AGENT_TARGET}/src/Database"
    mkdir_on_target "${AGENT_TARGET}/src/Security"
    mkdir_on_target "${AGENT_TARGET}/src/Endpoints"
    mkdir_on_target "${AGENT_TARGET}/src/Cron"
    mkdir_on_target "${AGENT_TARGET}/src/Notification"
    mkdir_on_target "${AGENT_TARGET}/sql"
    mkdir_on_target "${AGENT_TARGET}/systemd"
    mkdir_on_target "${AGENT_TARGET}/docker"
    mkdir_on_target "${AGENT_TARGET}/docker/agent"

    mkdir_on_target "${DB_DIR}/data"
    mkdir_on_target "${DB_DIR}/conf"
    mkdir_on_target "${DB_DIR}/log"
    mkdir_on_target "${DB_DIR}/init"
fi

if [[ "${DEPLOY_WEB_PART}" == "true" ]]; then
    mkdir_on_target "${WEB_WWW_TARGET}"
    mkdir_on_target "${WEB_CONF_TARGET}"
    mkdir_on_target "${WEB_LOG_TARGET}"
    mkdir_on_target "${WEB_WWW_TARGET}/src/Http"
    mkdir_on_target "${WEB_WWW_TARGET}/src/Controller"
    mkdir_on_target "${WEB_WWW_TARGET}/src/Agent"
    mkdir_on_target "${WEB_WWW_TARGET}/src/Auth"
    mkdir_on_target "${WEB_WWW_TARGET}/src/Session"
    mkdir_on_target "${WEB_WWW_TARGET}/src/I18n"
    mkdir_on_target "${WEB_WWW_TARGET}/src/Database"
    mkdir_on_target "${WEB_WWW_TARGET}/src/Repository"
    mkdir_on_target "${WEB_WWW_TARGET}/templates/cron"
    mkdir_on_target "${WEB_WWW_TARGET}/templates/maintenance"
    mkdir_on_target "${WEB_WWW_TARGET}/lang"
    mkdir_on_target "${WEB_WWW_TARGET}/assets/css"
    mkdir_on_target "${WEB_WWW_TARGET}/assets/js"
fi

log "Folder structure ready."

# ---------------------------------------------------------------------------
# Deploy Agent
# ---------------------------------------------------------------------------

if [[ "${DEPLOY_AGENT_PART}" == "true" ]]; then
    log "Deploying agent to ${AGENT_TARGET}..."

    if [[ "${DEPLOY_MODE}" == "full" ]]; then
        # shellcheck disable=SC2086
        ${RSYNC_FULL} --exclude='config/' ${RSYNC_TRANSPORT} \
            "${AGENT_SRC}/" "${TARGET_PREFIX}${AGENT_TARGET}/"
    else
        # shellcheck disable=SC2086
        ${RSYNC_UPDATE} --exclude='config/' ${RSYNC_TRANSPORT} \
            "${AGENT_SRC}/" "${TARGET_PREFIX}${AGENT_TARGET}/"
    fi

    # Deploy example config only on full deploy if no config exists yet
    if [[ "${DEPLOY_MODE}" == "full" ]]; then
        if ! file_exists_on_target "${AGENT_TARGET}/config/config.json" 2>/dev/null; then
            log "No existing agent config – deploying example config..."
            copy_to_target "${AGENT_SRC}/config/config.json" "${AGENT_TARGET}/config/config.json"
            # Patch database.host for docker mode (container reaches DB via service name)
            if [[ "${DEPLOY_TARGET}" == "docker" ]]; then
                log "Docker mode: patching agent config database.host → cronmanager-db..."
                _run_remote_python \
'import json, sys
path = sys.argv[1]
with open(path, "r") as fh:
    cfg = json.load(fh)
if isinstance(cfg.get("database"), dict):
    cfg["database"]["host"] = "cronmanager-db"
with open(path, "w") as fh:
    json.dump(cfg, fh, indent=4, ensure_ascii=False)
print("[deploy] Agent config database.host patched.")' \
                    "${AGENT_TARGET}/config/config.json"
            fi
        else
            log "Agent config already exists – skipping (update manually if needed)."
        fi
    fi

    # Deploy docker helper files (entrypoint.sh etc.) alongside the agent
    if [[ -d "${DOCKER_SRC}" ]]; then
        # shellcheck disable=SC2086
        ${RSYNC_UPDATE} ${RSYNC_TRANSPORT} \
            "${DOCKER_SRC}/" "${TARGET_PREFIX}${AGENT_TARGET}/docker/"
        run_on_target "find '${AGENT_TARGET}/docker' -name '*.sh' -exec chmod +x {} \;" 2>/dev/null || true
        log "Docker helper files deployed to ${AGENT_TARGET}/docker/."
    fi

    # Ensure scripts are executable
    run_on_target "chmod +x '${AGENT_TARGET}/bin/start-agent.sh'"  2>/dev/null || true
    run_on_target "chmod +x '${AGENT_TARGET}/bin/cron-wrapper.sh'" 2>/dev/null || true
    run_on_target "chmod +x '${AGENT_TARGET}/bin/setup-db.php'"    2>/dev/null || true
    run_on_target "chmod +x '${AGENT_TARGET}/agent.php'"           2>/dev/null || true

    log "Agent files deployed."

    # -------------------------------------------------------------------------
    # Install / reload systemd service  (skipped in migrate mode)
    # -------------------------------------------------------------------------

    if [[ "${DEPLOY_TARGET}" == "host-agent" && "${DEPLOY_MODE}" != "migrate" ]]; then
        log "Installing systemd service..."
        run_on_target "cp '${AGENT_TARGET}/systemd/cronmanager-agent.service' /etc/systemd/system/cronmanager-agent.service"
        run_on_target "systemctl daemon-reload"
        run_on_target "systemctl enable cronmanager-agent"
        run_on_target "systemctl restart cronmanager-agent"

        run_on_target "systemctl is-active --quiet cronmanager-agent \
            && echo '[deploy] Service is running.' \
            || echo '[deploy] WARNING: Service is NOT running – check: journalctl -u cronmanager-agent'"
    elif [[ "${DEPLOY_MODE}" == "migrate" ]]; then
        log "Migrate mode: skipping systemd service install (will be stopped in migration step)."
    else
        log "Docker mode: skipping systemd service install."
    fi

    # -------------------------------------------------------------------------
    # MariaDB init script (generated from credentials)
    # -------------------------------------------------------------------------

    log "Deploying MariaDB init script..."
    run_on_target "cat > '${DB_DIR}/init/01-grants.sql'" <<EOF
-- Auto-generated by deploy.sh – do not edit manually.
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
EOF
    log "MariaDB init script deployed."

    # -------------------------------------------------------------------------
    # Ensure MariaDB user grants (handles already-running containers)
    # -------------------------------------------------------------------------

    log "Ensuring MariaDB user grants..."
    run_on_target "docker exec -i cronmanager-db mariadb \
        -u '${DB_ROOT_USER}' -p'${DB_ROOT_PASSWORD}' \
        -e \"CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}'; \
             GRANT ALL PRIVILEGES ON \\\`${DB_NAME}\\\`.* TO '${DB_USER}'@'%'; \
             FLUSH PRIVILEGES;\"" \
        && log "MariaDB user grants applied." \
        || log "WARNING: Could not apply MariaDB grants – container may not be running yet."

    # -------------------------------------------------------------------------
    # Database schema (full deploy only)
    # -------------------------------------------------------------------------

    if [[ "${DEPLOY_MODE}" == "full" ]]; then
        log "Applying database schema..."
        run_on_target "docker exec -i cronmanager-db mariadb \
            -u '${DB_USER}' -p'${DB_PASSWORD}' '${DB_NAME}' \
            < '${AGENT_TARGET}/sql/schema.sql'" \
            && log "Schema applied successfully." \
            || log "WARNING: Schema application failed – container may not be running yet. Apply manually."
    fi
fi

# ---------------------------------------------------------------------------
# Deploy Web Application
# ---------------------------------------------------------------------------

if [[ "${DEPLOY_WEB_PART}" == "true" ]]; then
    log "Deploying web application to ${WEB_WWW_TARGET}..."

    if [[ "${DEPLOY_MODE}" == "full" ]]; then
        # Exclude self-hosted JS libs so --delete does not wipe previously downloaded copies
        # shellcheck disable=SC2086
        ${RSYNC_FULL} --exclude='config/' --exclude='assets/css/tailwind.min.css' \
            --exclude='assets/js/tailwind.min.js' --exclude='assets/js/chart.min.js' \
            ${RSYNC_TRANSPORT} "${WEB_SRC}/" "${TARGET_PREFIX}${WEB_WWW_TARGET}/"
    else
        # shellcheck disable=SC2086
        ${RSYNC_UPDATE} --exclude='config/' ${RSYNC_TRANSPORT} \
            "${WEB_SRC}/" "${TARGET_PREFIX}${WEB_WWW_TARGET}/"
    fi

    # Deploy example web config only on full deploy if none exists yet
    if [[ "${DEPLOY_MODE}" == "full" ]]; then
        if ! file_exists_on_target "${WEB_CONF_TARGET}/config.json" 2>/dev/null; then
            log "No existing web config – deploying example config..."
            copy_to_target "${WEB_SRC}/config/config.json" "${WEB_CONF_TARGET}/config.json"
            # Patch agent.url for docker mode (web → agent via Docker service name)
            if [[ "${DEPLOY_TARGET}" == "docker" ]]; then
                log "Docker mode: patching web config agent.url → http://cronmanager-agent:8865..."
                _run_remote_python \
'import json, re, sys
path = sys.argv[1]
with open(path, "r") as fh:
    cfg = json.load(fh)
if isinstance(cfg.get("agent"), dict) and "url" in cfg["agent"]:
    url = cfg["agent"]["url"]
    m = re.search(r":(\d+)", url)
    port = m.group(1) if m else "8865"
    cfg["agent"]["url"] = "http://cronmanager-agent:" + port
with open(path, "w") as fh:
    json.dump(cfg, fh, indent=4, ensure_ascii=False)
print("[deploy] Web config agent.url patched.")' \
                    "${WEB_CONF_TARGET}/config.json"
            fi
        else
            log "Web config already exists – skipping."
        fi
    fi

    log "Web application deployed."

    # -------------------------------------------------------------------------
    # Set ownership on web log and conf directories (PHP-FPM runs as nobody)
    # -------------------------------------------------------------------------

    run_on_target "chown nobody:nogroup '${WEB_LOG_TARGET}' '${WEB_CONF_TARGET}'" 2>/dev/null \
        || log "WARNING: Could not set nobody:nogroup on web log/conf dirs – check manually."
    log "Ownership set: ${WEB_LOG_TARGET}, ${WEB_CONF_TARGET} → nobody:nogroup"

    # -------------------------------------------------------------------------
    # Download Tailwind CSS (skip if already present)
    # -------------------------------------------------------------------------

    TAILWIND_TARGET="${WEB_WWW_TARGET}/assets/js/tailwind.min.js"
    if ! run_on_target "test -f '${TAILWIND_TARGET}'" 2>/dev/null; then
        log "Downloading Tailwind CSS (Play CDN script)..."
        run_on_target "mkdir -p '$(dirname "${TAILWIND_TARGET}")' && \
            curl -sL https://cdn.tailwindcss.com/3.4.17 -o '${TAILWIND_TARGET}'" \
            && log "Tailwind downloaded to ${TAILWIND_TARGET}." \
            || log "WARNING: Tailwind download failed – download manually to ${TAILWIND_TARGET}"
    else
        log "Tailwind already present – skipping."
    fi

    # -------------------------------------------------------------------------
    # Download Chart.js (required by the monitor page; skip if already present)
    # The UMD build is used so it works as a plain <script> tag without bundler.
    # -------------------------------------------------------------------------

    CHARTJS_TARGET="${WEB_WWW_TARGET}/assets/js/chart.min.js"
    if ! run_on_target "test -f '${CHARTJS_TARGET}'" 2>/dev/null; then
        log "Downloading Chart.js 4 (UMD build)..."
        run_on_target "mkdir -p '$(dirname "${CHARTJS_TARGET}")' && \
            curl -sL 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js' \
                 -o '${CHARTJS_TARGET}'" \
            && log "Chart.js downloaded to ${CHARTJS_TARGET}." \
            || log "WARNING: Chart.js download failed – download manually to ${CHARTJS_TARGET}"
    else
        log "Chart.js already present – skipping."
    fi
fi

# ---------------------------------------------------------------------------
# Migration: host-agent → docker-agent
# ---------------------------------------------------------------------------

if [[ "${DEPLOY_MODE}" == "migrate" ]]; then

    AGENT_CONFIG_FILE="${AGENT_TARGET}/config/config.json"
    WEB_CONFIG_FILE="${WEB_CONF_TARGET}/config.json"

    log "============================================================"
    log "Migration: host-agent → docker-agent"
    log "============================================================"

    # -- Stop and disable the systemd service --------------------------------

    log "Stopping and disabling cronmanager-agent systemd service..."
    run_on_target "systemctl stop    cronmanager-agent 2>/dev/null || true"
    run_on_target "systemctl disable cronmanager-agent 2>/dev/null || true"
    log "Systemd service stopped and disabled."

    # -- Remove managed crontab entries from all host users ------------------
    # The host agent wrote wrapper-script entries into per-user crontabs.
    # Strip every line referencing the cron-wrapper.sh so orphaned host jobs
    # no longer execute after the agent moves into the container.

    log "Removing managed crontab entries from host user crontabs..."
    run_on_target "
_removed=0
for _user in \$(cut -d: -f1 /etc/passwd); do
    if crontab -u \"\$_user\" -l 2>/dev/null | grep -qF 'cron-wrapper.sh'; then
        ( crontab -u \"\$_user\" -l 2>/dev/null \
            | grep -vF 'cron-wrapper.sh' ) \
            | crontab -u \"\$_user\" -
        echo \"[deploy] Cleared crontab entries for user: \$_user\"
        _removed=1
    fi
done
[ \"\$_removed\" -eq 0 ] && echo '[deploy] No managed crontab entries found on host.'
"
    log "Host crontab cleanup complete."

    # -- Patch agent config.json ---------------------------------------------
    # Actual config keys: database.host, cron.wrapper_script, logging.path

    if run_on_target "test -f '${AGENT_CONFIG_FILE}'" 2>/dev/null; then
        log "Patching agent config.json (database.host, cron paths, logging.path)..."
        _run_remote_python \
'import json, sys
path = sys.argv[1]
with open(path, "r") as fh:
    cfg = json.load(fh)
if isinstance(cfg.get("database"), dict):
    cfg["database"]["host"] = "cronmanager-db"
if isinstance(cfg.get("cron"), dict):
    cfg["cron"]["wrapper_script"] = "/opt/cronmanager/agent/bin/cron-wrapper.sh"
if isinstance(cfg.get("logging"), dict):
    old = cfg["logging"].get("path", "")
    fname = old.split("/")[-1] if "/" in old else "cronmanager-agent.log"
    cfg["logging"]["path"] = "/opt/cronmanager/agent/log/" + fname
with open(path, "w") as fh:
    json.dump(cfg, fh, indent=4, ensure_ascii=False)
print("[deploy] Agent config patched successfully.")' \
            "${AGENT_CONFIG_FILE}"
        log "Agent config patched."
    else
        log "WARNING: Agent config not found at ${AGENT_CONFIG_FILE} – patch manually:"
        log "         database.host        → cronmanager-db"
        log "         cron.wrapper_script  → /opt/cronmanager/agent/bin/cron-wrapper.sh"
        log "         logging.path         → /opt/cronmanager/agent/log/cronmanager-agent.log"
    fi

    # -- Patch web config.json -----------------------------------------------
    # Changes: agent.url host.docker.internal:PORT → cronmanager-agent:PORT

    if run_on_target "test -f '${WEB_CONFIG_FILE}'" 2>/dev/null; then
        log "Patching web config.json (agent.url → docker service name)..."
        _run_remote_python \
'import json, re, sys
path = sys.argv[1]
with open(path, "r") as fh:
    cfg = json.load(fh)
if isinstance(cfg.get("agent"), dict) and "url" in cfg["agent"]:
    url = cfg["agent"]["url"]
    m = re.search(r":(\d+)", url)
    port = m.group(1) if m else "8865"
    cfg["agent"]["url"] = "http://cronmanager-agent:" + port
with open(path, "w") as fh:
    json.dump(cfg, fh, indent=4, ensure_ascii=False)
print("[deploy] Web config patched successfully.")' \
            "${WEB_CONFIG_FILE}"
        log "Web config patched."
    else
        log "WARNING: Web config not found at ${WEB_CONFIG_FILE} – patch agent.url manually:"
        log "         agent.url → http://cronmanager-agent:<port>"
    fi

    log "============================================================"
    log "Migration steps complete. Manual steps remaining:"
    log "  1. Replace your docker-compose.yml with docker/docker-compose-agent.yml"
    log "     from the source repository (paths are already standardised)."
    log "  2. Restart the agent container to pick up the new config:"
    log "       docker restart cronmanager-agent"
    log "  3. Verify agent health:"
    log "       docker exec cronmanager-agent curl -s http://localhost:8865/health"
    log "  4. Open the web UI → Maintenance → Crontab Sync to write all active"
    log "     jobs into the agent container's crontab."
    log "  NOTE: cron jobs run as root inside the container. Ensure linux_user"
    log "        is set to 'root' for all jobs, or update them before the sync."
    log "============================================================"
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

log "============================================================"
log "Deployment complete!"
log "  Deploy mode  : ${DEPLOY_MODE}"
log "  Transport    : ${DEPLOY_TYPE}"
if [[ "${DEPLOY_TYPE}" == "SSH" ]]; then
log "  SSH host     : ${SSH_HOST}"
fi
if [[ "${DEPLOY_AGENT_PART}" == "true" ]]; then
log "  Agent        : ${AGENT_TARGET}"
fi
if [[ "${DEPLOY_WEB_PART}" == "true" ]]; then
log "  Web          : ${WEB_WWW_TARGET}"
fi
log "============================================================"
if [[ "${DEPLOY_AGENT_PART}" == "true" && "${DEPLOY_WEB_PART}" == "true" ]]; then
log "Next steps:"
log "  1. Review and adjust ${AGENT_TARGET}/config/config.json"
log "  2. Review and adjust ${WEB_CONF_TARGET}/config.json"
if [[ "${DEPLOY_TARGET}" == "docker" ]]; then
log "  3. Deploy Docker stack via Portainer:"
log "       - Paste the contents of docker/docker-compose-agent.yml into a new Portainer stack"
log "       - Add the following environment variables in Portainer:"
log "           DB_NAME          = ${DB_NAME}"
log "           DB_USER          = ${DB_USER}"
log "           DB_PASSWORD      = (your password)"
log "           DB_ROOT_PASSWORD = (your root password)"
log "  4. Apply DB schema:    docker exec -i cronmanager-db mariadb -u ${DB_USER} -p${DB_PASSWORD} ${DB_NAME} < ${AGENT_TARGET}/sql/schema.sql"
log "  5. Test agent health:  docker exec cronmanager-agent curl -s http://localhost:8865/health"
else
log "  3. Deploy Docker stack via Portainer:"
log "       - Paste the contents of docker/docker-compose.yml into a new Portainer stack"
log "       - Add the following environment variables in Portainer:"
log "           DB_NAME          = ${DB_NAME}"
log "           DB_USER          = ${DB_USER}"
log "           DB_PASSWORD      = (your password)"
log "           DB_ROOT_PASSWORD = (your root password)"
log "  4. Apply DB schema:    docker exec -i cronmanager-db mariadb -u ${DB_USER} -p${DB_PASSWORD} ${DB_NAME} < ${AGENT_TARGET}/sql/schema.sql"
log "  5. Test agent health:  curl http://localhost:8865/health"
fi
log "  6. Open web UI:        http://<host>:8880/ (first visit creates the admin account)"
fi
