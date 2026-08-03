<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Abstract Base API Controller
 *
 * Provides shared helpers for all external REST API controllers:
 * JSON response emission, error handling, agent client construction, and
 * pagination utilities.
 *
 * API controllers differ from web UI controllers in that they:
 *   - Never render HTML templates
 *   - Always return JSON
 *   - Use ApiKey-based auth (no session, no CSRF)
 *   - Determine the target agent from the X-Agent-Id header
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Api;

use Cronmanager\Web\Agent\AgentHttpException;
use Cronmanager\Web\Agent\HostAgentClient;
use Cronmanager\Web\Auth\ApiKey;
use Cronmanager\Web\Repository\AgentRepository;
use Monolog\Logger;
use Noodlehaus\Config;

/**
 * Class BaseApiController
 *
 * Abstract base for all /api/v1/* controllers.
 */
abstract class BaseApiController
{
    // -------------------------------------------------------------------------
    // Pagination defaults
    // -------------------------------------------------------------------------

    protected const DEFAULT_LIMIT = 100;
    protected const MAX_LIMIT     = 500;

    // -------------------------------------------------------------------------
    // Resolved agent context
    // -------------------------------------------------------------------------

    /**
     * The DB id of the agent resolved by the most recent agentClient() call.
     * Injected into every agent-specific response so API consumers can
     * unambiguously identify which agent a job/execution/window belongs to
     * (job IDs are only unique per agent, not globally).
     */
    protected int $resolvedAgentId = 0;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param Config $config Noodlehaus configuration instance.
     * @param Logger $logger Monolog logger.
     * @param \PDO   $pdo   Active database connection (for agent lookup).
     */
    public function __construct(
        protected readonly Config $config,
        protected readonly Logger $logger,
        protected readonly \PDO   $pdo,
    ) {}

    // -------------------------------------------------------------------------
    // Response helpers
    // -------------------------------------------------------------------------

