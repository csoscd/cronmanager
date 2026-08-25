<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Auth Token Repository
 *
 * Manages one-time tokens for user invite and password-reset flows.
 * Plain tokens are generated here and returned to the caller; only
 * the sha256 hash is stored in the database.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Auth;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Class AuthTokenRepository
 *
 * CRUD for the auth_tokens table.  Every token is a cryptographically
 * random 32-byte value encoded as a 64-character hex string.  Only its
 * sha256 hash is persisted; the plain token is returned from create()
 * exactly once and must be embedded in the email link.
 */
class AuthTokenRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Create a new one-time token for the given user.
     *
     * Any previous unused tokens of the same type are invalidated first.
     *
     * @param int    $userId  User ID to bind the token to.
     * @param string $type    'invite' or 'reset'.
     * @param int    $ttlHours Token validity in hours (default 72).
     *
     * @return string Plain 64-hex-char token to embed in the email link.
     *
     * @throws RuntimeException On database failure.
     */
    public function create(int $userId, string $type, int $ttlHours = 72): string
    {
        $plain     = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $plain);
        $expiresAt = (new DateTimeImmutable())->modify("+{$ttlHours} hours")->format('Y-m-d H:i:s');

        try {
            // Invalidate any outstanding tokens of this type for this user
            $this->pdo->prepare(
                'DELETE FROM auth_tokens WHERE user_id = :uid AND type = :type AND used_at IS NULL'
            )->execute([':uid' => $userId, ':type' => $type]);

            $this->pdo->prepare(
                'INSERT INTO auth_tokens (user_id, token_hash, type, expires_at)
                 VALUES (:uid, :hash, :type, :exp)'
            )->execute([
                ':uid'  => $userId,
                ':hash' => $hash,
                ':type' => $type,
                ':exp'  => $expiresAt,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to create auth token: ' . $e->getMessage(), previous: $e);
        }

        return $plain;
    }

    /**
     * Look up a valid (unexpired, unused) token and return its row, or null.
     *
     * @param string $plainToken The plain token from the URL.
     * @param string $type       'invite' or 'reset'.
     *
     * @return array<string,mixed>|null Token row including user_id, or null if invalid.
     */
    public function find(string $plainToken, string $type): ?array
    {
        $hash = hash('sha256', $plainToken);

        try {
            $stmt = $this->pdo->prepare(
                'SELECT t.id, t.user_id, t.type, t.expires_at, t.used_at,
                        u.username, u.email, u.active
                   FROM auth_tokens t
                   JOIN users u ON u.id = t.user_id
                  WHERE t.token_hash = :hash
                    AND t.type       = :type
                    AND t.used_at   IS NULL
                    AND t.expires_at > NOW()
                  LIMIT 1'
            );
            $stmt->execute([':hash' => $hash, ':type' => $type]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to look up auth token: ' . $e->getMessage(), previous: $e);
        }

        return $row !== false ? $row : null;
    }

    /**
     * Mark a token as used (consumed).
     *
     * @param int $tokenId Token ID from the auth_tokens table.
     *
     * @return void
     */
    public function consume(int $tokenId): void
    {
        try {
            $this->pdo->prepare('UPDATE auth_tokens SET used_at = NOW() WHERE id = :id')
                      ->execute([':id' => $tokenId]);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to consume auth token: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * Delete all expired or used tokens (housekeeping helper).
     *
     * @return int Number of deleted rows.
     */
    public function purgeExpired(): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM auth_tokens WHERE expires_at < NOW() OR used_at IS NOT NULL'
            );
            $stmt->execute();
            return (int) $stmt->rowCount();
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to purge auth tokens: ' . $e->getMessage(), previous: $e);
        }
    }
}
