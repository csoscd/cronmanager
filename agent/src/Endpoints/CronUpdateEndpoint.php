<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – CronUpdateEndpoint
 *
 * Handles PUT /crons/{id} requests to update an existing cron job.
 *
 * The endpoint applies PATCH semantics: any field present in the request body
 * overwrites the stored value; absent fields retain their existing values.
 *
 * Crontab synchronisation:
 *   - active → active   : syncEntries() (schedule, user or targets may have changed)
 *   - active → inactive : removeAllEntries()
 *   - inactive → active : addEntriesForTargets()
 *   - inactive → inactive : no crontab change
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
 * Class CronUpdateEndpoint
 *
 * Handles PUT /crons/{id} API requests.
 *
 * Response on success (HTTP 200): the updated job record.
 * Response on not found (HTTP 404): `{"error": "Not Found", ...}`.
 * Response on validation error (HTTP 422): `{"error": "Validation failed", "fields": {...}}`.
 * Response on server error (HTTP 500): generic error message.
 */
final class CronUpdateEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * CronUpdateEndpoint constructor.
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
     * Handle an incoming PUT /crons/{id} request.
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $jobId = isset($params['id']) ? (int) $params['id'] : 0;

        $this->logger->debug('CronUpdateEndpoint: handling PUT /crons/{id}', ['job_id' => $jobId]);

        if ($jobId <= 0) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Invalid or missing job ID.',
                'code'    => 400,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 1. Fetch existing job
        // ------------------------------------------------------------------

        $existing = $this->fetchJobRaw($jobId);

        if ($existing === null) {
            $this->logger->info('CronUpdateEndpoint: job not found', ['job_id' => $jobId]);
            jsonResponse(404, [
                'error'   => 'Not Found',
                'message' => sprintf('Cron job with ID %d does not exist.', $jobId),
                'code'    => 404,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 2. Parse JSON body
        // ------------------------------------------------------------------

        $rawBody = (string) file_get_contents('php://input');
        $body    = json_decode($rawBody, true);

        if (!is_array($body)) {
            $this->logger->warning('CronUpdateEndpoint: invalid JSON body');
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Request body must be valid JSON.',
                'code'    => 400,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 3. Merge provided fields with existing values
        // ------------------------------------------------------------------

        $merged = $this->mergeFields($existing, $body);

        // ------------------------------------------------------------------
        // 4. Validate merged values
        // ------------------------------------------------------------------

        $errors = $this->validate($merged);

        if ($errors !== []) {
            jsonResponse(422, [
                'error'  => 'Validation failed',
                'fields' => $errors,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 5. Database transaction + crontab sync
        // ------------------------------------------------------------------

        $wasActive = (bool) $existing['active'];
        $isActive  = (bool) $merged['active'];

        /** @var string[] $mergedTargets */
        $mergedTargets = $merged['targets'];

        // Derive legacy columns from targets for backward compat wrapper calls
        [$executionMode, $sshHost] = $this->deriveLegacyFields($mergedTargets);

        try {
            $this->pdo->beginTransaction();

            // UPDATE cronjobs
            $stmt = $this->pdo->prepare(
                'UPDATE cronjobs
                 SET linux_user = :linux_user,
                     schedule = :schedule,
                     command = :command,
                     description = :description,
                     active = :active,
                     notify_on_failure = :notify_on_failure,
                     execution_limit_seconds = :execution_limit_seconds,
                     auto_kill_on_limit = :auto_kill_on_limit,
                     execution_mode = :execution_mode,
                     ssh_host = :ssh_host
                 WHERE id = :id'
            );
            $stmt->execute([
                ':linux_user'              => $merged['linux_user'],
                ':schedule'                => $merged['schedule'],
                ':command'                 => $merged['command'],
                ':description'             => $merged['description'],
                ':active'                  => (int) $isActive,
                ':notify_on_failure'       => (int) $merged['notify_on_failure'],
                ':execution_limit_seconds' => $merged['execution_limit_seconds'],
                ':auto_kill_on_limit'      => (int) ($merged['auto_kill_on_limit'] ?? false),
                ':execution_mode'          => $executionMode,
                ':ssh_host'                => $sshHost,
                ':id'                      => $jobId,
            ]);

            // Sync tags
            $this->pdo->prepare('DELETE FROM cronjob_tags WHERE cronjob_id = :id')
                       ->execute([':id' => $jobId]);
            $tags = isset($merged['tags']) && is_array($merged['tags']) ? $merged['tags'] : [];
            $this->syncTags($jobId, $tags);

            // Sync targets
            $this->pdo->prepare('DELETE FROM job_targets WHERE job_id = :id')
                       ->execute([':id' => $jobId]);
            $this->syncTargets($jobId, $mergedTargets);

            // Sync crontab
            $this->syncCrontab(
                wasActive:   $wasActive,
                isActive:    $isActive,
                oldUser:     (string) $existing['linux_user'],
                newUser:     (string) $merged['linux_user'],
                jobId:       $jobId,
                schedule:    (string) $merged['schedule'],
                targets:     $mergedTargets,
            );

            $this->pdo->commit();

            $this->logger->info('CronUpdateEndpoint: job updated', [
                'job_id'     => $jobId,
                'linux_user' => $merged['linux_user'],
                'active'     => $isActive,
                'targets'    => $mergedTargets,
            ]);

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->logger->error('CronUpdateEndpoint: failed to update job', [
                'job_id'  => $jobId,
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to update cron job.',
                'code'    => 500,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 6. Return updated job
        // ------------------------------------------------------------------

        $job = $this->fetchJob($jobId);

        jsonResponse(200, $job);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Merge request body fields with the existing job record.
     *
     * @param array<string, mixed> $existing Existing job record.
     * @param array<string, mixed> $body     Decoded JSON request body.
     *
     * @return array<string, mixed> Merged field set.
     */
    private function mergeFields(array $existing, array $body): array
    {
        $tagsRaw      = isset($existing['tags'])    && $existing['tags']    !== null ? (string) $existing['tags']    : '';
        $targetsRaw   = isset($existing['targets']) && $existing['targets'] !== null ? (string) $existing['targets'] : '';

        $existingTags    = $tagsRaw    !== '' ? explode(',', $tagsRaw)    : [];
        $existingTargets = $targetsRaw !== '' ? explode(',', $targetsRaw) : $this->legacyTargets($existing);

        $mergedTargets = array_key_exists('targets', $body)
            ? $this->normaliseTargets($body['targets'])
            : $existingTargets;

        // execution_limit_seconds: null means "no limit"; keep existing value if not provided
        $existingLimit = isset($existing['execution_limit_seconds']) && $existing['execution_limit_seconds'] !== null
            ? (int) $existing['execution_limit_seconds']
            : null;
        if (array_key_exists('execution_limit_seconds', $body)) {
            $mergedLimit = (is_int($body['execution_limit_seconds']) && $body['execution_limit_seconds'] > 0)
                ? $body['execution_limit_seconds']
                : null;
        } else {
            $mergedLimit = $existingLimit;
        }

        return [
            'linux_user'               => array_key_exists('linux_user',        $body) ? $body['linux_user']        : $existing['linux_user'],
            'schedule'                 => array_key_exists('schedule',          $body) ? $body['schedule']          : $existing['schedule'],
            'command'                  => array_key_exists('command',           $body) ? $body['command']           : $existing['command'],
            'description'              => array_key_exists('description',       $body) ? $body['description']       : $existing['description'],
            'active'                   => array_key_exists('active',            $body) ? $body['active']            : (bool) $existing['active'],
            'notify_on_failure'        => array_key_exists('notify_on_failure', $body) ? $body['notify_on_failure'] : (bool) $existing['notify_on_failure'],
            'execution_limit_seconds'  => $mergedLimit,
            'auto_kill_on_limit'       => array_key_exists('auto_kill_on_limit', $body)
                ? (bool) $body['auto_kill_on_limit']
                : (bool) ($existing['auto_kill_on_limit'] ?? false),
            'targets'                  => $mergedTargets,
            'tags'                     => array_key_exists('tags', $body) ? $body['tags'] : $existingTags,
        ];
    }

    /**
     * Derive a targets array from legacy execution_mode / ssh_host columns.
     * Used when a job has no rows in job_targets yet (unmigrated job).
     *
     * @param array<string, mixed> $row Raw database row.
     *
     * @return string[]
     */
    private function legacyTargets(array $row): array
    {
        $mode    = (string) ($row['execution_mode'] ?? 'local');
        $sshHost = isset($row['ssh_host']) ? trim((string) $row['ssh_host']) : '';

        if ($mode === 'remote' && $sshHost !== '') {
            return [$sshHost];
        }

        return ['local'];
    }

    /**
     * Validate the merged field set.
     *
     * @param array<string, mixed> $data Merged field values.
     *
     * @return array<string, string> Map of field name → error message.
     */
    private function validate(array $data): array
    {
        $errors = [];

        // linux_user
        if (!is_string($data['linux_user']) || $data['linux_user'] === '') {
            $errors['linux_user'] = 'Field is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string) $data['linux_user'])) {
            $errors['linux_user'] = 'Must contain only [a-zA-Z0-9_-] and be at most 64 characters.';
        }

        // schedule
        if (!is_string($data['schedule']) || $data['schedule'] === '') {
            $errors['schedule'] = 'Field is required.';
        } elseif (strlen((string) $data['schedule']) > 100) {
            $errors['schedule'] = 'Must not exceed 100 characters.';
        } elseif (!$this->isValidCronSchedule((string) $data['schedule'])) {
            $errors['schedule'] = 'Must be a valid cron expression (5 or 6 space-separated fields).';
        }

        // command
        if (!is_string($data['command']) || trim((string) $data['command']) === '') {
            $errors['command'] = 'Field is required and must be a non-empty string.';
        }

        // description
        if (isset($data['description']) && $data['description'] !== null) {
            if (!is_string($data['description'])) {
                $errors['description'] = 'Must be a string.';
            } elseif (strlen((string) $data['description']) > 255) {
                $errors['description'] = 'Must not exceed 255 characters.';
            }
        }

        // active
        if (!is_bool($data['active'])) {
            $errors['active'] = 'Must be a boolean.';
        }

        // notify_on_failure
        if (!is_bool($data['notify_on_failure'])) {
            $errors['notify_on_failure'] = 'Must be a boolean.';
        }

        // targets
        if (!is_array($data['targets']) || $data['targets'] === []) {
            $errors['targets'] = 'Must be a non-empty array of target strings.';
        } else {
            foreach ($data['targets'] as $i => $target) {
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

        // tags
        if (isset($data['tags']) && is_array($data['tags'])) {
            foreach ($data['tags'] as $i => $tag) {
                if (!is_string($tag) || trim($tag) === '') {
                    $errors['tags'] = sprintf('Element at index %d must be a non-empty string.', $i);
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Synchronise the system crontab based on the active state transition.
     *
     * @param bool     $wasActive Whether the job was active before the update.
     * @param bool     $isActive  Whether the job is active after the update.
     * @param string   $oldUser   Linux user before the update.
     * @param string   $newUser   Linux user after the update.
     * @param int      $jobId     Cronmanager job ID.
     * @param string   $schedule  New (or unchanged) cron schedule.
     * @param string[] $targets   New (or unchanged) targets list.
     *
     * @return void
     */
    private function syncCrontab(
        bool   $wasActive,
        bool   $isActive,
        string $oldUser,
        string $newUser,
        int    $jobId,
        string $schedule,
        array  $targets,
    ): void {
        if ($wasActive && $isActive) {
            // Remove from old user (handles user change and target changes)
            $this->crontabManager->removeAllEntries($oldUser, $jobId);
            // Add to new user with updated targets
            $this->crontabManager->addEntriesForTargets($newUser, $jobId, $schedule, $this->wrapperScript, $targets);
        } elseif ($wasActive && !$isActive) {
            $this->crontabManager->removeAllEntries($oldUser, $jobId);
        } elseif (!$wasActive && $isActive) {
            $this->crontabManager->addEntriesForTargets($newUser, $jobId, $schedule, $this->wrapperScript, $targets);
        }
        // was inactive, still inactive: no crontab change needed
    }

    /**
     * Normalise the targets array: trim, filter empty, deduplicate.
     * Defaults to ['local'] when empty.
     *
     * @param mixed $raw Raw value from the request body.
     *
     * @return string[]
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
     * Derive legacy execution_mode and ssh_host from targets for backward compat.
     *
     * @param string[] $targets
     *
     * @return array{0: string, 1: string|null}
     */
    private function deriveLegacyFields(array $targets): array
    {
        if (count($targets) === 1 && $targets[0] !== 'local') {
            return ['remote', $targets[0]];
        }

        return ['local', null];
    }

    /**
     * Resolve or create tags and associate them with the given job.
     *
     * @param int      $jobId Job ID.
     * @param string[] $tags  Array of tag names.
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
     * Fetch a raw database row for the given job ID (includes targets string).
     *
     * @param int $jobId Job ID to look up.
     *
     * @return array<string, mixed>|null Raw row or null.
     */
    private function fetchJobRaw(int $jobId): ?array
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
                j.execution_mode,
                j.ssh_host,
                j.created_at,
                GROUP_CONCAT(DISTINCT t.name    ORDER BY t.name    SEPARATOR ',') AS tags,
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

        return ($row !== false) ? $row : null;
    }

    /**
     * Fetch a job record in the normalised API response format.
     *
     * @param int $jobId Job ID to fetch.
     *
     * @return array<string, mixed> Normalised job record.
     */
    private function fetchJob(int $jobId): array
    {
        $row = $this->fetchJobRaw($jobId);

        if ($row === null) {
            return ['id' => $jobId];
        }

        $tagsRaw    = isset($row['tags'])    && $row['tags']    !== null ? (string) $row['tags']    : '';
        $targetsRaw = isset($row['targets']) && $row['targets'] !== null ? (string) $row['targets'] : '';

        $tags    = $tagsRaw    !== '' ? explode(',', $tagsRaw)    : [];
        $targets = $targetsRaw !== '' ? explode(',', $targetsRaw) : $this->legacyTargets($row);

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
            'targets'                  => $targets,
            'execution_mode'           => (string) ($row['execution_mode'] ?? 'local'),
            'ssh_host'                 => isset($row['ssh_host']) ? (string) $row['ssh_host'] : null,
            'created_at'               => (string) $row['created_at'],
            'tags'                     => $tags,
        ];
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
}
