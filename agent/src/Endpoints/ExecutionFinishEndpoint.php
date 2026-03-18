<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – ExecutionFinishEndpoint
 *
 * Handles POST /execution/finish requests.
 *
 * This endpoint is called by the cron-wrapper.sh bash script immediately after
 * the managed job command exits. It updates the corresponding `execution_log`
 * row with the exit code, captured output, and finished_at timestamp. If the
 * job exited with a non-zero code and has `notify_on_failure` enabled, a
 * failure-alert e-mail is dispatched via MailNotifier.
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
     * @param PDO          $pdo          Active PDO database connection.
     * @param Logger       $logger       Monolog logger instance.
     * @param MailNotifier $mailNotifier Mail notifier for failure alerts.
     */
    public function __construct(
        private readonly PDO          $pdo,
        private readonly Logger       $logger,
        private readonly MailNotifier $mailNotifier,
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

            $stmt = $this->pdo->prepare(
                'UPDATE execution_log
                    SET finished_at = :finished_at,
                        exit_code   = :exit_code,
                        output      = :output,
                        target      = COALESCE(:target, target)
                  WHERE id = :id'
            );
            $stmt->execute([
                ':finished_at' => $finishedAt,
                ':exit_code'   => $exitCode,
                ':output'      => $output,
                ':target'      => $target,
                ':id'          => $executionId,
            ]);

            $this->logger->info('ExecutionFinishEndpoint: execution finished', [
                'execution_id' => $executionId,
                'job_id'       => $jobId,
                'exit_code'    => $exitCode,
                'finished_at'  => $finishedAt,
            ]);

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
        // 5. Send failure notification if exit_code != 0
        // ------------------------------------------------------------------

        $notified = false;

        if ($exitCode !== 0) {
            try {
                $job = $this->fetchJob($jobId);

                if ($job !== null && (bool) $job['notify_on_failure']) {
                    // Use description if available, otherwise fall back to command
                    $label = ($job['description'] !== null && $job['description'] !== '')
                        ? (string) $job['description']
                        : (string) $job['command'];

                    $notified = $this->mailNotifier->sendFailureAlert(
                        jobId:       $jobId,
                        description: $label,
                        linuxUser:   (string) $job['linux_user'],
                        schedule:    (string) $job['schedule'],
                        exitCode:    $exitCode,
                        output:      $output,
                        startedAt:   $this->fetchStartedAt($executionId),
                        finishedAt:  $finishedAt
                    );
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
            'SELECT id, linux_user, schedule, command, description, notify_on_failure
               FROM cronjobs
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch();

        return $row !== false ? (array) $row : null;
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
