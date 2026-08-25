<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – CronBulkStatusEndpoint
 *
 * Handles POST /crons/bulk/status requests to activate or deactivate
 * multiple cron jobs in a single atomic operation.
 *
 * Request body:
 *   {"ids": [1, 4, 7], "active": true}
 *
 * Response on success (HTTP 200):
 *   {"updated": 3}
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Audit\AuditLogger;
use Cronmanager\Agent\Cron\CrontabManager;
use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class CronBulkStatusEndpoint
 *
 * Activates or deactivates multiple jobs in one transaction and syncs
 * the system crontab accordingly.
 */
final class CronBulkStatusEndpoint
{
    /**
     * @param PDO            $pdo            Active PDO connection.
     * @param Logger         $logger         Monolog logger.
     * @param CrontabManager $crontabManager CrontabManager for crontab sync.
     * @param string         $wrapperScript  Path to cron-wrapper.sh.
     */
    public function __construct(
        private readonly PDO            $pdo,
        private readonly Logger         $logger,
        private readonly CrontabManager $crontabManager,
        private readonly string         $wrapperScript,
        private readonly AuditLogger    $audit,
    ) {}

    /**
     * Handle POST /crons/bulk/status.
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $body = json_decode((string) file_get_contents('php://input'), associative: true) ?? [];

        // ── Validate ──────────────────────────────────────────────────────────
        $ids    = isset($body['ids'])    && is_array($body['ids'])  ? $body['ids']    : [];
        $active = isset($body['active']) && is_bool($body['active']) ? $body['active'] : null;

        if ($ids === []) {
            jsonResponse(400, ['error' => 'Bad Request', 'message' => 'ids must be a non-empty array.']);
            return;
        }

        if ($active === null) {
            jsonResponse(400, ['error' => 'Bad Request', 'message' => 'active must be a boolean.']);
            return;
        }

        $intIds = array_values(array_unique(array_map('intval', $ids)));
        foreach ($intIds as $id) {
            if ($id <= 0) {
                jsonResponse(400, ['error' => 'Bad Request', 'message' => 'All IDs must be positive integers.']);
                return;
            }
        }

        // ── Fetch current job state ───────────────────────────────────────────
        $placeholders = implode(',', array_fill(0, count($intIds), '?'));
        // $placeholders contains only literal '?' characters; values bound via execute().
        $stmt = $this->pdo->prepare(
            // nosemgrep: php.lang.security.injection.tainted-callable.tainted-callable
            "SELECT j.id, j.linux_user, j.active, j.schedule, GROUP_CONCAT(jt.target ORDER BY jt.target) AS targets
             FROM cronjobs j
             LEFT JOIN job_targets jt ON jt.job_id = j.id
             WHERE j.id IN ({$placeholders})
             GROUP BY j.id, j.linux_user, j.active, j.schedule"
        );
        $stmt->execute($intIds);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($jobs) !== count($intIds)) {
            jsonResponse(404, ['error' => 'Not Found', 'message' => 'One or more job IDs do not exist.']);
            return;
        }

        // ── Update + crontab sync in a transaction ────────────────────────────
        try {
            $this->pdo->beginTransaction();

            $updateStmt = $this->pdo->prepare(
                'UPDATE cronjobs SET active = :active WHERE id = :id'
            );

            foreach ($jobs as $job) {
                $jobId     = (int)    $job['id'];
                $wasActive = (bool)   $job['active'];
                $linuxUser = (string) $job['linux_user'];
                $schedule  = (string) $job['schedule'];
                $targets   = $job['targets'] !== null
                    ? explode(',', (string) $job['targets'])
                    : ['local'];

                $updateStmt->execute([':active' => (int) $active, ':id' => $jobId]);

                // Sync crontab only when the state actually changes
                if (!$wasActive && $active) {
                    $this->crontabManager->addEntriesForTargets($linuxUser, $jobId, $schedule, $this->wrapperScript, $targets);
                } elseif ($wasActive && !$active) {
                    $this->crontabManager->removeAllEntries($linuxUser, $jobId);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->logger->error('CronBulkStatusEndpoint: transaction failed', ['message' => $e->getMessage()]);
            jsonResponse(500, ['error' => 'Internal Server Error', 'message' => 'Bulk status update failed.']);
            return;
        }

        $this->logger->info('CronBulkStatusEndpoint: updated', ['ids' => $intIds, 'active' => $active]);

        $auditAction = $active ? 'cron.bulk.activate' : 'cron.bulk.deactivate';
        $this->audit->log($auditAction, 'cron', null, null, ['count' => count($jobs), 'ids' => $intIds]);

        jsonResponse(200, ['updated' => count($jobs)]);
    }
}
