<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MaintenanceHistoryCleanupEndpoint
 *
 * Handles POST /maintenance/history/cleanup
 *
 * Permanently deletes finished execution records older than a configurable
 * number of days.  Only records with a non-NULL finished_at are eligible –
 * still-running executions are never deleted.
 *
 * Request body (JSON):
 * ```json
 * { "older_than_days": 90 }
 * ```
 *
 * Response on success (HTTP 200):
 * ```json
 * { "deleted": 1234, "older_than_days": 90,
 *   "message": "Deleted 1234 history records older than 90 days." }
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
 * Class MaintenanceHistoryCleanupEndpoint
 *
 * Bulk-deletes old finished execution records.
 */
final class MaintenanceHistoryCleanupEndpoint
{
    /** Minimum allowed retention period in days. */
    private const MIN_DAYS = 1;

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
     * Handle POST /maintenance/history/cleanup.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function handle(array $params): void
    {
        $body = (string) file_get_contents('php://input');
        $decoded = $body !== '' ? json_decode($body, true) : null;

        $days = max(self::MIN_DAYS, (int) ($decoded['older_than_days'] ?? 90));

        $this->logger->info('MaintenanceHistoryCleanupEndpoint: starting history cleanup', [
            'older_than_days' => $days,
        ]);

        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM execution_log
                  WHERE finished_at IS NOT NULL
                    AND finished_at < DATE_SUB(NOW(), INTERVAL :days DAY)'
            );
            $stmt->execute([':days' => $days]);
            $deleted = $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logger->error('MaintenanceHistoryCleanupEndpoint: database error', [
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to clean up history records.',
                'code'    => 500,
            ]);
            return;
        }

        $this->logger->info('MaintenanceHistoryCleanupEndpoint: cleanup complete', [
            'deleted'         => $deleted,
            'older_than_days' => $days,
        ]);

        jsonResponse(200, [
            'deleted'         => $deleted,
            'older_than_days' => $days,
            'message'         => sprintf(
                'Deleted %d history record(s) older than %d days.',
                $deleted,
                $days,
            ),
        ]);
    }
}
