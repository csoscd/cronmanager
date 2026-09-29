<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – DependencyRepository
 *
 * Manages read/write access to the `job_dependencies` table and provides
 * cycle-detection for dependency chains.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Repository;

use PDO;

/**
 * Class DependencyRepository
 *
 * Single point of access for all job_dependencies operations.
 */
final class DependencyRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // -------------------------------------------------------------------------
    // Read helpers
    // -------------------------------------------------------------------------

    /**
     * Return the dependency row for a given job, or null if none exists.
     *
     * @param int $jobId The dependent job ID.
     *
     * @return array<string, mixed>|null Row with keys: id, job_id, predecessor_id, type, exit_codes, max_age_minutes, created_at
     */
    public function findByJobId(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, job_id, predecessor_id, type, exit_codes, max_age_minutes, created_at
               FROM job_dependencies
              WHERE job_id = :job_id'
        );
        $stmt->execute([':job_id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return all jobs that declare the given job as their predecessor.
     *
     * @param int $predecessorId The predecessor job ID.
     *
     * @return array<int, array<string, mixed>> List of dependency rows.
     */
    public function findByPredecessorId(int $predecessorId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT jd.id, jd.job_id, jd.predecessor_id, jd.type, jd.exit_codes, jd.max_age_minutes,
                    c.description AS job_description
               FROM job_dependencies jd
               JOIN cronjobs c ON c.id = jd.job_id
              WHERE jd.predecessor_id = :predecessor_id'
        );
        $stmt->execute([':predecessor_id' => $predecessorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return all active 'triggered_by' jobs that depend on the given predecessor.
     *
     * Used by ExecutionFinishEndpoint to trigger downstream jobs.
     *
     * @param int    $predecessorId The predecessor job ID.
     * @param int    $exitCode      The exit code the predecessor finished with.
     *
     * @return array<int, array<string, mixed>> Rows for jobs whose exit_codes list contains $exitCode.
     */
    public function findTriggeredByJobs(int $predecessorId, int $exitCode): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT jd.job_id, jd.exit_codes
               FROM job_dependencies jd
               JOIN cronjobs c ON c.id = jd.job_id
              WHERE jd.predecessor_id = :predecessor_id
                AND jd.type = 'triggered_by'
                AND c.active = 1"
        );
        $stmt->execute([':predecessor_id' => $predecessorId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_filter($rows, static function (array $row) use ($exitCode): bool {
            return self::exitCodeMatches($exitCode, (string) $row['exit_codes']);
        }));
    }

    // -------------------------------------------------------------------------
    // Write helpers
    // -------------------------------------------------------------------------

    /**
     * Insert or replace the dependency row for a job.
     *
     * @param int         $jobId          The dependent job ID.
     * @param int         $predecessorId  The predecessor job ID.
     * @param string      $type           'requires' or 'triggered_by'.
     * @param string      $exitCodes      Comma-separated exit codes, e.g. "0,2".
     * @param int|null    $maxAgeMinutes  Only for 'requires'; NULL defaults to 60 at check time.
     *
     * @return void
     */
    public function save(
        int    $jobId,
        int    $predecessorId,
        string $type,
        string $exitCodes,
        ?int   $maxAgeMinutes,
    ): void {
        // DELETE + INSERT is simpler than REPLACE INTO because REPLACE assigns a new PK.
        $this->pdo->prepare('DELETE FROM job_dependencies WHERE job_id = :job_id')
            ->execute([':job_id' => $jobId]);

        $this->pdo->prepare(
            'INSERT INTO job_dependencies (job_id, predecessor_id, type, exit_codes, max_age_minutes)
             VALUES (:job_id, :predecessor_id, :type, :exit_codes, :max_age_minutes)'
        )->execute([
            ':job_id'         => $jobId,
            ':predecessor_id' => $predecessorId,
            ':type'           => $type,
            ':exit_codes'     => $exitCodes,
            ':max_age_minutes'=> $maxAgeMinutes,
        ]);
    }

    /**
     * Remove the dependency row for a job (if any).
     *
     * @param int $jobId The dependent job ID.
     *
     * @return void
     */
    public function delete(int $jobId): void
    {
        $this->pdo->prepare('DELETE FROM job_dependencies WHERE job_id = :job_id')
            ->execute([':job_id' => $jobId]);
    }

    // -------------------------------------------------------------------------
    // Cycle detection
    // -------------------------------------------------------------------------

    /**
     * Detect whether assigning $predecessorId as the predecessor of $jobId
     * would introduce a cycle.
     *
     * Walks the dependency chain upward from $predecessorId. If $jobId is
     * encountered during traversal, a cycle exists.
     *
     * A chain depth limit of 100 guards against runaway loops in corrupt data.
     *
     * @param int $jobId         The job being saved.
     * @param int $predecessorId The proposed predecessor.
     *
     * @return bool True when adding this dependency would create a cycle.
     */
    public function detectCycle(int $jobId, int $predecessorId): bool
    {
        $visited = [];
        $current = $predecessorId;
        $limit   = 100;

        while ($limit-- > 0) {
            if ($current === $jobId) {
                return true;
            }

            if (isset($visited[$current])) {
                // Already seen this node without finding jobId — no cycle through this path.
                break;
            }

            $visited[$current] = true;

            $row = $this->findByJobId($current);
            if ($row === null) {
                break;
            }

            $current = (int) $row['predecessor_id'];
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Static helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether $exitCode is contained in a comma-separated list of codes.
     *
     * @param int    $exitCode  The exit code to look for.
     * @param string $exitCodes Comma-separated list, e.g. "0,2,5".
     *
     * @return bool
     */
    public static function exitCodeMatches(int $exitCode, string $exitCodes): bool
    {
        $trimmed = trim($exitCodes);
        if ($trimmed === '') {
            return false;
        }
        foreach (explode(',', $trimmed) as $code) {
            $code = trim($code);
            if ($code !== '' && (int) $code === $exitCode) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate a comma-separated exit-code string (e.g. "0", "0,2", "1,2,3").
     *
     * Each segment must be an integer. Returns an error message or null on success.
     *
     * @param string $exitCodes Raw input.
     *
     * @return string|null Null when valid, error message otherwise.
     */
    public static function validateExitCodes(string $exitCodes): ?string
    {
        $trimmed = trim($exitCodes);

        if ($trimmed === '') {
            return 'Must not be empty.';
        }

        foreach (explode(',', $trimmed) as $part) {
            $part = trim($part);
            if ($part === '') {
                return 'Empty token found; check for consecutive commas.';
            }
            if (!preg_match('/^\d+$/', $part)) {
                return sprintf('"%s" is not a valid exit code (must be a non-negative integer 0–255).', $part);
            }
            $int = (int) $part;
            if ($int < 0 || $int > 255) {
                return sprintf('Exit code %d is out of range (must be 0–255).', $int);
            }
        }

        return null;
    }
}
