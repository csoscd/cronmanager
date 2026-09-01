<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: HistoryEndpoint
 *
 * Verifies the v4.7.0 two-phase query redesign of GET /history against a real
 * MariaDB database: the paginated id-subquery must return the same rows, in
 * the same order (running first, then started_at DESC), with the same total
 * as the previous single-phase GROUP BY version – including all filters.
 *
 * Run with the test DB running:
 *   docker compose -f tests/docker-compose.test.yml up -d
 *   ./vendor/bin/phpunit --testsuite integration
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Endpoints\HistoryEndpoint;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;
use Tests\Support\AgentResponse;

final class HistoryEndpointTest extends AgentEndpointTestCase
{
    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Call GET /history with the given query parameters and return the body.
     *
     * @param array<string, string|int> $query Query parameters ($_GET).
     *
     * @return array<string, mixed> Decoded response body.
     */
    private function callHistory(array $query = []): array
    {
        $_GET = array_map(strval(...), $query);
        AgentResponse::reset();

        (new HistoryEndpoint($this->pdo, $this->createNullLogger()))->handle([]);

        $_GET = [];

        return AgentResponse::$body ?? [];
    }

    /**
     * Attach a tag to a job (creating the tag when needed).
     */
    private function seedTag(int $jobId, string $tagName): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO tags (name) VALUES (:n)')->execute([':n' => $tagName]);
        $idStmt = $this->pdo->prepare('SELECT id FROM tags WHERE name = :n');
        $idStmt->execute([':n' => $tagName]);
        $tagId = (int) $idStmt->fetchColumn();

