#!/bin/bash
# =============================================================================
# Cronmanager Host Agent – Start Script
#
# Reads bind_address and port from config.json via PHP/Noodlehaus and then
# launches the PHP built-in server with the correct listen address.
#
# Used by the systemd service unit as ExecStart.
#
# @author  Christian Schulz <technik@meinetechnikwelt.rocks>
# @license GNU General Public License version 3 or later
# =============================================================================

set -euo pipefail

# Path to the production configuration file
CONFIG="/opt/cronmanager/agent/config/config.json"
AGENT_PHP="/opt/cronmanager/agent/agent.php"
CLEANUP_PHP="/opt/cronmanager/agent/bin/startup-cleanup.php"
AUTOLOAD="/opt/phplib/vendor/autoload.php"

# ---------------------------------------------------------------------------
# Resolve bind address, port, and optional web pool settings from config.json
# ---------------------------------------------------------------------------
BIND=$(php -r "
    require '${AUTOLOAD}';
    \$c = new \Noodlehaus\Config('${CONFIG}');
    echo \$c->get('agent.bind_address', '0.0.0.0') . ':' . \$c->get('agent.port', 8865);
")

WEB_PORT=$(php -r "
    require '${AUTOLOAD}';
    \$c = new \Noodlehaus\Config('${CONFIG}');
    echo \$c->get('agent.web_port', '');
")

WEB_WORKERS=$(php -r "
    require '${AUTOLOAD}';
    \$c = new \Noodlehaus\Config('${CONFIG}');
    echo \$c->get('agent.web_workers', 6);
")

echo "[cronmanager-agent] Starting PHP built-in server on ${BIND}"
echo "[cronmanager-agent] Agent script: ${AGENT_PHP}"

# ---------------------------------------------------------------------------
# Startup cleanup: mark any orphaned running executions as interrupted.
# Runs once before the HTTP server starts so stale records are resolved
# before the first incoming request.  Failure is non-fatal.
# ---------------------------------------------------------------------------
echo "[cronmanager-agent] Running startup cleanup..."
/usr/bin/php "${CLEANUP_PHP}" || echo "[cronmanager-agent] WARNING: startup cleanup encountered an error (agent will still start)"

# ---------------------------------------------------------------------------
# Start the PHP built-in server(s)
#
# Single-pool mode (default, web_port not set):
#   exec replaces the shell process so systemd tracks the correct PID.
#
# Dual-pool mode (agent.web_port configured):
#   Web pool starts in the background; exec pool runs in the foreground via
#   'wait'. A trap ensures both PHP processes are terminated when systemd
#   sends SIGTERM to this shell script.
#
# PHP_CLI_SERVER_WORKERS: without it the built-in server is single-threaded
# and serialises all requests.
# opcache.enable_cli: the CLI SAPI has OPcache disabled by default, causing
# every request to re-compile the whole codebase (~20-60 ms overhead).
# ---------------------------------------------------------------------------

if [[ -n "${WEB_PORT}" ]]; then
    echo "[cronmanager-agent] Starting web pool on 0.0.0.0:${WEB_PORT} (${WEB_WORKERS} workers)"
    PHP_CLI_SERVER_WORKERS="${WEB_WORKERS}" /usr/bin/php \
        -d opcache.enable=1 \
        -d opcache.enable_cli=1 \
        -d opcache.jit=off \
        -S "0.0.0.0:${WEB_PORT}" "${AGENT_PHP}" &
    WEB_PHP_PID=$!
    echo "[cronmanager-agent] Web pool started (PID ${WEB_PHP_PID})"

    export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}"
    echo "[cronmanager-agent] Starting exec pool on ${BIND} (${PHP_CLI_SERVER_WORKERS} workers)"
    /usr/bin/php \
        -d opcache.enable=1 \
        -d opcache.enable_cli=1 \
        -d opcache.jit=off \
        -S "${BIND}" "${AGENT_PHP}" &
    EXEC_PHP_PID=$!

    # Kill both pools when this script exits (systemd SIGTERM, manual stop, etc.)
    trap "kill ${WEB_PHP_PID} ${EXEC_PHP_PID} 2>/dev/null; wait ${WEB_PHP_PID} ${EXEC_PHP_PID} 2>/dev/null; exit 0" EXIT TERM INT

    wait ${EXEC_PHP_PID}
else
    export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}"

    exec /usr/bin/php \
        -d opcache.enable=1 \
        -d opcache.enable_cli=1 \
        -d opcache.jit=off \
        -S "${BIND}" "${AGENT_PHP}"
fi
