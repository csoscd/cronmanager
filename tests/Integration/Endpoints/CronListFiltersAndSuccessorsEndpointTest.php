<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: CronListEndpoint server-side filters
 *                                   and SuccessorsEndpoint
 *
 * Covers v7.2.0 invariants:
 *
 *   CronListEndpoint:
 *     1. ?q= filters by description
 *     2. ?q= filters by notes (new field)
 *     3. ?active=0 returns only inactive jobs
 *     4. ?active=1 returns only active jobs
 *     5. ?exec_type=schedule returns only jobs without a dependency row
 *     6. ?exec_type=requires returns only jobs with a requires dependency
 *     7. Combining ?q= and ?active= narrows the result set
 *
 *   SuccessorsEndpoint:
 *     8. GET /crons/{id}/successors returns has_active_successors=false when none
 *     9. GET /crons/{id}/successors returns has_active_successors=true with list
 *    10. POST /crons/bulk/successors returns has_active_successors=false for no-dep jobs
 *    11. POST /crons/bulk/successors returns has_active_successors=true with affected_jobs
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Cron\CrontabManager;
use Cronmanager\Agent\Endpoints\CronListEndpoint;
use Cronmanager\Agent\Endpoints\SuccessorsEndpoint;
use Cronmanager\Agent\Repository\DependencyRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;
use Tests\Support\AgentResponse;
use Tests\Support\PhpInputStream;

