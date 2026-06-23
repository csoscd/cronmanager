<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: AgentsApiController
 *
 * Tests the GET /api/v1/agents endpoint end-to-end against a real MariaDB
 * database.  Each test seeds agent rows and an API key, simulates the
 * Authorization header via $_SERVER superglobals, calls the controller action,
 * and asserts on the captured HTTP status code and JSON body.
 *
 * Key behaviours under test:
 *   - Auth gate: missing/invalid/wrong-scope key → 4xx
 *   - Unrestricted key (agent_ids = null) → all agents returned
 *   - Restricted key (agent_ids = [id]) → only matching agents returned
 *   - Sensitive fields (url, hmac_secret, ssl_ca_bundle) never appear
 *   - enabled is a JSON boolean, not an integer
 *   - Disabled agents are still listed
 *   - count field always matches data array length
 *
 * Prerequisites:
 *   docker compose -f tests/docker-compose.test.yml up -d
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Web;

use Cronmanager\Web\Api\AgentsApiController;
use Cronmanager\Web\Auth\ApiKey;
use Cronmanager\Web\Auth\ApiKeyRepository;
use Cronmanager\Web\Bootstrap\ApiKeySchema;
use Monolog\Logger;
use Noodlehaus\Config;
use Noodlehaus\Parser\Json as JsonParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\IntegrationTestCase;

