#!/usr/bin/env bash
# =============================================================================
#  Cronmanager – Simple Debian Setup Script
#
#  Guides through a complete local installation of Cronmanager on a Debian
#  (or Ubuntu) based system.  Covers:
#    • Prerequisite check and installation
#    • Repository clone
#    • Composer / PHP library setup
#    • Host agent deployment and systemd service
#    • Web application deployment (Docker + MariaDB)
#    • Optional OIDC / SSO integration
#    • Optional email failure notifications
#
#  Must be run as root on the target host.
#
#  @author  Christian Schulz <technik@meinetechnikwelt.rocks>
#  @license GNU General Public License version 3 or later
# =============================================================================

set -uo pipefail
IFS=$'\n\t'

SCRIPT_VERSION="1.0.0"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Colours ────────────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
    CYAN='\033[0;36m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; CYAN=''; BLUE=''; BOLD=''; NC=''
fi

# ── Output helpers ─────────────────────────────────────────────────────────────
header() { echo; echo -e "${BOLD}${CYAN}══════════════════════════════════════════════════${NC}";
           echo -e "${BOLD}${CYAN}  $*${NC}";
           echo -e "${BOLD}${CYAN}══════════════════════════════════════════════════${NC}"; echo; }
step()  { echo -e "  ${BOLD}▶  $*${NC}"; }
info()  { echo -e "  ${BLUE}→${NC}  $*"; }
ok()    { echo -e "  ${GREEN}✔${NC}  $*"; }
warn()  { echo -e "  ${YELLOW}⚠${NC}  $*"; }
error() { echo -e "  ${RED}✘${NC}  $*" >&2; }
die()   { error "$*"; exit 1; }
sep()   { echo -e "  ${CYAN}──────────────────────────────────────────────────${NC}"; }
blank() { echo; }

# ── Interactive input helpers ──────────────────────────────────────────────────

# ask VARNAME "Prompt text" "default_value"
ask() {
    local _var="$1" _prompt="$2" _default="${3:-}" _input
    if [[ -n "$_default" ]]; then
        read -r -p "  ${BOLD}${_prompt}${NC} [${_default}]: " _input
        _input="${_input:-$_default}"
    else
        _input=""
        while [[ -z "$_input" ]]; do
            read -r -p "  ${BOLD}${_prompt}${NC}: " _input
            [[ -z "$_input" ]] && warn "This field is required."
        done
    fi
    printf -v "$_var" '%s' "$_input"
}

# ask_secret VARNAME "Prompt text"  (hidden input, required)
ask_secret() {
    local _var="$1" _prompt="$2" _input=""
    while [[ -z "$_input" ]]; do
        read -r -s -p "  ${BOLD}${_prompt}${NC}: " _input; echo
        [[ -z "$_input" ]] && warn "This field is required."
    done
    printf -v "$_var" '%s' "$_input"
}

# ask_yn VARNAME "Question?" "yes|no"   → sets VARNAME to "yes" or "no"
ask_yn() {
    local _var="$1" _prompt="$2" _default="${3:-yes}" _input _hint
    [[ "$_default" == "yes" ]] && _hint="Y/n" || _hint="y/N"
    read -r -p "  ${BOLD}${_prompt}${NC} [${_hint}]: " _input
    _input="${_input:-$_default}"
    if [[ "${_input,,}" == "y" || "${_input,,}" == "yes" ]]; then
        printf -v "$_var" 'yes'
    else
        printf -v "$_var" 'no'
    fi
}

