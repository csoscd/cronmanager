<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Local Authentication Provider
 *
 * Authenticates users against a password_hash stored in the local MariaDB
 * database.  OIDC-only accounts (password_hash IS NULL) are rejected here;
 * they must authenticate via OidcAuthProvider.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Auth;

use Monolog\Logger;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Class LocalAuthProvider
 *
 * Provides username/password authentication using PHP's password_verify()
 * and BCrypt hashes stored in the `users` table.
 */
class LocalAuthProvider
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param PDO    $pdo    Active PDO connection to the cronmanager database.
     * @param Logger $logger Monolog logger for authentication events.
     */
    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Attempt to authenticate a user by username and password.
     *
     * Authentication fails (returns null) when:
     *   - The username does not exist in the database.
     *   - The account has no local password (OIDC-only account).
     *   - The provided password does not match the stored hash.
     *
     * @param string $username Plain-text username.
     * @param string $password Plain-text password.
     *
     * @return array<string, mixed>|null User row array on success, null on failure.
     *
     * @throws RuntimeException On unexpected database errors.
     */
    public function authenticate(string $username, string $password): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, username, password_hash, role, oauth_sub
                 FROM users
                 WHERE username = :username
                 LIMIT 1'
            );
            $stmt->execute([':username' => $username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error('Database error during authentication', [
                'username' => $username,
                'message'  => $e->getMessage(),
            ]);
            throw new RuntimeException('Authentication failed due to a database error.', previous: $e);
        }

        // ------------------------------------------------------------------
        // User not found
        // ------------------------------------------------------------------
        if ($row === false) {
            $this->logger->debug('Local auth: user not found', ['username' => $username]);
            return null;
        }

        // ------------------------------------------------------------------
        // OIDC-only account – no local password
        // ------------------------------------------------------------------
        if ($row['password_hash'] === null) {
            $this->logger->warning('Local login attempted for OIDC-only account', [
                'username' => $username,
            ]);
            return null;
        }

        // ------------------------------------------------------------------
        // Password verification
        // ------------------------------------------------------------------
        if (!password_verify($password, (string) $row['password_hash'])) {
            $this->logger->warning('Local auth: invalid password', ['username' => $username]);
            return null;
        }

        $this->logger->info('Local auth: login successful', [
            'username' => $username,
            'role'     => $row['role'],
        ]);

        return $row;
    }

    /**
     * Create or update a local user account.
     *
     * The password is hashed with BCrypt (cost 12) before storage.
     * If a user with the same username already exists the password and role
     * are updated in-place; otherwise a new record is inserted.
     *
     * This method is intended for administrative use only (e.g. a setup CLI
     * script).  It should not be called from a publicly reachable endpoint
     * without additional access-control checks.
     *
     * @param string $username Plain-text username.
     * @param string $password Plain-text password (will be hashed).
     * @param string $role     Role to assign: 'view' (default) or 'admin'.
     *
     * @return int The ID of the created or updated user.
     *
     * @throws RuntimeException On database errors.
     */
    public function createUser(string $username, string $password, string $role = 'view'): int
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        try {
            // Check whether the user already exists
            $stmt = $this->pdo->prepare(
                'SELECT id FROM users WHERE username = :username LIMIT 1'
            );
            $stmt->execute([':username' => $username]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing !== false) {
                // Update existing user
                $stmt = $this->pdo->prepare(
                    'UPDATE users
                     SET password_hash = :hash, role = :role
                     WHERE username = :username'
                );
                $stmt->execute([
                    ':hash'     => $hash,
                    ':role'     => $role,
                    ':username' => $username,
                ]);

                $this->logger->info('Local auth: user updated', [
                    'username' => $username,
                    'role'     => $role,
                ]);

                return (int) $existing['id'];
            }

            // Insert new user
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (username, password_hash, role)
                 VALUES (:username, :hash, :role)'
            );
            $stmt->execute([
                ':username' => $username,
                ':hash'     => $hash,
                ':role'     => $role,
            ]);

            $newId = (int) $this->pdo->lastInsertId();

            $this->logger->info('Local auth: user created', [
                'id'       => $newId,
                'username' => $username,
                'role'     => $role,
            ]);

            return $newId;

        } catch (PDOException $e) {
            $this->logger->error('Database error during user creation', [
                'username' => $username,
                'message'  => $e->getMessage(),
            ]);
            throw new RuntimeException('Failed to create user due to a database error.', previous: $e);
        }
    }
}
