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
# Dependencies (on the agent host):
#   - bash 4+
#   - curl
#   - openssl  (HMAC-SHA256 signature computation)
#   - php 8.4  (JSON building/parsing; always available on the host)
#
# Dependencies (on remote SSH target hosts):
#   - POSIX sh  (any shell: bash, dash, busybox ash, etc.)
#   - kill      (POSIX – available on all Linux distributions)
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

readonly CONFIG_FILE="/opt/cronmanager/agent/config/config.json"
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

# Optional third argument: "--once" flag.
# When present the wrapper was invoked from a once-only crontab entry created
# by the Run Now feature.  After execution it calls the cleanup endpoint to
# remove that temporary entry from the crontab.
RUN_ONCE="${3:-}"

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

# 409 Conflict = singleton job already running → skip this execution cleanly
if [[ "${_HTTP_CODE}" == "409" ]]; then
    log_info "Job ${JOB_ID}: skipped – singleton mode active and a previous instance is still running"
    exit 0
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
    #
    # The remote shell writes its own PID to /tmp/.cmgr_<execution_id> before
    # exec-ing sh -s, enabling the Kill Running Jobs feature.
    # The command is passed via stdin (here-string) rather than being embedded
    # in the shell string.  This avoids ALL quoting issues: commands with
    # single quotes, double quotes, &&, ||, pipes, backticks, etc. all work
    # correctly because the command is treated as data, not as shell syntax
    # that must survive multiple levels of quoting.
    # The PID file path is reported to the agent so the kill endpoint knows
    # where to find it on the remote host.
    # -------------------------------------------------------------------------
    log_info "Job ${JOB_ID}: remote execution via SSH host '${TARGET}'"

    # Construct the remote pid-file path (based on execution_id so it is unique)
    REMOTE_PID_FILE="/tmp/.cmgr_${EXECUTION_ID}"

    # The remote sh writes its PID, then exec's sh -s which reads and runs
    # the command from stdin (provided via the here-string below).
    # exec preserves the PID so the kill endpoint can target the right process.
    # Using POSIX $$ so this works on Alpine/busybox/dash/bash.
    # shellcheck disable=SC2029
    ssh -o BatchMode=yes -o ConnectTimeout=30 "${TARGET}" -- \
        "sh -c 'echo \$\$ > ${REMOTE_PID_FILE}; exec sh -s'" \
        <<< "${COMMAND}" \
        > "${TMP_OUTPUT}" 2>&1 &
    SSH_BG_PID=$!

    # Report the pid_file path to the agent so the kill endpoint can find it
    if [[ "$EXECUTION_ID" != "0" ]]; then
        PID_BODY="$(php -r "
            echo json_encode([
                'pid_file' => '${REMOTE_PID_FILE}',
            ], JSON_UNESCAPED_UNICODE);
        ")"
        agent_request "POST" "/execution/${EXECUTION_ID}/pid" "${PID_BODY}" >/dev/null 2>&1 || true
    fi

    wait "${SSH_BG_PID}"
    JOB_EXIT_CODE=$?

    # Remove the remote pid file on clean finish (best-effort; kill endpoint also removes it)
    if [[ "$EXECUTION_ID" != "0" ]]; then
        ssh -o BatchMode=yes -o ConnectTimeout=10 "${TARGET}" -- \
            "rm -f ${REMOTE_PID_FILE}" >/dev/null 2>&1 || true
    fi
else
    # -------------------------------------------------------------------------
    # Local execution
    # The command is written to a temporary script file and executed with a
    # fresh bash invocation.  This avoids two problems:
    #   1. Quoting: commands with single quotes, &&, ||, pipes, etc. all work
    #      correctly because the command is stored as-is in a file rather than
    #      being embedded in a shell string argument.
    #   2. SHELLOPTS inheritance: bash -c inherits set -uo pipefail from this
    #      wrapper via the exported SHELLOPTS variable, which can interfere with
    #      the job command.  A fresh bash invoked as a script file starts with
    #      default options.
    # The temp file is removed after the job finishes (bash has it open until
    # then via its file descriptor, so unlinking is safe on Linux).
    # -------------------------------------------------------------------------
    log_info "Job ${JOB_ID}: local execution"

    LOCAL_CMD_FILE="$(mktemp --suffix=.sh)"
    printf '%s\n' "${COMMAND}" > "${LOCAL_CMD_FILE}"

    # setsid creates a new session so bash becomes its own process-group leader
    # (PGID == PID).  This is required for `kill -TERM -$PID` in the agent's
    # kill / check-limits logic to reach bash *and* all its children (e.g. sleep).
    setsid bash "${LOCAL_CMD_FILE}" > "${TMP_OUTPUT}" 2>&1 &
    LOCAL_JOB_PID=$!

    # Report the PID to the agent so the kill endpoint can kill this process
    if [[ "$EXECUTION_ID" != "0" ]]; then
        PID_BODY="$(php -r "
            echo json_encode([
                'pid' => (int)'${LOCAL_JOB_PID}',
            ], JSON_UNESCAPED_UNICODE);
        ")"
        agent_request "POST" "/execution/${EXECUTION_ID}/pid" "${PID_BODY}" >/dev/null 2>&1 || true
    fi

    wait "${LOCAL_JOB_PID}"
    JOB_EXIT_CODE=$?
    rm -f "${LOCAL_CMD_FILE}"
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
# Step 5 (once-only): Remove the temporary crontab entry
# =============================================================================
#
# When invoked with "--once" the job was scheduled via the Run Now feature.
# Notify the agent cleanup endpoint so the temporary crontab entry is removed
# immediately.  This is best-effort: if it fails the entry will fire at most
# once per year due to its full-date schedule.

if [[ "${RUN_ONCE}" == "--once" ]]; then
    log_info "Job ${JOB_ID}: removing once-only crontab entry (target=${TARGET})"

    CLEANUP_PATH="/crons/${JOB_ID}/execute/cleanup"
    CLEANUP_BODY="$(php -r "
        echo json_encode(['target' => '${TARGET}'], JSON_UNESCAPED_UNICODE);
    ")"

    if agent_request "POST" "${CLEANUP_PATH}" "${CLEANUP_BODY}" >/dev/null; then
        if [[ "${_HTTP_CODE}" -ge 200 && "${_HTTP_CODE}" -lt 300 ]]; then
            log_info "Job ${JOB_ID}: once-entry removed from crontab (http_code=${_HTTP_CODE})"
        else
            log_warn "Job ${JOB_ID}: cleanup returned HTTP ${_HTTP_CODE} – once-entry may remain in crontab (harmless, expires next year)"
        fi
    else
        log_warn "Job ${JOB_ID}: could not reach agent for cleanup – once-entry may remain in crontab (harmless, expires next year)"
    fi
fi

# =============================================================================
# Exit with the original job exit code
# =============================================================================

exit "${JOB_EXIT_CODE}"
