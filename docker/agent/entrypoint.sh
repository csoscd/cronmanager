#!/usr/bin/env bash
# =============================================================================
# Cronmanager Agent Container – entrypoint.sh
#
# Purpose:
#   Container entrypoint for the docker-only deployment mode.
#   1. Fixes SSH key permissions (host-mounted keys are often 644).
#   2. Starts the system cron daemon in the background.
#   3. Reads agent bind address and port from config.json.
#   4. Starts the PHP built-in server for the Cronmanager Host Agent.
#
# The PHP CLI server (foreground) is the main process (PID 1-equivalent
# after exec), so Docker's signal handling works correctly.
# The cron daemon runs as a background child process.
#
# Environment variables:
#   AGENT_DIR   – Path inside the container where the agent source is mounted.
#                 Default: /opt/cronmanager/agent
#
# @author  Christian Schulz <technik@meinetechnikwelt.rocks>
# @license GNU General Public License version 3 or later
# =============================================================================

set -uo pipefail

AGENT_DIR="${AGENT_DIR:-/opt/cronmanager/agent}"
CONFIG_FILE="${AGENT_DIR}/config/config.json"

# ── Logging helpers ───────────────────────────────────────────────────────────
log_info()  { echo "[entrypoint] [INFO]  $(date -Iseconds) $*"; }
log_warn()  { echo "[entrypoint] [WARN]  $(date -Iseconds) $*"; }
log_error() { echo "[entrypoint] [ERROR] $(date -Iseconds) $*" >&2; }

# =============================================================================
# 1. Fix SSH key permissions
#    The host's ~/.ssh directory is mounted read-only.  SSH refuses to use
#    keys if the directory or files have permissions wider than 0700/0600.
#    We copy the keys to a writable location and fix permissions there.
# =============================================================================

if [[ -d /root/.ssh ]]; then
    # The mount is read-only, but we can at least check connectivity.
    # SSH only cares about the permissions of the *actual* files, so we
    # ensure the owning user's umask is correct within the container.
    # If the host fs exports the files with correct permissions no action
    # is needed; if not, copy to a writable location.

    SSH_PERMS_OK=true

    # Check directory permission
    _dir_perm=$(stat -c '%a' /root/.ssh 2>/dev/null || echo "000")
    if [[ "$_dir_perm" != "700" && "$_dir_perm" != "600" ]]; then
        SSH_PERMS_OK=false
    fi

    if [[ "$SSH_PERMS_OK" == false ]]; then
        log_warn "/root/.ssh permissions may be too open ($_dir_perm). Copying to writable location..."
        mkdir -p /root/.ssh-rw
        cp -rp /root/.ssh/. /root/.ssh-rw/ 2>/dev/null || true
        chmod 700 /root/.ssh-rw
        find /root/.ssh-rw -type f -exec chmod 600 {} \;
        # Redirect SSH config to the writable copy
        export HOME_SSH=/root/.ssh-rw
        log_info "SSH keys copied to /root/.ssh-rw with correct permissions."
    else
        log_info "SSH key permissions look correct."
    fi
else
    log_warn "/root/.ssh not found – remote (SSH) targets will not be available."
fi

# =============================================================================
# 2. Start cron daemon
# =============================================================================

log_info "Starting cron daemon..."
cron -f &
CRON_PID=$!
log_info "Cron daemon started (PID ${CRON_PID})."

# =============================================================================
# 3. Read bind address and port from config.json
# =============================================================================

BIND_ADDRESS="0.0.0.0"
PORT="8865"

if [[ -f "$CONFIG_FILE" ]]; then
    _parsed=$(php -r "
        \$c = json_decode(file_get_contents('${CONFIG_FILE}'), true);
        if (!\$c) { echo '0.0.0.0 8865'; exit; }
        echo (\$c['agent']['bind_address'] ?? '0.0.0.0') . ' ' . (\$c['agent']['port'] ?? '8865');
    " 2>/dev/null || echo "0.0.0.0 8865")
    BIND_ADDRESS="${_parsed%% *}"
    PORT="${_parsed##* }"
    log_info "Config loaded: bind=${BIND_ADDRESS} port=${PORT}"
else
    log_warn "Config file not found at ${CONFIG_FILE} – using defaults (0.0.0.0:8865)."
fi

AGENT_PHP="${AGENT_DIR}/agent.php"
if [[ ! -f "$AGENT_PHP" ]]; then
    log_error "Agent entry point not found: ${AGENT_PHP}"
    exit 1
fi

# =============================================================================
# 4. Start PHP built-in server (foreground – becomes main process)
# =============================================================================

log_info "Starting Cronmanager agent on ${BIND_ADDRESS}:${PORT}"
exec php -S "${BIND_ADDRESS}:${PORT}" "${AGENT_PHP}"
