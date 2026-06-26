<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – AuditLogEndpoint
 *
 * Handles GET /audit/logs requests.
 *
 * Returns a paginated list of audit log entries with optional filters.
 *
 * Query parameters:
 *   - username      (string)       Filter by exact username
 *   - action_prefix (string)       Filter by action prefix (e.g. "cron" matches "cron.create")
 *   - date_from     (string)       Filter entries from this datetime (Y-m-d or Y-m-d H:i:s)
 *   - date_to       (string)       Filter entries up to this datetime (Y-m-d or Y-m-d H:i:s)
 *   - limit         (int, 1–500)   Max rows to return (default 50)
 *   - offset        (int, ≥ 0)     Pagination offset (default 0)
 *
 * Response (HTTP 200):
 * ```json
 * {
 *   "data": [...],
 *   "total": 123,
 *   "limit": 50,
 *   "offset": 0
 * }
 * ```
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class AuditLogEndpoint
 *
 * Reads audit log entries from the audit_log table with pagination and filters.
 */
final class AuditLogEndpoint
{
    /** Default page size. */
    private const DEFAULT_LIMIT = 50;

    /** Hard upper bound on the ?limit parameter. */
    private const MAX_LIMIT = 500;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
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
     * Handle an incoming GET /audit/logs request.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $this->logger->debug('AuditLogEndpoint: handling GET /audit/logs');

        $limit  = $this->parseLimit($_GET['limit']   ?? null);
        $offset = $this->parseOffset($_GET['offset'] ?? null);

        $username     = isset($_GET['username'])      ? trim((string) $_GET['username'])      : null;
        $actionPrefix = isset($_GET['action_prefix']) ? trim((string) $_GET['action_prefix']) : null;
        $dateFrom     = isset($_GET['date_from'])     ? trim((string) $_GET['date_from'])     : null;
        $dateTo       = isset($_GET['date_to'])       ? trim((string) $_GET['date_to'])       : null;

        [$whereClause, $queryParams] = $this->buildWhere($username, $actionPrefix, $dateFrom, $dateTo);

        try {
            $total = $this->fetchTotal($whereClause, $queryParams);
            $data  = $this->fetchData($whereClause, $queryParams, $limit, $offset);
        } catch (PDOException $e) {
            $this->logger->error('AuditLogEndpoint: database error', ['message' => $e->getMessage()]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to retrieve audit log.',
                'code'    => 500,
            ]);
            return;
        }

        jsonResponse(200, [
            'data'   => $data,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the WHERE clause and parameter bindings from the active filters.
     *
     * @param string|null $username     Exact username filter.
     * @param string|null $actionPrefix Action prefix filter (LIKE '<prefix>%').
     * @param string|null $dateFrom     Lower bound on created_at.
     * @param string|null $dateTo       Upper bound on created_at.
     *
     * @return array{string, array<string, mixed>} [whereClause, params]
     */
    private function buildWhere(
        ?string $username,
        ?string $actionPrefix,
        ?string $dateFrom,
        ?string $dateTo,
    ): array {
        $conditions = [];
        $params     = [];

        if ($username !== null && $username !== '') {
            $conditions[]          = 'username = :username';
            $params[':username']   = $username;
        }

        if ($actionPrefix !== null && $actionPrefix !== '') {
            $conditions[]              = 'action LIKE :action_prefix';
            $params[':action_prefix']  = $actionPrefix . '%';
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $conditions[]          = 'created_at >= :date_from';
            $params[':date_from']  = $dateFrom;
        }

        if ($dateTo !== null && $dateTo !== '') {
            $conditions[]        = 'created_at <= :date_to';
            $params[':date_to']  = $dateTo;
        }

        $whereClause = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$whereClause, $params];
    }

    /**
     * Count the total number of matching rows (without pagination).
     *
     * @param string               $whereClause SQL WHERE clause (may be empty).
     * @param array<string, mixed> $params      Named parameter bindings.
     *
     * @return int Total matching row count.
     *
     * @throws PDOException On database errors.
     */
    private function fetchTotal(string $whereClause, array $params): int
    {
        $sql  = "SELECT COUNT(*) FROM audit_log {$whereClause}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Fetch a page of audit log rows.
     *
     * @param string               $whereClause SQL WHERE clause (may be empty).
     * @param array<string, mixed> $params      Named parameter bindings.
     * @param int                  $limit       Max rows to return.
     * @param int                  $offset      Number of rows to skip.
     *
     * @return array<int, array<string, mixed>> Rows as associative arrays.
     *
     * @throws PDOException On database errors.
     */
    private function fetchData(string $whereClause, array $params, int $limit, int $offset): array
    {
        $sql = "SELECT id, user_id, username, action, resource_type, resource_id,
                       resource_label, details, ip_address, created_at
                  FROM audit_log
                {$whereClause}
                 ORDER BY id DESC
                 LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): array {
            return [
                'id'             => (int)    $row['id'],
                'user_id'        => (int)    $row['user_id'],
                'username'       => (string) $row['username'],
                'action'         => (string) $row['action'],
                'resource_type'  => $row['resource_type'] !== null ? (string) $row['resource_type'] : null,
                'resource_id'    => $row['resource_id']   !== null ? (int)    $row['resource_id']   : null,
                'resource_label' => $row['resource_label'] !== null ? (string) $row['resource_label'] : null,
                'details'        => $row['details'] !== null ? json_decode((string) $row['details'], true) : null,
                'ip_address'     => $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
                'created_at'     => (string) $row['created_at'],
            ];
        }, $rows !== false ? $rows : []);
    }

    /**
     * Parse and clamp the ?limit query parameter.
     *
     * @param string|null $value Raw query string value.
     *
     * @return int Clamped limit.
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
     * Parse the ?offset query parameter, ensuring it is a non-negative integer.
     *
     * @param string|null $value Raw query string value.
     *
     * @return int Non-negative offset.
     */
    private function parseOffset(?string $value): int
    {
        if ($value === null || $value === '' || !ctype_digit($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }
}
