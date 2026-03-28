#!/bin/sh
# =============================================================================
# Cronmanager Web Container – entrypoint.sh
#
# Steps executed on every container start:
#   1. Generate /var/www/conf/config.json from environment variables.
#   2. Fix ownership of /var/www/conf and /var/www/log for the nobody user.
#   3. Drop privileges to nobody and exec supervisord (nginx + php-fpm).
#
# Required environment variables:
#   AGENT_HMAC_SECRET   – shared secret for HMAC-SHA256 request signing
#   DB_PASSWORD         – MariaDB password for the application user
#
# Optional environment variables (shown with their defaults):
#   AGENT_URL           http://cronmanager-agent:8865
#   AGENT_TIMEOUT       10
#   DB_HOST             cronmanager-db
#   DB_PORT             3306
#   DB_NAME             cronmanager
#   DB_USER             cronmanager
#   LOG_PATH            /var/www/log/cronmanager-web.log
#   LOG_LEVEL           info
#   LOG_MAX_DAYS        30
#   SESSION_LIFETIME    3600
#   SESSION_NAME        cronmanager_sess
#   I18N_LANGUAGE       en
#   OIDC_ENABLED        false
#   OIDC_PROVIDER_URL   ""
#   OIDC_CLIENT_ID      ""
#   OIDC_CLIENT_SECRET  ""
#   OIDC_REDIRECT_URI   ""
#   OIDC_SSL_VERIFY     true
#   OIDC_SSL_CA_BUNDLE  ""
#
# @author  Christian Schulz <technik@meinetechnikwelt.rocks>
# @license GNU General Public License version 3 or later
# =============================================================================

set -eu

CONFIG_FILE="/var/www/conf/config.json"

# ── Logging helpers ───────────────────────────────────────────────────────────
log_info()  { echo "[entrypoint] [INFO]  $(date -Iseconds) $*"; }
log_warn()  { echo "[entrypoint] [WARN]  $(date -Iseconds) $*"; }
log_error() { echo "[entrypoint] [ERROR] $(date -Iseconds) $*" >&2; }

# =============================================================================
# 1. Generate config.json from environment variables
# =============================================================================

log_info "Generating ${CONFIG_FILE} from environment variables..."

# Validate required variables
if [ -z "${AGENT_HMAC_SECRET:-}" ]; then
    log_error "AGENT_HMAC_SECRET is required but not set."
    exit 1
fi
if [ -z "${DB_PASSWORD:-}" ]; then
    log_error "DB_PASSWORD is required but not set."
    exit 1
fi

mkdir -p /var/www/conf

# The alpine base image uses php84, not php
php84 -r "
\$config = [
    'database' => [
        'host'     => getenv('DB_HOST') ?: 'cronmanager-db',
        'port'     => (int)(getenv('DB_PORT') ?: 3306),
        'name'     => getenv('DB_NAME') ?: 'cronmanager',
        'user'     => getenv('DB_USER') ?: 'cronmanager',
        'password' => getenv('DB_PASSWORD'),
    ],
    'agent' => [
        'url'         => getenv('AGENT_URL') ?: 'http://cronmanager-agent:8865',
        'hmac_secret' => getenv('AGENT_HMAC_SECRET'),
        'timeout'     => (int)(getenv('AGENT_TIMEOUT') ?: 10),
    ],
    'logging' => [
        'path'     => getenv('LOG_PATH') ?: '/var/www/log/cronmanager-web.log',
        'level'    => getenv('LOG_LEVEL') ?: 'info',
        'max_days' => (int)(getenv('LOG_MAX_DAYS') ?: 30),
    ],
    'session' => [
        'lifetime' => (int)(getenv('SESSION_LIFETIME') ?: 3600),
        'name'     => getenv('SESSION_NAME') ?: 'cronmanager_sess',
    ],
    'i18n' => [
        'default_language' => getenv('I18N_LANGUAGE') ?: 'en',
        'available'        => ['en', 'de'],
    ],
    'auth' => [
        'oidc_enabled'       => filter_var(getenv('OIDC_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'oidc_provider_url'  => getenv('OIDC_PROVIDER_URL') ?: '',
        'oidc_client_id'     => getenv('OIDC_CLIENT_ID') ?: '',
        'oidc_client_secret' => getenv('OIDC_CLIENT_SECRET') ?: '',
        'oidc_redirect_uri'  => getenv('OIDC_REDIRECT_URI') ?: '',
        'oidc_ssl_verify'    => filter_var(getenv('OIDC_SSL_VERIFY') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'oidc_ssl_ca_bundle' => getenv('OIDC_SSL_CA_BUNDLE') ?: '',
    ],
];
file_put_contents('${CONFIG_FILE}', json_encode(\$config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo 'Config written.' . PHP_EOL;
"

log_info "Config written to ${CONFIG_FILE}."

# =============================================================================
# 2. Fix directory ownership for the nobody user
# =============================================================================

chown -R nobody:nobody /var/www/conf /var/www/log
log_info "Ownership of /var/www/conf and /var/www/log set to nobody."

# =============================================================================
# 3. Drop privileges and start supervisord (nginx + php-fpm)
# =============================================================================

log_info "Starting supervisord as nobody..."
exec su-exec nobody /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
