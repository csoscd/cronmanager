<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – StatsEndpoint
 *
 * Handles GET /stats requests.
 *
 * Returns aggregate execution counts for the dashboard statistics widget.
 * Uses idx_el_started_at for efficient range scans – no full table scan.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "executed_today": 42,
 *   "failed_today":    3,
 *   "executed_24h":   38,
 *   "failed_24h":      2
 * }
 * ```
 *
 * "Failed" excludes exit_code 0 (success) and -4 (maintenance skip).
 * Retried executions count individually as they each appear in execution_log.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Monolog\Logger;
use PDO;

/**
 * Class StatsEndpoint
 *
 * Provides aggregate execution statistics for the dashboard widget.
 * Two queries, both resolved via idx_el_started_at (index range scan).
 */
final class StatsEndpoint
{
    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $logger,
    ) {}

    /**
     * Handle GET /stats.
     *
     * @param array<string,string> $params Unused path parameters.
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $this->logger->debug('StatsEndpoint: handling GET /stats');

        try {
            // Today: started_at >= CURDATE() uses idx_el_started_at (range scan)
            $stmtToday = $this->pdo->query(
                "SELECT
                     COUNT(*)                                                        AS total,
                     COALESCE(SUM(CASE WHEN exit_code NOT IN (0, -4) AND acknowledged_at IS NULL THEN 1 END), 0) AS failed
                 FROM execution_log
                 WHERE started_at >= CURDATE()"
            );
            /** @var array{total:string,failed:string}|false $today */
            $today = $stmtToday !== false ? $stmtToday->fetch(PDO::FETCH_ASSOC) : false;

            // Last 24 h: different cut-off, same index
            $stmt24h = $this->pdo->query(
                "SELECT
                     COUNT(*)                                                        AS total,
                     COALESCE(SUM(CASE WHEN exit_code NOT IN (0, -4) AND acknowledged_at IS NULL THEN 1 END), 0) AS failed
                 FROM execution_log
                 WHERE started_at >= NOW() - INTERVAL 24 HOUR"
            );
            /** @var array{total:string,failed:string}|false $last24h */
            $last24h = $stmt24h !== false ? $stmt24h->fetch(PDO::FETCH_ASSOC) : false;
        } catch (\Throwable $e) {
            $this->logger->error('StatsEndpoint: query failed', ['message' => $e->getMessage()]);
            jsonResponse(500, ['error' => 'Internal Server Error', 'message' => 'Stats query failed.', 'code' => 500]);
            return;
        }

        jsonResponse(200, [
            'executed_today' => $today  !== false ? (int) $today['total']   : 0,
            'failed_today'   => $today  !== false ? (int) $today['failed']  : 0,
            'executed_24h'   => $last24h !== false ? (int) $last24h['total']  : 0,
            'failed_24h'     => $last24h !== false ? (int) $last24h['failed'] : 0,
        ]);
    }
}
