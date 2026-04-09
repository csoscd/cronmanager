<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Session Manager
 *
 * Static helper class that wraps PHP's native session functions.
 * Provides typed accessors for the authenticated user and arbitrary
 * session values, and enforces secure session cookie settings.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Session;

use Noodlehaus\Config;
use RuntimeException;

/**
 * Class SessionManager
 *
 * All methods are static because PHP session state is global.  The class
 * acts as a typed, testable facade over $_SESSION and the session_*() family
 * of functions.
 *
 * Role hierarchy (least → most privileged):
 *   view  – read-only access to all pages
 *   admin – all permissions, including create / edit / delete
 */
class SessionManager
{
    // -------------------------------------------------------------------------
    // Session keys (constants to avoid typo-driven bugs)
    // -------------------------------------------------------------------------

    /** @var string Session key for the authenticated user array */
    private const KEY_USER = '_cronmanager_user';

    /** @var string Session key for the per-session CSRF token */
    private const KEY_CSRF = '_cronmanager_csrf';

    /**
     * Session key for login rate-limit data.
     * Stores an array indexed by hashed IP addresses.
     *
     * NOTE: This is session-scoped rate limiting.  It prevents brute-force
     * attacks that reuse the same session cookie (the most common automated
     * attack pattern).  For full IP-based protection across sessions a shared
     * persistent store (APCu, Redis, or a database table) is required.
     */
    private const KEY_RATE = '_cronmanager_rate';

    /** @var string Session key for the last-activity timestamp (idle timeout). */
    private const KEY_LAST_ACTIVITY = '_cronmanager_last_activity';

    /** @var int Maximum failed login attempts before the IP is locked */
    private const RATE_MAX_ATTEMPTS = 5;

    /** @var int Lock duration in seconds after exceeding the attempt limit (15 min) */
    private const RATE_LOCK_SECONDS = 900;

    // -------------------------------------------------------------------------
    // Session initialisation
    // -------------------------------------------------------------------------

    /**
     * Start the PHP session with secure settings read from configuration.
     *
     * Must be called once per request, before any output is sent.
     *
     * Configuration keys used:
     *   session.name         – session cookie name (default: cronmanager_sess)
     *   session.lifetime     – cookie max-age in seconds (default: 3600)
     *   session.idle_timeout – server-side idle expiry in seconds
     *                          (default: same as lifetime)
     *
     * The PHP ini session.gc_maxlifetime is set to idle_timeout so that server
     * data and cookie lifetime stay in sync.  An additional last-activity
     * timestamp written to the session is checked on every request so that
     * long-lived cookies are invalidated server-side when the user has been
     * idle for longer than idle_timeout seconds.
     *
     * @param Config $config Noodlehaus configuration instance.
     *
     * @throws RuntimeException When the session cannot be started.
     *
     * @return void
     */
    public static function start(Config $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Already started (e.g. in unit tests or repeated calls)
            return;
        }

        $name        = (string) $config->get('session.name',         'cronmanager_sess');
        $lifetime    = (int)    $config->get('session.lifetime',     3600);
        $idleTimeout = (int)    $config->get('session.idle_timeout', $lifetime);

        // Align PHP's server-side GC window with the configured idle timeout
        ini_set('session.gc_maxlifetime', (string) $idleTimeout);

