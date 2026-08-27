<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Jobs API Controller
 *
 * Handles all /api/v1/jobs/* and /api/v1/executions/* REST endpoints.
 *
 * Routes:
 *   GET    /api/v1/jobs                  jobs:read
 *   GET    /api/v1/jobs/{id}             jobs:read
 *   POST   /api/v1/jobs                  jobs:write
 *   PUT    /api/v1/jobs/{id}             jobs:write
 *   DELETE /api/v1/jobs/{id}             jobs:write
 *   POST   /api/v1/jobs/{id}/execute     jobs:execute
 *   POST   /api/v1/executions/{id}/kill             jobs:execute
 *   POST   /api/v1/executions/{id}/acknowledge     executions:acknowledge
 *   DELETE /api/v1/executions/{id}/acknowledge     executions:acknowledge
 *   GET    /api/v1/jobs/{id}/history               jobs:read
 *   GET    /api/v1/tags                  jobs:read
 *   GET    /api/v1/targets               jobs:read
 *   GET    /api/v1/linux-users           jobs:read
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Api;

use Cronmanager\Web\Auth\ApiKey;
use Cronmanager\Web\Auth\ApiKeyMiddleware;
use Cronmanager\Web\Auth\ScopeHelper;
use Cronmanager\Web\Database\Connection;

/**
 * Class JobsApiController
 */
final class JobsApiController extends BaseApiController
{
    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/jobs
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function index(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_JOBS_READ);

        if ($apiKey === null) {
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        ['limit' => $limit, 'offset' => $offset] = $this->pagination();

        $query = array_filter([
            'tag'    => $_GET['tag']    ?? null,
            'user'   => $_GET['user']   ?? null,
            'target' => $_GET['target'] ?? null,
            'search' => $_GET['search'] ?? null,
            'active' => isset($_GET['active']) ? (string) (int) $_GET['active'] : null,
        ], fn($v) => $v !== null);

        $response = $this->agentGet($agent, '/crons', $query);
        if ($response === null) {
            return;
        }

        $all  = $response['data'] ?? $response;
        $total = count($all);

        $page = array_slice((array) $all, $offset, $limit);

        $this->jsonOk(array_merge(['agent_id' => $this->resolvedAgentId], $this->paginated($page, $total, $limit, $offset)));
    }

    /**
     * GET /api/v1/jobs/{id}
     *
     * @param array<string, string> $params Path parameters: id.
     *
     * @return void
     */
    public function show(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_JOBS_READ);

        if ($apiKey === null) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->jsonError(400, 'Bad Request', 'Job ID must be a positive integer.');
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentGet($agent, "/crons/{$id}");
        if ($response === null) {
            return;
        }

