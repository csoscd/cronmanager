<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: ExecutionProgressEndpoint
 *
 * Tests the POST /execution/{id}/progress handler against a real MariaDB
 * database.  Verifies partial output is written only to running executions
 * (finished_at IS NULL) and that all edge cases (invalid ID, missing body
 * field, non-existent / already-finished execution) return the correct
 * HTTP status codes.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Endpoints\ExecutionProgressEndpoint;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;
use Tests\Support\AgentResponse;
use Tests\Support\PhpInputStream;

final class ExecutionProgressEndpointTest extends AgentEndpointTestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeEndpoint(): ExecutionProgressEndpoint
    {
        return new ExecutionProgressEndpoint($this->pdo, $this->createNullLogger());
    }

    /**
     * Inject a JSON body and call the endpoint with the given URL path param.
     *
     * @param array<string, mixed> $body
     */
    private function callProgress(int $executionId, array $body): void
    {
        AgentResponse::reset();
        PhpInputStream::set(json_encode($body) ?: '');
        $this->makeEndpoint()->handle(['id' => (string) $executionId]);
        PhpInputStream::restore();
    }

    // =========================================================================
    // 1. Happy path
    // =========================================================================

    #[Test]
    public function happyPath_writesPartialOutputToRunningExecution(): void
    {
        $jobId = $this->seedJob();
        $execId = $this->seedRunningExecution($jobId);

        $this->callProgress($execId, ['output' => 'partial output so far']);

        $this->assertStatus(200);
        $this->assertBodyHas('execution_id', $execId);
        $this->assertBodyHas('updated', true);

        $row = $this->fetchExecution($execId);
        $this->assertNotFalse($row);
        $this->assertSame('partial output so far', $row['output']);
        $this->assertNull($row['finished_at'], 'finished_at must remain NULL');
    }

    #[Test]
    public function subsequentCallOverwritesPreviousPartialOutput(): void
    {
        $jobId  = $this->seedJob();
        $execId = $this->seedRunningExecution($jobId);

        $this->callProgress($execId, ['output' => 'first chunk']);
        $this->callProgress($execId, ['output' => 'first chunk\nsecond chunk']);

        $row = $this->fetchExecution($execId);
        $this->assertNotFalse($row);
        $this->assertSame('first chunk\nsecond chunk', $row['output']);
    }

    // =========================================================================
    // 2. Finished execution – must not be overwritten
    // =========================================================================

    #[Test]
    public function doesNotOverwriteFinishedExecution(): void
    {
        $jobId  = $this->seedJob();
        $execId = $this->seedFinishedExecution($jobId, [
            'exit_code' => 0,
            'output'    => 'final output',
        ]);

        $this->callProgress($execId, ['output' => 'late partial']);

        $this->assertStatus(404);

        $row = $this->fetchExecution($execId);
        $this->assertNotFalse($row);
        $this->assertSame('final output', $row['output'], 'Finished execution output must not be overwritten');
    }

    // =========================================================================
    // 3. Non-existent execution
    // =========================================================================

    #[Test]
    public function nonExistentExecutionIdReturns404(): void
    {
        $this->callProgress(999999, ['output' => 'some output']);

        $this->assertStatus(404);
    }

    // =========================================================================
    // 4. Validation errors
    // =========================================================================

    #[Test]
    public function invalidExecutionIdZeroReturnsBadRequest(): void
    {
        AgentResponse::reset();
        PhpInputStream::set(json_encode(['output' => 'x']) ?: '');
        $this->makeEndpoint()->handle(['id' => '0']);
        PhpInputStream::restore();

        $this->assertStatus(400);
    }

    #[Test]
    public function missingOutputFieldReturnsBadRequest(): void
    {
        $jobId  = $this->seedJob();
        $execId = $this->seedRunningExecution($jobId);

        $this->callProgress($execId, []);

        $this->assertStatus(400);
    }

    #[Test]
    public function nonStringOutputFieldReturnsBadRequest(): void
    {
        $jobId  = $this->seedJob();
        $execId = $this->seedRunningExecution($jobId);

        $this->callProgress($execId, ['output' => 12345]);

        $this->assertStatus(400);
    }

    #[Test]
    public function invalidJsonBodyReturnsBadRequest(): void
    {
        $jobId  = $this->seedJob();
        $execId = $this->seedRunningExecution($jobId);

        AgentResponse::reset();
        PhpInputStream::set('not-json');
        $this->makeEndpoint()->handle(['id' => (string) $execId]);
        PhpInputStream::restore();

        $this->assertStatus(400);
    }
}
