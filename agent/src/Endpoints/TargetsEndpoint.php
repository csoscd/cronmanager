<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – TargetsEndpoint
 *
 * Handles GET /targets requests.
 *
 * Returns all distinct execution targets (e.g. "local", SSH host aliases)
 * that are configured across cronjobs, together with the number of jobs
 * using each target.  Supports an optional `?active=1` query parameter
 * to restrict the result to targets of active jobs only.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "data": [
 *     {"target": "local",    "job_count": 5},
 *     {"target": "myserver", "job_count": 3}
 *   ],
 *   "count": 2
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

use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class TargetsEndpoint
 *
 * Handles GET /targets API requests.
 *
 * Optional query parameters:
 *   - active (int, 0 or 1): When set, restricts results to targets of
 *     jobs with the given active state.
 */
final class TargetsEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * TargetsEndpoint constructor.
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
     * Handle an incoming GET /targets request.
     *
     * @param array<string, string> $params Path parameters extracted by the Router
     *                                      (unused for this endpoint).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $activeFilter = null;

        if (isset($_GET['active']) && $_GET['active'] !== '') {
            $activeFilter = (int) $_GET['active'] !== 0 ? 1 : 0;
        }

        $this->logger->debug('TargetsEndpoint: handling GET /targets', [
            'active' => $activeFilter,
        ]);

        try {
            $targets = $this->fetchTargets($activeFilter);

            jsonResponse(200, [
                'data'  => $targets,
                'count' => count($targets),
            ]);
        } catch (PDOException $e) {
            $this->logger->error('TargetsEndpoint: database error', [
                'message' => $e->getMessage(),
            ]);

            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to retrieve targets.',
                'code'    => 500,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Query all distinct execution targets with the count of associated jobs.
     *
     * Reads from the job_targets table, which is the canonical store for
     * per-job execution targets (populated by migration 002).  Legacy jobs
     * that were not migrated will not appear here; as migration 002 runs
     * unconditionally, this is expected to be an empty set in practice.
     *
     * @param int|null $activeFilter 1 = active jobs only, 0 = inactive only, null = all.
     *
     * @return array<int, array<string, mixed>> Normalised target records.
     *
     * @throws PDOException On database errors.
     */
    private function fetchTargets(?int $activeFilter): array
    {
        $sql = <<<SQL
            SELECT
                jt.target,
                COUNT(DISTINCT jt.job_id) AS job_count
            FROM job_targets jt
            INNER JOIN cronjobs j ON j.id = jt.job_id
            SQL;

        if ($activeFilter !== null) {
            $sql .= ' WHERE j.active = :active';
        }

        $sql .= ' GROUP BY jt.target ORDER BY jt.target';

        $stmt = $this->pdo->prepare($sql);

        if ($activeFilter !== null) {
            $stmt->bindValue(':active', $activeFilter, PDO::PARAM_INT);
        }

        $stmt->execute();
        $rows    = $stmt->fetchAll();
        $targets = [];

        foreach ($rows as $row) {
            $targets[] = [
                'target'    => (string) $row['target'],
                'job_count' => (int)    $row['job_count'],
            ];
        }

        return $targets;
    }
}
