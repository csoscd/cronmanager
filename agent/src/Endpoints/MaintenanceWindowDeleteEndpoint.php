<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MaintenanceWindowDeleteEndpoint
 *
 * Handles DELETE /maintenance/windows/{id} requests.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Repository\MaintenanceWindowRepository;
use Monolog\Logger;
use PDOException;

/**
 * Class MaintenanceWindowDeleteEndpoint
 */
final class MaintenanceWindowDeleteEndpoint
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

        $this->logger->debug('MaintenanceWindowDeleteEndpoint: handling DELETE /maintenance/windows/{id}', [
            'id' => $id,
        ]);

        try {
            $deleted = $this->repo->delete($id);
        } catch (PDOException $e) {
            $this->logger->error('MaintenanceWindowDeleteEndpoint: database error', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to delete maintenance window.',
                'code'    => 500,
            ]);
            return;
        }

        if (!$deleted) {
            jsonResponse(404, [
                'error'   => 'Not Found',
                'message' => sprintf('Maintenance window %d does not exist.', $id),
                'code'    => 404,
            ]);
            return;
        }

        $this->logger->info('MaintenanceWindowDeleteEndpoint: window deleted', ['id' => $id]);

        jsonResponse(200, ['deleted' => true, 'id' => $id]);
    }
}