final class CronListFiltersAndSuccessorsEndpointTest extends AgentEndpointTestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCronList(): CronListEndpoint
    {
        return new CronListEndpoint(
            $this->pdo,
            $this->createNullLogger(),
            new CrontabManager($this->createNullLogger(), '/dev/null'),
            new DependencyRepository($this->pdo),
        );
    }

    private function makeSuccessors(): SuccessorsEndpoint
    {
        return new SuccessorsEndpoint(
            $this->pdo,
            $this->createNullLogger(),
            new DependencyRepository($this->pdo),
        );
    }

    private function seedActiveJob(array $overrides = []): int
    {
        $id = $this->seedJob(array_merge(['active' => 1, 'schedule' => '* * * * *'], $overrides));
        $this->seedJobTarget($id, 'local');
        return $id;
    }

    private function seedInactiveJob(array $overrides = []): int
    {
        $id = $this->seedJob(array_merge(['active' => 0, 'schedule' => '* * * * *'], $overrides));
        $this->seedJobTarget($id, 'local');
        return $id;
    }

    /** Set notes on a job directly in DB. */
    private function setNotes(int $jobId, string $notes): void
    {
        $this->pdo->prepare('UPDATE cronjobs SET notes = :n WHERE id = :id')
                  ->execute(['n' => $notes, 'id' => $jobId]);
    }

    /** Insert a job_dependencies row. */
    private function seedDependency(int $predecessorId, int $successorId, string $type = 'requires'): void
    {
        $this->pdo->prepare(
            "INSERT INTO job_dependencies (predecessor_id, job_id, type, exit_codes)
             VALUES (:pred, :job, :type, '0')"
        )->execute(['pred' => $predecessorId, 'job' => $successorId, 'type' => $type]);
    }

    private function getJobsData(): array
    {
        return AgentResponse::$body['data'] ?? [];
    }

    private function runListWith(array $get): void
    {
        $prev = $_GET;
        $_GET = $get;
        AgentResponse::reset();
        $this->makeCronList()->handle([]);
        $_GET = $prev;
    }

    // =========================================================================
    // CronListEndpoint: ?q= filter
    // =========================================================================

    #[Test]
    public function qFilterMatchesJobDescription(): void
    {
        $matchId = $this->seedActiveJob(['description' => 'unique-backup-job']);
        $otherId = $this->seedActiveJob(['description' => 'daily report']);

        $this->runListWith(['q' => 'unique-backup-job']);

        $this->assertSame(200, AgentResponse::$statusCode);
        $jobs = $this->getJobsData();
        $ids  = array_column($jobs, 'id');
        $this->assertContains($matchId, $ids);
        $this->assertNotContains($otherId, $ids);
    }

    #[Test]
    public function qFilterMatchesJobNotes(): void
    {
        $matchId = $this->seedActiveJob(['description' => 'job with note']);
        $this->setNotes($matchId, 'special-note-content-xyz');
        $otherId = $this->seedActiveJob(['description' => 'job without note']);

        $this->runListWith(['q' => 'special-note-content-xyz']);

        $jobs = $this->getJobsData();
        $ids  = array_column($jobs, 'id');
        $this->assertContains($matchId, $ids);
        $this->assertNotContains($otherId, $ids);
    }

    // =========================================================================
    // CronListEndpoint: ?active= filter
    // =========================================================================

    #[Test]
    public function activeZeroFilterReturnsOnlyInactiveJobs(): void
    {
        $activeId   = $this->seedActiveJob();
        $inactiveId = $this->seedInactiveJob();

        $this->runListWith(['active' => '0']);

        $jobs = $this->getJobsData();
        $ids  = array_column($jobs, 'id');
        $this->assertNotContains($activeId, $ids);
        $this->assertContains($inactiveId, $ids);
    }

    #[Test]
    public function activeOneFilterReturnsOnlyActiveJobs(): void
    {
        $activeId   = $this->seedActiveJob();
        $inactiveId = $this->seedInactiveJob();

        $this->runListWith(['active' => '1']);

        $jobs = $this->getJobsData();
        $ids  = array_column($jobs, 'id');
        $this->assertContains($activeId, $ids);
        $this->assertNotContains($inactiveId, $ids);
    }

    // =========================================================================
    // CronListEndpoint: ?exec_type= filter
    // =========================================================================

    #[Test]
    public function execTypeScheduleFilterReturnsOnlyNonDependencyJobs(): void
    {
        $scheduleId  = $this->seedActiveJob(['description' => 'plain schedule']);
        $requiresId  = $this->seedActiveJob(['schedule' => '* * * * *', 'description' => 'has requires dep']);
        $this->seedDependency($scheduleId, $requiresId, 'requires');

        $this->runListWith(['exec_type' => 'schedule']);

        $jobs = $this->getJobsData();
        $ids  = array_column($jobs, 'id');
        $this->assertContains($scheduleId, $ids);
        $this->assertNotContains($requiresId, $ids);
    }

    #[Test]
    public function execTypeRequiresFilterReturnsOnlyRequiresJobs(): void
    {
        $scheduleId = $this->seedActiveJob(['description' => 'plain schedule']);
        $requiresId = $this->seedActiveJob(['description' => 'has requires dep']);
        $this->seedDependency($scheduleId, $requiresId, 'requires');

        $this->runListWith(['exec_type' => 'requires']);

        $jobs = $this->getJobsData();
        $ids  = array_column($jobs, 'id');
        $this->assertContains($requiresId, $ids);
        $this->assertNotContains($scheduleId, $ids);
    }

    // =========================================================================
    // CronListEndpoint: combined filters
    // =========================================================================

    #[Test]
    public function combinedQAndActiveFilterNarrowsResults(): void
    {
        $matchId   = $this->seedActiveJob(['description' => 'combined-filter-test']);
        $inactId   = $this->seedInactiveJob(['description' => 'combined-filter-test']);
        $otherId   = $this->seedActiveJob(['description' => 'other job']);

        $this->runListWith(['q' => 'combined-filter-test', 'active' => '1']);

        $jobs = $this->getJobsData();
        $ids  = array_column($jobs, 'id');
        $this->assertContains($matchId, $ids);
        $this->assertNotContains($inactId, $ids);
        $this->assertNotContains($otherId, $ids);
    }

    // =========================================================================
    // SuccessorsEndpoint: GET /crons/{id}/successors
    // =========================================================================

    #[Test]
    public function successorsEndpointReturnsFalseWhenNoSuccessors(): void
    {
        $jobId = $this->seedActiveJob();

        AgentResponse::reset();
        $this->makeSuccessors()->handleSingle(['id' => (string) $jobId]);

        $this->assertSame(200, AgentResponse::$statusCode);
        $body = AgentResponse::$body ?? [];
        $this->assertFalse($body['has_active_successors']);
        $this->assertSame([], $body['successors']);
    }

    #[Test]
    public function successorsEndpointReturnsTrueWithSuccessorList(): void
    {
        $predId = $this->seedActiveJob();
        $succId = $this->seedActiveJob();
        $this->seedDependency($predId, $succId);

        AgentResponse::reset();
        $this->makeSuccessors()->handleSingle(['id' => (string) $predId]);

        $this->assertSame(200, AgentResponse::$statusCode);
        $body = AgentResponse::$body ?? [];
        $this->assertTrue($body['has_active_successors']);
        $this->assertCount(1, $body['successors']);
        $this->assertSame($succId, $body['successors'][0]['id']);
    }

    // =========================================================================
    // SuccessorsEndpoint: POST /crons/bulk/successors
    // =========================================================================

    #[Test]
    public function bulkSuccessorsReturnsFalseForNoDependencyJobs(): void
    {
        $jobId1 = $this->seedActiveJob();
        $jobId2 = $this->seedActiveJob();

        AgentResponse::reset();
        PhpInputStream::set(json_encode(['ids' => [$jobId1, $jobId2]]) ?: '');
        $this->makeSuccessors()->handleBulk([]);
        PhpInputStream::restore();

        $this->assertSame(200, AgentResponse::$statusCode);
        $body = AgentResponse::$body ?? [];
        $this->assertFalse($body['has_active_successors']);
    }

    #[Test]
    public function bulkSuccessorsReturnsTrueWithAffectedJobsList(): void
    {
        $predId = $this->seedActiveJob();
        $succId = $this->seedActiveJob();
        $this->seedDependency($predId, $succId);

        AgentResponse::reset();
        PhpInputStream::set(json_encode(['ids' => [$predId]]) ?: '');
        $this->makeSuccessors()->handleBulk([]);
        PhpInputStream::restore();

        $this->assertSame(200, AgentResponse::$statusCode);
        $body = AgentResponse::$body ?? [];
        $this->assertTrue($body['has_active_successors']);
        $this->assertNotEmpty($body['jobs']);

        $firstJob = $body['jobs'][0];
        $this->assertSame($predId, $firstJob['job_id']);
        $this->assertNotEmpty($firstJob['successors']);
    }
}
