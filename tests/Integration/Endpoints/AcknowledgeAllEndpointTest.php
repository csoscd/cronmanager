<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: AcknowledgeAllEndpoint
 *
 * Covers the correctness invariants of POST /crons/{id}/acknowledge-all:
 *
 *   1. Rejects a non-numeric path parameter (HTTP 400)
 *   2. Returns 404 when the job does not exist
 *   3. Returns 200 with acknowledged_count = 0 when no unacknowledged failures exist
 *   4. Acknowledges all eligible failures and returns the correct count (HTTP 200)
 *   5. Only marks rows that are finished AND non-zero AND unacknowledged
 *      — running executions are excluded
 *      — exit_code = 0 (success) rows are excluded
 *      — already-acknowledged rows are excluded
 *   6. Rows belonging to other jobs are not touched
 *   7. acknowledged_by_user_id is set to the userId passed at construction
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Audit\AuditLogger;
use Cronmanager\Agent\Endpoints\AcknowledgeAllEndpoint;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;

final class AcknowledgeAllEndpointTest extends AgentEndpointTestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeEndpoint(int $userId = 42): AcknowledgeAllEndpoint
    {
        $audit = new AuditLogger($this->pdo, $this->createNullLogger(), $userId, 'testuser', '127.0.0.1');

        return new AcknowledgeAllEndpoint(
            $this->pdo,
            $this->createNullLogger(),
            $audit,
            $userId,
        );
    }

    /**
     * Call handle() with a given path parameter (simulates router injection).
     *
     * @param array<string, string> $params
     */
    private function callWithParams(array $params): void
    {
        $endpoint = $this->makeEndpoint();
        $endpoint->handle($params);
    }

    /**
     * Count acknowledged rows for a job.
     */
    private function countAcknowledged(int $jobId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM execution_log
              WHERE cronjob_id = :id AND acknowledged_at IS NOT NULL'
        );
        $stmt->execute([':id' => $jobId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Fetch acknowledged_by_user_id for all acknowledged rows of a job.
     *
     * @return int[]
     */
    private function fetchAcknowledgedByUserIds(int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT acknowledged_by_user_id FROM execution_log
              WHERE cronjob_id = :id AND acknowledged_at IS NOT NULL'
        );
        $stmt->execute([':id' => $jobId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // =========================================================================
    // 1. 400 – invalid path parameter
    // =========================================================================

    #[Test]
    public function rejectsNonNumericId(): void
    {
        $this->callWithParams(['id' => 'abc']);
        $this->assertStatus(400);
    }

    #[Test]
    public function rejectsZeroId(): void
    {
        $this->callWithParams(['id' => '0']);
        $this->assertStatus(400);
    }

    // =========================================================================
    // 2. 404 – job does not exist
    // =========================================================================

    #[Test]
    public function returns404WhenJobDoesNotExist(): void
    {
        $this->callWithParams(['id' => '999999']);
        $this->assertStatus(404);
    }

    // =========================================================================
    // 3. 200 – no unacknowledged failures
    // =========================================================================

    #[Test]
    public function returnsZeroCountWhenNoUnacknowledgedFailuresExist(): void
    {
        $jobId = $this->seedJob();

        $this->callWithParams(['id' => (string) $jobId]);

        $this->assertStatus(200);
        $this->assertBodyHas('acknowledged_count', 0);
        $this->assertBodyHas('job_id', $jobId);
    }

    // =========================================================================
    // 4. 200 – happy path: multiple failures acknowledged
    // =========================================================================

    #[Test]
    public function acknowledgesAllUnacknowledgedFailures(): void
    {
        $jobId = $this->seedJob();
        $this->seedFinishedExecution($jobId, ['exit_code' => 1]);
        $this->seedFinishedExecution($jobId, ['exit_code' => 2]);
        $this->seedFinishedExecution($jobId, ['exit_code' => 127]);

        $this->callWithParams(['id' => (string) $jobId]);

        $this->assertStatus(200);
        $this->assertBodyHas('acknowledged_count', 3);
        $this->assertSame(3, $this->countAcknowledged($jobId));
    }

    // =========================================================================
    // 5a. Running executions are excluded (finished_at IS NULL)
    // =========================================================================

    #[Test]
    public function excludesRunningExecutions(): void
    {
        $jobId = $this->seedJob();
        $this->seedFinishedExecution($jobId, ['exit_code' => 1]);
        $this->seedRunningExecution($jobId);  // exit_code = NULL, finished_at = NULL

        $this->callWithParams(['id' => (string) $jobId]);

        $this->assertStatus(200);
        // Only the finished failure is acknowledged; the running one is skipped
        $this->assertBodyHas('acknowledged_count', 1);
    }

    // =========================================================================
    // 5b. Successful executions (exit_code = 0) are excluded
    // =========================================================================

    #[Test]
    public function excludesSuccessfulExecutions(): void
    {
        $jobId = $this->seedJob();
        $this->seedFinishedExecution($jobId, ['exit_code' => 0]);  // success
        $this->seedFinishedExecution($jobId, ['exit_code' => 1]);  // failure

        $this->callWithParams(['id' => (string) $jobId]);

        $this->assertStatus(200);
        $this->assertBodyHas('acknowledged_count', 1);
    }

    // =========================================================================
    // 5c. Already-acknowledged rows are excluded
    // =========================================================================

    #[Test]
    public function excludesAlreadyAcknowledgedRows(): void
    {
        $jobId = $this->seedJob();
        // Already acknowledged
        $this->seedFinishedExecution($jobId, [
            'exit_code'       => 1,
            'acknowledged_at' => date('Y-m-d H:i:s'),
        ]);
        // Not yet acknowledged
        $this->seedFinishedExecution($jobId, ['exit_code' => 2]);

        $this->callWithParams(['id' => (string) $jobId]);

        $this->assertStatus(200);
        $this->assertBodyHas('acknowledged_count', 1);
        // Total acknowledged rows = 2 (1 pre-existing + 1 new)
        $this->assertSame(2, $this->countAcknowledged($jobId));
    }

    // =========================================================================
    // 6. Other jobs' rows are not touched
    // =========================================================================

    #[Test]
    public function doesNotTouchOtherJobsExecutions(): void
    {
        $targetJob = $this->seedJob(['description' => 'Target job']);
        $otherJob  = $this->seedJob(['description' => 'Other job']);

        $this->seedFinishedExecution($targetJob, ['exit_code' => 1]);
        $this->seedFinishedExecution($otherJob,  ['exit_code' => 1]);

        $this->callWithParams(['id' => (string) $targetJob]);

        $this->assertStatus(200);
        $this->assertBodyHas('acknowledged_count', 1);

        // Other job's execution must remain unacknowledged
        $this->assertSame(0, $this->countAcknowledged($otherJob));
    }

    // =========================================================================
    // 7. acknowledged_by_user_id is set to the userId injected at construction
    // =========================================================================

    #[Test]
    public function setsAcknowledgedByUserIdFromConstructorArgument(): void
    {
        $jobId   = $this->seedJob();
        $userId  = 99;
        $audit   = new AuditLogger($this->pdo, $this->createNullLogger(), $userId, 'u', '127.0.0.1');
        $endpoint = new AcknowledgeAllEndpoint(
            $this->pdo,
            $this->createNullLogger(),
            $audit,
            $userId,
        );

        $this->seedFinishedExecution($jobId, ['exit_code' => 3]);
        $endpoint->handle(['id' => (string) $jobId]);

        $userIds = $this->fetchAcknowledgedByUserIds($jobId);
        $this->assertSame([$userId], $userIds);
    }
}
