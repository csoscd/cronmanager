#!/usr/bin/env bash
# =============================================================================
# Cronmanager – cron-wrapper.sh
#
# Purpose:
#   Wraps any cron job managed by Cronmanager so that execution start/finish
#   events are reported to the Host Agent, enabling runtime tracking and
#   failure-alert notifications.
#
# Usage (crontab entry written by CrontabManager):
#   {schedule}  /opt/phpscripts/cronmanager/agent/bin/cron-wrapper.sh {job_id} {target}
#
#   target  – 'local' to execute on this host, or an SSH config host alias for
#             remote execution.  When omitted (legacy crontab entry), the target
#             is derived from the execution_mode / ssh_host fields returned by
#             the agent (backward-compatible fallback).
#
# Behaviour:
#   1. Reads config from config.json (agent base URL + HMAC secret).
#   2. Notifies the agent that the job is starting (POST /execution/start).
#   3. Fetches the command to execute (GET /crons/{job_id}).
#   4. Runs the command locally or via SSH, capturing stdout + stderr combined.
#   5. Notifies the agent that the job finished (POST /execution/finish).
#   6. Exits with the original command's exit code.
#
# Error handling:
#   - Agent communication failures are logged to stderr but NEVER prevent
#     the actual job from running.
#   - If the agent is unreachable or returns an error the job still executes.
#
# Dependencies:
#   - bash 4+
#   - curl
#   - openssl  (HMAC-SHA256 signature computation)
#   - php 8.4  (JSON building/parsing; always available on the host)
#
# Make executable: chmod +x /opt/phpscripts/cronmanager/agent/bin/cron-wrapper.sh
#
# @author  Christian Schulz <technik@meinetechnikwelt.rocks>
# @license GNU General Public License version 3 or later
# =============================================================================

set -uo pipefail

# =============================================================================
# Constants
# =============================================================================

readonly CONFIG_FILE="/opt/phpscripts/cronmanager/agent/config/config.json"
readonly MAX_OUTPUT_BYTES=50000   # Truncate captured output to this many bytes

# =============================================================================
# Logging helpers (all output goes to stderr so it appears in the cron mail)
# =============================================================================

log_info() {
    echo "[cronmanager-wrapper] [INFO]  $(date -Iseconds) $*" >&2
}

log_warn() {
    echo "[cronmanager-wrapper] [WARN]  $(date -Iseconds) $*" >&2
}

log_error() {
    echo "[cronmanager-wrapper] [ERROR] $(date -Iseconds) $*" >&2
}

# =============================================================================
# Argument validation
# =============================================================================

