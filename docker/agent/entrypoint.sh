#!/usr/bin/env bash
# =============================================================================
# Cronmanager Agent Container – entrypoint.sh
#
# Steps executed on every container start:
#   1. Generate /opt/cronmanager/agent/config/config.json from environment variables.
#   2. Wait for MariaDB and apply schema.sql if the 'cronjobs' table is missing.
#   3. Fix SSH key permissions (host-mounted keys are often 644).
#   4. Start the system cron daemon in the background.
#   5. Read bind address and port from the generated config and start the PHP
#      built-in server as the foreground process (PID 1 equivalent).
#
# Required environment variables:
#   AGENT_HMAC_SECRET   – shared secret for HMAC-SHA256 request signing
#   DB_PASSWORD         – MariaDB password for the application user
#
# Optional environment variables (shown with their defaults):
#   AGENT_BIND_ADDRESS  0.0.0.0
#   AGENT_PORT          8865
#   DB_HOST             cronmanager-db
#   DB_PORT             3306
#   DB_NAME             cronmanager
#   DB_USER             cronmanager
#   LOG_PATH            /opt/cronmanager/agent/log/cronmanager-agent.log
#   LOG_LEVEL           info
#   LOG_MAX_DAYS        30
#   MAIL_ENABLED        false
#   MAIL_HOST           smtp.example.com
#   MAIL_PORT           587
#   MAIL_USERNAME       ""
#   MAIL_PASSWORD       ""
#   MAIL_FROM           alerts@example.com
#   MAIL_FROM_NAME      Cronmanager
#   MAIL_TO             admin@example.com
#   MAIL_ENCRYPTION     tls
#   CRON_WRAPPER_SCRIPT /opt/cronmanager/agent/bin/cron-wrapper.sh
#
# @author  Christian Schulz <technik@meinetechnikwelt.rocks>
# @license GNU General Public License version 3 or later
# =============================================================================

set -uo pipefail

AGENT_DIR="${AGENT_DIR:-/opt/cronmanager/agent}"
CONFIG_FILE="${AGENT_DIR}/config/config.json"
SCHEMA_FILE="${SCHEMA_FILE:-/opt/cronmanager/schema.sql}"

# ── Logging helpers ───────────────────────────────────────────────────────────
log_info()  { echo "[entrypoint] [INFO]  $(date -Iseconds) $*"; }
log_warn()  { echo "[entrypoint] [WARN]  $(date -Iseconds) $*"; }
log_error() { echo "[entrypoint] [ERROR] $(date -Iseconds) $*" >&2; }

# =============================================================================
# 1. Generate config.json from environment variables
# =============================================================================

log_info "Generating ${CONFIG_FILE} from environment variables..."

# Validate required variables
if [[ -z "${AGENT_HMAC_SECRET:-}" ]]; then
    log_error "AGENT_HMAC_SECRET is required but not set."
    exit 1
fi
if [[ -z "${DB_PASSWORD:-}" ]]; then
    log_error "DB_PASSWORD is required but not set."
    exit 1
fi

mkdir -p "${AGENT_DIR}/config"