        $this->pdo->prepare(
            'INSERT IGNORE INTO cronjob_tags (cronjob_id, tag_id) VALUES (:j, :t)'
        )->execute([':j' => $jobId, ':t' => $tagId]);
    }

    // =========================================================================
    // 1. Ordering and pagination
    // =========================================================================

    #[Test]
    public function runningExecutionsAreListedBeforeFinishedOnes(): void
    {
        $jobId = $this->seedJob();

        $finished = $this->seedRunningExecution($jobId, [
            'started_at'  => '2026-01-15 12:00:00',
            'finished_at' => '2026-01-15 12:00:05',
            'exit_code'   => 0,
        ]);
        // Older than the finished one – must still be listed first (running)
        $running = $this->seedRunningExecution($jobId, [
            'started_at' => '2026-01-15 10:00:00',
        ]);

        $body = $this->callHistory();

        $ids = array_column($body['data'] ?? [], 'execution_id');
        $this->assertSame([$running, $finished], array_map(intval(...), $ids));
    }

    #[Test]
    public function finishedExecutionsAreOrderedByStartedAtDescending(): void
    {
        $jobId = $this->seedJob();

        $older = $this->seedRunningExecution($jobId, [
            'started_at'  => '2026-01-15 08:00:00',
            'finished_at' => '2026-01-15 08:00:05',
            'exit_code'   => 0,
        ]);
        $newer = $this->seedRunningExecution($jobId, [
            'started_at'  => '2026-01-15 09:00:00',
            'finished_at' => '2026-01-15 09:00:05',
            'exit_code'   => 1,
        ]);

        $body = $this->callHistory();

        $ids = array_map(intval(...), array_column($body['data'] ?? [], 'execution_id'));
        $this->assertSame([$newer, $older], $ids);
    }

    #[Test]
    public function paginationSlicesResultAndReportsFullTotal(): void
    {
        $jobId = $this->seedJob();
        for ($i = 0; $i < 5; $i++) {
            $this->seedRunningExecution($jobId, [
                'started_at'  => sprintf('2026-01-15 0%d:00:00', $i),
                'finished_at' => sprintf('2026-01-15 0%d:00:05', $i),
                'exit_code'   => 0,
            ]);
        }

        $body = $this->callHistory(['limit' => 2, 'offset' => 2]);

        $this->assertSame(5, (int) ($body['total'] ?? -1));
        $this->assertCount(2, $body['data'] ?? []);
        // Page 2 of started_at DESC ordering: 02:00 and 01:00
        $this->assertSame('2026-01-15 02:00:00', (string) $body['data'][0]['started_at']);
        $this->assertSame('2026-01-15 01:00:00', (string) $body['data'][1]['started_at']);
    }

    // =========================================================================
    // 2. Filters
    // =========================================================================

    #[Test]
    public function statusFilterFailedReturnsOnlyNonZeroFinishedRows(): void
    {
        $jobId = $this->seedJob();
        $this->seedRunningExecution($jobId, [
            'started_at'  => '2026-01-15 08:00:00',
            'finished_at' => '2026-01-15 08:00:05',
            'exit_code'   => 0,
        ]);
        $failed = $this->seedRunningExecution($jobId, [
            'started_at'  => '2026-01-15 09:00:00',
            'finished_at' => '2026-01-15 09:00:05',
            'exit_code'   => 2,
        ]);
        $this->seedRunningExecution($jobId, ['started_at' => '2026-01-15 10:00:00']);

        $body = $this->callHistory(['status' => 'failed']);

        $this->assertSame(1, (int) ($body['total'] ?? -1));
        $this->assertSame($failed, (int) $body['data'][0]['execution_id']);
    }

    #[Test]
    public function userFilterMatchesOnlyJobsOfThatLinuxUser(): void
    {
        $deployJob = $this->seedJob(['linux_user' => 'deploy']);
        $rootJob   = $this->seedJob(['linux_user' => 'root']);

        $deployExec = $this->seedRunningExecution($deployJob, [
            'started_at'  => '2026-01-15 08:00:00',
            'finished_at' => '2026-01-15 08:00:05',
            'exit_code'   => 0,
        ]);
        $this->seedRunningExecution($rootJob, [
            'started_at'  => '2026-01-15 09:00:00',
            'finished_at' => '2026-01-15 09:00:05',
            'exit_code'   => 0,
        ]);

        $body = $this->callHistory(['user' => 'deploy']);

        $this->assertSame(1, (int) ($body['total'] ?? -1));
        $this->assertSame($deployExec, (int) $body['data'][0]['execution_id']);
    }

    #[Test]
    public function tagFilterMatchesOnlyTaggedJobs(): void
    {
        $taggedJob   = $this->seedJob(['description' => 'tagged']);
        $untaggedJob = $this->seedJob(['description' => 'untagged']);
        $this->seedTag($taggedJob, 'backup');

        $taggedExec = $this->seedRunningExecution($taggedJob, [
            'started_at'  => '2026-01-15 08:00:00',
            'finished_at' => '2026-01-15 08:00:05',
            'exit_code'   => 0,
        ]);
        $this->seedRunningExecution($untaggedJob, [
            'started_at'  => '2026-01-15 09:00:00',
            'finished_at' => '2026-01-15 09:00:05',
            'exit_code'   => 0,
        ]);

        $body = $this->callHistory(['tag' => 'backup']);

        $this->assertSame(1, (int) ($body['total'] ?? -1));
        $this->assertSame($taggedExec, (int) $body['data'][0]['execution_id']);
        $this->assertSame(['backup'], $body['data'][0]['tags']);
    }

    #[Test]
    public function multipleTagsOnOneJobDoNotDuplicateRowsOrInflateTotal(): void
    {
        $jobId = $this->seedJob();
        $this->seedTag($jobId, 'alpha');
        $this->seedTag($jobId, 'beta');

        $this->seedRunningExecution($jobId, [
            'started_at'  => '2026-01-15 08:00:00',
            'finished_at' => '2026-01-15 08:00:05',
            'exit_code'   => 0,
        ]);

        $body = $this->callHistory();

        $this->assertSame(1, (int) ($body['total'] ?? -1));
        $this->assertCount(1, $body['data'] ?? []);
        $this->assertSame(['alpha', 'beta'], $body['data'][0]['tags']);
    }

    #[Test]
    public function outputColumnSurvivesTheTwoPhaseQuery(): void
    {
        $jobId  = $this->seedJob();
        $output = str_repeat('Lorem ipsum dolor sit amet. ', 200);

        $this->seedRunningExecution($jobId, [
            'started_at'  => '2026-01-15 08:00:00',
            'finished_at' => '2026-01-15 08:00:05',
            'exit_code'   => 0,
            'output'      => $output,
        ]);

        $body = $this->callHistory();

        $this->assertSame($output, (string) $body['data'][0]['output']);
    }

    #[Test]
    public function unacknowledgedOnlyExcludesAcknowledgedExecutions(): void
    {
        $jobId = $this->seedJob();

        // Unacknowledged failure – must appear
        $this->seedFinishedExecution($jobId, [
            'exit_code'        => 1,
            'started_at'       => '2026-01-15 08:00:00',
            'acknowledged_at'  => null,
        ]);

        // Acknowledged failure – must be excluded
        $this->seedFinishedExecution($jobId, [
            'exit_code'       => 2,
            'started_at'      => '2026-01-15 09:00:00',
            'acknowledged_at' => '2026-01-15 09:05:00',
        ]);

        $body = $this->callHistory(['status' => 'failed', 'unacknowledged_only' => '1']);

        $this->assertSame(1, (int) ($body['total'] ?? -1));
        $this->assertCount(1, $body['data'] ?? []);
        $this->assertSame(1, (int) $body['data'][0]['exit_code']);
        $this->assertNull($body['data'][0]['acknowledged_at']);
    }

    #[Test]
    public function unacknowledgedOnlyZeroOrAbsentDoesNotFilterAcknowledged(): void
    {
        $jobId = $this->seedJob();

        $this->seedFinishedExecution($jobId, [
            'exit_code'       => 1,
            'started_at'      => '2026-01-15 08:00:00',
            'acknowledged_at' => '2026-01-15 08:05:00',
        ]);

        // Without the flag: acknowledged entry is returned
        $bodyAll = $this->callHistory(['status' => 'failed']);
        $this->assertSame(1, (int) ($bodyAll['total'] ?? -1));

        // With unacknowledged_only=0: same as without the flag
        $bodyZero = $this->callHistory(['status' => 'failed', 'unacknowledged_only' => '0']);
        $this->assertSame(1, (int) ($bodyZero['total'] ?? -1));
    }
}
