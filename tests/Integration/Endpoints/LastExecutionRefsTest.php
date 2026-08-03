<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: last-execution reference maintenance
 *
 * Migration 018 denormalises two reference columns onto cronjobs:
 *   last_execution_id          – newest execution_log row for the job
 *   last_finished_execution_id – newest row with finished_at set
 *
 * These tests verify that the write path keeps the references correct:
 *   - ExecutionStartEndpoint sets last_execution_id on a real start
 *   - ExecutionFinishEndpoint sets last_finished_execution_id on finish
 *   - the monotonic guard keeps the newest finished pointer when an older
 *     execution finishes late (out-of-order finish of overlapping runs)
 *   - MaintenanceDeleteExecutionEndpoint re-derives both references for the
 *     affected job after a row is deleted
 *
 * Run with the test DB running:
 *   docker compose -f tests/docker-compose.test.yml up -d
 *   ./vendor/bin/phpunit --testsuite integration
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Cron\CrontabManager;
use Cronmanager\Agent\Endpoints\ExecutionFinishEndpoint;
use Cronmanager\Agent\Endpoints\ExecutionStartEndpoint;
use Cronmanager\Agent\Endpoints\MaintenanceDeleteExecutionEndpoint;
use Cronmanager\Agent\Notification\MailNotifier;
use Cronmanager\Agent\Notification\TelegramNotifier;
use Cronmanager\Agent\Repository\MaintenanceWindowRepository;
use Noodlehaus\ConfigInterface;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;
use Tests\Support\AgentResponse;

