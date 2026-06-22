<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: ApiKeyMiddleware
 *
 * Tests the authentication gate for the external REST API (/api/v1/*).
 * Each test seeds a real API key via ApiKeyRepository, manipulates
 * $_SERVER superglobals to simulate different request contexts, and
 * captures the HTTP status code + JSON body emitted by the middleware.
 *
 * Output is captured with ob_start()/ob_get_clean() since ApiKeyMiddleware
 * calls echo + http_response_code() directly (not via a custom stub).
 *
 * Each test runs inside a MariaDB transaction rolled back in tearDown(),
 * so no test data leaks between tests.
 *
 * Prerequisites:
 *   docker compose -f tests/docker-compose.test.yml up -d
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Auth;

use Cronmanager\Web\Auth\ApiKey;
use Cronmanager\Web\Auth\ApiKeyMiddleware;
use Cronmanager\Web\Auth\ApiKeyRepository;
use Cronmanager\Web\Bootstrap\ApiKeySchema;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\IntegrationTestCase;

final class ApiKeyMiddlewareTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    private ApiKeyMiddleware $middleware;
    private ApiKeyRepository $repo;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        ApiKeySchema::ensure($this->pdo, new Logger('test'));

        $this->repo       = new ApiKeyRepository($this->pdo);
        $this->middleware  = new ApiKeyMiddleware($this->pdo, new Logger('test'));

        // Reset HTTP response code and superglobals to a clean baseline
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
     * Create a valid API key for a fresh user and return both the ApiKey and
     * the plain-text token.
     *
     * @param list<string>              $scopes
     * @param list<int>|null            $agentIds
     * @param \DateTimeImmutable|null   $expiresAt
     * @param list<string>|null         $ipWhitelist
     *
     * @return array{key: ApiKey, plainText: string}
     */
    private function seedKey(
        array               $scopes      = ['jobs:read'],
        ?array              $agentIds    = null,
        ?\DateTimeImmutable $expiresAt   = null,
        ?array              $ipWhitelist = null,
    ): array {
        $userId = $this->seedUser();

        return $this->repo->create($userId, 'Test Key', $scopes, $agentIds, $expiresAt, $ipWhitelist);
    }

    /**
     * Set the Authorization header and call authenticate(), capturing the
     * HTTP status code and response body.
     *
     * @return array{result: ApiKey|null, status: int, body: array<string, mixed>|null}
     */
    private function callAuthenticate(string $scope = 'jobs:read'): array
    {
        http_response_code(200);

        ob_start();
        $result = $this->middleware->authenticate($scope);
        $output = ob_get_clean();

        $status = (int) http_response_code();
        /** @var array<string, mixed>|null $body */
        $body = ($output !== '' && $output !== false)
            ? json_decode((string) $output, true)
            : null;

        return ['result' => $result, 'status' => $status, 'body' => $body];
    }

    // =========================================================================
    // Missing / malformed Authorization header
    // =========================================================================

    #[Test]
    public function noAuthorizationHeaderReturns401(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $r = $this->callAuthenticate();

        $this->assertNull($r['result']);
        $this->assertSame(401, $r['status']);
        $this->assertSame(401, $r['body']['code'] ?? null);
    }

    #[Test]
    public function nonBearerSchemeReturns401(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

        $r = $this->callAuthenticate();

        $this->assertNull($r['result']);
        $this->assertSame(401, $r['status']);
    }

    #[Test]
    public function emptyBearerTokenReturns401(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';

        $r = $this->callAuthenticate();

        $this->assertNull($r['result']);
        $this->assertSame(401, $r['status']);
    }

    // =========================================================================
    // Unknown key
    // =========================================================================

    #[Test]
    public function unknownBearerTokenReturns401(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer cm_completely_nonexistent_key_1234567890';

        $r = $this->callAuthenticate();

        $this->assertNull($r['result']);
        $this->assertSame(401, $r['status']);
    }

    // =========================================================================
    // Expiry
    // =========================================================================

    #[Test]
    public function expiredKeyReturns401(): void
    {
        $result = $this->seedKey(
            expiresAt: new \DateTimeImmutable('2020-01-01 00:00:00'),
        );
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $result['plainText'];

        $r = $this->callAuthenticate();

        $this->assertNull($r['result']);
        $this->assertSame(401, $r['status']);
    }

    #[Test]
    public function nonExpiredKeyIsNotRejectedForExpiry(): void
    {
        $result = $this->seedKey(
            expiresAt: new \DateTimeImmutable('+1 year'),
        );
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $result['plainText'];

        $r = $this->callAuthenticate();

        // Must not be rejected with 401 due to expiry (scope/IP checks still pass
        // because we use defaults: no whitelist, required scope 'jobs:read' is granted)
        $this->assertNotNull($r['result']);
        $this->assertSame(200, $r['status']);
    }

    // =========================================================================
    // IP whitelist
    // =========================================================================

    #[Test]
    public function ipNotInWhitelistReturns403(): void
    {
        $result = $this->seedKey(
            ipWhitelist: ['10.0.0.0/8'],
        );
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $result['plainText'];
        $_SERVER['REMOTE_ADDR']        = '192.168.1.1'; // not in 10.0.0.0/8

        $r = $this->callAuthenticate();

        $this->assertNull($r['result']);
        $this->assertSame(403, $r['status']);
    }

    #[Test]
    public function ipInWhitelistIsAllowed(): void
    {
        $result = $this->seedKey(
            ipWhitelist: ['127.0.0.0/8'],
        );
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $result['plainText'];
        $_SERVER['REMOTE_ADDR']        = '127.0.0.1'; // in 127.0.0.0/8

        $r = $this->callAuthenticate();

        $this->assertNotNull($r['result']);
    }

    // =========================================================================
    // Scope check
    // =========================================================================

    #[Test]
    public function missingRequiredScopeReturns403(): void
    {
        $result = $this->seedKey(scopes: ['jobs:read']); // does NOT have jobs:write
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $result['plainText'];

        $r = $this->callAuthenticate('jobs:write');

        $this->assertNull($r['result']);
        $this->assertSame(403, $r['status']);
    }

    #[Test]
    public function correctScopeAllowsAccess(): void
    {
        $result = $this->seedKey(scopes: ['jobs:read', 'jobs:write']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $result['plainText'];

        $r = $this->callAuthenticate('jobs:write');

        $this->assertNotNull($r['result']);
        $this->assertSame(200, $r['status']);
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    #[Test]
    public function successfulAuthReturnsApiKeyInstance(): void
    {
        $result = $this->seedKey();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $result['plainText'];

        $r = $this->callAuthenticate();

        $this->assertInstanceOf(ApiKey::class, $r['result']);
    }

    #[Test]
    public function returnedApiKeyHasCorrectId(): void
    {
        $result = $this->seedKey();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $result['plainText'];

        $r = $this->callAuthenticate();

        $this->assertNotNull($r['result']);
        $this->assertSame($result['key']->id, $r['result']->id);
    }

    // =========================================================================
    // Error response format
    // =========================================================================

    #[Test]
    public function errorResponseBodyContainsStandardFields(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $r = $this->callAuthenticate();

        $this->assertIsArray($r['body']);
        $this->assertArrayHasKey('error',   $r['body']);
        $this->assertArrayHasKey('message', $r['body']);
        $this->assertArrayHasKey('code',    $r['body']);
        $this->assertSame(401, $r['body']['code']);
    }
}
