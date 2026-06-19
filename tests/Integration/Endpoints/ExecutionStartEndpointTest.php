<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: ExecutionStartEndpoint
 *
 * Tests the POST /execution/start handler against a real MariaDB database.
 * External services (mail, InfluxDB, crontab) are not involved in this
 * endpoint and require no mocking.
 *
 * Run with the test DB running:
 *   docker compose -f tests/docker-compose.test.yml up -d
 *   ./vendor/bin/phpunit --testsuite integration
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Endpoints\ExecutionStartEndpoint;
use Cronmanager\Agent\Repository\MaintenanceWindowRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;
use Tests\Support\AgentResponse;

final class ExecutionStartEndpointTest extends AgentEndpointTestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeEndpoint(): ExecutionStartEndpoint
    {
        return new ExecutionStartEndpoint(
            $this->pdo,
            $this->createNullLogger(),
            new MaintenanceWindowRepository($this->pdo, $this->createNullLogger()),
        );
    }

    /**
     * Return the execution_log row for the most recently inserted execution,
     * or null when no row exists for the given job.
     *
     * @return array<string, mixed>|null
     */
    private function latestExecution(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM execution_log WHERE cronjob_id = :id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    // =========================================================================
    // 1. Happy path
    // =========================================================================

    #[Test]
    public function happyPathCreatesExecutionLogRowAndReturns201(): void
    {
        $jobId = $this->seedJob();

        $this->callHandle($this->makeEndpoint(), [
            'job_id'     => $jobId,
            'started_at' => '2026-01-15T10:00:00Z',
            'target'     => 'local',
        ]);

        $this->assertStatus(201);
        $this->assertBodyHasKey('execution_id');
        $this->assertBodyHas('job_id', $jobId);

        $executionId = AgentResponse::$body['execution_id'] ?? 0;
        $this->assertGreaterThan(0, $executionId);

        $row = $this->latestExecution($jobId);
        $this->assertNotNull($row);
        $this->assertSame('2026-01-15 10:00:00', $row['started_at']);
        $this->assertNull($row['finished_at']);
        $this->assertSame(0, (int) $row['retry_attempt']);
        $this->assertNull($row['retry_root_execution_id']);
        $this->assertSame(0, (int) $row['during_maintenance']);
    }

    #[Test]
    public function iso8601TimestampWithOffsetIsConvertedToUtc(): void
    {
        $jobId = $this->seedJob();

        // +01:00 offset → UTC is one hour earlier
        $this->callHandle($this->makeEndpoint(), [
            'job_id'     => $jobId,
            'started_at' => '2026-01-15T11:00:00+01:00',
        ]);

        $this->assertStatus(201);

        $row = $this->latestExecution($jobId);
        $this->assertNotNull($row);
        $this->assertSame('2026-01-15 10:00:00', $row['started_at']);
    }

    #[Test]
    public function targetIsStoredInExecutionLog(): void
    {
        $jobId = $this->seedJob();

        $this->callHandle($this->makeEndpoint(), [
            'job_id'     => $jobId,
            'started_at' => '2026-01-15T10:00:00Z',
            'target'     => 'ssh-backup-server',
        ]);

        $this->assertStatus(201);
        $row = $this->latestExecution($jobId);
        $this->assertSame('ssh-backup-server', $row['target']);
    }

    #[Test]
    public function missingTargetDefaultsToNullInExecutionLog(): void
    {
        $jobId = $this->seedJob();

        $this->callHandle($this->makeEndpoint(), [
            'job_id'     => $jobId,
            'started_at' => '2026-01-15T10:00:00Z',
            // no 'target' key
        ]);

        $this->assertStatus(201);
        $row = $this->latestExecution($jobId);
        $this->assertNull($row['target']);
    }

    // =========================================================================
    // 2. Input validation
    // =========================================================================

    #[Test]
    public function invalidJsonBodyReturns400(): void
    {
        \Tests\Support\PhpInputStream::set('not valid json {');
        $this->makeEndpoint()->handle([]);
        \Tests\Support\PhpInputStream::restore();

        $this->assertStatus(400);
    }

    #[Test]
    public function missingJobIdReturns422(): void
    {
        $this->callHandle($this->makeEndpoint(), [
            'started_at' => '2026-01-15T10:00:00Z',
        ]);

        $this->assertStatus(422);
        $this->assertArrayHasKey('job_id', AgentResponse::$body['fields'] ?? []);
    }

    #[Test]
    public function missingStartedAtReturns422(): void
    {
        $jobId = $this->seedJob();

        $this->callHandle($this->makeEndpoint(), [
            'job_id' => $jobId,
        ]);

        $this->assertStatus(422);
        $this->assertArrayHasKey('started_at', AgentResponse::$body['fields'] ?? []);
    }

    #[Test]
    public function jobIdZeroReturns422(): void
    {
        $this->callHandle($this->makeEndpoint(), [
            'job_id'     => 0,
            'started_at' => '2026-01-15T10:00:00Z',
        ]);

        $this->assertStatus(422);
    }

    #[Test]
    public function nonIntegerJobIdReturns422(): void
    {
        $this->callHandle($this->makeEndpoint(), [
            'job_id'     => 'abc',
            'started_at' => '2026-01-15T10:00:00Z',
        ]);

        $this->assertStatus(422);
    }

    // =========================================================================
    // 3. Job not found
    // =========================================================================

    #[Test]
    public function nonExistentJobIdReturns404(): void
    {
        $this->callHandle($this->makeEndpoint(), [
            'job_id'     => 999999,
            'started_at' => '2026-01-15T10:00:00Z',
        ]);

        $this->assertStatus(404);
    }

    // =========================================================================
    // 4. Singleton guard
    // =========================================================================

    #[Test]
    public function singletonJobWithRunningExecutionReturns409(): void
    {
        $jobId = $this->seedJob(['singleton' => 1]);
        $this->seedRunningExecution($jobId);

        $this->callHandle($this->makeEndpoint(), [
            'job_id'     => $jobId,
            'started_at' => '2026-01-15T10:00:00Z',
            'target'     => 'local',
        ]);

        $this->assertStatus(409);
        // Only the seeded execution should exist, not a new one
        $this->assertSame(1, $this->countExecutions($jobId));
    }

    #[Test]
    public function singletonJobWithPendingRetryReturns409(): void
    {
        $jobId       = $this->seedJob(['singleton' => 1]);
        $executionId = $this->seedRunningExecution($jobId);
        // Mark it as finished so singleton's running-check passes
        $this->pdo->prepare('UPDATE execution_log SET finished_at = NOW(), exit_code = 1 WHERE id = :id')
                  ->execute([':id' => $executionId]);
        // Plant a retry state on a DIFFERENT target so hasPendingRetryForTarget() won't flag this as a retry invocation
        $this->seedRetryState($jobId, $executionId, ['target' => 'other-target']);

        $this->callHandle($this->makeEndpoint(), [
            'job_id'                => $jobId,
            'started_at'            => '2026-01-15T10:00:00Z',
            'target'                => 'local',
            'is_retry_invocation'   => false,
        ]);

        $this->assertStatus(409);
    }

    // =========================================================================
    // 5. Retry-pending guard (non-singleton)
    // =========================================================================

    #[Test]
    public function regularTickBlockedWhenRetryPendingForSameTarget(): void
    {
        $jobId       = $this->seedJob(['singleton' => 0, 'retry_count' => 3]);
        $executionId = $this->seedRunningExecution($jobId);
        $this->seedRetryState($jobId, $executionId, ['target' => 'local']);

        $this->callHandle($this->makeEndpoint(), [
            'job_id'              => $jobId,
            'started_at'          => '2026-01-15T10:00:00Z',
            'target'              => 'local',
            'is_retry_invocation' => false,
        ]);

        $this->assertStatus(409);
    }

    #[Test]
    public function regularTickOnDifferentTargetIsNotBlockedByRetryState(): void
    {
        $jobId       = $this->seedJob(['singleton' => 0, 'retry_count' => 3]);
        $executionId = $this->seedRunningExecution($jobId, ['target' => 'ssh-server-1']);
        // Retry is pending on ssh-server-1, not on local
        $this->seedRetryState($jobId, $executionId, ['target' => 'ssh-server-1']);

        $this->callHandle($this->makeEndpoint(), [
            'job_id'              => $jobId,
            'started_at'          => '2026-01-15T10:00:00Z',
            'target'              => 'local',   // different target
            'is_retry_invocation' => false,
        ]);

        // local target has no retry pending → should start normally
        $this->assertStatus(201);
    }

    // =========================================================================
    // 6. Retry execution itself
    // =========================================================================

    #[Test]
    public function retryInvocationConsumesRetryStateAndSetsRetryAttempt(): void
    {
        $jobId       = $this->seedJob(['retry_count' => 3]);
        $executionId = $this->seedRunningExecution($jobId);
        $this->pdo->prepare('UPDATE execution_log SET finished_at = NOW(), exit_code = 1 WHERE id = :id')
                  ->execute([':id' => $executionId]);
        $this->seedRetryState($jobId, $executionId, [
            'target'             => 'local',
            'next_retry_attempt' => 1,
            'root_execution_id'  => $executionId,
        ]);

        $this->callHandle($this->makeEndpoint(), [
            'job_id'              => $jobId,
            'started_at'          => '2026-01-15T10:05:00Z',
            'target'              => 'local',
            'is_retry_invocation' => true,
        ]);

        $this->assertStatus(201);

        // job_retry_state must have been deleted
        $this->assertFalse($this->hasRetryState($jobId, 'local'));

        // New execution must carry the retry metadata
        $row = $this->latestExecution($jobId);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['retry_attempt']);
        $this->assertSame($executionId, (int) $row['retry_root_execution_id']);
    }

    #[Test]
    public function retryInvocationSetsCorrectAttemptCounter(): void
    {
        $jobId       = $this->seedJob(['retry_count' => 3]);
        $rootId      = $this->seedRunningExecution($jobId);
        $this->pdo->prepare('UPDATE execution_log SET finished_at = NOW(), exit_code = 1 WHERE id = :id')
                  ->execute([':id' => $rootId]);
        $this->seedRetryState($jobId, $rootId, [
            'target'             => 'local',
            'next_retry_attempt' => 2,   // second retry
            'root_execution_id'  => $rootId,
        ]);

        $this->callHandle($this->makeEndpoint(), [
            'job_id'              => $jobId,
            'started_at'          => '2026-01-15T10:10:00Z',
            'target'              => 'local',
            'is_retry_invocation' => true,
        ]);

        $this->assertStatus(201);

        $row = $this->latestExecution($jobId);
        $this->assertSame(2, (int) $row['retry_attempt']);
        $this->assertSame($rootId, (int) $row['retry_root_execution_id']);
    }

    // =========================================================================
    // 7. Agent-wide maintenance window
    // =========================================================================

    #[Test]
    public function agentMaintenanceWindowSkipsExecutionAndReturns423(): void
    {
        $jobId = $this->seedJob();
        // Plant an always-active agent-wide maintenance window
        $this->seedActiveMaintenanceWindow('_agent_');

        $this->callHandle($this->makeEndpoint(), [
            'job_id'     => $jobId,
            'started_at' => '2026-01-15T10:00:00Z',
            'target'     => 'local',
        ]);

        $this->assertStatus(423);
        $this->assertBodyHasKey('execution_id');

        // A skipped execution row must have been created
        $row = $this->latestExecution($jobId);
        $this->assertNotNull($row);
        $this->assertSame(-4, (int) $row['exit_code']);
        $this->assertSame(1, (int) $row['during_maintenance']);
        $this->assertNotNull($row['finished_at']);
        $this->assertStringContainsStringIgnoringCase('agent', (string) $row['output']);
    }

    // =========================================================================
    // 8. Per-target maintenance window
    // =========================================================================

    #[Test]
    public function targetInMaintenanceWithRunInMaintenanceFalseReturns423(): void
    {
        $jobId = $this->seedJob(['run_in_maintenance' => 0]);
        $this->seedActiveMaintenanceWindow('local');

        $this->callHandle($this->makeEndpoint(), [
            'job_id'     => $jobId,
            'started_at' => '2026-01-15T10:00:00Z',
            'target'     => 'local',
        ]);

        $this->assertStatus(423);

        $row = $this->latestExecution($jobId);
        $this->assertNotNull($row);
        $this->assertSame(-4, (int) $row['exit_code']);
        $this->assertSame(1, (int) $row['during_maintenance']);
        $this->assertNotNull($row['finished_at']);
    }

    #[Test]
    public function targetInMaintenanceWithRunInMaintenanceTrueReturns201(): void
    {
        $jobId = $this->seedJob(['run_in_maintenance' => 1]);
        $this->seedActiveMaintenanceWindow('local');

        $this->callHandle($this->makeEndpoint(), [
            'job_id'     => $jobId,
            'started_at' => '2026-01-15T10:00:00Z',
            'target'     => 'local',
        ]);

        $this->assertStatus(201);

        $row = $this->latestExecution($jobId);
        $this->assertNotNull($row);
        $this->assertNull($row['finished_at']);        // still running
        $this->assertNull($row['exit_code']);           // not yet finished
        $this->assertSame(1, (int) $row['during_maintenance']);
    }

    #[Test]
    public function maintenanceWindowForOtherTargetDoesNotBlockCurrentTarget(): void
    {
        $jobId = $this->seedJob(['run_in_maintenance' => 0]);
        // Window is for a different target
        $this->seedActiveMaintenanceWindow('ssh-other-server');

        $this->callHandle($this->makeEndpoint(), [
            'job_id'     => $jobId,
            'started_at' => '2026-01-15T10:00:00Z',
            'target'     => 'local',
        ]);

        // 'local' is not in maintenance → should start normally
        $this->assertStatus(201);
    }
}