final class LastExecutionRefsTest extends AgentEndpointTestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeStartEndpoint(): ExecutionStartEndpoint
    {
        return new ExecutionStartEndpoint(
            $this->pdo,
            $this->createNullLogger(),
            new MaintenanceWindowRepository($this->pdo, $this->createNullLogger()),
        );
    }

    private function makeFinishEndpoint(): ExecutionFinishEndpoint
    {
        $configStub = $this->createStub(ConfigInterface::class);

        return new ExecutionFinishEndpoint(
            pdo:              $this->pdo,
            logger:           $this->createNullLogger(),
            mailNotifier:     new MailNotifier($this->createNullLogger(), $configStub),
            telegramNotifier: new TelegramNotifier($this->createNullLogger(), $configStub),
            crontabManager:   new CrontabManager($this->createNullLogger(), '/dev/null'),
            wrapperScript:    '/dev/null',
        );
    }

    /**
     * Fetch the two reference columns for a job.
     *
     * @return array{last_execution_id: int|null, last_finished_execution_id: int|null}
     */
    private function fetchRefs(int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT last_execution_id, last_finished_execution_id
               FROM cronjobs WHERE id = :id'
        );
        $stmt->execute([':id' => $jobId]);
        /** @var array{last_execution_id: string|null, last_finished_execution_id: string|null} $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'last_execution_id'          => $row['last_execution_id'] !== null ? (int) $row['last_execution_id'] : null,
            'last_finished_execution_id' => $row['last_finished_execution_id'] !== null ? (int) $row['last_finished_execution_id'] : null,
        ];
    }

    // =========================================================================
    // 1. Start path
    // =========================================================================

    #[Test]
    public function startSetsLastExecutionId(): void
    {
        $jobId = $this->seedJob();

        $this->callHandle($this->makeStartEndpoint(), [
            'job_id'     => $jobId,
            'started_at' => '2026-01-15T10:00:00Z',
            'target'     => 'local',
        ]);

        $this->assertStatus(201);
        $executionId = (int) (AgentResponse::$body['execution_id'] ?? 0);

        $refs = $this->fetchRefs($jobId);
        $this->assertSame($executionId, $refs['last_execution_id']);
        $this->assertNull($refs['last_finished_execution_id']);
    }

    #[Test]
    public function secondStartMovesLastExecutionIdForward(): void
    {
        $jobId = $this->seedJob();

        $this->callHandle($this->makeStartEndpoint(), [
            'job_id'     => $jobId,
            'started_at' => '2026-01-15T10:00:00Z',
        ]);
        $first = (int) (AgentResponse::$body['execution_id'] ?? 0);

        $this->callHandle($this->makeStartEndpoint(), [
            'job_id'     => $jobId,
            'started_at' => '2026-01-15T10:05:00Z',
        ]);
        $second = (int) (AgentResponse::$body['execution_id'] ?? 0);

        $this->assertGreaterThan($first, $second);
        $this->assertSame($second, $this->fetchRefs($jobId)['last_execution_id']);
    }

    // =========================================================================
    // 2. Finish path
    // =========================================================================

    #[Test]
    public function finishSetsLastFinishedExecutionId(): void
    {
        $jobId       = $this->seedJob();
        $executionId = $this->seedRunningExecution($jobId);

        $this->callHandle($this->makeFinishEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 0,
            'finished_at'  => '2026-01-15T10:00:05Z',
        ]);

        $this->assertStatus(200);
        $this->assertSame($executionId, $this->fetchRefs($jobId)['last_finished_execution_id']);
    }

    #[Test]
    public function lateFinishOfOlderExecutionDoesNotMovePointerBackwards(): void
    {
        $jobId  = $this->seedJob();
        $older  = $this->seedRunningExecution($jobId, ['started_at' => '2026-01-15 10:00:00']);
        $newer  = $this->seedRunningExecution($jobId, ['started_at' => '2026-01-15 10:01:00']);

        // The newer execution finishes first …
        $this->callHandle($this->makeFinishEndpoint(), [
            'execution_id' => $newer,
            'job_id'       => $jobId,
            'exit_code'    => 0,
            'finished_at'  => '2026-01-15T10:02:00Z',
        ]);
        $this->assertStatus(200);
        $this->assertSame($newer, $this->fetchRefs($jobId)['last_finished_execution_id']);

        // … then the older one reports its (late) finish. The monotonic guard
        // must keep the pointer on the newer row.
        $this->callHandle($this->makeFinishEndpoint(), [
            'execution_id' => $older,
            'job_id'       => $jobId,
            'exit_code'    => 1,
            'finished_at'  => '2026-01-15T10:03:00Z',
        ]);
        $this->assertStatus(200);
        $this->assertSame($newer, $this->fetchRefs($jobId)['last_finished_execution_id']);
    }

    // =========================================================================
    // 3. Delete path
    // =========================================================================

    #[Test]
    public function deletingLatestExecutionRedirectsReferencesToPreviousRow(): void
    {
        $jobId = $this->seedJob();

        $first  = $this->seedRunningExecution($jobId, [
            'started_at'  => '2026-01-15 10:00:00',
            'finished_at' => '2026-01-15 10:00:05',
            'exit_code'   => 0,
        ]);
        $second = $this->seedRunningExecution($jobId, [
            'started_at'  => '2026-01-15 10:05:00',
            'finished_at' => '2026-01-15 10:05:05',
            'exit_code'   => 1,
        ]);

        // Point both references at the newest row (as the write path would)
        $this->pdo->prepare(
            'UPDATE cronjobs SET last_execution_id = :e, last_finished_execution_id = :f WHERE id = :id'
        )->execute([':e' => $second, ':f' => $second, ':id' => $jobId]);

        $endpoint = new MaintenanceDeleteExecutionEndpoint($this->pdo, $this->createNullLogger());
        AgentResponse::reset();
        $endpoint->handle(['id' => (string) $second]);

        $this->assertStatus(200);

        $refs = $this->fetchRefs($jobId);
        $this->assertSame($first, $refs['last_execution_id']);
        $this->assertSame($first, $refs['last_finished_execution_id']);
    }

    #[Test]
    public function deletingOnlyExecutionClearsBothReferences(): void
    {
        $jobId = $this->seedJob();
        $only  = $this->seedRunningExecution($jobId, [
            'started_at'  => '2026-01-15 10:00:00',
            'finished_at' => '2026-01-15 10:00:05',
            'exit_code'   => 0,
        ]);

        $this->pdo->prepare(
            'UPDATE cronjobs SET last_execution_id = :e, last_finished_execution_id = :f WHERE id = :id'
        )->execute([':e' => $only, ':f' => $only, ':id' => $jobId]);

        $endpoint = new MaintenanceDeleteExecutionEndpoint($this->pdo, $this->createNullLogger());
        AgentResponse::reset();
        $endpoint->handle(['id' => (string) $only]);

        $this->assertStatus(200);

        $refs = $this->fetchRefs($jobId);
        $this->assertNull($refs['last_execution_id']);
        $this->assertNull($refs['last_finished_execution_id']);
    }
}
