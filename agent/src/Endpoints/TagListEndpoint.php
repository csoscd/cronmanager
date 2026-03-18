<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – TagListEndpoint
 *
 * Handles GET /tags requests.
 *
 * Returns all known tags together with a count of how many cronjobs are
 * currently associated with each tag. The result is ordered alphabetically
 * by tag name so that consumers can render it without further sorting.
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
 * Class TagListEndpoint
 *
 * Handles GET /tags API requests.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "data": [
 *     {"id": 1, "name": "backup",      "job_count": 3},
 *     {"id": 2, "name": "maintenance", "job_count": 1}
 *   ],
 *   "count": 2
 * }
 * ```
 */
final class TagListEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * TagListEndpoint constructor.
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
     * Handle an incoming GET /tags request.
     *
     * Fetches all tags with their associated job counts and emits a JSON
     * response via the global jsonResponse().
     *
     * @param array<string, string> $params Path parameters extracted by the Router
     *                                      (unused for this endpoint).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $this->logger->debug('TagListEndpoint: handling GET /tags');

        try {
            $tags = $this->fetchTags();

            jsonResponse(200, [
                'data'  => $tags,
                'count' => count($tags),
            ]);
        } catch (PDOException $e) {
            $this->logger->error('TagListEndpoint: database error', [
                'message' => $e->getMessage(),
            ]);

            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to retrieve tags.',
                'code'    => 500,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Query all tags with the count of associated cronjobs.
     *
     * The LEFT JOIN ensures tags with zero associated jobs are included in the
     * result. Results are sorted alphabetically by tag name.
     *
     * @return array<int, array<string, mixed>> Normalised tag records.
     *
     * @throws PDOException On database errors.
     */
    private function fetchTags(): array
    {
        $sql = <<<SQL
            SELECT
                t.id,
                t.name,
                COUNT(ct.cronjob_id) AS job_count
            FROM tags t
            LEFT JOIN cronjob_tags ct ON ct.tag_id = t.id
            GROUP BY t.id, t.name
            ORDER BY t.name
            SQL;

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll();
        $tags = [];

        foreach ($rows as $row) {
            $tags[] = [
                'id'        => (int)    $row['id'],
                'name'      => (string) $row['name'],
                'job_count' => (int)    $row['job_count'],
            ];
        }

        return $tags;
    }
}
