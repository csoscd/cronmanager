#!/usr/bin/env bash
# =============================================================================
# Cronmanager – Full Test Runner
#
# Starts the test Docker stack, waits for MariaDB to become ready, runs all
# configured test suites, then tears down the stack regardless of test outcome.
#
# Usage:
#   ./run-tests.sh              # unit + integration
#   ./run-tests.sh unit         # unit only  (no Docker needed)
#   ./run-tests.sh integration  # integration only
#
# Exit code mirrors PHPUnit: 0 = all green, non-zero = failures/errors.
#
# @author  Christian Schulz <technik@meinetechnikwelt.rocks>
# @license GNU General Public License version 3 or later
# =============================================================================

set -euo pipefail

# -----------------------------------------------------------------------------
# Config
# -----------------------------------------------------------------------------
COMPOSE_FILE="tests/docker-compose.test.yml"
DB_CONTAINER="tests-cronmanager-test-db-1"
DB_TIMEOUT=60   # seconds to wait for MariaDB to become healthy
PHPUNIT="./vendor/bin/phpunit"

# Colour codes (disabled when not a terminal)
if [ -t 1 ]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RESET='\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; RESET=''
fi

info()    { echo -e "${GREEN}[run-tests]${RESET} $*"; }
warn()    { echo -e "${YELLOW}[run-tests]${RESET} $*"; }
error()   { echo -e "${RED}[run-tests]${RESET} $*" >&2; }

# -----------------------------------------------------------------------------
# Argument handling
# -----------------------------------------------------------------------------
SUITE="${1:-unit,integration}"

NEED_DOCKER=true
if [[ "$SUITE" == "unit" ]]; then
    NEED_DOCKER=false
fi

# -----------------------------------------------------------------------------
# Sanity checks
# -----------------------------------------------------------------------------
if [ ! -f "$PHPUNIT" ]; then
    error "vendor/bin/phpunit not found – run 'composer install' first."
    exit 1
fi

# -----------------------------------------------------------------------------
# Docker stack lifecycle
# -----------------------------------------------------------------------------
start_stack() {
    info "Starting test Docker stack ($COMPOSE_FILE)…"
    docker compose -f "$COMPOSE_FILE" up -d
}

wait_for_db() {
    info "Waiting for MariaDB to become ready (timeout: ${DB_TIMEOUT}s)…"
    local elapsed=0
    until docker exec "$DB_CONTAINER" \
            mariadb-admin ping -h localhost -u root -proot_test --silent 2>/dev/null; do
        if (( elapsed >= DB_TIMEOUT )); then
            error "MariaDB did not become ready within ${DB_TIMEOUT}s."
            docker compose -f "$COMPOSE_FILE" logs "$DB_CONTAINER" | tail -20
            return 1
        fi
        sleep 2
        (( elapsed += 2 ))
    done
    info "MariaDB is ready (${elapsed}s)."
}

stop_stack() {
    info "Stopping test Docker stack…"
    docker compose -f "$COMPOSE_FILE" down
}

# -----------------------------------------------------------------------------
# Main
# -----------------------------------------------------------------------------
EXIT_CODE=0

if $NEED_DOCKER; then
    start_stack
    # Always tear down on exit, even if tests fail or the script is interrupted
    trap stop_stack EXIT
    wait_for_db
fi

info "Running PHPUnit suite(s): ${SUITE}"
echo ""

"$PHPUNIT" --testsuite "$SUITE" || EXIT_CODE=$?

echo ""
if [ $EXIT_CODE -eq 0 ]; then
    info "All tests passed."
else
    error "Tests finished with failures (exit code ${EXIT_CODE})."
fi

exit $EXIT_CODE
