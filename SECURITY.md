# Cronmanager – Security Analysis

This document describes the security analysis performed on Cronmanager, mapping findings to
OWASP Top 10 (2021) and other industry standards. It covers the controls already implemented
and the residual risks with recommended next steps.

For the overall security architecture see [TECHNICAL.md – Security Model](TECHNICAL.md#security-model).

---

## Table of Contents

1. [Scope](#scope)
2. [Threat Model](#threat-model)
3. [OWASP Top 10 (2021) Mapping](#owasp-top-10-2021-mapping)
4. [Findings and Status](#findings-and-status)
   - [A01 – Broken Access Control](#a01--broken-access-control)
   - [A02 – Cryptographic Failures](#a02--cryptographic-failures)
   - [A03 – Injection](#a03--injection)
   - [A05 – Security Misconfiguration](#a05--security-misconfiguration)
   - [A06 – Vulnerable and Outdated Components](#a06--vulnerable-and-outdated-components)
   - [A07 – Identification and Authentication Failures](#a07--identification-and-authentication-failures)
   - [A09 – Security Logging and Monitoring Failures](#a09--security-logging-and-monitoring-failures)
5. [Additional Controls Implemented](#additional-controls-implemented)
6. [Residual Risks and Recommendations](#residual-risks-and-recommendations)
7. [Security Checklist](#security-checklist)

---

## Scope

The analysis covers:

| Layer | Scope |
|---|---|
| **Web UI** | PHP source, templates, session management, routing (`/opt/dev/cronmanager/web/`) |
| **Host Agent** | PHP source, HMAC validation, crontab operations (`/opt/dev/cronmanager/agent/`) |
| **Database** | Schema, query patterns, credential handling |
| **cron-wrapper.sh** | Bash script injected into crontab entries |
| **Deployment** | `deploy.sh`, `deploy.env`, credential files |
| **Docker / host** | Container configuration, network exposure |

---

## Threat Model

### Assets

| Asset | Confidentiality | Integrity | Availability |
|---|---|---|---|
| User credentials (hashed) | High | High | Medium |
| Crontab entries (system commands) | Medium | **Critical** | High |
| Execution history / output | Medium | Medium | Medium |
| HMAC shared secret | **Critical** | High | Medium |
| Database credentials | **Critical** | High | Medium |

### Threat actors

| Actor | Vector |
|---|---|
| Unauthenticated external attacker | Public-facing web UI |
| Authenticated low-privilege user | In-app privilege escalation |
| Attacker with local host access | Reads config files / memory |
| Malicious cron payload author | Injects commands through job editing |

---

## OWASP Top 10 (2021) Mapping

| OWASP ID | Category | Status |
|---|---|---|
| A01 | Broken Access Control | **Mitigated** |
| A02 | Cryptographic Failures | **Mitigated** |
| A03 | Injection (SQL, OS) | **Mitigated** |
| A04 | Insecure Design | Low risk – design reviewed |
| A05 | Security Misconfiguration | **Mitigated** |
| A06 | Vulnerable and Outdated Components | Ongoing – monitor |
| A07 | Identification and Authentication Failures | **Mitigated** |
| A08 | Software and Data Integrity Failures | Low risk |
| A09 | Security Logging and Monitoring Failures | **Mitigated** |
| A10 | Server-Side Request Forgery | Not applicable |

---

## Findings and Status

### A01 – Broken Access Control

#### Finding 1.1 – Role enforcement on all protected routes ✅ Fixed

**Risk**: An authenticated viewer could access admin-only pages (user management, cron create/edit/delete).

**Root cause**: Routes are registered with an optional minimum-role parameter. Before the fix,
templates checked `$user['role'] === 'admin'` using a template variable that could be overridden
by sub-templates (e.g., `detail.php` sets `$user` as a string for an unrelated purpose, leaking
into the layout scope).

**Fix applied** (`web/templates/layout.php`):
- All admin navigation visibility checks replaced with `SessionManager::hasRole('admin')`, which reads
  directly from the authenticated session rather than a possibly polluted template variable.
- The Router (`Router.php`) enforces the role on every request before dispatching.

**Verification**: Every protected route calls `SessionManager::hasRole($requiredRole)` server-side.
Template role checks are defence-in-depth only (UI hiding, not access control).

---

#### Finding 1.2 – Self-modification protection ✅ Already implemented

**Risk**: An admin user could accidentally (or maliciously) demote or delete their own account,
leaving the system without an administrator.

**Fix**: `UserController` rejects requests where the target user ID equals the currently logged-in
user ID with HTTP 403.

---

#### Finding 1.3 – CSRF on all state-changing requests ✅ Fixed

**Risk**: A malicious page could silently trigger cron creation, deletion, or user role changes on
behalf of an authenticated user (Cross-Site Request Forgery).

**Fix applied**:
- `SessionManager::getCsrfToken()` generates a 64-character cryptographically random hex token
  (`bin2hex(random_bytes(32))`) stored in the session on first access.
- `SessionManager::validateCsrfToken(string $submitted): bool` compares with `hash_equals()` to
  prevent timing attacks.
- `Router::dispatch()` validates `$_POST['_csrf']` for all `POST`, `PUT`, `PATCH`, and `DELETE`
  requests on protected routes.
- All POST forms in templates include `<input type="hidden" name="_csrf" value="<?= $csrf_token ?>">`.
- `BaseController::render()` automatically injects `csrf_token` into every template data array.

**Note on public POST routes**: The login and setup forms (`POST /login`, `POST /setup`) are not
behind the router's CSRF check because they cannot rely on a pre-authenticated session token.
Their CSRF risk is lower: a forged login at worst logs the attacker in as themselves, and setup is
a one-time event. Nonetheless, the forms include the CSRF field for consistency and defence-in-depth.

---

### A02 – Cryptographic Failures

#### Finding 2.1 – Password hashing ✅ Already implemented

**Risk**: Passwords stored in recoverable form.

**Implementation**: `LocalAuthProvider` uses PHP's `password_hash()` with `PASSWORD_BCRYPT` (or
`PASSWORD_ARGON2ID` where available, depending on configuration). `password_verify()` is used for
all comparisons. Plaintext passwords are never written to logs or databases.

---

#### Finding 2.2 – HMAC secret strength ✅ Fixed

**Risk**: Weak or default HMAC secrets allow request forgery between the web container and the
host agent.

**Fix applied** (`web/index.php`, `agent/agent.php`):
- At startup, the application checks the configured `hmac_secret`.
- If it is empty or equals the example placeholder (`change-me-to-a-secure-random-string`), a
  `CRITICAL` log entry is emitted.
- If it is shorter than 32 characters, a `WARNING` is emitted.
- The recommended generation command (`openssl rand -hex 32`) is included in both the log message
  and the configuration example.

---

#### Finding 2.3 – OIDC PKCE ✅ Already implemented

**Risk**: Authorization code interception.

**Implementation**: `OidcAuthProvider` generates a cryptographically random PKCE `code_verifier`
(64 bytes, base64url-encoded), computes `code_challenge = BASE64URL(SHA256(code_verifier))`, and
stores both verifier and `state` in the PHP session. Both are verified before the token exchange.

---

#### Finding 2.4 – Transport encryption

**Risk**: HTTP between components exposes credentials or commands.

**Status / recommendation**:
- Browser → Web UI: HTTPS should be configured at the reverse-proxy / Docker ingress level.
  The application itself does not handle TLS termination.
- Web container → Host agent: Uses HTTP. In host-agent mode the web container reaches the agent via `host.docker.internal` (Docker bridge to host loopback, not externally routable). In docker mode traffic stays on the private `cronmanager-internal` Docker network and never leaves the host. In both cases the HMAC signature ensures integrity and authenticity even without TLS on this hop.
- **Recommendation**: Add TLS to the host agent listener for defence-in-depth if the Docker network
  is shared with untrusted containers.

---

### A03 – Injection

#### Finding 3.1 – SQL injection ✅ Already implemented

**Risk**: Attacker-controlled data interpolated into SQL queries.

**Implementation**: Every database interaction uses PDO prepared statements with named placeholders.
No raw string concatenation occurs in SQL queries anywhere in the codebase.

---

#### Finding 3.2 – OS command injection ✅ Risk accepted (by design)

**Risk**: Cron job `command` fields are written verbatim into system crontabs.

**Context**: Cronmanager's purpose is to manage arbitrary system commands. Only authenticated
admin users can create or edit jobs. The host agent writes commands to crontab files; it does
not `eval` or `exec` them — the cron daemon interprets them.

**Controls in place**:
- Only admin-role users can create or modify cron job commands (role enforcement, A01).
- The HMAC-secured channel prevents un-authenticated writes to the agent.
- `cron-wrapper.sh` runs as the crontab owner (not root by default).

**Recommendation**: Consider a UI-level allowlist or regex validation on the command field to
prevent accidental typos with dangerous shell constructs (e.g., `; rm -rf /`).

---

#### Finding 3.3 – XSS (reflected / stored) ✅ Fixed

**Risk**: Stored cron job descriptions or user input rendered as raw HTML.

**Fix**: All dynamic output in PHP templates passes through:
```php
htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
```
Verified across all template files. No `echo $variable` without escaping exists.

---

### A05 – Security Misconfiguration

#### Finding 5.1 – HTTP security response headers ✅ Fixed

**Risk**: Missing security headers allow clickjacking, MIME sniffing, and XSS escalation.

**Fix applied** (`web/index.php`, sent before any output):

| Header | Value | Protection |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | Prevents MIME-type sniffing |
| `X-Frame-Options` | `DENY` | Prevents clickjacking via `<iframe>` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Limits referrer leakage |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` | Disables unused browser APIs |
| `Content-Security-Policy` | See below | Defence-in-depth against XSS |

**CSP value**:
```
default-src 'self';
script-src 'self' 'unsafe-inline';
style-src 'self' 'unsafe-inline';
img-src 'self' data:;
font-src 'self';
connect-src 'self';
frame-ancestors 'none'
```

**Known limitation**: `'unsafe-inline'` for scripts is required because the Tailwind dark-mode
detection snippet and `tailwind.config` block are inline `<script>` elements in `layout.php`.
For a stricter CSP, these should be extracted to external files and a `nonce`-based CSP applied.
This is a recommended future improvement but does not represent a critical risk in a homelab
deployment model.

---

#### Finding 5.2 – Default credentials / example secrets ✅ Fixed

See Finding 2.2 (startup HMAC secret validation).

**Additional control**: The `deploy.env.example` and `config.json.example` files explicitly
contain placeholder values. The deployment script prints a reminder to update secrets after a
full deployment.

---

#### Finding 5.3 – Debug information exposure ✅ Already implemented

**Risk**: Stack traces or internal paths exposed in HTTP responses.

**Implementation**: The top-level catch block in `index.php` logs full exception details via
Monolog but returns only a generic "500 – Internal Server Error" page to the browser.
PHP error display (`display_errors`) must be set to `Off` in `php.ini` for production — this
is a deployment configuration requirement, not enforced by the application code.

---

### A06 – Vulnerable and Outdated Components

**Status**: Ongoing operational concern.

The application uses the following third-party libraries (via shared `/opt/phplib/vendor/`):

| Library | Version constraint | Purpose |
|---|---|---|
| `hassankhan/config` | `^2.1` | Configuration loading |
| `monolog/monolog` | `^3.6` | Logging |
| `guzzlehttp/guzzle` | `^7.8` | HTTP client (HostAgentClient) |
| `phpmailer/phpmailer` | `^6.8` | Email notifications |

**Recommendations**:
1. Run `composer audit` regularly to check for known vulnerabilities in dependencies.
2. Pin minor versions in `composer.json` and update deliberately with testing.
3. Subscribe to security advisories for the listed packages.

---

### A07 – Identification and Authentication Failures

#### Finding 7.1 – Login rate limiting ✅ Fixed

**Risk**: Brute-force or credential-stuffing attacks against the login form.

**Fix applied** (`web/src/Session/SessionManager.php`, `web/src/Controller/AuthController.php`):
- Per-IP tracking with `hash('sha256', $ip)` as the session key (no plaintext IP in session data).
- Threshold: 5 failed attempts.
- Lockout duration: 15 minutes (configurable via `RATE_LOCK_SECONDS`).
- On lockout: HTTP redirect to `/login` with a flash message showing remaining minutes.
- Rate tracking is stored in the PHP session.

**Known limitation**: Session-based rate limiting is scoped to a single PHP session. An attacker
using a fresh browser (new session) or different IPs bypasses the counter. For production hardening,
use a shared store (APCu, Redis, or a database table) keyed by IP address.

---

#### Finding 7.2 – Username enumeration ✅ Fixed

**Risk**: Timing or error message differences reveal whether a username exists.

**Fix applied**:
- Failed login always returns the same flash error key (`login_error_credentials`) regardless
  of whether the username was found or the password was wrong.
- Failed login logs `hash('sha256', $username)` instead of the plaintext username, so log
  analysis cannot enumerate valid usernames from the log file.

---

#### Finding 7.3 – Session fixation ✅ Already implemented

**Risk**: An attacker pre-sets a known session ID before login.

**Implementation**: `SessionManager::login()` calls `session_regenerate_id(true)` immediately
after successful authentication, invalidating the old session and preventing fixation.

---

#### Finding 7.4 – Session configuration ✅ Already implemented

**Implementation**: `SessionManager::start()` sets the following before `session_start()`:

| INI setting | Value | Reason |
|---|---|---|
| `session.cookie_httponly` | `1` | Prevents JavaScript access to the session cookie |
| `session.cookie_samesite` | `Lax` | Mitigates CSRF from cross-origin requests |
| `session.cookie_secure` | `1` (when HTTPS) | Prevents transmission over plain HTTP |
| `session.use_strict_mode` | `1` | Rejects externally supplied session IDs |

---

### A09 – Security Logging and Monitoring Failures

#### Finding 9.1 – Security event logging ✅ Already implemented / improved

**Events logged via Monolog**:

| Event | Level | Details logged |
|---|---|---|
| Successful login | `INFO` | username, IP |
| Failed login | `INFO` | `sha256(username)`, IP |
| Login blocked (rate limit) | `WARNING` | IP |
| Login exception | `ERROR` | exception message |
| Logout | `INFO` | username |
| CSRF validation failure | `WARNING` | method, path, IP |
| OIDC callback error | `WARNING` / `ERROR` | error code, description |
| 403 access denied | `WARNING` | method, path, username |
| Unhandled exception | `ERROR` | exception class, message, file, line, trace |
| Weak/default HMAC secret | `CRITICAL` / `WARNING` | at startup |

**Log configuration**: `RotatingFileHandler` with a configurable path (default
`/opt/cronmanager/agent/log/cronmanager-agent.log` (agent) and `/var/www/log/cronmanager-web.log` (web)) and 30-day retention. Log level is configurable (default `DEBUG`
in development, should be `WARNING` or higher in production).

---

## Additional Controls Implemented

The following controls were added that do not map directly to a single OWASP category but
represent security best practices:

| Control | Location | Description |
|---|---|---|
| Constant-time HMAC comparison | `HmacValidator::validate()` | `hash_equals()` prevents timing attacks |
| Constant-time CSRF comparison | `SessionManager::validateCsrfToken()` | `hash_equals()` prevents timing attacks |
| PKCE for OIDC | `OidcAuthProvider` | Prevents authorization code interception |
| State parameter for OIDC | `OidcAuthProvider` | Prevents CSRF on OIDC callback |
| Parameterised queries (PDO) | All DB access | Prevents SQL injection |
| `extract(EXTR_SKIP)` | Template rendering | Prevents template variable injection from data |
| Output escaping | All templates | `htmlspecialchars(ENT_QUOTES, UTF-8)` on all dynamic output |
| cron-wrapper HMAC | `cron-wrapper.sh` | Execution reports are signed; the agent validates them |

---

## Residual Risks and Recommendations

The following items are known limitations or recommended improvements that were not
addressed in this iteration. They are ordered by priority.

### High priority

| # | Risk | Recommendation |
|---|---|---|
| R1 | Session-scoped rate limiting can be bypassed by new sessions | Replace with shared-store (APCu / Redis / DB) rate limiting keyed by IP |
| R2 | `'unsafe-inline'` in CSP | Extract inline scripts to external files; use nonce-based CSP |
| R3 | HTTP (not HTTPS) on web UI by default | Configure TLS at the ingress/proxy level; enforce `Strict-Transport-Security` |

### Medium priority

| # | Risk | Recommendation |
|---|---|---|
| R4 | No CSRF protection on `POST /login` | Add CSRF validation to public POST routes as additional defence-in-depth |
| R5 | Cron command field allows arbitrary shell syntax | Add UI-level soft validation (warn on dangerous patterns like `;`, `&&`, `|`) |
| R6 | `display_errors` must be disabled in PHP runtime | Document as explicit deployment prerequisite; consider adding a startup check |

### Low priority

| # | Risk | Recommendation |
|---|---|---|
| R7 | No HTTP Strict Transport Security header | Add `Strict-Transport-Security: max-age=63072000` when HTTPS is confirmed |
| R8 | Composer dependency versions unpinned | Pin exact versions with `composer.lock` |
| R9 | Log file permissions | Ensure log directory is not world-readable; logs contain IP addresses (personal data) |
| R10 | No account lockout for OIDC path | OIDC brute-force is the IdP's responsibility; document this clearly |

---

## Security Checklist

Use this checklist when deploying a new instance or reviewing after changes.

### Pre-deployment

- [ ] Generated a fresh HMAC secret (`openssl rand -hex 32`) in agent and web config
- [ ] Changed database passwords from example values
- [ ] Set `DEPLOY_TYPE` and SSH host in `deploy.env`
- [ ] Verified `php.ini` has `display_errors = Off` and `expose_php = Off`
- [ ] TLS/HTTPS configured at the ingress level (reverse proxy, nginx, Caddy, etc.)

### Post-deployment

- [ ] Confirm first-run setup created the admin account and `/setup` redirects to `/login`
- [ ] Confirm Monolog is writing to the configured log path
- [ ] Confirm log file is not world-readable
- [ ] Test login rate limiting: 6 consecutive failed logins should lock the IP
- [ ] Test CSRF: submitting a form without `_csrf` field returns 403
- [ ] Test role enforcement: viewer account cannot access `/users`, `/crons/new`, etc.
- [ ] Run `composer audit` to check for known CVEs in dependencies

### Periodic

- [ ] Review log files for unusual patterns (mass failed logins, CSRF failures, 403s)
- [ ] Update Composer dependencies and re-run `composer audit`
- [ ] Rotate HMAC secret (update both agent config and web config; restart agent service)
- [ ] Review user accounts and remove stale accounts

---

*Analysis performed: 2026-03-18*
*Analyst: Christian Schulz <technik@meinetechnikwelt.rocks>*
