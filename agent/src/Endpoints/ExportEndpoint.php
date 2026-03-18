<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – ExportEndpoint
 *
 * Handles GET /export requests.
 *
 * Generates a clean, importable crontab block (or a JSON representation) for
 * all or a filtered subset of managed cron jobs. The endpoint is intended for
 * backup/migration use cases and produces output that can be fed directly into
 * `crontab -` for the appropriate Linux user.
 *
 * Supported query parameters (all optional):
 *   - user   (string)               Export only jobs belonging to this Linux user.
 *   - tag    (string)               Export only jobs carrying this tag.
 *   - format (string: crontab|json) Output format; default is "crontab".
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
 * Class ExportEndpoint
 *
 * Handles GET /export API requests.
 *
 * For format=crontab the response Content-Type is text/plain and the body
 * contains a ready-to-import crontab fragment.
 *
 * For format=json the response Content-Type is application/json and the body
 * mirrors the CronListEndpoint structure with an additional export metadata
 * wrapper.
 */
final class ExportEndpoint
{
    /** Allowed output formats. */
    private const VALID_FORMATS = ['crontab', 'json'];

    /** Default output format when ?format is not specified. */
    private const DEFAULT_FORMAT = 'crontab';

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * ExportEndpoint constructor.
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
     * Handle an incoming GET /export request.
     *
     * Parses query parameters, fetches the relevant cron jobs, and emits the
     * export in the requested format.
     *
     * @param array<string, string> $params Path parameters extracted by the Router
     *                                      (unused for this endpoint).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $this->logger->debug('ExportEndpoint: handling GET /export', [
            'query' => $_GET,
        ]);

        // ------------------------------------------------------------------
        // 1. Parse query parameters
        // ------------------------------------------------------------------

        $user   = isset($_GET['user'])   && $_GET['user']   !== '' ? (string) $_GET['user']   : null;
        $tag    = isset($_GET['tag'])    && $_GET['tag']    !== '' ? (string) $_GET['tag']    : null;
        $format = isset($_GET['format']) && $_GET['format'] !== '' ? (string) $_GET['format'] : self::DEFAULT_FORMAT;

        if (!in_array($format, self::VALID_FORMATS, true)) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => sprintf(
                    'Parameter "format" must be one of: %s.',
                    implode(', ', self::VALID_FORMATS)
                ),
                'code'    => 400,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 2. Fetch matching jobs
        // ------------------------------------------------------------------

