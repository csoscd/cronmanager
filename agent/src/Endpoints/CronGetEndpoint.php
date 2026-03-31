<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – CronGetEndpoint
 *
 * Handles GET /crons/{id} requests.
 *
 * Returns a single cron job record including its associated tags, using the
 * same response structure produced by CronListEndpoint. This endpoint is used
 * by the cron-wrapper.sh bash script to retrieve the command that should be
 * executed for a given job ID without having to parse the entire crontab.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "id": 42,
 *   "linux_user": "deploy",
 *   "schedule": "5 * * * *",
 *   "command": "/opt/scripts/backup.sh",
 *   "description": "Backup database",
 *   "active": true,
 *   "notify_on_failure": true,
 *   "created_at": "2026-03-15T10:00:00",
 *   "tags": ["backup", "daily"]
 * }
 * ```
 *
 * Response on not found (HTTP 404):
 * ```json
 * { "error": "Not Found", "message": "Cron job with ID 42 does not exist.", "code": 404 }
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
 * Class CronGetEndpoint
 *
 * Handles GET /crons/{id} API requests.
 *
 * The job ID is extracted from the URL path parameters by the Router and
 * passed to the handler via the $params array as $params['id'].
 */
final class CronGetEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * CronGetEndpoint constructor.
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
     * Handle an incoming GET /crons/{id} request.
     *
     * @param array<string, string> $params Path parameters extracted by the Router.
     *                                      Expected key: 'id' (string representation of an int).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        // ------------------------------------------------------------------
        // 1. Extract and validate the path parameter
        // ------------------------------------------------------------------

        $rawId = $params['id'] ?? '';

        if ($rawId === '' || !ctype_digit($rawId) || (int) $rawId <= 0) {
            $this->logger->warning('CronGetEndpoint: invalid or missing id parameter', [
                'raw_id' => $rawId,
            ]);
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Path parameter {id} must be a positive integer.',
                'code'    => 400,
            ]);
            return;
        }

        $jobId = (int) $rawId;

        $this->logger->debug('CronGetEndpoint: handling GET /crons/{id}', [
            'job_id' => $jobId,
        ]);

        // ------------------------------------------------------------------
        // 2. Fetch the job from the database
        // ------------------------------------------------------------------

        try {
            $job = $this->fetchJob($jobId);
        } catch (PDOException $e) {
            $this->logger->error('CronGetEndpoint: database error', [
                'job_id'  => $jobId,
                'message' => $e->getMessage(),
            ]);

            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to retrieve cron job.',
                'code'    => 500,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 3. Return 404 when the job does not exist
        // ------------------------------------------------------------------

        if ($job === null) {
            $this->logger->info('CronGetEndpoint: job not found', ['job_id' => $jobId]);
            jsonResponse(404, [
                'error'   => 'Not Found',
                'message' => sprintf('Cron job with ID %d does not exist.', $jobId),
                'code'    => 404,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 4. Return the job record
        // ------------------------------------------------------------------

        jsonResponse(200, $job);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch a single cron job by ID including its associated tags.
     *
     * Returns null when no job with the given ID is found.
     *
     * @param int $jobId The job ID to look up.
     *
     * @return array<string, mixed>|null Normalised job record or null.
     *
     * @throws PDOException On database errors.
     */
    private function fetchJob(int $jobId): ?array
    {
        $sql = <<<SQL
            SELECT
                j.id,
                j.linux_user,
                j.schedule,
                j.command,
                j.description,
                j.active,
                j.notify_on_failure,
                j.execution_limit_seconds,
                j.auto_kill_on_limit,
                j.singleton,
                j.execution_mode,
                j.ssh_host,
                j.created_at,
                GROUP_CONCAT(DISTINCT t.name    ORDER BY t.name    SEPARATOR ',') AS tags,
                GROUP_CONCAT(DISTINCT jt.target ORDER BY jt.target SEPARATOR ',') AS targets,
                (SELECT el.started_at
                   FROM execution_log el
                  WHERE el.cronjob_id = j.id
                  ORDER BY el.id DESC
                  LIMIT 1) AS last_run,
                (SELECT el.exit_code
                   FROM execution_log el
                  WHERE el.cronjob_id = j.id
                    AND el.finished_at IS NOT NULL
                  ORDER BY el.id DESC
                  LIMIT 1) AS last_exit_code
            FROM cronjobs j
            LEFT JOIN cronjob_tags ct ON ct.cronjob_id = j.id
            LEFT JOIN tags t          ON t.id = ct.tag_id
            LEFT JOIN job_targets jt  ON jt.job_id = j.id
            WHERE j.id = :id
            GROUP BY j.id
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->normaliseRow($row);
    }

    /**
     * Normalise a raw database row into the API response format.
     *
     * Converts integer/boolean columns from their string representations and
     * splits the comma-separated tags string into a proper PHP array.
     *
     * @param array<string, mixed> $row Raw database row.
     *
     * @return array<string, mixed> Normalised job record.
     */
    private function normaliseRow(array $row): array
    {
        $tagsRaw    = isset($row['tags'])    && $row['tags']    !== null ? (string) $row['tags']    : '';
        $targetsRaw = isset($row['targets']) && $row['targets'] !== null ? (string) $row['targets'] : '';

        $tags = $tagsRaw !== '' ? explode(',', $tagsRaw) : [];

        // When no job_targets rows exist (job not yet migrated), derive from legacy columns
        if ($targetsRaw !== '') {
            $targets = explode(',', $targetsRaw);
        } else {
            $mode    = (string) ($row['execution_mode'] ?? 'local');
            $sshHost = isset($row['ssh_host']) ? trim((string) $row['ssh_host']) : '';
            $targets = ($mode === 'remote' && $sshHost !== '') ? [$sshHost] : ['local'];
        }

        return [
            'id'                       => (int)    $row['id'],
            'linux_user'               => (string) $row['linux_user'],
            'schedule'                 => (string) $row['schedule'],
            'command'                  => (string) $row['command'],
            'description'              => isset($row['description']) ? (string) $row['description'] : null,
            'active'                   => (bool)   $row['active'],
            'notify_on_failure'        => (bool)   $row['notify_on_failure'],
            'execution_limit_seconds'  => isset($row['execution_limit_seconds']) && $row['execution_limit_seconds'] !== null
                ? (int) $row['execution_limit_seconds']
                : null,
            'auto_kill_on_limit'       => (bool) ($row['auto_kill_on_limit'] ?? false),
            'singleton'                => (bool) ($row['singleton'] ?? false),
            'targets'                  => $targets,
            // Legacy fields kept so old wrapper invocations (no target arg) still work
            'execution_mode'           => (string) ($row['execution_mode'] ?? 'local'),
            'ssh_host'                 => isset($row['ssh_host']) ? (string) $row['ssh_host'] : null,
            'created_at'               => (string) $row['created_at'],
            'tags'                     => $tags,
            'last_run'                 => isset($row['last_run'])       && $row['last_run']       !== null ? (string) $row['last_run']       : null,
            'last_exit_code'           => isset($row['last_exit_code']) && $row['last_exit_code'] !== null ? (int)    $row['last_exit_code'] : null,
        ];
    }
}
