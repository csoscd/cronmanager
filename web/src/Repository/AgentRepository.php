<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Agent Repository
 *
 * Provides CRUD access to the `agents` table.  Each agent record stores the
 * connection parameters (URL, HMAC secret, TLS settings) for one remote agent
 * instance, allowing the web UI to manage and switch between multiple agents.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Repository;

use PDO;
use PDOException;

/**
 * Class AgentRepository
 *
 * All methods operate on the `agents` table via a PDO connection.
 */
final class AgentRepository
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param PDO $pdo Active PDO database connection.
     */
    public function __construct(private readonly PDO $pdo) {}

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return all agents ordered by sort_order, then name.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PDOException On database errors.
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM agents ORDER BY sort_order ASC, name ASC'
        );

        return $stmt ? $stmt->fetchAll() : [];
    }

    /**
     * Return all enabled agents ordered by sort_order, then name.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PDOException On database errors.
     */
    public function findEnabled(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM agents WHERE enabled = 1 ORDER BY sort_order ASC, name ASC'
        );

        return $stmt ? $stmt->fetchAll() : [];
    }

    /**
     * Return a single agent by primary key, or null when not found.
     *
     * @param int $id Agent primary key.
     *
     * @return array<string, mixed>|null
     *
     * @throws PDOException On database errors.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM agents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Return the total number of agents (including disabled ones).
     *
     * @return int
     *
     * @throws PDOException On database errors.
     */
    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM agents')->fetchColumn();
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert a new agent and return its auto-generated primary key.
     *
     * Expected keys in $data:
     *   name (string), url (string), hmac_secret (string),
     *   description (string|null), timeout (int), ssl_verify (bool|int),
     *   ssl_ca_bundle (string|null), enabled (bool|int), sort_order (int)
     *
     * @param array<string, mixed> $data Agent field values.
     *
     * @return int New agent ID.
     *
     * @throws PDOException On database errors.
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO agents
                (name, description, url, hmac_secret, timeout, ssl_verify, ssl_ca_bundle, enabled, sort_order)
             VALUES
                (:name, :description, :url, :hmac_secret, :timeout, :ssl_verify, :ssl_ca_bundle, :enabled, :sort_order)'
        );

        $stmt->execute([
            ':name'          => (string) ($data['name']          ?? ''),
            ':description'   => isset($data['description']) && $data['description'] !== ''
                                    ? (string) $data['description']
                                    : null,
            ':url'           => (string) ($data['url']           ?? ''),
            ':hmac_secret'   => (string) ($data['hmac_secret']   ?? ''),
            ':timeout'       => (int)    ($data['timeout']        ?? 10),
            ':ssl_verify'    => (int)    ($data['ssl_verify']     ?? 1),
            ':ssl_ca_bundle' => isset($data['ssl_ca_bundle']) && $data['ssl_ca_bundle'] !== ''
                                    ? (string) $data['ssl_ca_bundle']
                                    : null,
            ':enabled'       => (int)    ($data['enabled']        ?? 1),
            ':sort_order'    => (int)    ($data['sort_order']     ?? 0),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update an existing agent identified by $id.
     *
     * Only the keys present in $data are updated; absent keys are ignored.
     *
     * @param int                  $id   Agent primary key.
     * @param array<string, mixed> $data Field values to update.
     *
     * @return void
     *
     * @throws PDOException On database errors.
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE agents SET
                name          = :name,
                description   = :description,
                url           = :url,
                hmac_secret   = :hmac_secret,
                timeout       = :timeout,
                ssl_verify    = :ssl_verify,
                ssl_ca_bundle = :ssl_ca_bundle,
                enabled       = :enabled,
                sort_order    = :sort_order
             WHERE id = :id'
        );

        $stmt->execute([
            ':name'          => (string) ($data['name']          ?? ''),
            ':description'   => isset($data['description']) && $data['description'] !== ''
                                    ? (string) $data['description']
                                    : null,
            ':url'           => (string) ($data['url']           ?? ''),
            ':hmac_secret'   => (string) ($data['hmac_secret']   ?? ''),
            ':timeout'       => (int)    ($data['timeout']        ?? 10),
            ':ssl_verify'    => (int)    ($data['ssl_verify']     ?? 1),
            ':ssl_ca_bundle' => isset($data['ssl_ca_bundle']) && $data['ssl_ca_bundle'] !== ''
                                    ? (string) $data['ssl_ca_bundle']
                                    : null,
            ':enabled'       => (int)    ($data['enabled']        ?? 1),
            ':sort_order'    => (int)    ($data['sort_order']     ?? 0),
            ':id'            => $id,
        ]);
    }

    /**
     * Delete an agent by primary key.
     *
     * @param int $id Agent primary key.
     *
     * @return void
     *
     * @throws PDOException On database errors.
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM agents WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