        try {
            $jobs = $this->fetchJobs($user, $tag);
        } catch (PDOException $e) {
            $this->logger->error('ExportEndpoint: database error', [
                'message' => $e->getMessage(),
            ]);

            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to retrieve cron jobs for export.',
                'code'    => 500,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 3. Emit response in requested format
        // ------------------------------------------------------------------

        $this->logger->info('ExportEndpoint: exporting jobs', [
            'format'    => $format,
            'job_count' => count($jobs),
            'user'      => $user,
            'tag'       => $tag,
        ]);

        if ($format === 'json') {
            $this->emitJson($jobs, $user, $tag);
        } else {
            $this->emitCrontab($jobs, $user, $tag);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers – output formatters
    // -------------------------------------------------------------------------

    /**
     * Emit a crontab-formatted plain-text export and terminate the script.
     *
     * The output is grouped by Linux user and includes comment lines for
     * the job description and associated tags. It can be piped directly into
     * `crontab -` for the respective user.
     *
     * @param array<int, array<string, mixed>> $jobs  Normalised job records.
     * @param string|null                      $user  Active user filter (for header).
     * @param string|null                      $tag   Active tag filter (for header).
     *
     * @return void  (exits)
     */
    private function emitCrontab(array $jobs, ?string $user, ?string $tag): void
    {
        $now      = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
        $filename = 'cronmanager-export-' . date('Y-m-d') . '.crontab';

        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header(sprintf('Content-Disposition: attachment; filename="%s"', $filename));

        // File header
        echo "# Cronmanager Export\n";
        echo sprintf("# Generated: %s\n", $now);
        echo sprintf("# User: %s\n", $user ?? 'all users');

        if ($tag !== null) {
            echo sprintf("# Tag:  %s\n", $tag);
        }

        echo "#\n";

        if ($jobs === []) {
            echo "# (no jobs match the current filter)\n";
            exit;
        }

        // Group jobs by linux_user (already ordered by linux_user, id from the query)
        $currentUser = null;

        foreach ($jobs as $job) {
            $jobUser  = (string) $job['linux_user'];
            $schedule = (string) $job['schedule'];
            $command  = (string) $job['command'];
            $targets  = is_array($job['targets']) && $job['targets'] !== [] ? $job['targets'] : ['local'];

            if ($jobUser !== $currentUser) {
                echo sprintf("\n# --- %s ---\n", $jobUser);
                $currentUser = $jobUser;
            }

            // Description and tags comment line (once per job, before all target lines)
            $tagList     = implode(',', $job['tags']);
            $description = $job['description'] !== null && $job['description'] !== ''
                ? (string) $job['description']
                : '(no description)';

            $commentParts = [sprintf('[%s]', $description)];
            if ($tagList !== '') {
                $commentParts[] = sprintf('tags:%s', $tagList);
            }

            echo sprintf("# %s\n", implode(' ', $commentParts));

            // One crontab line per execution target
            foreach ($targets as $target) {
                $target = (string) $target;

                // Annotate target in a sub-comment when there are multiple targets
                if (count($targets) > 1) {
                    echo sprintf("# target: %s\n", $target);
                }

                // Build the effective command: local → as-is, remote → via SSH
                $effectiveCommand = $target !== 'local'
                    ? sprintf('ssh -o BatchMode=yes %s -- %s', $target, $command)
                    : $command;

                // Active/inactive marker for disabled jobs
                if (!(bool) $job['active']) {
                    echo '# DISABLED: ';
                }

                echo sprintf("%s %s\n", $schedule, $effectiveCommand);
            }
        }

        echo "\n";
        exit;
    }

    /**
     * Emit a JSON export response including export metadata.
     *
     * The structure mirrors CronListEndpoint's response with an added
     * `export` metadata block at the top level.
     *
     * @param array<int, array<string, mixed>> $jobs  Normalised job records.
     * @param string|null                      $user  Active user filter.
     * @param string|null                      $tag   Active tag filter.
     *
     * @return void  (exits via jsonResponse)
     */
    private function emitJson(array $jobs, ?string $user, ?string $tag): void
    {
        $exportMeta = [
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            'user_filter'  => $user,
            'tag_filter'   => $tag,
            'job_count'    => count($jobs),
        ];

        jsonResponse(200, [
            'export' => $exportMeta,
            'data'   => $jobs,
            'count'  => count($jobs),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers – database
    // -------------------------------------------------------------------------

    /**
     * Fetch all cron jobs matching the given filters, ordered by linux_user then ID.
     *
     * Includes tag data aggregated via GROUP_CONCAT, mirroring CronListEndpoint.
     *
     * @param string|null $user   Filter by linux_user when not null.
     * @param string|null $tag    Filter by tag name when not null.
     *
     * @return array<int, array<string, mixed>> Normalised job records.
     *
     * @throws PDOException On database errors.
     */
    private function fetchJobs(?string $user, ?string $tag): array
    {
        // Build WHERE conditions
        $conditions  = [];
        $queryParams = [];

        if ($user !== null) {
            $conditions[]           = 'j.linux_user = :user';
            $queryParams[':user']   = $user;
        }

        if ($tag !== null) {
            $conditions[] = <<<SQL
                j.id IN (
                    SELECT ct2.cronjob_id
                    FROM cronjob_tags ct2
                    JOIN tags t2 ON t2.id = ct2.tag_id
                    WHERE t2.name = :tag
                )
                SQL;
            $queryParams[':tag'] = $tag;
        }

        $whereClause = $conditions !== []
            ? 'WHERE ' . implode(' AND ', $conditions)
            : '';

        $sql = <<<SQL
            SELECT
                j.id,
                j.linux_user,
                j.schedule,
                j.command,
                j.description,
                j.active,
                j.notify_on_failure,
                j.created_at,
                GROUP_CONCAT(DISTINCT t.name  ORDER BY t.name  SEPARATOR ',') AS tags,
                GROUP_CONCAT(DISTINCT jt.target ORDER BY jt.target SEPARATOR ',') AS targets
            FROM cronjobs j
            LEFT JOIN cronjob_tags ct ON ct.cronjob_id = j.id
            LEFT JOIN tags t ON t.id = ct.tag_id
            LEFT JOIN job_targets jt ON jt.job_id = j.id
            {$whereClause}
            GROUP BY j.id
            ORDER BY j.linux_user, j.id
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($queryParams);

        $rows = $stmt->fetchAll();
        $jobs = [];

        foreach ($rows as $row) {
            $jobs[] = $this->normaliseRow($row);
        }

        return $jobs;
    }

    /**
     * Normalise a raw database row into the API response / export format.
     *
     * @param array<string, mixed> $row Raw database row.
     *
     * @return array<string, mixed> Normalised job record.
     */
    private function normaliseRow(array $row): array
    {
        $tagsRaw    = isset($row['tags'])    && $row['tags']    !== null ? (string) $row['tags']    : '';
        $targetsRaw = isset($row['targets']) && $row['targets'] !== null ? (string) $row['targets'] : '';
        $tags       = $tagsRaw    !== '' ? explode(',', $tagsRaw)    : [];
        $targets    = $targetsRaw !== '' ? explode(',', $targetsRaw) : ['local'];

        return [
            'id'                => (int)    $row['id'],
            'linux_user'        => (string) $row['linux_user'],
            'schedule'          => (string) $row['schedule'],
            'command'           => (string) $row['command'],
            'description'       => isset($row['description']) ? (string) $row['description'] : null,
            'active'            => (bool)   $row['active'],
            'notify_on_failure' => (bool)   $row['notify_on_failure'],
            'created_at'        => (string) $row['created_at'],
            'tags'              => $tags,
            'targets'           => $targets,
        ];
    }
}
