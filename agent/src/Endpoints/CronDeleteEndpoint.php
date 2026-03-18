<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – CronDeleteEndpoint
 *
 * Handles DELETE /crons/{id} requests to remove a cron job.
 *
 * Process:
 *   1. Fetch the existing job — return 404 if not found.
 *   2. If the job is active, remove its crontab entry via CrontabManager.
 *   3. DELETE the row from `cronjobs`; the ON DELETE CASCADE constraint on
 *      `cronjob_tags` and `execution_log` removes related rows automatically.
 *   4. Return HTTP 200 with a confirmation payload.
 *
 * This class relies on the global `jsonResponse()` function being available
 * in the calling scope (defined in agent.php).
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
 * Class CronDeleteEndpoint
 *
 * Handles DELETE /crons/{id} API requests.
 *
 * Response on success (HTTP 200):
 * ```json
 * {"message": "Job deleted", "id": 42}
 * ```
 *
 * Response on not found (HTTP 404):
 * ```json
 * {"error": "Not Found", "message": "...", "code": 404}
 * ```
 *
 * Response on server error (HTTP 500): generic error message.
 */
final class CronDeleteEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * CronDeleteEndpoint constructor.
     *
     * @param PDO            $pdo            Active PDO database connection.
     * @param Logger         $logger         Monolog logger instance.
     * @param CrontabManager $crontabManager CrontabManager for crontab cleanup.
     */
    public function __construct(
        private readonly PDO            $pdo,
        private readonly Logger         $logger,
        private readonly CrontabManager $crontabManager,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming DELETE /crons/{id} request.
     *
     * @param array<string, string> $params Path parameters extracted by the Router.
     *                                      Expected key: 'id'.
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $jobId = isset($params['id']) ? (int) $params['id'] : 0;

        $this->logger->debug('CronDeleteEndpoint: handling DELETE /crons/{id}', ['job_id' => $jobId]);

        if ($jobId <= 0) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Invalid or missing job ID.',
                'code'    => 400,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 1. Fetch existing job
        // ------------------------------------------------------------------

        $job = $this->fetchJob($jobId);

        if ($job === null) {
            $this->logger->info('CronDeleteEndpoint: job not found', ['job_id' => $jobId]);
            jsonResponse(404, [
                'error'   => 'Not Found',
                'message' => sprintf('Cron job with ID %d does not exist.', $jobId),
                'code'    => 404,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 2. Remove crontab entry if the job is active
        // ------------------------------------------------------------------

        if ((bool) $job['active']) {
            try {
                $this->crontabManager->removeEntry((string) $job['linux_user'], $jobId);

                $this->logger->info('CronDeleteEndpoint: crontab entry removed', [
                    'job_id'     => $jobId,
                    'linux_user' => $job['linux_user'],
                ]);
            } catch (\Throwable $e) {
                // Log but do not abort: the DB row must still be deleted so the
                // system remains consistent. The stale crontab entry can be
                // cleaned up manually if necessary.
                $this->logger->error('CronDeleteEndpoint: failed to remove crontab entry', [
                    'job_id'  => $jobId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // ------------------------------------------------------------------
        // 3. Delete from database
        // ------------------------------------------------------------------

        try {
            $stmt = $this->pdo->prepare('DELETE FROM cronjobs WHERE id = :id');
            $stmt->execute([':id' => $jobId]);

            $this->logger->info('CronDeleteEndpoint: job deleted from database', [
                'job_id'     => $jobId,
                'linux_user' => $job['linux_user'],
            ]);
        } catch (PDOException $e) {
            $this->logger->error('CronDeleteEndpoint: database error on delete', [
                'job_id'  => $jobId,
                'message' => $e->getMessage(),
            ]);

            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to delete cron job.',
                'code'    => 500,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 4. Return confirmation
        // ------------------------------------------------------------------

        jsonResponse(200, [
            'message' => 'Job deleted',
            'id'      => $jobId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch a minimal job record to determine its active state and linux_user.
     *
     * Returns null when no row exists for the given ID.
     *
     * @param int $jobId Job ID to look up.
     *
     * @return array<string, mixed>|null Associative row or null.
     *
     * @throws PDOException On database errors.
     */
    private function fetchJob(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, linux_user, active FROM cronjobs WHERE id = :id'
        );
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch();

        return ($row !== false) ? $row : null;
    }
}
