<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: CronCreateEndpoint
 *
 * Covers the correctness invariants of POST /crons:
 *
 *   1. Creates a job and returns it with all basic fields (HTTP 201)
 *   2. **Fix #128 #6** – When a triggered_by dependency with trigger_delay_minutes
 *      is included in the create request, the response must contain
 *      dependency.trigger_delay_minutes (it was missing from fetchCreatedJob()
 *      before the fix).
 *
 * CronCreateEndpoint calls beginTransaction() internally, so transaction
 * isolation is disabled and cleanup is performed manually in tearDown().
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Audit\AuditLogger;
use Cronmanager\Agent\Cron\CrontabManager;
use Cronmanager\Agent\Endpoints\CronCreateEndpoint;
use Cronmanager\Agent\Repository\DependencyRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;
use Tests\Support\AgentResponse;

final class CronCreateEndpointTest extends AgentEndpointTestCase
{
    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    /**
     * Disable framework-level transaction isolation: CronCreateEndpoint calls
     * beginTransaction() itself; nested transactions cause a PDO exception.
     */
    protected bool $useTransactionIsolation = false;

    private string $crontabDir = '';
    private CrontabManager $crontabMgr;

    /** @var int[] Job IDs created during tests, for manual tearDown cleanup. */
    private array $createdJobIds = [];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->createdJobIds = [];

        $this->crontabDir = sys_get_temp_dir() . '/cm-test-create-' . uniqid();
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

            // Delete job_dependencies where predecessor_id points to one of our jobs
            // BEFORE deleting the cronjobs rows, to avoid FK violations.
            $this->pdo->prepare(
                "DELETE FROM job_dependencies WHERE predecessor_id IN ({$placeholders})"
            )->execute($this->createdJobIds);

            // job_dependencies with job_id in our set cascade-delete automatically;
            // deleting the cronjobs rows is safe now.
            $this->pdo->prepare("DELETE FROM cronjobs WHERE id IN ({$placeholders})")
                      ->execute($this->createdJobIds);
        }

        if ($this->crontabDir !== '' && is_dir($this->crontabDir)) {
            foreach (glob($this->crontabDir . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($this->crontabDir);
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeEndpoint(): CronCreateEndpoint
    {
        return new CronCreateEndpoint(
            pdo:            $this->pdo,
            logger:         $this->createNullLogger(),
            crontabManager: $this->crontabMgr,
            wrapperScript:  '/dev/null',
            audit:          new AuditLogger($this->pdo, $this->createNullLogger(), 0, '', '127.0.0.1'),
            deps:           new DependencyRepository($this->pdo),
        );
    }

    // =========================================================================
    // 1. Basic create
    // =========================================================================

    #[Test]
    public function createJobReturns201WithJobId(): void
    {
        AgentResponse::reset();
        $this->callHandle($this->makeEndpoint(), [
            'linux_user'  => 'root',
            'schedule'    => '* * * * *',
            'command'     => 'echo test',
            'description' => 'Test job',
            'active'      => false,
            'targets'     => ['local'],
        ]);

        $this->assertStatus(201);
        $body = AgentResponse::$body ?? [];
        $this->assertArrayHasKey('id', $body);

        $this->createdJobIds[] = (int) $body['id'];
    }

    // =========================================================================
    // 2. Fix #128 #6 – trigger_delay_minutes present in POST /crons response
    // =========================================================================

    #[Test]
    public function createJobWithTriggeredByDependencyIncludesTriggerDelayMinutesInResponse(): void
    {
        // Seed a predecessor job directly (not via the endpoint) so we have its ID
        $predecessorId = $this->seedJob(['active' => 0]);
        $this->createdJobIds[] = $predecessorId;

        AgentResponse::reset();
        $this->callHandle($this->makeEndpoint(), [
            'linux_user'  => 'root',
            'schedule'    => null,           // triggered_by jobs have no schedule
            'command'     => 'echo dependent',
            'description' => 'Dependent job',
            'active'      => false,
            'targets'     => ['local'],
            'dependency'  => [
                'predecessor_id'        => $predecessorId,
                'type'                  => 'triggered_by',
                'exit_codes'            => '0',
                'trigger_delay_minutes' => 5,
            ],
        ]);

        $this->assertStatus(201);

        $body = AgentResponse::$body ?? [];
        $this->assertArrayHasKey('id', $body);
        $this->createdJobIds[] = (int) $body['id'];

        $this->assertArrayHasKey('dependency', $body, 'Response must contain dependency object');
        $dep = $body['dependency'];
        $this->assertIsArray($dep);
        $this->assertArrayHasKey(
            'trigger_delay_minutes',
            $dep,
            'dependency.trigger_delay_minutes must be present in POST /crons response',
        );
        $this->assertSame(5, $dep['trigger_delay_minutes'], 'trigger_delay_minutes must reflect the value sent');
    }
}
