<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Swimlane Controller
 *
 * Renders the schedule swimlane view: a time-of-day lane diagram showing
 * when each managed cron job is planned to fire within a selected time window
 * and day-of-week filter.
 *
 * Job schedule data is pre-computed server-side via dragonmantank/cron-expression
 * so the client receives ready-to-render fire-time arrays – no JS cron parsing
 * is required.  All interactive filtering (hour range, day, tag, target) is
 * then handled client-side without additional HTTP round-trips.
 *
 * Routes handled:
 *   GET /swimlane
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

use Cron\CronExpression;
use Lorisleiva\CronTranslator\CronTranslator;

/**
 * Class SwimlaneController
 *
 * Fetches all managed cron jobs, pre-computes their weekly fire-time patterns
 * using dragonmantank/cron-expression, enriches each entry with a human-
 * readable schedule description via lorisleiva/cron-translator, then renders
 * the swimlane template with the result as an inline JSON payload.
 */
class SwimlaneController extends BaseController
{
    /**
     * Stable reference week start date.
     *
     * 2024-01-01 is a Monday.  Using a fixed date makes the per-weekday
     * fire-time patterns reproducible regardless of when the page is requested.
     * The seven days starting here cover every JS day-of-week value (0–6).
     */
    private const REFERENCE_WEEK_START = '2024-01-01';

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Display the schedule swimlane view.
     *
     * Fetches the full job list and all tags from the host agent, pre-computes
     * weekly fire-time patterns for every job, and renders the swimlane
     * template.  The template receives the job data as a JSON-encoded string
     * which the client-side renderer consumes directly.
     *
     * @param array<string,string> $params Path parameters (unused for this route).
     *
     * @return void
     */
    public function index(array $params): void
    {
        $agent = $this->agentClient();

        try {
            $jobsResponse = $agent->get('/crons');
            $tagsResponse = $agent->get('/tags');
        } catch (\RuntimeException $e) {
            $this->logger->error('SwimlaneController::index: agent request failed', [
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/swimlane');
            return;
        }

        // Normalise agent responses (may return {data:[...]} or plain array)
        $rawJobs = $jobsResponse['data'] ?? $jobsResponse;
        $tags    = $tagsResponse['data']  ?? $tagsResponse;

        if (!is_array($rawJobs)) {
            $rawJobs = [];
        }
        if (!is_array($tags)) {
            $tags = [];
        }

        // Build unique target list across all jobs for the filter dropdown
        $allTargets = [];
        foreach ($rawJobs as $job) {
            foreach ((array) ($job['targets'] ?? ['local']) as $target) {
                $target = (string) $target;
                if ($target !== '' && !in_array($target, $allTargets, strict: true)) {
                    $allTargets[] = $target;
                }
            }
        }
        sort($allTargets);

        // Pre-compute schedule data for each active job
        $swimlaneJobs = [];
        foreach ($rawJobs as $job) {
            $cronExpr = trim((string) ($job['schedule'] ?? ''));
            if ($cronExpr === '') {
                continue;
            }

            $schedule = $this->computeSchedule($cronExpr);

            // Skip jobs whose expression could not be parsed
            if (empty($schedule['allDays']) && empty($schedule['byDay'])) {
                continue;
            }

            // Prefer the human-readable description as the display name;
            // fall back to the raw command string if no description is set.
            $description = trim((string) ($job['description'] ?? ''));
            $command     = trim((string) ($job['command']     ?? ''));

            $swimlaneJobs[] = [
                'id'         => $job['id']       ?? 0,
                'name'       => $description !== '' ? $description : $command,
                'command'    => $command,
                'cron'       => $cronExpr,
                'cronHuman'  => $this->translateCron($cronExpr),
                'linuxUser'  => (string) ($job['linux_user'] ?? ''),
                'tags'       => array_values((array) ($job['tags']    ?? [])),
                'targets'    => array_values((array) ($job['targets'] ?? ['local'])),
                'active'     => (bool) ($job['active'] ?? true),
                'byDay'      => $schedule['byDay'],
                'allDays'    => $schedule['allDays'],
                'activeDays' => $schedule['activeDays'],
            ];
        }

        try {
            $jobsJson = json_encode(
                $swimlaneJobs,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            $this->logger->error('SwimlaneController: JSON encoding failed', [
                'message' => $e->getMessage(),
            ]);
            $jobsJson = '[]';
        }

        $this->render('swimlane.php', $this->translator()->t('swimlane_title'), [
            'swimlaneJobsJson' => $jobsJson,
            'tags'             => $tags,
            'allTargets'       => $allTargets,
        ], '/swimlane');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Compute the weekly fire-time pattern for a cron expression.
     *
     * Iterates over all seven days of a fixed reference week (2024-01-01 Mon
     * through 2024-01-07 Sun) and calls getMultipleRunDates() for each day,
     * capping at 1 440 occurrences per day (theoretical maximum of one firing
     * per minute for 24 hours).
     *
     * The JS day-of-week convention (0 = Sun … 6 = Sat) is used as key in
     * the returned byDay map so the client can pass it directly to getDay().
     *
     * Reference-week Monday offset mapping (2024-01-01 = Mon = JS dow 1):
     *   JS dow 0 (Sun) → 2024-01-07, offset = 6
     *   JS dow 1 (Mon) → 2024-01-01, offset = 0
     *   JS dow 2 (Tue) → 2024-01-02, offset = 1
     *   ...
     *   JS dow 6 (Sat) → 2024-01-06, offset = 5
     * Formula: offset = (dow + 6) % 7
     *
     * @param string $cronExpr Five-field standard cron expression.
     *
     * @return array{
     *   byDay: array<int, list<array{h:int,m:int}>>,
     *   allDays: list<array{h:int,m:int}>,
     *   activeDays: list<int>
     * }
     */
    private function computeSchedule(string $cronExpr): array
    {
        try {
            $cron = new CronExpression($cronExpr);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('SwimlaneController: invalid cron expression', [
                'expression' => $cronExpr,
                'error'      => $e->getMessage(),
            ]);
            return ['byDay' => [], 'allDays' => [], 'activeDays' => []];
        }

        $weekStart = new \DateTime(self::REFERENCE_WEEK_START . ' 00:00:00');

        /** @var array<int, list<array{h:int,m:int}>> $byDay */
        $byDay    = [];
        /** @var array<int, array{h:int,m:int}> $allTimes */
        $allTimes = [];

        for ($dow = 0; $dow < 7; $dow++) {
            // Map JS day-of-week to offset from reference Monday (2024-01-01)
            $offset   = ($dow + 6) % 7;
            $dayStart = (clone $weekStart)->modify("+{$offset} days");
            $dayEnd   = (clone $dayStart)->modify('+1 day');
            // Start one second before midnight so exact 00:00 fires are included
            $before   = (clone $dayStart)->modify('-1 second');

            try {
                // 1440 = 24 h × 60 min – enough for the most frequent cron schedules
                $occurrences = $cron->getMultipleRunDates(1440, $before, false, false);
            } catch (\Throwable $e) {
                $this->logger->debug('SwimlaneController: getMultipleRunDates failed', [
                    'expression' => $cronExpr,
                    'dow'        => $dow,
                    'error'      => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($occurrences as $dt) {
                // Stop as soon as we leave the current day window
                if ($dt >= $dayEnd) {
                    break;
                }

                $h = (int) $dt->format('H');
                $m = (int) $dt->format('i');

                $byDay[$dow][] = ['h' => $h, 'm' => $m];

                // Accumulate unique time-of-day values across all active days
                $minuteKey           = $h * 60 + $m;
                $allTimes[$minuteKey] = ['h' => $h, 'm' => $m];
            }
        }

        // Sort by minute-of-day so the client can iterate in chronological order
        ksort($allTimes);

        return [
            'byDay'      => $byDay,
            'allDays'    => array_values($allTimes),
            'activeDays' => array_keys($byDay),
        ];
    }

    /**
     * Return a human-readable description of a cron expression.
     *
     * Uses lorisleiva/cron-translator with the English locale.  Falls back to
     * the raw expression string if translation fails for any reason (unsupported
     * syntax, invalid expression, etc.).
     *
     * @param string $cronExpr Five-field cron expression.
     *
     * @return string Human-readable description in English.
     */
    private function translateCron(string $cronExpr): string
    {
        try {
            return CronTranslator::translate($cronExpr, 'en');
        } catch (\Throwable $e) {
            // Non-standard expressions (e.g. @reboot) are not supported – return raw
            return $cronExpr;
        }
    }
}
