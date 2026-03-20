<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – CrontabManager
 *
 * Manages per-user crontab entries on the Linux host. The MariaDB database
 * is the single source of truth for all job metadata; the system crontab is
 * treated as a projection of the active jobs stored there.
 *
 * Each cronmanager-managed crontab entry consists of two consecutive lines:
 *
 *   # cronmanager:{jobId}:{target}
 *   {schedule} {wrapperScript} {jobId} {target}
 *
 * A job may have multiple targets; each target produces its own pair of lines.
 * All entries share the same schedule and run in parallel.
 *
 * Legacy entries (written before multi-target support) use the older format:
 *
 *   # cronmanager:{jobId}
 *   {schedule} {wrapperScript} {jobId}
 *
 * Both formats are handled transparently by the remove / scan methods.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Cron;

use InvalidArgumentException;
use Monolog\Logger;
use RuntimeException;

/**
 * Class CrontabManager
 *
 * Provides CRUD operations for cronmanager-managed crontab entries.
 * Uses `crontab -u {user}` commands executed via proc_open / shell_exec.
 */
final class CrontabManager
{
    // -------------------------------------------------------------------------
    // Marker prefix used in the crontab comment lines
    // -------------------------------------------------------------------------

    /** @var string Prefix for the marker comment line written above each managed entry */
    private const MARKER_PREFIX = '# cronmanager:';

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * CrontabManager constructor.
     *
     * @param Logger $logger        Monolog logger instance for all crontab operations.
     * @param string $wrapperScript Absolute path to the cron-wrapper shell script
     *                              (from config key cron.wrapper_script).
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly string $wrapperScript,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return all raw crontab lines for the given Linux user.
     *
     * Runs `crontab -u {user} -l` and returns all non-empty, non-cronmanager
     * lines as an array. Returns an empty array when the user has no crontab
     * (crontab exits non-zero in that case, which is silently ignored).
     *
     * @param string $user Linux user name.
     *
     * @return string[] Array of raw crontab lines (no trailing newlines).
     *
     * @throws InvalidArgumentException When $user contains disallowed characters.
     */
    public function getRawLines(string $user): array
    {
        $this->validateUser($user);

        $output = $this->readCrontab($user);

        $lines = [];
        foreach (explode("\n", $output) as $line) {
            // Skip blank lines and cronmanager marker comments
            if (trim($line) === '') {
                continue;
            }
            if (str_starts_with($line, self::MARKER_PREFIX)) {
                continue;
            }
            $lines[] = $line;
        }

        $this->logger->debug('CrontabManager: read raw lines', [
            'user'  => $user,
            'count' => count($lines),
        ]);

        return $lines;
    }

