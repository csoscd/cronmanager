<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: JobsApiController bulk/status and successors endpoints
 *
 * Covers v7.2.0 invariants for the three new REST API endpoints:
 *
 *   POST /api/v1/jobs/bulk/status
 *     1. Missing auth header → HTTP 401
 *     2. Missing "ids" field in body → HTTP 400
 *     3. Missing "active" field in body → HTTP 400
 *     4. Valid auth but unknown agent → HTTP 404 (agent not found before proxying)
 *
 *   GET /api/v1/jobs/{id}/successors
 *     5. Missing auth header → HTTP 401
 *     6. Non-positive job ID → HTTP 400
 *     7. Valid auth but unknown agent → HTTP 404
 *
 *   POST /api/v1/jobs/bulk/successors
 *     8. Missing auth header → HTTP 401
 *     9. Missing "ids" field in body → HTTP 400
 *    10. Valid auth but unknown agent → HTTP 404
 *
 * All tests rely only on input validation and auth/agent resolution — no running
 * agent is required; the 4xx responses are emitted before any real HTTP call.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Web;

use Cronmanager\Web\Api\JobsApiController;
use Cronmanager\Web\Auth\ApiKeyRepository;
use Cronmanager\Web\Bootstrap\ApiKeySchema;
use Monolog\Logger;
use Noodlehaus\Config;
use Noodlehaus\Parser\Json as JsonParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\IntegrationTestCase;
use Tests\Support\PhpInputStream;

final class JobsApiControllerBulkAndSuccessorsTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    private ApiKeyRepository $keyRepo;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        ApiKeySchema::ensure($this->pdo, new Logger('test'));
        $this->pdo->exec('DELETE FROM agents');

        $this->keyRepo = new ApiKeyRepository($this->pdo);

        http_response_code(200);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_GET['agent_id']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REMOTE_ADDR']);
        unset($_GET['agent_id']);
        PhpInputStream::restore();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeController(): JobsApiController
    {
        return new JobsApiController(
            new Config('{}', new JsonParser(), true),
            new Logger('test'),
            $this->pdo,
        );
    }

    /**
     * @param list<string> $scopes
     */
    private function seedKey(array $scopes): string
    {
        $userId = $this->seedUser();
        $result = $this->keyRepo->create($userId, 'Test Key', $scopes, null, null, null);
        return $result['plainText'];
    }

    /**
     * @return array{status: int, body: array<string, mixed>|null}
     */
    private function capture(\Closure $fn): array
    {
        http_response_code(200);
        ob_start();
        $fn();
        $output = ob_get_clean();
        $status = (int) http_response_code();
        /** @var array<string, mixed>|null $decoded */
        $decoded = ($output !== '' && $output !== false)
            ? json_decode((string) $output, true)
            : null;
        return ['status' => $status, 'body' => $decoded];
    }

    // =========================================================================
    // POST /api/v1/jobs/bulk/status
    // =========================================================================

    #[Test]
    public function bulkStatusWithoutAuthReturns401(): void
    {
        PhpInputStream::set('{}');
        $r = $this->capture(fn() => $this->makeController()->bulkStatus([]));
        $this->assertSame(401, $r['status']);
    }

    #[Test]
    public function bulkStatusWithMissingIdsFieldReturns400(): void
    {
        $key = $this->seedKey(['jobs:write']);
        $this->seedAgent();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;

        PhpInputStream::set(json_encode(['active' => false]) ?: '{}');
        $r = $this->capture(fn() => $this->makeController()->bulkStatus([]));
        $this->assertSame(400, $r['status']);
        $this->assertStringContainsString('ids', (string) ($r['body']['message'] ?? ''));
    }

    #[Test]
    public function bulkStatusWithMissingActiveFieldReturns400(): void
    {
        $key = $this->seedKey(['jobs:write']);
        $this->seedAgent();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;

        PhpInputStream::set(json_encode(['ids' => [1, 2]]) ?: '{}');
        $r = $this->capture(fn() => $this->makeController()->bulkStatus([]));
        $this->assertSame(400, $r['status']);
        $this->assertStringContainsString('active', (string) ($r['body']['message'] ?? ''));
    }

    #[Test]
    public function bulkStatusWithUnknownAgentReturns404(): void
    {
        $agentId = $this->seedAgent();
        $key     = $this->seedKey(['jobs:write']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
        $_GET['agent_id'] = '99999';

        PhpInputStream::set(json_encode(['ids' => [1], 'active' => false]) ?: '{}');
        $r = $this->capture(fn() => $this->makeController()->bulkStatus([]));
        $this->assertSame(404, $r['status']);
    }

    // =========================================================================
    // GET /api/v1/jobs/{id}/successors
    // =========================================================================

    #[Test]
    public function successorsWithoutAuthReturns401(): void
    {
        $r = $this->capture(fn() => $this->makeController()->successors(['id' => '1']));
        $this->assertSame(401, $r['status']);
    }

    #[Test]
    public function successorsWithNonPositiveIdReturns400(): void
    {
        $key = $this->seedKey(['jobs:read']);
        $this->seedAgent();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;

        $r = $this->capture(fn() => $this->makeController()->successors(['id' => '0']));
        $this->assertSame(400, $r['status']);
    }

    #[Test]
    public function successorsWithUnknownAgentReturns404(): void
    {
        $this->seedAgent();
        $key = $this->seedKey(['jobs:read']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
        $_GET['agent_id'] = '99999';

        $r = $this->capture(fn() => $this->makeController()->successors(['id' => '5']));
        $this->assertSame(404, $r['status']);
    }

    // =========================================================================
    // POST /api/v1/jobs/bulk/successors
    // =========================================================================

    #[Test]
    public function bulkSuccessorsWithoutAuthReturns401(): void
    {
        PhpInputStream::set('{}');
        $r = $this->capture(fn() => $this->makeController()->bulkSuccessors([]));
        $this->assertSame(401, $r['status']);
    }

    #[Test]
    public function bulkSuccessorsWithMissingIdsFieldReturns400(): void
    {
        $key = $this->seedKey(['jobs:read']);
        $this->seedAgent();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;

        PhpInputStream::set(json_encode(['foo' => 'bar']) ?: '{}');
        $r = $this->capture(fn() => $this->makeController()->bulkSuccessors([]));
        $this->assertSame(400, $r['status']);
        $this->assertStringContainsString('ids', (string) ($r['body']['message'] ?? ''));
    }

    #[Test]
    public function bulkSuccessorsWithUnknownAgentReturns404(): void
    {
        $this->seedAgent();
        $key = $this->seedKey(['jobs:read']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
        $_GET['agent_id'] = '99999';

        PhpInputStream::set(json_encode(['ids' => [1, 2]]) ?: '{}');
        $r = $this->capture(fn() => $this->makeController()->bulkSuccessors([]));
        $this->assertSame(404, $r['status']);
    }
}
