<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: JobsApiController bulk/status and successors endpoints
 *
 * Covers v7.2.0 invariants for the three new REST API endpoints.
 * Tests are split into two groups:
 *
 * GROUP A – Input validation / auth (no running agent required):
 *
 *   POST /api/v1/jobs/bulk/status
 *     1. Missing auth header → HTTP 401
 *     2. Missing "ids" field in body → HTTP 400
 *     3. Missing "active" field in body → HTTP 400
 *     4. Valid auth but unknown agent → HTTP 404
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
 * GROUP B – Proxy behaviour with mocked Guzzle (success & conflict paths):
 *
 *   POST /api/v1/jobs/bulk/status
 *    11. Agent returns 200 {"updated":2} → controller returns 200 with agent_id
 *    12. Agent returns 409 Conflict → controller forwards 409 body unchanged
 *
 *   GET /api/v1/jobs/{id}/successors
 *    13. Agent returns 200 {"has_active_successors":true,...} → 200 forwarded
 *
 *   POST /api/v1/jobs/bulk/successors
 *    14. Agent returns 200 {"has_active_successors":false,"jobs":[]} → 200 forwarded
 *
 * Group B uses a GuzzleHttp MockHandler injected via the HostAgentClient's optional
 * $httpClient constructor parameter (v7.2.0 addition). A protected factory method
 * buildAgentClientForTest() on a TestableJobsApiController subclass injects the
 * mock instead of creating a real HTTP connection.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Web;

use Cronmanager\Web\Agent\HostAgentClient;
use Cronmanager\Web\Api\JobsApiController;
use Cronmanager\Web\Auth\ApiKey;
use Cronmanager\Web\Auth\ApiKeyRepository;
use Cronmanager\Web\Bootstrap\ApiKeySchema;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Monolog\Logger;
use Noodlehaus\Config;
use Noodlehaus\Parser\Json as JsonParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\IntegrationTestCase;
use Tests\Support\PhpInputStream;

// ---------------------------------------------------------------------------
// Testable subclass: overrides agentClient() for mock HTTP injection
// ---------------------------------------------------------------------------

/**
 * Extends JobsApiController to allow injecting a GuzzleHttp mock client.
 * Used only by Group-B tests (proxy behaviour).
 */
final class MockedJobsApiController extends JobsApiController
{
    private Client $mockHttpClient;

    public function __construct(Client $mockHttpClient, \PDO $pdo)
    {
        parent::__construct(new Config('{}', new JsonParser(), true), new Logger('test'), $pdo);
        $this->mockHttpClient = $mockHttpClient;
    }

    protected function agentClient(ApiKey $apiKey): ?HostAgentClient
    {
        // Skip real agent lookup; set a dummy agent ID and return a client backed
        // by the mock Guzzle handler.
        $this->resolvedAgentId = 1;
        return new HostAgentClient(
            logger:     new Logger('test'),
            agentUrl:   'http://mock-agent',
            hmacSecret: 'test-secret',
            httpClient: $this->mockHttpClient,
        );
    }
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------

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

    /**
     * Build a Guzzle Client backed by a MockHandler with the given responses.
     *
     * @param Response[] $responses
     */
    private function mockGuzzleClient(array $responses): Client
    {
        $mock    = new MockHandler($responses);
        $stack   = HandlerStack::create($mock);
        return new Client(['handler' => $stack, 'http_errors' => false]);
    }

    /**
     * @param list<string> $scopes
     */
    private function seedKeyAndAuth(array $scopes): void
    {
        $key = $this->seedKey($scopes);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
    }

    // =========================================================================
    // GROUP A – Input validation / auth (no running agent required)
    // =========================================================================

    // --- POST /api/v1/jobs/bulk/status ---

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
        $this->seedKeyAndAuth(['jobs:write']);
        $this->seedAgent();

