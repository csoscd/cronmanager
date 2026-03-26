<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – ExecuteNowEndpoint
 *
 * Handles POST /crons/{id}/execute requests to schedule a one-time immediate
 * execution of a cron job.
 *
 * Process:
 *   1. Validate the job ID and fetch the job (linux_user + targets).
 *   2. Compute a full-date cron schedule for the next minute:
 *      "{min} {hour} {dom} {month} *"
 *      The day-of-month and month fields ensure that even if the cleanup step
 *      fails, the entry fires at most once per year instead of every hour.
 *   3. Add a once-only crontab entry per target via CrontabManager::addOnceEntry().
 *      The wrapper script is invoked with the extra "--once" flag, which triggers
 *      cleanup after the job finishes.
 *   4. Return HTTP 200 with the scheduled time and cron expression.
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
 * Class ExecuteNowEndpoint
 *
 * Schedules a one-time immediate (next-minute) execution of a cron job by
 * inserting a temporary crontab entry with a full-date schedule.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "message": "Job scheduled for immediate execution.",
 *   "job_id": 42,
 *   "scheduled_at": "14:37 on 26.03.2026",
 *   "schedule": "37 14 26 3 *",
 *   "targets": ["local"]
 * }
 * ```
 */
final class ExecuteNowEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * ExecuteNowEndpoint constructor.
     *
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
     * Handle a POST /crons/{id}/execute request.
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $jobId = isset($params['id']) ? (int) $params['id'] : 0;

        $this->logger->debug('ExecuteNowEndpoint: handling POST /crons/{id}/execute', [
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
        // 1. Fetch job
        // ------------------------------------------------------------------

        $job = $this->fetchJob($jobId);

        if ($job === null) {
            $this->logger->info('ExecuteNowEndpoint: job not found', ['job_id' => $jobId]);
            jsonResponse(404, [
                'error'   => 'Not Found',
                'message' => sprintf('Cron job with ID %d does not exist.', $jobId),
                'code'    => 404,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 2. Resolve targets (fall back to 'local' for legacy jobs)
        // ------------------------------------------------------------------

        $targets = $this->fetchTargets($jobId);
        if ($targets === []) {
            $targets = ['local'];
        }

        // ------------------------------------------------------------------
        // 3. Compute full-date schedule for next minute
        //    Format: "{min} {hour} {dom} {month} *"
        //    Using day-of-month + month means the entry fires at most once per
        //    year if the cleanup step fails – far safer than "* * * * *".
        //
        //    We explicitly use the host system timezone so that the computed
        //    minute/hour values match what cron sees on its clock.
        //    Detection order: TZ env var → /etc/timezone → PHP default.
        // ------------------------------------------------------------------

        $tz   = new \DateTimeZone($this->resolveSystemTimezone());
        $next = new \DateTime('+1 minute', $tz);
        $schedule = sprintf(
            '%d %d %d %d *',
            (int) $next->format('i'),  // minute
            (int) $next->format('G'),  // hour (no leading zero)
            (int) $next->format('j'),  // day of month (no leading zero)
            (int) $next->format('n'),  // month (no leading zero)
        );
        $scheduledAt = $next->format('H:i') . ' on ' . $next->format('d.m.Y');

        // ------------------------------------------------------------------
        // 4. Add once-only crontab entries
        // ------------------------------------------------------------------

        try {
            foreach ($targets as $target) {
                $this->crontabManager->addOnceEntry(
                    (string) $job['linux_user'],
                    $jobId,
                    $schedule,
                    $this->wrapperScript,
                    $target,
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('ExecuteNowEndpoint: failed to add once-entry to crontab', [
                'job_id'  => $jobId,
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to schedule immediate execution. Check agent logs.',
                'code'    => 500,
            ]);
            return;
        }

        $this->logger->info('ExecuteNowEndpoint: once-execution scheduled', [
            'job_id'       => $jobId,
            'linux_user'   => $job['linux_user'],
            'schedule'     => $schedule,
            'targets'      => $targets,
            'scheduled_at' => $scheduledAt,
        ]);

        jsonResponse(200, [
            'message'      => 'Job scheduled for immediate execution.',
            'job_id'       => $jobId,
            'scheduled_at' => $scheduledAt,
            'schedule'     => $schedule,
            'targets'      => $targets,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch minimal job data needed for scheduling.
     *
     * @param int $jobId Job ID.
     *
     * @return array<string, mixed>|null Row with 'id' and 'linux_user', or null.
     *
     * @throws PDOException On database errors.
     */
    private function fetchJob(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, linux_user FROM cronjobs WHERE id = :id'
        );
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch();

        return ($row !== false) ? $row : null;
    }

    /**
     * Fetch the execution targets for the given job from job_targets.
     *
     * @param int $jobId Job ID.
     *
     * @return string[] List of target strings (e.g. ['local', 'ssh-backup-1']).
     */
    private function fetchTargets(int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT target FROM job_targets WHERE job_id = :id ORDER BY target'
        );
        $stmt->execute([':id' => $jobId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Resolve the host system timezone for accurate schedule computation.
     *
     * The cron daemon uses the OS timezone, not PHP's configured timezone.
     * We detect it in priority order:
     *   1. TZ environment variable (set by systemd or shell)
     *   2. /etc/timezone (Debian/Ubuntu/most Linux distros)
     *   3. PHP's configured date.timezone (php.ini fallback)
     *
     * @return string A valid timezone identifier (e.g. "Europe/Berlin").
     */
    private function resolveSystemTimezone(): string
    {
        // 1. TZ environment variable
        $envTz = getenv('TZ');
        if ($envTz !== false && $envTz !== '') {
            return $envTz;
        }

        // 2. /etc/timezone (Debian / Ubuntu / most systemd distros)
        if (is_readable('/etc/timezone')) {
            $tz = trim((string) file_get_contents('/etc/timezone'));
            if ($tz !== '') {
                return $tz;
            }
        }

        // 3. PHP's own configured timezone (date.timezone in php.ini)
        return date_default_timezone_get();
    }
}
