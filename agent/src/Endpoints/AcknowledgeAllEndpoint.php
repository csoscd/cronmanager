<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – AcknowledgeAllEndpoint
 *
 * Handles bulk-acknowledge requests for all unacknowledged failed executions
 * of a specific cron job:
 *
 *   POST /crons/{id}/acknowledge-all
 *
 * Marks every finished, non-zero, unacknowledged execution_log row for the
 * given job as acknowledged in a single UPDATE statement.
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
 * Class AcknowledgeAllEndpoint
 *
 * Bulk-acknowledges all unacknowledged failures for a cron job.
 */
final class AcknowledgeAllEndpoint
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
     * Handle POST /crons/{id}/acknowledge-all.
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

        $jobId = (int) $rawId;

        $this->logger->info('AcknowledgeAllEndpoint: request received', ['job_id' => $jobId]);

        // ------------------------------------------------------------------
        // 1. Verify the job exists
        // ------------------------------------------------------------------

        try {
            $stmt = $this->pdo->prepare('SELECT id FROM cronjobs WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $jobId]);
            if ($stmt->fetch() === false) {
                jsonResponse(404, [
                    'error'   => 'Not Found',
                    'message' => sprintf('Cron job with ID %d does not exist.', $jobId),
                    'code'    => 404,
                ]);
                return;
            }
        } catch (PDOException $e) {
            $this->logger->error('AcknowledgeAllEndpoint: DB error checking job', [
                'job_id'  => $jobId,
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, ['error' => 'Internal Server Error', 'code' => 500]);
            return;
        }

        // ------------------------------------------------------------------
        // 2. Bulk-acknowledge all unacknowledged failures
        // ------------------------------------------------------------------

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE execution_log
                    SET acknowledged_at         = :now,
                        acknowledged_by_user_id = :user_id
                  WHERE cronjob_id       = :job_id
                    AND exit_code        != 0
                    AND finished_at      IS NOT NULL
                    AND acknowledged_at  IS NULL'
            );
            $stmt->execute([
                ':now'     => date('Y-m-d H:i:s'),
                ':user_id' => $this->userId,
                ':job_id'  => $jobId,
            ]);
            $count = $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logger->error('AcknowledgeAllEndpoint: DB error during bulk-acknowledge', [
                'job_id'  => $jobId,
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, ['error' => 'Internal Server Error', 'code' => 500]);
            return;
        }

        // ------------------------------------------------------------------
        // 3. Audit log (one entry for the bulk action)
        // ------------------------------------------------------------------

        $this->audit->log(
            'execution.acknowledged_all',
            'cron',
            $jobId,
            null,
            ['acknowledged_count' => $count],
        );

        $this->logger->info('AcknowledgeAllEndpoint: done', [
            'job_id'             => $jobId,
            'acknowledged_count' => $count,
        ]);

        jsonResponse(200, [
            'job_id'             => $jobId,
            'acknowledged_count' => $count,
        ]);
    }
}