php -r "
\$config = [
    'agent' => [
        'bind_address' => getenv('AGENT_BIND_ADDRESS') ?: '0.0.0.0',
        'port'         => (int)(getenv('AGENT_PORT') ?: 8865),
        'hmac_secret'  => getenv('AGENT_HMAC_SECRET'),
    ],
    'database' => [
        'host'     => getenv('DB_HOST') ?: 'cronmanager-db',
        'port'     => (int)(getenv('DB_PORT') ?: 3306),
        'name'     => getenv('DB_NAME') ?: 'cronmanager',
        'user'     => getenv('DB_USER') ?: 'cronmanager',
        'password' => getenv('DB_PASSWORD'),
    ],
    'logging' => [
        'path'     => getenv('LOG_PATH') ?: '/opt/cronmanager/agent/log/cronmanager-agent.log',
        'level'    => getenv('LOG_LEVEL') ?: 'info',
        'max_days' => (int)(getenv('LOG_MAX_DAYS') ?: 30),
    ],
    'mail' => [
        'enabled'   => filter_var(getenv('MAIL_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'host'      => getenv('MAIL_HOST') ?: 'smtp.example.com',
        'port'      => (int)(getenv('MAIL_PORT') ?: 587),
        'username'  => getenv('MAIL_USERNAME') ?: '',
        'password'  => getenv('MAIL_PASSWORD') ?: '',
        'from'      => getenv('MAIL_FROM') ?: 'alerts@example.com',
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'Cronmanager',
        'to'        => getenv('MAIL_TO') ?: 'admin@example.com',
        'encryption'=> getenv('MAIL_ENCRYPTION') ?: 'tls',
    ],
    'cron' => [
        'wrapper_script' => getenv('CRON_WRAPPER_SCRIPT') ?: '/opt/cronmanager/agent/bin/cron-wrapper.sh',
    ],
];
file_put_contents('${CONFIG_FILE}', json_encode(\$config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo 'Config written.' . PHP_EOL;
"

log_info "Config written to ${CONFIG_FILE}."

# =============================================================================
# 2. Wait for MariaDB, apply schema, and run pending migrations
# =============================================================================

DB_HOST="${DB_HOST:-cronmanager-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-cronmanager}"
DB_USER="${DB_USER:-cronmanager}"
MIGRATIONS_DIR="${AGENT_DIR}/sql/migrations"

log_info "Waiting for MariaDB at ${DB_HOST}:${DB_PORT}..."

MAX_RETRIES=30
RETRY_WAIT=2
CONNECTED=false

for i in $(seq 1 $MAX_RETRIES); do
    if DB_HOST="${DB_HOST}" DB_PORT="${DB_PORT}" DB_NAME="${DB_NAME}" DB_USER="${DB_USER}" \
       php -r "
        try {
            \$pdo = new PDO(
                'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME'),
                getenv('DB_USER'),
                getenv('DB_PASSWORD'),
                [PDO::ATTR_TIMEOUT => 3]
            );
            exit(0);
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null; then
        CONNECTED=true
        log_info "MariaDB is reachable (attempt ${i}/${MAX_RETRIES})."
        break
    fi
    log_info "MariaDB not ready yet (attempt ${i}/${MAX_RETRIES}), retrying in ${RETRY_WAIT}s..."
    sleep $RETRY_WAIT
done

if [[ "$CONNECTED" == false ]]; then
    log_error "Could not connect to MariaDB after ${MAX_RETRIES} attempts. Aborting."
    exit 1
fi

# ── 2a. Apply schema.sql on first boot ────────────────────────────────────────

log_info "Checking database schema..."
FRESH_INSTALL=false

TABLE_EXISTS=$(DB_HOST="${DB_HOST}" DB_PORT="${DB_PORT}" DB_NAME="${DB_NAME}" DB_USER="${DB_USER}" \
php -r "
    try {
        \$pdo = new PDO(
            'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME'),
            getenv('DB_USER'),
            getenv('DB_PASSWORD')
        );
        \$stmt = \$pdo->query(\"SHOW TABLES LIKE 'cronjobs'\");
        echo \$stmt->rowCount() > 0 ? 'yes' : 'no';
    } catch (Exception \$e) {
        echo 'error: ' . \$e->getMessage();
        exit(1);
    }
" 2>/dev/null)

if [[ "$TABLE_EXISTS" == "no" ]]; then
    log_info "Schema not found – applying ${SCHEMA_FILE}..."
    if [[ ! -f "$SCHEMA_FILE" ]]; then
        log_error "Schema file not found: ${SCHEMA_FILE}"
        exit 1
    fi
    DB_HOST="${DB_HOST}" DB_PORT="${DB_PORT}" DB_NAME="${DB_NAME}" DB_USER="${DB_USER}" \
    SCHEMA_FILE="${SCHEMA_FILE}" php -r "
        \$sql = file_get_contents(getenv('SCHEMA_FILE'));
        try {
            \$pdo = new PDO(
                'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME'),
                getenv('DB_USER'),
                getenv('DB_PASSWORD')
            );
            \$pdo->exec(\$sql);
            echo 'Schema applied successfully.' . PHP_EOL;
        } catch (Exception \$e) {
            fwrite(STDERR, 'Schema init failed: ' . \$e->getMessage() . PHP_EOL);
            exit(1);
        }
    "
    log_info "Database schema initialised."
    FRESH_INSTALL=true
elif [[ "$TABLE_EXISTS" == "yes" ]]; then
    log_info "Database schema already present."
else
    log_warn "Could not determine schema state (${TABLE_EXISTS}) – continuing anyway."
fi

# ── 2b. Ensure schema_migrations table exists ─────────────────────────────────
# Needed for installations that predate the migration-tracking feature.

DB_HOST="${DB_HOST}" DB_PORT="${DB_PORT}" DB_NAME="${DB_NAME}" DB_USER="${DB_USER}" \
php -r "
    \$pdo = new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME'),
        getenv('DB_USER'),
        getenv('DB_PASSWORD')
    );
    \$pdo->exec(\"CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   VARCHAR(255) NOT NULL,
        applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\");
" 2>/dev/null && log_info "schema_migrations table ready." \
             || log_warn "Could not ensure schema_migrations table."

# ── 2c. Seed schema_migrations on fresh install ───────────────────────────────
# All bundled migrations are already included in schema.sql, so mark them as
# applied without executing them again.

if [[ "$FRESH_INSTALL" == true ]]; then
    log_info "Fresh install – seeding schema_migrations with bundled migrations..."
    for mig in $(ls -1v "${MIGRATIONS_DIR}"/*.sql 2>/dev/null); do
        fname=$(basename "$mig")
        DB_HOST="${DB_HOST}" DB_PORT="${DB_PORT}" DB_NAME="${DB_NAME}" DB_USER="${DB_USER}" \
        MIGRATION_NAME="${fname}" php -r "
            \$pdo = new PDO(
                'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME'),
                getenv('DB_USER'),
                getenv('DB_PASSWORD')
            );
            \$stmt = \$pdo->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (?)');
            \$stmt->execute([getenv('MIGRATION_NAME')]);
        " 2>/dev/null && log_info "  seeded: ${fname}" \
                      || log_warn "  could not seed: ${fname}"
    done
fi

# ── 2d. Apply pending migrations ──────────────────────────────────────────────

log_info "Checking for pending migrations..."
MIGRATIONS_APPLIED=0

for mig in $(ls -1v "${MIGRATIONS_DIR}"/*.sql 2>/dev/null); do
    fname=$(basename "$mig")

    ALREADY_APPLIED=$(DB_HOST="${DB_HOST}" DB_PORT="${DB_PORT}" DB_NAME="${DB_NAME}" DB_USER="${DB_USER}" \
    MIGRATION_NAME="${fname}" php -r "
        \$pdo = new PDO(
            'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME'),
            getenv('DB_USER'),
            getenv('DB_PASSWORD')
        );
        \$stmt = \$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE filename = ?');
        \$stmt->execute([getenv('MIGRATION_NAME')]);
        echo \$stmt->fetchColumn();
    " 2>/dev/null || echo "0")

    if [[ "${ALREADY_APPLIED}" -gt 0 ]]; then
        log_info "  already applied: ${fname}"
        continue
    fi

    log_info "  applying: ${fname}..."
    RESULT=$(DB_HOST="${DB_HOST}" DB_PORT="${DB_PORT}" DB_NAME="${DB_NAME}" DB_USER="${DB_USER}" \
    MIGRATION_FILE="${mig}" MIGRATION_NAME="${fname}" php -r "
        try {
            \$sql = file_get_contents(getenv('MIGRATION_FILE'));
            \$pdo = new PDO(
                'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME'),
                getenv('DB_USER'),
                getenv('DB_PASSWORD')
            );
            \$pdo->exec(\$sql);
            \$stmt = \$pdo->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (?)');
            \$stmt->execute([getenv('MIGRATION_NAME')]);
            echo 'ok';
        } catch (Exception \$e) {
            fwrite(STDERR, 'Migration failed: ' . \$e->getMessage() . PHP_EOL);
            echo 'fail';
        }
    " 2>/dev/null)

    if [[ "$RESULT" == "ok" ]]; then
        log_info "  applied: ${fname}"
        MIGRATIONS_APPLIED=$((MIGRATIONS_APPLIED + 1))
    else
        log_warn "  FAILED: ${fname} – check logs and apply manually if needed"
    fi
done

if [[ "$MIGRATIONS_APPLIED" -gt 0 ]]; then
    log_info "${MIGRATIONS_APPLIED} migration(s) applied."
else
    log_info "No pending migrations."
fi

# =============================================================================
# 3. Fix SSH key permissions
#    The host's ~/.ssh directory is mounted read-only.  SSH refuses to use
#    keys if the directory or files have permissions wider than 0700/0600.
#    We copy the keys to a writable location and fix permissions there.
# =============================================================================

if [[ -d /root/.ssh ]]; then
    SSH_PERMS_OK=true

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
        export HOME_SSH=/root/.ssh-rw
        log_info "SSH keys copied to /root/.ssh-rw with correct permissions."
    else
        log_info "SSH key permissions look correct."
    fi
else
    log_warn "/root/.ssh not found – remote (SSH) targets will not be available."
fi

# =============================================================================
# 3. Resync crontab from database
#    Rebuilds all crontab entries so that jobs are never lost after a
#    container recreation (e.g. Portainer stack redeploy, image update).
# =============================================================================

RESYNC_SCRIPT="${AGENT_DIR}/bin/resync-crontab.php"

if [[ -f "$RESYNC_SCRIPT" ]]; then
    log_info "Resyncing crontab entries from database..."
    if php "${RESYNC_SCRIPT}" 2>&1; then
        log_info "Crontab resync completed."
    else
        log_warn "Crontab resync exited with errors – jobs may need a manual resync."
    fi
else
    log_warn "Resync script not found at ${RESYNC_SCRIPT} – skipping crontab resync."
fi

# =============================================================================
# 4. Install execution-limit checker cron entry and start cron daemon
# =============================================================================

LIMITS_CRON_FILE="/etc/cron.d/cronmanager-limits"
LIMITS_CHECKER="${AGENT_DIR}/bin/check-limits.php"

log_info "Installing execution-limit checker cron entry..."
printf '* * * * * root /usr/bin/php %s >> /dev/null 2>&1\n' "${LIMITS_CHECKER}" \
    > "${LIMITS_CRON_FILE}"
chmod 0644 "${LIMITS_CRON_FILE}"
log_info "Cron entry written to ${LIMITS_CRON_FILE}."

log_info "Starting cron daemon..."
cron -f &
CRON_PID=$!
log_info "Cron daemon started (PID ${CRON_PID})."

# =============================================================================
# 5. Start PHP built-in server (foreground – becomes main process)
# =============================================================================

# Re-read bind address and port from the generated config file
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
fi

AGENT_PHP="${AGENT_DIR}/agent.php"
if [[ ! -f "$AGENT_PHP" ]]; then
    log_error "Agent entry point not found: ${AGENT_PHP}"
    exit 1
fi

log_info "Starting Cronmanager agent on ${BIND_ADDRESS}:${PORT}"
exec php -S "${BIND_ADDRESS}:${PORT}" "${AGENT_PHP}"
