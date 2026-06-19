<?php

declare(strict_types=1);

/**
 * Cronmanager – Base class for agent endpoint integration tests.
 *
 * Extends IntegrationTestCase with:
 *   - php://input injection via PhpInputStream stream wrapper
 *   - Response capture via Tests\Support\AgentResponse
 *   - Convenience assertion methods for HTTP status and body fields
 *   - A null Monolog logger (no output during tests)
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Base;

use Monolog\Logger;
use Tests\Support\AgentResponse;
use Tests\Support\PhpInputStream;

abstract class AgentEndpointTestCase extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        AgentResponse::reset();
    }

    protected function tearDown(): void
    {
        PhpInputStream::restore();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Endpoint invocation helper
    // -------------------------------------------------------------------------

    /**
     * Inject a JSON body into php://input and call $endpoint->handle([]).
     *
     * The PhpInputStream stream wrapper replaces php://input for the duration
     * of the call and is restored in tearDown().
     *
     * @param object               $endpoint  Any agent endpoint with a handle(array) method.
     * @param array<string, mixed> $body      Data to JSON-encode as the request body.
     */
    protected function callHandle(object $endpoint, array $body): void
    {
        AgentResponse::reset();
        PhpInputStream::set(json_encode($body) ?: '');

        $endpoint->handle([]);

        PhpInputStream::restore();
    }

    // -------------------------------------------------------------------------
    // Assertion helpers
    // -------------------------------------------------------------------------

    /**
     * Assert that the captured HTTP status code matches.
     */
    protected function assertStatus(int $expected): void
    {
        $this->assertSame(
            $expected,
            AgentResponse::$statusCode,
            sprintf(
                'Expected HTTP %d but got %s. Body: %s',
                $expected,
                AgentResponse::$statusCode ?? 'null',
                json_encode(AgentResponse::$body)
            )
        );
    }

    /**
     * Assert that the captured response body contains a key with the given value.
     *
     * @param mixed $expected
     */
    protected function assertBodyHas(string $key, mixed $expected): void
    {
        $body = AgentResponse::$body ?? [];

        $this->assertArrayHasKey($key, $body, "Response body missing key '{$key}'");
        $this->assertSame(
            $expected,
            $body[$key],
            "Response body['{$key}'] mismatch"
        );
    }

    /**
     * Assert that the captured response body contains the given key (any value).
     */
    protected function assertBodyHasKey(string $key): void
    {
        $this->assertArrayHasKey(
            $key,
            AgentResponse::$body ?? [],
            "Response body missing key '{$key}'"
        );
    }

    // -------------------------------------------------------------------------
    // Factory helpers
    // -------------------------------------------------------------------------

    /**
     * Create a silent Monolog logger (no handlers → no output during tests).
     */
    protected function createNullLogger(): Logger
    {
        return new Logger('test');
    }
}
