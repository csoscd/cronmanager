<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: BaseApiController agent selection via ?agent_id=
 *
 * Verifies that the REST API honours the ?agent_id= query parameter (v7.1.1) as
 * an alternative to the X-Agent-Id request header for selecting the target agent.
 *
 * Covered behaviours:
 *
 *   1. ?agent_id=<unknown>  with at least one agent present → HTTP 404
 *   2. ?agent_id=<id>  forbidden by the API key's agent_ids restriction → HTTP 403
 *   3. X-Agent-Id header takes precedence over ?agent_id= when both are supplied
 *   4. No agents configured → HTTP 502 regardless of ?agent_id= value
 *
 * Uses JobsApiController::execute() as a representative endpoint because its
 * agent-selection path (agentClient()) is shared by every agent-specific endpoint.
 * The 404 / 403 / 502 responses are emitted before any real agent HTTP call is
 * attempted, so no running agent is required.
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

final class BaseApiControllerAgentSelectionTest extends IntegrationTestCase
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
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_AGENT_ID']);
        unset($_GET['agent_id']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_AGENT_ID'], $_SERVER['REMOTE_ADDR']);
        unset($_GET['agent_id']);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<string>   $scopes
     * @param list<int>|null $agentIds  null = unrestricted
     */
    private function seedKey(array $scopes = ['jobs:execute'], ?array $agentIds = null): string
    {
        $userId = $this->seedUser();
        $result = $this->keyRepo->create($userId, 'Test Key', $scopes, $agentIds, null, null);
        return $result['plainText'];
    }

    /**
     * @return array{status: int, body: array<string, mixed>|null}
     */
    private function callExecute(string $bearerToken, int $jobId = 1): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearerToken;
        http_response_code(200);

        $controller = new JobsApiController(
            new Config('{}', new JsonParser(), true),
            new Logger('test'),
            $this->pdo,
        );

        ob_start();
        $controller->execute(['id' => (string) $jobId]);
        $output = ob_get_clean();

        $status = (int) http_response_code();
        /** @var array<string, mixed>|null $body */
        $body = ($output !== '' && $output !== false)
            ? json_decode((string) $output, true)
            : null;

        return ['status' => $status, 'body' => $body];
    }

    // =========================================================================
    // 1. ?agent_id= pointing to an unknown agent ID → 404
    // =========================================================================

    #[Test]
    public function agentIdQueryParamWithUnknownIdReturns404(): void
    {
        $this->seedAgent(); // at least one agent exists, but not 99999
        $key = $this->seedKey();
        $_GET['agent_id'] = '99999';

        $r = $this->callExecute($key);

        $this->assertSame(404, $r['status']);
    }

    // =========================================================================
    // 2. ?agent_id= forbidden by the API key's agent_ids restriction → 403
    // =========================================================================

    #[Test]
    public function agentIdQueryParamForbiddenByKeyAgentRestrictionReturns403(): void
    {
        $agentA = $this->seedAgent();
        $agentB = $this->seedAgent();

        // Key is restricted to agent B only; request targets agent A → forbidden
        $key = $this->seedKey(agentIds: [$agentB]);
        $_GET['agent_id'] = (string) $agentA;

        $r = $this->callExecute($key);

        $this->assertSame(403, $r['status']);
    }

    // =========================================================================
    // 3. X-Agent-Id header takes precedence over ?agent_id= query parameter
    //
    //    Header → nonexistent agent (→ 404)
    //    ?agent_id= → valid agent
    //    Expected: 404 (header wins)
    // =========================================================================

    #[Test]
    public function xAgentIdHeaderTakesPrecedenceOverQueryParam(): void
    {
        $agentId = $this->seedAgent();
        $key     = $this->seedKey();

        $_SERVER['HTTP_X_AGENT_ID'] = '99999';         // nonexistent
        $_GET['agent_id']           = (string) $agentId; // valid

        $r = $this->callExecute($key);

        $this->assertSame(404, $r['status'], 'X-Agent-Id header must take precedence; invalid header → 404');
    }

    // =========================================================================
    // 4. No agents configured → 502 regardless of ?agent_id= value
    // =========================================================================

    #[Test]
    public function noAgentsConfiguredReturns502RegardlessOfQueryParam(): void
    {
        // agents table is empty (cleared in setUp)
        $key = $this->seedKey();
        $_GET['agent_id'] = '1';

        $r = $this->callExecute($key);

        $this->assertSame(502, $r['status']);
    }
}