    /**
     * Return all Linux users that currently have at least one cronmanager-managed
     * crontab entry.
     *
     * Iterates over all system users with UID >= 1000 (standard non-system users)
     * plus root (UID 0), reads each user's crontab, and checks for the
     * cronmanager marker comment.
     *
     * @return string[] Array of Linux user names.
     */
    public function getManagedUsers(): array
    {
        $candidates = $this->getCandidateUsers();
        $managed    = [];

        foreach ($candidates as $user) {
            try {
                $raw = $this->readCrontab($user);
                if (str_contains($raw, self::MARKER_PREFIX)) {
                    $managed[] = $user;
                }
            } catch (\Throwable $e) {
                // Non-fatal: user may not have a crontab or crontab may be unreadable
                $this->logger->debug('CrontabManager: skipping user during managed scan', [
                    'user'  => $user,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->debug('CrontabManager: getManagedUsers result', [
            'managed' => $managed,
        ]);

        return $managed;
    }

    /**
     * Return all Linux users (root + UID >= 1000) that have any crontab entries,
     * regardless of whether those entries are managed by Cronmanager.
     *
     * Used by the import page to populate the user selector with candidates
     * that actually have something to import.
     *
     * @return string[] Sorted array of Linux user names.
     */
    public function getUsersWithCrontab(): array
    {
        $candidates = $this->getCandidateUsers();
        $withCrontab = [];

        foreach ($candidates as $user) {
            try {
                $raw = trim($this->readCrontab($user));
                if ($raw !== '') {
                    $withCrontab[] = $user;
                }
            } catch (\Throwable $e) {
                $this->logger->debug('CrontabManager: skipping user during crontab scan', [
                    'user'  => $user,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        sort($withCrontab);

        $this->logger->debug('CrontabManager: getUsersWithCrontab result', [
            'users' => $withCrontab,
        ]);

        return $withCrontab;
    }

    /**
     * Comment out an unmanaged crontab entry that has just been imported as a
     * Cronmanager-managed job.
     *
     * Scans the user's crontab for a line whose normalised content matches
     * "{$originalSchedule} {$originalCommand}" and replaces it with a comment
     * of the form:
     *
     *   # replaced by cronmanager:{jobId} | {original line}
     *
     * Only the first matching line is commented out. Lines that are already
     * Cronmanager marker comments are never touched.
     *
     * @param string $user             Linux user name.
     * @param string $originalSchedule Schedule field(s) from the original entry.
     * @param string $originalCommand  Command from the original entry.
     * @param int    $jobId            Cronmanager job ID that replaced this entry.
     *
     * @return void
     *
     * @throws InvalidArgumentException When $user contains disallowed characters.
     * @throws RuntimeException         When crontab read/write fails.
     */
    public function commentOutUnmanagedEntry(
        string $user,
        string $originalSchedule,
        string $originalCommand,
        int    $jobId,
    ): void {
        $this->validateUser($user);

        $raw   = $this->readCrontab($user);
        $lines = explode("\n", $raw);

        // Normalise the target: collapse all whitespace to single spaces
        $target  = preg_replace('/\s+/', ' ', trim($originalSchedule . ' ' . $originalCommand));
        $replaced = false;
        $result   = [];

        foreach ($lines as $line) {
            // Never touch existing Cronmanager marker lines
            if (str_starts_with(trim($line), self::MARKER_PREFIX)) {
                $result[] = $line;
                continue;
            }

            // Normalise this line for comparison
            $normalised = preg_replace('/\s+/', ' ', trim($line));

            // Only comment out the first matching unmanaged line
            if (!$replaced && $normalised === $target) {
                $result[]  = '# replaced by cronmanager:' . $jobId . ' | ' . trim($line);
                $replaced  = true;

                $this->logger->info('CrontabManager: commented out replaced entry', [
                    'user'   => $user,
                    'job_id' => $jobId,
                    'line'   => trim($line),
                ]);
            } else {
                $result[] = $line;
            }
        }

        if (!$replaced) {
            // The original line was not found – log a warning but do not fail
            $this->logger->warning('CrontabManager: original entry not found for commenting out', [
                'user'    => $user,
                'job_id'  => $jobId,
                'target'  => $target,
            ]);
            return;
        }

        $this->writeCrontab($user, implode("\n", $result));
    }

    /**
     * Add crontab entries for all given targets of a single job.
     *
     * Appends one pair of lines per target to the user's crontab:
     *
     *   # cronmanager:{jobId}:{target}
     *   {schedule} {wrapperScript} {jobId} {target}
     *
     * All entries are written in a single crontab write operation.
     * Does NOT deduplicate; call {@see removeAllEntries()} first if needed.
     *
     * @param string   $user          Linux user name.
     * @param int      $jobId         Cronmanager job ID.
     * @param string   $schedule      Cron schedule expression.
     * @param string   $wrapperScript Absolute path to the wrapper script.
     * @param string[] $targets       List of targets ('local' or SSH host aliases).
     *
     * @return void
     *
     * @throws InvalidArgumentException When $user contains disallowed characters.
     * @throws RuntimeException         When writing the crontab fails.
     */
    public function addEntriesForTargets(
        string $user,
        int    $jobId,
        string $schedule,
        string $wrapperScript,
        array  $targets,
    ): void {
        $this->validateUser($user);

        $targets = array_values(array_filter(array_map('trim', $targets)));

        if ($targets === []) {
            $this->logger->warning('CrontabManager: addEntriesForTargets called with empty targets list', [
                'user'   => $user,
                'job_id' => $jobId,
            ]);
            return;
        }

        $existing = rtrim($this->readCrontab($user));
        if ($existing !== '') {
            $existing .= "\n";
        }

        $newEntries = '';
        foreach ($targets as $target) {
            $newEntries .= sprintf(
                "%s%d:%s\n%s %s %d %s\n",
                self::MARKER_PREFIX,
                $jobId,
                $target,
                $schedule,
                $wrapperScript,
                $jobId,
                $target,
            );
        }

        $this->writeCrontab($user, $existing . $newEntries);

        $this->logger->info('CrontabManager: entries added for targets', [
            'user'     => $user,
            'job_id'   => $jobId,
            'schedule' => $schedule,
            'targets'  => $targets,
        ]);
    }

    /**
     * Remove ALL cronmanager-managed crontab entries for the given job ID.
     *
     * Handles both the legacy single-target format (`# cronmanager:{jobId}`) and
     * the current multi-target format (`# cronmanager:{jobId}:{target}`).
     * Silently succeeds when no entries are found.
     *
     * @param string $user  Linux user name.
     * @param int    $jobId Cronmanager job ID.
     *
     * @return void
     *
     * @throws InvalidArgumentException When $user contains disallowed characters.
     * @throws RuntimeException         When writing the updated crontab fails.
     */
    public function removeAllEntries(string $user, int $jobId): void
    {
        $this->validateUser($user);

        $raw          = $this->readCrontab($user);
        $markerPrefix = self::MARKER_PREFIX . $jobId;

        if (!str_contains($raw, $markerPrefix)) {
            $this->logger->debug('CrontabManager: removeAllEntries – no entries found (no-op)', [
                'user'   => $user,
                'job_id' => $jobId,
            ]);
            return;
        }

        $lines    = explode("\n", $raw);
        $filtered = [];
        $skipNext = false;

        foreach ($lines as $line) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }

            $trimmed = trim($line);

            // Match legacy "# cronmanager:42" or new "# cronmanager:42:target"
            if ($trimmed === $markerPrefix || str_starts_with($trimmed, $markerPrefix . ':')) {
                $skipNext = true;
                continue;
            }

            $filtered[] = $line;
        }

        $this->writeCrontab($user, implode("\n", $filtered));

        $this->logger->info('CrontabManager: all entries removed', [
            'user'   => $user,
            'job_id' => $jobId,
        ]);
    }

    /**
     * Synchronise crontab entries: remove all existing entries for the job and
     * re-add them for the provided targets.
     *
     * Convenience wrapper around {@see removeAllEntries()} + {@see addEntriesForTargets()}.
     *
     * @param string   $user          Linux user name.
     * @param int      $jobId         Cronmanager job ID.
     * @param string   $schedule      (New) cron schedule expression.
     * @param string   $wrapperScript Absolute path to the wrapper script.
     * @param string[] $targets       New target list.
     *
     * @return void
     *
     * @throws InvalidArgumentException When $user contains disallowed characters.
     * @throws RuntimeException         When crontab read/write fails.
     */
    public function syncEntries(
        string $user,
        int    $jobId,
        string $schedule,
        string $wrapperScript,
        array  $targets,
    ): void {
        $this->removeAllEntries($user, $jobId);
        $this->addEntriesForTargets($user, $jobId, $schedule, $wrapperScript, $targets);

        $this->logger->info('CrontabManager: entries synced', [
            'user'     => $user,
            'job_id'   => $jobId,
            'schedule' => $schedule,
            'targets'  => $targets,
        ]);
    }

    /**
     * Return all crontab entries for a Linux user that are NOT managed by
     * Cronmanager (i.e. have no "# cronmanager:{id}" marker).
     *
     * Parses each unmanaged line into schedule + command parts.
     * Skips blank lines, comment lines, environment variable assignments,
     * and any lines that are part of a cronmanager-managed block.
     *
     * @param  string  $user  Linux user whose crontab to read.
     * @return array<int, array{schedule: string, command: string}>
     * @throws InvalidArgumentException On invalid username.
     */
    public function getUnmanagedEntries(string $user): array
    {
        $this->validateUser($user);

        $raw   = $this->readCrontab($user);
        $lines = explode("\n", $raw);

        $entries  = [];
        $skipNext = false; // true when the next cron command line is managed

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // A cronmanager marker comment: the NEXT non-blank/non-comment line
            // is the managed cron command – mark it to be skipped.
            if (preg_match('/^#\s*cronmanager:\d+/', $trimmed)) {
                $skipNext = true;
                continue;
            }

            // Skip blank lines
            if ($trimmed === '') {
                continue;
            }

            // Skip pure comment lines (not cronmanager markers, caught above)
            if (str_starts_with($trimmed, '#')) {
                continue;
            }

            // Skip environment variable assignments (VAR=value)
            if (preg_match('/^[A-Z_][A-Z0-9_]*\s*=/i', $trimmed)) {
                continue;
            }

            // If this line is the managed command following a marker, skip it
            // and reset the flag so the next unrelated line is evaluated normally.
            if ($skipNext) {
                $skipNext = false;
                continue;
            }

            // Parse the remaining line as an unmanaged crontab entry.
            if (str_starts_with($trimmed, '@')) {
                // Special schedule syntax: @reboot, @daily, etc.
                $tokens   = preg_split('/\s+/', $trimmed, 2);
                $schedule = $tokens[0] ?? '';
                $command  = isset($tokens[1]) ? trim($tokens[1]) : '';

                if ($schedule === '' || $command === '') {
                    // Malformed – skip
                    continue;
                }
            } else {
                // Standard 5-field schedule: min hour dom mon dow command…
                $tokens = preg_split('/\s+/', $trimmed);

                if (count($tokens) < 6) {
                    // Fewer than 5 schedule fields + 1 command token – malformed
                    continue;
                }

                $schedule = implode(' ', array_slice($tokens, 0, 5));
                $command  = implode(' ', array_slice($tokens, 5));
            }

            $entries[] = [
                'schedule' => $schedule,
                'command'  => $command,
            ];
        }

        $this->logger->debug('CrontabManager: getUnmanagedEntries result', [
            'user'  => $user,
            'count' => count($entries),
        ]);

        return $entries;
    }

    /**
     * Return all Cronmanager-managed crontab entries for a user, indexed by
     * job ID and target.
     *
     * Scans the crontab for marker comment lines and returns a two-level map:
     *
     *   [jobId => [target => true, ...], ...]
     *
     * For the current multi-target format (`# cronmanager:{jobId}:{target}`)
     * the target is stored as-is (e.g. "local", "ssh-alias").
     * For the legacy single-target format (`# cronmanager:{jobId}`) the entry
     * is stored under the special key `__legacy__`.
     *
     * One `crontab -l` call is issued per invocation, making it efficient for
     * bulk per-target consistency checks across many jobs.
     *
     * @param string $user Linux user name.
     *
     * @return array<int, array<string, bool>> Map of jobId → [target → true].
     *
     * @throws InvalidArgumentException When $user contains disallowed characters.
     */
    public function getManagedEntries(string $user): array
    {
        $this->validateUser($user);

        $raw     = $this->readCrontab($user);
        $entries = [];

        // Match "# cronmanager:42" (legacy) and "# cronmanager:42:target"
        if (preg_match_all('/^#\s*cronmanager:(\d+)(?::(.+))?$/m', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $jobId  = (int) $match[1];
                $target = (isset($match[2]) && $match[2] !== '') ? trim($match[2]) : '__legacy__';

                if (!isset($entries[$jobId])) {
                    $entries[$jobId] = [];
                }

                $entries[$jobId][$target] = true;
            }
        }

        $this->logger->debug('CrontabManager: getManagedEntries result', [
            'user'       => $user,
            'job_count'  => count($entries),
        ]);

        return $entries;
    }

    /**
     * Check whether a cronmanager-managed entry for the given job ID exists in
     * the given user's crontab.
     *
     * @param string $user  Linux user name.
     * @param int    $jobId Cronmanager job ID.
     *
     * @return bool True when the marker comment is found in the crontab.
     *
     * @throws InvalidArgumentException When $user contains disallowed characters.
     */
    public function entryExists(string $user, int $jobId): bool
    {
        $this->validateUser($user);

        $raw          = $this->readCrontab($user);
        $markerPrefix = self::MARKER_PREFIX . $jobId;

        // Matches legacy "# cronmanager:42" and new "# cronmanager:42:target"
        return str_contains($raw, $markerPrefix);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate that a Linux user name contains only safe characters.
     *
     * Allowed pattern: `[a-zA-Z0-9_-]` (matches most real-world Unix user names
     * and prevents shell injection via the user name parameter).
     *
     * @param string $user Linux user name to validate.
     *
     * @return void
     *
     * @throws InvalidArgumentException When $user is empty or contains forbidden characters.
     */
    private function validateUser(string $user): void
    {
        if ($user === '') {
            throw new InvalidArgumentException('Linux user name must not be empty.');
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $user)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid Linux user name "%s": only [a-zA-Z0-9_-] are allowed.',
                    $user
                )
            );
        }
    }

    /**
     * Read the crontab for the given user via `crontab -u {user} -l`.
     *
     * Stderr is suppressed because crontab exits non-zero and prints to stderr
     * when the user has no crontab yet – this is expected and not an error.
     *
     * @param string $user Linux user name (caller must validate before calling).
     *
     * @return string Raw crontab content (may be empty).
     */
    private function readCrontab(string $user): string
    {
        $command = sprintf('crontab -u %s -l 2>/dev/null', escapeshellarg($user));
        $output  = shell_exec($command);

        return ($output === null) ? '' : $output;
    }

    /**
     * Write the given content as the new crontab for the specified user.
     *
     * Uses `proc_open` to pipe the content into `crontab -u {user} -` so that
     * no temporary file needs to be created (avoids race conditions and requires
     * no writable temp directory).
     *
     * @param string $user    Linux user name (caller must validate before calling).
     * @param string $content Full crontab content to write.
     *
     * @return void
     *
     * @throws RuntimeException When the crontab process cannot be opened or exits
     *                          with a non-zero status code.
     */
    private function writeCrontab(string $user, string $content): void
    {
        $command = sprintf('crontab -u %s -', escapeshellarg($user));

        $descriptorSpec = [
            0 => ['pipe', 'r'],   // stdin  – we write the crontab content here
            1 => ['pipe', 'w'],   // stdout – ignored
            2 => ['pipe', 'w'],   // stderr – captured for error reporting
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if ($process === false) {
            throw new RuntimeException(
                sprintf('CrontabManager: failed to open crontab process for user "%s".', $user)
            );
        }

        // Write the crontab content to stdin and close the pipe
        fwrite($pipes[0], $content);
        fclose($pipes[0]);

        // Capture stdout / stderr
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->logger->error('CrontabManager: crontab write failed', [
                'user'      => $user,
                'exit_code' => $exitCode,
                'stderr'    => trim((string) $stderr),
            ]);

            throw new RuntimeException(
                sprintf(
                    'CrontabManager: crontab write exited with code %d for user "%s". Stderr: %s',
                    $exitCode,
                    $user,
                    trim((string) $stderr)
                )
            );
        }

        $this->logger->debug('CrontabManager: crontab written successfully', [
            'user' => $user,
        ]);
    }

    /**
     * Build the list of Linux user names to scan when looking for managed entries.
     *
     * Reads /etc/passwd and returns names for:
     *   - root (UID 0)
     *   - all users with UID >= 1000 (typical non-system users on Linux)
     *
     * @return string[] Array of Linux user names.
     */
    private function getCandidateUsers(): array
    {
        $users    = [];
        $passwdFh = fopen('/etc/passwd', 'r');

        if ($passwdFh === false) {
            $this->logger->warning('CrontabManager: cannot open /etc/passwd for reading');
            return [];
        }

        while (($line = fgets($passwdFh)) !== false) {
            $line   = rtrim($line);
            $fields = explode(':', $line);

            if (count($fields) < 4) {
                continue;
            }

            $username = $fields[0];
            $uid      = (int) $fields[2];

            // Include root (UID 0) and normal users (UID >= 1000)
            if ($uid === 0 || $uid >= 1000) {
                // Validate the user name to avoid processing malformed entries
                if (preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
                    $users[] = $username;
                }
            }
        }

        fclose($passwdFh);

        return $users;
    }
}
