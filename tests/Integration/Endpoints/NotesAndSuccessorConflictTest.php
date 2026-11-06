<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: Notes field and successor-conflict (409) logic
 *
 * Covers v7.2.0 invariants:
 *
 *   1. `notes` field stored and returned by PUT /crons/{id}
 *   2. `notes` cleared when explicitly set to null
 *   3. CronUpdateEndpoint returns 409 when deactivating a job with active successors
 *   4. CronUpdateEndpoint accepts force:true to bypass the 409
 *   5. CronUpdateEndpoint accepts cascade:true to also deactivate successors
 *   6. CronBulkStatusEndpoint returns 409 when any selected job has active successors
 *   7. CronBulkStatusEndpoint accepts force:true for bulk deactivation
 *
 * Both endpoints call beginTransaction() internally, so transaction isolation is
 * disabled; cleanup is performed manually in tearDown().
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Audit\AuditLogger;
use Cronmanager\Agent\Cron\CrontabManager;
use Cronmanager\Agent\Endpoints\CronBulkStatusEndpoint;
use Cronmanager\Agent\Endpoints\CronUpdateEndpoint;
use Cronmanager\Agent\Repository\DependencyRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;
use Tests\Support\AgentResponse;
use Tests\Support\PhpInputStream;

final class NotesAndSuccessorConflictTest extends AgentEndpointTestCase
{
    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    protected bool $useTransactionIsolation = false;

    private string $crontabDir = '';
    private CrontabManager $crontabMgr;

    /** @var int[] */
    private array $createdJobIds = [];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->createdJobIds = [];

        $this->crontabDir = sys_get_temp_dir() . '/cm-test-notes-' . uniqid();
        mkdir($this->crontabDir, 0700, true);

