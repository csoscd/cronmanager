<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MaintenanceResolveEndpoint
 *
 * Handles POST /maintenance/executions/{id}/finish
 *
 * Marks a stuck execution as finished by setting finished_at to the current
 * timestamp and exit_code to -1.  This is an administrative action intended
 * for executions that have been running for an unreasonably long time and
 * whose finish event was never received (e.g. due to a crash or timeout).
 *
 * The execution record is NOT deleted – it remains visible in the history
 * with a note in the output field indicating it was manually resolved.
 *
 * Response on success (HTTP 200):
 * ```json
 * { "message": "Execution #17 marked as finished.", "id": 17 }
 * ```
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class MaintenanceResolveEndpoint
 *
 * Manually marks a stuck execution as finished.
 */
final class MaintenanceResolveEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param PDO    $pdo    Active PDO database connection.
     * @param Logger $logger Monolog logger instance.
     */
    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle POST /maintenance/executions/{id}/finish.
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     */
    public function handle(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;

        if ($id <= 0) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Invalid or missing execution ID.',
                'code'    => 400,
            ]);
            return;
        }

        $this->logger->info('MaintenanceResolveEndpoint: marking execution as finished', [
            'execution_id' => $id,
        ]);

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE execution_log
                    SET finished_at = NOW(),
                        exit_code   = -1,
                        output      = CONCAT(COALESCE(output, \'\'), \'[Manually marked as finished by maintenance action]\')
                  WHERE id          = :id
                    AND finished_at IS NULL'
            );
            $stmt->execute([':id' => $id]);
            $affected = $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logger->error('MaintenanceResolveEndpoint: database error', [
                'execution_id' => $id,
                'message'      => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to update execution record.',
                'code'    => 500,
            ]);
            return;
        }

        if ($affected === 0) {
            jsonResponse(404, [
                'error'   => 'Not Found',
                'message' => sprintf('Execution #%d not found or already finished.', $id),
                'code'    => 404,
            ]);
            return;
        }

        $this->logger->info('MaintenanceResolveEndpoint: execution resolved', [
            'execution_id' => $id,
        ]);

        jsonResponse(200, [
            'message' => sprintf('Execution #%d marked as finished.', $id),
            'id'      => $id,
        ]);
    }
}
