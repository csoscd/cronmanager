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
# Resolve bind address and port from config.json using PHP + Noodlehaus
# ---------------------------------------------------------------------------
BIND=$(php -r "
    require '${AUTOLOAD}';
    \$c = new \Noodlehaus\Config('${CONFIG}');
    echo \$c->get('agent.bind_address', '0.0.0.0') . ':' . \$c->get('agent.port', 8865);
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
# Start the PHP built-in server
# exec replaces the shell process so systemd tracks the correct PID
# ---------------------------------------------------------------------------
exec /usr/bin/php -S "${BIND}" "${AGENT_PHP}"