        $this->crontabMgr = new CrontabManager(
            $this->createNullLogger(),
            '/dev/null',
            $this->crontabDir,
        );
    }

    protected function tearDown(): void
    {
        if ($this->createdJobIds !== [] && isset($this->pdo)) {
            $placeholders = implode(',', array_fill(0, count($this->createdJobIds), '?'));
            $this->pdo->prepare(
                "DELETE FROM job_dependencies WHERE predecessor_id IN ({$placeholders}) OR job_id IN ({$placeholders})"
            )->execute(array_merge($this->createdJobIds, $this->createdJobIds));
            $this->pdo->prepare("DELETE FROM cronjobs WHERE id IN ({$placeholders})")
                      ->execute($this->createdJobIds);
        }

        if ($this->crontabDir !== '' && is_dir($this->crontabDir)) {
            foreach (glob($this->crontabDir . '/*') ?: [] as $f) { unlink($f); }
            rmdir($this->crontabDir);
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUpdateEndpoint(): CronUpdateEndpoint
    {
        return new CronUpdateEndpoint(
            pdo:            $this->pdo,
            logger:         $this->createNullLogger(),
            crontabManager: $this->crontabMgr,
            wrapperScript:  '/dev/null',
            audit:          new AuditLogger($this->pdo, $this->createNullLogger(), 0, '', '127.0.0.1'),
            deps:           new DependencyRepository($this->pdo),
        );
    }

    private function makeBulkStatusEndpoint(): CronBulkStatusEndpoint
    {
        return new CronBulkStatusEndpoint(
            pdo:            $this->pdo,
            logger:         $this->createNullLogger(),
            crontabManager: $this->crontabMgr,
            wrapperScript:  '/dev/null',
            audit:          new AuditLogger($this->pdo, $this->createNullLogger(), 0, '', '127.0.0.1'),
            deps:           new DependencyRepository($this->pdo),
        );
    }

    private function seedActiveJob(array $overrides = []): int
    {
        $id = $this->seedJob(array_merge(['active' => 1, 'schedule' => '* * * * *'], $overrides));
        $this->seedJobTarget($id, 'local');
        $this->createdJobIds[] = $id;
        return $id;
    }

    /** Insert a job_dependencies row (successor depends on predecessor). */
    private function seedDependency(int $predecessorId, int $successorId, string $type = 'requires'): void
    {
        $this->pdo->prepare(
            "INSERT INTO job_dependencies (predecessor_id, job_id, type, exit_codes)
             VALUES (:pred, :job, :type, '0')"
        )->execute(['pred' => $predecessorId, 'job' => $successorId, 'type' => $type]);
    }

    private function fetchJobNotes(int $jobId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT notes FROM cronjobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $row['notes'] : null;
    }

    private function fetchJobActive(int $jobId): int
    {
        $stmt = $this->pdo->prepare('SELECT active FROM cronjobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int) ($row['active'] ?? -1);
    }

    // =========================================================================
    // 1. notes field stored and returned
    // =========================================================================

    #[Test]
    public function updateJobStoresAndReturnsNotes(): void
    {
        $jobId = $this->seedActiveJob(['active' => 0]);

        AgentResponse::reset();
        PhpInputStream::set(json_encode(['notes' => 'This is a test note.']) ?: '');
        $this->makeUpdateEndpoint()->handle(['id' => (string) $jobId]);
        PhpInputStream::restore();

        $this->assertStatus(200);
        $body = AgentResponse::$body ?? [];
        $this->assertArrayHasKey('notes', $body);
        $this->assertSame('This is a test note.', $body['notes']);

        $this->assertSame('This is a test note.', $this->fetchJobNotes($jobId));
    }

    // =========================================================================
    // 2. notes cleared when set to null
    // =========================================================================

    #[Test]
    public function updateJobClearsNotesWhenSetToNull(): void
    {
        $jobId = $this->seedActiveJob(['active' => 0, 'notes' => 'existing note']);

        AgentResponse::reset();
        PhpInputStream::set(json_encode(['notes' => null]) ?: '');
        $this->makeUpdateEndpoint()->handle(['id' => (string) $jobId]);
        PhpInputStream::restore();

        $this->assertStatus(200);
        $this->assertNull($this->fetchJobNotes($jobId));
    }

    // =========================================================================
    // 3. 409 Conflict when deactivating a job with active successors
    // =========================================================================

    #[Test]
    public function deactivatingJobWithActiveSuccessorReturns409(): void
    {
        $predId = $this->seedActiveJob();
        $succId = $this->seedActiveJob();
        $this->seedDependency($predId, $succId);

        AgentResponse::reset();
        PhpInputStream::set(json_encode(['active' => false]) ?: '');
        $this->makeUpdateEndpoint()->handle(['id' => (string) $predId]);
        PhpInputStream::restore();

        $this->assertStatus(409);
        $body = AgentResponse::$body ?? [];
        $this->assertArrayHasKey('affected_successors', $body);
        $this->assertNotEmpty($body['affected_successors']);

        // Job must still be active after the 409
        $this->assertSame(1, $this->fetchJobActive($predId));
    }

    // =========================================================================
    // 4. force:true bypasses the 409
    // =========================================================================

    #[Test]
    public function deactivatingJobWithForceDeactivatesOnlyTheJob(): void
    {
        $predId = $this->seedActiveJob();
        $succId = $this->seedActiveJob();
        $this->seedDependency($predId, $succId);

        AgentResponse::reset();
        PhpInputStream::set(json_encode(['active' => false, 'force' => true]) ?: '');
        $this->makeUpdateEndpoint()->handle(['id' => (string) $predId]);
        PhpInputStream::restore();

        $this->assertStatus(200);

        // Predecessor deactivated, successor still active
        $this->assertSame(0, $this->fetchJobActive($predId));
        $this->assertSame(1, $this->fetchJobActive($succId));
    }

    // =========================================================================
    // 5. cascade:true deactivates job AND all successors
    // =========================================================================

    #[Test]
    public function deactivatingJobWithCascadeDeactivatesAllSuccessors(): void
    {
        $predId  = $this->seedActiveJob();
        $succId1 = $this->seedActiveJob();
        $succId2 = $this->seedActiveJob();
        $this->seedDependency($predId, $succId1);
        $this->seedDependency($succId1, $succId2);

        AgentResponse::reset();
        PhpInputStream::set(json_encode(['active' => false, 'cascade' => true]) ?: '');
        $this->makeUpdateEndpoint()->handle(['id' => (string) $predId]);
        PhpInputStream::restore();

        $this->assertStatus(200);

        $this->assertSame(0, $this->fetchJobActive($predId));
        $this->assertSame(0, $this->fetchJobActive($succId1));
        $this->assertSame(0, $this->fetchJobActive($succId2));
    }

    // =========================================================================
    // 6. CronBulkStatusEndpoint returns 409 when job has active successors
    // =========================================================================

    #[Test]
    public function bulkDeactivateWithActiveSuccessorReturns409(): void
    {
        $predId = $this->seedActiveJob();
        $succId = $this->seedActiveJob();
        $this->seedDependency($predId, $succId);

        AgentResponse::reset();
        PhpInputStream::set(json_encode(['ids' => [$predId], 'active' => false]) ?: '');
        $this->makeBulkStatusEndpoint()->handle([]);
        PhpInputStream::restore();

        $this->assertStatus(409);
        $body = AgentResponse::$body ?? [];
        $this->assertArrayHasKey('affected_jobs', $body);
    }

    // =========================================================================
    // 7. CronBulkStatusEndpoint accepts force:true
    // =========================================================================

    #[Test]
    public function bulkDeactivateWithForceSucceeds(): void
    {
        $predId = $this->seedActiveJob();
        $succId = $this->seedActiveJob();
        $this->seedDependency($predId, $succId);

        AgentResponse::reset();
        PhpInputStream::set(json_encode(['ids' => [$predId], 'active' => false, 'force' => true]) ?: '');
        $this->makeBulkStatusEndpoint()->handle([]);
        PhpInputStream::restore();

        $this->assertStatus(200);
        $this->assertSame(0, $this->fetchJobActive($predId));
        $this->assertSame(1, $this->fetchJobActive($succId));
    }
}
