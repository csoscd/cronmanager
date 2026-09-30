<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – DependencyCheckEndpoint
 *
 * Handles GET /crons/{id}/dependency-check requests.
 *
 * Used by cron-wrapper.sh immediately after /execution/start for jobs with a
 * 'requires' dependency.  Returns whether the dependency condition is currently
 * satisfied so the wrapper can decide to run the job or skip it with exit -7.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "satisfied": true,
 *   "reason": "Predecessor last ran with exit code 0 within the time window"
 * }
 * ```
 * or
 * ```json
 * {
 *   "satisfied": false,
 *   "reason": "Predecessor has not run with a matching exit code within the last 60 minutes"
 * }
 * ```
 *
 * For jobs with no dependency or a 'triggered_by' dependency, satisfied is
 * always true (these jobs are not gated by a runtime check).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Repository\DependencyRepository;
use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class DependencyCheckEndpoint
 *
 * Handles GET /crons/{id}/dependency-check API requests.
 */
final class DependencyCheckEndpoint
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** Default time window when max_age_minutes is NULL (legacy / not yet configured). */
    private const DEFAULT_MAX_AGE_MINUTES = 60;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param PDO                  $pdo
     * @param Logger               $logger
     * @param DependencyRepository $deps
     */
    public function __construct(
        private readonly PDO                  $pdo,
        private readonly Logger               $logger,
        private readonly DependencyRepository $deps,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming GET /crons/{id}/dependency-check request.
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $rawId = $params['id'] ?? '';

        if ($rawId === '' || !ctype_digit($rawId) || (int) $rawId <= 0) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Path parameter {id} must be a positive integer.',
                'code'    => 400,
            ]);
            return;
        }

        $jobId = (int) $rawId;

        $this->logger->debug('DependencyCheckEndpoint: GET /crons/{id}/dependency-check', [
            'job_id' => $jobId,
        ]);

        try {
            $dep = $this->deps->findByJobId($jobId);
        } catch (PDOException $e) {
            $this->logger->error('DependencyCheckEndpoint: database error', [
                'job_id'  => $jobId,
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, ['error' => 'Internal Server Error', 'message' => 'DB error.', 'code' => 500]);
            return;
        }

        // No dependency or triggered_by: always satisfied (no runtime gate)
        if ($dep === null || $dep['type'] !== 'requires') {
            jsonResponse(200, ['satisfied' => true, 'reason' => 'No requires-dependency configured.']);
            return;
        }

        $predecessorId = (int) $dep['predecessor_id'];
        $exitCodes     = (string) $dep['exit_codes'];
        // 0 = no age limit (only exit code matters); NULL = use legacy default
        $rawAge        = $dep['max_age_minutes'] !== null ? (int) $dep['max_age_minutes'] : null;
        $maxAge        = ($rawAge !== null) ? $rawAge : self::DEFAULT_MAX_AGE_MINUTES;

        try {
            [$satisfied, $reason] = $this->checkRequires($predecessorId, $exitCodes, $maxAge);
        } catch (PDOException $e) {
            $this->logger->error('DependencyCheckEndpoint: error checking predecessor', [
                'job_id'         => $jobId,
                'predecessor_id' => $predecessorId,
                'message'        => $e->getMessage(),
            ]);
            // Fail open: if we cannot check, allow the job to run
            jsonResponse(200, [
                'satisfied' => true,
                'reason'    => 'Dependency check failed (DB error) – proceeding.',
            ]);
            return;
        }

        $this->logger->debug('DependencyCheckEndpoint: result', [
            'job_id'         => $jobId,
            'predecessor_id' => $predecessorId,
            'satisfied'      => $satisfied,
            'reason'         => $reason,
        ]);

        jsonResponse(200, ['satisfied' => $satisfied, 'reason' => $reason]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether the 'requires' dependency condition is met.
     *
     * Looks for the most recent finished, non-sentinel execution of the
     * predecessor whose exit code matches the configured list.  When maxAge
     * is 0 the time constraint is omitted (any past execution qualifies).
     * Maintenance sentinels (exit_code -4) and dependency skips (exit_code -7)
     * are excluded from the check.
     *
     * @param int    $predecessorId Job ID of the required predecessor.
     * @param string $exitCodes     Comma-separated list of qualifying exit codes.
     * @param int    $maxAge        Maximum age in minutes; 0 = no age constraint.
     *
     * @return array{0: bool, 1: string} [satisfied, reason]
     *
     * @throws PDOException On database errors.
     */
    private function checkRequires(int $predecessorId, string $exitCodes, int $maxAge): array
    {
        // When maxAge = 0 we skip the time constraint entirely
        $timeClause = $maxAge > 0
            ? 'AND finished_at >= DATE_SUB(NOW(), INTERVAL :max_age MINUTE)'
            : '';

        $sql = "SELECT id, exit_code, finished_at
                  FROM execution_log
                 WHERE cronjob_id  = :predecessor_id
                   AND finished_at IS NOT NULL
                   AND exit_code   NOT IN (-4, -7)
                   {$timeClause}
                 ORDER BY finished_at DESC
                 LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $binds = [':predecessor_id' => $predecessorId];
        if ($maxAge > 0) {
            $binds[':max_age'] = $maxAge;
        }
        $stmt->execute($binds);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $ageDesc = $maxAge > 0
            ? sprintf('within the last %d minute(s)', $maxAge)
            : 'at any time';

        if ($row === false) {
            return [
                false,
                sprintf(
                    'Predecessor (job %d) has not finished with a real exit code %s.',
                    $predecessorId,
                    $ageDesc,
                ),
            ];
        }

        $actualCode = (int) $row['exit_code'];

        if (!DependencyRepository::exitCodeMatches($actualCode, $exitCodes)) {
            return [
                false,
                sprintf(
                    'Predecessor (job %d) last ran with exit code %d, which is not in the required list [%s].',
                    $predecessorId,
                    $actualCode,
                    $exitCodes,
                ),
            ];
        }

        return [
            true,
            sprintf(
                'Predecessor (job %d) last ran with exit code %d %s.',
                $predecessorId,
                $actualCode,
                $ageDesc,
            ),
        ];
    }
}
