<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – ExecutionFinishEndpoint
 *
 * Handles POST /execution/finish requests.
 *
 * This endpoint is called by the cron-wrapper.sh bash script immediately after
 * the managed job command exits. It updates the corresponding `execution_log`
 * row with the exit code, captured output, and finished_at timestamp.
 *
 * Notifications are dispatched in two situations:
 *   1. The job exited with a non-zero code and has `notify_on_failure` enabled.
 *   2. The job's runtime exceeded `execution_limit_seconds` and a notification
 *      has not been sent yet (notified_limit_exceeded = 0).  This handles
 *      short-lived jobs that exceeded their limit but finished before the
 *      periodic check-limits.php checker ran.
 *
 * Request body (JSON):
 * ```json
 * {
 *   "execution_id": 123,
 *   "job_id":       42,
 *   "exit_code":    1,
 *   "output":       "Error: file not found",
 *   "finished_at":  "2026-03-15T10:00:05Z"
 * }
 * ```
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "execution_id": 123,
 *   "job_id":       42,
 *   "exit_code":    1,
 *   "notified":     true
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

use Cronmanager\Agent\Cron\CrontabManager;
use Cronmanager\Agent\Notification\MailNotifier;
use Cronmanager\Agent\Notification\TelegramNotifier;
use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class ExecutionFinishEndpoint
 *
 * Records the completion of a cron job execution and optionally sends a
 * failure-alert e-mail when the exit code is non-zero.
 *
 * Validation rules:
 *   - `execution_id` required integer > 0; the corresponding execution_log row must exist.
 *   - `job_id`       required integer > 0.
 *   - `exit_code`    required integer (may be 0 for success).
 *   - `finished_at`  required non-empty string (ISO 8601 recommended).
 *   - `output`       optional string (defaults to empty string).
 */
