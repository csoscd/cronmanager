<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – ExecutionStartEndpoint
 *
 * Handles POST /execution/start requests.
 *
 * This endpoint is called by the cron-wrapper.sh bash script immediately before
 * it executes the managed job command. It creates a new row in the
 * `execution_log` table with the provided job_id and started_at timestamp,
 * and returns the new execution_id to the wrapper so that the corresponding
 * /execution/finish call can be associated with the same log row.
 *
 * Request body (JSON):
 * ```json
 * { "job_id": 42, "started_at": "2026-03-15T10:00:00Z", "target": "local" }
 * ```
 *
 * The `target` field is optional (defaults to NULL) for backward compatibility
 * with wrapper script versions that do not yet send it.
 *
 * Response on success (HTTP 201):
 * ```json
 * { "execution_id": 123, "job_id": 42 }
 * ```
 *
 * This class relies on the global `jsonResponse()` function being available
 * in the calling scope (defined in agent.php).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Repository\MaintenanceWindowRepository;
use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class ExecutionStartEndpoint
 *
 * Records the start of a cron job execution in the `execution_log` table.
 *
 * Validation rules:
 *   - `job_id`     required integer > 0; the corresponding cronjobs row must exist.
 *   - `started_at` required non-empty string (ISO 8601 recommended).
 */
