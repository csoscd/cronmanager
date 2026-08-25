<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: CronBulkDeleteEndpoint
 *
 * Covers the six correctness invariants of POST /crons/bulk/delete:
 *
 *   1. Rejects an empty IDs array (HTTP 400)
 *   2. Rejects a batch that contains an unknown job ID (HTTP 404)
 *   3. Rejects the entire batch when any job has a running execution (HTTP 409);
 *      the guard is atomic – the non-running job in the same batch is also
 *      preserved (no partial delete)
 *   4. Deletes all requested jobs and returns the correct count (HTTP 200)
 *   5. ON DELETE CASCADE: execution_log rows of deleted jobs are gone after the
 *      bulk delete (foundation for the "no last_execution_id re-derivation needed"
 *      reasoning – the cronjobs row itself no longer exists)
 *   6. Survivor-ref integrity: last_execution_id / last_finished_execution_id on
 *      a job that was NOT deleted remain unchanged after the bulk operation
 *
 * A file-backed sandbox CrontabManager (crontabDir parameter) is used so that
 * no real system crontab is read or written during the tests.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Audit\AuditLogger;
use Cronmanager\Agent\Cron\CrontabManager;
use Cronmanager\Agent\Endpoints\CronBulkDeleteEndpoint;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;

final class CronBulkDeleteEndpointTest extends AgentEndpointTestCase
{
    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    /**
     * CronBulkDeleteEndpoint calls beginTransaction() internally.
     * PDO throws "There is already an active transaction" when the test
     * framework's outer transaction is active at the same time.
     * Disable automatic transaction isolation and manage cleanup manually.
     */
    protected bool $useTransactionIsolation = false;

    private string $crontabDir;
    private CrontabManager $crontabMgr;

    /** @var int[] Job IDs inserted by this test that must be deleted in tearDown */
    private array $seedJobIds = [];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedJobIds = [];

        $this->crontabDir = sys_get_temp_dir() . '/cm-test-bulkdel-' . uniqid();
        mkdir($this->crontabDir, 0700, true);

