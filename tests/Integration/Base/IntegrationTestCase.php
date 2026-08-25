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

    /**
     * When true (default), each test runs inside a database transaction that is
     * rolled back in tearDown() — providing automatic, zero-cleanup test isolation.
     *
     * Set to false in subclasses whose SUT calls beginTransaction() itself
     * (PDO throws "There is already an active transaction" on nested calls).
     * Those tests must perform manual cleanup in their own tearDown().
     */
    protected bool $useTransactionIsolation = true;

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

        if ($this->useTransactionIsolation) {
            // Each test runs inside a transaction; rolled back in tearDown
            $this->pdo->beginTransaction();
        }
    }

    protected function tearDown(): void
    {
        // Guard against setUp() failure (e.g. DB unreachable → markTestSkipped()
        // before $this->pdo was ever initialised).
        if ($this->useTransactionIsolation && isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Fixture helpers
    // -------------------------------------------------------------------------

    /**
     * Insert a user row and return its auto-increment ID.
     *
     * @param array<string, mixed> $overrides Column overrides.
     */
    protected function seedUser(array $overrides = []): int
    {
        $defaults = [
            'username'      => 'testuser_' . uniqid(),
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'role'          => 'admin',
        ];
        $data = array_merge($defaults, $overrides);

        $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, role) VALUES (:username, :password_hash, :role)'
        )->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

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
     * Insert a pending retry state row for the given job + target.
     *
     * @param int                  $jobId           References cronjobs.id.
     * @param int                  $rootExecutionId References execution_log.id of the original run.
     * @param array<string, mixed> $overrides       Column overrides.
     */
    protected function seedRetryState(int $jobId, int $rootExecutionId, array $overrides = []): void
    {
        $defaults = [
            'job_id'              => $jobId,
            'target'              => 'local',
            'next_retry_attempt'  => 1,
            'root_execution_id'   => $rootExecutionId,
            'retry_delay_minutes' => 5,
            'scheduled_at'        => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($defaults, $overrides);

        $columns      = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($k) => ':' . $k, array_keys($data)));

        $this->pdo->prepare("INSERT INTO job_retry_state ({$columns}) VALUES ({$placeholders})")
                  ->execute($data);
    }

    /**
     * Insert an already-finished execution log entry and return its ID.
     *
     * Useful for building failure-streak history in threshold and recovery tests.
     * Unlike seedRunningExecution(), the row has both started_at and finished_at set.
     *
     * @param int                  $jobId     References cronjobs.id.
     * @param array<string, mixed> $overrides Column overrides.
     */
    protected function seedFinishedExecution(int $jobId, array $overrides = []): int
    {
        $defaults = [
            'cronjob_id'    => $jobId,
            'started_at'    => date('Y-m-d H:i:s', time() - 3600),
            'finished_at'   => date('Y-m-d H:i:s', time() - 3595),
            'target'        => 'local',
            'exit_code'     => 1,
            'output'        => '',
            'retry_attempt' => 0,
        ];

        $data = array_filter(array_merge($defaults, $overrides), fn($v) => $v !== null);

        $columns      = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($k) => ':' . $k, array_keys($data)));

        $this->pdo->prepare("INSERT INTO execution_log ({$columns}) VALUES ({$placeholders})")
                  ->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert a maintenance window that is always currently active.
     *
     * Uses schedule `* * * * *` (fires every minute) with a 1440-minute
     * (24-hour) duration, guaranteeing the window covers the current moment
     * regardless of when the test runs.
     *
     * @param string      $target    Target to put in maintenance (use '_agent_' for agent-wide).
     * @param string|null $description Optional description.
     */
    protected function seedActiveMaintenanceWindow(string $target, ?string $description = null): int
    {
        return $this->seedMaintenanceWindow([
            'target'           => $target,
            'cron_schedule'    => '* * * * *',
            'duration_minutes' => 1440,
            'description'      => $description ?? "Test window for {$target}",
            'active'           => 1,
        ]);
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

    /**
     * Insert an agent row and return its auto-increment ID.
     *
     * @param array<string, mixed> $overrides Column overrides.
     */
    protected function seedAgent(array $overrides = []): int
    {
        $defaults = [
            'name'        => 'Test Agent ' . uniqid(),
            'description' => null,
            'url'         => 'http://agent:8865',
            'hmac_secret' => 'test-secret',
            'timeout'     => 10,
            'ssl_verify'  => 1,
            'enabled'     => 1,
            'sort_order'  => 0,
        ];

        $data = array_merge($defaults, $overrides);
        // Remove null values so columns with DEFAULT NULL are handled by the DB
        $data = array_filter($data, fn($v) => $v !== null);

        $columns      = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($k) => ':' . $k, array_keys($data)));

        $this->pdo->prepare("INSERT INTO agents ({$columns}) VALUES ({$placeholders})")
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
