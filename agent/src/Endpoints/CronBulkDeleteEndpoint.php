<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – CronBulkDeleteEndpoint
 *
 * Handles POST /crons/bulk/delete requests to delete multiple cron jobs
 * atomically.
 *
 * Request body:
 *   {"ids": [1, 4, 7]}
 *
 * Rejects with HTTP 409 if any selected job has a currently-running execution.
 *
 * Response on success (HTTP 200):
 *   {"deleted": 3}
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Cron\CrontabManager;
use Monolog\Logger;
use PDO;

/**
 * Class CronBulkDeleteEndpoint
 *
 * Removes crontab entries for all selected jobs, then deletes the DB rows
 * inside a single transaction.  Aborts with 409 if any job is currently
 * running to prevent orphaned execution records.
 */
final class CronBulkDeleteEndpoint
{
    /**
     * @param PDO            $pdo            Active PDO connection.
     * @param Logger         $logger         Monolog logger.
     * @param CrontabManager $crontabManager CrontabManager for crontab cleanup.
     */
    public function __construct(
        private readonly PDO            $pdo,
        private readonly Logger         $logger,
        private readonly CrontabManager $crontabManager,
    ) {}

    /**
     * Handle POST /crons/bulk/delete.
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $body = json_decode((string) file_get_contents('php://input'), associative: true) ?? [];

        // ── Validate ──────────────────────────────────────────────────────────
        $ids = isset($body['ids']) && is_array($body['ids']) ? $body['ids'] : [];

        if ($ids === []) {
            jsonResponse(400, ['error' => 'Bad Request', 'message' => 'ids must be a non-empty array.']);
            return;
        }

        $intIds = array_values(array_unique(array_map('intval', $ids)));
        foreach ($intIds as $id) {
            if ($id <= 0) {
                jsonResponse(400, ['error' => 'Bad Request', 'message' => 'All IDs must be positive integers.']);
                return;
            }
        }

        $placeholders = implode(',', array_fill(0, count($intIds), '?'));

        // ── Fetch jobs ────────────────────────────────────────────────────────
        $stmt = $this->pdo->prepare(
            "SELECT id, linux_user FROM cronjobs WHERE id IN ({$placeholders})"
        );
        $stmt->execute($intIds);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($jobs) !== count($intIds)) {
            jsonResponse(404, ['error' => 'Not Found', 'message' => 'One or more job IDs do not exist.']);
            return;
        }

        // ── Reject if any job is currently running ────────────────────────────
        $runStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM execution_log
             WHERE cronjob_id IN ({$placeholders}) AND finished_at IS NULL"
        );
        $runStmt->execute($intIds);
        if ((int) $runStmt->fetchColumn() > 0) {
            jsonResponse(409, [
                'error'   => 'Conflict',
                'message' => 'One or more selected jobs have a running execution.',
            ]);
            return;
        }

        // ── Remove crontab entries (all users), then delete DB rows ───────────
        try {
            $this->pdo->beginTransaction();

            foreach ($jobs as $job) {
                $this->crontabManager->removeAllEntries((string) $job['linux_user'], (int) $job['id']);
            }

            $deleteStmt = $this->pdo->prepare(
                "DELETE FROM cronjobs WHERE id IN ({$placeholders})"
            );
            $deleteStmt->execute($intIds);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->logger->error('CronBulkDeleteEndpoint: transaction failed', ['message' => $e->getMessage()]);
            jsonResponse(500, ['error' => 'Internal Server Error', 'message' => 'Bulk delete failed.']);
            return;
        }

        $this->logger->info('CronBulkDeleteEndpoint: deleted', ['ids' => $intIds]);

        jsonResponse(200, ['deleted' => count($jobs)]);
    }
}
