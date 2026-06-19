<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Test Base Class
 *
 * Provides a real MariaDB connection (test-only database) with automatic
 * schema bootstrapping and transaction-based test isolation: each test runs
 * inside a transaction that is rolled back on tearDown(), so no test data
 * leaks into the next test.
 *
 * Prerequisites:
 *   docker compose -f tests/docker-compose.test.yml up -d
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Base;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    protected PDO $pdo;

    /** @var bool Track whether the schema has been initialised in this PHP process */
    private static bool $schemaInitialised = false;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = $this->createConnection();

        if (!self::$schemaInitialised) {
            $this->applySchema();
            self::$schemaInitialised = true;
        }

        // Each test runs inside a transaction; rolled back in tearDown
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Fixture helpers
    // -------------------------------------------------------------------------

    /**
     * Insert a minimal cron job and return its auto-increment ID.
     *
     * @param array<string, mixed> $overrides Column overrides.
     */
    protected function seedJob(array $overrides = []): int
    {
        $defaults = [
            'linux_user'  => 'root',
            'schedule'    => '* * * * *',
            'command'     => 'echo test',
            'description' => 'Test job',
            'active'      => 1,
        ];

        $data = array_merge($defaults, $overrides);

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($k) => ':' . $k, array_keys($data)));

        $this->pdo->prepare("INSERT INTO cronjobs ({$columns}) VALUES ({$placeholders})")
                  ->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert a job target and return its auto-increment ID.
     *
     * @param int    $jobId  References cronjobs.id.
     * @param string $target "local" or SSH alias.
     */
    protected function seedJobTarget(int $jobId, string $target = 'local'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO job_targets (job_id, target) VALUES (:job_id, :target)');
        $stmt->execute(['job_id' => $jobId, 'target' => $target]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert a running execution log entry and return its ID.
     *
     * @param int                  $jobId     References cronjobs.id.
     * @param array<string, mixed> $overrides Column overrides.
     */
    protected function seedRunningExecution(int $jobId, array $overrides = []): int
    {
        $defaults = [
            'cronjob_id'  => $jobId,
            'started_at'  => date('Y-m-d H:i:s'),
            'finished_at' => null,
            'target'      => 'local',
            'retry_attempt' => 0,
        ];

        $data = array_merge($defaults, $overrides);

        // Filter out null values to rely on column defaults
        $data = array_filter($data, fn($v) => $v !== null);

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($k) => ':' . $k, array_keys($data)));

        $this->pdo->prepare("INSERT INTO execution_log ({$columns}) VALUES ({$placeholders})")
                  ->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert a maintenance window and return its ID.
     *
     * @param array<string, mixed> $overrides Column overrides.
     */
    protected function seedMaintenanceWindow(array $overrides = []): int
    {
        $defaults = [
            'target'           => 'local',
            'cron_schedule'    => '0 2 * * *',
            'duration_minutes' => 60,
            'description'      => 'Test window',
            'active'           => 1,
        ];

        $data = array_merge($defaults, $overrides);

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($k) => ':' . $k, array_keys($data)));

        $this->pdo->prepare("INSERT INTO maintenance_windows ({$columns}) VALUES ({$placeholders})")
                  ->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch a single row from execution_log by ID.
     *
     * @return array<string, mixed>|false
     */
    protected function fetchExecution(int $id): array|false
    {
        $stmt = $this->pdo->prepare('SELECT * FROM execution_log WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Count rows in execution_log for the given job.
     */
    protected function countExecutions(int $jobId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM execution_log WHERE cronjob_id = :id');
        $stmt->execute(['id' => $jobId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Check whether a retry state row exists for the given job + target.
     */
    protected function hasRetryState(int $jobId, string $target = 'local'): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM job_retry_state WHERE job_id = :job_id AND target = :target'
        );
        $stmt->execute(['job_id' => $jobId, 'target' => $target]);

        return (int) $stmt->fetchColumn() > 0;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Create and return a PDO connection to the test database.
     *
     * Connection parameters are read from phpunit.xml <env> entries.
     */
    private function createConnection(): PDO
    {
        $host     = (string) ($_ENV['DB_HOST']     ?? '127.0.0.1');
        $port     = (int)    ($_ENV['DB_PORT']     ?? 13306);
        $name     = (string) ($_ENV['DB_NAME']     ?? 'cronmanager_test');
        $user     = (string) ($_ENV['DB_USER']     ?? 'cronmanager_test');
        $password = (string) ($_ENV['DB_PASSWORD'] ?? 'cronmanager_test');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            return new PDO(
                $dsn,
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped(
                'Test-Datenbank nicht erreichbar (docker compose up?): ' . $e->getMessage()
            );
        }
    }

    /**
     * Apply schema.sql and all migration files to the test database.
     *
     * Safe because CREATE TABLE IF NOT EXISTS is used throughout.
     */
    private function applySchema(): void
    {
        $root = dirname(__DIR__, 3);

        $schemaSql = file_get_contents($root . '/agent/sql/schema.sql');
        if ($schemaSql !== false) {
            $this->pdo->exec($schemaSql);
        }

        $migrationDir = $root . '/agent/sql/migrations';
        if (!is_dir($migrationDir)) {
            return;
        }

        $files = glob($migrationDir . '/*.sql');
        sort($files);

        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql !== false) {
                // Ignore errors from migrations already applied (idempotency)
                try {
                    $this->pdo->exec($sql);
                } catch (PDOException) {
                    // Migration already applied or column exists – skip silently
                }
            }
        }
    }
}
