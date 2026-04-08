<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MaintenanceWindowConflictEndpoint
 *
 * Handles GET /maintenance/windows/conflict requests.
 *
 * Checks whether a given cron schedule has any of its upcoming run times
 * falling inside an active maintenance window for a target.
 *
 * Query parameters:
 *   - schedule  (required) 5-field cron expression of the job to check
 *   - target    (required) Target name ("local" or SSH alias)
 *   - look_ahead (optional, default 10) Number of upcoming runs to examine
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "has_conflict": true,
 *   "conflicts": [
 *     {
 *       "run_time":    "2026-04-06T02:00:00+00:00",
 *       "window_id":   1,
 *       "window_start": "2026-04-06T02:00:00+00:00",
 *       "window_end":   "2026-04-06T03:00:00+00:00"
 *     }
 *   ]
 * }
 * ```
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cron\CronExpression;
use Cronmanager\Agent\Repository\MaintenanceWindowRepository;
use Monolog\Logger;
use PDOException;

/**
 * Class MaintenanceWindowConflictEndpoint
 */
final class MaintenanceWindowConflictEndpoint
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
     * @param array<string, string> $params Path parameters (unused).
     */
    public function handle(array $params): void
    {
        $this->logger->debug('MaintenanceWindowConflictEndpoint: handling GET /maintenance/windows/conflict');

        $schedule   = isset($_GET['schedule'])   ? (string) $_GET['schedule']   : '';
        $target     = isset($_GET['target'])     ? (string) $_GET['target']     : '';
        $lookAhead  = isset($_GET['look_ahead']) ? max(1, (int) $_GET['look_ahead']) : 10;

        if ($schedule === '') {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Query parameter "schedule" is required.',
                'code'    => 400,
            ]);
            return;
        }

        if ($target === '') {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Query parameter "target" is required.',
                'code'    => 400,
            ]);
            return;
        }

        if (!CronExpression::isValidExpression($schedule)) {
            jsonResponse(422, [
                'error'   => 'Unprocessable Entity',
                'message' => 'Query parameter "schedule" is not a valid cron expression.',
                'code'    => 422,
            ]);
            return;
        }

        try {
            $conflicts = $this->repo->detectConflicts($schedule, $target, $lookAhead);
        } catch (PDOException $e) {
            $this->logger->error('MaintenanceWindowConflictEndpoint: database error', [
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to check for conflicts.',
                'code'    => 500,
            ]);
            return;
        }

        jsonResponse(200, [
            'has_conflict' => $conflicts !== [],
            'conflicts'    => $conflicts,
        ]);
    }
}