        $this->crontabMgr = new CrontabManager(
            $this->createNullLogger(),
            '/dev/null',
            $this->crontabDir,
        );
    }

    protected function tearDown(): void
    {
        // Manual cleanup: delete any seeded jobs that the endpoint did not delete
        // (execution_log rows cascade-delete via fk_el_cronjob ON DELETE CASCADE)
        if ($this->seedJobIds !== [] && isset($this->pdo)) {
            $placeholders = implode(',', array_fill(0, count($this->seedJobIds), '?'));
            $this->pdo->prepare("DELETE FROM cronjobs WHERE id IN ({$placeholders})")
                      ->execute($this->seedJobIds);
        }

        $this->rmdirRecursive($this->crontabDir);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Seed a job and track its ID for manual tearDown cleanup.
     *
     * @param array<string, mixed> $overrides
     */
    protected function seedJob(array $overrides = []): int
    {
        $id = parent::seedJob($overrides);
        $this->seedJobIds[] = $id;
        return $id;
    }

    private function makeEndpoint(): CronBulkDeleteEndpoint
    {
        $audit = new AuditLogger($this->pdo, $this->createNullLogger(), 0, '', '127.0.0.1');

        return new CronBulkDeleteEndpoint(
            $this->pdo,
            $this->createNullLogger(),
            $this->crontabMgr,
            $audit,
        );
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private function rmdirRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->rmdirRecursive($full) : unlink($full);
        }
        rmdir($path);
    }

    /**
     * Assert whether a cronjobs row exists for the given ID.
     */
    private function jobExists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cronjobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Fetch last_execution_id and last_finished_execution_id for a job.
     *
     * @return array{last_execution_id: int|null, last_finished_execution_id: int|null}
     */
    private function fetchRefs(int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT last_execution_id, last_finished_execution_id FROM cronjobs WHERE id = :id'
        );
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'last_execution_id'          => $row['last_execution_id'] !== null ? (int) $row['last_execution_id'] : null,
            'last_finished_execution_id' => $row['last_finished_execution_id'] !== null ? (int) $row['last_finished_execution_id'] : null,
        ];
    }

    // =========================================================================
    // 1. 400 – empty IDs array
    // =========================================================================

    #[Test]
    public function rejectsEmptyIdsArray(): void
    {
        $this->callHandle($this->makeEndpoint(), ['ids' => []]);

        $this->assertStatus(400);
    }

    // =========================================================================
    // 2. 404 – batch contains unknown job ID
    // =========================================================================

    #[Test]
    public function rejectsWhenAnyIdDoesNotExist(): void
    {
        $jobId = $this->seedJob();

        $this->callHandle($this->makeEndpoint(), ['ids' => [$jobId, 999999]]);

        $this->assertStatus(404);
    }

    // =========================================================================
    // 3. 409 – running execution blocks entire batch (atomic guard)
    // =========================================================================

    #[Test]
    public function rejectsEntireBatchWhenAnyJobIsRunning(): void
    {
        $runningJob  = $this->seedJob(['description' => 'Running job']);
        $idleJob     = $this->seedJob(['description' => 'Idle job']);

        $this->seedRunningExecution($runningJob);

        $this->callHandle($this->makeEndpoint(), ['ids' => [$runningJob, $idleJob]]);

        $this->assertStatus(409);

        // Atomicity: the idle job must NOT have been deleted (no partial delete)
        $this->assertTrue($this->jobExists($runningJob), 'Running job must still exist after 409');
        $this->assertTrue($this->jobExists($idleJob),   'Idle job must still exist after 409 (no partial delete)');
    }

    // =========================================================================
    // 4. 200 – happy path: multiple jobs deleted
    // =========================================================================

    #[Test]
    public function deletesAllRequestedJobsAndReturnsCount(): void
    {
        $jobA = $this->seedJob(['description' => 'Job A']);
        $jobB = $this->seedJob(['description' => 'Job B']);

        $this->callHandle($this->makeEndpoint(), ['ids' => [$jobA, $jobB]]);

        $this->assertStatus(200);
        $this->assertBodyHas('deleted', 2);

        $this->assertFalse($this->jobExists($jobA), 'Job A must be deleted');
        $this->assertFalse($this->jobExists($jobB), 'Job B must be deleted');
    }

    // =========================================================================
    // 5. ON DELETE CASCADE: execution_log rows removed with their job
    // =========================================================================

    #[Test]
    public function executionLogRowsCascadeDeleteWithJob(): void
    {
        $jobId = $this->seedJob();
        $this->seedFinishedExecution($jobId);
        $this->seedFinishedExecution($jobId);

        $this->assertSame(2, $this->countExecutions($jobId), 'Pre-condition: 2 execution rows');

        $this->callHandle($this->makeEndpoint(), ['ids' => [$jobId]]);

        $this->assertStatus(200);

        // execution_log rows must be gone via ON DELETE CASCADE (fk_el_cronjob)
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM execution_log WHERE cronjob_id = :id');
        $stmt->execute([':id' => $jobId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'execution_log rows must cascade-delete with the job');
    }

    // =========================================================================
    // 6. Survivor-ref integrity: refs on a non-deleted job are unchanged
    // =========================================================================

    #[Test]
    public function survivorJobRefsAreNotAffectedByBulkDelete(): void
    {
        $survivorJob  = $this->seedJob(['description' => 'Survivor']);
        $deletedJobA  = $this->seedJob(['description' => 'Delete A']);
        $deletedJobB  = $this->seedJob(['description' => 'Delete B']);

        // Give the survivor job a finished execution and set both ref columns
        $execId = $this->seedFinishedExecution($survivorJob, ['exit_code' => 0]);
        $this->pdo->prepare(
            'UPDATE cronjobs SET last_execution_id = :e, last_finished_execution_id = :f WHERE id = :id'
        )->execute([':e' => $execId, ':f' => $execId, ':id' => $survivorJob]);

        // Delete only the two non-survivor jobs
        $this->callHandle($this->makeEndpoint(), ['ids' => [$deletedJobA, $deletedJobB]]);

        $this->assertStatus(200);
        $this->assertBodyHas('deleted', 2);

        // Survivor must still exist …
        $this->assertTrue($this->jobExists($survivorJob), 'Survivor job must still exist');

        // … and its ref columns must be unchanged
        $refs = $this->fetchRefs($survivorJob);
        $this->assertSame(
            $execId,
            $refs['last_execution_id'],
            'Bulk delete must not modify last_execution_id on non-deleted jobs'
        );
        $this->assertSame(
            $execId,
            $refs['last_finished_execution_id'],
            'Bulk delete must not modify last_finished_execution_id on non-deleted jobs'
        );
    }
}
