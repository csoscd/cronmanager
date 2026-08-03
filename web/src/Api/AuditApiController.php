<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Audit Log API Controller
 *
 * Handles the /api/v1/audit REST endpoint.
 *
 * Route:
 *   GET  /api/v1/audit     audit:read
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Api;

use Cronmanager\Web\Auth\ApiKeyMiddleware;
use Cronmanager\Web\Auth\ScopeHelper;
use Cronmanager\Web\Database\Connection;

/**
 * Class AuditApiController
 *
 * Proxies audit log reads from the agent and returns paginated JSON.
 * Requires the `audit:read` scope; only admin users may grant this scope.
 */
final class AuditApiController extends BaseApiController
{
    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/audit
     *
     * Return a paginated list of audit log entries with optional filters.
     *
     * Supported query parameters:
     *   limit         – max entries per page (default 100, max 500)
     *   offset        – pagination offset (default 0)
     *   username      – filter by exact username
     *   action_prefix – filter by action prefix (e.g. "cron" matches "cron.create")
     *   date_from     – lower bound on created_at (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)
     *   date_to       – upper bound on created_at
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function index(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_AUDIT_READ);

        if ($apiKey === null) {
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $query = array_filter([
            'limit'         => (int)    ($_GET['limit']         ?? 100),
            'offset'        => (int)    ($_GET['offset']        ?? 0),
            'username'      => (string) ($_GET['username']      ?? ''),
            'action_prefix' => (string) ($_GET['action_prefix'] ?? ''),
            'date_from'     => (string) ($_GET['date_from']     ?? ''),
            'date_to'       => (string) ($_GET['date_to']       ?? ''),
        ], static fn ($v): bool => $v !== '' && $v !== 0);

        $response = $this->agentGet($agent, '/audit/logs', $query);
        if ($response === null) {
            return;
        }

        $this->jsonOk(array_merge(['agent_id' => $this->resolvedAgentId], $response));
    }
}
