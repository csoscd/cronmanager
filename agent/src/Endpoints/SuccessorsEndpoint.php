<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – SuccessorsEndpoint
 *
 * Handles:
 *   GET  /crons/{id}/successors       – full recursive tree of active successors for one job
 *   POST /crons/bulk/successors        – same for an array of job IDs (bulk pre-check)
 *
 * Used by the web UI before deactivating a job (or a bulk selection) to warn
 * the user that active dependent jobs exist. The response contains the full
 * recursive tree so the confirmation dialog can show all jobs that would be
 * affected by a cascade-deactivation.
 *
 * Response format (single):
 * ```json
 * {
 *   "has_active_successors": true,
 *   "successors": [
 *     {"id": 5, "description": "Backup cleanup",   "dependency_type": "triggered_by", "depth": 1},
 *     {"id": 9, "description": "Report generator", "dependency_type": "requires",     "depth": 2}
 *   ]
 * }
 * ```
 *
 * Response format (bulk):
 * ```json
 * {
 *   "has_active_successors": true,
 *   "jobs": [
 *     {
 *       "job_id": 3,
 *       "description": "My job",
 *       "successors": [
 *         {"id": 5, "description": "Backup cleanup", "dependency_type": "triggered_by", "depth": 1}
 *       ]
 *     }
 *   ]
 * }
 * ```
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Repository\DependencyRepository;
use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class SuccessorsEndpoint
 *
 * Returns the full recursive tree of active successors for one or more jobs.
 * Used by the web UI to warn before deactivation.
 */
final class SuccessorsEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param PDO                  $pdo
     * @param Logger               $logger
     * @param DependencyRepository $deps
     */
    public function __construct(
        private readonly PDO                  $pdo,
        private readonly Logger               $logger,
        private readonly DependencyRepository $deps,
    ) {}

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /**
     * Handle GET /crons/{id}/successors.
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     *
     * @return void
     */
    public function handleSingle(array $params): void
    {
        $rawId = $params['id'] ?? '';

        if ($rawId === '' || !ctype_digit($rawId) || (int) $rawId <= 0) {
            jsonResponse(400, ['error' => 'Bad Request', 'message' => 'Path parameter {id} must be a positive integer.', 'code' => 400]);
            return;
        }

        $jobId = (int) $rawId;

        $this->logger->debug('SuccessorsEndpoint: GET /crons/{id}/successors', ['job_id' => $jobId]);

        try {
            $successors = $this->deps->findActiveSuccessorsRecursive([$jobId]);
            $jobSuccessors = $successors[$jobId] ?? [];

            jsonResponse(200, [
                'has_active_successors' => $jobSuccessors !== [],
                'successors'            => $jobSuccessors,
            ]);
        } catch (PDOException $e) {
            $this->logger->error('SuccessorsEndpoint: database error', ['message' => $e->getMessage()]);
            jsonResponse(500, ['error' => 'Internal Server Error', 'message' => 'DB error.', 'code' => 500]);
        }
    }

    /**
     * Handle POST /crons/bulk/successors.
     *
     * Expects JSON body: {"ids": [1, 2, 3]}
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function handleBulk(array $params): void
    {
        $body = json_decode((string) file_get_contents('php://input'), true);

        if (!is_array($body) || !isset($body['ids']) || !is_array($body['ids'])) {
            jsonResponse(400, ['error' => 'Bad Request', 'message' => 'Body must contain an "ids" array.', 'code' => 400]);
            return;
        }

        $ids = array_values(array_filter(array_map('intval', $body['ids']), static fn(int $id) => $id > 0));

        if ($ids === []) {
            jsonResponse(400, ['error' => 'Bad Request', 'message' => '"ids" must contain at least one positive integer.', 'code' => 400]);
            return;
        }

        $this->logger->debug('SuccessorsEndpoint: POST /crons/bulk/successors', ['ids' => $ids]);

        try {
            $successorsByJob = $this->deps->findActiveSuccessorsRecursive($ids);

            $hasAny = false;
            $jobs   = [];

            foreach ($ids as $jobId) {
                $jobSuccessors = $successorsByJob[$jobId] ?? [];
                if ($jobSuccessors !== []) {
                    $hasAny = true;
                }

                $desc = $this->fetchDescription($jobId);

                $jobs[] = [
                    'job_id'      => $jobId,
                    'description' => $desc,
                    'successors'  => $jobSuccessors,
                ];
            }

            jsonResponse(200, [
                'has_active_successors' => $hasAny,
                'jobs'                  => $jobs,
            ]);
        } catch (PDOException $e) {
            $this->logger->error('SuccessorsEndpoint: database error', ['message' => $e->getMessage()]);
            jsonResponse(500, ['error' => 'Internal Server Error', 'message' => 'DB error.', 'code' => 500]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch the description of a single job.
     *
     * @param int $jobId
     *
     * @return string|null
     *
     * @throws PDOException
     */
    private function fetchDescription(int $jobId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT description FROM cronjobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return ($row !== false && isset($row['description'])) ? (string) $row['description'] : null;
    }
}
