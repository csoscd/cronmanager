<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – API Key Schema Bootstrap
 *
 * Ensures the `api_keys` table exists in the shared MariaDB schema.
 * Called once per request from the front controller before session start.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Bootstrap;

use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class ApiKeySchema
 *
 * Idempotent schema bootstrap for the `api_keys` table.
 */
final class ApiKeySchema
{
    /**
     * Ensure the `api_keys` table exists.
     *
     * Errors are logged but never thrown so that a schema hiccup does not
     * prevent the application from serving a page.
     *
     * @param PDO    $pdo    Active database connection.
     * @param Logger $logger Monolog logger.
     *
     * @return void
     */
    public static function ensure(PDO $pdo, Logger $logger): void
    {
        try {
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS api_keys (
                    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id      INT UNSIGNED NOT NULL,
                    name         VARCHAR(100) NOT NULL,
                    key_hash     VARCHAR(64)  NOT NULL UNIQUE
                                     COMMENT 'sha256(plain_key) – plain text is never stored',
                    scopes       JSON         NOT NULL
                                     COMMENT 'Array of granted scope strings, e.g. ["jobs:read"]',
                    agent_ids    JSON         NULL     DEFAULT NULL
                                     COMMENT 'NULL = all agents; [1,3] = only these agent IDs',
                    expires_at   DATETIME     NULL     DEFAULT NULL
                                     COMMENT 'NULL = never expires',
                    ip_whitelist JSON         NULL     DEFAULT NULL
                                     COMMENT 'NULL = no restriction; array of CIDR strings',
                    last_used_at DATETIME     NULL     DEFAULT NULL,
                    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_api_keys_user
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL);
        } catch (PDOException $e) {
            $logger->error('ApiKeySchema::ensure: database error', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
