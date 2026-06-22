<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – API Key Repository
 *
 * Database access layer for the `api_keys` table.  All methods work with the
 * ApiKey value object and the raw PDO connection.
 *
 * The plain-text key is generated here (on creation) and returned to the
 * caller exactly once.  Only its SHA-256 hash is persisted.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Auth;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Class ApiKeyRepository
 *
 * Provides CRUD operations for API keys.
 */
final class ApiKeyRepository
{
    // -------------------------------------------------------------------------
    // Key format
    // -------------------------------------------------------------------------

    /** Prefix that makes Cronmanager API keys recognisable in logs and UIs */
    private const KEY_PREFIX = 'cm_';

    /** Number of random bytes for the key body (yields 40 base64url chars) */
    private const KEY_BYTES = 30;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param PDO $pdo Active database connection.
     */
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Create a new API key for a user.
     *
     * Generates a cryptographically random key, hashes it with SHA-256, and
     * stores the hash in the database.  Returns both the generated ApiKey
     * object and the plain-text key so the caller can show it to the user.
     *
     * @param int         $userId      FK to users.id.
     * @param string      $name        Human-readable label.
     * @param string[]    $scopes      Granted scopes.
     * @param int[]|null  $agentIds    NULL = all agents.
     * @param \DateTimeImmutable|null $expiresAt NULL = never expires.
     * @param string[]|null $ipWhitelist NULL = no restriction.
     *
     * @return array{key: ApiKey, plainText: string}
     *
     * @throws RuntimeException On key generation or database error.
     */
    public function create(
        int     $userId,
        string  $name,
        array   $scopes,
        ?array  $agentIds,
        ?\DateTimeImmutable $expiresAt,
        ?array  $ipWhitelist,
    ): array {
        $plainText = self::KEY_PREFIX . $this->generateKeyBody();
        $hash      = hash('sha256', $plainText);

        $stmt = $this->pdo->prepare(
            'INSERT INTO api_keys (user_id, name, key_hash, scopes, agent_ids, expires_at, ip_whitelist)
             VALUES (:user_id, :name, :key_hash, :scopes, :agent_ids, :expires_at, :ip_whitelist)'
        );

        $stmt->execute([
            ':user_id'      => $userId,
            ':name'         => $name,
            ':key_hash'     => $hash,
            ':scopes'       => json_encode($scopes, JSON_THROW_ON_ERROR),
            ':agent_ids'    => $agentIds !== null ? json_encode($agentIds, JSON_THROW_ON_ERROR) : null,
            ':expires_at'   => $expiresAt?->format('Y-m-d H:i:s'),
            ':ip_whitelist' => $ipWhitelist !== null ? json_encode($ipWhitelist, JSON_THROW_ON_ERROR) : null,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        $apiKey = new ApiKey(
            id:          $id,
            userId:      $userId,
            name:        $name,
            scopes:      $scopes,
            agentIds:    $agentIds,
            expiresAt:   $expiresAt,
            ipWhitelist: $ipWhitelist,
            lastUsedAt:  null,
            createdAt:   new \DateTimeImmutable('now'),
        );

        return ['key' => $apiKey, 'plainText' => $plainText];
    }

    /**
     * Find an API key by its plain-text value.
     *
     * Hashes the provided value with SHA-256 and looks it up in the database.
     * Returns null if no matching key exists.
     *
     * @param string $plainText The Bearer token value (with or without "Bearer " prefix).
     *
     * @return ApiKey|null
     */
    public function findByPlainText(string $plainText): ?ApiKey
    {
        $hash = hash('sha256', $plainText);
        return $this->findByHash($hash);
    }

    /**
     * Find an API key by its SHA-256 hash.
     *
     * @param string $hash Hex-encoded SHA-256 hash.
     *
     * @return ApiKey|null
     */
    public function findByHash(string $hash): ?ApiKey
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, scopes, agent_ids, expires_at, ip_whitelist, last_used_at, created_at
             FROM api_keys
             WHERE key_hash = :hash
             LIMIT 1'
        );

        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * List all API keys belonging to a user (without the hash).
     *
     * @param int $userId User ID.
     *
     * @return ApiKey[]
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, scopes, agent_ids, expires_at, ip_whitelist, last_used_at, created_at
             FROM api_keys
             WHERE user_id = :user_id
             ORDER BY created_at DESC'
        );

        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Delete an API key by ID, scoped to the owning user.
     *
     * Returns true if a row was deleted, false if the key did not exist or
     * belongs to a different user.
     *
     * @param int $id     API key ID.
     * @param int $userId User ID (ownership check).
     *
     * @return bool
     */
    public function deleteForUser(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM api_keys WHERE id = :id AND user_id = :user_id'
        );

        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update the last_used_at timestamp for the given key ID.
     *
     * Intended to be called from a shutdown function so it does not add
     * latency to the API response.
     *
     * @param int $id API key ID.
     *
     * @return void
     */
    public function touchLastUsed(int $id): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE api_keys SET last_used_at = NOW() WHERE id = :id'
            );
            $stmt->execute([':id' => $id]);
        } catch (PDOException) {
            // Silently ignore – this is a best-effort update
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Generate the random body of a new API key (URL-safe base64, no padding).
     *
     * @return string
     *
     * @throws RuntimeException When the random bytes cannot be generated.
     */
    private function generateKeyBody(): string
    {
        $bytes = random_bytes(self::KEY_BYTES);
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Hydrate a database row into an ApiKey value object.
     *
     * @param array<string, mixed> $row Raw PDO row.
     *
     * @return ApiKey
     */
    private function hydrate(array $row): ApiKey
    {
        /** @var string[] $scopes */
        $scopes = json_decode((string) $row['scopes'], true, flags: JSON_THROW_ON_ERROR);

        $agentIds = $row['agent_ids'] !== null
            ? json_decode((string) $row['agent_ids'], true, flags: JSON_THROW_ON_ERROR)
            : null;

        $ipWhitelist = $row['ip_whitelist'] !== null
            ? json_decode((string) $row['ip_whitelist'], true, flags: JSON_THROW_ON_ERROR)
            : null;

        $expiresAt  = $row['expires_at']   !== null
            ? new \DateTimeImmutable((string) $row['expires_at'])
            : null;

        $lastUsedAt = $row['last_used_at'] !== null
            ? new \DateTimeImmutable((string) $row['last_used_at'])
            : null;

        return new ApiKey(
            id:          (int) $row['id'],
            userId:      (int) $row['user_id'],
            name:        (string) $row['name'],
            scopes:      $scopes,
            agentIds:    $agentIds,
            expiresAt:   $expiresAt,
            ipWhitelist: $ipWhitelist,
            lastUsedAt:  $lastUsedAt,
            createdAt:   new \DateTimeImmutable((string) $row['created_at']),
        );
    }
}
