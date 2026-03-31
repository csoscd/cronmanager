<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – ExecutionUpdatePidEndpoint
 *
 * Handles POST /execution/{id}/pid requests.
 *
 * Called by cron-wrapper.sh immediately after the managed job process starts
 * to record the OS PID (local jobs) or remote PID-file path (SSH jobs).
 * This information is used by the kill endpoint to terminate a running job.
 *
 * Request body (JSON) – at least one field is required:
 * ```json
 * { "pid": 12345 }                              // local job: OS process ID
 * { "pid_file": "/tmp/.cmgr_123" }              // SSH job: remote PID file path
 * { "pid": 12345, "pid_file": "/tmp/.cmgr_123" }// both fields are accepted
 * ```
 *
 * Response on success (HTTP 200):
 * ```json
 * { "execution_id": 123, "pid": 12345, "pid_file": null }
 * ```
 *
 * This class relies on the global `jsonResponse()` function being available
 * in the calling scope (defined in agent.php).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class ExecutionUpdatePidEndpoint
 *
 * Records the process ID and / or PID file path of a running cron job so that
 * the job can be killed on demand via the kill endpoint.
 */
final class ExecutionUpdatePidEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * ExecutionUpdatePidEndpoint constructor.
     *
     * @param PDO    $pdo    Active PDO database connection.
     * @param Logger $logger Monolog logger instance.
     */
    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming POST /execution/{id}/pid request.
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $rawId = $params['id'] ?? '';

        if ($rawId === '' || !ctype_digit($rawId) || (int) $rawId <= 0) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Path parameter {id} must be a positive integer.',
                'code'    => 400,
            ]);
            return;
        }

        $executionId = (int) $rawId;

        $this->logger->debug('ExecutionUpdatePidEndpoint: handling POST /execution/{id}/pid', [
            'execution_id' => $executionId,
        ]);

        // ------------------------------------------------------------------
        // 1. Parse JSON body
        // ------------------------------------------------------------------

        $rawBody = (string) file_get_contents('php://input');
        $body    = json_decode($rawBody, true);

        if (!is_array($body)) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Request body must be valid JSON.',
                'code'    => 400,
            ]);
            return;
        }

        // Extract optional fields
        $pid     = isset($body['pid'])      && is_int($body['pid'])    && $body['pid'] > 0
            ? $body['pid']
            : null;
        $pidFile = isset($body['pid_file']) && is_string($body['pid_file']) && $body['pid_file'] !== ''
            ? $body['pid_file']
            : null;

        if ($pid === null && $pidFile === null) {
            jsonResponse(422, [
                'error'   => 'Validation failed',
                'message' => 'At least one of "pid" (integer > 0) or "pid_file" (non-empty string) must be provided.',
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 2. Update the execution_log row
        // ------------------------------------------------------------------

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE execution_log
                    SET pid      = COALESCE(:pid,      pid),
                        pid_file = COALESCE(:pid_file, pid_file)
                  WHERE id = :id
                    AND finished_at IS NULL'
            );
            $stmt->execute([
                ':pid'      => $pid,
                ':pid_file' => $pidFile,
                ':id'       => $executionId,
            ]);

            if ($stmt->rowCount() === 0) {
                $this->logger->warning('ExecutionUpdatePidEndpoint: execution not found or already finished', [
                    'execution_id' => $executionId,
                ]);
                jsonResponse(404, [
                    'error'   => 'Not Found',
                    'message' => sprintf(
                        'Running execution with ID %d does not exist.',
                        $executionId
                    ),
                    'code'    => 404,
                ]);
                return;
            }

            $this->logger->info('ExecutionUpdatePidEndpoint: PID recorded', [
                'execution_id' => $executionId,
                'pid'          => $pid,
                'pid_file'     => $pidFile,
            ]);

        } catch (PDOException $e) {
            $this->logger->error('ExecutionUpdatePidEndpoint: database error', [
                'execution_id' => $executionId,
                'message'      => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to update execution PID.',
                'code'    => 500,
            ]);
            return;
        }

        jsonResponse(200, [
            'execution_id' => $executionId,
            'pid'          => $pid,
            'pid_file'     => $pidFile,
        ]);
    }
}