final class ExecutionFinishEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * ExecutionFinishEndpoint constructor.
     *
     * @param PDO              $pdo              Active PDO database connection.
     * @param Logger           $logger           Monolog logger instance.
     * @param MailNotifier     $mailNotifier     Mail notifier for failure alerts.
     * @param TelegramNotifier $telegramNotifier Telegram notifier for failure alerts.
     * @param CrontabManager   $crontabManager   CrontabManager for scheduling retries.
     * @param string           $wrapperScript    Absolute path to cron-wrapper.sh.
     */
    public function __construct(
        private readonly PDO              $pdo,
        private readonly Logger           $logger,
        private readonly MailNotifier     $mailNotifier,
        private readonly TelegramNotifier $telegramNotifier,
        private readonly CrontabManager   $crontabManager,
        private readonly string           $wrapperScript,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming POST /execution/finish request.
     *
     * @param array<string, string> $params Path parameters extracted by the Router
     *                                      (unused for this endpoint).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $this->logger->debug('ExecutionFinishEndpoint: handling POST /execution/finish');

        // ------------------------------------------------------------------
        // 1. Parse JSON body
        // ------------------------------------------------------------------

        $rawBody = (string) file_get_contents('php://input');
        $body    = json_decode($rawBody, true);

        if (!is_array($body)) {
            $this->logger->warning('ExecutionFinishEndpoint: invalid JSON body', [
                'raw_body' => mb_substr($rawBody, 0, 200),
            ]);
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Request body must be valid JSON.',
                'code'    => 400,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 2. Validate required fields
        // ------------------------------------------------------------------

        $errors = $this->validate($body);

        if ($errors !== []) {
            jsonResponse(422, [
                'error'  => 'Validation failed',
                'fields' => $errors,
            ]);
            return;
        }

        $executionId = (int) $body['execution_id'];
        $jobId       = (int) $body['job_id'];
        $exitCode    = (int) $body['exit_code'];
        $output      = isset($body['output']) ? (string) $body['output'] : '';
        $target      = isset($body['target']) && is_string($body['target']) && $body['target'] !== ''
            ? $body['target']
            : null;

        // Normalise finished_at to the format MariaDB DATETIME expects (UTC).
        try {
            $dt         = new \DateTimeImmutable((string) $body['finished_at']);
            $finishedAt = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            $finishedAt = date('Y-m-d H:i:s');
        }

        // ------------------------------------------------------------------
        // 3. Verify the execution_log row exists
        // ------------------------------------------------------------------

        try {
            if (!$this->executionExists($executionId)) {
                $this->logger->warning('ExecutionFinishEndpoint: execution log row not found', [
                    'execution_id' => $executionId,
                ]);
                jsonResponse(404, [
                    'error'   => 'Not Found',
                    'message' => sprintf('Execution log entry with ID %d does not exist.', $executionId),
                    'code'    => 404,
                ]);
                return;
            }

            // ------------------------------------------------------------------
            // 4. UPDATE the execution_log row
            // ------------------------------------------------------------------

            // Guard: only update rows that are still running.  If check-limits.php
            // already auto-killed this execution (finished_at IS NOT NULL, exit_code = -2),
            // we must not overwrite it with the wrapper's exit code (e.g. 143 from SIGTERM).
            $stmt = $this->pdo->prepare(
                'UPDATE execution_log
                    SET finished_at = :finished_at,
                        exit_code   = :exit_code,
                        output      = :output,
                        target      = COALESCE(:target, target),
                        pid         = NULL,
                        pid_file    = NULL
                  WHERE id = :id
                    AND finished_at IS NULL'
            );
            $stmt->execute([
                ':finished_at' => $finishedAt,
                ':exit_code'   => $exitCode,
                ':output'      => $output,
                ':target'      => $target,
                ':id'          => $executionId,
            ]);

            $alreadyFinished = $stmt->rowCount() === 0;

            if ($alreadyFinished) {
                // Execution was already closed by check-limits.php (auto-kill).
                // Acknowledge silently without overwriting the stored result.
                $this->logger->info('ExecutionFinishEndpoint: execution already finished (auto-killed), ignoring wrapper finish report', [
                    'execution_id' => $executionId,
                    'job_id'       => $jobId,
                    'wrapper_exit_code' => $exitCode,
                ]);
            } else {
                $this->logger->info('ExecutionFinishEndpoint: execution finished', [
                    'execution_id' => $executionId,
                    'job_id'       => $jobId,
                    'exit_code'    => $exitCode,
                    'finished_at'  => $finishedAt,
                ]);
            }

        } catch (PDOException $e) {
            $this->logger->error('ExecutionFinishEndpoint: database error while updating execution log', [
                'execution_id' => $executionId,
                'message'      => $e->getMessage(),
            ]);

            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to record execution finish.',
                'code'    => 500,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 5. Send failure / limit-exceeded notification
        // ------------------------------------------------------------------

        // Skip notification dispatch entirely when auto-kill already handled it
        // or when the execution ran during a maintenance window.
        $duringMaintenance = $this->isDuringMaintenance($executionId);

        if ($alreadyFinished) {
            jsonResponse(200, [
                'execution_id' => $executionId,
                'job_id'       => $jobId,
                'exit_code'    => $exitCode,
                'finished_at'  => $finishedAt,
            ]);
            return;
        }

        $notified = false;

        // Suppress all failure notifications when the job ran inside a
        // maintenance window (during_maintenance = 1 set at execution start).
        if ($duringMaintenance) {
            $this->logger->info('ExecutionFinishEndpoint: notification suppressed – execution ran during maintenance window', [
                'execution_id' => $executionId,
                'job_id'       => $jobId,
                'exit_code'    => $exitCode,
            ]);

            jsonResponse(200, [
                'execution_id' => $executionId,
                'job_id'       => $jobId,
                'exit_code'    => $exitCode,
                'notified'     => false,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 5a. Auto-retry on failure
        //
        // When the job failed (non-zero exit), check if more retries are
        // configured and remaining.  If yes, schedule the next retry via a
        // once-only crontab entry and write the retry state so the next
        // ExecutionStartEndpoint call can link the execution back.
        // Notification is suppressed until all retries are exhausted.
        // ------------------------------------------------------------------

        $retryScheduled = false;

        if ($exitCode !== 0 && !$alreadyFinished) {
            try {
                $job = $this->fetchJob($jobId);

                if ($job !== null) {
                    $retryCount        = (int)  ($job['retry_count']         ?? 0);
                    $retryDelayMinutes = max(1, (int) ($job['retry_delay_minutes'] ?? 1));

                    // Fetch current attempt from the execution log
                    $currentAttempt = $this->fetchRetryAttempt($executionId);

                    if ($retryCount > 0 && $currentAttempt < $retryCount) {
                        $nextAttempt = $currentAttempt + 1;

                        // Determine root execution id (either this execution or its parent)
                        $rootExecutionId = $this->fetchRetryRootExecutionId($executionId) ?? $executionId;

                        // Determine the effective target for this execution
                        $effectiveTarget = $target ?? 'local';

                        // Compute the crontab schedule for retry_delay_minutes from now
                        $tz       = new \DateTimeZone($this->resolveSystemTimezone());
                        $fireAt   = new \DateTime('+' . $retryDelayMinutes . ' minutes', $tz);
                        $schedule = sprintf(
                            '%d %d %d %d *',
                            (int) $fireAt->format('i'),
                            (int) $fireAt->format('G'),
                            (int) $fireAt->format('j'),
                            (int) $fireAt->format('n'),
                        );

                        // Write pending retry state so ExecutionStartEndpoint can pick it up
                        $this->pdo->prepare(
                            'INSERT INTO job_retry_state
                                (job_id, target, next_retry_attempt, root_execution_id,
                                 retry_delay_minutes, scheduled_at)
                             VALUES (:job_id, :target, :next_attempt, :root_id, :delay, :scheduled_at)
                             ON DUPLICATE KEY UPDATE
                                next_retry_attempt  = :next_attempt,
                                root_execution_id   = :root_id,
                                retry_delay_minutes = :delay,
                                scheduled_at        = :scheduled_at'
                        )->execute([
                            ':job_id'       => $jobId,
                            ':target'       => $effectiveTarget,
                            ':next_attempt' => $nextAttempt,
                            ':root_id'      => $rootExecutionId,
                            ':delay'        => $retryDelayMinutes,
                            ':scheduled_at' => (new \DateTime('now', $tz))->format('Y-m-d H:i:s'),
                        ]);

                        // Add once-only crontab entry
                        $this->crontabManager->addOnceEntry(
                            (string) $job['linux_user'],
                            $jobId,
                            $schedule,
                            $this->wrapperScript,
                            $effectiveTarget,
                        );

                        $retryScheduled = true;

                        $this->logger->info('ExecutionFinishEndpoint: retry scheduled', [
                            'execution_id'       => $executionId,
                            'job_id'             => $jobId,
                            'next_attempt'       => $nextAttempt,
                            'retry_count'        => $retryCount,
                            'delay_minutes'      => $retryDelayMinutes,
                            'schedule'           => $schedule,
                            'target'             => $effectiveTarget,
                            'root_execution_id'  => $rootExecutionId,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                // Retry scheduling failure must not prevent the finish response
                $this->logger->error('ExecutionFinishEndpoint: error scheduling retry', [
                    'execution_id' => $executionId,
                    'job_id'       => $jobId,
                    'message'      => $e->getMessage(),
                ]);
            }
        }

        // When job succeeded, clean up any leftover retry state for this (job, target)
        // (e.g. if a retry eventually succeeded – no stale rows should remain)
        if ($exitCode === 0 && $target !== null) {
            try {
                $this->pdo->prepare(
                    'DELETE FROM job_retry_state WHERE job_id = :job_id AND target = :target'
                )->execute([':job_id' => $jobId, ':target' => $target]);
            } catch (\Throwable) {
                // Best-effort, non-critical
            }
        }

        try {
            $job = $this->fetchJob($jobId);

            if ($job !== null && (bool) $job['notify_on_failure']) {
                // Use description if available, otherwise fall back to command
                $label     = ($job['description'] !== null && $job['description'] !== '')
                    ? (string) $job['description']
                    : (string) $job['command'];
                $startedAt = $this->fetchStartedAt($executionId);

                // ---- Failure notification ----
                // When a retry is scheduled, suppress notification until all retries exhausted.
                // When retryScheduled is false (no more retries or retry not configured),
                // send notification for non-zero exit codes as usual.
                if ($exitCode !== 0 && !$retryScheduled) {
                    $retryCount          = (int) ($job['retry_count']          ?? 0);
                    $notifyAfterFailures = max(1, (int) ($job['notify_after_failures'] ?? 1));
                    $currentAttempt      = $this->fetchRetryAttempt($executionId);

                    // Add retry context to the output when this was a retry
                    $notifyOutput = $output;
                    if ($retryCount > 0 && $currentAttempt > 0) {
                        $notifyOutput = sprintf(
                            '[Attempt %d/%d failed]\n\n%s',
                            $currentAttempt + 1,
                            $retryCount + 1,
                            $output,
                        );
                    }

                    // threshold=1 (default): notify on every failure – no count query needed,
                    // preserves original behaviour exactly.
                    // threshold>1: notify only the first time the streak reaches the
                    // threshold; subsequent failures are silent until the job recovers.
                    $shouldNotify = true;
                    if ($notifyAfterFailures > 1) {
                        $consecutiveFailures = $this->countConsecutiveFailedRuns($jobId, $target);
                        $shouldNotify        = ($consecutiveFailures === $notifyAfterFailures);

                        if (!$shouldNotify) {
                            $this->logger->info('ExecutionFinishEndpoint: failure notification suppressed (threshold not reached or already notified)', [
                                'job_id'               => $jobId,
                                'consecutive_failures' => $consecutiveFailures,
                                'threshold'            => $notifyAfterFailures,
                            ]);
                        }
                    }

                    if ($shouldNotify) {
                        $notified = $this->dispatchNotification(
                            jobId:               $jobId,
                            description:         $label,
                            linuxUser:           (string) $job['linux_user'],
                            schedule:            (string) $job['schedule'],
                            exitCode:            $exitCode,
                            output:              $notifyOutput,
                            startedAt:           $startedAt,
                            finishedAt:          $finishedAt,
                            notifyAfterFailures: $notifyAfterFailures,
                        );
                    }
                }

                // ---- Limit-exceeded notification (if not already sent by check-limits.php) ----
                $limitSeconds = isset($job['execution_limit_seconds']) && $job['execution_limit_seconds'] !== null
                    ? (int) $job['execution_limit_seconds']
                    : null;

                if ($limitSeconds !== null && !$this->isLimitNotified($executionId)) {
                    // Calculate actual elapsed seconds from the stored started_at
                    $elapsedSeconds = $this->calcElapsedSeconds($startedAt, $finishedAt);

                    if ($elapsedSeconds > $limitSeconds) {
                        $limitOutput = sprintf(
                            'Execution limit exceeded: job ran for %d seconds (limit: %d seconds).%s',
                            $elapsedSeconds,
                            $limitSeconds,
                            $output !== '' ? "\n\nOutput:\n" . $output : '',
                        );

                        $notified = $this->dispatchNotification(
                            jobId:       $jobId,
                            description: $label,
                            linuxUser:   (string) $job['linux_user'],
                            schedule:    (string) $job['schedule'],
                            exitCode:    -3,  // sentinel: -3 = limit exceeded
                            output:      $limitOutput,
                            startedAt:   $startedAt,
                            finishedAt:  $finishedAt
                        );

                        // Mark as notified to prevent a duplicate from check-limits.php
                        $this->markLimitNotified($executionId);

                        $this->logger->info('ExecutionFinishEndpoint: limit-exceeded notification dispatched at finish', [
                            'execution_id'    => $executionId,
                            'elapsed_seconds' => $elapsedSeconds,
                            'limit_seconds'   => $limitSeconds,
                        ]);
                    }
                }
            } else {
                $this->logger->debug('ExecutionFinishEndpoint: notification skipped (notify_on_failure=false or job not found)', [
                    'job_id'    => $jobId,
                    'exit_code' => $exitCode,
                ]);
            }
        } catch (\Throwable $e) {
            // Notification failure must never prevent a successful 200 response
            $this->logger->error('ExecutionFinishEndpoint: unexpected error during notification', [
                'execution_id' => $executionId,
                'job_id'       => $jobId,
                'message'      => $e->getMessage(),
            ]);
        }

        // ------------------------------------------------------------------
        // 6. Return result
        // ------------------------------------------------------------------

        jsonResponse(200, [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => $exitCode,
            'notified'     => $notified,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate the request body for POST /execution/finish.
     *
     * @param array<string, mixed> $body Decoded JSON request body.
     *
     * @return array<string, string> Map of field name → error message. Empty when valid.
     */
    private function validate(array $body): array
    {
        $errors = [];

        // execution_id: required integer > 0
        if (!isset($body['execution_id'])) {
            $errors['execution_id'] = 'Field is required.';
        } elseif (!is_int($body['execution_id']) || $body['execution_id'] <= 0) {
            $errors['execution_id'] = 'Must be a positive integer.';
        }

        // job_id: required integer > 0
        if (!isset($body['job_id'])) {
            $errors['job_id'] = 'Field is required.';
        } elseif (!is_int($body['job_id']) || $body['job_id'] <= 0) {
            $errors['job_id'] = 'Must be a positive integer.';
        }

        // exit_code: required integer (0 is valid)
        if (!isset($body['exit_code'])) {
            $errors['exit_code'] = 'Field is required.';
        } elseif (!is_int($body['exit_code'])) {
            $errors['exit_code'] = 'Must be an integer.';
        }

        // finished_at: required non-empty string
        if (!isset($body['finished_at'])) {
            $errors['finished_at'] = 'Field is required.';
        } elseif (!is_string($body['finished_at']) || trim($body['finished_at']) === '') {
            $errors['finished_at'] = 'Must be a non-empty string (ISO 8601 timestamp).';
        }

        // output: optional string
        if (isset($body['output']) && !is_string($body['output'])) {
            $errors['output'] = 'Must be a string.';
        }

        return $errors;
    }

    /**
     * Check whether an execution_log row with the given ID exists.
     *
     * @param int $executionId The execution log ID to look up.
     *
     * @return bool True if the row exists, false otherwise.
     *
     * @throws PDOException On database errors.
     */
    private function executionExists(int $executionId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM execution_log WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $executionId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Fetch a single cron job row from the database.
     *
     * Returns null when no row with the given ID can be found.
     *
     * @param int $jobId The cron job ID.
     *
     * @return array<string, mixed>|null Job row or null if not found.
     *
     * @throws PDOException On database errors.
     */
    private function fetchJob(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, linux_user, schedule, command, description, notify_on_failure,
                    execution_limit_seconds, retry_count, retry_delay_minutes,
                    notify_after_failures
               FROM cronjobs
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch();

        return $row !== false ? (array) $row : null;
    }

    /**
     * Fetch the retry_attempt value for an execution log row.
     *
     * @param int $executionId Execution log ID.
     *
     * @return int Retry attempt (0 = original run).
     */
    private function fetchRetryAttempt(int $executionId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT retry_attempt FROM execution_log WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $executionId]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (int) $value : 0;
    }

    /**
     * Fetch the retry_root_execution_id for an execution log row.
     * Returns null when this is an original (non-retry) execution.
     *
     * @param int $executionId Execution log ID.
     *
     * @return int|null Root execution ID or null.
     */
    private function fetchRetryRootExecutionId(int $executionId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT retry_root_execution_id FROM execution_log WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $executionId]);
        $value = $stmt->fetchColumn();

        return ($value !== false && $value !== null) ? (int) $value : null;
    }

    /**
     * Resolve the host system timezone (same logic as ExecuteNowEndpoint).
     *
     * @return string A valid timezone identifier.
     */
    private function resolveSystemTimezone(): string
    {
        $envTz = getenv('TZ');
        if ($envTz !== false && $envTz !== '') {
            return $envTz;
        }

        $link = @readlink('/etc/localtime');
        if ($link !== false) {
            $pos = strpos($link, 'zoneinfo/');
            if ($pos !== false) {
                $tz = substr($link, $pos + strlen('zoneinfo/'));
                if ($tz !== '') {
                    return $tz;
                }
            }
        }

        if (is_readable('/etc/timezone')) {
            $tz = trim((string) file_get_contents('/etc/timezone'));
            if ($tz !== '') {
                return $tz;
            }
        }

        return date_default_timezone_get();
    }

    /**
     * Check whether a limit-exceeded notification has already been sent for an execution.
     *
     * @param int $executionId Execution log ID.
     *
     * @return bool True when the notification has already been dispatched.
     *
     * @throws PDOException On database errors.
     */
    private function isLimitNotified(int $executionId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT notified_limit_exceeded FROM execution_log WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $executionId]);
        $value = $stmt->fetchColumn();

        return $value !== false && (bool) $value;
    }

    /**
     * Set notified_limit_exceeded = 1 for an execution to prevent duplicate alerts.
     *
     * @param int $executionId Execution log ID.
     *
     * @return void
     *
     * @throws PDOException On database errors.
     */
    private function markLimitNotified(int $executionId): void
    {
        $this->pdo->prepare(
            'UPDATE execution_log SET notified_limit_exceeded = 1 WHERE id = :id'
        )->execute([':id' => $executionId]);
    }

    /**
     * Calculate elapsed seconds between two datetime strings.
     *
     * @param string $startedAt  ISO 8601 / MySQL datetime string.
     * @param string $finishedAt ISO 8601 / MySQL datetime string.
     *
     * @return int Elapsed seconds (0 on parse error).
     */
    private function calcElapsedSeconds(string $startedAt, string $finishedAt): int
    {
        try {
            $start  = new \DateTimeImmutable($startedAt);
            $finish = new \DateTimeImmutable($finishedAt);
            return max(0, (int) $finish->getTimestamp() - (int) $start->getTimestamp());
        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * Dispatch a failure notification via a detached background process.
     *
     * Writes the notification payload to a temporary file and spawns
     * agent/bin/send-notification.php as a background process (`&`).
     * The HTTP response is returned immediately; the background process
     * handles SMTP independently and cannot block the agent.
     *
     * Falls back to synchronous sending via MailNotifier when:
     *   - send-notification.php cannot be located
     *   - exec() is unavailable (disabled_functions)
     *   - The temporary file cannot be written
     *
     * @param int    $jobId
     * @param string $description
     * @param string $linuxUser
     * @param string $schedule
     * @param int    $exitCode
     * @param string $output
     * @param string $startedAt
     * @param string $finishedAt
     *
     * @return bool True if dispatched (or synchronously sent), false on error.
     */
    private function dispatchNotification(
        int    $jobId,
        string $description,
        string $linuxUser,
        string $schedule,
        int    $exitCode,
        string $output,
        string $startedAt,
        string $finishedAt,
        int    $notifyAfterFailures = 1,
    ): bool {
        // Path: agent/src/Endpoints/ → up 2 levels → agent/ → bin/send-notification.php
        $notifyScript = dirname(__DIR__, 2) . '/bin/send-notification.php';

        // Check prerequisites for background dispatch
        $execAvailable = function_exists('exec')
            && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);

        if (!file_exists($notifyScript) || !$execAvailable) {
            $this->logger->warning('ExecutionFinishEndpoint: falling back to synchronous notification sending', [
                'script_exists'  => file_exists($notifyScript),
                'exec_available' => $execAvailable,
            ]);
            $mailSent     = $this->mailNotifier->sendFailureAlert(
                jobId:               $jobId,
                description:         $description,
                linuxUser:           $linuxUser,
                schedule:            $schedule,
                exitCode:            $exitCode,
                output:              $output,
                startedAt:           $startedAt,
                finishedAt:          $finishedAt,
                notifyAfterFailures: $notifyAfterFailures,
            );
            $telegramSent = $this->telegramNotifier->sendFailureAlert(
                jobId:               $jobId,
                description:         $description,
                linuxUser:           $linuxUser,
                schedule:            $schedule,
                exitCode:            $exitCode,
                output:              $output,
                startedAt:           $startedAt,
                finishedAt:          $finishedAt,
                notifyAfterFailures: $notifyAfterFailures,
            );
            return $mailSent || $telegramSent;
        }

        // Write payload to a temp file; the background script deletes it after reading
        $tempFile = tempnam(sys_get_temp_dir(), 'cronmgr_notify_');

        if ($tempFile === false) {
            $this->logger->error('ExecutionFinishEndpoint: tempnam() failed – falling back to synchronous notification sending');
            $mailSent     = $this->mailNotifier->sendFailureAlert(
                jobId:               $jobId,
                description:         $description,
                linuxUser:           $linuxUser,
                schedule:            $schedule,
                exitCode:            $exitCode,
                output:              $output,
                startedAt:           $startedAt,
                finishedAt:          $finishedAt,
                notifyAfterFailures: $notifyAfterFailures,
            );
            $telegramSent = $this->telegramNotifier->sendFailureAlert(
                jobId:               $jobId,
                description:         $description,
                linuxUser:           $linuxUser,
                schedule:            $schedule,
                exitCode:            $exitCode,
                output:              $output,
                startedAt:           $startedAt,
                finishedAt:          $finishedAt,
                notifyAfterFailures: $notifyAfterFailures,
            );
            return $mailSent || $telegramSent;
        }

        $payload = json_encode([
            'job_id'               => $jobId,
            'description'          => $description,
            'linux_user'           => $linuxUser,
            'schedule'             => $schedule,
            'exit_code'            => $exitCode,
            'output'               => $output,
            'started_at'           => $startedAt,
            'finished_at'          => $finishedAt,
            'notify_after_failures' => $notifyAfterFailures,
        ], JSON_UNESCAPED_UNICODE);

        if (file_put_contents($tempFile, $payload) === false) {
            @unlink($tempFile);
            $this->logger->error('ExecutionFinishEndpoint: failed to write notification temp file – falling back to synchronous notification sending');
            $mailSent     = $this->mailNotifier->sendFailureAlert(
                jobId:               $jobId,
                description:         $description,
                linuxUser:           $linuxUser,
                schedule:            $schedule,
                exitCode:            $exitCode,
                output:              $output,
                startedAt:           $startedAt,
                finishedAt:          $finishedAt,
                notifyAfterFailures: $notifyAfterFailures,
            );
            $telegramSent = $this->telegramNotifier->sendFailureAlert(
                jobId:               $jobId,
                description:         $description,
                linuxUser:           $linuxUser,
                schedule:            $schedule,
                exitCode:            $exitCode,
                output:              $output,
                startedAt:           $startedAt,
                finishedAt:          $finishedAt,
                notifyAfterFailures: $notifyAfterFailures,
            );
            return $mailSent || $telegramSent;
        }

        // Spawn the notification process in the background.
        // `timeout 30` provides a hard OS-level kill if SMTP truly hangs.
        // stdout/stderr are discarded; the script logs via the shared agent log file.
        $cmd = sprintf(
            'timeout 30 php %s %s > /dev/null 2>&1 &',
            escapeshellarg($notifyScript),
            escapeshellarg($tempFile)
        );
        exec($cmd);

        $this->logger->info('ExecutionFinishEndpoint: failure notification dispatched to background process', [
            'job_id'    => $jobId,
            'exit_code' => $exitCode,
        ]);

        return true;
    }

    /**
     * Count consecutive failed original runs for a given job and target.
     *
     * "Consecutive" means uninterrupted by any successful execution (exit_code = 0,
     * any retry_attempt).  Only retry_attempt = 0 rows are counted as individual
     * "runs" so that each retry chain counts as one logical failure event.
     *
     * Exit code -4 (skipped during maintenance window) is excluded because a skip
     * is not a failure and should not advance the failure counter.
     *
     * The current execution must already be committed before this method is called.
     *
     * @param int         $jobId  Cron job ID.
     * @param string|null $target Execution target ("local", SSH alias, or NULL for legacy rows).
     *
     * @return int Number of consecutive failed runs since the last success.
     */
    private function countConsecutiveFailedRuns(int $jobId, ?string $target): int
    {
        // Find the started_at of the most recent successful execution for this
        // job/target (success = exit_code 0, any retry attempt).
        // PDO named parameters may not be reused within one query, so each
        // occurrence of the target value gets a distinct placeholder name.
        $lastSuccessStmt = $this->pdo->prepare(
            'SELECT MAX(started_at)
               FROM execution_log
              WHERE cronjob_id = :job_id
                AND (:target1 IS NULL AND target IS NULL OR target = :target2)
                AND exit_code = 0
                AND finished_at IS NOT NULL'
        );
        $lastSuccessStmt->execute([':job_id' => $jobId, ':target1' => $target, ':target2' => $target]);
        $lastSuccess = $lastSuccessStmt->fetchColumn();

        // Count original runs (retry_attempt = 0) that started after the last
        // success and have a real failure exit code (not 0, not -4 maintenance skip).
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM execution_log
              WHERE cronjob_id = :job_id
                AND (:target1 IS NULL AND target IS NULL OR target = :target2)
                AND retry_attempt = 0
                AND finished_at IS NOT NULL
                AND exit_code != 0
                AND exit_code != -4
                AND started_at > COALESCE(:last_success, \'1970-01-01 00:00:00\')'
        );
        $countStmt->execute([
            ':job_id'       => $jobId,
            ':target1'      => $target,
            ':target2'      => $target,
            ':last_success' => ($lastSuccess !== false && $lastSuccess !== null) ? (string) $lastSuccess : null,
        ]);

        return (int) $countStmt->fetchColumn();
    }

    /**
     * Return true when the execution was recorded as running during a
     * maintenance window (during_maintenance = 1).
     *
     * @param int $executionId Execution log ID.
     *
     * @return bool
     *
     * @throws PDOException On database errors.
     */
    private function isDuringMaintenance(int $executionId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT during_maintenance FROM execution_log WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $executionId]);
        $value = $stmt->fetchColumn();

        return $value !== false && (bool) $value;
    }

    /**
     * Fetch the started_at timestamp for an execution log row.
     *
     * Returns an empty string when the row cannot be found (should not
     * happen in normal flow, as existence has already been verified).
     *
     * @param int $executionId The execution log ID.
     *
     * @return string The started_at timestamp string, or '' if not found.
     *
     * @throws PDOException On database errors.
     */
    private function fetchStartedAt(int $executionId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT started_at FROM execution_log WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $executionId]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : '';
    }
}
