<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MaintenanceLogsPruneEndpoint
 *
 * Handles POST /maintenance/logs/prune requests.
 *
 * Manually triggers the same log-pruning logic that runs nightly via
 * prune-logs.php.  Deletes finished execution_log rows that exceed their
 * job's configured retention_days and removes stale job_retry_state rows
 * whose scheduled_at timestamp is older than (retry_delay_minutes + 60) minutes.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "deleted_logs":        42,
 *   "deleted_retry_state": 0,
 *   "message": "Pruned 42 log records and 0 stale retry-state entries."
 * }
 * ```
 *
 * This class relies on the global `jsonResponse()` function being available
 * in the calling scope (defined in agent.php).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class MaintenanceLogsPruneEndpoint
 *
 * Prunes expired execution logs and stale retry-state rows on demand.
 */
final class MaintenanceLogsPruneEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * MaintenanceLogsPruneEndpoint constructor.
     *
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
     * Handle a POST /maintenance/logs/prune request.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $this->logger->info('MaintenanceLogsPruneEndpoint: manual log prune triggered');

        try {
            [$deletedLogs, $deletedRetryState] = $this->prune();
        } catch (PDOException $e) {
            $this->logger->error('MaintenanceLogsPruneEndpoint: database error during prune', [
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Log pruning failed. Check agent log for details.',
                'code'    => 500,
            ]);
            return;
        }

        $this->logger->info('MaintenanceLogsPruneEndpoint: prune completed', [
            'deleted_logs'        => $deletedLogs,
            'deleted_retry_state' => $deletedRetryState,
        ]);

        jsonResponse(200, [
            'deleted_logs'        => $deletedLogs,
            'deleted_retry_state' => $deletedRetryState,
            'message'             => sprintf(
                'Pruned %d log record%s and %d stale retry-state entr%s.',
                $deletedLogs,
                $deletedLogs === 1 ? '' : 's',
                $deletedRetryState,
                $deletedRetryState === 1 ? 'y' : 'ies',
            ),
        ]);
    }

    // -------------------------------------------------------------------------
    // Pruning logic (shared with prune-logs.php CLI script)
    // -------------------------------------------------------------------------

    /**
     * Execute the pruning queries and return the row counts.
     *
     * @return array{int, int} [deleted_logs, deleted_retry_state]
     *
     * @throws PDOException On database errors.
     */
    public function prune(): array
    {
        // Delete finished execution_log rows that exceed their job's retention_days.
        // Only rows with a finished_at value are eligible; running executions are
        // never pruned regardless of the policy.
        $logStmt = $this->pdo->query(
            'DELETE el
               FROM execution_log el
               JOIN cronjobs j ON j.id = el.cronjob_id
              WHERE j.retention_days IS NOT NULL
                AND el.finished_at IS NOT NULL
                AND el.finished_at < DATE_SUB(NOW(), INTERVAL j.retention_days DAY)'
        );
        $deletedLogs = (int) $logStmt->rowCount();

        // Delete stale job_retry_state rows whose scheduled once-entry never fired.
        // Threshold: retry_delay_minutes + 60 minutes after scheduling.
        $retryStmt = $this->pdo->query(
            'DELETE FROM job_retry_state
              WHERE scheduled_at < DATE_SUB(NOW(), INTERVAL (retry_delay_minutes + 60) MINUTE)'
        );
        $deletedRetryState = (int) $retryStmt->rowCount();

        return [$deletedLogs, $deletedRetryState];
    }
}