        PhpInputStream::set(json_encode(['active' => false]) ?: '{}');
        $r = $this->capture(fn() => $this->makeController()->bulkStatus([]));
        $this->assertSame(400, $r['status']);
        $this->assertStringContainsString('ids', (string) ($r['body']['message'] ?? ''));
    }

    #[Test]
    public function bulkStatusWithMissingActiveFieldReturns400(): void
    {
        $this->seedKeyAndAuth(['jobs:write']);
        $this->seedAgent();

        PhpInputStream::set(json_encode(['ids' => [1, 2]]) ?: '{}');
        $r = $this->capture(fn() => $this->makeController()->bulkStatus([]));
        $this->assertSame(400, $r['status']);
        $this->assertStringContainsString('active', (string) ($r['body']['message'] ?? ''));
    }

    #[Test]
    public function bulkStatusWithUnknownAgentReturns404(): void
    {
        $this->seedKeyAndAuth(['jobs:write']);
        $this->seedAgent();
        $_GET['agent_id'] = '99999';

        PhpInputStream::set(json_encode(['ids' => [1], 'active' => false]) ?: '{}');
        $r = $this->capture(fn() => $this->makeController()->bulkStatus([]));
        $this->assertSame(404, $r['status']);
    }

    // --- GET /api/v1/jobs/{id}/successors ---

    #[Test]
    public function successorsWithoutAuthReturns401(): void
    {
        $r = $this->capture(fn() => $this->makeController()->successors(['id' => '1']));
        $this->assertSame(401, $r['status']);
    }

    #[Test]
    public function successorsWithNonPositiveIdReturns400(): void
    {
        $this->seedKeyAndAuth(['jobs:read']);
        $this->seedAgent();

        $r = $this->capture(fn() => $this->makeController()->successors(['id' => '0']));
        $this->assertSame(400, $r['status']);
    }

    #[Test]
    public function successorsWithUnknownAgentReturns404(): void
    {
        $this->seedKeyAndAuth(['jobs:read']);
        $this->seedAgent();
        $_GET['agent_id'] = '99999';

        $r = $this->capture(fn() => $this->makeController()->successors(['id' => '5']));
        $this->assertSame(404, $r['status']);
    }

    // --- POST /api/v1/jobs/bulk/successors ---

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
        $this->seedKeyAndAuth(['jobs:read']);
        $this->seedAgent();

        PhpInputStream::set(json_encode(['foo' => 'bar']) ?: '{}');
        $r = $this->capture(fn() => $this->makeController()->bulkSuccessors([]));
        $this->assertSame(400, $r['status']);
        $this->assertStringContainsString('ids', (string) ($r['body']['message'] ?? ''));
    }

    #[Test]
    public function bulkSuccessorsWithUnknownAgentReturns404(): void
    {
        $this->seedKeyAndAuth(['jobs:read']);
        $this->seedAgent();
        $_GET['agent_id'] = '99999';

        PhpInputStream::set(json_encode(['ids' => [1, 2]]) ?: '{}');
        $r = $this->capture(fn() => $this->makeController()->bulkSuccessors([]));
        $this->assertSame(404, $r['status']);
    }

    // =========================================================================
    // GROUP B – Proxy behaviour with mocked Guzzle client
    // =========================================================================

    #[Test]
    public function bulkStatusSuccessForwardsAgentResponse(): void
    {
        $this->seedKeyAndAuth(['jobs:write']);
        $agentBody = json_encode(['updated' => 2]) ?: '';

        $guzzle     = $this->mockGuzzleClient([new Response(200, [], $agentBody)]);
        $controller = new MockedJobsApiController($guzzle, $this->pdo);

        PhpInputStream::set(json_encode(['ids' => [1, 2], 'active' => false]) ?: '{}');
        $r = $this->capture(fn() => $controller->bulkStatus([]));
        PhpInputStream::restore();

        $this->assertSame(200, $r['status']);
        $this->assertArrayHasKey('agent_id', $r['body'] ?? []);
        $this->assertSame(2, $r['body']['updated'] ?? null);
    }

    #[Test]
    public function bulkStatus409FromAgentIsForwardedUnchanged(): void
    {
        $this->seedKeyAndAuth(['jobs:write']);
        $conflictBody = json_encode([
            'error'         => 'Conflict',
            'code'          => 409,
            'affected_jobs' => [
                ['job_id' => 1, 'description' => 'Job A', 'successors' => []],
            ],
        ]) ?: '';

        $guzzle     = $this->mockGuzzleClient([new Response(409, [], $conflictBody)]);
        $controller = new MockedJobsApiController($guzzle, $this->pdo);

        PhpInputStream::set(json_encode(['ids' => [1], 'active' => false]) ?: '{}');
        $r = $this->capture(fn() => $controller->bulkStatus([]));
        PhpInputStream::restore();

        $this->assertSame(409, $r['status']);
        $this->assertArrayHasKey('affected_jobs', $r['body'] ?? []);
        $this->assertCount(1, $r['body']['affected_jobs'] ?? []);
    }

    #[Test]
    public function successorsSuccessForwardsAgentResponse(): void
    {
        $this->seedKeyAndAuth(['jobs:read']);
        $agentBody = json_encode([
            'has_active_successors' => true,
            'successors'            => [['id' => 5, 'description' => 'Dep', 'dependency_type' => 'requires', 'depth' => 1]],
        ]) ?: '';

        $guzzle     = $this->mockGuzzleClient([new Response(200, [], $agentBody)]);
        $controller = new MockedJobsApiController($guzzle, $this->pdo);

        $r = $this->capture(fn() => $controller->successors(['id' => '3']));

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['body']['has_active_successors'] ?? false);
        $this->assertCount(1, $r['body']['successors'] ?? []);
    }

    #[Test]
    public function bulkSuccessorsSuccessForwardsAgentResponse(): void
    {
        $this->seedKeyAndAuth(['jobs:read']);
        $agentBody = json_encode([
            'has_active_successors' => false,
            'jobs'                  => [],
        ]) ?: '';

        $guzzle     = $this->mockGuzzleClient([new Response(200, [], $agentBody)]);
        $controller = new MockedJobsApiController($guzzle, $this->pdo);

        PhpInputStream::set(json_encode(['ids' => [1, 2]]) ?: '{}');
        $r = $this->capture(fn() => $controller->bulkSuccessors([]));
        PhpInputStream::restore();

        $this->assertSame(200, $r['status']);
        $this->assertFalse($r['body']['has_active_successors'] ?? true);
        $this->assertSame([], $r['body']['jobs'] ?? ['x']);
    }
}