if [[ $# -lt 1 || -z "${1:-}" ]]; then
    log_error "Usage: $0 <job_id> [target]"
    log_error "Missing required argument: job_id"
    exit 1
fi

JOB_ID="$1"

# job_id must be a positive integer
if ! [[ "$JOB_ID" =~ ^[1-9][0-9]*$ ]]; then
    log_error "job_id must be a positive integer, got: '${JOB_ID}'"
    exit 1
fi

# Optional second argument: execution target.
# 'local' = run on this host; any other value = SSH config host alias.
# When absent, the target is derived from the agent response (backward compat).
TARGET="${2:-}"

# =============================================================================
# Read configuration via PHP JSON parser
# =============================================================================

if [[ ! -r "$CONFIG_FILE" ]]; then
    log_error "Config file not readable: ${CONFIG_FILE}"
    exit 1
fi

# Reads a dot-notation key from the JSON config file.
# Usage: read_config "agent.port" "8865"
read_config() {
    local key="$1"
    local default="${2:-}"
    php -r "
        \$cfg = json_decode(file_get_contents('${CONFIG_FILE}'), true);
        if (!\$cfg) { echo '${default}'; exit; }
        \$parts = explode('.', '${key}');
        \$v = \$cfg;
        foreach (\$parts as \$p) {
            if (!array_key_exists(\$p, \$v)) { echo '${default}'; exit; }
            \$v = \$v[\$p];
        }
        echo \$v;
    " 2>/dev/null
}

BIND_ADDRESS="$(read_config 'agent.bind_address' '0.0.0.0')"
AGENT_PORT="$(read_config 'agent.port' '8865')"
HMAC_SECRET="$(read_config 'agent.hmac_secret' '')"

# Replace wildcard bind address with loopback for outgoing connections
if [[ "$BIND_ADDRESS" == "0.0.0.0" ]]; then
    BIND_ADDRESS="127.0.0.1"
fi

readonly AGENT_BASE_URL="http://${BIND_ADDRESS}:${AGENT_PORT}"

if [[ -z "$HMAC_SECRET" ]]; then
    log_warn "HMAC secret is empty – requests will be rejected by the agent"
fi

# =============================================================================
# HMAC-SHA256 signature function
# =============================================================================

# Computes the HMAC-SHA256 signature used by HmacValidator.
# Signature input: METHOD + PATH + BODY  (all concatenated, no separator).
#
# Arguments:
#   $1  HTTP method (uppercase, e.g. POST, GET)
#   $2  Request path (e.g. /execution/start)
#   $3  Request body string
#
# Outputs the lowercase hex digest to stdout.
hmac_sign() {
    local method="$1"
    local path="$2"
    local body="$3"
    printf '%s' "${method}${path}${body}" \
        | openssl dgst -sha256 -hmac "${HMAC_SECRET}" 2>/dev/null \
        | awk '{print $2}'
}

# =============================================================================
# Signed HTTP request helper
# =============================================================================

# Makes a signed HTTP request to the agent and prints the response body.
# Sets the global _HTTP_CODE variable to the numeric HTTP status code.
# Returns exit code 0 on successful curl execution, 1 on transport failure.
#
# Arguments:
#   $1  HTTP method (GET | POST)
#   $2  Request path  (e.g. /execution/start)
#   $3  Request body  (JSON string; pass empty string for GET)
#   $4  Max total time in seconds for curl (optional; default: 10)
#       Use a longer value for /execution/finish when mail notification is enabled,
#       so the agent has time to attempt SMTP delivery before curl times out.
_HTTP_CODE=0

agent_request() {
    local method="$1"
    local path="$2"
    local body="${3:-}"
    local max_time="${4:-10}"

    local signature
    signature="$(hmac_sign "${method}" "${path}" "${body}")"

    local url="${AGENT_BASE_URL}${path}"
    local tmp_file
    tmp_file="$(mktemp)"

    local curl_args=(
        --silent
        --show-error
        --max-time "${max_time}"
        --connect-timeout 5
        --request "${method}"
        --header "X-Agent-Signature: ${signature}"
        --write-out '%{http_code}'
        --output "${tmp_file}"
    )

    if [[ "$method" == "POST" ]]; then
        curl_args+=(
            --header "Content-Type: application/json"
            --data-raw "${body}"
        )
    fi

    local exit_code=0
    local http_code
    http_code="$(curl "${curl_args[@]}" "${url}" 2>/dev/null)" || exit_code=$?

    _HTTP_CODE="${http_code:-0}"

    local response_body
    response_body="$(cat "${tmp_file}")"
    rm -f "${tmp_file}"

    if [[ $exit_code -ne 0 ]]; then
        return 1
    fi

    printf '%s' "${response_body}"
    return 0
}

# =============================================================================
# JSON field extractor using PHP (handles all escaping correctly)
# =============================================================================

# Extracts a top-level string or integer field from a JSON string.
# The JSON is passed via a temp file to avoid any quoting issues.
#
# Arguments:
#   $1  JSON string
#   $2  Field name
#
# Outputs the field value to stdout (empty string if not found).
json_get() {
    local json="$1"
    local field="$2"
    local tmp_json
    tmp_json="$(mktemp)"
    printf '%s' "${json}" > "${tmp_json}"
    php -r "
        \$d = json_decode(file_get_contents('${tmp_json}'), true);
        if (isset(\$d['${field}'])) { echo \$d['${field}']; }
    " 2>/dev/null || true
    rm -f "${tmp_json}"
}

# =============================================================================
# Step 1: Notify agent – execution start
# =============================================================================

STARTED_AT="$(date -Iseconds)"
EXECUTION_ID=""

log_info "Job ${JOB_ID}: notifying agent of execution start"

# Build the POST body safely through PHP
START_BODY="$(php -r "
    echo json_encode(
        ['job_id' => (int)'${JOB_ID}', 'started_at' => '${STARTED_AT}', 'target' => '${TARGET}'],
        JSON_UNESCAPED_UNICODE
    );
")"

START_RESPONSE=""
if START_RESPONSE="$(agent_request "POST" "/execution/start" "${START_BODY}")"; then
    EXECUTION_ID="$(json_get "${START_RESPONSE}" "execution_id")"
else
    log_warn "Job ${JOB_ID}: agent unreachable for /execution/start – continuing without tracking"
fi

if [[ -z "$EXECUTION_ID" || "$EXECUTION_ID" == "0" ]]; then
    log_warn "Job ${JOB_ID}: no valid execution_id received (http_code=${_HTTP_CODE}) – continuing anyway"
    EXECUTION_ID="0"
fi

log_info "Job ${JOB_ID}: execution_id=${EXECUTION_ID}, started_at=${STARTED_AT}"

# =============================================================================
# Step 2: Fetch the job's command from the agent
# =============================================================================

COMMAND=""
CRON_PATH="/crons/${JOB_ID}"
CRON_RESPONSE=""

if CRON_RESPONSE="$(agent_request "GET" "${CRON_PATH}" "")"; then
    COMMAND="$(json_get "${CRON_RESPONSE}" "command")"
else
    log_warn "Job ${JOB_ID}: agent unreachable for GET ${CRON_PATH}"
fi

if [[ -z "$COMMAND" ]]; then
    log_error "Job ${JOB_ID}: could not retrieve command from agent (http_code=${_HTTP_CODE})"

    # Report the failure back to the agent if we have an execution_id
    if [[ "$EXECUTION_ID" != "0" ]]; then
        FINISHED_AT="$(date -Iseconds)"
        ERROR_MSG="ERROR: could not retrieve command from agent (http_code=${_HTTP_CODE})"
        FAIL_BODY="$(php -r "
            echo json_encode([
                'execution_id' => (int)'${EXECUTION_ID}',
                'job_id'       => (int)'${JOB_ID}',
                'exit_code'    => 127,
                'output'       => '${ERROR_MSG}',
                'finished_at'  => '${FINISHED_AT}',
            ], JSON_UNESCAPED_UNICODE);
        ")"
        agent_request "POST" "/execution/finish" "${FAIL_BODY}" >/dev/null 2>&1 || true
    fi

    exit 127
fi

log_info "Job ${JOB_ID}: executing command: ${COMMAND}"

# =============================================================================
# Step 3: Resolve target, then execute the job command (locally or via SSH)
# =============================================================================

# If no target was supplied on the command line (legacy crontab entry written
# before multi-target support), derive the target from the agent response so
# that existing crontab lines keep working without any changes.
if [[ -z "${TARGET}" ]]; then
    EXECUTION_MODE="$(json_get "${CRON_RESPONSE}" "execution_mode")"
    SSH_HOST_ALIAS="$(json_get "${CRON_RESPONSE}" "ssh_host")"

    if [[ "${EXECUTION_MODE}" == "remote" && -n "${SSH_HOST_ALIAS}" ]]; then
        TARGET="${SSH_HOST_ALIAS}"
    else
        TARGET="local"
    fi

    log_info "Job ${JOB_ID}: no target argument – derived target '${TARGET}' from agent response"
fi

# Capture combined stdout + stderr via a temp file so that the exit code of
# the actual command is preserved (not masked by a pipe or subshell).
TMP_OUTPUT="$(mktemp)"

if [[ "${TARGET}" != "local" ]]; then
    # -------------------------------------------------------------------------
    # Remote execution via SSH
    # TARGET is an SSH config host alias from ~/.ssh/config.
    # BatchMode=yes disables interactive prompts; ConnectTimeout limits hang.
    # -------------------------------------------------------------------------
    log_info "Job ${JOB_ID}: remote execution via SSH host '${TARGET}'"
    # shellcheck disable=SC2029
    ssh -o BatchMode=yes -o ConnectTimeout=30 "${TARGET}" -- "${COMMAND}" \
        > "${TMP_OUTPUT}" 2>&1
    JOB_EXIT_CODE=$?
else
    # -------------------------------------------------------------------------
    # Local execution (default)
    # eval is intentional: the command was entered by the operator and stored
    # in the database; it is retrieved from the authenticated, HMAC-protected
    # agent endpoint.
    # -------------------------------------------------------------------------
    log_info "Job ${JOB_ID}: local execution"
    # shellcheck disable=SC2094
    bash -c "${COMMAND}" > "${TMP_OUTPUT}" 2>&1
    JOB_EXIT_CODE=$?
fi

RAW_OUTPUT="$(cat "${TMP_OUTPUT}")"
rm -f "${TMP_OUTPUT}"

log_info "Job ${JOB_ID}: command exited with code ${JOB_EXIT_CODE}"

# =============================================================================
# Step 4: Notify agent – execution finish
# =============================================================================

FINISHED_AT="$(date -Iseconds)"

# Truncate output to MAX_OUTPUT_BYTES to avoid oversized POST request bodies
TRUNCATED_OUTPUT="${RAW_OUTPUT:0:${MAX_OUTPUT_BYTES}}"
if [[ "${#RAW_OUTPUT}" -gt "${MAX_OUTPUT_BYTES}" ]]; then
    TRUNCATED_OUTPUT="${TRUNCATED_OUTPUT}
[... output truncated to ${MAX_OUTPUT_BYTES} bytes ...]"
fi

# Build the finish JSON safely: pipe the captured output to PHP via stdin to
# avoid any quoting or escaping issues with arbitrary command output.
# JSON_INVALID_UTF8_SUBSTITUTE ensures non-UTF-8 bytes in command output never
# cause json_encode() to return false (which would silently produce an empty body).
FINISH_BODY="$(php -r "
    \$output = stream_get_contents(STDIN);
    echo json_encode([
        'execution_id' => (int)'${EXECUTION_ID}',
        'job_id'       => (int)'${JOB_ID}',
        'exit_code'    => (int)'${JOB_EXIT_CODE}',
        'output'       => \$output,
        'finished_at'  => '${FINISHED_AT}',
        'target'       => '${TARGET}',
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
" <<< "${TRUNCATED_OUTPUT}")"

if [[ -z "${FINISH_BODY}" ]]; then
    log_error "Job ${JOB_ID}: failed to build finish JSON body – skipping finish notification"
elif [[ "$EXECUTION_ID" != "0" ]]; then
    log_info "Job ${JOB_ID}: notifying agent of execution finish (execution_id=${EXECUTION_ID})"

    # Use a 60-second timeout for the finish call: the agent may need extra time
    # to attempt SMTP delivery before responding (mail.smtp_timeout defaults to 15 s).
    if ! agent_request "POST" "/execution/finish" "${FINISH_BODY}" 60 >/dev/null; then
        log_warn "Job ${JOB_ID}: could not reach agent for /execution/finish (curl transport error)"
    elif [[ "${_HTTP_CODE}" -lt 200 || "${_HTTP_CODE}" -ge 300 ]]; then
        log_warn "Job ${JOB_ID}: agent returned HTTP ${_HTTP_CODE} for /execution/finish"
    else
        log_info "Job ${JOB_ID}: execution finish recorded (http_code=${_HTTP_CODE})"
    fi
else
    log_warn "Job ${JOB_ID}: skipping /execution/finish notification (no execution_id)"
fi

# =============================================================================
# Exit with the original job exit code
# =============================================================================

exit "${JOB_EXIT_CODE}"
