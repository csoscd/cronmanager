<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Export API Controller
 *
 * Handles GET /api/v1/export.
 *
 * Supported formats: json (default), csv, cron
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Api;

use Cronmanager\Web\Auth\ApiKeyMiddleware;
use Cronmanager\Web\Auth\ScopeHelper;
use Cronmanager\Web\Database\Connection;

/**
 * Class ExportApiController
 */
final class ExportApiController extends BaseApiController
{
    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/export
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function download(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_EXPORT_READ);

        if ($apiKey === null) {
            return;
        }

        $format = strtolower((string) ($_GET['format'] ?? 'json'));

        if (!in_array($format, ['json', 'csv', 'cron'], strict: true)) {
            $this->jsonError(400, 'Invalid format', 'Supported formats: json, csv, cron.');
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        $response = $this->agentGet($agent, '/export', ['format' => $format]);
        if ($response === null) {
            return;
        }

        if ($format === 'json') {
            $this->jsonOk($response);
            return;
        }

        // For non-JSON formats the agent returns raw text wrapped in a JSON envelope
        $content     = (string) ($response['content'] ?? '');
        $contentType = $format === 'csv' ? 'text/csv' : 'text/plain';
        $filename    = 'cronmanager-export.' . $format;

        header('Content-Type: ' . $contentType . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $content;
    }
}
