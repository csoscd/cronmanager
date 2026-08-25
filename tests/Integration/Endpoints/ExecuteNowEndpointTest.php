<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: ExecuteNowEndpoint
 *
 * Covers the correctness invariants of POST /crons/{id}/execute:
 *
 *   1. Rejects an invalid (zero) job ID (HTTP 400)
 *   2. Rejects a non-existent job ID (HTTP 404)
 *   3. Rejects a duplicate "Run Now" request when a once-only entry is already
 *      pending for the job+target (HTTP 409 – double-click guard)
 *   4. Auto-cleans a stale once-entry (past schedule, no running execution) and
 *      schedules a fresh run (HTTP 200) — self-healing after failed cleanup
 *   5. Schedules a once-only crontab entry for the next minute and returns the
 *      expected JSON payload (HTTP 200); the entry is verified by direct
 *      inspection of the sandbox crontab file (not via the cached reader)
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
use Cronmanager\Agent\Endpoints\ExecuteNowEndpoint;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;
use Tests\Support\AgentResponse;

final class ExecuteNowEndpointTest extends AgentEndpointTestCase
{
    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    private string $crontabDir = '';
    private CrontabManager $crontabMgr;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->crontabDir = sys_get_temp_dir() . '/cm-test-executenow-' . uniqid();
        mkdir($this->crontabDir, 0700, true);

        $this->crontabMgr = new CrontabManager(
            $this->createNullLogger(),
            '/dev/null',
            $this->crontabDir,
        );
    }

    protected function tearDown(): void
    {
        // Guard: $crontabDir may be empty when setUp() bailed out early (e.g. DB
        // unreachable → markTestSkipped() before the directory was created).
        if ($this->crontabDir !== '') {
            $this->rmdirRecursive($this->crontabDir);
        }
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeEndpoint(): ExecuteNowEndpoint
    {
        $audit = new AuditLogger($this->pdo, $this->createNullLogger(), 0, '', '127.0.0.1');

        return new ExecuteNowEndpoint(
            $this->pdo,
            $this->createNullLogger(),
            $this->crontabMgr,
            '/dev/null',
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
     * Read the raw sandbox crontab content for the given Linux user.
     */
    private function readSandboxCrontab(string $user): string
    {
        $file = $this->crontabDir . '/' . $user . '.crontab';
        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    // =========================================================================
    // 1. 400 – invalid (zero) job ID
    // =========================================================================

    #[Test]
    public function rejectsInvalidJobId(): void
    {
        AgentResponse::reset();
        $this->makeEndpoint()->handle(['id' => '0']);

        $this->assertStatus(400);
    }

    // =========================================================================
    // 2. 404 – job not found
    // =========================================================================

    #[Test]
    public function rejectsNonExistentJob(): void
    {
        AgentResponse::reset();
        $this->makeEndpoint()->handle(['id' => '999999']);

        $this->assertStatus(404);
    }

    // =========================================================================
    // 3. 409 – duplicate "Run Now" rejected (double-click guard)
    // =========================================================================

    #[Test]
    public function rejectsDuplicateRunNowWhenOnceEntryAlreadyPending(): void
    {
        $jobId = $this->seedJob(['linux_user' => 'root']);
        $this->seedJobTarget($jobId, 'local');

        // Pre-populate the sandbox crontab with a once-only entry for this job+target.
        // Using a distant full-date schedule (31 Dec 23:59) so the entry never fires.
        $this->crontabMgr->addOnceEntry('root', $jobId, '59 23 31 12 *', '/dev/null', 'local');

        // A second "Run Now" request must be rejected
        AgentResponse::reset();
        $this->makeEndpoint()->handle(['id' => (string) $jobId]);

        $this->assertStatus(409);
    }

    // =========================================================================
    // 4. 200 – stale once-entry auto-cleaned when job is not running
    //
    //    Scenario: a previous "Run Now" fired (exit code ≠ 0) but the cleanup
    //    call from cron-wrapper failed.  The once-entry has a past schedule and
    //    the job has a finished execution (finished_at IS NOT NULL).  The endpoint
    //    must remove the stale marker and schedule a fresh execution (HTTP 200).
    // =========================================================================

    #[Test]
    public function autoCleansStalePendingEntryAndSchedulesNewRun(): void
    {
        $jobId = $this->seedJob(['linux_user' => 'root']);
        $this->seedJobTarget($jobId, 'local');

        // Simulate a finished execution (cleanup step failed to remove the marker)
        $this->seedFinishedExecution($jobId, ['exit_code' => 126]);

        // Insert a once-entry with a schedule 1 hour in the past
        $pastTime    = new \DateTimeImmutable('-1 hour');
        $pastSchedule = sprintf(
            '%d %d %d %d *',
            (int) $pastTime->format('i'),
            (int) $pastTime->format('G'),
            (int) $pastTime->format('j'),
            (int) $pastTime->format('n')
        );
        $this->crontabMgr->addOnceEntry('root', $jobId, $pastSchedule, '/dev/null', 'local');

        // Verify the stale marker is present before the request
        $this->assertTrue(
            $this->crontabMgr->hasOnceEntry('root', $jobId, 'local'),
            'Pre-condition: stale once-entry must be present in sandbox crontab'
        );

        AgentResponse::reset();
        $this->makeEndpoint()->handle(['id' => (string) $jobId]);

        // Endpoint must auto-clean and return 200 (not 409)
        $this->assertStatus(200);

        $body = AgentResponse::$body ?? [];
        $this->assertSame($jobId, $body['job_id'] ?? null, 'Response must contain the job_id');
        $this->assertSame(['local'], $body['targets'] ?? null, 'Response must list the targets');

        // The sandbox crontab must now contain the fresh once-entry (not the stale one)
        $raw = $this->readSandboxCrontab('root');
        $this->assertStringContainsString(
            '# cronmanager-once:' . $jobId . ':local',
            $raw,
            'Fresh once-entry marker must be present after auto-clean'
        );
        // Stale schedule must be gone; fresh schedule must differ from the past one
        $this->assertStringNotContainsString(
            $pastSchedule . ' /dev/null',
            $raw,
            'Stale crontab command line must have been replaced'
        );
    }

    // =========================================================================
    // 6. 200 – once-entry scheduled; verified via direct sandbox file inspection
    // =========================================================================

    #[Test]
    public function schedulesOnceEntryAndReturnsCronExpression(): void
    {
        $jobId = $this->seedJob(['linux_user' => 'root']);
        $this->seedJobTarget($jobId, 'local');

        AgentResponse::reset();
        $this->makeEndpoint()->handle(['id' => (string) $jobId]);

        $this->assertStatus(200);

        $body = AgentResponse::$body ?? [];
        $this->assertSame($jobId, $body['job_id'] ?? null, 'Response must contain the job_id');
        $this->assertArrayHasKey('schedule', $body, 'Response must contain a schedule');
        $this->assertSame(['local'], $body['targets'] ?? null, 'Response must list the targets');

        // Verify the once-entry marker is present in the sandbox crontab file
        // (direct file read – bypasses the getManagedEntriesCached cache, which
        // is keyed globally and could be stale when two tests share the same user).
        $raw = $this->readSandboxCrontab('root');
        $this->assertStringContainsString(
            '# cronmanager-once:' . $jobId . ':local',
            $raw,
            'Once-entry marker must be present in the sandbox crontab after scheduling'
        );
        $this->assertStringContainsString(
            (string) $jobId,
            $raw,
            'Job ID must appear in the crontab entry'
        );
    }
}
