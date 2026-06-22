<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Settings API Controller
 *
 * Handles all /api/v1/settings/* REST endpoints.
 *
 * Routes:
 *   GET    /api/v1/settings              settings:read
 *   GET    /api/v1/settings/{section}    settings:read
 *   PUT    /api/v1/settings/{section}    settings:write
 *   POST   /api/v1/settings/resync       settings:write
 *
 * Valid sections: mail, telegram, influxdb, notifications
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Api;

use Cronmanager\Web\Auth\ApiKeyMiddleware;
use Cronmanager\Web\Auth\ScopeHelper;
use Cronmanager\Web\Database\Connection;

/**
 * Class SettingsApiController
 */
final class SettingsApiController extends BaseApiController
{
    /** @var string[] Valid settings section names */
    private const VALID_SECTIONS = ['mail', 'telegram', 'influxdb', 'notifications'];

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/settings
     *
     * Returns all settings sections.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function index(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_SETTINGS_READ);

        if ($apiKey === null) {
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentGet($agent, '/settings');
        if ($response === null) {
            return;
        }

        $this->jsonOk($response);
    }

    /**
     * GET /api/v1/settings/{section}
     *
     * Returns a single settings section.
     *
     * @param array<string, string> $params Path parameters: section.
     *
     * @return void
     */
    public function show(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_SETTINGS_READ);

        if ($apiKey === null) {
            return;
        }

        $section = strtolower((string) ($params['section'] ?? ''));

        if (!in_array($section, self::VALID_SECTIONS, strict: true)) {
            $this->jsonError(
                400,
                'Invalid section',
                'Valid sections: ' . implode(', ', self::VALID_SECTIONS)
            );
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentGet($agent, '/settings');
        if ($response === null) {
            return;
        }

        $sectionData = $response[$section] ?? [];
        $this->jsonOk(is_array($sectionData) ? $sectionData : []);
    }

    /**
     * PUT /api/v1/settings/{section}
     *
     * Updates a single settings section.
     *
     * @param array<string, string> $params Path parameters: section.
     *
     * @return void
     */
    public function update(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_SETTINGS_WRITE);

        if ($apiKey === null) {
            return;
        }

        $section = strtolower((string) ($params['section'] ?? ''));

        if (!in_array($section, self::VALID_SECTIONS, strict: true)) {
            $this->jsonError(
                400,
                'Invalid section',
                'Valid sections: ' . implode(', ', self::VALID_SECTIONS)
            );
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

        $response = $this->agentPut($agent, '/settings', [$section => $body]);
        if ($response === null) {
            return;
        }

        $sectionData = $response[$section] ?? $body;
        $this->jsonOk(is_array($sectionData) ? $sectionData : $body);
    }

    /**
     * POST /api/v1/settings/resync
     *
     * Triggers a crontab resync.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function resync(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_SETTINGS_WRITE);

        if ($apiKey === null) {
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentPost($agent, '/maintenance/crontab/resync');
        if ($response === null) {
            return;
        }

        $this->jsonOk(['success' => true, 'message' => 'Crontab resynced.']);
    }
}
