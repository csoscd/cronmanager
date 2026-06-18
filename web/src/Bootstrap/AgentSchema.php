<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Agent Schema Bootstrap
 *
 * Ensures the `agents` table exists in the shared MariaDB schema and seeds
 * an initial "Default" agent entry from the legacy `agent.*` config keys when
 * the table is empty.  This allows existing single-agent installations to
 * upgrade without any manual database or configuration changes.
 *
 * Called once per request from the front controller (index.php) before
 * session start, so the table is always present before any controller runs.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Bootstrap;

use Monolog\Logger;
use Noodlehaus\Config;
use PDO;
use PDOException;

/**
 * Class AgentSchema
 *
 * Idempotent schema bootstrap for the `agents` table.
 */
final class AgentSchema
{
    /**
     * Ensure the `agents` table exists and contains at least one entry.
     *
     * Steps:
     *   1. CREATE TABLE IF NOT EXISTS agents (…)
     *   2. If the table is empty, insert a "Default" agent seeded from the
     *      legacy `agent.*` configuration keys that were used in single-agent
     *      mode.
     *
     * Errors are logged but never thrown so that a schema hiccup does not
     * prevent the application from serving a page.
     *
     * @param PDO    $pdo    Active database connection.
     * @param Config $config Noodlehaus configuration instance.
     * @param Logger $logger Monolog logger.
     *
     * @return void
     */
    public static function ensure(PDO $pdo, Config $config, Logger $logger): void
    {
        try {
            self::createTable($pdo);
            self::seedFromConfig($pdo, $config, $logger);
        } catch (PDOException $e) {
            $logger->error('AgentSchema::ensure: database error', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Create the `agents` table if it does not already exist.
     *
     * @param PDO $pdo Active database connection.
     *
     * @throws PDOException On database errors.
     */
    private static function createTable(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS agents (
                id            INT          AUTO_INCREMENT PRIMARY KEY,
                name          VARCHAR(100) NOT NULL,
                description   VARCHAR(255) NULL     DEFAULT NULL,
                url           VARCHAR(500) NOT NULL,
                hmac_secret   VARCHAR(255) NOT NULL,
                timeout       INT          NOT NULL DEFAULT 10,
                ssl_verify    TINYINT(1)   NOT NULL DEFAULT 1,
                ssl_ca_bundle VARCHAR(500) NULL     DEFAULT NULL,
                enabled       TINYINT(1)   NOT NULL DEFAULT 1,
                sort_order    INT          NOT NULL DEFAULT 0,
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                           ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    /**
     * Insert a "Default" agent from the legacy `agent.*` config keys when the
     * table is empty.
     *
     * This migration path means existing deployments that previously relied on
     * a single agent configured in config.json continue to work automatically
     * after the upgrade without any manual steps.
     *
     * @param PDO    $pdo    Active database connection.
     * @param Config $config Noodlehaus configuration instance.
     * @param Logger $logger Monolog logger.
     *
     * @throws PDOException On database errors.
     */
    private static function seedFromConfig(PDO $pdo, Config $config, Logger $logger): void
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM agents')->fetchColumn();

        if ($count > 0) {
            return;
        }

        $url    = (string) $config->get('agent.url',         'https://host.docker.internal:8865');
        $secret = (string) $config->get('agent.hmac_secret', '');

        // Only seed when there is a meaningful URL and secret available
        if ($url === '' || $secret === '') {
            $logger->warning('AgentSchema: agents table is empty and no legacy agent.url / agent.hmac_secret found in config – skipping auto-seed');
            return;
        }

        $timeout   = (int)  $config->get('agent.timeout',     10);
        $sslVerify = (bool) $config->get('agent.ssl_verify',  true);
        $caBundle  = (string) $config->get('agent.ssl_ca_bundle', '');

        $stmt = $pdo->prepare(
            'INSERT INTO agents (name, description, url, hmac_secret, timeout, ssl_verify, ssl_ca_bundle, enabled, sort_order)
             VALUES (:name, :description, :url, :hmac_secret, :timeout, :ssl_verify, :ssl_ca_bundle, 1, 0)'
        );

        $stmt->execute([
            ':name'         => 'Default',
            ':description'  => 'Automatically migrated from config.json',
            ':url'          => $url,
            ':hmac_secret'  => $secret,
            ':timeout'      => $timeout,
            ':ssl_verify'   => $sslVerify ? 1 : 0,
            ':ssl_ca_bundle'=> $caBundle !== '' ? $caBundle : null,
        ]);

        $logger->info('AgentSchema: seeded default agent from legacy config.json values', [
            'url' => $url,
        ]);
    }
}