    /**
     * Emit a successful JSON response.
     *
     * @param array<string, mixed>|list<mixed> $data   Payload to encode.
     * @param int                              $status HTTP status code.
     *
     * @return void
     */
    protected function jsonOk(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Emit a JSON error response.
     *
     * @param int    $status  HTTP status code.
     * @param string $error   Short error type.
     * @param string $message Human-readable explanation.
     *
     * @return void
     */
    protected function jsonError(int $status, string $error, string $message): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error'   => $error,
            'message' => $message,
            'code'    => $status,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Emit a JSON validation error response.
     *
     * @param array<string, string> $fields Field-level error messages.
     *
     * @return void
     */
    protected function jsonValidationError(array $fields): void
    {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error'  => 'Validation failed',
            'fields' => $fields,
            'code'   => 422,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    // -------------------------------------------------------------------------
    // Agent client
    // -------------------------------------------------------------------------

    /**
     * Build a HostAgentClient for the agent targeted by this API request.
     *
     * The agent is determined by the optional `X-Agent-Id` request header.
     * If the header is absent, the first enabled agent is used.
     * If the API key restricts agent access, the requested agent must be in
     * the key's allowed list.
     *
     * @param ApiKey $apiKey The authenticated API key (for agent-scope check).
     *
     * @return HostAgentClient|null Returns null and emits a JSON error when the
     *                              agent cannot be resolved or is not permitted.
     */
    protected function agentClient(ApiKey $apiKey): ?HostAgentClient
    {
        $agentRepo = new AgentRepository($this->pdo);
        $agents    = $agentRepo->findAll();

        if ($agents === []) {
            $this->jsonError(502, 'No agents configured', 'No agent is configured in this Cronmanager instance.');
            return null;
        }

        // Determine requested agent
        $requestedId = isset($_SERVER['HTTP_X_AGENT_ID'])
            ? (int) $_SERVER['HTTP_X_AGENT_ID']
            : null;

        if ($requestedId !== null) {
            $agentRow = null;
            foreach ($agents as $row) {
                if ((int) $row['id'] === $requestedId) {
                    $agentRow = $row;
                    break;
                }
            }

            if ($agentRow === null) {
                $this->jsonError(404, 'Agent not found', "Agent with ID {$requestedId} does not exist.");
                return null;
            }
        } else {
            // Default: first enabled agent
            $agentRow = null;
            foreach ($agents as $row) {
                if ((bool) $row['enabled']) {
                    $agentRow = $row;
                    break;
                }
            }

            if ($agentRow === null) {
                $this->jsonError(502, 'No agents available', 'No enabled agent found.');
                return null;
            }
        }

        // API key agent scope check
        if (!$apiKey->isAgentAllowed((int) $agentRow['id'])) {
            $this->jsonError(403, 'Agent not permitted', 'This API key is not allowed to access the requested agent.');
            return null;
        }

        $this->resolvedAgentId = (int) $agentRow['id'];

        return new HostAgentClient(
            logger:      $this->logger,
            agentUrl:    (string) $agentRow['url'],
            hmacSecret:  (string) $agentRow['hmac_secret'],
            userId:      $apiKey->userId,
            username:    'api:' . $apiKey->name,
            timeout:     (int)    ($agentRow['timeout']     ?? 10),
            sslVerify:   (bool)   ($agentRow['ssl_verify']  ?? true),
            sslCaBundle: (string) ($agentRow['ssl_ca_bundle'] ?? ''),
        );
    }

    // -------------------------------------------------------------------------
    // Pagination
    // -------------------------------------------------------------------------

    /**
     * Extract and clamp limit and offset from query parameters.
     *
     * @return array{limit: int, offset: int}
     */
    protected function pagination(): array
    {
        $limit  = min((int) ($_GET['limit']  ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        return ['limit' => $limit, 'offset' => $offset];
    }

    /**
     * Wrap a data array in the standard paginated envelope.
     *
     * @param list<mixed> $data   Items for the current page.
     * @param int         $count  Total item count (before pagination).
     * @param int         $limit  Applied limit.
     * @param int         $offset Applied offset.
     *
     * @return array<string, mixed>
     */
    protected function paginated(array $data, int $count, int $limit, int $offset): array
    {
        return [
            'data'   => $data,
            'count'  => $count,
            'limit'  => $limit,
            'offset' => $offset,
        ];
    }

    // -------------------------------------------------------------------------
    // Request body
    // -------------------------------------------------------------------------

    /**
     * Parse the JSON request body and return it as an associative array.
     * Returns null and emits a 400 error on malformed JSON or non-object body.
     *
     * @return array<string, mixed>|null
     */
    protected function parseJsonBody(): ?array
    {
        $raw = (string) file_get_contents('php://input');

        if ($raw === '') {
            $this->jsonError(400, 'Empty body', 'Request body must be valid JSON.');
            return null;
        }

        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->jsonError(400, 'Invalid JSON', 'Request body could not be parsed as JSON.');
            return null;
        }

        if (!is_array($body)) {
            $this->jsonError(400, 'Invalid body', 'Request body must be a JSON object.');
            return null;
        }

        return $body;
    }

    // -------------------------------------------------------------------------
    // Agent proxy helpers
    // -------------------------------------------------------------------------

    /**
     * Proxy a GET request to the agent and return the decoded response.
     * On agent error, emits an appropriate JSON error and returns null.
     *
     * @param HostAgentClient $agent Agent client instance.
     * @param string          $path  Agent endpoint path.
     * @param array<string, string> $query Query parameters.
     *
     * @return array<string, mixed>|null
     */
    protected function agentGet(HostAgentClient $agent, string $path, array $query = []): ?array
    {
        try {
            return $agent->get($path, $query);
        } catch (AgentHttpException $e) {
            $this->handleAgentException($e);
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Agent GET failed', ['path' => $path, 'error' => $e->getMessage()]);
            $this->jsonError(502, 'Agent unreachable', 'Could not reach the host agent.');
            return null;
        }
    }

    /**
     * Proxy a POST request to the agent and return the decoded response.
     *
     * @param HostAgentClient      $agent Agent client instance.
     * @param string               $path  Agent endpoint path.
     * @param array<string, mixed> $body  Request payload.
     *
     * @return array<string, mixed>|null
     */
    protected function agentPost(HostAgentClient $agent, string $path, array $body = []): ?array
    {
        try {
            return $agent->post($path, $body);
        } catch (AgentHttpException $e) {
            $this->handleAgentException($e);
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Agent POST failed', ['path' => $path, 'error' => $e->getMessage()]);
            $this->jsonError(502, 'Agent unreachable', 'Could not reach the host agent.');
            return null;
        }
    }

    /**
     * Proxy a PUT request to the agent and return the decoded response.
     *
     * @param HostAgentClient      $agent Agent client instance.
     * @param string               $path  Agent endpoint path.
     * @param array<string, mixed> $body  Request payload.
     *
     * @return array<string, mixed>|null
     */
    protected function agentPut(HostAgentClient $agent, string $path, array $body = []): ?array
    {
        try {
            return $agent->put($path, $body);
        } catch (AgentHttpException $e) {
            $this->handleAgentException($e);
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Agent PUT failed', ['path' => $path, 'error' => $e->getMessage()]);
            $this->jsonError(502, 'Agent unreachable', 'Could not reach the host agent.');
            return null;
        }
    }

    /**
     * Proxy a DELETE request to the agent and return the decoded response.
     *
     * @param HostAgentClient $agent Agent client instance.
     * @param string          $path  Agent endpoint path.
     *
     * @return array<string, mixed>|null
     */
    protected function agentDelete(HostAgentClient $agent, string $path): ?array
    {
        try {
            return $agent->delete($path);
        } catch (AgentHttpException $e) {
            $this->handleAgentException($e);
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Agent DELETE failed', ['path' => $path, 'error' => $e->getMessage()]);
            $this->jsonError(502, 'Agent unreachable', 'Could not reach the host agent.');
            return null;
        }
    }

    /**
     * Map an AgentHttpException to an appropriate JSON error response.
     *
     * The exception message contains the raw agent response body; we attempt
     * to extract a structured error message from it.
     *
     * @param AgentHttpException $e The exception thrown by the agent client.
     *
     * @return void
     */
    private function handleAgentException(AgentHttpException $e): void
    {
        $statusCode = $e->getStatusCode();

        $status = match (true) {
            $statusCode === 404 => 404,
            $statusCode === 422 => 422,
            $statusCode >= 500  => 502,
            default             => 502,
        };

        // Message format: "Agent error <code>: <json-body>"
        $parts   = explode(': ', $e->getMessage(), 2);
        $decoded = isset($parts[1]) ? json_decode($parts[1], true) : null;

        $this->jsonError(
            $status,
            (is_array($decoded) && isset($decoded['error']))   ? (string) $decoded['error']   : 'Agent error',
            (is_array($decoded) && isset($decoded['message'])) ? (string) $decoded['message'] : $e->getMessage(),
        );
    }
}
