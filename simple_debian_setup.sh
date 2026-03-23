#!/usr/bin/env bash
# =============================================================================
#  Cronmanager – Simple Debian Setup Script
#
#  Guides through a complete installation of Cronmanager on a local or
#  remote Debian / Ubuntu based host.  Covers:
#    • Prerequisite check and installation on the target host
#    • Repository clone
#    • Composer / PHP library setup on the target host
#    • Host agent deployment and systemd service
#    • Web application deployment (Docker + MariaDB)
#    • Optional OIDC / SSO integration
#    • Optional email failure notifications
#
#  Run this script from any machine that can reach the target host via SSH.
#  For local installation: answer "local" when asked for the target.
#
#  @author  Christian Schulz <technik@meinetechnikwelt.rocks>
#  @license GNU General Public License version 3 or later
# =============================================================================

set -uo pipefail

SCRIPT_VERSION="1.1.0"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Colours ──────────────────────────────────────────────────────────────────
# Use $'...' ANSI-C quoting so the actual ESC byte (0x1b) is stored in the
# variable – this makes the codes work correctly in both echo and read -p.
if [[ -t 1 ]]; then
    RED=$'\033[0;31m'
    GREEN=$'\033[0;32m'
    YELLOW=$'\033[1;33m'
    CYAN=$'\033[0;36m'
    BLUE=$'\033[0;34m'
    BOLD=$'\033[1m'
    NC=$'\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; CYAN=''; BLUE=''; BOLD=''; NC=''
fi

# ── Output helpers ────────────────────────────────────────────────────────────
header() {
    echo
    echo -e "${BOLD}${CYAN}══════════════════════════════════════════════════${NC}"
    echo -e "${BOLD}${CYAN}  $*${NC}"
    echo -e "${BOLD}${CYAN}══════════════════════════════════════════════════${NC}"
    echo
}
step()  { echo -e "  ${BOLD}▶  $*${NC}"; }
info()  { echo -e "  ${BLUE}→${NC}  $*"; }
ok()    { echo -e "  ${GREEN}✔${NC}  $*"; }
warn()  { echo -e "  ${YELLOW}⚠${NC}  $*"; }
error() { echo -e "  ${RED}✘${NC}  $*" >&2; }
die()            { error "$*"; exit 1; }
# warn_continue: show an error and ask the user whether to continue or abort.
# Use this instead of die() for recoverable operational failures.
warn_continue()  {
    error "$*"
    ask_yn _wc_cont "Continue despite this error?" "no"
    [[ "$_wc_cont" == "no" ]] && exit 1
    return 0
}
sep()   { echo -e "  ${CYAN}──────────────────────────────────────────────────${NC}"; }
blank() { echo; }

# ── Interactive input helpers ─────────────────────────────────────────────────

# All read calls use </dev/tty so the script works correctly when launched
# via curl | bash (where stdin is the pipe, not the terminal).

# ask VARNAME "Prompt" "default"
ask() {
    local _var="$1" _prompt="$2" _default="${3:-}" _input
    if [[ -n "$_default" ]]; then
        read -r -p "  ${BOLD}${_prompt}${NC} [${_default}]: " _input < /dev/tty
        _input="${_input:-$_default}"
    else
        _input=""
        while [[ -z "$_input" ]]; do
            read -r -p "  ${BOLD}${_prompt}${NC}: " _input < /dev/tty
            [[ -z "$_input" ]] && warn "This field is required."
        done
    fi
    printf -v "$_var" '%s' "$_input"
}

# ask_secret VARNAME "Prompt"  (hidden, required)
ask_secret() {
    local _var="$1" _prompt="$2" _input=""
    while [[ -z "$_input" ]]; do
        read -r -s -p "  ${BOLD}${_prompt}${NC}: " _input < /dev/tty; echo
        [[ -z "$_input" ]] && warn "This field is required."
    done
    printf -v "$_var" '%s' "$_input"
}

