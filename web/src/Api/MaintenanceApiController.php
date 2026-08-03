<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Maintenance Windows API Controller
 *
 * Handles all /api/v1/maintenance/* REST endpoints.
 *
 * Routes:
 *   GET    /api/v1/maintenance/windows          maintenance:read
 *   GET    /api/v1/maintenance/windows/{id}     maintenance:read
 *   POST   /api/v1/maintenance/windows          maintenance:write
 *   PUT    /api/v1/maintenance/windows/{id}     maintenance:write
 *   DELETE /api/v1/maintenance/windows/{id}     maintenance:write
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Api;

use Cronmanager\Web\Auth\ApiKeyMiddleware;
use Cronmanager\Web\Auth\ScopeHelper;
use Cronmanager\Web\Database\Connection;

/**
 * Class MaintenanceApiController
 */
final class MaintenanceApiController extends BaseApiController
{
    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/maintenance/windows
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function index(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_MAINTENANCE_READ);

        if ($apiKey === null) {
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $query = [];
        if (isset($_GET['target']) && $_GET['target'] !== '') {
            $query['target'] = (string) $_GET['target'];
        }

        $response = $this->agentGet($agent, '/maintenance/windows', $query);
        if ($response === null) {
            return;
        }

        $data  = $response['data'] ?? $response;
        $count = is_array($data) ? count($data) : 0;

        $this->jsonOk(['agent_id' => $this->resolvedAgentId, 'data' => $data, 'count' => $count]);
    }

    /**
     * GET /api/v1/maintenance/windows/{id}
     *
     * @param array<string, string> $params Path parameters: id.
     *
     * @return void
     */
    public function show(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_MAINTENANCE_READ);

        if ($apiKey === null) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->jsonError(400, 'Bad Request', 'Window ID must be a positive integer.');
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentGet($agent, "/maintenance/windows/{$id}");
        if ($response === null) {
            return;
        }

        $this->jsonOk(array_merge(['agent_id' => $this->resolvedAgentId], $response));
    }

    /**
     * POST /api/v1/maintenance/windows
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function store(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_MAINTENANCE_WRITE);

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

        $response = $this->agentPost($agent, '/maintenance/windows', $body);
        if ($response === null) {
            return;
        }

        $this->jsonOk(array_merge(['agent_id' => $this->resolvedAgentId], $response), 201);
    }

    /**
     * PUT /api/v1/maintenance/windows/{id}
     *
     * @param array<string, string> $params Path parameters: id.
     *
     * @return void
     */
    public function update(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_MAINTENANCE_WRITE);

        if ($apiKey === null) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->jsonError(400, 'Bad Request', 'Window ID must be a positive integer.');
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

        $response = $this->agentPut($agent, "/maintenance/windows/{$id}", $body);
        if ($response === null) {
            return;
        }

        $this->jsonOk(array_merge(['agent_id' => $this->resolvedAgentId], $response));
    }

    /**
     * DELETE /api/v1/maintenance/windows/{id}
     *
     * @param array<string, string> $params Path parameters: id.
     *
     * @return void
     */
    public function destroy(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_MAINTENANCE_WRITE);

        if ($apiKey === null) {
            return;
        }

        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->jsonError(400, 'Bad Request', 'Window ID must be a positive integer.');
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentDelete($agent, "/maintenance/windows/{$id}");
        if ($response === null) {
            return;
        }

        $this->jsonOk(['agent_id' => $this->resolvedAgentId, 'success' => true]);
    }
}