        $this->jsonOk(array_merge(['agent_id' => $this->resolvedAgentId], $response));
    }

    /**
     * POST /api/v1/jobs
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function store(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_JOBS_WRITE);

        if ($apiKey === null) {
            return;
        }

        $body = $this->parseJsonBody();
        if ($body === null) {
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentPost($agent, '/crons', $body);
        if ($response === null) {
            return;
        }

        $this->jsonOk(array_merge(['agent_id' => $this->resolvedAgentId], $response), 201);
    }

    /**
     * PUT /api/v1/jobs/{id}
     *
     * @param array<string, string> $params Path parameters: id.
     *
     * @return void
     */
    public function update(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_JOBS_WRITE);

        if ($apiKey === null) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->jsonError(400, 'Bad Request', 'Job ID must be a positive integer.');
            return;
        }

        $body = $this->parseJsonBody();
        if ($body === null) {
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentPut($agent, "/crons/{$id}", $body);
        if ($response === null) {
            return;
        }

        $this->jsonOk(array_merge(['agent_id' => $this->resolvedAgentId], $response));
    }

    /**
     * DELETE /api/v1/jobs/{id}
     *
     * @param array<string, string> $params Path parameters: id.
     *
     * @return void
     */
    public function destroy(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_JOBS_WRITE);

        if ($apiKey === null) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->jsonError(400, 'Bad Request', 'Job ID must be a positive integer.');
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentDelete($agent, "/crons/{$id}");
        if ($response === null) {
            return;
        }

        $this->jsonOk(['agent_id' => $this->resolvedAgentId, 'success' => true]);
    }

    /**
     * POST /api/v1/jobs/{id}/execute
     *
     * @param array<string, string> $params Path parameters: id.
     *
     * @return void
     */
    public function execute(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_JOBS_EXECUTE);

        if ($apiKey === null) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->jsonError(400, 'Bad Request', 'Job ID must be a positive integer.');
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentPost($agent, "/crons/{$id}/execute");
        if ($response === null) {
            return;
        }

        $this->jsonOk(['agent_id' => $this->resolvedAgentId, 'success' => true, 'message' => 'Job queued for immediate execution.']);
    }

    /**
     * POST /api/v1/executions/{id}/kill
     *
     * @param array<string, string> $params Path parameters: id (execution log ID).
     *
     * @return void
     */
    public function kill(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_JOBS_EXECUTE);

        if ($apiKey === null) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->jsonError(400, 'Bad Request', 'Execution ID must be a positive integer.');
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentPost($agent, "/execution/{$id}/kill");
        if ($response === null) {
            return;
        }

        $this->jsonOk(['agent_id' => $this->resolvedAgentId, 'success' => true]);
    }

    /**
     * POST /api/v1/executions/{id}/acknowledge
     *
     * @param array<string, string> $params Path parameters: id (execution log ID).
     * @return void
     */
    public function acknowledge(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_EXECUTIONS_ACKNOWLEDGE);

        if ($apiKey === null) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->jsonError(400, 'Bad Request', 'Execution ID must be a positive integer.');
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentPost($agent, "/execution/{$id}/acknowledge");
        if ($response === null) {
            return;
        }

        $this->jsonOk(['agent_id' => $this->resolvedAgentId, 'acknowledged' => true]);
    }

    /**
     * DELETE /api/v1/executions/{id}/acknowledge
     *
     * @param array<string, string> $params Path parameters: id (execution log ID).
     * @return void
     */
    public function unacknowledge(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_EXECUTIONS_ACKNOWLEDGE);

        if ($apiKey === null) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->jsonError(400, 'Bad Request', 'Execution ID must be a positive integer.');
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentDelete($agent, "/execution/{$id}/acknowledge");
        if ($response === null) {
            return;
        }

        $this->jsonOk(['agent_id' => $this->resolvedAgentId, 'acknowledged' => false]);
    }

    /**
     * GET /api/v1/jobs/{id}/history
     *
     * @param array<string, string> $params Path parameters: id.
     *
     * @return void
     */
    public function history(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_JOBS_READ);

        if ($apiKey === null) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->jsonError(400, 'Bad Request', 'Job ID must be a positive integer.');
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        ['limit' => $limit, 'offset' => $offset] = $this->pagination();

        $query = array_filter([
            'job_id' => (string) $id,
            'status' => $_GET['status'] ?? null,
            'limit'  => (string) $limit,
            'offset' => (string) $offset,
        ], fn($v) => $v !== null);

        $response = $this->agentGet($agent, '/history', $query);
        if ($response === null) {
            return;
        }

        $data  = $response['data']  ?? $response;
        $count = $response['count'] ?? count((array) $data);

        $this->jsonOk(array_merge(['agent_id' => $this->resolvedAgentId], $this->paginated((array) $data, (int) $count, $limit, $offset)));
    }

    /**
     * GET /api/v1/tags
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function tags(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_JOBS_READ);

        if ($apiKey === null) {
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentGet($agent, '/tags');
        if ($response === null) {
            return;
        }

        $data  = $response['data']  ?? $response;
        $count = is_array($data) ? count($data) : 0;

        $this->jsonOk(['agent_id' => $this->resolvedAgentId, 'data' => $data, 'count' => $count]);
    }

    /**
     * GET /api/v1/targets
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function targets(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_JOBS_READ);

        if ($apiKey === null) {
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $query = array_filter([
            'active' => isset($_GET['active']) ? (string) (int) $_GET['active'] : null,
        ], fn($v) => $v !== null);

        $response = $this->agentGet($agent, '/targets', $query);
        if ($response === null) {
            return;
        }

        $data  = $response['data']  ?? $response;
        $count = is_array($data) ? count($data) : 0;

        $this->jsonOk(['agent_id' => $this->resolvedAgentId, 'data' => $data, 'count' => $count]);
    }

    /**
     * GET /api/v1/linux-users
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function linuxUsers(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_JOBS_READ);

        if ($apiKey === null) {
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentGet($agent, '/linux-users');
        if ($response === null) {
            return;
        }

        $data       = $response['data']        ?? [];
        $dockerMode = (bool) ($response['docker_mode'] ?? false);
        $count      = is_array($data) ? count($data) : 0;

        $this->jsonOk([
            'agent_id'    => $this->resolvedAgentId,
            'docker_mode' => $dockerMode,
            'data'        => $data,
            'count'       => $count,
        ]);
    }

    /**
     * GET /api/v1/timeline
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function timeline(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_JOBS_READ);

        if ($apiKey === null) {
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        ['limit' => $limit, 'offset' => $offset] = $this->pagination();

        $validStatuses = ['success', 'failed', 'running'];
        $status        = isset($_GET['status']) && $_GET['status'] !== '' ? (string) $_GET['status'] : null;

        if ($status !== null && !in_array($status, $validStatuses, strict: true)) {
            $this->jsonError(400, 'Bad Request', 'Parameter "status" must be one of: ' . implode(', ', $validStatuses) . '.');
            return;
        }

        $query = array_filter([
            'status' => $status,
            'tag'    => $_GET['tag'] ?? null,
            'limit'  => (string) $limit,
            'offset' => (string) $offset,
        ], fn($v) => $v !== null);

        $response = $this->agentGet($agent, '/history', $query);
        if ($response === null) {
            return;
        }

        $data  = $response['data']  ?? $response;
        $count = $response['count'] ?? count((array) $data);

        $this->jsonOk(array_merge(['agent_id' => $this->resolvedAgentId], $this->paginated((array) $data, (int) $count, $limit, $offset)));
    }
}
