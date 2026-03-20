<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – CronListEndpoint
 *
 * Handles GET /crons requests.
 *
 * Returns a JSON list of all cronmanager jobs, optionally filtered by the
 * `linux_user` query parameter. Each job includes its associated tags,
 * aggregated via GROUP_CONCAT to avoid multiple result rows per job.
 *
 * This class relies on the global `jsonResponse()` function being available
 * in the calling scope (defined in agent.php).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Cron\CrontabManager;
use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class CronListEndpoint
 *
 * Handles GET /crons API requests.
 *
 * Supported query parameters:
 *   - user (string, optional): Filter results to jobs belonging to this Linux user.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "data": [
 *     {
 *       "id": 1,
 *       "linux_user": "deploy",
 *       "schedule": "* /5 * * * *",
 *       "command": "/opt/scripts/backup.sh",
 *       "description": "Backup database",
 *       "active": true,
 *       "notify_on_failure": true,
 *       "created_at": "2026-03-15T10:00:00",
 *       "tags": ["backup", "daily"]
 *     }
 *   ],
 *   "count": 1
 * }
 * ```
 */
final class CronListEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * CronListEndpoint constructor.
     *
     * @param PDO            $pdo            Active PDO database connection.
     * @param Logger         $logger         Monolog logger instance.
     * @param CrontabManager $crontabManager Used to verify that active jobs have
     *                                        a crontab entry (consistency check).
     */
    public function __construct(
        private readonly PDO            $pdo,
        private readonly Logger         $logger,
        private readonly CrontabManager $crontabManager,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming GET /crons request.
     *
     * Reads the optional `user` query parameter from $_GET, executes the
     * list query, and emits a JSON response via the global jsonResponse().
     *
     * @param array<string, string> $params Path parameters extracted by the Router
     *                                      (unused for this endpoint).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        // Read optional filters from the query string
        $userFilter   = isset($_GET['user'])   && $_GET['user']   !== '' ? (string) $_GET['user']   : null;
        $tagFilter    = isset($_GET['tag'])    && $_GET['tag']    !== '' ? (string) $_GET['tag']    : null;
        $targetFilter = isset($_GET['target']) && $_GET['target'] !== '' ? (string) $_GET['target'] : null;

        // Backward compat: accept ssh_host filter from older web app versions
        if ($targetFilter === null && isset($_GET['ssh_host']) && $_GET['ssh_host'] !== '') {
            $targetFilter = (string) $_GET['ssh_host'];
        }

        $this->logger->debug('CronListEndpoint: handling GET /crons', [
            'user_filter'   => $userFilter,
            'tag_filter'    => $tagFilter,
            'target_filter' => $targetFilter,
        ]);

        try {
            $jobs = $this->fetchJobs($userFilter, $tagFilter, $targetFilter);
            $jobs = $this->attachCrontabStatus($jobs);

            jsonResponse(200, [
                'data'  => $jobs,
                'count' => count($jobs),
            ]);
        } catch (PDOException $e) {
            $this->logger->error('CronListEndpoint: database error', [
                'message' => $e->getMessage(),
            ]);

            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to retrieve cron jobs.',
                'code'    => 500,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Add a `crontab_ok` boolean to each job record.
     *
     * For active jobs the field is true when the crontab entry actually exists,
     * false when it is missing (DB/crontab out of sync).
     * For inactive jobs the field is always true (no entry is expected).
     *
     * Crontab reads are batched per unique Linux user to minimise shell calls.
     *
     * @param array<int, array<string, mixed>> $jobs Normalised job records.
     *
     * @return array<int, array<string, mixed>> The same records with `crontab_ok` added.
     */
    private function attachCrontabStatus(array $jobs): array
    {
        // Collect unique linux users that have at least one active job
        $activeUsers = [];
        foreach ($jobs as $job) {
            if ($job['active']) {
                $activeUsers[$job['linux_user']] = true;
            }
        }

        // One crontab read per user → map user → set of managed job IDs
        /** @var array<string, array<int, bool>> $managedByUser */
        $managedByUser = [];
        foreach (array_keys($activeUsers) as $user) {
            try {
                $ids = $this->crontabManager->getManagedJobIds($user);
                $managedByUser[$user] = array_fill_keys($ids, true);
            } catch (\Throwable $e) {
                // If we cannot read the crontab, assume unknown → mark as ok
                // to avoid false-positive warnings rather than hiding real ones.
                $this->logger->warning('CronListEndpoint: could not read crontab for consistency check', [
                    'user'    => $user,
                    'message' => $e->getMessage(),
                ]);
                $managedByUser[$user] = null; // null = unknown
            }
        }

        foreach ($jobs as &$job) {
            if (!$job['active']) {
                // Inactive jobs are not expected to have a crontab entry
                $job['crontab_ok'] = true;
            } else {
                $user = $job['linux_user'];
                if (!isset($managedByUser[$user])) {
                    // Should not happen (we pre-populated), but be safe
                    $job['crontab_ok'] = true;
                } elseif ($managedByUser[$user] === null) {
                    // Crontab unreadable – treat as unknown, surface as ok
                    $job['crontab_ok'] = true;
                } else {
                    $job['crontab_ok'] = isset($managedByUser[$user][$job['id']]);
                }
            }
        }
        unset($job);

        return $jobs;
    }

    /**
     * Execute the jobs query and return a normalised result array.
     *
     * @param string|null $userFilter   When non-null, limit results to this Linux user.
     * @param string|null $tagFilter    When non-null, limit results to jobs with this tag.
     * @param string|null $targetFilter When non-null, limit results to jobs with this target.
     *
     * @return array<int, array<string, mixed>> Array of job records.
     *
     * @throws PDOException On database errors.
     */
    private function fetchJobs(?string $userFilter, ?string $tagFilter, ?string $targetFilter): array
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
            WHERE (:user1 IS NULL OR j.linux_user = :user2)
              AND (:tag1 IS NULL OR j.id IN (
                    SELECT ct2.cronjob_id
                    FROM cronjob_tags ct2
                    JOIN tags t2 ON t2.id = ct2.tag_id
                    WHERE t2.name = :tag2
              ))
              AND (:target1 IS NULL OR j.id IN (
                    SELECT jt2.job_id
                    FROM job_targets jt2
                    WHERE jt2.target = :target2
              ))
            GROUP BY j.id
            ORDER BY j.linux_user, j.id
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user1'    => $userFilter,
            ':user2'    => $userFilter,
            ':tag1'     => $tagFilter,
            ':tag2'     => $tagFilter,
            ':target1'  => $targetFilter,
            ':target2'  => $targetFilter,
        ]);

        $rows = $stmt->fetchAll();
        $jobs = [];

        foreach ($rows as $row) {
            $jobs[] = $this->normaliseRow($row);
        }

        return $jobs;
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

        // When no job_targets rows exist (unmigrated job), derive from legacy columns
        if ($targetsRaw !== '') {
            $targets = explode(',', $targetsRaw);
        } else {
            $mode    = (string) ($row['execution_mode'] ?? 'local');
            $sshHost = isset($row['ssh_host']) ? trim((string) $row['ssh_host']) : '';
            $targets = ($mode === 'remote' && $sshHost !== '') ? [$sshHost] : ['local'];
        }

        return [
            'id'                => (int)    $row['id'],
            'linux_user'        => (string) $row['linux_user'],
            'schedule'          => (string) $row['schedule'],
            'command'           => (string) $row['command'],
            'description'       => isset($row['description']) ? (string) $row['description'] : null,
            'active'            => (bool)   $row['active'],
            'notify_on_failure' => (bool)   $row['notify_on_failure'],
            'targets'           => $targets,
            'execution_mode'    => (string) ($row['execution_mode'] ?? 'local'),
            'ssh_host'          => isset($row['ssh_host']) ? (string) $row['ssh_host'] : null,
            'created_at'        => (string) $row['created_at'],
            'tags'              => $tags,
            'last_run'          => isset($row['last_run'])       && $row['last_run']       !== null ? (string) $row['last_run']       : null,
            'last_exit_code'    => isset($row['last_exit_code']) && $row['last_exit_code'] !== null ? (int)    $row['last_exit_code'] : null,
        ];
    }
}
