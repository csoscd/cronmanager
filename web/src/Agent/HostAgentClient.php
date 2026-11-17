<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Host Agent HTTP Client
 *
 * The sole communication bridge between the web UI container and the host
 * agent process.  Every request is signed with an HMAC-SHA256 signature so
 * the agent can verify that the call originated from a trusted source.
 *
 * Connection parameters (url, hmacSecret, timeout, sslVerify, sslCaBundle) are
 * supplied directly via the constructor so that the caller can switch between
 * multiple agents at runtime without re-reading config files.
 *
 * Signature algorithm:
 *   X-Agent-Signature: hmac_sha256(secret, UPPER(method) + path + rawBody + "\0" + userId + "\0" + username)
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Agent;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Monolog\Logger;

/**
 * Class HostAgentClient
 *
 * Wraps GuzzleHttp\Client to provide typed GET / POST / PUT / DELETE methods
 * against the host agent REST API.  All methods return the decoded JSON
 * response body as an associative array, or throw a \RuntimeException on
 * transport or protocol errors.
 */
class HostAgentClient
{
    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    /** @var Client|null Lazily-created Guzzle client instance. */
    private ?Client $guzzle = null;

    /**
     * @var bool True when a request on the web pool failed with a connection
     *           error and the client automatically fell back to the exec pool
     *           URL for the remainder of this request lifetime.
     */
    private bool $fallbackActive = false;

