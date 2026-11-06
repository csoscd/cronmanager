<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – CronListEndpoint
 *
 * Handles GET /crons requests.
 *
 * Returns a JSON list of all cronmanager jobs, server-side filtered by the
 * query parameters listed below. Each job includes its associated tags,
 * aggregated via GROUP_CONCAT to avoid multiple result rows per job.
 * The `notes` column is intentionally excluded from the list response for
 * payload efficiency; it is returned only by GET /crons/{id}.
 *
 * Supported query parameters:
 *   user       string   Filter to jobs belonging to this Linux user.
 *   tag        string   Filter to jobs that carry this tag.
 *   target     string   Filter to jobs that run on this target.
 *   q          string   Free-text search on description, command, notes and
 *                       job ID (exact numeric match).
 *   active     0|1      Filter to inactive (0) or active (1) jobs only.
 *   exec_type  string   Filter by dependency type: "schedule" (no dependency),
 *                       "requires", or "triggered_by".
 *
 * This class relies on the global `jsonResponse()` function being available
 * in the calling scope (defined in agent.php).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Cron\CrontabManager;
use Cronmanager\Agent\Repository\DependencyRepository;
use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class CronListEndpoint
 *
 * Handles GET /crons API requests with server-side filtering.
 */
final class CronListEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * CronListEndpoint constructor.
     *
     * @param PDO                  $pdo            Active PDO database connection.
     * @param Logger               $logger         Monolog logger instance.
     * @param CrontabManager       $crontabManager Used to verify that active jobs have
     *                                              a crontab entry (consistency check).
     * @param DependencyRepository $deps           Dependency repository.
     */
    public function __construct(
        private readonly PDO                  $pdo,
        private readonly Logger               $logger,
        private readonly CrontabManager       $crontabManager,
        private readonly DependencyRepository $deps,
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
        $userFilter     = isset($_GET['user'])      && $_GET['user']      !== '' ? (string) $_GET['user']      : null;
        $tagFilter      = isset($_GET['tag'])       && $_GET['tag']       !== '' ? (string) $_GET['tag']       : null;
        $targetFilter   = isset($_GET['target'])    && $_GET['target']    !== '' ? (string) $_GET['target']    : null;
        $searchFilter   = isset($_GET['q'])         && $_GET['q']         !== '' ? (string) $_GET['q']         : null;
        $execTypeFilter = isset($_GET['exec_type']) && $_GET['exec_type'] !== '' ? (string) $_GET['exec_type'] : null;

        // active filter: '1' = active only, '0' = inactive only, absent = all
        $activeFilter = null;
        if (isset($_GET['active']) && $_GET['active'] !== '') {
            $activeFilter = $_GET['active'] === '0' ? false : true;
        }

        // Backward compat: accept ssh_host filter from older web app versions
        if ($targetFilter === null && isset($_GET['ssh_host']) && $_GET['ssh_host'] !== '') {
            $targetFilter = (string) $_GET['ssh_host'];
        }

        // Validate exec_type
        if ($execTypeFilter !== null && !in_array($execTypeFilter, ['schedule', 'requires', 'triggered_by'], true)) {
            jsonResponse(400, ['error' => 'Bad Request', 'message' => 'exec_type must be one of: schedule, requires, triggered_by.', 'code' => 400]);
            return;
        }

        $this->logger->debug('CronListEndpoint: handling GET /crons', [
            'user_filter'      => $userFilter,
            'tag_filter'       => $tagFilter,
            'target_filter'    => $targetFilter,
            'search_filter'    => $searchFilter,
            'active_filter'    => $activeFilter,
            'exec_type_filter' => $execTypeFilter,
        ]);

        try {
            $jobs = $this->fetchJobs($userFilter, $tagFilter, $targetFilter, $searchFilter, $activeFilter, $execTypeFilter);
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
     * For active jobs the field is true only when ALL expected targets have a
     * matching crontab entry. A single missing target entry (e.g. one of three
     * SSH targets was removed) is enough to set the field to false.
     * For inactive jobs the field is always true (no entry is expected).
     *
     * Crontab reads are batched per unique Linux user (one shell call per user).
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

        // One crontab read per user → map user → [jobId => [target => true]]
        // null means the crontab was unreadable (treat as unknown = ok)
        /** @var array<string, array<int, array<string, bool>>|null> $managedByUser */
        $managedByUser = [];
        foreach (array_keys($activeUsers) as $user) {
            try {
                $managedByUser[$user] = $this->crontabManager->getManagedEntriesCached($user);
            } catch (\Throwable $e) {
                // Cannot read crontab – default to ok to avoid false-positive warnings
                $this->logger->warning('CronListEndpoint: could not read crontab for consistency check', [
                    'user'    => $user,
                    'message' => $e->getMessage(),
                ]);
                $managedByUser[$user] = null;
            }
        }

        foreach ($jobs as &$job) {
            if (!$job['active']) {
                // Inactive jobs are not expected to have any crontab entry
                $job['crontab_ok'] = true;
                continue;
            }

            // triggered_by jobs have no schedule and no crontab entry
            if ($job['schedule'] === null) {
                $job['crontab_ok'] = true;
                continue;
            }

            $user    = $job['linux_user'];
            $entries = $managedByUser[$user] ?? null;

            if ($entries === null) {
                // Crontab unreadable – treat as unknown, show no warning
                $job['crontab_ok'] = true;
                continue;
            }

            $jobId  = $job['id'];
            $targets = $job['targets'];

            if (!isset($entries[$jobId])) {
                // No crontab entry exists for this job at all
                $job['crontab_ok'] = false;
                continue;
            }

            // Legacy format (# cronmanager:{id} without target): covers any target
            if (isset($entries[$jobId]['__legacy__'])) {
                $job['crontab_ok'] = true;
                continue;
            }

            // Check that every expected target has an entry
            $job['crontab_ok'] = true;
            foreach ($targets as $target) {
                if (!isset($entries[$jobId][(string) $target])) {
                    $job['crontab_ok'] = false;
                    break;
                }
            }
        }
        unset($job);

        return $jobs;
    }

    /**
     * Execute the jobs query and return a normalised result array.
     *
     * @param string|null $userFilter     Limit to this Linux user.
     * @param string|null $tagFilter      Limit to jobs carrying this tag.
     * @param string|null $targetFilter   Limit to jobs running on this target.
     * @param string|null $searchFilter   Free-text search on description/command/notes/id.
     * @param bool|null   $activeFilter   true = active only, false = inactive only, null = all.
     * @param string|null $execTypeFilter 'schedule', 'requires', or 'triggered_by'.
     *
     * @return array<int, array<string, mixed>> Array of job records.
     *
     * @throws PDOException On database errors.
     */
    private function fetchJobs(
        ?string $userFilter,
        ?string $tagFilter,
        ?string $targetFilter,
        ?string $searchFilter   = null,
        ?bool   $activeFilter   = null,
        ?string $execTypeFilter = null,
    ): array {
        // Build LIKE pattern once for free-text search
        $likePattern = $searchFilter !== null ? '%' . $searchFilter . '%' : null;

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
                j.run_in_maintenance,
                j.retention_days,
                j.retry_count,
                j.retry_delay_minutes,
                j.restart_on_exitcodes,
                j.notify_after_failures,
                j.notify_after_limit_exceeded,
                j.notify_on_silence,
                j.silence_grace_minutes,
                j.last_silence_alert_at,
                j.execution_mode,
                j.ssh_host,
                j.created_at,
                GROUP_CONCAT(DISTINCT t.name    ORDER BY t.name    SEPARATOR ',') AS tags,
                GROUP_CONCAT(DISTINCT jt.target ORDER BY jt.target SEPARATOR ',') AS targets,
                el_last.started_at             AS last_run,
                el_last.finished_at            AS last_finished_at,
                el_last_fin.exit_code          AS last_exit_code,
                el_last_fin.acknowledged_at    AS last_acknowledged_at,
                jd.predecessor_id              AS dep_predecessor_id,
                jd.type                        AS dep_type,
                jd.exit_codes                  AS dep_exit_codes,
                jd.max_age_minutes             AS dep_max_age_minutes,
                jd.trigger_delay_minutes       AS dep_trigger_delay_minutes,
                pred.description               AS dep_predecessor_description
            FROM cronjobs j
            LEFT JOIN cronjob_tags ct ON ct.cronjob_id = j.id
            LEFT JOIN tags t          ON t.id = ct.tag_id
            LEFT JOIN job_targets jt  ON jt.job_id = j.id
            -- The last / last-finished execution ids are denormalised onto the
            -- job row (migration 018) and maintained by the execution start /
            -- finish endpoints. Two primary-key lookups replace the previous
            -- derived-table aggregate over the complete execution_log, whose
            -- cost grew with the total history size on every list request.
            LEFT JOIN execution_log el_last     ON el_last.id     = j.last_execution_id
            LEFT JOIN execution_log el_last_fin ON el_last_fin.id = j.last_finished_execution_id
            LEFT JOIN job_dependencies jd       ON jd.job_id      = j.id
            LEFT JOIN cronjobs pred             ON pred.id         = jd.predecessor_id
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
              AND (:active1 IS NULL OR j.active = :active2)
              AND (:q1 IS NULL OR (
                    j.description LIKE :q2
                    OR j.command     LIKE :q3
                    OR j.notes       LIKE :q4
                    OR CAST(j.id AS CHAR) = :q5
              ))
              AND (:exec_type1 IS NULL OR (
                    (:exec_type2 = 'schedule'    AND jd.job_id IS NULL)
                 OR (:exec_type3 = 'requires'    AND jd.type = 'requires')
                 OR (:exec_type4 = 'triggered_by' AND jd.type = 'triggered_by')
              ))
            GROUP BY j.id
            ORDER BY j.linux_user, j.id
            SQL;

        $activeInt = $activeFilter !== null ? (int) $activeFilter : null;

        $stmt = $this->pdo->prepare($sql);
        \Cronmanager\Agent\Database\Connection::timedExecute($stmt, [
            ':user1'       => $userFilter,
            ':user2'       => $userFilter,
            ':tag1'        => $tagFilter,
            ':tag2'        => $tagFilter,
            ':target1'     => $targetFilter,
            ':target2'     => $targetFilter,
            ':active1'     => $activeInt,
            ':active2'     => $activeInt,
            ':q1'          => $likePattern,
            ':q2'          => $likePattern,
            ':q3'          => $likePattern,
            ':q4'          => $likePattern,
            ':q5'          => $searchFilter,
            ':exec_type1'  => $execTypeFilter,
            ':exec_type2'  => $execTypeFilter,
            ':exec_type3'  => $execTypeFilter,
            ':exec_type4'  => $execTypeFilter,
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

        $dependency = $row['dep_predecessor_id'] !== null ? [
            'predecessor_id'          => (int)    $row['dep_predecessor_id'],
            'type'                    => (string) $row['dep_type'],
            'exit_codes'              => (string) $row['dep_exit_codes'],
            'max_age_minutes'         => $row['dep_max_age_minutes'] !== null ? (int) $row['dep_max_age_minutes'] : null,
            'trigger_delay_minutes'   => (int) ($row['dep_trigger_delay_minutes'] ?? 0),
            'predecessor_description' => isset($row['dep_predecessor_description'])
                ? (string) $row['dep_predecessor_description']
                : null,
        ] : null;

        return [
            'id'                       => (int)    $row['id'],
            'linux_user'               => (string) $row['linux_user'],
            'schedule'                 => $row['schedule'] !== null ? (string) $row['schedule'] : null,
            'command'                  => (string) $row['command'],
            'description'              => isset($row['description']) ? (string) $row['description'] : null,
            'active'                   => (bool)   $row['active'],
            'notify_on_failure'        => (bool)   $row['notify_on_failure'],
            'execution_limit_seconds'  => isset($row['execution_limit_seconds']) && $row['execution_limit_seconds'] !== null
                ? (int) $row['execution_limit_seconds']
                : null,
            'auto_kill_on_limit'       => (bool) ($row['auto_kill_on_limit']   ?? false),
            'singleton'                => (bool) ($row['singleton']           ?? false),
            'run_in_maintenance'       => (bool) ($row['run_in_maintenance']  ?? false),
            'targets'                  => $targets,
            'execution_mode'           => (string) ($row['execution_mode'] ?? 'local'),
            'ssh_host'                 => isset($row['ssh_host']) ? (string) $row['ssh_host'] : null,
            'created_at'               => (string) $row['created_at'],
            'tags'                     => $tags,
            'retention_days'           => isset($row['retention_days']) && $row['retention_days'] !== null
                ? (int) $row['retention_days']
                : null,
            'retry_count'              => (int) ($row['retry_count']          ?? 0),
            'retry_delay_minutes'      => (int) ($row['retry_delay_minutes']  ?? 1),
            'restart_on_exitcodes'     => isset($row['restart_on_exitcodes']) && (string) $row['restart_on_exitcodes'] !== ''
                ? (string) $row['restart_on_exitcodes']
                : null,
            'notify_after_failures'       => (int) ($row['notify_after_failures']        ?? 1),
            'notify_after_limit_exceeded' => (int) ($row['notify_after_limit_exceeded']  ?? 1),
            'notify_on_silence'           => (bool) ($row['notify_on_silence']            ?? false),
            'silence_grace_minutes'       => isset($row['silence_grace_minutes']) && $row['silence_grace_minutes'] !== null
                ? (int) $row['silence_grace_minutes']
                : null,
            'last_silence_alert_at'       => isset($row['last_silence_alert_at']) && $row['last_silence_alert_at'] !== null
                ? (string) $row['last_silence_alert_at']
                : null,
            'last_run'                 => isset($row['last_run'])       && $row['last_run']       !== null ? (string) $row['last_run']       : null,
            'last_exit_code'           => isset($row['last_exit_code']) && $row['last_exit_code'] !== null ? (int)    $row['last_exit_code'] : null,
            'last_acknowledged_at'     => isset($row['last_acknowledged_at']) && $row['last_acknowledged_at'] !== null ? (string) $row['last_acknowledged_at'] : null,
            'is_running'               => isset($row['last_run']) && $row['last_run'] !== null
                && (!isset($row['last_finished_at']) || $row['last_finished_at'] === null),
            'dependency'               => $dependency,
        ];
    }
}
