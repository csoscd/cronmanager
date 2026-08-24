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
 *   POST   /api/v1/maintenance/logs/purge       maintenance:write
 *   POST   /api/v1/maintenance/history/cleanup  maintenance:write
 *   POST   /api/v1/maintenance/once/cleanup     maintenance:write
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

    /**
     * POST /api/v1/maintenance/logs/purge
     *
     * Triggers immediate deletion of finished execution_log rows that exceed
     * the agent's configured retention period.  Equivalent to the
     * "Logs jetzt bereinigen" button in the web UI.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function purgeLogs(array $params): void
    {
        $apiKey = (new ApiKeyMiddleware($this->pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_MAINTENANCE_WRITE);

        if ($apiKey === null) {
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentPost($agent, '/maintenance/logs/prune');
        if ($response === null) {
            return;
        }

        $this->jsonOk(array_merge(['agent_id' => $this->resolvedAgentId], $response));
    }

    /**
     * POST /api/v1/maintenance/history/cleanup
     *
     * Permanently deletes finished execution_log rows older than the given
     * number of days.  Running executions are never deleted.  Equivalent to
     * the "Historien-Bereinigung" action in the web UI settings.
     *
     * Request body (JSON):
     * ```json
     * { "older_than_days": 90 }
     * ```
     * `older_than_days` is optional and defaults to 90 on the agent side.
     * When provided it must be a positive integer (≥ 1).
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function historyCleanup(array $params): void
    {
        $apiKey = (new ApiKeyMiddleware($this->pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_MAINTENANCE_WRITE);

        if ($apiKey === null) {
            return;
        }

        // Body is optional: an empty body means "use the agent's default (90 days)".
        $raw  = (string) file_get_contents('php://input');
        $body = [];

        if ($raw !== '') {
            $parsed = $this->parseJsonBody();
            if ($parsed === null) {
                return;
            }
            $body = $parsed;
        }

        if (isset($body['older_than_days'])) {
            $days = (int) $body['older_than_days'];
            if ($days < 1) {
                $this->jsonError(400, 'Bad Request', 'older_than_days must be a positive integer (≥ 1).');
                return;
            }
            $body['older_than_days'] = $days;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentPost($agent, '/maintenance/history/cleanup', $body);
        if ($response === null) {
            return;
        }

        $this->jsonOk(array_merge(['agent_id' => $this->resolvedAgentId], $response));
    }

    /**
     * POST /api/v1/maintenance/once/cleanup
     *
     * Removes stale "cronmanager-once" crontab entries left behind by Run Now
     * jobs whose automatic self-cleanup call failed (e.g. the agent was
     * temporarily unreachable).  Equivalent to the "Run-Now-Bereinigung"
     * button in the web UI settings.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function onceCleanup(array $params): void
    {
        $apiKey = (new ApiKeyMiddleware($this->pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_MAINTENANCE_WRITE);

        if ($apiKey === null) {
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentPost($agent, '/maintenance/once/cleanup');
        if ($response === null) {
            return;
        }

        $this->jsonOk(array_merge(['agent_id' => $this->resolvedAgentId], $response));
    }
}
