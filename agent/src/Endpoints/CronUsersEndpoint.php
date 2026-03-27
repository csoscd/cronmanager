<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – CronUsersEndpoint
 *
 * Handles GET /crons/users requests.
 *
 * Returns all Linux users (root + UID >= 1000) that have any crontab entries,
 * whether managed by Cronmanager or not. Used by the web UI import page to
 * populate the user selector.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Cron\CrontabManager;
use Monolog\Logger;

/**
 * Class CronUsersEndpoint
 *
 * Handles GET /crons/users API requests.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "data": ["deploy", "root"],
 *   "count": 2
 * }
 * ```
 */
final class CronUsersEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * CronUsersEndpoint constructor.
     *
     * @param Logger         $logger         Monolog logger instance.
     * @param CrontabManager $crontabManager Crontab manager for user scanning.
     */
    public function __construct(
        private readonly Logger         $logger,
        private readonly CrontabManager $crontabManager,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming GET /crons/users request.
     *
     * Optional query parameter:
     *   target (string) – SSH host alias. When present and not "local", users
     *                     are fetched from the remote host via SSH rather than
     *                     from the local crontab spool.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        // Resolve optional target; treat absent / "local" as local execution
        $target = (isset($_GET['target']) && $_GET['target'] !== '' && $_GET['target'] !== 'local')
            ? (string) $_GET['target']
            : null;

        $this->logger->debug('CronUsersEndpoint: handling GET /crons/users', [
            'target' => $target ?? 'local',
        ]);

        try {
            if ($target !== null) {
                $users = $this->crontabManager->getRemoteUsersWithCrontab($target);
            } else {
                $users = $this->crontabManager->getUsersWithCrontab();
            }

            jsonResponse(200, [
                'data'  => $users,
                'count' => count($users),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('CronUsersEndpoint: invalid target', [
                'target'  => $target,
                'message' => $e->getMessage(),
            ]);
            jsonResponse(422, [
                'error'   => 'Unprocessable Entity',
                'message' => $e->getMessage(),
                'code'    => 422,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('CronUsersEndpoint: error scanning users', [
                'target'  => $target ?? 'local',
                'message' => $e->getMessage(),
            ]);

            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to retrieve crontab users.',
                'code'    => 500,
            ]);
        }
    }
}
