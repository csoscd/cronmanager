<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MaintenanceCrontabResyncEndpoint
 *
 * Handles POST /maintenance/crontab/resync
 *
 * Re-synchronises the crontab of every affected Linux user with the current
 * database state:
 *   • Active jobs   → syncEntries() (remove + re-add all target lines)
 *   • Inactive jobs → removeAllEntries() (remove any lingering lines)
 *
 * This is the recommended recovery action after a migration from host-agent
 * mode to docker-only mode, or after any manual crontab edit.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "synced":  5,
 *   "removed": 2,
 *   "total":   7,
 *   "errors":  [],
 *   "message": "Synced 5 active and removed 2 inactive jobs."
 * }
 * ```
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Cron\CrontabManager;
use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class MaintenanceCrontabResyncEndpoint
 *
 * Re-writes all crontab entries from the database.
 */
final class MaintenanceCrontabResyncEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param PDO            $pdo            Active PDO database connection.
     * @param Logger         $logger         Monolog logger instance.
     * @param CrontabManager $crontabManager CrontabManager for crontab operations.
     * @param string         $wrapperScript  Absolute path to cron-wrapper.sh.
     */
    public function __construct(
        private readonly PDO            $pdo,
        private readonly Logger         $logger,
        private readonly CrontabManager $crontabManager,
        private readonly string         $wrapperScript,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle POST /maintenance/crontab/resync.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function handle(array $params): void
    {
        $this->logger->info('MaintenanceCrontabResyncEndpoint: starting full crontab resync');

        try {
            $stmt = $this->pdo->query(
                'SELECT id, linux_user, schedule, active FROM cronjobs ORDER BY id'
            );
            $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error('MaintenanceCrontabResyncEndpoint: failed to fetch jobs', [
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to fetch jobs from database.',
                'code'    => 500,
            ]);
            return;
        }

        $synced  = 0;
        $removed = 0;
        $errors  = [];

        foreach ($jobs as $job) {
            $jobId     = (int)  $job['id'];
            $linuxUser = (string) $job['linux_user'];
            $schedule  = (string) $job['schedule'];
            $active    = (bool) $job['active'];

            try {
                if ($active) {
                    // Fetch configured targets; fall back to 'local' for legacy jobs
                    $tStmt = $this->pdo->prepare(
                        'SELECT target FROM job_targets WHERE job_id = :id ORDER BY target'
                    );
                    $tStmt->execute([':id' => $jobId]);
                    $targets = $tStmt->fetchAll(PDO::FETCH_COLUMN) ?: ['local'];

                    $this->crontabManager->syncEntries(
                        $linuxUser,
                        $jobId,
                        $schedule,
                        $this->wrapperScript,
                        $targets,
                    );
                    $synced++;
                    $this->logger->debug('MaintenanceCrontabResyncEndpoint: synced active job', [
                        'job_id'     => $jobId,
                        'linux_user' => $linuxUser,
                        'targets'    => $targets ?? [],
                    ]);
                } else {
                    // Remove any lingering crontab entries for inactive jobs
                    $this->crontabManager->removeAllEntries($linuxUser, $jobId);
                    $removed++;
                    $this->logger->debug('MaintenanceCrontabResyncEndpoint: removed inactive job entries', [
                        'job_id'     => $jobId,
                        'linux_user' => $linuxUser,
                    ]);
                }
            } catch (\Throwable $e) {
                $msg = sprintf('Job #%d (%s): %s', $jobId, $linuxUser, $e->getMessage());
                $errors[] = $msg;
                $this->logger->warning('MaintenanceCrontabResyncEndpoint: error processing job', [
                    'job_id'  => $jobId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $total = count($jobs);
        $this->logger->info('MaintenanceCrontabResyncEndpoint: resync complete', [
            'total'   => $total,
            'synced'  => $synced,
            'removed' => $removed,
            'errors'  => count($errors),
        ]);

        jsonResponse(200, [
            'synced'  => $synced,
            'removed' => $removed,
            'total'   => $total,
            'errors'  => $errors,
            'message' => sprintf(
                'Synced %d active and removed %d inactive jobs out of %d total.',
                $synced,
                $removed,
                $total,
            ),
        ]);
    }
}
