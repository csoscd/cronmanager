<?php

declare(strict_types=1);

/**
 * Cronmanager – Unit Tests: HostAgentClient
 *
 * Tests HMAC signing, error handling, and response decoding without a real
 * network connection.  A Guzzle MockHandler queues synthetic responses, and
 * Middleware::history() captures the outgoing requests so that headers and
 * URLs can be inspected.
 *
 * Because HostAgentClient creates its Guzzle Client lazily in a private
 * method, a pre-built Client instance is injected via ReflectionProperty.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Unit\Web;

use Cronmanager\Web\Agent\AgentHttpException;
use Cronmanager\Web\Agent\HostAgentClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HostAgentClientTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Test infrastructure
    // -------------------------------------------------------------------------

    private const SECRET  = 'test-hmac-secret';
    private const AGENT_URL = 'http://agent.test';

    /**
     * Build a HostAgentClient whose internal Guzzle client is backed by
     * $stack, bypassing the lazy-init logic in client().
     */
    private function makeClient(HandlerStack $stack, string $secret = self::SECRET): HostAgentClient
    {
        $guzzle = new Client([
            'handler'     => $stack,
            'base_uri'    => self::AGENT_URL,
            'http_errors' => false,   // let HostAgentClient::handleResponse() decide
        ]);

        $client = new HostAgentClient(
            logger:     new Logger('test'),
            agentUrl:   self::AGENT_URL,
            hmacSecret: $secret,
            sslVerify:  false,
        );

        // Inject the pre-built Guzzle client so client() doesn't create its own
        $prop = new \ReflectionProperty(HostAgentClient::class, 'guzzle');
        $prop->setValue($client, $guzzle);

        return $client;
    }

    /**
     * @param list<Response|\Throwable> $responses Queued Guzzle responses.
     * @param array<int, array<string, mixed>> $history Reference that will collect request/response pairs.
     *                                                  Must be declared as [] in the caller before use.
     */
    private function buildStack(array $responses, mixed &$history): HandlerStack
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return $stack;
    }

    /**
     * Compute the expected HMAC the way HostAgentClient does.
     * Matches the extended formula: METHOD + PATH + BODY + NUL + userId + NUL + username.
     */
    private function expectedHmac(
        string $method,
        string $path,
        string $body     = '',
        int    $userId   = 0,
        string $username = 'system',
    ): string {
        $message = strtoupper($method) . $path . $body . "\0" . $userId . "\0" . $username;
        return hash_hmac('sha256', $message, self::SECRET);
    }

    // =========================================================================
    // 1. GET – signing and URL
    // =========================================================================

    #[Test]
    public function getSignsHmacOverPathOnlyWithoutQueryString(): void
    {
        $history = [];
        $stack   = $this->buildStack([new Response(200, [], '{}')], $history);
        $client  = $this->makeClient($stack);

        $client->get('/crons', ['filter' => 'active', 'page' => 1]);

        $sig      = $history[0]['request']->getHeaderLine('X-Agent-Signature');
        $expected = $this->expectedHmac('GET', '/crons', '');

        $this->assertSame($expected, $sig);
    }

    #[Test]
    public function getIncludesQueryStringInRequestUrl(): void
    {
        $history = [];
        $stack   = $this->buildStack([new Response(200, [], '{}')], $history);
        $client  = $this->makeClient($stack);

        $client->get('/crons', ['tag' => 'backup']);

        $uri = (string) $history[0]['request']->getUri();
        $this->assertStringContainsString('tag=backup', $uri);
    }

    #[Test]
    public function getReturnsDecodedJsonArray(): void
    {
        $history = [];
        $stack   = $this->buildStack([new Response(200, [], '{"jobs":[{"id":1}]}')], $history);
        $client  = $this->makeClient($stack);

        $result = $client->get('/crons');

        $this->assertSame([['id' => 1]], $result['jobs']);
    }

    #[Test]
    public function getSendsAcceptJsonHeader(): void
    {
        $history = [];
        $stack   = $this->buildStack([new Response(200, [], '{}')], $history);
        $client  = $this->makeClient($stack);

        $client->get('/crons');

        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Accept'));
    }

    // =========================================================================
    // 2. POST – signing and body
    // =========================================================================

    #[Test]
    public function postSignsHmacOverPathPlusJsonBody(): void
    {
        $history = [];
        $stack   = $this->buildStack([new Response(201, [], '{"id":42}')], $history);
        $client  = $this->makeClient($stack);

        $data = ['command' => 'echo hello', 'schedule' => '* * * * *'];
        $client->post('/crons', $data);

        $sentBody = (string) $history[0]['request']->getBody();
        $expected = $this->expectedHmac('POST', '/crons', $sentBody);
        $actual   = $history[0]['request']->getHeaderLine('X-Agent-Signature');

        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function postSendsContentTypeJsonHeader(): void
    {
        $history = [];
        $stack   = $this->buildStack([new Response(200, [], '{}')], $history);
        $client  = $this->makeClient($stack);

        $client->post('/crons', ['cmd' => 'test']);

        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Content-Type'));
    }

    // =========================================================================
    // 3. PUT – signing
    // =========================================================================

    #[Test]
    public function putSignsHmacOverPathPlusJsonBody(): void
    {
        $history = [];
        $stack   = $this->buildStack([new Response(200, [], '{}')], $history);
        $client  = $this->makeClient($stack);

        $data = ['active' => false];
        $client->put('/crons/7', $data);

        $sentBody = (string) $history[0]['request']->getBody();
        $expected = $this->expectedHmac('PUT', '/crons/7', $sentBody);
        $actual   = $history[0]['request']->getHeaderLine('X-Agent-Signature');

        $this->assertSame($expected, $actual);
    }

    // =========================================================================
    // 4. DELETE – signing with empty body
    // =========================================================================

    #[Test]
    public function deleteSignsHmacOverPathWithEmptyBody(): void
    {
        $history = [];
        $stack   = $this->buildStack([new Response(200, [], '{}')], $history);
        $client  = $this->makeClient($stack);

        $client->delete('/crons/7');

        $expected = $this->expectedHmac('DELETE', '/crons/7', '');
        $actual   = $history[0]['request']->getHeaderLine('X-Agent-Signature');

        $this->assertSame($expected, $actual);
    }

    // =========================================================================
    // 5. Error handling
    // =========================================================================

    #[Test]
    public function status404ThrowsAgentHttpExceptionWithCode404(): void
    {
        $history = [];
        $stack   = $this->buildStack([new Response(404, [], '{"error":"Not Found"}')], $history);
        $client  = $this->makeClient($stack);

        $this->expectException(AgentHttpException::class);

        try {
            $client->get('/crons/999');
        } catch (AgentHttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            throw $e;
        }
    }

    #[Test]
    public function status500ThrowsAgentHttpExceptionWithCode500(): void
    {
        $history = [];
        $stack   = $this->buildStack([new Response(500, [], '{"error":"Server Error"}')], $history);
        $client  = $this->makeClient($stack);

        $this->expectException(AgentHttpException::class);

        try {
            $client->post('/execution/finish', []);
        } catch (AgentHttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
            throw $e;
        }
    }

    #[Test]
    public function connectExceptionIsWrappedInRuntimeException(): void
    {
        $history   = [];
        $connectEx = new ConnectException('Connection refused', new GuzzleRequest('GET', '/'));
        $stack     = $this->buildStack([$connectEx], $history);
        $client    = $this->makeClient($stack);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Agent unreachable/');

        $client->get('/crons');
    }

    // =========================================================================
    // 6. Non-JSON response body
    // =========================================================================

    #[Test]
    public function nonJsonResponseBodyReturnsEmptyArray(): void
    {
        $history = [];
        $stack   = $this->buildStack([new Response(200, [], 'not-json-at-all')], $history);
        $client  = $this->makeClient($stack);

        $result = $client->get('/ping');

        $this->assertSame([], $result);
    }

    // =========================================================================
    // 7. User-context headers (audit support)
    // =========================================================================

    #[Test]
    public function userHeadersAreSentWithCorrectValues(): void
    {
        $history = [];
        $stack   = $this->buildStack([new Response(200, [], '{}')], $history);

        $client = new HostAgentClient(
            logger:     new Logger('test'),
            agentUrl:   self::AGENT_URL,
            hmacSecret: self::SECRET,
            userId:     7,
            username:   'alice',
            sslVerify:  false,
        );
        $prop = new \ReflectionProperty(HostAgentClient::class, 'guzzle');
        $prop->setValue($client, new Client(['handler' => $stack, 'base_uri' => self::AGENT_URL, 'http_errors' => false]));

        $client->get('/crons');

        $req = $history[0]['request'];
        $this->assertSame('7',     $req->getHeaderLine('X-User-Id'));
        $this->assertSame('alice', $req->getHeaderLine('X-User-Name'));
    }

    #[Test]
    public function userContextIsIncludedInHmacSignature(): void
    {
        $history = [];
        $stack   = $this->buildStack([new Response(200, [], '{}')], $history);

        $client = new HostAgentClient(
            logger:     new Logger('test'),
            agentUrl:   self::AGENT_URL,
            hmacSecret: self::SECRET,
            userId:     42,
            username:   'bob',
            sslVerify:  false,
        );
        $prop = new \ReflectionProperty(HostAgentClient::class, 'guzzle');
        $prop->setValue($client, new Client(['handler' => $stack, 'base_uri' => self::AGENT_URL, 'http_errors' => false]));

        $client->get('/crons');

        $actual   = $history[0]['request']->getHeaderLine('X-Agent-Signature');
        $expected = $this->expectedHmac('GET', '/crons', '', 42, 'bob');

        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function differentUserContextProducesDifferentSignature(): void
    {
        $history1 = [];
        $history2 = [];

        $makeClientWithUser = function (int $userId, string $username, mixed &$history): HostAgentClient {
            $stack = $this->buildStack([new Response(200, [], '{}')], $history);
            $c = new HostAgentClient(
                logger:     new Logger('test'),
                agentUrl:   self::AGENT_URL,
                hmacSecret: self::SECRET,
                userId:     $userId,
                username:   $username,
                sslVerify:  false,
            );
            $prop = new \ReflectionProperty(HostAgentClient::class, 'guzzle');
            $prop->setValue($c, new Client(['handler' => $stack, 'base_uri' => self::AGENT_URL, 'http_errors' => false]));
            return $c;
        };

        $makeClientWithUser(1, 'admin', $history1)->get('/crons');
        $makeClientWithUser(2, 'editor', $history2)->get('/crons');

        $sig1 = $history1[0]['request']->getHeaderLine('X-Agent-Signature');
        $sig2 = $history2[0]['request']->getHeaderLine('X-Agent-Signature');

        $this->assertNotSame($sig1, $sig2);
    }
}