# ask_choice VARNAME "Prompt" option1 option2 ...
ask_choice() {
    local _var="$1" _prompt="$2"; shift 2
    local _opts=("$@") _input _valid=false
    echo -e "  ${BOLD}${_prompt}${NC}"
    for _i in "${!_opts[@]}"; do
        echo -e "    $((_i+1))) ${_opts[$_i]}"
    done
    while [[ "$_valid" == false ]]; do
        read -r -p "  Choice [1]: " _input
        _input="${_input:-1}"
        if [[ "$_input" =~ ^[0-9]+$ ]] && (( _input >= 1 && _input <= ${#_opts[@]} )); then
            printf -v "$_var" '%s' "${_opts[$((_input-1))]}"
            _valid=true
        else
            warn "Please enter a number between 1 and ${#_opts[@]}."
        fi
    done
}

# json_escape VAL  →  JSON-encoded string value including surrounding quotes
json_escape() {
    python3 -c "import json,sys; print(json.dumps(sys.argv[1]),end='')" "$1"
}

# Temp directory – cleaned up on exit
CLONE_DIR=""
cleanup() { [[ -n "$CLONE_DIR" && -d "$CLONE_DIR" ]] && rm -rf "$CLONE_DIR"; }
trap cleanup EXIT INT TERM

# ═════════════════════════════════════════════════════════════════════════════
#  1. WELCOME BANNER
# ═════════════════════════════════════════════════════════════════════════════

clear
echo -e "${BOLD}${CYAN}"
echo "  ╔══════════════════════════════════════════════════════════════╗"
echo "  ║           Cronmanager – Simple Debian Setup                  ║"
echo "  ║                      Version ${SCRIPT_VERSION}                            ║"
echo "  ╚══════════════════════════════════════════════════════════════╝"
echo -e "${NC}"
echo "  This script installs Cronmanager on this Debian / Ubuntu host:"
echo
echo "    •  Host agent (PHP 8.4 CLI, systemd)"
echo "    •  Web application + MariaDB (Docker)"
echo "    •  Shared PHP libraries via Composer"
echo "    •  Optional: OIDC / SSO authentication"
echo "    •  Optional: Email failure notifications"
echo
echo -e "  ${YELLOW}Requirements: Debian 12+ / Ubuntu 22.04+  •  Internet access  •  root${NC}"
blank

# ═════════════════════════════════════════════════════════════════════════════
#  2. ROOT CHECK
# ═════════════════════════════════════════════════════════════════════════════

[[ "$EUID" -ne 0 ]] && die "Please run as root:  sudo bash $0"
ok "Running as root."

# ═════════════════════════════════════════════════════════════════════════════
#  3. PREREQUISITES CHECK
# ═════════════════════════════════════════════════════════════════════════════

header "Step 1 – Prerequisites"

MISSING_PKGS=()

check_pkg() {
    local pkg="$1" bin="${2:-}" apt_pkg="${3:-$1}"
    local found=false
    # check via dpkg first (apt-installed packages)
    if dpkg -l "$apt_pkg" 2>/dev/null | grep -q '^ii'; then
        found=true
    elif [[ -n "$bin" ]] && command -v "$bin" &>/dev/null; then
        found=true
    fi
    if [[ "$found" == true ]]; then
        ok "${pkg} – OK"
    else
        warn "${pkg} – NOT installed  (package: ${apt_pkg})"
        MISSING_PKGS+=("$apt_pkg")
    fi
}

check_php_ext() {
    local ext="$1" apt_pkg="${2:-}"
    if php -m 2>/dev/null | grep -qi "^${ext}$"; then
        ok "PHP extension ${ext} – OK"
    else
        warn "PHP extension ${ext} – missing  (package: ${apt_pkg})"
        [[ -n "$apt_pkg" ]] && MISSING_PKGS+=("$apt_pkg")
    fi
}

step "Checking required system packages..."
blank

check_pkg "PHP 8.4 CLI"          "php8.4"       "php8.4-cli"
check_php_ext "pdo_mysql"        "php8.4-mysql"
check_php_ext "mbstring"         "php8.4-mbstring"
check_php_ext "curl"             "php8.4-curl"
check_pkg "curl"                 "curl"         "curl"
check_pkg "git"                  "git"          "git"
check_pkg "openssl"              "openssl"      "openssl"
check_pkg "rsync"                "rsync"        "rsync"
check_pkg "unzip"                "unzip"        "unzip"
check_pkg "python3"              "python3"      "python3"
check_pkg "jq"                   "jq"           "jq"

# Docker
if command -v docker &>/dev/null; then
    ok "Docker – OK"
else
    warn "Docker – NOT installed"
    MISSING_PKGS+=("docker.io")
fi

# Docker Compose v2
if docker compose version &>/dev/null 2>&1; then
    ok "docker compose (v2) – OK"
elif command -v docker-compose &>/dev/null; then
    ok "docker-compose (v1) – OK  (v2 recommended)"
else
    warn "docker compose – NOT installed"
    MISSING_PKGS+=("docker-compose-plugin")
fi

blank

# Remove duplicate packages
MISSING_PKGS=($(printf '%s\n' "${MISSING_PKGS[@]}" | sort -u))

if [[ ${#MISSING_PKGS[@]} -eq 0 ]]; then
    ok "All prerequisites satisfied."
else
    warn "The following packages need to be installed:"
    for pkg in "${MISSING_PKGS[@]}"; do
        echo -e "    ${YELLOW}•${NC}  $pkg"
    done
    blank
    ask_yn INSTALL_PKGS "Install missing packages now via apt?" "yes"

    if [[ "$INSTALL_PKGS" == "yes" ]]; then
        step "Updating package index..."
        apt-get update -qq || die "apt-get update failed."

        step "Installing: ${MISSING_PKGS[*]}"
        apt-get install -y "${MISSING_PKGS[@]}" || die "Package installation failed."
        ok "All packages installed."

        # Verify PHP extensions after install
        for ext in pdo_mysql mbstring curl; do
            php -m 2>/dev/null | grep -qi "^${ext}$" || \
                die "PHP extension ${ext} still not available after install. Check your PHP setup."
        done
    else
        die "Cannot continue without required packages. Please install them and re-run this script."
    fi
fi

# ═════════════════════════════════════════════════════════════════════════════
#  4. CLONE REPOSITORY
# ═════════════════════════════════════════════════════════════════════════════

header "Step 2 – Clone Repository"

# Try to detect the remote URL from this script's own git repo
DETECTED_URL=""
DETECTED_URL=$(git -C "$SCRIPT_DIR" remote get-url origin 2>/dev/null || true)

ask REPO_URL "Repository URL" "${DETECTED_URL:-https://github.com/csoscd/cronmanager.git}"

CLONE_DIR=$(mktemp -d /tmp/cronmanager-setup-XXXXXX)
step "Cloning into ${CLONE_DIR} ..."
if git clone --depth=1 "$REPO_URL" "$CLONE_DIR" 2>&1; then
    ok "Repository cloned successfully."
else
    die "git clone failed. Check the URL and your internet connection."
fi

# Verify the clone looks like a cronmanager repo
[[ -f "$CLONE_DIR/agent/agent.php" ]] || die "Cloned repository does not look like Cronmanager (agent/agent.php not found)."
[[ -f "$CLONE_DIR/composer.json" ]]   || die "composer.json not found in cloned repository."

# ═════════════════════════════════════════════════════════════════════════════
#  5. COMPOSER CHECK AND INSTALL
# ═════════════════════════════════════════════════════════════════════════════

header "Step 3 – Composer"

COMPOSER_BIN=""
if command -v composer &>/dev/null; then
    COMPOSER_VER=$(composer --version 2>/dev/null | head -1 || true)
    ok "Composer is installed: ${COMPOSER_VER}"
    COMPOSER_BIN=$(command -v composer)
elif [[ -f "/usr/local/bin/composer" ]]; then
    ok "Composer found at /usr/local/bin/composer."
    COMPOSER_BIN="/usr/local/bin/composer"
else
    warn "Composer is not installed."
    ask_yn INSTALL_COMPOSER "Install Composer globally now?" "yes"

    if [[ "$INSTALL_COMPOSER" == "yes" ]]; then
        step "Downloading and installing Composer..."
        EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
        php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
        ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
        if [[ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]]; then
            rm -f composer-setup.php
            die "Composer installer checksum verification failed."
        fi
        php composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
        rm -f composer-setup.php
        COMPOSER_BIN="/usr/local/bin/composer"
        ok "Composer installed at ${COMPOSER_BIN}."
    else
        die "Cannot continue without Composer. Please install it and re-run this script."
    fi
fi

# ═════════════════════════════════════════════════════════════════════════════
#  6. PHP LIBRARY CHECK
# ═════════════════════════════════════════════════════════════════════════════

header "Step 4 – PHP Libraries"

ask PHPLIB_DIR "Shared PHP library directory (vendor parent)" "/opt/phplib"

VENDOR_DIR="${PHPLIB_DIR}/vendor"
PHPLIB_COMPOSER="${PHPLIB_DIR}/composer.json"

# Required packages from the cronmanager composer.json
declare -A REQUIRED_PACKAGES=(
    ["hassankhan/config"]="^2.1"
    ["monolog/monolog"]="^3.6"
    ["guzzlehttp/guzzle"]="^7.8"
    ["phpmailer/phpmailer"]="^6.8"
    ["dragonmantank/cron-expression"]="^3.3"
    ["lorisleiva/cron-translator"]="^0.4"
)

step "Checking installed packages in ${VENDOR_DIR} ..."
MISSING_PACKAGES=()

for pkg in "${!REQUIRED_PACKAGES[@]}"; do
    vendor_path="${VENDOR_DIR}/$(echo "$pkg" | tr '/' '/')"
    if [[ -d "${VENDOR_DIR}/$pkg" ]]; then
        ok "$pkg – OK"
    else
        warn "$pkg – NOT found in vendor directory"
        MISSING_PACKAGES+=("$pkg")
    fi
done

if [[ ${#MISSING_PACKAGES[@]} -eq 0 ]]; then
    ok "All required PHP libraries are installed."
else
    warn "The following libraries are missing from ${VENDOR_DIR}:"
    for pkg in "${MISSING_PACKAGES[@]}"; do
        echo -e "    ${YELLOW}•${NC}  $pkg  ${REQUIRED_PACKAGES[$pkg]}"
    done
    blank
    ask_yn ADD_LIBS "Add missing libraries to ${PHPLIB_COMPOSER} and run composer install?" "yes"

    if [[ "$ADD_LIBS" == "yes" ]]; then
        mkdir -p "$PHPLIB_DIR"

        # Build or update composer.json
        if [[ -f "$PHPLIB_COMPOSER" ]]; then
            step "Updating existing ${PHPLIB_COMPOSER} ..."
            # Use python3 to merge required packages
            python3 - "$PHPLIB_COMPOSER" << 'PYEOF'
import json, sys
path = sys.argv[1]
with open(path) as f:
    data = json.load(f)
required = {
    "hassankhan/config":            "^2.1",
    "monolog/monolog":              "^3.6",
    "guzzlehttp/guzzle":            "^7.8",
    "phpmailer/phpmailer":          "^6.8",
    "dragonmantank/cron-expression":"^3.3",
    "lorisleiva/cron-translator":   "^0.4",
}
data.setdefault("require", {})["php"] = ">=8.4"
for pkg, ver in required.items():
    data["require"].setdefault(pkg, ver)
data.setdefault("config", {})["optimize-autoloader"] = True
with open(path, "w") as f:
    json.dump(data, f, indent=4)
print("Updated:", path)
PYEOF
        else
            step "Creating ${PHPLIB_COMPOSER} ..."
            cat > "$PHPLIB_COMPOSER" << 'JSONEOF'
{
    "name": "local/phplib",
    "description": "Shared PHP libraries for Cronmanager",
    "type": "project",
    "require": {
        "php": ">=8.4",
        "hassankhan/config":             "^2.1",
        "monolog/monolog":               "^3.6",
        "guzzlehttp/guzzle":             "^7.8",
        "phpmailer/phpmailer":           "^6.8",
        "dragonmantank/cron-expression": "^3.3",
        "lorisleiva/cron-translator":    "^0.4"
    },
    "config": {
        "optimize-autoloader": true
    }
}
JSONEOF
        fi

        step "Running composer install in ${PHPLIB_DIR} ..."
        "$COMPOSER_BIN" install --no-dev --optimize-autoloader --working-dir="$PHPLIB_DIR" \
            || die "composer install failed."
        ok "PHP libraries installed in ${VENDOR_DIR}."
    else
        die "Cannot continue without required PHP libraries."
    fi
fi

# ═════════════════════════════════════════════════════════════════════════════
#  7. COLLECT CONFIGURATION
# ═════════════════════════════════════════════════════════════════════════════

header "Step 5 – Configuration"
echo "  Please answer the following questions. Press Enter to accept the default."
blank

# ── Installation paths ────────────────────────────────────────────────────────
sep
echo -e "  ${BOLD}Installation Paths${NC}"
blank
ask AGENT_DIR   "Host agent installation directory"    "/opt/phpscripts/cronmanager/agent"
ask WEB_DIR     "Web application base directory"       "/opt/websites/cronmanager"
ask DB_DIR      "MariaDB data directory"               "/opt/cronmanager/db"

# Derived sub-paths
WEB_WWW="${WEB_DIR}/www"
WEB_CONF="${WEB_DIR}/conf"
WEB_LOG="${WEB_DIR}/log"
DB_DATA="${DB_DIR}/data"
DB_CONF="${DB_DIR}/conf"
DB_LOG="${DB_DIR}/log"
DB_INIT="${DB_DIR}/init"
COMPOSE_DIR="$WEB_DIR"

blank

# ── Database credentials ───────────────────────────────────────────────────────
sep
echo -e "  ${BOLD}Database Credentials${NC}"
blank
ask        DB_NAME          "Database name"                     "cronmanager"
ask        DB_USER          "Database user"                     "cronmanager"
ask_secret DB_PASSWORD      "Database password"
ask_secret DB_ROOT_PASSWORD "MariaDB root password"
blank

# ── Agent settings ────────────────────────────────────────────────────────────
sep
echo -e "  ${BOLD}Host Agent Settings${NC}"
blank
ask AGENT_BIND  "Agent listen address (0.0.0.0 = all interfaces)" "0.0.0.0"
ask AGENT_PORT  "Agent listen port"                               "8865"
ask_choice AGENT_LOG_LEVEL "Agent log level" "info" "debug" "warning" "error"
blank

# ── Web application settings ──────────────────────────────────────────────────
sep
echo -e "  ${BOLD}Web Application Settings${NC}"
blank
ask WEB_PORT     "Web application HTTP port (external Docker port)" "8880"
ask WEB_LANG     "Default language (en / de)"                       "en"
ask_choice WEB_LOG_LEVEL "Web application log level" "info" "debug" "warning" "error"
blank

# Agent URL as seen from inside Docker
AGENT_URL="http://host.docker.internal:${AGENT_PORT}"
info "Agent URL for web app (Docker → host): ${AGENT_URL}"
blank

# ── OIDC ──────────────────────────────────────────────────────────────────────
sep
echo -e "  ${BOLD}OIDC / SSO Configuration${NC}"
blank
ask_yn OIDC_ENABLED "Enable OIDC single sign-on?" "no"
OIDC_PROVIDER_URL=""; OIDC_CLIENT_ID=""; OIDC_CLIENT_SECRET=""
OIDC_REDIRECT_URI=""; OIDC_SSL_VERIFY="true"; OIDC_CA_BUNDLE=""

if [[ "$OIDC_ENABLED" == "yes" ]]; then
    ask        OIDC_PROVIDER_URL  "OIDC provider URL (with trailing slash)" ""
    ask        OIDC_CLIENT_ID     "OIDC client ID"                          ""
    ask_secret OIDC_CLIENT_SECRET "OIDC client secret"
    ask        OIDC_REDIRECT_URI  "OIDC redirect URI (callback URL)"        ""
    blank
    ask_yn OIDC_SSL_NO_VERIFY "Disable SSL certificate verification for OIDC?" "no"
    if [[ "$OIDC_SSL_NO_VERIFY" == "yes" ]]; then
        OIDC_SSL_VERIFY="false"
        warn "SSL verification disabled – only use this in trusted environments."
    else
        ask_yn USE_PRIVATE_CA "Use a private / self-signed CA certificate?" "no"
        if [[ "$USE_PRIVATE_CA" == "yes" ]]; then
            ask OIDC_CA_BUNDLE "Path to PEM CA certificate on the web container" ""
            OIDC_SSL_VERIFY="$OIDC_CA_BUNDLE"
        fi
    fi
fi
blank

# ── Email notifications ───────────────────────────────────────────────────────
sep
echo -e "  ${BOLD}Email Failure Notifications${NC}"
blank
ask_yn MAIL_ENABLED "Enable email notifications for job failures?" "no"
MAIL_HOST=""; MAIL_PORT="587"; MAIL_USER=""; MAIL_PASS=""
MAIL_FROM=""; MAIL_FROM_NAME="Cronmanager"; MAIL_TO=""; MAIL_ENC="tls"; MAIL_TIMEOUT="15"

if [[ "$MAIL_ENABLED" == "yes" ]]; then
    ask        MAIL_HOST      "SMTP server hostname"              ""
    ask        MAIL_PORT      "SMTP port (587=STARTTLS, 465=SSL)" "587"
    ask        MAIL_USER      "SMTP username (email address)"     ""
    ask_secret MAIL_PASS      "SMTP password"
    ask        MAIL_FROM      "Sender address"                    "$MAIL_USER"
    ask        MAIL_FROM_NAME "Sender display name"               "Cronmanager"
    ask        MAIL_TO        "Recipient address for alerts"       ""
    ask_choice MAIL_ENC       "Encryption" "tls" "ssl" "none"
    ask        MAIL_TIMEOUT   "SMTP connection timeout (seconds)" "15"
fi
blank

# ── Summary of collected config ───────────────────────────────────────────────
sep
echo -e "  ${BOLD}Configuration Summary${NC}"
blank
echo -e "    Agent dir       : ${CYAN}${AGENT_DIR}${NC}"
echo -e "    Web dir         : ${CYAN}${WEB_DIR}${NC}"
echo -e "    DB dir          : ${CYAN}${DB_DIR}${NC}"
echo -e "    PHP libs        : ${CYAN}${PHPLIB_DIR}${NC}"
echo -e "    Agent listen    : ${CYAN}${AGENT_BIND}:${AGENT_PORT}${NC}"
echo -e "    Web HTTP port   : ${CYAN}${WEB_PORT}${NC}"
echo -e "    Database        : ${CYAN}${DB_NAME}${NC}  (user: ${DB_USER})"
echo -e "    Default lang    : ${CYAN}${WEB_LANG}${NC}"
echo -e "    OIDC            : ${CYAN}${OIDC_ENABLED}${NC}"
echo -e "    Email alerts    : ${CYAN}${MAIL_ENABLED}${NC}"
blank
ask_yn PROCEED "Proceed with this configuration?" "yes"
[[ "$PROCEED" == "no" ]] && die "Setup cancelled by user."

# ═════════════════════════════════════════════════════════════════════════════
#  8. GENERATE HMAC SECRET
# ═════════════════════════════════════════════════════════════════════════════

header "Step 6 – Security"

step "Generating HMAC-SHA256 secret..."
HMAC_SECRET=$(openssl rand -hex 32)
ok "HMAC secret generated  (${#HMAC_SECRET} hex characters)."

# ═════════════════════════════════════════════════════════════════════════════
#  9. CREATE DIRECTORY STRUCTURE
# ═════════════════════════════════════════════════════════════════════════════

header "Step 7 – Directory Structure"

for dir in \
    "$AGENT_DIR" "$AGENT_DIR/config" "$AGENT_DIR/bin" \
    "$WEB_WWW" "$WEB_CONF" "$WEB_LOG" \
    "$DB_DATA" "$DB_CONF" "$DB_LOG" "$DB_INIT" \
    "$PHPLIB_DIR"; do
    mkdir -p "$dir"
    ok "Created: $dir"
done

# ═════════════════════════════════════════════════════════════════════════════
#  10. DEPLOY AGENT FILES
# ═════════════════════════════════════════════════════════════════════════════

header "Step 8 – Deploy Host Agent"

step "Copying agent files from clone to ${AGENT_DIR} ..."
rsync -a --delete \
    --exclude='config/config.json' \
    "$CLONE_DIR/agent/" "$AGENT_DIR/"
ok "Agent files deployed."

# Update hardcoded paths in start-agent.sh and cron-wrapper.sh
step "Patching paths in start-agent.sh ..."
sed -i \
    -e "s|/opt/phpscripts/cronmanager/agent|${AGENT_DIR}|g" \
    -e "s|/opt/phplib|${PHPLIB_DIR}|g" \
    "${AGENT_DIR}/bin/start-agent.sh"
chmod +x "${AGENT_DIR}/bin/start-agent.sh"

step "Patching paths in cron-wrapper.sh ..."
sed -i \
    -e "s|/opt/phpscripts/cronmanager/agent|${AGENT_DIR}|g" \
    -e "s|/opt/phplib|${PHPLIB_DIR}|g" \
    "${AGENT_DIR}/bin/cron-wrapper.sh"
chmod +x "${AGENT_DIR}/bin/cron-wrapper.sh"

if [[ -f "${AGENT_DIR}/bin/send-notification.php" ]]; then
    sed -i "s|/opt/phplib|${PHPLIB_DIR}|g" "${AGENT_DIR}/bin/send-notification.php"
fi
if [[ -f "${AGENT_DIR}/bin/create-admin.php" ]]; then
    sed -i "s|/opt/phplib|${PHPLIB_DIR}|g" "${AGENT_DIR}/bin/create-admin.php"
fi
if [[ -f "${AGENT_DIR}/agent.php" ]]; then
    sed -i "s|/opt/phplib|${PHPLIB_DIR}|g" "${AGENT_DIR}/agent.php"
fi

ok "Paths patched."

# ── Write agent config.json ────────────────────────────────────────────────────

step "Writing agent config.json ..."

MAIL_ENABLED_BOOL=$(json_bool "$MAIL_ENABLED")

python3 - << PYEOF
import json, os

mail_enabled = "${MAIL_ENABLED}" == "yes"
mail_enc     = "${MAIL_ENC}"

config = {
    "agent": {
        "bind_address": "${AGENT_BIND}",
        "port":         int("${AGENT_PORT}"),
        "hmac_secret":  "${HMAC_SECRET}"
    },
    "database": {
        "host":     "127.0.0.1",
        "port":     3306,
        "name":     "${DB_NAME}",
        "user":     "${DB_USER}",
        "password": "${DB_PASSWORD}"
    },
    "logging": {
        "path":     "${AGENT_DIR}/log/cronmanager-agent.log",
        "level":    "${AGENT_LOG_LEVEL}",
        "max_days": 30
    },
    "mail": {
        "enabled":      mail_enabled,
        "host":         "${MAIL_HOST}",
        "port":         int("${MAIL_PORT}") if "${MAIL_PORT}" else 587,
        "username":     "${MAIL_USER}",
        "password":     "${MAIL_PASS}",
        "from":         "${MAIL_FROM}",
        "from_name":    "${MAIL_FROM_NAME}",
        "to":           "${MAIL_TO}",
        "encryption":   mail_enc if mail_enc != "none" else "",
        "smtp_timeout": int("${MAIL_TIMEOUT}") if "${MAIL_TIMEOUT}" else 15
    },
    "cron": {
        "wrapper_script": "${AGENT_DIR}/bin/cron-wrapper.sh"
    }
}

with open("${AGENT_DIR}/config/config.json", "w") as f:
    json.dump(config, f, indent=4, ensure_ascii=False)
print("  Written: ${AGENT_DIR}/config/config.json")
PYEOF

chmod 600 "${AGENT_DIR}/config/config.json"
ok "Agent config.json written."

# ── Create log directory for agent ────────────────────────────────────────────
mkdir -p "${AGENT_DIR}/log"

# ═════════════════════════════════════════════════════════════════════════════
#  11. SYSTEMD SERVICE
# ═════════════════════════════════════════════════════════════════════════════

header "Step 9 – Systemd Service"

SERVICE_FILE="${AGENT_DIR}/systemd/cronmanager-agent.service"

if [[ -f "$SERVICE_FILE" ]]; then
    step "Installing systemd service from ${SERVICE_FILE} ..."
    # Patch paths in service file
    sed \
        -e "s|/opt/phpscripts/cronmanager/agent|${AGENT_DIR}|g" \
        -e "s|/opt/phplib|${PHPLIB_DIR}|g" \
        "$SERVICE_FILE" > /etc/systemd/system/cronmanager-agent.service

    systemctl daemon-reload
    systemctl enable cronmanager-agent.service
    ok "Service enabled:  cronmanager-agent.service"
else
    warn "Service file not found at ${SERVICE_FILE}. Creating minimal service unit..."
    cat > /etc/systemd/system/cronmanager-agent.service << SVCEOF
[Unit]
Description=Cronmanager Host Agent
After=network.target

[Service]
Type=simple
User=root
ExecStart=${AGENT_DIR}/bin/start-agent.sh
WorkingDirectory=${AGENT_DIR}
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=cronmanager-agent

[Install]
WantedBy=multi-user.target
SVCEOF
    systemctl daemon-reload
    systemctl enable cronmanager-agent.service
    ok "Minimal service unit created and enabled."
fi

# ═════════════════════════════════════════════════════════════════════════════
#  12. DEPLOY WEB APPLICATION
# ═════════════════════════════════════════════════════════════════════════════

header "Step 10 – Deploy Web Application"

step "Copying web files to ${WEB_WWW} ..."
rsync -a --delete \
    --exclude='config/config.json' \
    "$CLONE_DIR/web/" "$WEB_WWW/"

# Patch autoload path in PHP files
step "Patching PHP library path in web application..."
find "$WEB_WWW" -name '*.php' -exec \
    sed -i "s|/opt/phplib|${PHPLIB_DIR}|g" {} \;

ok "Web files deployed."

# ── Download frontend assets ──────────────────────────────────────────────────

JS_DIR="${WEB_WWW}/assets/js"
mkdir -p "$JS_DIR"

if [[ ! -f "${JS_DIR}/tailwind.min.js" ]]; then
    step "Downloading Tailwind CSS v3.4.17 ..."
    curl -fsSL "https://cdn.tailwindcss.com/3.4.17" -o "${JS_DIR}/tailwind.min.js" \
        || warn "Tailwind download failed – install manually."
    ok "tailwind.min.js downloaded."
else
    ok "tailwind.min.js already present."
fi

if [[ ! -f "${JS_DIR}/chart.min.js" ]]; then
    step "Downloading Chart.js v4 ..."
    curl -fsSL "https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js" \
        -o "${JS_DIR}/chart.min.js" \
        || warn "Chart.js download failed – install manually."
    ok "chart.min.js downloaded."
else
    ok "chart.min.js already present."
fi

# ── Write web app config.json ─────────────────────────────────────────────────

step "Writing web application config.json ..."

# Build oidc_ssl_verify value: boolean or path string
if [[ "$OIDC_SSL_VERIFY" == "true" ]]; then
    OIDC_SSL_VERIFY_JSON="true"
elif [[ "$OIDC_SSL_VERIFY" == "false" ]]; then
    OIDC_SSL_VERIFY_JSON="false"
else
    # It's a path string
    OIDC_SSL_VERIFY_JSON=$(json_escape "$OIDC_SSL_VERIFY")
fi

python3 - << PYEOF
import json

oidc_enabled = "${OIDC_ENABLED}" == "yes"
ssl_verify_raw = "${OIDC_SSL_VERIFY}"

# Determine ssl_verify value type
if ssl_verify_raw == "true":
    ssl_verify = True
elif ssl_verify_raw == "false":
    ssl_verify = False
else:
    ssl_verify = ssl_verify_raw  # path string

config = {
    "database": {
        "host":     "cronmanager-db",
        "port":     3306,
        "name":     "${DB_NAME}",
        "user":     "${DB_USER}",
        "password": "${DB_PASSWORD}"
    },
    "agent": {
        "url":         "${AGENT_URL}",
        "hmac_secret": "${HMAC_SECRET}",
        "timeout":     10
    },
    "logging": {
        "path":     "/var/www/log/cronmanager-web.log",
        "level":    "${WEB_LOG_LEVEL}",
        "max_days": 30
    },
    "session": {
        "lifetime": 3600,
        "name":     "cronmanager_sess"
    },
    "i18n": {
        "default_language": "${WEB_LANG}",
        "available":        ["en", "de"]
    },
    "auth": {
        "oidc_enabled":      oidc_enabled,
        "oidc_provider_url": "${OIDC_PROVIDER_URL}",
        "oidc_client_id":    "${OIDC_CLIENT_ID}",
        "oidc_client_secret":"${OIDC_CLIENT_SECRET}",
        "oidc_redirect_uri": "${OIDC_REDIRECT_URI}",
        "oidc_ssl_verify":   ssl_verify,
        "oidc_ssl_ca_bundle":"${OIDC_CA_BUNDLE}"
    }
}

with open("${WEB_CONF}/config.json", "w") as f:
    json.dump(config, f, indent=4, ensure_ascii=False)
print("  Written: ${WEB_CONF}/config.json")
PYEOF

chmod 640 "${WEB_CONF}/config.json"
ok "Web config.json written."

# ═════════════════════════════════════════════════════════════════════════════
#  13. CREDENTIAL AND DEPLOY FILES
# ═════════════════════════════════════════════════════════════════════════════

header "Step 11 – Credential Files"

# db.credentials (used for reference and docker compose --env-file)
cat > "${COMPOSE_DIR}/db.credentials" << CREDEOF
# =============================================================================
# Cronmanager – Database Credentials
# KEEP THIS FILE SECURE – do not commit to version control
# =============================================================================
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
DB_ROOT_USER=root
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
CREDEOF
chmod 600 "${COMPOSE_DIR}/db.credentials"
ok "db.credentials written to ${COMPOSE_DIR}/db.credentials"

# .env for docker compose (docker compose reads .env automatically)
cat > "${COMPOSE_DIR}/.env" << ENVEOF
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
ENVEOF
chmod 600 "${COMPOSE_DIR}/.env"
ok ".env written to ${COMPOSE_DIR}/.env"

# deploy.env (informational / future use with deploy.sh)
cat > "${COMPOSE_DIR}/deploy.env" << DEPLOYEOF
# =============================================================================
# Cronmanager – Deployment Configuration
# =============================================================================
DEPLOY_TYPE=LOCAL
DEPLOY_DB=${DB_DIR}/
DEPLOY_WEB=${WEB_DIR}/
DEPLOY_AGENT=${AGENT_DIR}/
DEPLOY_COMPOSER=${PHPLIB_DIR}/
DEPLOY_COMPOSER_VENDOR=${PHPLIB_DIR}/vendor/
DEPLOYEOF
ok "deploy.env written to ${COMPOSE_DIR}/deploy.env"

# ═════════════════════════════════════════════════════════════════════════════
#  14. DOCKER COMPOSE FILE
# ═════════════════════════════════════════════════════════════════════════════

header "Step 12 – Docker Compose"

COMPOSE_FILE="${COMPOSE_DIR}/docker-compose.yml"

cat > "$COMPOSE_FILE" << COMPOSEEOF
# =============================================================================
#  Cronmanager – docker-compose.yml
#  Generated by simple_debian_setup.sh
# =============================================================================

services:

  # ---------------------------------------------------------------------------
  # Web Application (PHP-FPM + Nginx)
  # ---------------------------------------------------------------------------
  cronmanager-web:
    image: cs1711/cs_php-nginx-fpm:latest-alpine
    container_name: cronmanager-web
    restart: unless-stopped
    extra_hosts:
      - "host.docker.internal:host-gateway"
    volumes:
      - /etc/localtime:/etc/localtime:ro
      - ${WEB_CONF}:/var/www/conf
      - ${WEB_WWW}:/var/www/html
      - ${WEB_LOG}:/var/www/log
      - ${PHPLIB_DIR}/vendor:/var/www/libs/vendor
    ports:
      - "${WEB_PORT}:80"
    networks:
      - cronmanager-internal
    depends_on:
      cronmanager-db:
        condition: service_healthy

  # ---------------------------------------------------------------------------
  # Database (MariaDB LTS)
  # ---------------------------------------------------------------------------
  cronmanager-db:
    image: mariadb:lts
    container_name: cronmanager-db
    restart: unless-stopped
    volumes:
      - ${DB_DATA}:/var/lib/mysql
      - ${DB_CONF}:/etc/mysql
      - ${DB_LOG}:/var/log/mysql
      - ${DB_INIT}:/docker-entrypoint-initdb.d
    ports:
      - "127.0.0.1:3306:3306"
    environment:
      MARIADB_DATABASE:      \${DB_NAME}
      MARIADB_USER:          \${DB_USER}
      MARIADB_PASSWORD:      \${DB_PASSWORD}
      MARIADB_ROOT_PASSWORD: \${DB_ROOT_PASSWORD}
    healthcheck:
      test:         ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval:     30s
      timeout:      5s
      retries:      3
      start_period: 30s
    networks:
      - cronmanager-internal

networks:
  cronmanager-internal:
    driver: bridge
COMPOSEEOF

ok "docker-compose.yml written to ${COMPOSE_FILE}"
blank

# Display the generated file
sep
echo -e "  ${BOLD}Generated docker-compose.yml:${NC}"
blank
sed 's/^/    /' "$COMPOSE_FILE"
blank
sep

# ═════════════════════════════════════════════════════════════════════════════
#  15. START HOST AGENT + HEALTH CHECK
# ═════════════════════════════════════════════════════════════════════════════

header "Step 13 – Start Host Agent"

step "Starting cronmanager-agent service..."
if systemctl start cronmanager-agent.service 2>&1; then
    sleep 2

    # Health check
    HEALTH_URL="http://127.0.0.1:${AGENT_PORT}/health"
    step "Health check: ${HEALTH_URL}"
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
        --max-time 5 "$HEALTH_URL" 2>/dev/null || echo "000")

    if [[ "$HTTP_CODE" == "200" ]]; then
        ok "Agent health check passed  (HTTP ${HTTP_CODE})."
    else
        warn "Agent health check returned HTTP ${HTTP_CODE}."
        warn "Check logs:  journalctl -u cronmanager-agent -n 50"
    fi
else
    warn "Agent service could not be started."
    warn "Check logs:  journalctl -u cronmanager-agent -n 50"
fi

# ═════════════════════════════════════════════════════════════════════════════
#  16. DOCKER COMPOSE DEPLOYMENT
# ═════════════════════════════════════════════════════════════════════════════

header "Step 14 – Docker Stack"

DOCKER_DEPLOYED=false

ask_yn AUTO_DEPLOY "Start the Docker stack automatically now?" "yes"

if [[ "$AUTO_DEPLOY" == "yes" ]]; then
    step "Starting Docker stack in ${COMPOSE_DIR} ..."
    cd "$COMPOSE_DIR"
    docker compose up -d
    ok "Docker stack started."
    DOCKER_DEPLOYED=true
else
    info "Docker stack was NOT started automatically."
    ask_yn MANUAL_DEPLOYED "Have you already deployed the Docker stack manually?" "no"
    [[ "$MANUAL_DEPLOYED" == "yes" ]] && DOCKER_DEPLOYED=true
fi

# ═════════════════════════════════════════════════════════════════════════════
#  17. DATABASE SCHEMA
# ═════════════════════════════════════════════════════════════════════════════

header "Step 15 – Database Schema"

SCHEMA_FILE="${AGENT_DIR}/sql/schema.sql"
MIGRATIONS_DIR="${AGENT_DIR}/sql/migrations"

if [[ "$DOCKER_DEPLOYED" == true ]]; then
    ask_yn APPLY_SCHEMA "Apply database schema and migrations now?" "yes"

    if [[ "$APPLY_SCHEMA" == "yes" ]]; then
        # Wait for MariaDB to be healthy
        step "Waiting for MariaDB to be ready..."
        RETRIES=15
        READY=false
        for i in $(seq 1 $RETRIES); do
            if docker exec cronmanager-db healthcheck.sh --connect --innodb_initialized \
                &>/dev/null 2>&1; then
                READY=true
                break
            fi
            echo -ne "    Attempt ${i}/${RETRIES}... \r"
            sleep 4
        done
        echo

        if [[ "$READY" == false ]]; then
            warn "MariaDB did not become healthy in time."
            warn "Apply the schema manually after the database is ready:"
            info "  docker exec -i cronmanager-db mariadb -u ${DB_USER} -p'<password>' ${DB_NAME} < ${SCHEMA_FILE}"
        else
            ok "MariaDB is ready."

            if [[ -f "$SCHEMA_FILE" ]]; then
                step "Applying schema.sql ..."
                docker exec -i cronmanager-db \
                    mariadb -u "$DB_USER" -p"${DB_PASSWORD}" "$DB_NAME" \
                    < "$SCHEMA_FILE" \
                    && ok "Schema applied." \
                    || warn "Schema application failed – it may already exist (safe to ignore on re-runs)."
            else
                warn "Schema file not found: ${SCHEMA_FILE}"
            fi

            if [[ -d "$MIGRATIONS_DIR" ]]; then
                step "Applying migrations ..."
                for migration in $(ls -1v "$MIGRATIONS_DIR"/*.sql 2>/dev/null); do
                    MIG_NAME=$(basename "$migration")
                    docker exec -i cronmanager-db \
                        mariadb -u "$DB_USER" -p"${DB_PASSWORD}" "$DB_NAME" \
                        < "$migration" \
                        && ok "  Migration applied: ${MIG_NAME}" \
                        || warn "  Migration ${MIG_NAME} failed (may already be applied – safe to ignore)."
                done
            fi
        fi
    fi
else
    info "Docker stack not deployed – skipping schema setup."
    info "Apply schema manually after deployment:"
    info "  docker exec -i cronmanager-db mariadb -u ${DB_USER} -p'<password>' ${DB_NAME} < ${SCHEMA_FILE}"
fi

# ═════════════════════════════════════════════════════════════════════════════
#  18. SUMMARY
# ═════════════════════════════════════════════════════════════════════════════

header "Setup Complete"

echo -e "  ${GREEN}${BOLD}Cronmanager has been installed successfully.${NC}"
blank
echo -e "  ${BOLD}Installed paths:${NC}"
echo -e "    Host agent    : ${CYAN}${AGENT_DIR}${NC}"
echo -e "    Web app       : ${CYAN}${WEB_WWW}${NC}"
echo -e "    Web config    : ${CYAN}${WEB_CONF}/config.json${NC}"
echo -e "    Agent config  : ${CYAN}${AGENT_DIR}/config/config.json${NC}"
echo -e "    Docker Compose: ${CYAN}${COMPOSE_DIR}/${NC}"
echo -e "    DB data       : ${CYAN}${DB_DATA}${NC}"
blank
echo -e "  ${BOLD}Service management:${NC}"
echo -e "    ${CYAN}systemctl status cronmanager-agent${NC}"
echo -e "    ${CYAN}systemctl restart cronmanager-agent${NC}"
echo -e "    ${CYAN}journalctl -u cronmanager-agent -f${NC}"
blank
echo -e "  ${BOLD}Docker stack (from ${COMPOSE_DIR}):${NC}"
echo -e "    ${CYAN}docker compose up -d${NC}       Start"
echo -e "    ${CYAN}docker compose down${NC}         Stop"
echo -e "    ${CYAN}docker compose logs -f${NC}      Logs"
blank
echo -e "  ${BOLD}Agent health check:${NC}"
echo -e "    ${CYAN}curl http://127.0.0.1:${AGENT_PORT}/health${NC}"
blank
echo -e "  ${BOLD}Web UI:${NC}"
echo -e "    ${CYAN}http://$(hostname -I | awk '{print $1}'):${WEB_PORT}/${NC}"
echo -e "    Open this URL in your browser to complete the setup."
echo -e "    On first visit, create the initial administrator account."
blank
echo -e "  ${BOLD}HMAC secret${NC}  (keep this secure – needed if you reinstall):"
echo -e "    ${YELLOW}${HMAC_SECRET}${NC}"
blank
echo -e "  ${BOLD}Next steps:${NC}"
echo -e "    1. Open the web UI and create your admin account."
[[ "$OIDC_ENABLED" == "yes" ]] && \
    echo -e "    2. Verify OIDC login at ${CYAN}${OIDC_REDIRECT_URI}${NC}."
[[ "$MAIL_ENABLED" == "yes" ]] && \
    echo -e "    3. Test email notifications by creating a job with 'Notify on failure' enabled."
echo -e "    4. Add your first cron job via the web UI."
blank