        session_name($name);

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Failed to start PHP session.');
        }

        // Server-side idle-timeout check: if the session contains a previous
        // activity timestamp and the idle window has elapsed, destroy the session
        // so that the user is treated as unauthenticated on this request.
        if (isset($_SESSION[self::KEY_LAST_ACTIVITY])) {
            if ((time() - (int) $_SESSION[self::KEY_LAST_ACTIVITY]) > $idleTimeout) {
                // Session has gone idle – wipe it so isAuthenticated() returns false
                session_unset();
                session_destroy();
                session_start();
            }
        }

        // Refresh the last-activity timestamp on every request
        $_SESSION[self::KEY_LAST_ACTIVITY] = time();
    }

    // -------------------------------------------------------------------------
    // Authentication state
    // -------------------------------------------------------------------------

    /**
     * Store an authenticated user in the session.
     *
     * Regenerates the session ID to prevent session fixation attacks.
     *
     * Expected $user structure:
     *   ['id' => int, 'username' => string, 'role' => 'view'|'admin']
     *
     * @param array $user Associative user data array.
     *
     * @return void
     */
    public static function login(array $user): void
    {
        // Regenerate session ID on privilege escalation
        session_regenerate_id(delete_old_session: true);
        $_SESSION[self::KEY_USER] = $user;
    }

    /**
     * Destroy the current session and clear the session cookie.
     *
     * @return void
     */
    public static function logout(): void
    {
        $_SESSION = [];

        // Remove the session cookie from the browser
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly'],
            );
        }

        session_destroy();
    }

    /**
     * Return true when a user is currently authenticated.
     *
     * @return bool
     */
    public static function isAuthenticated(): bool
    {
        return isset($_SESSION[self::KEY_USER]) && is_array($_SESSION[self::KEY_USER]);
    }

    // -------------------------------------------------------------------------
    // User accessors
    // -------------------------------------------------------------------------

    /**
     * Return the full user array stored in the session, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function getUser(): ?array
    {
        return $_SESSION[self::KEY_USER] ?? null;
    }

    /**
     * Return the authenticated user's primary key, or null.
     *
     * @return int|null
     */
    public static function getUserId(): ?int
    {
        $user = self::getUser();
        return isset($user['id']) ? (int) $user['id'] : null;
    }

    /**
     * Return the authenticated user's username, or null.
     *
     * @return string|null
     */
    public static function getUsername(): ?string
    {
        $user = self::getUser();
        return isset($user['username']) ? (string) $user['username'] : null;
    }

    /**
     * Return the authenticated user's role ('view' or 'admin'), or null.
     *
     * @return string|null
     */
    public static function getRole(): ?string
    {
        $user = self::getUser();
        return isset($user['role']) ? (string) $user['role'] : null;
    }

    /**
     * Check whether the authenticated user holds at least the given role.
     *
     * Role hierarchy:
     *   - hasRole('view')  → true for both 'view' and 'admin'
     *   - hasRole('admin') → true only for 'admin'
     *
     * @param string $role Required role ('view' or 'admin').
     *
     * @return bool
     */
    public static function hasRole(string $role): bool
    {
        if (!self::isAuthenticated()) {
            return false;
        }

        $userRole = self::getRole();

        return match ($role) {
            'view'  => in_array($userRole, ['view', 'admin'], strict: true),
            'admin' => $userRole === 'admin',
            default => false,
        };
    }

    // -------------------------------------------------------------------------
    // Generic session value store
    // -------------------------------------------------------------------------

    /**
     * Store an arbitrary value in the session under a namespaced key.
     *
     * @param string $key   Session key.
     * @param mixed  $value Value to store (must be serialisable).
     *
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Retrieve an arbitrary value from the session.
     *
     * @param string $key     Session key.
     * @param mixed  $default Value returned when the key is absent.
     *
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Remove a key from the session.
     *
     * @param string $key Session key to remove.
     *
     * @return void
     */
    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Read a value from the session and immediately remove it (flash pattern).
     *
     * @param string $key     Session key.
     * @param mixed  $default Value returned when the key is absent.
     *
     * @return mixed
     */
    public static function flash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    // -------------------------------------------------------------------------
    // CSRF protection
    // -------------------------------------------------------------------------

    /**
     * Return the per-session CSRF token, generating one if it does not exist.
     *
     * The token is a 64-character hex string generated with random_bytes().
     * It is tied to the session lifetime and rotated on every login via
     * session_regenerate_id().
     *
     * @return string 64-character hex CSRF token.
     */
    public static function getCsrfToken(): string
    {
        if (empty($_SESSION[self::KEY_CSRF]) || !is_string($_SESSION[self::KEY_CSRF])) {
            $_SESSION[self::KEY_CSRF] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::KEY_CSRF];
    }

    /**
     * Validate a submitted CSRF token against the session token.
     *
     * Uses hash_equals() for constant-time comparison to prevent timing attacks.
     *
     * @param string $submittedToken Token value from the POST body (_csrf field).
     *
     * @return bool True when the token matches, false otherwise.
     */
    public static function validateCsrfToken(string $submittedToken): bool
    {
        $stored = $_SESSION[self::KEY_CSRF] ?? '';

        if ($stored === '' || $submittedToken === '') {
            return false;
        }

        return hash_equals($stored, $submittedToken);
    }

    // -------------------------------------------------------------------------
    // Login rate limiting (session-scoped, per hashed IP)
    // -------------------------------------------------------------------------

    /**
     * Check whether a login attempt from the given IP is currently allowed.
     *
     * Returns false if the IP has exceeded RATE_MAX_ATTEMPTS failed logins within
     * the sliding window and the lock period has not yet expired.
     *
     * @param string $ip Remote IP address of the client.
     *
     * @return bool True when the attempt is allowed, false when locked.
     */
    public static function isLoginAllowed(string $ip): bool
    {
        $data = self::getRateEntry($ip);

        if ($data === null) {
            return true;
        }

        if (isset($data['locked_until']) && $data['locked_until'] > time()) {
            return false;
        }

        return true;
    }

    /**
     * Return the number of seconds remaining in the current lockout, or 0.
     *
     * @param string $ip Remote IP address of the client.
     *
     * @return int Seconds until the lock expires; 0 when not locked.
     */
    public static function getLockoutRemaining(string $ip): int
    {
        $data = self::getRateEntry($ip);

        if ($data === null || !isset($data['locked_until'])) {
            return 0;
        }

        $remaining = (int) $data['locked_until'] - time();

        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Record a failed login attempt for the given IP.
     *
     * Increments the failure counter and sets a lock when RATE_MAX_ATTEMPTS is
     * reached.  The sliding window resets automatically once RATE_LOCK_SECONDS
     * have elapsed since the first attempt.
     *
     * @param string $ip Remote IP address of the client.
     *
     * @return void
     */
    public static function recordLoginFailure(string $ip): void
    {
        $data = self::getRateEntry($ip);
        $now  = time();

        if ($data === null || ($now - ($data['first_at'] ?? 0)) > self::RATE_LOCK_SECONDS) {
            // Start a fresh window
            $data = ['attempts' => 1, 'first_at' => $now];
        } else {
            $data['attempts'] = ($data['attempts'] ?? 0) + 1;

            if ($data['attempts'] >= self::RATE_MAX_ATTEMPTS) {
                $data['locked_until'] = $now + self::RATE_LOCK_SECONDS;
            }
        }

        self::setRateEntry($ip, $data);
    }

    /**
     * Clear the failed-login counter for the given IP (called on successful login).
     *
     * @param string $ip Remote IP address of the client.
     *
     * @return void
     */
    public static function clearLoginFailures(string $ip): void
    {
        $all = $_SESSION[self::KEY_RATE] ?? [];
        unset($all[hash('sha256', $ip)]);
        $_SESSION[self::KEY_RATE] = $all;
    }

    // -------------------------------------------------------------------------
    // Private rate-limit helpers
    // -------------------------------------------------------------------------

    /**
     * Retrieve the rate-limit entry for the given IP from the session.
     *
     * @param string $ip Remote IP address.
     *
     * @return array<string,mixed>|null Entry array, or null when absent.
     */
    private static function getRateEntry(string $ip): ?array
    {
        $all = $_SESSION[self::KEY_RATE] ?? [];
        $key = hash('sha256', $ip);

        return isset($all[$key]) && is_array($all[$key]) ? $all[$key] : null;
    }

    /**
     * Persist the rate-limit entry for the given IP in the session.
     *
     * @param string               $ip   Remote IP address.
     * @param array<string,mixed>  $data Rate-limit data to store.
     *
     * @return void
     */
    private static function setRateEntry(string $ip, array $data): void
    {
        $all = $_SESSION[self::KEY_RATE] ?? [];
        $all[hash('sha256', $ip)] = $data;
        $_SESSION[self::KEY_RATE] = $all;
    }
}
