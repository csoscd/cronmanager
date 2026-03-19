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
 * Performance:
 *   Fire-time patterns and human-readable translations are cached in APCu
 *   (shared memory) keyed by cron expression string.  Because the patterns are
 *   computed against a fixed reference week (2024-01-01), they are invariant
 *   and can be cached for a full day without any staleness risk.  Agent calls
 *   (/crons, /tags) are always live to reflect current job state.
 *
 *   If APCu is unavailable the controller falls back to computing everything
 *   on every request without error.
 *
 * Routes handled:
 *   GET /swimlane
 *   GET /swimlane?debug=1  (includes server-side timing breakdown in HTML comment)
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
 *
 * APCu caching is applied to the two most expensive per-expression operations:
 *   - computeSchedule()  → cached under key "cronmgr_sched_<md5>"  TTL 86400 s
 *   - translateCron()    → cached under key "cronmgr_trans_<md5>"  TTL 86400 s
 *
 * Timing instrumentation is written to the application log at DEBUG level on
 * every request and is additionally embedded as an HTML comment when the
 * query string contains `debug=1`.
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

    /**
     * APCu cache TTL in seconds.
     *
     * Fire-time patterns for a fixed reference week never change, so a 24-hour
     * TTL is safe.  Increase or remove the TTL if memory pressure is not a
     * concern.
     */
    private const CACHE_TTL = 86400;

    /**
     * APCu cache key prefix for computed schedules.
     */
    private const CACHE_PREFIX_SCHED = 'cronmgr_sched_';

    /**
     * APCu cache key prefix for cron-translator output.
     */
    private const CACHE_PREFIX_TRANS = 'cronmgr_trans_';

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Display the schedule swimlane view.
     *
     * Fetches the full job list and all tags from the host agent, pre-computes
     * weekly fire-time patterns for every job (using APCu cache where
     * available), and renders the swimlane template.  The template receives the
     * job data as a JSON-encoded string which the client-side renderer consumes
     * directly.
     *
     * Pass `?debug=1` to include a timing breakdown as an HTML comment in the
     * rendered page.  Timing data is always written to the application log at
     * DEBUG level regardless of the query parameter.
     *
     * @param array<string,string> $params Path parameters (unused for this route).
     *
     * @return void
     */
    public function index(array $params): void
    {
        $debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';
        $t0        = hrtime(true);

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

        $tAfterAgent = hrtime(true);

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
        $swimlaneJobs   = [];
        $cacheHits      = 0;
        $cacheMisses    = 0;
        $apCuAvailable  = \function_exists('apcu_fetch') && \ini_get('apc.enabled');

        foreach ($rawJobs as $job) {
            $cronExpr = trim((string) ($job['schedule'] ?? ''));
            if ($cronExpr === '') {
                continue;
            }

            $schedule = $this->computeScheduleCached(
                $cronExpr,
                $apCuAvailable,
                $cacheHits,
                $cacheMisses
            );

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
                'cronHuman'  => $this->translateCronCached($cronExpr, $apCuAvailable),
                'linuxUser'  => (string) ($job['linux_user'] ?? ''),
                'tags'       => array_values((array) ($job['tags']    ?? [])),
                'targets'    => array_values((array) ($job['targets'] ?? ['local'])),
                'active'     => (bool) ($job['active'] ?? true),
                'byDay'      => $schedule['byDay'],
                'allDays'    => $schedule['allDays'],
                'activeDays' => $schedule['activeDays'],
            ];
        }

        $tAfterCompute = hrtime(true);

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

        $tAfterJson = hrtime(true);

        // Build timing breakdown
        $timings = [
            'agent_ms'   => round(($tAfterAgent   - $t0)            / 1e6, 2),
            'compute_ms' => round(($tAfterCompute  - $tAfterAgent)   / 1e6, 2),
            'json_ms'    => round(($tAfterJson     - $tAfterCompute) / 1e6, 2),
            'total_ms'   => round(($tAfterJson     - $t0)            / 1e6, 2),
            'jobs'       => count($swimlaneJobs),
            'apcu'       => $apCuAvailable,
            'cache_hits' => $cacheHits,
            'cache_miss' => $cacheMisses,
            'payload_b'  => strlen($jobsJson),
        ];

        $this->logger->debug('SwimlaneController::index timing', $timings);

        $this->render('swimlane.php', $this->translator()->t('swimlane_title'), [
            'swimlaneJobsJson' => $jobsJson,
            'tags'             => $tags,
            'allTargets'       => $allTargets,
            'debugMode'        => $debugMode,
            'timings'          => $timings,
        ], '/swimlane');
    }

    // -------------------------------------------------------------------------
    // Private helpers – caching wrappers
    // -------------------------------------------------------------------------

    /**
     * Return the computed schedule for a cron expression, using APCu cache.
     *
     * On a cache hit the pre-computed array is returned immediately.
     * On a miss the schedule is computed, stored in APCu, and returned.
     * If APCu is unavailable the schedule is computed directly.
     *
     * @param string $cronExpr     Five-field standard cron expression.
     * @param bool   $apcu         Whether APCu is available.
     * @param int    &$hits        Hit counter (incremented by reference).
     * @param int    &$misses      Miss counter (incremented by reference).
     *
     * @return array{byDay:array<int,list<array{h:int,m:int}>>,allDays:list<array{h:int,m:int}>,activeDays:list<int>}
     */
    private function computeScheduleCached(
        string $cronExpr,
        bool   $apcu,
        int    &$hits,
        int    &$misses
    ): array {
        if (!$apcu) {
            ++$misses;
            return $this->computeSchedule($cronExpr);
        }

        $key    = self::CACHE_PREFIX_SCHED . md5($cronExpr);
        $cached = apcu_fetch($key, $success);

        if ($success && is_array($cached)) {
            ++$hits;
            /** @var array{byDay:array<int,list<array{h:int,m:int}>>,allDays:list<array{h:int,m:int}>,activeDays:list<int>} $cached */
            return $cached;
        }

        ++$misses;
        $schedule = $this->computeSchedule($cronExpr);
        apcu_store($key, $schedule, self::CACHE_TTL);

        return $schedule;
    }

    /**
     * Return the human-readable translation of a cron expression, using APCu cache.
     *
     * @param string $cronExpr Five-field cron expression.
     * @param bool   $apcu     Whether APCu is available.
     *
     * @return string Human-readable description in English.
     */
    private function translateCronCached(string $cronExpr, bool $apcu): string
    {
        if (!$apcu) {
            return $this->translateCron($cronExpr);
        }

        $key    = self::CACHE_PREFIX_TRANS . md5($cronExpr);
        $cached = apcu_fetch($key, $success);

        if ($success && is_string($cached)) {
            return $cached;
        }

        $translation = $this->translateCron($cronExpr);
        apcu_store($key, $translation, self::CACHE_TTL);

        return $translation;
    }

    // -------------------------------------------------------------------------
    // Private helpers – computation
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
                $minuteKey            = $h * 60 + $m;
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
