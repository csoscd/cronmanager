<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Agents API Controller
 *
 * Handles the /api/v1/agents REST endpoint.  Returns the list of configured
 * agents visible to the calling API key, omitting all sensitive connection
 * fields (url, hmac_secret, ssl_ca_bundle).
 *
 * Routes:
 *   GET /api/v1/agents    settings:read
 *
 * The endpoint reads directly from the local `agents` table in MariaDB and
 * therefore does not need a HostAgentClient.  Agent-ID filtering is applied
 * according to the `agent_ids` restriction stored on the API key: a key with
 * agent_ids = [1, 3] only sees agents 1 and 3; a key with agent_ids = null
 * sees all agents.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Api;

use Cronmanager\Web\Auth\ApiKeyMiddleware;
use Cronmanager\Web\Auth\ScopeHelper;
use Cronmanager\Web\Repository\AgentRepository;

/**
 * Class AgentsApiController
 */
class AgentsApiController extends BaseApiController
{
    // =========================================================================
    // Actions
    // =========================================================================

    /**
     * GET /api/v1/agents
     *
     * List all agents visible to this API key.
     *
     * @param array<string, string> $params Route parameters (unused).
     */
    public function index(array $params): void
    {
        $apiKey = (new ApiKeyMiddleware($this->pdo, $this->logger))
            ->authenticate(ScopeHelper::SCOPE_SETTINGS_READ);

        if ($apiKey === null) {
            return;
        }

        $repo       = new AgentRepository($this->pdo);
        $agents     = $repo->findAll();
        $allowedIds = $apiKey->agentIds; // null = unrestricted, array = restricted

        if ($allowedIds !== null) {
            $agents = array_values(
                array_filter(
                    $agents,
                    static fn(array $a): bool => in_array((int) $a['id'], $allowedIds, true)
                )
            );
        }

        $webUrl = rtrim((string) $this->config->get('app.web_url', ''), '/') ?: null;

        $data = array_map(fn(array $a): array => [
            'id'          => (int)    $a['id'],
            'name'        => (string) $a['name'],
            'description' => $a['description'] !== null ? (string) $a['description'] : null,
            'enabled'     => (bool)   $a['enabled'],
            'web_url'     => $webUrl,
        ], $agents);

        $this->jsonOk([
            'data'  => $data,
            'count' => count($data),
        ]);
    }
}
