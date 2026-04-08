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
     */
    public function __construct(
        private readonly PDO              $pdo,
        private readonly Logger           $logger,
        private readonly MailNotifier     $mailNotifier,
        private readonly TelegramNotifier $telegramNotifier,
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

        try {
            $job = $this->fetchJob($jobId);

            if ($job !== null && (bool) $job['notify_on_failure']) {
                // Use description if available, otherwise fall back to command
                $label     = ($job['description'] !== null && $job['description'] !== '')
                    ? (string) $job['description']
                    : (string) $job['command'];
                $startedAt = $this->fetchStartedAt($executionId);

                // ---- Failure notification (non-zero exit code) ----
                if ($exitCode !== 0) {
                    $notified = $this->dispatchNotification(
                        jobId:       $jobId,
                        description: $label,
                        linuxUser:   (string) $job['linux_user'],
                        schedule:    (string) $job['schedule'],
                        exitCode:    $exitCode,
                        output:      $output,
                        startedAt:   $startedAt,
                        finishedAt:  $finishedAt
                    );
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
                    execution_limit_seconds
               FROM cronjobs
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch();

        return $row !== false ? (array) $row : null;
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
        string $finishedAt
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
                jobId:       $jobId,
                description: $description,
                linuxUser:   $linuxUser,
                schedule:    $schedule,
                exitCode:    $exitCode,
                output:      $output,
                startedAt:   $startedAt,
                finishedAt:  $finishedAt
            );
            $telegramSent = $this->telegramNotifier->sendFailureAlert(
                jobId:       $jobId,
                description: $description,
                linuxUser:   $linuxUser,
                schedule:    $schedule,
                exitCode:    $exitCode,
                output:      $output,
                startedAt:   $startedAt,
                finishedAt:  $finishedAt
            );
            return $mailSent || $telegramSent;
        }

        // Write payload to a temp file; the background script deletes it after reading
        $tempFile = tempnam(sys_get_temp_dir(), 'cronmgr_notify_');

        if ($tempFile === false) {
            $this->logger->error('ExecutionFinishEndpoint: tempnam() failed – falling back to synchronous notification sending');
            $mailSent     = $this->mailNotifier->sendFailureAlert(
                jobId:       $jobId,
                description: $description,
                linuxUser:   $linuxUser,
                schedule:    $schedule,
                exitCode:    $exitCode,
                output:      $output,
                startedAt:   $startedAt,
                finishedAt:  $finishedAt
            );
            $telegramSent = $this->telegramNotifier->sendFailureAlert(
                jobId:       $jobId,
                description: $description,
                linuxUser:   $linuxUser,
                schedule:    $schedule,
                exitCode:    $exitCode,
                output:      $output,
                startedAt:   $startedAt,
                finishedAt:  $finishedAt
            );
            return $mailSent || $telegramSent;
        }

        $payload = json_encode([
            'job_id'      => $jobId,
            'description' => $description,
            'linux_user'  => $linuxUser,
            'schedule'    => $schedule,
            'exit_code'   => $exitCode,
            'output'      => $output,
            'started_at'  => $startedAt,
            'finished_at' => $finishedAt,
        ], JSON_UNESCAPED_UNICODE);

        if (file_put_contents($tempFile, $payload) === false) {
            @unlink($tempFile);
            $this->logger->error('ExecutionFinishEndpoint: failed to write notification temp file – falling back to synchronous notification sending');
            $mailSent     = $this->mailNotifier->sendFailureAlert(
                jobId:       $jobId,
                description: $description,
                linuxUser:   $linuxUser,
                schedule:    $schedule,
                exitCode:    $exitCode,
                output:      $output,
                startedAt:   $startedAt,
                finishedAt:  $finishedAt
            );
            $telegramSent = $this->telegramNotifier->sendFailureAlert(
                jobId:       $jobId,
                description: $description,
                linuxUser:   $linuxUser,
                schedule:    $schedule,
                exitCode:    $exitCode,
                output:      $output,
                startedAt:   $startedAt,
                finishedAt:  $finishedAt
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
