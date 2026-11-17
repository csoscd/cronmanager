#!/bin/bash
#
# Cronmanager Agent – Docker health check
#
# Checks both pools (exec and optional web) via their nginx-exposed ports so
# that a failed nginx or a crashed PHP worker pool is detected immediately.
#
# TLS mode  (AGENT_TLS_ENABLED=true):  curl hits the HTTPS port with --insecure
#   because the auto-generated certificate is self-signed and not trusted by the
#   container's CA bundle.
# Plain mode:  curl hits the plain-HTTP port directly.
#
# Exit codes: 0 = healthy, 1 = unhealthy (Docker interprets any non-zero as fail).

set -euo pipefail

SCHEME="http"
CURL_OPTS=(-sf --max-time 5)

if [[ "${AGENT_TLS_ENABLED:-false}" == "true" ]]; then
    SCHEME="https"
    CURL_OPTS+=("--insecure")
fi

EXEC_PORT="${AGENT_PORT:-8865}"

# ── Exec pool (always required) ──────────────────────────────────────────────
curl "${CURL_OPTS[@]}" "${SCHEME}://127.0.0.1:${EXEC_PORT}/health" -o /dev/null \
    || { echo "exec pool unhealthy (${SCHEME}://127.0.0.1:${EXEC_PORT}/health)"; exit 1; }

# ── Web pool (optional – only when AGENT_WEB_PORT is set) ────────────────────
if [[ -n "${AGENT_WEB_PORT:-}" ]]; then
    curl "${CURL_OPTS[@]}" "${SCHEME}://127.0.0.1:${AGENT_WEB_PORT}/health" -o /dev/null \
        || { echo "web pool unhealthy (${SCHEME}://127.0.0.1:${AGENT_WEB_PORT}/health)"; exit 1; }
fi

exit 0
