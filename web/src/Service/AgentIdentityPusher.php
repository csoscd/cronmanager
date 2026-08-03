<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – AgentIdentityPusher
 *
 * Pushes the web container's public URL and per-agent web ID to each agent so
 * that notification links (email / Telegram) can include an ?agent_id=X query
 * parameter that auto-selects the correct agent context after login.
 *
 * The push is idempotent: agents overwrite their agent_settings row on every
 * call, so there is no need to check for prior values.
 *
 * Call sites:
 *   - web/public/index.php   – once per PHP-FPM worker process at startup
 *   - AgentController::store()  – immediately after creating a new agent
 *   - AgentController::update() – immediately after updating an agent
 *   - AgentController::select() – immediately before the session redirect
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Service;

use Cronmanager\Web\Agent\HostAgentClient;
use Cronmanager\Web\Repository\AgentRepository;
use Monolog\Logger;
use PDO;

/**
 * Class AgentIdentityPusher
 *
 * Sends PUT /settings/web-identity to one or all enabled agents, storing
 * the web container's public URL and the agent's own web-side ID.
 */
final class AgentIdentityPusher
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param Logger $logger  Monolog logger instance.
     * @param PDO    $pdo     Active database connection (used to read agents).
     * @param string $webUrl  Public base URL of the web UI (no trailing slash).
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly PDO    $pdo,
        private readonly string $webUrl,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Push web identity to a single agent.
     *
     * Swallows all exceptions: an unreachable agent must not prevent normal
     * operation (the next successful push will repair the stored value).
     *
     * @param array<string, mixed> $agent Agent row from the `agents` table.
     *
     * @return void
     */
    public function pushToAgent(array $agent): void
    {
        $agentId = (int) ($agent['id'] ?? 0);
        if ($agentId <= 0 || $this->webUrl === '') {
            return;
        }

        try {
            $client = new HostAgentClient(
                logger:      $this->logger,
                agentUrl:    (string) $agent['url'],
                hmacSecret:  (string) $agent['hmac_secret'],
                userId:      0,
                username:    '',
                timeout:     (int)   ($agent['timeout']      ?? 10),
                sslVerify:   (bool)  ($agent['ssl_verify']   ?? true),
                sslCaBundle: (string)($agent['ssl_ca_bundle'] ?? ''),
            );

            $client->put('/settings/web-identity', [
                'web_agent_id' => $agentId,
                'web_url'      => $this->webUrl,
            ]);

            $this->logger->info('AgentIdentityPusher: web identity pushed', [
                'agent_id' => $agentId,
                'web_url'  => $this->webUrl,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('AgentIdentityPusher: push failed (agent unreachable?)', [
                'agent_id' => $agentId,
                'message'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Push web identity to all enabled agents.
     *
     * Errors per agent are logged but do not abort the remaining pushes.
     *
     * @return void
     */
    public function pushToAllAgents(): void
    {
        if ($this->webUrl === '') {
            $this->logger->debug('AgentIdentityPusher: WEB_URL not configured, skipping push');
            return;
        }

        try {
            $agents = (new AgentRepository($this->pdo))->findEnabled();
        } catch (\Throwable $e) {
            $this->logger->warning('AgentIdentityPusher: could not load agents', [
                'message' => $e->getMessage(),
            ]);
            return;
        }

        foreach ($agents as $agent) {
            $this->pushToAgent($agent);
        }
    }
}
