<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – AcknowledgeEndpoint
 *
 * Handles acknowledge / unacknowledge requests for finished executions:
 *
 *   POST   /execution/{id}/acknowledge  – mark execution as acknowledged
 *   DELETE /execution/{id}/acknowledge  – clear acknowledgement
 *
 * Acknowledged failures are suppressed from the dashboard's error indicators
 * while the historical record remains fully intact.  Every action is written
 * to the audit log.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Audit\AuditLogger;
use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class AcknowledgeEndpoint
 *
 * Sets or clears acknowledged_at / acknowledged_by_user_id on execution_log rows.
 */
final class AcknowledgeEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param PDO         $pdo    Active PDO database connection.
     * @param Logger      $logger Monolog logger instance.
     * @param AuditLogger $audit  Audit logger (user context baked in at construction).
     * @param int         $userId Acting user ID (from HMAC-validated header).
     */
    public function __construct(
        private readonly PDO         $pdo,
        private readonly Logger      $logger,
        private readonly AuditLogger $audit,
        private readonly int         $userId,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle POST /execution/{id}/acknowledge (acknowledge) or
     * DELETE /execution/{id}/acknowledge (unacknowledge).
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $rawId = $params['id'] ?? '';

        if ($rawId === '' || !ctype_digit($rawId) || (int) $rawId <= 0) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Path parameter {id} must be a positive integer.',
                'code'    => 400,
            ]);
            return;
        }

        $executionId = (int) $rawId;
        $method      = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'POST');
        $isDelete    = ($method === 'DELETE');

        $this->logger->info('AcknowledgeEndpoint: request received', [
            'execution_id' => $executionId,
            'action'       => $isDelete ? 'unacknowledge' : 'acknowledge',
        ]);

        // ------------------------------------------------------------------
        // 1. Fetch execution row
        // ------------------------------------------------------------------

        try {
            $row = $this->fetchExecution($executionId);
        } catch (PDOException $e) {
            $this->logger->error('AcknowledgeEndpoint: database error fetching execution', [
                'execution_id' => $executionId,
                'message'      => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to retrieve execution record.',
                'code'    => 500,
            ]);
            return;
        }

        if ($row === null) {
            jsonResponse(404, [
                'error'   => 'Not Found',
                'message' => sprintf('Execution with ID %d does not exist.', $executionId),
                'code'    => 404,
            ]);
            return;
        }

        // Only finished executions can be acknowledged
        if ($row['finished_at'] === null) {
            jsonResponse(409, [
                'error'   => 'Conflict',
                'message' => 'Only finished executions can be acknowledged.',
                'code'    => 409,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 2. Update acknowledged state
        // ------------------------------------------------------------------

        try {
            if ($isDelete) {
                $this->clearAcknowledge($executionId);
            } else {
                $this->setAcknowledge($executionId, $this->userId);
            }
        } catch (PDOException $e) {
            $this->logger->error('AcknowledgeEndpoint: database error updating acknowledge state', [
                'execution_id' => $executionId,
                'message'      => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to update acknowledgement.',
                'code'    => 500,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 3. Audit log
        // ------------------------------------------------------------------

        $action = $isDelete ? 'execution.unacknowledged' : 'execution.acknowledged';
        $jobId  = isset($row['cronjob_id']) ? (int) $row['cronjob_id'] : null;

        $this->audit->log(
            $action,
            'execution',
            $executionId,
            null,
            ['job_id' => $jobId],
        );

        $this->logger->info('AcknowledgeEndpoint: done', [
            'execution_id' => $executionId,
            'action'       => $isDelete ? 'unacknowledged' : 'acknowledged',
        ]);

        jsonResponse(200, [
            'execution_id'   => $executionId,
            'acknowledged'   => !$isDelete,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     * @throws PDOException
     */
    private function fetchExecution(int $executionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, cronjob_id, finished_at
               FROM execution_log
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $executionId]);
        $row = $stmt->fetch();

        return $row !== false ? (array) $row : null;
    }

    /** @throws PDOException */
    private function setAcknowledge(int $executionId, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE execution_log
                SET acknowledged_at         = :now,
                    acknowledged_by_user_id = :user_id
              WHERE id = :id'
        );
        $stmt->execute([
            ':now'     => date('Y-m-d H:i:s'),
            ':user_id' => $userId,
            ':id'      => $executionId,
        ]);
    }

    /** @throws PDOException */
    private function clearAcknowledge(int $executionId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE execution_log
                SET acknowledged_at         = NULL,
                    acknowledged_by_user_id = NULL
              WHERE id = :id'
        );
        $stmt->execute([':id' => $executionId]);
    }
}
