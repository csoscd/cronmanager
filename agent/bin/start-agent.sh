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
CONFIG="/opt/phpscripts/cronmanager/agent/config/config.json"
AGENT_PHP="/opt/phpscripts/cronmanager/agent/agent.php"
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
# Start the PHP built-in server
# exec replaces the shell process so systemd tracks the correct PID
# ---------------------------------------------------------------------------
exec /usr/bin/php -S "${BIND}" "${AGENT_PHP}"