final class ExecutionStartEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * ExecutionStartEndpoint constructor.
     *
     * @param PDO                         $pdo    Active PDO database connection.
     * @param Logger                      $logger Monolog logger instance.
     * @param MaintenanceWindowRepository $maintenanceRepo Maintenance window repository.
     */
    public function __construct(
        private readonly PDO                         $pdo,
        private readonly Logger                      $logger,
        private readonly MaintenanceWindowRepository $maintenanceRepo,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming POST /execution/start request.
     *
     * @param array<string, string> $params Path parameters extracted by the Router
     *                                      (unused for this endpoint).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $this->logger->debug('ExecutionStartEndpoint: handling POST /execution/start');

        // ------------------------------------------------------------------
        // 1. Parse JSON body
        // ------------------------------------------------------------------

        $rawBody = (string) file_get_contents('php://input');
        $body    = json_decode($rawBody, true);

        if (!is_array($body)) {
            $this->logger->warning('ExecutionStartEndpoint: invalid JSON body', [
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

        $jobId  = (int) $body['job_id'];
        $target = isset($body['target']) && is_string($body['target']) && $body['target'] !== ''
            ? $body['target']
            : null;

        // Normalise the timestamp to the format MariaDB DATETIME expects.
        // The wrapper script may send ISO 8601 with a timezone offset
        // (e.g. "2026-03-17T22:35:01+01:00"); convert to "Y-m-d H:i:s" in UTC.
        try {
            $dt        = new \DateTimeImmutable((string) $body['started_at']);
            $startedAt = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            $startedAt = date('Y-m-d H:i:s');
        }

        // ------------------------------------------------------------------
        // 3. Verify the job exists
        // ------------------------------------------------------------------

        try {
            if (!$this->jobExists($jobId)) {
                $this->logger->warning('ExecutionStartEndpoint: job not found', [
                    'job_id' => $jobId,
                ]);
                jsonResponse(404, [
                    'error'   => 'Not Found',
                    'message' => sprintf('Cron job with ID %d does not exist.', $jobId),
                    'code'    => 404,
                ]);
                return;
            }

            // ------------------------------------------------------------------
            // 4. Singleton guard: reject if another instance is already running
            // ------------------------------------------------------------------

            if ($this->isSingleton($jobId) && $this->hasRunningExecution($jobId)) {
                $this->logger->info('ExecutionStartEndpoint: singleton job already running – skipping', [
                    'job_id' => $jobId,
                ]);
                jsonResponse(409, [
                    'error'   => 'Conflict',
                    'message' => 'Singleton job already has a running execution.',
                    'code'    => 409,
                ]);
                return;
            }

            // ------------------------------------------------------------------
            // 5. Maintenance-window guard
            // ------------------------------------------------------------------

            $effectiveTarget  = $target ?? 'local';
            $inMaintenance    = $this->maintenanceRepo->isTargetInMaintenance($effectiveTarget);
            $runInMaintenance = $this->getRunInMaintenance($jobId);
            $duringMaintenance = $inMaintenance ? 1 : 0;

            if ($inMaintenance && !$runInMaintenance) {
                // Record a skipped execution so the history is complete, then
                // return HTTP 423 (Locked) so cron-wrapper knows to exit cleanly.
                $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

                $stmt = $this->pdo->prepare(
                    'INSERT INTO execution_log
                        (cronjob_id, started_at, finished_at, exit_code, output, target, during_maintenance)
                     VALUES (:cronjob_id, :started_at, :finished_at, :exit_code, :output, :target, 1)'
                );
                $stmt->execute([
                    ':cronjob_id' => $jobId,
                    ':started_at' => $nowUtc,
                    ':finished_at' => $nowUtc,
                    ':exit_code'  => -4,
                    ':output'     => 'Skipped: target is in a maintenance window.',
                    ':target'     => $target,
                ]);

                $skippedId = (int) $this->pdo->lastInsertId();

                $this->logger->info('ExecutionStartEndpoint: job skipped – target in maintenance window', [
                    'job_id'       => $jobId,
                    'target'       => $effectiveTarget,
                    'execution_id' => $skippedId,
                ]);

                jsonResponse(423, [
                    'error'        => 'Locked',
                    'message'      => 'Target is in a maintenance window. Execution skipped.',
                    'execution_id' => $skippedId,
                    'code'         => 423,
                ]);
                return;
            }

            // ------------------------------------------------------------------
            // 6. Insert the execution log row
            // ------------------------------------------------------------------

            $stmt = $this->pdo->prepare(
                'INSERT INTO execution_log
                    (cronjob_id, started_at, finished_at, exit_code, output, target, during_maintenance)
                 VALUES (:cronjob_id, :started_at, NULL, NULL, NULL, :target, :during_maintenance)'
            );
            $stmt->execute([
                ':cronjob_id'         => $jobId,
                ':started_at'         => $startedAt,
                ':target'             => $target,
                ':during_maintenance' => $duringMaintenance,
            ]);

            $executionId = (int) $this->pdo->lastInsertId();

            $this->logger->info('ExecutionStartEndpoint: execution started', [
                'execution_id' => $executionId,
                'job_id'       => $jobId,
                'started_at'   => $startedAt,
                'target'       => $target,
            ]);

        } catch (PDOException $e) {
            $this->logger->error('ExecutionStartEndpoint: database error', [
                'job_id'  => $jobId,
                'message' => $e->getMessage(),
            ]);

            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to record execution start.',
                'code'    => 500,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 7. Return the new execution_id to the caller
        // ------------------------------------------------------------------

        jsonResponse(201, [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate the request body for POST /execution/start.
     *
     * @param array<string, mixed> $body Decoded JSON request body.
     *
     * @return array<string, string> Map of field name → error message. Empty when valid.
     */
    private function validate(array $body): array
    {
        $errors = [];

        // job_id: required integer > 0
        if (!isset($body['job_id'])) {
            $errors['job_id'] = 'Field is required.';
        } elseif (!is_int($body['job_id']) || $body['job_id'] <= 0) {
            $errors['job_id'] = 'Must be a positive integer.';
        }

        // started_at: required non-empty string
        if (!isset($body['started_at'])) {
            $errors['started_at'] = 'Field is required.';
        } elseif (!is_string($body['started_at']) || trim($body['started_at']) === '') {
            $errors['started_at'] = 'Must be a non-empty string (ISO 8601 timestamp).';
        }

        return $errors;
    }

    /**
     * Check whether a cronjobs row with the given ID exists.
     *
     * @param int $jobId The job ID to look up.
     *
     * @return bool True if the job exists, false otherwise.
     *
     * @throws PDOException On database errors.
     */
    private function jobExists(int $jobId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM cronjobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $jobId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Return true when the job has the singleton flag set.
     *
     * @param int $jobId The job ID to check.
     *
     * @return bool
     *
     * @throws PDOException On database errors.
     */
    private function isSingleton(int $jobId): bool
    {
        $stmt = $this->pdo->prepare('SELECT singleton FROM cronjobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false && (bool) $row['singleton'];
    }

    /**
     * Return true when the job has run_in_maintenance = 1.
     *
     * When true the job executes during a maintenance window but failure
     * notifications are suppressed.
     *
     * @param int $jobId The job ID to check.
     *
     * @return bool
     *
     * @throws PDOException On database errors.
     */
    private function getRunInMaintenance(int $jobId): bool
    {
        $stmt = $this->pdo->prepare('SELECT run_in_maintenance FROM cronjobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false && (bool) $row['run_in_maintenance'];
    }

    /**
     * Return true when at least one execution of the given job is still running
     * (i.e. has no finished_at timestamp).
     *
     * @param int $jobId The job ID to check.
     *
     * @return bool
     *
     * @throws PDOException On database errors.
     */
    private function hasRunningExecution(int $jobId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM execution_log WHERE cronjob_id = :id AND finished_at IS NULL LIMIT 1'
        );
        $stmt->execute([':id' => $jobId]);

        return $stmt->fetchColumn() !== false;
    }
}
