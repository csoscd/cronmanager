<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MonitorEndpoint
 *
 * Handles GET /crons/{id}/monitor requests.
 *
 * Returns statistics and chart data for a single cron job over a configurable
 * time period:
 *   - Aggregated KPIs (success rate, avg/min/max duration, execution count, alerts)
 *   - Duration time-series data points for a line chart
 *   - Time-bucketed success/failure counts for a stacked bar chart
 *   - Most recent execution records for a details table
 *
 * Supported query parameters:
 *   - period  (string)  One of: 1h, 6h, 12h, 24h, 7d, 30d (default), 3m, 6m, 1y
 *
 * Alert count is derived: a failed execution (exit_code != 0) on a job with
 * notify_on_failure = 1 is counted as an alert, since the execution_log table
 * has no dedicated alert_sent column.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class MonitorEndpoint
 *
 * Handles GET /crons/{id}/monitor API requests.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "job": { "id": 42, "linux_user": "deploy", "schedule": "* /5 * * * *", ... },
 *   "stats": {
 *     "execution_count": 150, "success_count": 142, "failure_count": 8,
 *     "success_rate": 94.7, "avg_duration": 12.4, "min_duration": 8,
 *     "max_duration": 45, "alert_count": 8
 *   },
 *   "duration_series": [
 *     { "started_at": "2026-03-20 10:00:01", "duration_seconds": 12, "success": true }
 *   ],
 *   "bar_buckets": [
 *     { "label": "Mar 19", "success": 10, "failed": 1 }
 *   ],
 *   "recent": [ ... ],
 *   "period": "30d",
 *   "from": "2026-02-19T10:00:00+00:00",
 *   "to":   "2026-03-20T10:00:00+00:00"
 * }
 * ```
 */
final class MonitorEndpoint
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** Allowed values for the ?period query parameter. */
    private const VALID_PERIODS = ['1h', '6h', '12h', '24h', '7d', '30d', '3m', '6m', '1y'];

    /** Default period when none or an invalid value is supplied. */
    private const DEFAULT_PERIOD = '30d';

    /**
     * Maximum execution rows fetched for chart data.
     * A high value covers even very frequently running jobs over 1 year.
     */
    private const MAX_EXECUTIONS = 500;

    /** Number of most-recent executions returned in the 'recent' array. */
    private const RECENT_LIMIT = 20;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * MonitorEndpoint constructor.
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
     * Handle an incoming GET /crons/{id}/monitor request.
     *
     * @param array<string, string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function handle(array $params): void
    {
        // ------------------------------------------------------------------
        // 1. Validate path parameter
        // ------------------------------------------------------------------

        $idRaw = $params['id'] ?? '';
        if (!ctype_digit((string) $idRaw) || (int) $idRaw <= 0) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Invalid job ID.',
                'code'    => 400,
            ]);
            return;
        }
        $id = (int) $idRaw;

        // ------------------------------------------------------------------
        // 2. Parse and validate ?period query parameter
        // ------------------------------------------------------------------

        $periodParam = isset($_GET['period']) && $_GET['period'] !== ''
            ? (string) $_GET['period']
            : self::DEFAULT_PERIOD;
        $period = in_array($periodParam, self::VALID_PERIODS, true)
            ? $periodParam
            : self::DEFAULT_PERIOD;

        $this->logger->debug('MonitorEndpoint: handling GET /crons/{id}/monitor', [
            'id'     => $id,
            'period' => $period,
        ]);

        // ------------------------------------------------------------------
        // 3. Fetch job and compute statistics
        // ------------------------------------------------------------------

        try {
            $job = $this->fetchJob($id);
            if ($job === null) {
                jsonResponse(404, [
                    'error'   => 'Not Found',
                    'message' => 'Job not found.',
                    'code'    => 404,
                ]);
                return;
            }

            [$from, $to] = $this->resolvePeriod($period);

            $fromStr = $from->format('Y-m-d H:i:s');
            $toStr   = $to->format('Y-m-d H:i:s');

            $stats      = $this->fetchStats($id, $fromStr, $toStr, (bool) $job['notify_on_failure']);
            $executions = $this->fetchExecutions($id, $fromStr, $toStr);

            jsonResponse(200, [
                'job'             => $job,
                'stats'           => $stats,
                'duration_series' => $this->buildDurationSeries($executions),
                'bar_buckets'     => $this->buildBarBuckets($from, $to, $period, $executions),
                'recent'          => array_slice($executions, 0, self::RECENT_LIMIT),
                'period'          => $period,
                'from'            => $from->format('c'),
                'to'              => $to->format('c'),
            ]);

        } catch (PDOException $e) {
            $this->logger->error('MonitorEndpoint: database error', [
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to retrieve monitor data.',
                'code'    => 500,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers – queries
    // -------------------------------------------------------------------------

    /**
     * Fetch the cron job record by ID.
     *
     * @param int $id Job ID.
     *
     * @return array<string, mixed>|null Job record or null if not found.
     *
     * @throws PDOException On database errors.
     */
    private function fetchJob(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, linux_user, schedule, command, description, active, notify_on_failure
               FROM cronjobs WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id'                => (int)    $row['id'],
            'linux_user'        => (string) $row['linux_user'],
            'schedule'          => (string) $row['schedule'],
            'command'           => (string) $row['command'],
            'description'       => isset($row['description']) ? (string) $row['description'] : null,
            'active'            => (bool)   $row['active'],
            'notify_on_failure' => (bool)   $row['notify_on_failure'],
        ];
    }

    /**
     * Fetch aggregated statistics for the specified job and time window.
     *
     * Alert count is approximated as the number of failed executions when
     * notify_on_failure is enabled on the job.
     *
     * @param int    $id              Job ID.
     * @param string $from            Start of window (YYYY-MM-DD HH:MM:SS).
     * @param string $to              End of window (YYYY-MM-DD HH:MM:SS).
     * @param bool   $notifyOnFailure Whether the job has failure notifications enabled.
     *
     * @return array<string, mixed> Aggregated statistics.
     *
     * @throws PDOException On database errors.
     */
    private function fetchStats(int $id, string $from, string $to, bool $notifyOnFailure): array
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT
                COUNT(*)                                                                AS execution_count,
                SUM(CASE WHEN el.exit_code = 0  THEN 1 ELSE 0 END)                     AS success_count,
                SUM(CASE WHEN el.exit_code != 0 THEN 1 ELSE 0 END)                     AS failure_count,
                ROUND(AVG(TIMESTAMPDIFF(SECOND, el.started_at, el.finished_at)), 1)     AS avg_duration,
                MIN(TIMESTAMPDIFF(SECOND, el.started_at, el.finished_at))               AS min_duration,
                MAX(TIMESTAMPDIFF(SECOND, el.started_at, el.finished_at))               AS max_duration
            FROM execution_log el
            WHERE el.cronjob_id  = :id
              AND el.finished_at IS NOT NULL
              AND el.started_at  >= :from
              AND el.started_at  <= :to
            SQL
        );
        $stmt->execute([':id' => $id, ':from' => $from, ':to' => $to]);
        $row = $stmt->fetch();

        $executionCount = (int) ($row['execution_count'] ?? 0);
        $successCount   = (int) ($row['success_count']   ?? 0);
        $failureCount   = (int) ($row['failure_count']   ?? 0);

        return [
            'execution_count' => $executionCount,
            'success_count'   => $successCount,
            'failure_count'   => $failureCount,
            'success_rate'    => $executionCount > 0
                ? round($successCount / $executionCount * 100, 1)
                : null,
            'avg_duration'    => $row['avg_duration']  !== null ? (float) $row['avg_duration']  : null,
            'min_duration'    => $row['min_duration']  !== null ? (int)   $row['min_duration']  : null,
            'max_duration'    => $row['max_duration']  !== null ? (int)   $row['max_duration']  : null,
            // Alert count = failures when notify_on_failure is active
            'alert_count'     => $notifyOnFailure ? $failureCount : 0,
        ];
    }

    /**
     * Fetch all finished execution records in the time window, newest first.
     *
     * Capped at MAX_EXECUTIONS rows to keep response size reasonable even for
     * very busy jobs over long periods.
     *
     * @param int    $id   Job ID.
     * @param string $from Start of window (YYYY-MM-DD HH:MM:SS).
     * @param string $to   End of window (YYYY-MM-DD HH:MM:SS).
     *
     * @return array<int, array<string, mixed>> Execution records, newest first.
     *
     * @throws PDOException On database errors.
     */
    private function fetchExecutions(int $id, string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT
                el.id          AS execution_id,
                el.started_at,
                el.finished_at,
                el.exit_code,
                el.target,
                TIMESTAMPDIFF(SECOND, el.started_at, el.finished_at) AS duration_seconds
            FROM execution_log el
            WHERE el.cronjob_id  = :id
              AND el.finished_at IS NOT NULL
              AND el.started_at  >= :from
              AND el.started_at  <= :to
            ORDER BY el.started_at DESC
            LIMIT :lim
            SQL
        );
        $stmt->bindValue(':id',   $id,   PDO::PARAM_INT);
        $stmt->bindValue(':from', $from);
        $stmt->bindValue(':to',   $to);
        $stmt->bindValue(':lim',  self::MAX_EXECUTIONS, PDO::PARAM_INT);
        $stmt->execute();

        $rows   = $stmt->fetchAll();
        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'execution_id'     => (int)    $row['execution_id'],
                'started_at'       => (string) $row['started_at'],
                'finished_at'      => (string) $row['finished_at'],
                'exit_code'        => $row['exit_code']        !== null ? (int)    $row['exit_code']        : null,
                'target'           => $row['target']           !== null ? (string) $row['target']           : null,
                'duration_seconds' => $row['duration_seconds'] !== null ? (int)    $row['duration_seconds'] : null,
            ];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Private helpers – chart data builders
    // -------------------------------------------------------------------------

    /**
     * Build the duration time-series for the line chart.
     *
     * Executions are returned in chronological order (oldest first) so the
     * chart renders left-to-right in time order.
     *
     * @param array<int, array<string, mixed>> $executions Executions, newest first.
     *
     * @return array<int, array<string, mixed>> Duration data points, oldest first.
     */
    private function buildDurationSeries(array $executions): array
    {
        $series = [];

        // executions are DESC; reverse for chronological chart order
        foreach (array_reverse($executions) as $exec) {
            if ($exec['duration_seconds'] === null) {
                continue;
            }
            $series[] = [
                'started_at'       => $exec['started_at'],
                'duration_seconds' => $exec['duration_seconds'],
                'success'          => $exec['exit_code'] === 0,
            ];
        }

        return $series;
    }

    /**
     * Aggregate executions into time-bucket counts for the stacked bar chart.
     *
     * The bucket interval adapts to the selected period so that approximately
     * 12–30 bars are shown regardless of the time span.
     *
     * @param \DateTimeImmutable               $from       Start of window.
     * @param \DateTimeImmutable               $to         End of window.
     * @param string                           $period     Selected period string.
     * @param array<int, array<string, mixed>> $executions Execution records.
     *
     * @return array<int, array<string, mixed>> Bar chart buckets with label, success, failed.
     */
    private function buildBarBuckets(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $period,
        array $executions,
    ): array {
        $intervalSeconds = match ($period) {
            '1h'    => 5  * 60,          //  5 min  → 12 bars
            '6h'    => 30 * 60,          // 30 min  → 12 bars
            '12h'   => 3600,             //  1 h    → 12 bars
            '24h'   => 2  * 3600,        //  2 h    → 12 bars
            '7d'    => 12 * 3600,        // 12 h    → 14 bars
            '30d'   => 86400,            //  1 day  → 30 bars
            '3m'    => 7  * 86400,       //  1 week → ~13 bars
            '6m'    => 7  * 86400,       //  1 week → ~26 bars
            '1y'    => 30 * 86400,       // ~1 month → 12 bars
            default => 86400,
        };

        $start = $from->getTimestamp();
        $end   = $to->getTimestamp();

        // Initialise empty buckets covering the entire period
        $buckets = [];
        for ($t = $start; $t < $end; $t += $intervalSeconds) {
            $label = match (true) {
                $intervalSeconds < 3600   => date('H:i', $t),
                $intervalSeconds <= 86400 => date('M d H:i', $t),
                default                   => date('M d', $t),
            };
            $buckets[$t] = ['label' => $label, 'success' => 0, 'failed' => 0];
        }

        // Fill each execution into the correct bucket
        foreach ($executions as $exec) {
            if ($exec['exit_code'] === null) {
                continue;
            }
            $ts     = strtotime($exec['started_at']);
            $bucket = $start + (int) floor(($ts - $start) / $intervalSeconds) * $intervalSeconds;
            if (isset($buckets[$bucket])) {
                if ($exec['exit_code'] === 0) {
                    $buckets[$bucket]['success']++;
                } else {
                    $buckets[$bucket]['failed']++;
                }
            }
        }

        return array_values($buckets);
    }

    /**
     * Compute the from/to DateTimeImmutable pair for the given period string.
     *
     * @param string $period One of VALID_PERIODS.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [from, to] pair.
     */
    private function resolvePeriod(string $period): array
    {
        $now  = new \DateTimeImmutable();
        $from = match ($period) {
            '1h'    => $now->modify('-1 hour'),
            '6h'    => $now->modify('-6 hours'),
            '12h'   => $now->modify('-12 hours'),
            '24h'   => $now->modify('-24 hours'),
            '7d'    => $now->modify('-7 days'),
            '3m'    => $now->modify('-3 months'),
            '6m'    => $now->modify('-6 months'),
            '1y'    => $now->modify('-1 year'),
            default => $now->modify('-30 days'),   // '30d'
        };

        return [$from, $now];
    }
}
