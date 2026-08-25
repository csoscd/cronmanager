<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – ExecutionProgressEndpoint
 *
 * Handles POST /execution/{id}/progress requests.
 *
 * Called periodically by cron-wrapper.sh while a job is still running to
 * stream partial output into the execution_log row.  This lets the web UI
 * show live progress before the job completes.
 *
 * Only updates rows where finished_at IS NULL to prevent a late-arriving
 * progress packet from overwriting the final output after the job finishes.
 *
 * Request body (JSON):
 * ```json
 * { "output": "partial stdout / stderr so far" }
 * ```
 *
 * Response on success (HTTP 200):
 * ```json
 * { "execution_id": 123, "updated": true }
 * ```
 *
 * HTTP 404 is returned when no running execution row matches the given ID.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class ExecutionProgressEndpoint
 *
 * Writes partial job output to the execution_log row while the job is running.
 */
final class ExecutionProgressEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * ExecutionProgressEndpoint constructor.
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
     * Handle an incoming POST /execution/{id}/progress request.
     *
     * @param array<string, string> $params Path parameters: 'id' = execution ID.
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $executionId = isset($params['id']) ? (int) $params['id'] : 0;

        if ($executionId <= 0) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Execution ID must be a positive integer.',
                'code'    => 400,
            ]);
            return;
        }

        $rawBody = (string) file_get_contents('php://input');
        $body    = json_decode($rawBody, true);

        if (!is_array($body) || !isset($body['output']) || !is_string($body['output'])) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Request body must be JSON with a string "output" field.',
                'code'    => 400,
            ]);
            return;
        }

        $output = $body['output'];

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE execution_log
                    SET output = :output
                  WHERE id = :id
                    AND finished_at IS NULL'
            );
            $stmt->execute([
                ':output' => $output,
                ':id'     => $executionId,
            ]);

            $updated = $stmt->rowCount() > 0;

            if (!$updated) {
                $this->logger->debug('ExecutionProgressEndpoint: no running row found', [
                    'execution_id' => $executionId,
                ]);
                jsonResponse(404, [
                    'error'        => 'Not Found',
                    'message'      => 'No running execution found with this ID.',
                    'execution_id' => $executionId,
                    'code'         => 404,
                ]);
                return;
            }

            $this->logger->debug('ExecutionProgressEndpoint: partial output written', [
                'execution_id' => $executionId,
                'output_bytes' => strlen($output),
            ]);

        } catch (PDOException $e) {
            $this->logger->error('ExecutionProgressEndpoint: database error', [
                'execution_id' => $executionId,
                'message'      => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to write partial output.',
                'code'    => 500,
            ]);
            return;
        }

        jsonResponse(200, [
            'execution_id' => $executionId,
            'updated'      => true,
        ]);
    }
}
