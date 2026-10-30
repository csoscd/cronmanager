<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: DependencyCheckEndpoint
 *
 * Covers the correctness invariants of GET /crons/{id}/dependency-check:
 *
 *   1. Rejects an invalid (zero) job ID (HTTP 400)
 *   2. Returns satisfied=true for a job with no dependency
 *   3. Returns satisfied=true when the predecessor ran with a matching exit code
 *   4. Returns satisfied=false when the predecessor has no recent matching run
 *   5. **Fix #128 #3** – Returns HTTP 500 (fail-closed) when the inner
 *      checkRequires() query throws a PDOException, preventing the job from
 *      running without a valid dependency check.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Endpoints\DependencyCheckEndpoint;
use Cronmanager\Agent\Repository\DependencyRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;
use Tests\Support\AgentResponse;

final class DependencyCheckEndpointTest extends AgentEndpointTestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeEndpoint(?PDO $overridePdo = null): DependencyCheckEndpoint
    {
        return new DependencyCheckEndpoint(
            pdo:    $overridePdo ?? $this->pdo,
            logger: $this->createNullLogger(),
            deps:   new DependencyRepository($this->pdo),
        );
    }

    /**
     * Insert a requires dependency row directly (bypasses validation).
     */
    private function seedDependency(
        int    $jobId,
        int    $predecessorId,
        string $type       = 'requires',
        string $exitCodes  = '0',
        ?int   $maxAge     = 60,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO job_dependencies
                 (job_id, predecessor_id, type, exit_codes, max_age_minutes, trigger_delay_minutes)
             VALUES (:jid, :pid, :type, :ec, :age, 0)'
        )->execute([
            ':jid'  => $jobId,
            ':pid'  => $predecessorId,
            ':type' => $type,
            ':ec'   => $exitCodes,
            ':age'  => $maxAge,
        ]);
    }

    // =========================================================================
    // 1. Input validation
    // =========================================================================

    #[Test]
    public function invalidJobIdReturns400(): void
    {
        AgentResponse::reset();
        $this->makeEndpoint()->handle(['id' => '0']);
        $this->assertStatus(400);
    }

    // =========================================================================
    // 2. No dependency → always satisfied
    // =========================================================================

    #[Test]
    public function noDependencyReturnsSatisfiedTrue(): void
    {
        $jobId = $this->seedJob();

        AgentResponse::reset();
        $this->makeEndpoint()->handle(['id' => (string) $jobId]);

        $this->assertStatus(200);
        $this->assertBodyHas('satisfied', true);
    }

    // =========================================================================
    // 3. Satisfied dependency
    // =========================================================================

    #[Test]
    public function requiresDependencySatisfiedWhenPredecessorRanWithMatchingCode(): void
    {
        $predecessorId = $this->seedJob();
        $jobId         = $this->seedJob();

        $this->seedDependency($jobId, $predecessorId, 'requires', '0', 60);

        // Predecessor ran with exit code 0 within the last 60 minutes
        $this->seedFinishedExecution($predecessorId, [
            'exit_code'   => 0,
            'finished_at' => date('Y-m-d H:i:s', time() - 120), // 2 min ago
        ]);

        AgentResponse::reset();
        $this->makeEndpoint()->handle(['id' => (string) $jobId]);

        $this->assertStatus(200);
        $this->assertBodyHas('satisfied', true);
    }

    // =========================================================================
    // 4. Unsatisfied dependency
    // =========================================================================

    #[Test]
    public function requiresDependencyUnsatisfiedWhenPredecessorHasNoRecentRun(): void
    {
        $predecessorId = $this->seedJob();
        $jobId         = $this->seedJob();

        $this->seedDependency($jobId, $predecessorId, 'requires', '0', 5); // only 5-minute window

        // Predecessor ran 10 minutes ago – outside the 5-minute window
        $this->seedFinishedExecution($predecessorId, [
            'exit_code'   => 0,
            'finished_at' => date('Y-m-d H:i:s', time() - 600),
        ]);

        AgentResponse::reset();
        $this->makeEndpoint()->handle(['id' => (string) $jobId]);

        $this->assertStatus(200);
        $this->assertBodyHas('satisfied', false);
    }

    // =========================================================================
    // 5. Fix #128 #3 – fail-closed on checkRequires() DB error
    // =========================================================================

    #[Test]
    public function dbErrorInCheckRequiresReturns500FailClosed(): void
    {
        $predecessorId = $this->seedJob();
        $jobId         = $this->seedJob();

        // Seed a 'requires' dependency so the outer findByJobId() succeeds and
        // the code proceeds to checkRequires() (which uses $this->pdo).
        $this->seedDependency($jobId, $predecessorId, 'requires', '0', 60);

        // A PDO stub that throws on prepare() – simulates DB failure inside checkRequires()
        $badPdo = $this->createMock(PDO::class);
        $badPdo->method('prepare')->willThrowException(new PDOException('Simulated DB failure'));

        // Pass bad PDO to the endpoint (used in checkRequires) but keep real PDO
        // in DependencyRepository (used in findByJobId) so the outer call succeeds.
        $endpoint = new DependencyCheckEndpoint(
            pdo:    $badPdo,
            logger: $this->createNullLogger(),
            deps:   new DependencyRepository($this->pdo),
        );

        AgentResponse::reset();
        $endpoint->handle(['id' => (string) $jobId]);

        // Fail closed: HTTP 500, not satisfied=true
        $this->assertStatus(500);
    }
}
