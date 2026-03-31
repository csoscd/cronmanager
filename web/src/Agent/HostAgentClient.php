<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Host Agent HTTP Client
 *
 * The sole communication bridge between the web UI container and the host
 * agent process.  Every request is signed with an HMAC-SHA256 signature so
 * the agent can verify that the call originated from a trusted source.
 *
 * Config keys used:
 *   agent.url         – Base URL of the host agent (e.g. http://host.docker.internal:8865)
 *   agent.hmac_secret – Shared secret for HMAC-SHA256 request signing
 *   agent.timeout     – Request timeout in seconds (default: 10)
 *
 * Signature algorithm:
 *   X-Agent-Signature: hmac_sha256(secret, UPPER(method) + path + rawBody)
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Agent;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Monolog\Logger;
use Noodlehaus\Config;

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

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param Config $config Noodlehaus configuration instance.
     * @param Logger $logger Monolog logger.
     */
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

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

        try {
            $response = $this->client()->request('GET', $path . $queryString, [
                'headers' => [
                    'X-Agent-Signature' => $signature,
                    'Accept'            => 'application/json',
                ],
            ]);
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

        try {
            $response = $this->client()->request('POST', $path, [
                'headers' => [
                    'X-Agent-Signature' => $signature,
                    'Content-Type'      => 'application/json',
                    'Accept'            => 'application/json',
                ],
                'body' => $rawBody,
            ]);
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

        try {
            $response = $this->client()->request('PUT', $path, [
                'headers' => [
                    'X-Agent-Signature' => $signature,
                    'Content-Type'      => 'application/json',
                    'Accept'            => 'application/json',
                ],
                'body' => $rawBody,
            ]);
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

        try {
            $response = $this->client()->request('DELETE', $path, [
                'headers' => [
                    'X-Agent-Signature' => $signature,
                    'Accept'            => 'application/json',
                ],
            ]);
        } catch (ConnectException $e) {
            $this->logger->error('HostAgentClient: connection failed on DELETE', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Agent unreachable: ' . $e->getMessage(), 0, $e);
        }

        return $this->handleResponse('DELETE', $path, $response);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return a lazily-created Guzzle HTTP client configured with the agent base URL
     * and request timeout from configuration.
     *
     * @return Client
     */
    private function client(): Client
    {
        if ($this->guzzle === null) {
            $baseUrl = (string) $this->config->get('agent.url', 'http://host.docker.internal:8865');
            $timeout = (int)    $this->config->get('agent.timeout', 10);

            $this->guzzle = new Client([
                'base_uri'    => rtrim($baseUrl, '/'),
                'timeout'     => $timeout,
                'http_errors' => false,  // We handle error codes ourselves
            ]);
        }

        return $this->guzzle;
    }

    /**
     * Build the HMAC-SHA256 signature for a request.
     *
     * The message is: UPPER(method) + path + rawBody
     *
     * @param string $method  HTTP method (will be uppercased).
     * @param string $path    Request path (without query string).
     * @param string $rawBody Raw request body (empty string for GET/DELETE).
     *
     * @return string Hex-encoded HMAC-SHA256 digest.
     */
    private function sign(string $method, string $path, string $rawBody): string
    {
        $secret  = (string) $this->config->get('agent.hmac_secret', '');
        $message = strtoupper($method) . $path . $rawBody;

        return hash_hmac('sha256', $message, $secret);
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
            throw new AgentHttpException($status, "Agent error {$status}: {$body}");
        }

        $decoded = json_decode($body, associative: true);

        return is_array($decoded) ? $decoded : [];
    }
}