# ask_yn VARNAME "Question?" "yes|no"  →  sets VARNAME to "yes" or "no"
ask_yn() {
    local _var="$1" _prompt="$2" _default="${3:-yes}" _input _hint
    [[ "$_default" == "yes" ]] && _hint="Y/n" || _hint="y/N"
    read -r -p "  ${BOLD}${_prompt}${NC} [${_hint}]: " _input < /dev/tty
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
        read -r -p "  Choice [1]: " _input < /dev/tty
        _input="${_input:-1}"
        if [[ "$_input" =~ ^[0-9]+$ ]] && (( _input >= 1 && _input <= ${#_opts[@]} )); then
            printf -v "$_var" '%s' "${_opts[$((_input-1))]}"
            _valid=true
        else
            warn "Please enter a number between 1 and ${#_opts[@]}."
        fi
    done
}

# ── Target execution helpers ──────────────────────────────────────────────────
# All operations on the install target go through these functions so the
# rest of the script works identically for local and remote deployments.

TARGET_TYPE="local"   # "local" or "remote"
TARGET_SSH=""         # SSH host alias (remote only)

# Run a shell command string on the target
target_exec() {
    if [[ "$TARGET_TYPE" == "local" ]]; then
        bash -c "$1"
    else
        ssh "$TARGET_SSH" "$1"
    fi
}

# Pipe a multi-line bash script to the target for execution
target_script() {
    if [[ "$TARGET_TYPE" == "local" ]]; then
        bash -s
    else
        ssh "$TARGET_SSH" bash -s
    fi
}

# Write stdin to a file on the target (create / overwrite)
target_write() {
    local _dst="$1"
    if [[ "$TARGET_TYPE" == "local" ]]; then
        cat > "$_dst"
    else
        ssh "$TARGET_SSH" "cat > $(printf '%q' "$_dst")"
    fi
}

# Copy a local path (file or directory) to a path on the target
target_copy() {
    local _src="$1" _dst="$2"
    if [[ "$TARGET_TYPE" == "local" ]]; then
        rsync -a "$_src" "$_dst"
    else
        rsync -a -e "ssh" "$_src" "${TARGET_SSH}:${_dst}"
    fi
}

# ── Temp directory – cleaned up on exit ──────────────────────────────────────
CLONE_DIR=""
cleanup()     { [[ -n "$CLONE_DIR" && -d "$CLONE_DIR" ]] && rm -rf "$CLONE_DIR"; }
interrupted() { echo; warn "Setup interrupted by user (CTRL-C). You can re-run the script at any time."; cleanup; exit 130; }
trap cleanup EXIT
trap interrupted INT TERM

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
echo "  This script installs Cronmanager on a local or remote host:"
echo
echo "    •  Host agent (PHP 8.4 CLI, systemd)"
echo "    •  Web application + MariaDB (Docker)"
echo "    •  Shared PHP libraries via Composer"
echo "    •  Optional: OIDC / SSO authentication"
echo "    •  Optional: Email failure notifications"
echo
echo -e "  ${YELLOW}Target host requirements: Debian 12+ / Ubuntu 22.04+  •  root access${NC}"
blank

# ═════════════════════════════════════════════════════════════════════════════
#  2. TARGET HOST SELECTION
# ═════════════════════════════════════════════════════════════════════════════

header "Step 1 – Target Host"

echo "  Where should Cronmanager be installed?"
blank
echo "    1) This machine (local)"
echo "    2) A remote server via SSH"
blank
read -r -p "  ${BOLD}Target${NC} [1]: " _target_choice < /dev/tty
_target_choice="${_target_choice:-1}"

if [[ "$_target_choice" == "2" ]]; then
    TARGET_TYPE="remote"
    blank
    info "Enter a host alias from ~/.ssh/config or user@hostname."
    ask TARGET_SSH "SSH host" ""

    step "Testing SSH connection to ${TARGET_SSH} ..."
    if ssh -o ConnectTimeout=10 -o BatchMode=yes "$TARGET_SSH" "echo ok" &>/dev/null; then
        ok "SSH connection to ${TARGET_SSH} successful."
    else
        die "Cannot connect to ${TARGET_SSH} via SSH. Check your SSH config and key authentication."
    fi

    step "Checking root access on ${TARGET_SSH} ..."
    REMOTE_USER=$(ssh "$TARGET_SSH" "id -un" 2>/dev/null || echo "")
    if [[ "$REMOTE_USER" == "root" ]]; then
        ok "Connected as root on ${TARGET_SSH}."
    else
        die "SSH connection is not as root (got: ${REMOTE_USER}). Use root@${TARGET_SSH} or configure SSH accordingly."
    fi
else
    TARGET_TYPE="local"
    blank
    [[ "$EUID" -ne 0 ]] && die "Local installation requires root. Please run: sudo bash $0"
    ok "Installing locally on this machine."
fi

# ═════════════════════════════════════════════════════════════════════════════
#  3. PREREQUISITES CHECK ON TARGET
# ═════════════════════════════════════════════════════════════════════════════

header "Step 2 – Prerequisites"

if [[ "$TARGET_TYPE" == "remote" ]]; then
    info "Checking required packages on ${TARGET_SSH}..."
else
    info "Checking required packages on this machine..."
fi
blank

MISSING_PKGS=()

check_pkg() {
    local _label="$1" _apt_pkg="$2" _bin="${3:-}"
    local _check="dpkg -l '$_apt_pkg' 2>/dev/null | grep -q '^ii'"
    [[ -n "$_bin" ]] && _check="{ ${_check}; } || command -v '$_bin' >/dev/null 2>&1"
    if target_exec "$_check" 2>/dev/null; then
        ok "${_label} – OK"
    else
        warn "${_label} – NOT installed  (package: ${_apt_pkg})"
        MISSING_PKGS+=("$_apt_pkg")
    fi
}

check_php_ext() {
    local _ext="$1" _apt_pkg="$2"
    if target_exec "php -m 2>/dev/null | grep -qi '^${_ext}\$'" 2>/dev/null; then
        ok "PHP extension ${_ext} – OK"
    else
        warn "PHP extension ${_ext} – missing  (package: ${_apt_pkg})"
        MISSING_PKGS+=("$_apt_pkg")
    fi
}

check_pkg "PHP 8.4 CLI"   "php8.4-cli"       "php"
check_php_ext "pdo_mysql" "php8.4-mysql"
check_php_ext "mbstring"  "php8.4-mbstring"
check_php_ext "curl"      "php8.4-curl"
check_pkg "curl"          "curl"             "curl"
check_pkg "git"           "git"              "git"
check_pkg "openssl"       "openssl"          "openssl"
check_pkg "rsync"         "rsync"            "rsync"
check_pkg "unzip"         "unzip"            "unzip"
check_pkg "python3"       "python3"          "python3"

if target_exec "command -v docker >/dev/null 2>&1" 2>/dev/null; then
    ok "Docker – OK"
else
    warn "Docker – NOT installed"
    MISSING_PKGS+=("docker.io")
fi

if target_exec "docker compose version >/dev/null 2>&1" 2>/dev/null; then
    ok "docker compose (v2) – OK"
elif target_exec "command -v docker-compose >/dev/null 2>&1" 2>/dev/null; then
    ok "docker-compose (v1) – OK  (v2 recommended)"
else
    warn "docker compose – NOT installed"
    MISSING_PKGS+=("docker-compose-plugin")
fi

blank
MISSING_PKGS=($(printf '%s\n' "${MISSING_PKGS[@]}" | sort -u))

if [[ ${#MISSING_PKGS[@]} -eq 0 ]]; then
    ok "All prerequisites satisfied."
else
    warn "The following packages need to be installed on the target:"
    for _pkg in "${MISSING_PKGS[@]}"; do
        echo -e "    ${YELLOW}•${NC}  $_pkg"
    done
    blank
    ask_yn INSTALL_PKGS "Install missing packages now via apt?" "yes"

    if [[ "$INSTALL_PKGS" == "yes" ]]; then
        step "Updating package index on target..."
        target_exec "apt-get update -qq" || warn_continue "apt-get update failed."

        _pkgs=$(printf '%s ' "${MISSING_PKGS[@]}")
        step "Installing: ${_pkgs}"
        target_exec "DEBIAN_FRONTEND=noninteractive apt-get install -y ${_pkgs}" \
            || warn_continue "Package installation failed. Some packages may be missing."
        ok "All packages installed."

        for _ext in pdo_mysql mbstring curl; do
            target_exec "php -m 2>/dev/null | grep -qi '^${_ext}\$'" \
                || warn_continue "PHP extension ${_ext} still unavailable. Check your PHP setup."
        done
    else
        die "Cannot continue without required packages."
    fi
fi

# ═════════════════════════════════════════════════════════════════════════════
#  4. CLONE REPOSITORY  (always local – files are then copied to target)
# ═════════════════════════════════════════════════════════════════════════════

header "Step 3 – Clone Repository"

DETECTED_URL=$(git -C "$SCRIPT_DIR" remote get-url origin 2>/dev/null || true)
ask REPO_URL "Repository URL" "${DETECTED_URL:-https://github.com/csoscd/cronmanager.git}"

CLONE_DIR=$(mktemp -d /tmp/cronmanager-setup-XXXXXX)
step "Cloning into ${CLONE_DIR} ..."
git clone --depth=1 "$REPO_URL" "$CLONE_DIR" 2>&1 \
    || die "git clone failed. Check the URL and your internet connection."

[[ -f "$CLONE_DIR/agent/agent.php" ]] \
    || die "Cloned repo missing agent/agent.php – unexpected structure."
[[ -f "$CLONE_DIR/composer.json" ]] \
    || die "composer.json not found in cloned repository."
ok "Repository cloned successfully."

# ═════════════════════════════════════════════════════════════════════════════
#  5. COMPOSER CHECK AND INSTALL  (on target)
# ═════════════════════════════════════════════════════════════════════════════

header "Step 4 – Composer"

COMPOSER_BIN="composer"
if target_exec "command -v composer >/dev/null 2>&1" 2>/dev/null; then
    _ver=$(target_exec "composer --version 2>/dev/null | head -1" || true)
    ok "Composer is installed: ${_ver}"
elif target_exec "test -f /usr/local/bin/composer" 2>/dev/null; then
    ok "Composer found at /usr/local/bin/composer."
    COMPOSER_BIN="/usr/local/bin/composer"
else
    warn "Composer is not installed on the target."
    ask_yn INSTALL_COMPOSER "Install Composer on the target now?" "yes"

    if [[ "$INSTALL_COMPOSER" == "yes" ]]; then
        step "Downloading and installing Composer on target..."
        target_exec \
            "curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --quiet" \
            || warn_continue "Composer installation failed."
        COMPOSER_BIN="/usr/local/bin/composer"
        ok "Composer installed."
    else
        die "Cannot continue without Composer on the target."
    fi
fi

# ═════════════════════════════════════════════════════════════════════════════
#  6. PHP LIBRARY CHECK  (on target)
# ═════════════════════════════════════════════════════════════════════════════

header "Step 5 – PHP Libraries"

ask PHPLIB_DIR "Shared PHP library directory on target" "/opt/phplib"
VENDOR_DIR="${PHPLIB_DIR}/vendor"

declare -A REQUIRED_PACKAGES=(
    ["hassankhan/config"]="^2.1"
    ["monolog/monolog"]="^3.6"
    ["guzzlehttp/guzzle"]="^7.8"
    ["phpmailer/phpmailer"]="^6.8"
    ["dragonmantank/cron-expression"]="^3.3"
    ["lorisleiva/cron-translator"]="^0.4"
)

step "Checking installed packages in ${VENDOR_DIR} on target..."
MISSING_PACKAGES=()

for _pkg in "${!REQUIRED_PACKAGES[@]}"; do
    if target_exec "test -d '${VENDOR_DIR}/${_pkg}'" 2>/dev/null; then
        ok "$_pkg – OK"
    else
        warn "$_pkg – NOT found"
        MISSING_PACKAGES+=("$_pkg")
    fi
done

if [[ ${#MISSING_PACKAGES[@]} -eq 0 ]]; then
    ok "All required PHP libraries are installed."
else
    warn "The following libraries are missing:"
    for _pkg in "${MISSING_PACKAGES[@]}"; do
        echo -e "    ${YELLOW}•${NC}  $_pkg  ${REQUIRED_PACKAGES[$_pkg]}"
    done
    blank
    ask_yn ADD_LIBS "Add missing libraries to composer.json on target and run composer install?" "yes"

    if [[ "$ADD_LIBS" == "yes" ]]; then
        target_exec "mkdir -p '${PHPLIB_DIR}'"

        # Build a merged composer.json locally, then send it to the target
        _tmp_composer=$(mktemp /tmp/cronmanager-composer-XXXXXX.json)
        python3 - "$CLONE_DIR/composer.json" "$_tmp_composer" << 'PYEOF'
import json, sys

src_path  = sys.argv[1]
dest_path = sys.argv[2]

try:
    with open(dest_path) as f:
        data = json.load(f)
except Exception:
    data = {"name": "local/phplib", "description": "Shared PHP libraries", "type": "project"}

with open(src_path) as f:
    src = json.load(f)

data.setdefault("require", {})
for pkg, ver in src.get("require", {}).items():
    data["require"].setdefault(pkg, ver)

data.setdefault("config", {})["optimize-autoloader"] = True

with open(dest_path, "w") as f:
    json.dump(data, f, indent=4)
PYEOF
        target_write "${PHPLIB_DIR}/composer.json" < "$_tmp_composer"
        rm -f "$_tmp_composer"

        step "Running composer install on target..."
        target_exec "${COMPOSER_BIN} install --no-dev --optimize-autoloader --working-dir='${PHPLIB_DIR}'" \
            || warn_continue "composer install failed."
        ok "PHP libraries installed."
    else
        die "Cannot continue without required PHP libraries."
    fi
fi

# ═════════════════════════════════════════════════════════════════════════════
#  7. COLLECT CONFIGURATION
# ═════════════════════════════════════════════════════════════════════════════

header "Step 6 – Configuration"
echo "  Please answer the following questions. Press Enter to accept the default."
blank

# ── Installation paths ─────────────────────────────────────────────────────
sep
echo -e "  ${BOLD}Installation Paths (on target)${NC}"
blank
ask AGENT_DIR "Host agent directory"           "/opt/cronmanager/agent"
ask WEB_DIR   "Web application base directory" "/opt/cronmanager/web"
ask DB_DIR    "MariaDB data directory"         "/opt/cronmanager/db"

WEB_WWW="${WEB_DIR}/www"
WEB_CONF="${WEB_DIR}/conf"
WEB_LOG="${WEB_DIR}/log"
DB_DATA="${DB_DIR}/data"
DB_CONF="${DB_DIR}/conf"
DB_LOG="${DB_DIR}/log"
DB_INIT="${DB_DIR}/init"
COMPOSE_DIR="$WEB_DIR"
blank

# ── Database credentials ────────────────────────────────────────────────────
sep
echo -e "  ${BOLD}Database Credentials${NC}"
blank
ask        DB_NAME          "Database name"        "cronmanager"
ask        DB_USER          "Database user"        "cronmanager"
ask_secret DB_PASSWORD      "Database password"
ask_secret DB_ROOT_PASSWORD "MariaDB root password"
blank

# ── Agent settings ──────────────────────────────────────────────────────────
sep
echo -e "  ${BOLD}Host Agent Settings${NC}"
blank
ask AGENT_BIND "Agent listen address (0.0.0.0 = all interfaces)" "0.0.0.0"
ask AGENT_PORT "Agent listen port"                               "8865"
ask_choice AGENT_LOG_LEVEL "Agent log level" "info" "debug" "warning" "error"
blank

# ── Web application settings ────────────────────────────────────────────────
sep
echo -e "  ${BOLD}Web Application Settings${NC}"
blank
ask WEB_PORT "Web application HTTP port (external Docker port)" "8880"
ask WEB_LANG "Default language (en / de)"                       "en"
ask_choice WEB_LOG_LEVEL "Web application log level" "info" "debug" "warning" "error"
blank

AGENT_URL="http://host.docker.internal:${AGENT_PORT}"
info "Agent URL for web app (Docker → host): ${AGENT_URL}"
blank

# ── OIDC ────────────────────────────────────────────────────────────────────
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

# ── Email notifications ─────────────────────────────────────────────────────
sep
echo -e "  ${BOLD}Email Failure Notifications${NC}"
blank
ask_yn MAIL_ENABLED "Enable email notifications for job failures?" "no"
MAIL_HOST=""; MAIL_PORT="587"; MAIL_USER=""; MAIL_PASS=""
MAIL_FROM=""; MAIL_FROM_NAME="Cronmanager"; MAIL_TO=""
MAIL_ENC="tls"; MAIL_TIMEOUT="15"

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

# ── Summary ──────────────────────────────────────────────────────────────────
sep
echo -e "  ${BOLD}Configuration Summary${NC}"
blank
if [[ "$TARGET_TYPE" == "remote" ]]; then
    echo -e "    Target          : ${CYAN}${TARGET_SSH} (remote SSH)${NC}"
else
    echo -e "    Target          : ${CYAN}local${NC}"
fi
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
#  8. GENERATE HMAC SECRET  (locally)
# ═════════════════════════════════════════════════════════════════════════════

header "Step 7 – Security"

step "Generating HMAC-SHA256 secret..."
HMAC_SECRET=$(openssl rand -hex 32)
ok "HMAC secret generated  (${#HMAC_SECRET} hex characters)."

# ═════════════════════════════════════════════════════════════════════════════
#  9. CREATE DIRECTORY STRUCTURE  (on target)
# ═════════════════════════════════════════════════════════════════════════════

header "Step 8 – Directory Structure"

for _dir in \
    "$AGENT_DIR" "$AGENT_DIR/config" "$AGENT_DIR/bin" "$AGENT_DIR/log" \
    "$WEB_WWW" "$WEB_CONF" "$WEB_LOG" \
    "$DB_DATA" "$DB_CONF" "$DB_LOG" "$DB_INIT"; do
    target_exec "mkdir -p '$_dir'" || warn_continue "Failed to create directory: $_dir"
    ok "Created: $_dir"
done

# The web log and conf directories are read/written by the nobody user inside the container
step "Setting ownership of web log and conf directories..."
target_exec "chown nobody:nogroup '${WEB_LOG}' '${WEB_CONF}'" \
    || warn_continue "Failed to set ownership on web directories."
ok "Ownership set: ${WEB_LOG}, ${WEB_CONF} → nobody:nogroup"

# ═════════════════════════════════════════════════════════════════════════════
#  10. DEPLOY AGENT FILES
# ═════════════════════════════════════════════════════════════════════════════

header "Step 9 – Deploy Host Agent"

step "Copying agent files to ${AGENT_DIR} on target..."
target_copy "$CLONE_DIR/agent/" "$AGENT_DIR/" \
    || warn_continue "Failed to copy agent files to ${AGENT_DIR}."
ok "Agent files deployed."

step "Patching hardcoded paths in all agent files..."
target_exec "find '${AGENT_DIR}' \( -name '*.php' -o -name '*.sh' -o -name '*.sql' \) \
    -exec sed -i \
        -e 's|/opt/phpscripts/cronmanager/agent|${AGENT_DIR}|g' \
        -e 's|/opt/phplib|${PHPLIB_DIR}|g' \
    {} \;" || warn_continue "Failed to patch hardcoded paths in agent files."
target_exec "chmod +x '${AGENT_DIR}/bin/'*.sh 2>/dev/null || true"
ok "Paths patched."

# Write agent config.json – generated locally via Python, written to target
step "Writing agent config.json..."

# Build Python booleans from shell vars before the heredoc
_mail_py=$( [[ "$MAIL_ENABLED" == "yes" ]] && echo "True" || echo "False" )
_mail_enc_py="${MAIL_ENC}"

_agent_conf_tmp=$(mktemp /tmp/cronmanager-agent-conf-XXXXXX.json)
python3 - > "$_agent_conf_tmp" << PYEOF
import json

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
        "enabled":      ${_mail_py},
        "host":         "${MAIL_HOST}",
        "port":         int("${MAIL_PORT}") if "${MAIL_PORT}" else 587,
        "username":     "${MAIL_USER}",
        "password":     "${MAIL_PASS}",
        "from":         "${MAIL_FROM}",
        "from_name":    "${MAIL_FROM_NAME}",
        "to":           "${MAIL_TO}",
        "encryption":   "" if "${_mail_enc_py}" == "none" else "${_mail_enc_py}",
        "smtp_timeout": int("${MAIL_TIMEOUT}") if "${MAIL_TIMEOUT}" else 15
    },
    "cron": {
        "wrapper_script": "${AGENT_DIR}/bin/cron-wrapper.sh"
    }
}
print(json.dumps(config, indent=4, ensure_ascii=False))
PYEOF
[[ $? -eq 0 ]] || warn_continue "Failed to generate agent config.json."
target_write "${AGENT_DIR}/config/config.json" < "$_agent_conf_tmp" \
    || warn_continue "Failed to write agent config.json to target."
rm -f "$_agent_conf_tmp"

target_exec "chmod 600 '${AGENT_DIR}/config/config.json'" 2>/dev/null || true
ok "Agent config.json written."

# ═════════════════════════════════════════════════════════════════════════════
#  11. SYSTEMD SERVICE  (on target)
# ═════════════════════════════════════════════════════════════════════════════

header "Step 10 – Systemd Service"

# Ship the service unit: patch paths from the clone's template, send to target
_tmp_svc=$(mktemp /tmp/cronmanager-svc-XXXXXX.service)

if [[ -f "$CLONE_DIR/agent/systemd/cronmanager-agent.service" ]]; then
    sed \
        -e "s|/opt/phpscripts/cronmanager/agent|${AGENT_DIR}|g" \
        -e "s|/opt/phplib|${PHPLIB_DIR}|g" \
        "$CLONE_DIR/agent/systemd/cronmanager-agent.service" > "$_tmp_svc"
else
    cat > "$_tmp_svc" << SVCEOF
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
fi

target_write "/etc/systemd/system/cronmanager-agent.service" < "$_tmp_svc" \
    || warn_continue "Failed to write systemd service file."
rm -f "$_tmp_svc"

target_exec "systemctl daemon-reload && systemctl enable cronmanager-agent.service" \
    || warn_continue "Failed to enable cronmanager-agent service."
ok "Service installed and enabled."

# ═════════════════════════════════════════════════════════════════════════════
#  12. DEPLOY WEB APPLICATION  (on target)
# ═════════════════════════════════════════════════════════════════════════════

header "Step 11 – Deploy Web Application"

step "Copying web files to ${WEB_WWW} on target..."
target_copy "$CLONE_DIR/web/" "$WEB_WWW/" \
    || warn_continue "Failed to copy web application files to ${WEB_WWW}."

step "Patching PHP library path in web application..."
target_exec "find '${WEB_WWW}' -name '*.php' -exec sed -i 's|/opt/phplib|${PHPLIB_DIR}|g' {} \\;" \
    || warn_continue "Failed to patch PHP library paths in web application."
ok "Web files deployed."

# Download frontend assets on the target
step "Downloading Tailwind CSS v3.4.17..."
target_exec "
    test -f '${WEB_WWW}/assets/js/tailwind.min.js' && exit 0
    mkdir -p '${WEB_WWW}/assets/js'
    curl -fsSL 'https://cdn.tailwindcss.com/3.4.17' \
        -o '${WEB_WWW}/assets/js/tailwind.min.js'
" && ok "tailwind.min.js – OK." || warn "Tailwind download failed – install manually."

step "Downloading Chart.js v4..."
target_exec "
    test -f '${WEB_WWW}/assets/js/chart.min.js' && exit 0
    curl -fsSL 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js' \
        -o '${WEB_WWW}/assets/js/chart.min.js'
" && ok "chart.min.js – OK." || warn "Chart.js download failed – install manually."

# Write web config.json – generated locally, written to target
step "Writing web application config.json..."

_oidc_py=$( [[ "$OIDC_ENABLED" == "yes" ]] && echo "True" || echo "False" )
_ssl_raw="$OIDC_SSL_VERIFY"

_web_conf_tmp=$(mktemp /tmp/cronmanager-web-conf-XXXXXX.json)
python3 - > "$_web_conf_tmp" << PYEOF
import json

ssl_raw = "${_ssl_raw}"
if ssl_raw == "true":
    ssl_verify = True
elif ssl_raw == "false":
    ssl_verify = False
else:
    ssl_verify = ssl_raw  # path to CA bundle

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
        "oidc_enabled":      ${_oidc_py},
        "oidc_provider_url": "${OIDC_PROVIDER_URL}",
        "oidc_client_id":    "${OIDC_CLIENT_ID}",
        "oidc_client_secret":"${OIDC_CLIENT_SECRET}",
        "oidc_redirect_uri": "${OIDC_REDIRECT_URI}",
        "oidc_ssl_verify":   ssl_verify,
        "oidc_ssl_ca_bundle":"${OIDC_CA_BUNDLE}"
    }
}
print(json.dumps(config, indent=4, ensure_ascii=False))
PYEOF
[[ $? -eq 0 ]] || warn_continue "Failed to generate web config.json."
target_write "${WEB_CONF}/config.json" < "$_web_conf_tmp" \
    || warn_continue "Failed to write web config.json to target."
rm -f "$_web_conf_tmp"

target_exec "chmod 640 '${WEB_CONF}/config.json'" 2>/dev/null || true
target_exec "chown nobody:nogroup '${WEB_CONF}/config.json'" 2>/dev/null || true
ok "Web config.json written."

# ═════════════════════════════════════════════════════════════════════════════
#  13. CREDENTIAL AND COMPOSE FILES  (on target)
# ═════════════════════════════════════════════════════════════════════════════

header "Step 12 – Credential and Compose Files"

# db.credentials
cat << CREDEOF | target_write "${COMPOSE_DIR}/db.credentials" \
    || warn_continue "Failed to write db.credentials."
# Cronmanager – Database Credentials
# KEEP SECURE – do not commit to version control
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
DB_ROOT_USER=root
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
CREDEOF
target_exec "chmod 600 '${COMPOSE_DIR}/db.credentials'" 2>/dev/null || true
ok "db.credentials written."

# .env (read automatically by docker compose)
cat << ENVEOF | target_write "${COMPOSE_DIR}/.env" \
    || warn_continue "Failed to write .env file."
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
ENVEOF
target_exec "chmod 600 '${COMPOSE_DIR}/.env'" 2>/dev/null || true
ok ".env written."

# deploy.env (informational)
cat << DEPLOYEOF | target_write "${COMPOSE_DIR}/deploy.env" \
    || warn_continue "Failed to write deploy.env."
DEPLOY_TYPE=LOCAL
DEPLOY_DB=${DB_DIR}/
DEPLOY_WEB=${WEB_DIR}/
DEPLOY_AGENT=${AGENT_DIR}/
DEPLOY_COMPOSER=${PHPLIB_DIR}/
DEPLOY_COMPOSER_VENDOR=${PHPLIB_DIR}/vendor/
DEPLOYEOF
ok "deploy.env written."

# ── Generate docker-compose.yml ───────────────────────────────────────────
# Shell variables for paths are expanded now (intentional).
# \${DB_*} is escaped so docker compose substitutes them from .env at runtime.
cat << COMPOSEEOF | target_write "${COMPOSE_DIR}/docker-compose.yml" \
    || warn_continue "Failed to write docker-compose.yml."
# Cronmanager – docker-compose.yml
# Generated by simple_debian_setup.sh

services:

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
      - "${WEB_PORT}:8080"
    depends_on:
      cronmanager-db:
        condition: service_healthy

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
COMPOSEEOF
ok "docker-compose.yml written."

# Display the generated credential files and docker-compose.yml
blank; sep
echo -e "  ${BOLD}Generated db.credentials${NC}  ${YELLOW}(keep secure)${NC}:"
blank
target_exec "cat '${COMPOSE_DIR}/db.credentials'" | sed 's/^/    /'
blank; sep
echo -e "  ${BOLD}Generated .env${NC}  ${YELLOW}(keep secure)${NC}:"
blank
target_exec "cat '${COMPOSE_DIR}/.env'" | sed 's/^/    /'
blank; sep
echo -e "  ${BOLD}Generated docker-compose.yml:${NC}"
blank
target_exec "cat '${COMPOSE_DIR}/docker-compose.yml'" | sed 's/^/    /'
blank; sep

# ═════════════════════════════════════════════════════════════════════════════
#  14. DOCKER STACK  (on target)
#  Must run before the agent so MariaDB is already up when the agent starts.
# ═════════════════════════════════════════════════════════════════════════════

header "Step 13 – Docker Stack"

DOCKER_DEPLOYED=false
ask_yn AUTO_DEPLOY "Start the Docker stack on target now?" "yes"

if [[ "$AUTO_DEPLOY" == "yes" ]]; then
    step "Starting Docker stack..."
    target_exec "cd '${COMPOSE_DIR}' && docker compose up -d" \
        || warn_continue "Docker stack failed to start. Check logs with: docker compose -f '${COMPOSE_DIR}/docker-compose.yml' logs"
    ok "Docker stack started."
    DOCKER_DEPLOYED=true
else
    info "Docker stack was NOT started automatically."
    ask_yn MANUAL_DEPLOYED "Has the Docker stack already been deployed manually?" "no"
    [[ "$MANUAL_DEPLOYED" == "yes" ]] && DOCKER_DEPLOYED=true
fi

# ═════════════════════════════════════════════════════════════════════════════
#  15. DATABASE SCHEMA  (on target via docker exec)
# ═════════════════════════════════════════════════════════════════════════════

header "Step 14 – Database Schema"

SCHEMA_FILE="${AGENT_DIR}/sql/schema.sql"
MIGRATIONS_DIR="${AGENT_DIR}/sql/migrations"

if [[ "$DOCKER_DEPLOYED" == true ]]; then
    ask_yn APPLY_SCHEMA "Apply database schema and migrations now?" "yes"

    if [[ "$APPLY_SCHEMA" == "yes" ]]; then
        step "Waiting for MariaDB to be ready..."
        READY=false
        for _i in $(seq 1 15); do
            if target_exec \
                "docker exec cronmanager-db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1"; then
                READY=true; break
            fi
            echo -ne "    Attempt ${_i}/15...\r"
            sleep 4
        done
        echo

        if [[ "$READY" == false ]]; then
            warn "MariaDB did not become healthy in time."
            warn "Apply schema manually once the DB is ready."
        else
            ok "MariaDB is ready."

            step "Applying schema.sql..."
            # Connect as root: docker exec always resolves to @'localhost' in
            # MariaDB (even with -h 127.0.0.1 due to reverse DNS), so the
            # app user cronmanager@'%' created by the Docker image would be
            # denied. Root has unconditional @'localhost' access.
            target_exec \
                "docker exec -i cronmanager-db \
                 mariadb -u root -p'${DB_ROOT_PASSWORD}' '${DB_NAME}' \
                 < '${SCHEMA_FILE}'" \
                && ok "Schema applied." \
                || warn "Schema failed (may already exist – safe to ignore on re-runs)."

            step "Applying migrations..."
            target_script << SHELLSCRIPT
for mig in \$(ls -1v '${MIGRATIONS_DIR}'/*.sql 2>/dev/null); do
    name=\$(basename "\$mig")
    docker exec -i cronmanager-db \
        mariadb -u root -p'${DB_ROOT_PASSWORD}' '${DB_NAME}' < "\$mig" \
        && echo "    applied: \${name}" \
        || echo "    skipped (may already be applied): \${name}"
done
SHELLSCRIPT
        fi
    fi
else
    info "Docker stack not deployed – skipping schema setup."
    info "Apply manually:  docker exec -i cronmanager-db mariadb -u root -p'<root-pw>' ${DB_NAME} < ${SCHEMA_FILE}"
fi

# ═════════════════════════════════════════════════════════════════════════════
#  16. START HOST AGENT + HEALTH CHECK  (on target)
#  Runs last so MariaDB is already up and the schema is applied.
# ═════════════════════════════════════════════════════════════════════════════

header "Step 15 – Start Host Agent"

step "Starting cronmanager-agent service..."
if target_exec "systemctl start cronmanager-agent.service" 2>&1; then
    sleep 2
    step "Health check: http://127.0.0.1:${AGENT_PORT}/health"
    HTTP_CODE=$(target_exec \
        "curl -s -o /dev/null -w '%{http_code}' --max-time 5 \
         http://127.0.0.1:${AGENT_PORT}/health 2>/dev/null || echo 000")
    if [[ "$HTTP_CODE" == "200" ]]; then
        ok "Agent health check passed  (HTTP ${HTTP_CODE})."
    else
        warn "Agent health check returned HTTP ${HTTP_CODE}."
        warn "Check logs with:  journalctl -u cronmanager-agent -n 50"
    fi
else
    warn "Agent service could not be started."
    warn "Check logs with:  journalctl -u cronmanager-agent -n 50"
fi

# ═════════════════════════════════════════════════════════════════════════════
#  17. SUMMARY
# ═════════════════════════════════════════════════════════════════════════════

header "Setup Complete"

if [[ "$TARGET_TYPE" == "remote" ]]; then
    _label="$TARGET_SSH"
    _ssh_pfx="ssh ${TARGET_SSH} "
    _host_ip=$(target_exec "hostname -I | awk '{print \$1}'" 2>/dev/null || echo "<target-ip>")
else
    _label="localhost"
    _ssh_pfx=""
    _host_ip=$(hostname -I 2>/dev/null | awk '{print $1}' || echo "127.0.0.1")
fi

echo -e "  ${GREEN}${BOLD}Cronmanager has been installed on ${_label}.${NC}"
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
echo -e "    ${CYAN}${_ssh_pfx}systemctl status cronmanager-agent${NC}"
echo -e "    ${CYAN}${_ssh_pfx}systemctl restart cronmanager-agent${NC}"
echo -e "    ${CYAN}${_ssh_pfx}journalctl -u cronmanager-agent -f${NC}"
blank
echo -e "  ${BOLD}Docker stack (from ${COMPOSE_DIR}):${NC}"
echo -e "    ${CYAN}${_ssh_pfx}sh -c 'cd ${COMPOSE_DIR} && docker compose up -d'${NC}"
echo -e "    ${CYAN}${_ssh_pfx}sh -c 'cd ${COMPOSE_DIR} && docker compose logs -f'${NC}"
blank
echo -e "  ${BOLD}Agent health check:${NC}"
echo -e "    ${CYAN}${_ssh_pfx}curl http://127.0.0.1:${AGENT_PORT}/health${NC}"
blank
echo -e "  ${BOLD}Web UI:${NC}"
echo -e "    ${CYAN}http://${_host_ip}:${WEB_PORT}/${NC}"
echo -e "    Open this URL to complete setup and create the admin account."
blank
echo -e "  ${BOLD}HMAC secret${NC}  (store securely – needed if you reinstall):"
echo -e "    ${YELLOW}${HMAC_SECRET}${NC}"
blank
echo -e "  ${BOLD}Next steps:${NC}"
echo -e "    1. Open the web UI and create your admin account."
[[ "$OIDC_ENABLED"  == "yes" ]] && \
    echo -e "    2. Verify OIDC login at ${CYAN}${OIDC_REDIRECT_URI}${NC}."
[[ "$MAIL_ENABLED"  == "yes" ]] && \
    echo -e "    3. Test email by creating a job with 'Notify on failure' enabled."
echo -e "    4. Add your first cron job via the web UI."
blank
