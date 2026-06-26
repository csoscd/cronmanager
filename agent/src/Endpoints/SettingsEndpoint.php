<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – SettingsEndpoint
 *
 * Provides persistent read/write access to the notification and integration
 * settings stored in the agent_settings MariaDB table via DbConfig.
 *
 * Routes:
 *   GET  /settings – return effective values for all DB-managed sections
 *   PUT  /settings – persist one or more sections to the database
 *
 * GET response shape:
 *   {
 *     "mail":          { "enabled": true, "host": "smtp.example.com", ... },
 *     "telegram":      { "enabled": false, "bot_token": "", ... },
 *     "influxdb":      { "enabled": false, "url": "...", ... },
 *     "notifications": { "web_url": "https://..." }
 *   }
 *
 * PUT request body (JSON):
 *   One or more section objects. Unknown sections are ignored.
 *   { "mail": { "enabled": true, "host": "smtp.example.com", ... } }
 *
 * PUT response:
 *   { "success": true, "saved": ["mail"] }
 *
 * All requests require a valid HMAC-SHA256 signature (enforced by agent.php).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Audit\AuditLogger;
use Cronmanager\Agent\Config\DbConfig;
use Monolog\Logger;

/**
 * Class SettingsEndpoint
 *
 * Handles GET and PUT /settings for persistent agent configuration management.
 */
final class SettingsEndpoint
{
    // -------------------------------------------------------------------------
    // Allowed sections
    // -------------------------------------------------------------------------

    /** @var string[] Sections that may be stored via this endpoint. */
    private const ALLOWED_SECTIONS = ['mail', 'telegram', 'influxdb', 'notifications', 'performance_monitor'];

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param DbConfig    $dbConfig DB-backed configuration wrapper.
     * @param Logger      $logger   Monolog logger instance.
     * @param AuditLogger $audit    Audit logger instance.
     */
    public function __construct(
        private readonly DbConfig    $dbConfig,
        private readonly Logger      $logger,
        private readonly AuditLogger $audit,
    ) {}

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    /**
     * Dispatch to the appropriate handler based on the HTTP method.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function handle(array $params = []): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        match ($method) {
            'GET' => $this->handleGet(),
            'PUT' => $this->handlePut(),
            default => jsonResponse(405, ['error' => 'Method Not Allowed', 'code' => 405]),
        };
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /**
     * Return effective settings for all DB-managed sections.
     */
    private function handleGet(): void
    {
        jsonResponse(200, $this->dbConfig->getAllSections());
    }

    /**
     * Persist one or more sections to the database.
     *
     * Sections not present in the JSON body are left untouched.
     * Unknown section names are silently ignored.
     */
    private function handlePut(): void
    {
        $raw  = (string) file_get_contents('php://input');
        $body = json_decode($raw, true);

        if (!is_array($body)) {
            jsonResponse(400, ['error' => 'Invalid or missing JSON body', 'code' => 400]);
            return;
        }

        $saved = [];

        foreach (self::ALLOWED_SECTIONS as $section) {
            if (!isset($body[$section]) || !is_array($body[$section])) {
                continue;
            }
            $this->dbConfig->setSection($section, $body[$section]);
            $saved[] = $section;
        }

        $this->logger->info('SettingsEndpoint: sections saved to DB', ['sections' => $saved]);

        if ($saved !== []) {
            $this->audit->log('settings.update', 'settings', null, null, ['sections' => $saved]);
        }

        jsonResponse(200, ['success' => true, 'saved' => $saved]);
    }
}
