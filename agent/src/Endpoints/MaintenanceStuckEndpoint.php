<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MaintenanceStuckEndpoint
 *
 * Handles GET /maintenance/executions/stuck
 *
 * Returns a list of execution records that have been in the "running" state
 * (finished_at IS NULL) for longer than a configurable threshold.
 *
 * Query parameters:
 *   hours  (int, ≥ 1, default 2)  Minimum running duration to flag.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "data": [
 *     {
 *       "id":               17,
 *       "job_id":           5,
 *       "description":      "Backup DB",
 *       "command":          "/usr/local/bin/backup.sh",
 *       "linux_user":       "root",
 *       "target":           "local",
 *       "started_at":       "2026-03-26T08:00:00+00:00",
 *       "duration_minutes": 137
 *     }
 *   ],
 *   "total":  1,
 *   "hours":  2
 * }
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
 * Class MaintenanceStuckEndpoint
 *
 * Lists executions that appear to be stuck (no finish event received).
 */
final class MaintenanceStuckEndpoint
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
     * Handle GET /maintenance/executions/stuck.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function handle(array $params): void
    {
        $hours = max(1, (int) ($_GET['hours'] ?? 2));

        $this->logger->debug('MaintenanceStuckEndpoint: querying stuck executions', [
            'hours' => $hours,
        ]);

        try {
            $stmt = $this->pdo->prepare(
                'SELECT el.id,
                        el.cronjob_id AS job_id,
                        el.target,
                        el.started_at,
                        TIMESTAMPDIFF(MINUTE, el.started_at, NOW()) AS duration_minutes,
                        c.description,
                        c.command,
                        c.linux_user
                   FROM execution_log el
                   JOIN cronjobs c ON c.id = el.cronjob_id
                  WHERE el.finished_at IS NULL
                    AND el.started_at < DATE_SUB(NOW(), INTERVAL :hours HOUR)
                  ORDER BY el.started_at ASC'
            );
            $stmt->execute([':hours' => $hours]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error('MaintenanceStuckEndpoint: database error', [
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to query stuck executions.',
                'code'    => 500,
            ]);
            return;
        }

        // Cast numeric fields for clean JSON output
        $data = array_map(static function (array $row): array {
            $row['id']               = (int) $row['id'];
            $row['job_id']           = (int) $row['job_id'];
            $row['duration_minutes'] = (int) $row['duration_minutes'];
            return $row;
        }, $rows);

        jsonResponse(200, [
            'data'  => $data,
            'total' => count($data),
            'hours' => $hours,
        ]);
    }
}
