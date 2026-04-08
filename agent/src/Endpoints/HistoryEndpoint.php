<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – HistoryEndpoint
 *
 * Handles GET /history requests.
 *
 * Returns a paginated list of cron job execution records from the
 * `execution_log` table. All query parameters are optional and can be combined
 * freely to narrow the result set.
 *
 * Supported query parameters:
 *   - job_id  (int)            Filter by a specific cron job ID.
 *   - tag     (string)         Filter by tag name – only jobs carrying this tag.
 *   - user    (string)         Filter by linux_user of the owning job.
 *   - status  (string)         One of: "failed", "success", "running".
 *   - search  (string)         Full-text LIKE filter on job description and command.
 *   - limit   (int, 1–500)     Max number of rows to return (default 50).
 *   - offset  (int, ≥ 0)       Pagination offset (default 0).
 *   - from    (YYYY-MM-DD)     Only executions started on or after this date.
 *   - to      (YYYY-MM-DD)     Only executions started on or before this date.
 *   - target  (string)         Filter by execution target (e.g. "local" or SSH host alias).
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
 * Class HistoryEndpoint
 *
 * Handles GET /history API requests.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "data": [
 *     {
 *       "execution_id": 123,
 *       "job_id": 42,
 *       "linux_user": "deploy",
 *       "description": "Backup database",
 *       "schedule": "* /5 * * * *",
 *       "tags": ["backup", "daily"],
 *       "started_at": "2026-03-15T10:00:00",
 *       "finished_at": "2026-03-15T10:00:05",
 *       "exit_code": 0,
 *       "output": "",
 *       "duration_seconds": 5
 *     }
 *   ],
 *   "count": 1,
 *   "total": 1,
 *   "limit": 50,
 *   "offset": 0
 * }
 * ```
 */
final class HistoryEndpoint
{
    /** Default number of rows returned when ?limit is not specified. */
    private const DEFAULT_LIMIT = 50;

    /** Hard upper bound on the ?limit parameter. */
    private const MAX_LIMIT = 500;

    /** Allowed values for the ?status query parameter. */
    private const VALID_STATUSES = ['failed', 'success', 'running'];

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * HistoryEndpoint constructor.
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
     * Handle an incoming GET /history request.
     *
     * Parses and validates all supported query parameters, runs the count and
     * data queries, and emits a paginated JSON response.
     *
     * @param array<string, string> $params Path parameters extracted by the Router
     *                                      (unused for this endpoint).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $this->logger->debug('HistoryEndpoint: handling GET /history', ['query' => $_GET]);

        // ------------------------------------------------------------------
        // 1. Parse and validate query parameters
        // ------------------------------------------------------------------

        $jobId  = $this->parsePositiveInt($_GET['job_id'] ?? null);
        $tag    = isset($_GET['tag'])    && $_GET['tag']    !== '' ? (string) $_GET['tag']    : null;
        $user   = isset($_GET['user'])   && $_GET['user']   !== '' ? (string) $_GET['user']   : null;
        $target = isset($_GET['target']) && $_GET['target'] !== '' ? (string) $_GET['target'] : null;
        $status = isset($_GET['status']) && $_GET['status'] !== '' ? (string) $_GET['status'] : null;
        $search = isset($_GET['search']) && $_GET['search'] !== '' ? (string) $_GET['search'] : null;
        $limit  = $this->parseLimit($_GET['limit']   ?? null);
        $offset = $this->parseOffset($_GET['offset'] ?? null);
        $from   = $this->parseDate($_GET['from'] ?? null);
        $to     = $this->parseDate($_GET['to']   ?? null);

        // Validate status value
        if ($status !== null && !in_array($status, self::VALID_STATUSES, true)) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => sprintf(
                    'Parameter "status" must be one of: %s.',
                    implode(', ', self::VALID_STATUSES)
                ),
                'code'    => 400,
            ]);
            return;
        }

        // Validate date formats when provided
        if (isset($_GET['from']) && $_GET['from'] !== '' && $from === null) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Parameter "from" must be a date in YYYY-MM-DD format.',
                'code'    => 400,
            ]);
            return;
        }

        if (isset($_GET['to']) && $_GET['to'] !== '' && $to === null) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Parameter "to" must be a date in YYYY-MM-DD format.',
                'code'    => 400,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 2. Build dynamic WHERE clause
        // ------------------------------------------------------------------

        $conditions = [];
        $queryParams = [];

        if ($jobId !== null) {
            $conditions[]             = 'el.cronjob_id = :job_id';
            $queryParams[':job_id']   = $jobId;
        }

        if ($user !== null) {
            $conditions[]           = 'j.linux_user = :user';
            $queryParams[':user']   = $user;
        }

        if ($target !== null) {
            $conditions[]             = 'el.target = :target';
            $queryParams[':target']   = $target;
        }

        if ($tag !== null) {
            // Require at least one tag row matching the requested tag name.
            // We use a sub-select here so the outer GROUP BY is not affected.
            $conditions[] = <<<SQL
                el.cronjob_id IN (
                    SELECT ct2.cronjob_id
                    FROM cronjob_tags ct2
                    JOIN tags t2 ON t2.id = ct2.tag_id
                    WHERE t2.name = :tag
                )
                SQL;
            $queryParams[':tag'] = $tag;
        }

        if ($status === 'running') {
            $conditions[] = 'el.finished_at IS NULL';
        } elseif ($status === 'success') {
            $conditions[] = 'el.finished_at IS NOT NULL AND el.exit_code = 0';
        } elseif ($status === 'failed') {
            $conditions[] = 'el.finished_at IS NOT NULL AND el.exit_code != 0';
        }

        if ($search !== null) {
            // Match against both description and command (case-insensitive LIKE)
            $conditions[]              = '(j.description LIKE :search OR j.command LIKE :search)';
            $queryParams[':search']    = '%' . $search . '%';
        }

        if ($from !== null) {
            $conditions[]           = 'el.started_at >= :from';
            $queryParams[':from']   = $from . ' 00:00:00';
        }

        if ($to !== null) {
            $conditions[]       = 'el.started_at <= :to';
            $queryParams[':to'] = $to . ' 23:59:59';
        }

        $whereClause = $conditions !== []
            ? 'WHERE ' . implode(' AND ', $conditions)
            : '';

        // ------------------------------------------------------------------
        // 3. Execute queries
        // ------------------------------------------------------------------

        try {
            $total = $this->fetchTotal($whereClause, $queryParams);
            $data  = $this->fetchData($whereClause, $queryParams, $limit, $offset);
        } catch (PDOException $e) {
            $this->logger->error('HistoryEndpoint: database error', [
                'message' => $e->getMessage(),
            ]);

            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to retrieve execution history.',
                'code'    => 500,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 4. Emit response
        // ------------------------------------------------------------------

        jsonResponse(200, [
            'data'   => $data,
            'count'  => count($data),
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers – queries
    // -------------------------------------------------------------------------

    /**
     * Execute the COUNT query to determine the total number of matching rows,
     * ignoring pagination (LIMIT/OFFSET).
     *
     * @param string               $whereClause Dynamic WHERE clause (may be empty).
     * @param array<string, mixed> $params      Named parameter bindings.
     *
     * @return int Total number of distinct execution log rows matching the filter.
     *
     * @throws PDOException On database errors.
     */
    private function fetchTotal(string $whereClause, array $params): int
    {
        $sql = <<<SQL
            SELECT COUNT(DISTINCT el.id) AS total
            FROM execution_log el
            JOIN cronjobs j ON j.id = el.cronjob_id
            LEFT JOIN cronjob_tags ct ON ct.cronjob_id = j.id
            LEFT JOIN tags t ON t.id = ct.tag_id
            {$whereClause}
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row !== false ? (int) $row['total'] : 0;
    }

    /**
     * Execute the paginated data query and return normalised execution records.
     *
     * @param string               $whereClause Dynamic WHERE clause (may be empty).
     * @param array<string, mixed> $params      Named parameter bindings (without limit/offset).
     * @param int                  $limit       Maximum number of rows to return.
     * @param int                  $offset      Number of rows to skip.
     *
     * @return array<int, array<string, mixed>> Normalised execution records.
     *
     * @throws PDOException On database errors.
     */
    private function fetchData(string $whereClause, array $params, int $limit, int $offset): array
    {
        $sql = <<<SQL
            SELECT
                el.id AS execution_id,
                j.id AS job_id,
                j.linux_user,
                j.description,
                j.schedule,
                GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ',') AS tags,
                el.started_at,
                el.finished_at,
                el.exit_code,
                el.output,
                el.target,
                el.during_maintenance,
                TIMESTAMPDIFF(SECOND, el.started_at, el.finished_at) AS duration_seconds
            FROM execution_log el
            JOIN cronjobs j ON j.id = el.cronjob_id
            LEFT JOIN cronjob_tags ct ON ct.cronjob_id = j.id
            LEFT JOIN tags t ON t.id = ct.tag_id
            {$whereClause}
            GROUP BY el.id
            ORDER BY (el.finished_at IS NULL) DESC, el.started_at DESC
            LIMIT :limit OFFSET :offset
            SQL;

        // PDO requires LIMIT/OFFSET bound as integers (not strings)
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $rows    = $stmt->fetchAll();
        $records = [];

        foreach ($rows as $row) {
            $records[] = $this->normaliseRow($row);
        }

        return $records;
    }

    /**
     * Normalise a raw database row into the API response format.
     *
     * Converts typed columns and splits the comma-separated tags string into
     * a PHP array.
     *
     * @param array<string, mixed> $row Raw database row.
     *
     * @return array<string, mixed> Normalised execution record.
     */
    private function normaliseRow(array $row): array
    {
        $tagsRaw = isset($row['tags']) && $row['tags'] !== null ? (string) $row['tags'] : '';
        $tags    = $tagsRaw !== '' ? explode(',', $tagsRaw) : [];

        return [
            'execution_id'     => (int)    $row['execution_id'],
            'job_id'           => (int)    $row['job_id'],
            'linux_user'       => (string) $row['linux_user'],
            'description'      => isset($row['description']) ? (string) $row['description'] : null,
            'schedule'         => (string) $row['schedule'],
            'tags'             => $tags,
            'started_at'       => (string) $row['started_at'],
            'finished_at'      => isset($row['finished_at']) ? (string) $row['finished_at'] : null,
            'exit_code'        => isset($row['exit_code']) ? (int) $row['exit_code'] : null,
            'output'           => isset($row['output']) ? (string) $row['output'] : null,
            'target'             => isset($row['target']) ? (string) $row['target'] : null,
            'during_maintenance' => !empty($row['during_maintenance']),
            'duration_seconds'   => isset($row['duration_seconds']) ? (int) $row['duration_seconds'] : null,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers – parameter parsing
    // -------------------------------------------------------------------------

    /**
     * Parse and validate a query parameter expected to be a positive integer.
     *
     * @param string|null $value Raw query parameter value.
     *
     * @return int|null Parsed integer or null if not provided.
     */
    private function parsePositiveInt(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!ctype_digit($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Parse the ?limit parameter and clamp it to the allowed range [1, MAX_LIMIT].
     *
     * @param string|null $value Raw query parameter value.
     *
     * @return int Clamped limit (defaults to DEFAULT_LIMIT when omitted).
     */
    private function parseLimit(?string $value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_LIMIT;
        }

        if (!ctype_digit($value)) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min((int) $value, self::MAX_LIMIT));
    }

    /**
     * Parse the ?offset parameter ensuring it is a non-negative integer.
     *
     * @param string|null $value Raw query parameter value.
     *
     * @return int Parsed offset (defaults to 0 when omitted or invalid).
     */
    private function parseOffset(?string $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (!ctype_digit($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

    /**
     * Parse a date string and validate that it matches YYYY-MM-DD format.
     *
     * Returns null when the value is absent, empty, or not a valid calendar date.
     *
     * @param string|null $value Raw query parameter value.
     *
     * @return string|null Validated date string (YYYY-MM-DD) or null.
     */
    private function parseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Require exactly YYYY-MM-DD
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        // Verify the date is a real calendar date (e.g. reject 2026-02-30)
        $parts = explode('-', $value);
        if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return null;
        }

        return $value;
    }
}
