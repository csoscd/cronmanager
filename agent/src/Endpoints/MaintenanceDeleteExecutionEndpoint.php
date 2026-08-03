<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MaintenanceDeleteExecutionEndpoint
 *
 * Handles DELETE /maintenance/executions/{id}
 *
 * Permanently removes a single execution record from the execution_log table.
 * Intended for stuck executions that should be completely discarded rather
 * than resolved.  This operation cannot be undone.
 *
 * Response on success (HTTP 200):
 * ```json
 * { "message": "Execution #17 deleted.", "id": 17 }
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
 * Class MaintenanceDeleteExecutionEndpoint
 *
 * Permanently deletes a single execution record.
 */
final class MaintenanceDeleteExecutionEndpoint
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
     * Handle DELETE /maintenance/executions/{id}.
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

        $this->logger->info('MaintenanceDeleteExecutionEndpoint: deleting execution record', [
            'execution_id' => $id,
        ]);

        try {
            // Capture the owning job before deleting so the denormalised
            // last-execution references (migration 018) can be re-derived.
            $jobStmt = $this->pdo->prepare('SELECT cronjob_id FROM execution_log WHERE id = :id');
            $jobStmt->execute([':id' => $id]);
            $jobId = (int) ($jobStmt->fetchColumn() ?: 0);

            $stmt = $this->pdo->prepare(
                'DELETE FROM execution_log WHERE id = :id'
            );
            $stmt->execute([':id' => $id]);
            $affected = $stmt->rowCount();

            if ($affected > 0 && $jobId > 0) {
                // Re-derive both reference columns for the affected job only –
                // a single-job aggregate covered by idx_el_cj_finished_cover.
                $this->pdo->prepare(
                    'UPDATE cronjobs c
                     LEFT JOIN (
                         SELECT cronjob_id,
                                MAX(id) AS max_id,
                                MAX(CASE WHEN finished_at IS NOT NULL THEN id ELSE NULL END) AS max_finished_id
                           FROM execution_log
                          WHERE cronjob_id = :jid
                          GROUP BY cronjob_id
                     ) el ON el.cronjob_id = c.id
                        SET c.last_execution_id          = el.max_id,
                            c.last_finished_execution_id = el.max_finished_id
                      WHERE c.id = :jid2'
                )->execute([':jid' => $jobId, ':jid2' => $jobId]);
            }
        } catch (PDOException $e) {
            $this->logger->error('MaintenanceDeleteExecutionEndpoint: database error', [
                'execution_id' => $id,
                'message'      => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to delete execution record.',
                'code'    => 500,
            ]);
            return;
        }

        if ($affected === 0) {
            jsonResponse(404, [
                'error'   => 'Not Found',
                'message' => sprintf('Execution #%d not found.', $id),
                'code'    => 404,
            ]);
            return;
        }

        $this->logger->info('MaintenanceDeleteExecutionEndpoint: execution deleted', [
            'execution_id' => $id,
        ]);

        jsonResponse(200, [
            'message' => sprintf('Execution #%d deleted.', $id),
            'id'      => $id,
        ]);
    }
}
