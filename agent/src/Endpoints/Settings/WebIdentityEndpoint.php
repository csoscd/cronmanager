<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – WebIdentityEndpoint
 *
 * Receives the web-container identity push from the central Cronmanager web UI.
 * The web container calls this endpoint after startup and whenever an agent is
 * created, updated, or switched in the UI.  The stored values are used by
 * MailNotifier and TelegramNotifier to build agent-aware notification links.
 *
 * Route:
 *   PUT /settings/web-identity
 *
 * Request body (JSON):
 *   {
 *     "web_agent_id": 3,
 *     "web_url": "https://cronmanager.example.com"
 *   }
 *
 * Response:
 *   { "ok": true }
 *
 * Errors:
 *   400  Missing or invalid fields
 *
 * All requests require a valid HMAC-SHA256 signature (enforced by agent.php).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints\Settings;

use Cronmanager\Agent\Config\DbConfig;
use Monolog\Logger;

/**
 * Class WebIdentityEndpoint
 *
 * Persists the web container's identity (agent ID and public URL) into the
 * agent_settings table so that notification links can reference the correct agent.
 */
final class WebIdentityEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param DbConfig $dbConfig DB-backed configuration wrapper.
     * @param Logger   $logger   Monolog logger instance.
     */
    public function __construct(
        private readonly DbConfig $dbConfig,
        private readonly Logger   $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    /**
     * Handle PUT /settings/web-identity.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function handle(array $params = []): void
    {
        $raw  = (string) file_get_contents('php://input');
        $body = json_decode($raw, true);

        if (!is_array($body)) {
            jsonResponse(400, ['error' => 'Invalid or missing JSON body', 'code' => 400]);
            return;
        }

        $webAgentId = isset($body['web_agent_id']) ? (int) $body['web_agent_id'] : 0;
        $webUrl     = isset($body['web_url']) ? trim((string) $body['web_url']) : '';

        if ($webAgentId <= 0) {
            jsonResponse(400, ['error' => 'web_agent_id must be a positive integer', 'code' => 400]);
            return;
        }

        if ($webUrl === '') {
            jsonResponse(400, ['error' => 'web_url must not be empty', 'code' => 400]);
            return;
        }

        $this->dbConfig->setSection('web', [
            'web_agent_id' => $webAgentId,
            'web_url'      => rtrim($webUrl, '/'),
        ]);

        $this->logger->info('WebIdentityEndpoint: web identity stored', [
            'web_agent_id' => $webAgentId,
            'web_url'      => $webUrl,
        ]);

        jsonResponse(200, ['ok' => true]);
    }
}
