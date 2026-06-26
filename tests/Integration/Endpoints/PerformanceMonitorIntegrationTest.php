<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: Performance Monitor
 *
 * Verifies the correctness of the two query optimisations introduced in v4.4.0:
 *
 *   1. CronListEndpoint: derived-table LEFT JOINs replace the previous correlated
 *      subqueries for last_run and last_exit_code.  The same data must be returned
 *      regardless of how many execution_log rows exist.
 *
 *   2. HistoryEndpoint: SQL_CALC_FOUND_ROWS + SELECT FOUND_ROWS() replaces the
 *      separate COUNT(DISTINCT …) query.  The total field must reflect the full
 *      result count, not just the current page.
 *
 * Both tests use a real MariaDB transaction (rolled back in tearDown) so no
 * persistent data is written to the test database.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Cron\CrontabManager;
use Cronmanager\Agent\Endpoints\CronListEndpoint;
use Cronmanager\Agent\Endpoints\HistoryEndpoint;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;

final class PerformanceMonitorIntegrationTest extends AgentEndpointTestCase
{
    // =========================================================================
    // 1. CronListEndpoint – derived-table JOIN correctness
    // =========================================================================

    #[Test]
    public function cronListReturnsLastRunFromLatestExecutionLog(): void
    {
        $jobId   = $this->seedJob();
        $target  = $this->seedJobTarget($jobId);

        // Seed two executions; the second is newer (higher ID / later started_at)
        $this->seedFinishedExecution($jobId, ['exit_code' => 0, 'started_at' => '2026-01-01 10:00:00', 'finished_at' => '2026-01-01 10:00:05']);
        $this->seedFinishedExecution($jobId, ['exit_code' => 1, 'started_at' => '2026-06-01 12:00:00', 'finished_at' => '2026-06-01 12:00:05']);

        $endpoint = new CronListEndpoint($this->pdo, $this->createNullLogger(), $this->makeCrontabManager());
        $endpoint->handle([]);

        $body = \Tests\Support\AgentResponse::$body;
        $this->assertSame(200, \Tests\Support\AgentResponse::$statusCode);

        $jobs = $body['data'] ?? [];
        $this->assertCount(1, $jobs);

        // last_exit_code must be from the most recent FINISHED execution (exit 1)
        $this->assertSame(1, $jobs[0]['last_exit_code']);
        // last_run must be the most recent started_at (regardless of exit code)
        $this->assertStringStartsWith('2026-06-01', (string) $jobs[0]['last_run']);
    }

    #[Test]
    public function cronListLastRunIsNullWhenNoExecutionsExist(): void
    {
        $jobId = $this->seedJob();
        $this->seedJobTarget($jobId);

        $endpoint = new CronListEndpoint($this->pdo, $this->createNullLogger(), $this->makeCrontabManager());
        $endpoint->handle([]);

        $jobs = \Tests\Support\AgentResponse::$body['data'] ?? [];
        $this->assertCount(1, $jobs);
        $this->assertNull($jobs[0]['last_run']);
        $this->assertNull($jobs[0]['last_exit_code']);
    }

    // =========================================================================
    // 2. HistoryEndpoint – SQL_CALC_FOUND_ROWS total correctness
    // =========================================================================

    #[Test]
    public function historyEndpointTotalReflectsFullResultCountNotPageSize(): void
    {
        $jobId = $this->seedJob();

        // Seed 5 failed executions
        for ($i = 0; $i < 5; $i++) {
            $this->seedFinishedExecution($jobId, ['exit_code' => 1]);
        }

        // Request only 2 rows per page
        $_GET = ['limit' => '2', 'offset' => '0', 'status' => 'failed'];

        $endpoint = new HistoryEndpoint($this->pdo, $this->createNullLogger());
        $endpoint->handle([]);

        $_GET = [];

        $body = \Tests\Support\AgentResponse::$body;
        $this->assertSame(200, \Tests\Support\AgentResponse::$statusCode);

        // count = rows on this page, total = all matching rows
        $this->assertSame(2, $body['count']);
        $this->assertSame(5, $body['total']);
    }

    #[Test]
    public function historyEndpointTotalIsZeroWhenNoExecutionsMatch(): void
    {
        $_GET = ['status' => 'failed'];

        $endpoint = new HistoryEndpoint($this->pdo, $this->createNullLogger());
        $endpoint->handle([]);

        $_GET = [];

        $body = \Tests\Support\AgentResponse::$body;
        $this->assertSame(200, \Tests\Support\AgentResponse::$statusCode);
        $this->assertSame(0, $body['total']);
    }

    private function makeCrontabManager(): CrontabManager
    {
        return new CrontabManager($this->createNullLogger(), '/dev/null');
    }
}
