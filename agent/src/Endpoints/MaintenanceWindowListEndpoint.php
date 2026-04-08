<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MaintenanceWindowListEndpoint
 *
 * Handles GET /maintenance/windows requests.
 *
 * Returns all maintenance windows, optionally filtered by target via the
 * `?target=` query parameter.
 *
 * Response on success (HTTP 200):
 * ```json
 * [
 *   {
 *     "id": 1,
 *     "target": "iom",
 *     "cron_schedule": "0 2 * * *",
 *     "duration_minutes": 60,
 *     "description": "Nightly backup window",
 *     "active": true,
 *     "created_at": "2026-04-05T00:00:00+00:00"
 *   }
 * ]
 * ```
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Repository\MaintenanceWindowRepository;
use Monolog\Logger;
use PDOException;

/**
 * Class MaintenanceWindowListEndpoint
 */
final class MaintenanceWindowListEndpoint
{
    /**
     * @param MaintenanceWindowRepository $repo   Window repository.
     * @param Logger                      $logger Monolog logger instance.
     */
    public function __construct(
        private readonly MaintenanceWindowRepository $repo,
        private readonly Logger                      $logger,
    ) {}

    /**
     * @param array<string, string> $params Path parameters (unused).
     */
    public function handle(array $params): void
    {
        $this->logger->debug('MaintenanceWindowListEndpoint: handling GET /maintenance/windows');

        $target = isset($_GET['target']) && $_GET['target'] !== '' ? (string) $_GET['target'] : null;

        try {
            $windows = $this->repo->findAll($target);
        } catch (PDOException $e) {
            $this->logger->error('MaintenanceWindowListEndpoint: database error', [
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to retrieve maintenance windows.',
                'code'    => 500,
            ]);
            return;
        }

        // Cast types for clean JSON output
        $result = array_map(function (array $row): array {
            return [
                'id'               => (int) $row['id'],
                'target'           => (string) $row['target'],
                'cron_schedule'    => (string) $row['cron_schedule'],
                'duration_minutes' => (int) $row['duration_minutes'],
                'description'      => $row['description'],
                'active'           => (bool) $row['active'],
                'created_at'       => (string) $row['created_at'],
            ];
        }, $windows);

        jsonResponse(200, $result);
    }
}
