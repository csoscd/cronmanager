<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MaintenanceWindowRepository
 *
 * Data-access layer for the maintenance_windows table.
 *
 * Provides CRUD operations and the core business-logic method
 * {@see isTargetInMaintenance()} which determines whether the current moment
 * falls inside any active maintenance window for a given target.
 *
 * Window evaluation algorithm:
 *   For each active window with a matching target the most-recent start time
 *   is computed via dragonmantank/cron-expression.  The window is considered
 *   active when:
 *
 *     window_start  ≤  now  <  window_start + duration_minutes
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Repository;

use Cron\CronExpression;
use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class MaintenanceWindowRepository
 *
 * Manages persistence and evaluation of maintenance windows.
 */
final class MaintenanceWindowRepository
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /**
     * Reserved target name for agent-level maintenance windows.
     *
     * A maintenance window with this target blocks ALL job executions
     * regardless of per-job run_in_maintenance settings.  It models
     * host-wide maintenance (e.g. VM suspend/resume cycles).
     */
    public const AGENT_TARGET = '_agent_';
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
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return all maintenance windows, optionally filtered by target.
     *
     * @param string|null $target Filter by exact target name; NULL returns all.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PDOException On database errors.
     */
    public function findAll(?string $target = null): array
    {
        if ($target !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT id, target, cron_schedule, duration_minutes, description, active, created_at
                   FROM maintenance_windows
                  WHERE target = :target
                  ORDER BY target, id'
            );
            $stmt->execute([':target' => $target]);
        } else {
            $stmt = $this->pdo->query(
                'SELECT id, target, cron_schedule, duration_minutes, description, active, created_at
                   FROM maintenance_windows
                  ORDER BY target, id'
            );
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return a single maintenance window by primary key.
     *
     * @param int $id Window ID.
     *
     * @return array<string, mixed>|null Row or null when not found.
     *
     * @throws PDOException On database errors.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, target, cron_schedule, duration_minutes, description, active, created_at
               FROM maintenance_windows
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert a new maintenance window and return the new primary key.
     *
     * @param string      $target          Target name ("local" or SSH alias).
     * @param string      $cronSchedule    5-field cron expression.
     * @param int         $durationMinutes Window length in minutes.
     * @param string|null $description     Optional human-readable description.
     * @param bool        $active          Whether the window is enabled.
     *
     * @return int Newly created window ID.
     *
     * @throws PDOException On database errors.
     */
    public function create(
        string  $target,
        string  $cronSchedule,
        int     $durationMinutes,
        ?string $description,
        bool    $active = true,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO maintenance_windows (target, cron_schedule, duration_minutes, description, active)
             VALUES (:target, :cron_schedule, :duration_minutes, :description, :active)'
        );
        $stmt->execute([
            ':target'           => $target,
            ':cron_schedule'    => $cronSchedule,
            ':duration_minutes' => $durationMinutes,
            ':description'      => $description,
            ':active'           => $active ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update an existing maintenance window.
     *
     * @param int         $id              Window ID to update.
     * @param string      $target          Target name.
     * @param string      $cronSchedule    5-field cron expression.
     * @param int         $durationMinutes Window length in minutes.
     * @param string|null $description     Optional description.
     * @param bool        $active          Whether the window is enabled.
     *
     * @return bool True when a row was actually updated, false when not found.
     *
     * @throws PDOException On database errors.
     */
    public function update(
        int     $id,
        string  $target,
        string  $cronSchedule,
        int     $durationMinutes,
        ?string $description,
        bool    $active,
    ): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE maintenance_windows
                SET target           = :target,
                    cron_schedule    = :cron_schedule,
                    duration_minutes = :duration_minutes,
                    description      = :description,
                    active           = :active
              WHERE id = :id'
        );
        $stmt->execute([
            ':target'           => $target,
            ':cron_schedule'    => $cronSchedule,
            ':duration_minutes' => $durationMinutes,
            ':description'      => $description,
            ':active'           => $active ? 1 : 0,
            ':id'               => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a maintenance window by primary key.
     *
     * @param int $id Window ID.
     *
     * @return bool True when a row was deleted, false when not found.
     *
     * @throws PDOException On database errors.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM maintenance_windows WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    // -------------------------------------------------------------------------
    // Business logic
    // -------------------------------------------------------------------------

    /**
     * Determine whether the current moment is inside an active maintenance
     * window for the given target.
     *
     * Returns true when at least one active window for the target is currently
     * open.  A window is open when:
     *
     *   last_start  ≤  now  <  last_start + duration_minutes
     *
     * @param string $target Target to check ("local" or SSH alias).
     *
     * @return bool True when in maintenance, false otherwise.
     */
    public function isTargetInMaintenance(string $target): bool
    {
        $windows  = $this->findAll($target);
        $tzName   = date_default_timezone_get();
        $tz       = new \DateTimeZone($tzName);
        $now      = new \DateTimeImmutable('now', $tz);
        $nowStr   = $now->format('Y-m-d H:i:s');

        foreach ($windows as $window) {
            if (!(bool) $window['active']) {
                continue;
            }

            try {
                $cron      = new CronExpression((string) $window['cron_schedule']);
                $lastStart = $cron->getPreviousRunDate($nowStr, 0, true, $tzName);
                $lastStart = \DateTimeImmutable::createFromMutable($lastStart)->setTimezone($tz);

                $windowEnd = $lastStart->modify(
                    sprintf('+%d minutes', (int) $window['duration_minutes'])
                );

                if ($now >= $lastStart && $now < $windowEnd) {
                    $this->logger->debug('MaintenanceWindowRepository: target is in maintenance', [
                        'target'      => $target,
                        'window_id'   => $window['id'],
                        'window_start' => $lastStart->format('Y-m-d H:i:s'),
                        'window_end'   => $windowEnd->format('Y-m-d H:i:s'),
                    ]);
                    return true;
                }
            } catch (\Throwable $e) {
                // Invalid cron expression – skip this window and log a warning
                $this->logger->warning('MaintenanceWindowRepository: could not evaluate window', [
                    'window_id'    => $window['id'],
                    'cron_schedule' => $window['cron_schedule'],
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        return false;
    }

    /**
     * Determine whether the current moment is inside an active agent-level
     * maintenance window (target = AGENT_TARGET).
     *
     * When true, all job executions must be skipped regardless of per-job
     * run_in_maintenance settings.
     *
     * @return bool True when the agent itself is in maintenance.
     */
    public function isAgentInMaintenance(): bool
    {
        return $this->isTargetInMaintenance(self::AGENT_TARGET);
    }

    /**
     * Return the next N run times for a cron schedule as ISO 8601 strings.
     *
     * Used by the conflict-check endpoint to show the upcoming executions that
     * overlap with maintenance windows.
     *
     * @param string $cronSchedule 5-field cron expression.
     * @param int    $count        Number of upcoming times to return (default 5).
     *
     * @return array<int, string> List of 'Y-m-d H:i:s' datetime strings in the system timezone.
     */
    public function getNextRunTimes(string $cronSchedule, int $count = 5): array
    {
        try {
            $cron   = new CronExpression($cronSchedule);
            $times  = [];
            $tzName = date_default_timezone_get();
            $tz     = new \DateTimeZone($tzName);
            $refStr = (new \DateTime('now', $tz))->format('Y-m-d H:i:s');

            for ($i = 0; $i < $count; $i++) {
                $next    = $cron->getNextRunDate($refStr, $i, false, $tzName);
                $times[] = $next->setTimezone($tz)->format('Y-m-d H:i:s');
            }

            return $times;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Check whether a cron schedule has any of its next N occurrences landing
     * inside an active maintenance window for the given target.
     *
     * Returns an array of conflict descriptions (one entry per colliding run
     * time).  An empty array means no conflict was detected.
     *
     * @param string $jobSchedule 5-field cron expression of the job to check.
     * @param string $target      Target to check windows for.
     * @param int    $lookAhead   Number of upcoming job runs to check.
     *
     * @return array<int, array{run_time: string, window_id: int, window_start: string, window_end: string}>
     */
    public function detectConflicts(string $jobSchedule, string $target, int $lookAhead = 10): array
    {
        $windows = $this->findAll($target);
        $windows = array_filter($windows, fn($w) => (bool) $w['active']);

        if ($windows === []) {
            return [];
        }

        $nextRuns = $this->getNextRunTimes($jobSchedule, $lookAhead);
        $conflicts = [];

        $tzName = date_default_timezone_get();
        $tz     = new \DateTimeZone($tzName);

        foreach ($nextRuns as $runTimeStr) {
            // $runTimeStr is 'Y-m-d H:i:s' in the system timezone (no offset embedded)
            $runTime = new \DateTimeImmutable($runTimeStr, $tz);

            foreach ($windows as $window) {
                try {
                    $cron      = new CronExpression((string) $window['cron_schedule']);
                    $lastStart = $cron->getPreviousRunDate($runTimeStr, 0, true, $tzName);
                    $lastStart = \DateTimeImmutable::createFromMutable($lastStart)->setTimezone($tz);
                    $windowEnd  = $lastStart->modify(
                        sprintf('+%d minutes', (int) $window['duration_minutes'])
                    );

                    if ($runTime >= $lastStart && $runTime < $windowEnd) {
                        $conflicts[] = [
                            'run_time'     => $runTimeStr,
                            'window_id'    => (int) $window['id'],
                            'window_start' => $lastStart->format(\DateTimeInterface::ATOM),
                            'window_end'   => $windowEnd->format(\DateTimeInterface::ATOM),
                        ];
                    }
                } catch (\Throwable) {
                    // Skip windows with unparseable schedules
                }
            }
        }

        return $conflicts;
    }
}
