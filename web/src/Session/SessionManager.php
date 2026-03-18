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

    // -------------------------------------------------------------------------
    // Session initialisation
    // -------------------------------------------------------------------------

    /**
     * Start the PHP session with secure settings read from configuration.
     *
     * Must be called once per request, before any output is sent.
     *
     * Configuration keys used:
     *   session.name     – session cookie name (default: cronmanager_sess)
     *   session.lifetime – cookie lifetime in seconds (default: 3600)
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

        $name     = (string) $config->get('session.name',     'cronmanager_sess');
        $lifetime = (int)    $config->get('session.lifetime', 3600);

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
}
