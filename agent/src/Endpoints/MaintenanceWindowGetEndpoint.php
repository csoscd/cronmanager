<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MaintenanceWindowGetEndpoint
 *
 * Handles GET /maintenance/windows/{id} requests.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Repository\MaintenanceWindowRepository;
use Monolog\Logger;
use PDOException;

/**
 * Class MaintenanceWindowGetEndpoint
 */
final class MaintenanceWindowGetEndpoint
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
     * @param array<string, string> $params Path parameters – expects 'id'.
     */
    public function handle(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;

        if ($id <= 0) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Invalid window ID.',
                'code'    => 400,
            ]);
            return;
        }

        $this->logger->debug('MaintenanceWindowGetEndpoint: handling GET /maintenance/windows/{id}', [
            'id' => $id,
        ]);

        try {
            $row = $this->repo->findById($id);
        } catch (PDOException $e) {
            $this->logger->error('MaintenanceWindowGetEndpoint: database error', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to retrieve maintenance window.',
                'code'    => 500,
            ]);
            return;
        }

        if ($row === null) {
            jsonResponse(404, [
                'error'   => 'Not Found',
                'message' => sprintf('Maintenance window %d does not exist.', $id),
                'code'    => 404,
            ]);
            return;
        }

        jsonResponse(200, [
            'id'               => (int) $row['id'],
            'target'           => (string) $row['target'],
            'cron_schedule'    => (string) $row['cron_schedule'],
            'duration_minutes' => (int) $row['duration_minutes'],
            'description'      => $row['description'],
            'active'           => (bool) $row['active'],
            'created_at'       => (string) $row['created_at'],
        ]);
    }
}
