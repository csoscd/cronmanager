<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MaintenanceOnceCleanupEndpoint
 *
 * Handles POST /maintenance/once/cleanup
 *
 * Scans every candidate Linux user's crontab and removes all stale
 * "cronmanager-once" entries left behind by Run Now jobs whose automatic
 * cleanup call failed (e.g. because the agent was unreachable at the time
 * the wrapper script tried to self-remove the entry).
 *
 * Response on success (HTTP 200):
 * ```json
 * { "removed": 4, "users_affected": 1,
 *   "message": "Removed 4 stale Run Now entry(s) across 1 user(s)." }
 * ```
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Cron\CrontabManager;
use Monolog\Logger;

/**
 * Class MaintenanceOnceCleanupEndpoint
 *
 * Removes all stale once-only crontab entries from all user crontabs.
 */
final class MaintenanceOnceCleanupEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param Logger         $logger         Monolog logger instance.
     * @param CrontabManager $crontabManager CrontabManager instance.
     */
    public function __construct(
        private readonly Logger         $logger,
        private readonly CrontabManager $crontabManager,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle POST /maintenance/once/cleanup.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function handle(array $params): void
    {
        $this->logger->info('MaintenanceOnceCleanupEndpoint: starting stale once-entry cleanup');

        $totalRemoved  = 0;
        $usersAffected = 0;

        // Iterate all candidate users (root + UID >= 1000)
        try {
            $users = $this->crontabManager->getUsersWithCrontab();
        } catch (\Throwable $e) {
            $this->logger->error('MaintenanceOnceCleanupEndpoint: failed to list users', [
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to list crontab users.',
                'code'    => 500,
            ]);
            return;
        }

        foreach ($users as $user) {
            try {
                $removed = $this->crontabManager->removeAllOnceEntriesForUser($user);
                if ($removed > 0) {
                    $totalRemoved  += $removed;
                    $usersAffected++;
                }
            } catch (\Throwable $e) {
                // Non-fatal: log and continue with the next user
                $this->logger->warning('MaintenanceOnceCleanupEndpoint: failed to clean user crontab', [
                    'user'    => $user,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('MaintenanceOnceCleanupEndpoint: cleanup complete', [
            'removed'       => $totalRemoved,
            'users_affected' => $usersAffected,
        ]);

        jsonResponse(200, [
            'removed'        => $totalRemoved,
            'users_affected' => $usersAffected,
            'message'        => sprintf(
                'Removed %d stale Run Now entry(s) across %d user(s).',
                $totalRemoved,
                $usersAffected,
            ),
        ]);
    }
}
