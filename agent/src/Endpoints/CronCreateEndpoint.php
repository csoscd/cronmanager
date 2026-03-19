<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – CronCreateEndpoint
 *
 * Handles POST /crons requests to create a new cron job.
 *
 * The endpoint:
 *   1. Parses and validates the JSON request body.
 *   2. Inserts the job into the `cronjobs` table inside a transaction.
 *   3. Resolves or creates tags and links them via `cronjob_tags`.
 *   4. Inserts the execution targets into `job_targets`.
 *   5. If the job is active, registers one crontab entry per target via CrontabManager.
 *   6. Returns the created job (HTTP 201) in the same format used by CronListEndpoint.
 *
 * Expected JSON request body:
 * ```json
 * {
 *   "linux_user":        "deploy",
 *   "schedule":          "0,5,10,15,20,25,30,35,40,45,50,55 * * * *",
 *   "command":           "df -H",
 *   "description":       "Disk usage check",
 *   "active":            true,
 *   "notify_on_failure": true,
 *   "targets":           ["local", "host1", "host2"],
 *   "tags":              ["monitoring"]
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

use Cronmanager\Agent\Cron\CrontabManager;
use Cron\CronExpression;
use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class CronCreateEndpoint
 *
 * Handles POST /crons API requests.
 *
 * Response on success (HTTP 201): the created job record.
 * Response on validation error (HTTP 422): `{"error": "Validation failed", "fields": {...}}`.
 * Response on server error (HTTP 500): generic error message.
 */
final class CronCreateEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * CronCreateEndpoint constructor.
     *
     * @param PDO            $pdo            Active PDO database connection.
     * @param Logger         $logger         Monolog logger instance.
     * @param CrontabManager $crontabManager CrontabManager for crontab operations.
     * @param string         $wrapperScript  Path to the cron-wrapper shell script.
     */
    public function __construct(
        private readonly PDO            $pdo,
        private readonly Logger         $logger,
        private readonly CrontabManager $crontabManager,
        private readonly string         $wrapperScript,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming POST /crons request.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $this->logger->debug('CronCreateEndpoint: handling POST /crons');

        // ------------------------------------------------------------------
        // 1. Parse JSON body
        // ------------------------------------------------------------------

        $rawBody = (string) file_get_contents('php://input');
        $body    = json_decode($rawBody, true);

        if (!is_array($body)) {
            $this->logger->warning('CronCreateEndpoint: invalid JSON body');
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Request body must be valid JSON.',
                'code'    => 400,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 2. Validate fields
        // ------------------------------------------------------------------

        $errors = $this->validate($body);

        if ($errors !== []) {
            jsonResponse(422, [
                'error'  => 'Validation failed',
                'fields' => $errors,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 3. Prepare values
        // ------------------------------------------------------------------

        $linuxUser       = (string)  $body['linux_user'];
        $schedule        = (string)  $body['schedule'];
        $command         = (string)  $body['command'];
        $description     = isset($body['description'])       ? (string) $body['description']       : null;
        $active          = isset($body['active'])            ? (bool)   $body['active']            : true;
        $notifyOnFailure = isset($body['notify_on_failure'])  ? (bool)   $body['notify_on_failure']  : true;
        $targets         = $this->normaliseTargets($body['targets'] ?? ['local']);
        $tags            = isset($body['tags']) && is_array($body['tags']) ? $body['tags'] : [];

        // Derive legacy columns from targets for backward compatibility with
        // old wrapper invocations that carry no target argument.
        [$executionMode, $sshHost] = $this->deriveLegacyFields($targets);

        // Optional: original schedule/command when importing an unmanaged entry.
        $originalSchedule = isset($body['original_schedule']) && is_string($body['original_schedule'])
            ? trim($body['original_schedule']) : null;
        $originalCommand  = isset($body['original_command']) && is_string($body['original_command'])
            ? trim($body['original_command'])  : null;

        // ------------------------------------------------------------------
        // 4. Database transaction
        // ------------------------------------------------------------------

        try {
            $this->pdo->beginTransaction();

            // INSERT the job
            $stmt = $this->pdo->prepare(
                'INSERT INTO cronjobs
                    (linux_user, schedule, command, description, active, notify_on_failure, execution_mode, ssh_host)
                 VALUES
                    (:linux_user, :schedule, :command, :description, :active, :notify_on_failure, :execution_mode, :ssh_host)'
            );
            $stmt->execute([
                ':linux_user'        => $linuxUser,
                ':schedule'          => $schedule,
                ':command'           => $command,
                ':description'       => $description,
                ':active'            => (int) $active,
                ':notify_on_failure' => (int) $notifyOnFailure,
                ':execution_mode'    => $executionMode,
                ':ssh_host'          => $sshHost,
            ]);

            $jobId = (int) $this->pdo->lastInsertId();

            // Resolve/create tags and link them
            $this->syncTags($jobId, $tags);

            // Insert execution targets
            $this->syncTargets($jobId, $targets);

            // Register crontab entries if the job is active (one per target)
            if ($active) {
                $this->crontabManager->addEntriesForTargets(
                    $linuxUser,
                    $jobId,
                    $schedule,
                    $this->wrapperScript,
                    $targets,
                );
            }

            // If this job was imported from an unmanaged entry, comment out the
            // original crontab line so it is not executed twice.
            if ($originalSchedule !== null && $originalCommand !== null) {
                $this->crontabManager->commentOutUnmanagedEntry(
                    $linuxUser,
                    $originalSchedule,
                    $originalCommand,
                    $jobId,
                );
            }

            $this->pdo->commit();

            $this->logger->info('CronCreateEndpoint: job created', [
                'job_id'     => $jobId,
                'linux_user' => $linuxUser,
                'active'     => $active,
                'targets'    => $targets,
            ]);

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->logger->error('CronCreateEndpoint: failed to create job', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to create cron job.',
                'code'    => 500,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 5. Return the created job
        // ------------------------------------------------------------------

        $job = $this->fetchJob($jobId);

        jsonResponse(201, $job);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate the request body fields.
     *
     * @param array<string, mixed> $body Decoded JSON request body.
     *
     * @return array<string, string> Map of field name → error message. Empty when valid.
     */
    private function validate(array $body): array
    {
        $errors = [];

        // linux_user: required, [a-zA-Z0-9_-], max 64 chars
        if (!isset($body['linux_user']) || !is_string($body['linux_user']) || $body['linux_user'] === '') {
            $errors['linux_user'] = 'Field is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $body['linux_user'])) {
            $errors['linux_user'] = 'Must contain only [a-zA-Z0-9_-] and be at most 64 characters.';
        }

        // schedule: required, 5 or 6 space-separated cron fields, max 100 chars
        if (!isset($body['schedule']) || !is_string($body['schedule']) || $body['schedule'] === '') {
            $errors['schedule'] = 'Field is required.';
        } elseif (strlen($body['schedule']) > 100) {
            $errors['schedule'] = 'Must not exceed 100 characters.';
        } elseif (!$this->isValidCronSchedule($body['schedule'])) {
            $errors['schedule'] = 'Must be a valid cron expression (5 or 6 space-separated fields).';
        }

        // command: required, non-empty string
        if (!isset($body['command']) || !is_string($body['command']) || trim($body['command']) === '') {
            $errors['command'] = 'Field is required and must be a non-empty string.';
        }

        // description: optional, max 255 chars
        if (isset($body['description'])) {
            if (!is_string($body['description'])) {
                $errors['description'] = 'Must be a string.';
            } elseif (strlen($body['description']) > 255) {
                $errors['description'] = 'Must not exceed 255 characters.';
            }
        }

        // active: optional bool
        if (isset($body['active']) && !is_bool($body['active'])) {
            $errors['active'] = 'Must be a boolean.';
        }

        // notify_on_failure: optional bool
        if (isset($body['notify_on_failure']) && !is_bool($body['notify_on_failure'])) {
            $errors['notify_on_failure'] = 'Must be a boolean.';
        }

        // targets: optional array, at least one non-empty string, each max 255 chars
        if (isset($body['targets'])) {
            if (!is_array($body['targets']) || $body['targets'] === []) {
                $errors['targets'] = 'Must be a non-empty array of target strings.';
            } else {
                foreach ($body['targets'] as $i => $target) {
                    if (!is_string($target) || trim($target) === '') {
                        $errors['targets'] = sprintf('Element at index %d must be a non-empty string.', $i);
                        break;
                    }
                    if (strlen($target) > 255) {
                        $errors['targets'] = sprintf('Element at index %d must not exceed 255 characters.', $i);
                        break;
                    }
                }
            }
        }

        // tags: optional array of strings
        if (isset($body['tags'])) {
            if (!is_array($body['tags'])) {
                $errors['tags'] = 'Must be an array of strings.';
            } else {
                foreach ($body['tags'] as $i => $tag) {
                    if (!is_string($tag) || trim($tag) === '') {
                        $errors['tags'] = sprintf('Element at index %d must be a non-empty string.', $i);
                        break;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Normalise the targets array: trim whitespace, filter empty, deduplicate.
     * Defaults to ['local'] when the result would be empty.
     *
     * @param mixed $raw Raw value from the request body.
     *
     * @return string[] Normalised targets list.
     */
    private function normaliseTargets(mixed $raw): array
    {
        if (!is_array($raw)) {
            return ['local'];
        }

        $targets = array_values(array_unique(
            array_filter(
                array_map(static fn($t): string => trim((string) $t), $raw),
                static fn(string $t): bool => $t !== '',
            )
        ));

        return $targets !== [] ? $targets : ['local'];
    }

    /**
     * Derive the legacy execution_mode and ssh_host columns from the targets list.
     *
     * These columns are kept for backward compatibility with older wrapper
     * invocations that carry no target argument on the command line.
     *
     *   – Single target 'local'       → ('local', null)
     *   – Single non-local target     → ('remote', that_target)
     *   – Multiple targets (any mix)  → ('local', null)  [new wrapper uses arg]
     *
     * @param string[] $targets Normalised targets list.
     *
     * @return array{0: string, 1: string|null}  [execution_mode, ssh_host]
     */
    private function deriveLegacyFields(array $targets): array
    {
        if (count($targets) === 1 && $targets[0] !== 'local') {
            return ['remote', $targets[0]];
        }

        return ['local', null];
    }

    /**
     * Validate a cron schedule expression using dragonmantank/cron-expression.
     *
     * Accepts standard 5-field cron expressions (including named day/month
     * abbreviations such as "sat", "jan") as well as the @ shorthand forms
     * supported by the library (@daily, @weekly, @monthly, @yearly, @annually,
     * @midnight, @hourly, @reboot).
     *
     * @param string $schedule Cron expression to validate.
     *
     * @return bool True when the expression is valid.
     */
    private function isValidCronSchedule(string $schedule): bool
    {
        $trimmed = trim($schedule);

        // @reboot is a valid vixie-cron directive but is not a time expression
        // and is therefore not supported by dragonmantank/cron-expression.
        if (strtolower($trimmed) === '@reboot') {
            return true;
        }

        try {
            new CronExpression($trimmed);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Resolve or create tags and associate them with the given job.
     *
     * @param int      $jobId Job ID to associate tags with.
     * @param string[] $tags  Array of tag name strings.
     *
     * @return void
     *
     * @throws PDOException On database errors.
     */
    private function syncTags(int $jobId, array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $insertTag  = $this->pdo->prepare('INSERT IGNORE INTO tags (name) VALUES (:name)');
        $selectTag  = $this->pdo->prepare('SELECT id FROM tags WHERE name = :name');
        $insertLink = $this->pdo->prepare(
            'INSERT IGNORE INTO cronjob_tags (cronjob_id, tag_id) VALUES (:job_id, :tag_id)'
        );

        foreach ($tags as $tagName) {
            $tagName = trim((string) $tagName);

            if ($tagName === '') {
                continue;
            }

            $insertTag->execute([':name' => $tagName]);
            $selectTag->execute([':name' => $tagName]);
            $tagId = (int) $selectTag->fetchColumn();

            if ($tagId > 0) {
                $insertLink->execute([':job_id' => $jobId, ':tag_id' => $tagId]);
            }
        }
    }

    /**
     * Insert execution targets for the given job into `job_targets`.
     *
     * @param int      $jobId   Job ID.
     * @param string[] $targets Normalised target list.
     *
     * @return void
     *
     * @throws PDOException On database errors.
     */
    private function syncTargets(int $jobId, array $targets): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO job_targets (job_id, target) VALUES (:job_id, :target)'
        );

        foreach ($targets as $target) {
            $stmt->execute([':job_id' => $jobId, ':target' => $target]);
        }
    }

    /**
     * Fetch a single job by ID in the same format returned by CronListEndpoint.
     *
     * @param int $jobId The job ID to fetch.
     *
     * @return array<string, mixed> Job record including tags and targets.
     *
     * @throws PDOException On database errors.
     */
    private function fetchJob(int $jobId): array
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
                GROUP_CONCAT(DISTINCT t.name  ORDER BY t.name   SEPARATOR ',') AS tags,
                GROUP_CONCAT(DISTINCT jt.target ORDER BY jt.target SEPARATOR ',') AS targets
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
            return ['id' => $jobId];
        }

        $tagsRaw    = isset($row['tags'])    && $row['tags']    !== null ? (string) $row['tags']    : '';
        $targetsRaw = isset($row['targets']) && $row['targets'] !== null ? (string) $row['targets'] : '';

        $tags    = $tagsRaw    !== '' ? explode(',', $tagsRaw)    : [];
        $targets = $targetsRaw !== '' ? explode(',', $targetsRaw) : ['local'];

        return [
            'id'                => (int)    $row['id'],
            'linux_user'        => (string) $row['linux_user'],
            'schedule'          => (string) $row['schedule'],
            'command'           => (string) $row['command'],
            'description'       => isset($row['description']) ? (string) $row['description'] : null,
            'active'            => (bool)   $row['active'],
            'notify_on_failure' => (bool)   $row['notify_on_failure'],
            'targets'           => $targets,
            // Legacy fields kept for backward compatibility
            'execution_mode'    => (string) ($row['execution_mode'] ?? 'local'),
            'ssh_host'          => isset($row['ssh_host']) ? (string) $row['ssh_host'] : null,
            'created_at'        => (string) $row['created_at'],
            'tags'              => $tags,
        ];
    }
}