    /**
     * @var array{request_ms: float, db_ms: float, db_queries: int}|null
     *      Performance data from the most recently completed agent request, or
     *      null when the agent did not include a _perf field in its response.
     */
    private ?array $lastPerf = null;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param Logger      $logger      Monolog logger.
     * @param string      $agentUrl    Base URL of the exec pool (e.g. https://host.docker.internal:8865).
     * @param string      $hmacSecret  Shared secret for HMAC-SHA256 request signing.
     * @param int         $userId      ID of the acting user (0 for unauthenticated / system calls).
     * @param string      $username    Username of the acting user (e.g. "admin" or "api:key-name").
     * @param int         $timeout     Request timeout in seconds (default: 10).
     * @param bool        $sslVerify   Verify TLS certificate (default: true).
     * @param string      $sslCaBundle Path to a custom CA bundle PEM (optional).
     * @param string      $webUrl      Optional web-pool URL (AGENT_WEB_PORT). When non-empty,
     *                                 all requests from the web container are routed here instead
     *                                 of $agentUrl, keeping UI reads off the exec pool.
     * @param Client|null $httpClient  Pre-built Guzzle client (for testing only; production passes null).
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly string $agentUrl,
        private readonly string $hmacSecret,
        private readonly int    $userId      = 0,
        private readonly string $username    = 'system',
        private readonly int    $timeout     = 10,
        private readonly bool   $sslVerify   = true,
        private readonly string $sslCaBundle = '',
        private readonly string $webUrl      = '',
        ?Client                 $httpClient  = null,
    ) {
        $this->guzzle = $httpClient;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Perform a GET request to the agent.
     *
     * @param string                    $path  Request path (e.g. '/crons').
     * @param array<string,string|int>  $query Optional query string parameters.
     *
     * @throws \RuntimeException When the agent is unreachable or returns an error status.
     *
     * @return array<string,mixed> Decoded JSON response body.
     */
    public function get(string $path, array $query = []): array
    {
        // Build query string – used in the full URL but NOT in the HMAC path
        $queryString = '';
        if (!empty($query)) {
            $queryString = '?' . http_build_query($query);
        }

        // HMAC is signed over the path WITHOUT query string
        $signature = $this->sign('GET', $path, '');

        $this->logger->debug('HostAgentClient GET', ['path' => $path, 'query' => $query]);

        $headers = [
            'X-Agent-Signature' => $signature,
            'X-User-Id'         => (string) $this->userId,
            'X-User-Name'       => $this->username,
            'Accept'            => 'application/json',
        ];

        try {
            $response = $this->attemptRequest(
                fn(Client $c) => $c->request('GET', $path . $queryString, ['headers' => $headers]),
            );
        } catch (ConnectException $e) {
            $this->logger->error('HostAgentClient: connection failed on GET', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Agent unreachable: ' . $e->getMessage(), 0, $e);
        }

        return $this->handleResponse('GET', $path, $response);
    }

    /**
     * Perform a POST request with a JSON body.
     *
     * @param string               $path Request path (e.g. '/crons').
     * @param array<string,mixed>  $data Data to JSON-encode as the request body.
     *
     * @throws \RuntimeException When the agent is unreachable or returns an error status.
     *
     * @return array<string,mixed> Decoded JSON response body.
     */
    public function post(string $path, array $data = []): array
    {
        $rawBody   = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $signature = $this->sign('POST', $path, $rawBody);

        $this->logger->debug('HostAgentClient POST', ['path' => $path]);

        $headers = [
            'X-Agent-Signature' => $signature,
            'X-User-Id'         => (string) $this->userId,
            'X-User-Name'       => $this->username,
            'Content-Type'      => 'application/json',
            'Accept'            => 'application/json',
        ];

        try {
            $response = $this->attemptRequest(
                fn(Client $c) => $c->request('POST', $path, ['headers' => $headers, 'body' => $rawBody]),
            );
        } catch (ConnectException $e) {
            $this->logger->error('HostAgentClient: connection failed on POST', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Agent unreachable: ' . $e->getMessage(), 0, $e);
        }

        return $this->handleResponse('POST', $path, $response);
    }

    /**
     * Perform a PUT request with a JSON body.
     *
     * @param string               $path Request path (e.g. '/crons/42').
     * @param array<string,mixed>  $data Data to JSON-encode as the request body.
     *
     * @throws \RuntimeException When the agent is unreachable or returns an error status.
     *
     * @return array<string,mixed> Decoded JSON response body.
     */
    public function put(string $path, array $data = []): array
    {
        $rawBody   = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $signature = $this->sign('PUT', $path, $rawBody);

        $this->logger->debug('HostAgentClient PUT', ['path' => $path]);

        $headers = [
            'X-Agent-Signature' => $signature,
            'X-User-Id'         => (string) $this->userId,
            'X-User-Name'       => $this->username,
            'Content-Type'      => 'application/json',
            'Accept'            => 'application/json',
        ];

        try {
            $response = $this->attemptRequest(
                fn(Client $c) => $c->request('PUT', $path, ['headers' => $headers, 'body' => $rawBody]),
            );
        } catch (ConnectException $e) {
            $this->logger->error('HostAgentClient: connection failed on PUT', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Agent unreachable: ' . $e->getMessage(), 0, $e);
        }

        return $this->handleResponse('PUT', $path, $response);
    }

    /**
     * Perform a DELETE request.
     *
     * @param string $path Request path (e.g. '/crons/42').
     *
     * @throws \RuntimeException When the agent is unreachable or returns an error status.
     *
     * @return array<string,mixed> Decoded JSON response body.
     */
    public function delete(string $path): array
    {
        $signature = $this->sign('DELETE', $path, '');

        $this->logger->debug('HostAgentClient DELETE', ['path' => $path]);

        $headers = [
            'X-Agent-Signature' => $signature,
            'X-User-Id'         => (string) $this->userId,
            'X-User-Name'       => $this->username,
            'Accept'            => 'application/json',
        ];

        try {
            $response = $this->attemptRequest(
                fn(Client $c) => $c->request('DELETE', $path, ['headers' => $headers]),
            );
        } catch (ConnectException $e) {
            $this->logger->error('HostAgentClient: connection failed on DELETE', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Agent unreachable: ' . $e->getMessage(), 0, $e);
        }

        return $this->handleResponse('DELETE', $path, $response);
    }

    // =========================================================================
    // Parallel requests
    // =========================================================================

    /**
     * Execute multiple GET requests in parallel and return all decoded bodies.
     *
     * Each entry in $requests must have a 'path' key and an optional 'query'
     * array (same format as the $query parameter of get()).  The return array
     * uses the same keys as the input array.
     *
     * Example:
     *   $results = $client->getMultiple([
     *       'crons'   => ['path' => '/crons'],
     *       'history' => ['path' => '/history', 'query' => ['limit' => 50]],
     *   ]);
     *
     * On individual request failure a \RuntimeException is thrown (same
     * behaviour as get()).  The _perf field of the last settled response is
     * stored in $this->lastPerf.
     *
     * @param array<string, array{path: string, query?: array<string, mixed>}> $requests
     *
     * @throws \RuntimeException When any agent request fails.
     *
     * @return array<string, array<string, mixed>> Key-matched decoded responses.
     */
    public function getMultiple(array $requests): array
    {
        $promises = [];

        foreach ($requests as $key => $spec) {
            $path        = (string) $spec['path'];
            $query       = isset($spec['query']) && is_array($spec['query']) ? $spec['query'] : [];
            $queryString = $query !== [] ? '?' . http_build_query($query) : '';
            $signature   = $this->sign('GET', $path, '');

            $this->logger->debug('HostAgentClient getMultiple GET', ['key' => $key, 'path' => $path]);

            $promises[$key] = $this->client()->requestAsync('GET', $path . $queryString, [
                'headers' => [
                    'X-Agent-Signature' => $signature,
                    'X-User-Id'         => (string) $this->userId,
                    'X-User-Name'       => $this->username,
                    'Accept'            => 'application/json',
                ],
            ]);
        }

        try {
            $responses = PromiseUtils::unwrap($promises);
        } catch (ConnectException $e) {
            if ($this->webUrl !== '' && !$this->fallbackActive) {
                $this->logger->warning('HostAgentClient: web pool unreachable in getMultiple, falling back to exec pool', [
                    'web_url'  => $this->webUrl,
                    'exec_url' => $this->agentUrl,
                    'message'  => $e->getMessage(),
                ]);
                $this->fallbackActive = true;
                $this->guzzle         = null;
                // Rebuild promises against the exec pool and retry
                $promises = [];
                foreach ($requests as $key => $spec) {
                    $path        = (string) $spec['path'];
                    $query       = isset($spec['query']) && is_array($spec['query']) ? $spec['query'] : [];
                    $queryString = $query !== [] ? '?' . http_build_query($query) : '';
                    $signature   = $this->sign('GET', $path, '');
                    $promises[$key] = $this->client()->requestAsync('GET', $path . $queryString, [
                        'headers' => [
                            'X-Agent-Signature' => $signature,
                            'X-User-Id'         => (string) $this->userId,
                            'X-User-Name'       => $this->username,
                            'Accept'            => 'application/json',
                        ],
                    ]);
                }
                try {
                    $responses = PromiseUtils::unwrap($promises);
                } catch (ConnectException $e2) {
                    $this->logger->error('HostAgentClient: connection failed in getMultiple (web pool + exec pool)', [
                        'message' => $e2->getMessage(),
                    ]);
                    throw new \RuntimeException('Agent unreachable (web pool + exec pool): ' . $e2->getMessage(), 0, $e2);
                }
            } else {
                $this->logger->error('HostAgentClient: connection failed in getMultiple', [
                    'message' => $e->getMessage(),
                ]);
                throw new \RuntimeException('Agent unreachable: ' . $e->getMessage(), 0, $e);
            }
        }

        $results = [];
        foreach ($responses as $key => $response) {
            $path        = (string) $requests[$key]['path'];
            $results[$key] = $this->handleResponse('GET', $path, $response);
        }

        return $results;
    }

    /**
     * Return true when a web-pool connection failure triggered an automatic
     * fallback to the exec pool for this request.
     *
     * Controllers pass this flag to the layout so a warning banner can be shown
     * to the user without interrupting their work.
     *
     * @return bool
     */
    public function isFallbackActive(): bool
    {
        return $this->fallbackActive;
    }

    /**
     * Return the _perf payload from the most recent agent response, or null.
     *
     * Populated by handleResponse() whenever the agent includes a _perf key.
     * Useful when the caller wants to forward timing data to the UI layer.
     *
     * @return array{request_ms: float, db_ms: float, db_queries: int}|null
     */
    public function getLastPerf(): ?array
    {
        return $this->lastPerf;
    }

    // =========================================================================
    // Maintenance windows
    // =========================================================================

    /**
     * Return all maintenance windows, optionally filtered by target.
     *
     * @param string|null $target Filter by exact target name; NULL returns all.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    public function getMaintenanceWindows(?string $target = null): array
    {
        $query = $target !== null ? ['target' => $target] : [];
        return $this->get('/maintenance/windows', $query);
    }

    /**
     * Return a single maintenance window.
     *
     * @param int $id Window ID.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function getMaintenanceWindow(int $id): array
    {
        return $this->get(sprintf('/maintenance/windows/%d', $id));
    }

    /**
     * Create a new maintenance window.
     *
     * @param array<string, mixed> $data Window data.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function createMaintenanceWindow(array $data): array
    {
        return $this->post('/maintenance/windows', $data);
    }

    /**
     * Update an existing maintenance window.
     *
     * @param int                  $id   Window ID.
     * @param array<string, mixed> $data Updated window data.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function updateMaintenanceWindow(int $id, array $data): array
    {
        return $this->put(sprintf('/maintenance/windows/%d', $id), $data);
    }

    /**
     * Delete a maintenance window.
     *
     * @param int $id Window ID.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function deleteMaintenanceWindow(int $id): array
    {
        return $this->delete(sprintf('/maintenance/windows/%d', $id));
    }

    /**
     * Fetch available Linux users and the docker_mode flag from the agent.
     *
     * @return array{docker_mode: bool, data: string[], count: int}
     *
     * @throws \RuntimeException
     */
    public function getLinuxUsers(): array
    {
        return $this->get('/linux-users');
    }

    /**
     * Check whether a cron schedule has conflicts with maintenance windows.
     *
     * @param string $schedule   5-field cron expression.
     * @param string $target     Target name.
     * @param int    $lookAhead  Number of upcoming runs to check (default 10).
     *
     * @return array{has_conflict: bool, conflicts: array<int, array<string, mixed>>}
     *
     * @throws \RuntimeException
     */
    public function checkMaintenanceConflict(string $schedule, string $target, int $lookAhead = 10): array
    {
        return $this->get('/maintenance/windows/conflict', [
            'schedule'    => $schedule,
            'target'      => $target,
            'look_ahead'  => $lookAhead,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return a lazily-created Guzzle HTTP client configured with the agent base URL,
     * request timeout, and TLS verification settings.
     *
     * The 'verify' Guzzle option resolves as follows:
     *   false          → disable certificate verification (self-signed certs)
     *   '/path/ca.pem' → verify against a custom CA bundle
     *   true           → use the system CA bundle
     *
     * @return Client
     */
    /**
     * Execute a Guzzle request callable with automatic web-pool fallback.
     *
     * When a web pool URL is configured and the first attempt raises a
     * ConnectException (the web pool is unreachable), the client is rebuilt
     * against the exec pool URL and the request is retried once.
     * $fallbackActive is set to true for the rest of this request lifetime so
     * all subsequent requests in the same page load also use the exec pool.
     *
     * @param  callable(\GuzzleHttp\Client): \Psr\Http\Message\ResponseInterface $fn
     * @throws \RuntimeException When both pools are unreachable.
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function attemptRequest(callable $fn): \Psr\Http\Message\ResponseInterface
    {
        try {
            return $fn($this->client());
        } catch (ConnectException $e) {
            if ($this->webUrl === '' || $this->fallbackActive) {
                throw $e;
            }
            $this->logger->warning('HostAgentClient: web pool unreachable, falling back to exec pool', [
                'web_url'   => $this->webUrl,
                'exec_url'  => $this->agentUrl,
                'message'   => $e->getMessage(),
            ]);
            $this->fallbackActive = true;
            $this->guzzle         = null; // Force rebuild against agentUrl
            try {
                return $fn($this->client());
            } catch (ConnectException $e2) {
                throw new \RuntimeException(
                    'Agent unreachable (web pool + exec pool): ' . $e2->getMessage(),
                    0,
                    $e2,
                );
            }
        }
    }

    private function client(): Client
    {
        if ($this->guzzle === null) {
            if (!$this->sslVerify) {
                $verify = false;
            } elseif ($this->sslCaBundle !== '') {
                $verify = $this->sslCaBundle;
            } else {
                $verify = true;
            }

            // When fallback is active use the exec pool URL, otherwise prefer
            // the web pool URL when one is configured.
            $baseUrl = (!$this->fallbackActive && $this->webUrl !== '')
                ? $this->webUrl
                : $this->agentUrl;

            $this->guzzle = new Client([
                'base_uri'        => rtrim($baseUrl, '/'),
                'timeout'         => $this->timeout,
                // Without an explicit connect_timeout cURL falls back to its
                // 300 s default, so an unreachable agent blocks for the full
                // request timeout (10 s). Capping the TCP/TLS connect phase
                // makes dead agents fail fast while slow responses still get
                // the full timeout budget.
                'connect_timeout' => min(3, max(1, $this->timeout)),
                'verify'          => $verify,
                'http_errors'     => false,  // We handle error codes ourselves
            ]);
        }

        return $this->guzzle;
    }

    /**
     * Build the HMAC-SHA256 signature for a request.
     *
     * The message is: UPPER(method) + path + rawBody + NUL + userId + NUL + username
     * NUL separators prevent collisions between adjacent fields.
     *
     * @param string $method  HTTP method (will be uppercased).
     * @param string $path    Request path (without query string).
     * @param string $rawBody Raw request body (empty string for GET/DELETE).
     *
     * @return string Hex-encoded HMAC-SHA256 digest.
     */
    private function sign(string $method, string $path, string $rawBody): string
    {
        $message = strtoupper($method) . $path . $rawBody
            . "\0" . $this->userId . "\0" . $this->username;

        return hash_hmac('sha256', $message, $this->hmacSecret);
    }

    /**
     * Inspect the Guzzle response status code and decode the JSON body.
     *
     * Logs a warning and throws \RuntimeException for HTTP 4xx/5xx responses.
     *
     * @param string                                  $method   HTTP method (for logging).
     * @param string                                  $path     Request path (for logging).
     * @param \Psr\Http\Message\ResponseInterface     $response Guzzle response.
     *
     * @throws \RuntimeException When the response status indicates an error.
     *
     * @return array<string,mixed> Decoded JSON response body.
     */
    private function handleResponse(
        string $method,
        string $path,
        \Psr\Http\Message\ResponseInterface $response,
    ): array {
        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        if ($status >= 400) {
            $this->logger->warning('HostAgentClient: agent returned error status', [
                'method' => $method,
                'path'   => $path,
                'status' => $status,
                'body'   => substr($body, 0, 500),
            ]);
            $decoded = json_decode($body, associative: true);
            throw new AgentHttpException(
                $status,
                "Agent error {$status}: {$body}",
                is_array($decoded) ? $decoded : [],
            );
        }

        $decoded = json_decode($body, associative: true);

        if (!is_array($decoded)) {
            return [];
        }

        // Capture optional _perf payload added by the agent PerformanceCollector.
        if (isset($decoded['_perf']) && is_array($decoded['_perf'])) {
            $this->lastPerf = $decoded['_perf'];
        }

        return $decoded;
    }
}
