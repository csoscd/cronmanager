<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – ExecuteCleanupEndpoint
 *
 * Handles POST /crons/{id}/execute/cleanup requests to remove the temporary
 * once-only crontab entry after it has been executed.
 *
 * This endpoint is called by cron-wrapper.sh at the end of a run triggered
 * via the "Run Now" feature (identified by the "--once" flag).  It is a
 * best-effort cleanup: even if it fails, the entry carries a full-date
 * schedule so it fires at most once per year.
 *
 * Request body (JSON):
 * ```json
 * {"target": "local"}
 * ```
 *
 * Response on success (HTTP 200):
 * ```json
 * {"message": "Once-entry removed from crontab.", "job_id": 42, "target": "local"}
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
 * Class ExecuteCleanupEndpoint
 *
 * Removes a once-only crontab entry after its single execution completes.
 * Called automatically by the cron-wrapper when run with the "--once" flag.
 */
final class ExecuteCleanupEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * ExecuteCleanupEndpoint constructor.
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
     * Handle a POST /crons/{id}/execute/cleanup request.
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $jobId = isset($params['id']) ? (int) $params['id'] : 0;

        $this->logger->debug('ExecuteCleanupEndpoint: handling POST /crons/{id}/execute/cleanup', [
            'job_id' => $jobId,
        ]);

        if ($jobId <= 0) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Invalid or missing job ID.',
                'code'    => 400,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // Parse request body
        // ------------------------------------------------------------------

        $rawBody = (string) file_get_contents('php://input');
        $body    = json_decode($rawBody, true);
        $target  = isset($body['target']) ? trim((string) $body['target']) : '';

        if ($target === '') {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Missing required field: target.',
                'code'    => 400,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // Fetch linux_user from DB
        // ------------------------------------------------------------------

        try {
            $stmt = $this->pdo->prepare(
                'SELECT linux_user FROM cronjobs WHERE id = :id'
            );
            $stmt->execute([':id' => $jobId]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            $this->logger->error('ExecuteCleanupEndpoint: database error', [
                'job_id'  => $jobId,
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Database error during cleanup.',
                'code'    => 500,
            ]);
            return;
        }

        if ($row === false) {
            // Job was deleted between scheduling and cleanup – log and return 200
            // so the wrapper does not retry. The entry was already removed with
            // the job, or will expire harmlessly.
            $this->logger->warning('ExecuteCleanupEndpoint: job not found (may have been deleted)', [
                'job_id' => $jobId,
                'target' => $target,
            ]);
            jsonResponse(200, [
                'message' => 'Job not found – no cleanup needed.',
                'job_id'  => $jobId,
                'target'  => $target,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // Remove once-only crontab entry
        // ------------------------------------------------------------------

        try {
            $this->crontabManager->removeOnceEntries(
                (string) $row['linux_user'],
                $jobId,
                $target,
            );
        } catch (\Throwable $e) {
            $this->logger->error('ExecuteCleanupEndpoint: failed to remove once-entry', [
                'job_id'     => $jobId,
                'linux_user' => $row['linux_user'],
                'target'     => $target,
                'message'    => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to remove once-entry from crontab. It will expire harmlessly next year.',
                'code'    => 500,
            ]);
            return;
        }

        $this->logger->info('ExecuteCleanupEndpoint: once-entry removed', [
            'job_id'     => $jobId,
            'linux_user' => $row['linux_user'],
            'target'     => $target,
        ]);

        jsonResponse(200, [
            'message' => 'Once-entry removed from crontab.',
            'job_id'  => $jobId,
            'target'  => $target,
        ]);
    }
}