final class AgentsApiControllerTest extends IntegrationTestCase
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
        // agents table is created by schema.sql (applied in IntegrationTestCase::applySchema).
        // Delete any pre-existing rows so each test starts from a known empty state.
        // The DELETE runs inside the transaction started by IntegrationTestCase::setUp()
        // and is rolled back in tearDown(), leaving no side effects.
        $this->pdo->exec('DELETE FROM agents');

        $this->keyRepo = new ApiKeyRepository($this->pdo);

        http_response_code(200);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create an API key and return its plain-text token.
     *
     * @param list<string>   $scopes
     * @param list<int>|null $agentIds
     *
     * @return array{key: ApiKey, plainText: string}
     */
    private function seedKey(
        array  $scopes   = ['settings:read'],
        ?array $agentIds = null,
    ): array {
        $userId = $this->seedUser();

        return $this->keyRepo->create($userId, 'Test Key', $scopes, $agentIds, null, null);
    }

    /**
     * Call GET /api/v1/agents with the given Bearer token and return the HTTP
     * status code and decoded JSON body.
     *
     * @return array{status: int, body: array<string, mixed>|null}
     */
    private function callIndex(string $bearerToken): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearerToken;
        http_response_code(200);

        $controller = new AgentsApiController(
            new Config('{}', new JsonParser(), true),
            new Logger('test'),
            $this->pdo,
        );

        ob_start();
        $controller->index([]);
        $output = ob_get_clean();

        $status = (int) http_response_code();
        /** @var array<string, mixed>|null $body */
        $body = ($output !== '' && $output !== false)
            ? json_decode((string) $output, true)
            : null;

        return ['status' => $status, 'body' => $body];
    }

    // =========================================================================
    // Auth gate
    // =========================================================================

    #[Test]
    public function noAuthorizationHeaderReturns401(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        http_response_code(200);

        $controller = new AgentsApiController(
            new Config('{}', new JsonParser(), true),
            new Logger('test'),
            $this->pdo,
        );

        ob_start();
        $controller->index([]);
        $output = ob_get_clean();

        $this->assertSame(401, (int) http_response_code());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $output, true);
        $this->assertArrayHasKey('error',   $body);
        $this->assertArrayHasKey('message', $body);
        $this->assertArrayHasKey('code',    $body);
        $this->assertSame(401, $body['code']);
    }

    #[Test]
    public function keyWithoutSettingsReadScopeReturns403(): void
    {
        $result = $this->seedKey(scopes: ['jobs:read']);

        $r = $this->callIndex($result['plainText']);

        $this->assertSame(403, $r['status']);
    }

    // =========================================================================
    // Happy path – unrestricted key
    // =========================================================================

    #[Test]
    public function returnsAllAgentsWhenKeyHasNoAgentRestriction(): void
    {
        $this->seedAgent(['name' => 'Agent Alpha']);
        $this->seedAgent(['name' => 'Agent Beta']);
        $result = $this->seedKey();

        $r = $this->callIndex($result['plainText']);

        $this->assertSame(200, $r['status']);
        $this->assertIsArray($r['body']['data']);
        $this->assertCount(2, $r['body']['data']);
    }

    #[Test]
    public function responseContainsDataAndCountFields(): void
    {
        $this->seedAgent();
        $result = $this->seedKey();

        $r = $this->callIndex($result['plainText']);

        $this->assertSame(200, $r['status']);
        $this->assertIsArray($r['body']['data']);
        $this->assertIsInt($r['body']['count']);
    }

    #[Test]
    public function countMatchesDataLength(): void
    {
        $this->seedAgent();
        $this->seedAgent();
        $this->seedAgent();
        $result = $this->seedKey();

        $r = $this->callIndex($result['plainText']);

        $this->assertSame(count($r['body']['data']), $r['body']['count']);
    }

    // =========================================================================
    // agent_ids restriction
    // =========================================================================

    #[Test]
    public function filtersAgentsByKeyAgentIds(): void
    {
        $id1 = $this->seedAgent(['name' => 'Allowed']);
        $this->seedAgent(['name' => 'Blocked']);
        $result = $this->seedKey(agentIds: [$id1]);

        $r = $this->callIndex($result['plainText']);

        $this->assertSame(200, $r['status']);
        $this->assertCount(1, $r['body']['data']);
        $this->assertSame($id1, $r['body']['data'][0]['id']);
    }

    #[Test]
    public function emptyDataWhenKeyRestrictedToNonExistentAgentId(): void
    {
        $this->seedAgent();
        $result = $this->seedKey(agentIds: [99999]);

        $r = $this->callIndex($result['plainText']);

        $this->assertSame(200, $r['status']);
        $this->assertSame([], $r['body']['data']);
        $this->assertSame(0, $r['body']['count']);
    }

    // =========================================================================
    // Response structure
    // =========================================================================

    #[Test]
    public function sensitiveFieldsAreNotPresentInResponse(): void
    {
        $this->seedAgent([
            'url'         => 'http://agent:8865',
            'hmac_secret' => 'super-secret',
            'ssl_ca_bundle' => '/etc/ssl/ca.pem',
        ]);
        $result = $this->seedKey();

        $r = $this->callIndex($result['plainText']);

        $this->assertSame(200, $r['status']);
        $item = $r['body']['data'][0];
        $this->assertArrayNotHasKey('url',          $item);
        $this->assertArrayNotHasKey('hmac_secret',  $item);
        $this->assertArrayNotHasKey('ssl_ca_bundle', $item);
        $this->assertArrayNotHasKey('timeout',      $item);
        $this->assertArrayNotHasKey('ssl_verify',   $item);
    }

    #[Test]
    public function enabledFieldIsBooleanNotInteger(): void
    {
        $this->seedAgent(['enabled' => 1]);
        $result = $this->seedKey();

        $r = $this->callIndex($result['plainText']);

        $this->assertSame(200, $r['status']);
        $enabled = $r['body']['data'][0]['enabled'];
        $this->assertIsBool($enabled);
    }

    #[Test]
    public function disabledAgentIsIncludedInListing(): void
    {
        $this->seedAgent(['name' => 'Active',   'enabled' => 1]);
        $this->seedAgent(['name' => 'Disabled', 'enabled' => 0]);
        $result = $this->seedKey();

        $r = $this->callIndex($result['plainText']);

        $this->assertSame(200, $r['status']);
        $this->assertCount(2, $r['body']['data']);

        $enabledValues = array_column($r['body']['data'], 'enabled');
        $this->assertContains(false, $enabledValues);
    }

    #[Test]
    public function responseItemContainsRequiredFields(): void
    {
        $this->seedAgent(['name' => 'My Agent', 'description' => 'A test agent']);
        $result = $this->seedKey();

        $r = $this->callIndex($result['plainText']);

        $this->assertSame(200, $r['status']);
        $item = $r['body']['data'][0];
        $this->assertArrayHasKey('id',          $item);
        $this->assertArrayHasKey('name',        $item);
        $this->assertArrayHasKey('description', $item);
        $this->assertArrayHasKey('enabled',     $item);
        $this->assertSame('My Agent',    $item['name']);
        $this->assertSame('A test agent', $item['description']);
    }

    #[Test]
    public function nullDescriptionIsPreservedInResponse(): void
    {
        $this->seedAgent(['description' => null]);
        $result = $this->seedKey();

        $r = $this->callIndex($result['plainText']);

        $this->assertSame(200, $r['status']);
        $this->assertNull($r['body']['data'][0]['description']);
    }
}
