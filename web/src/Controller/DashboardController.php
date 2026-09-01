<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Dashboard Controller
 *
 * Aggregates summary statistics from the host agent and renders the
 * main dashboard page.
 *
 * Routes handled:
 *   GET /dashboard
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

use Cronmanager\Web\Http\Request;
use Cronmanager\Web\Session\SessionManager;

/**
 * Class DashboardController
 *
 * Fetches all cron jobs, recent failures and tag data from the host agent,
 * computes local statistics (total, active, inactive, by-user counts) and
 * hands everything to the dashboard template for rendering.
 */
class DashboardController extends BaseController
{
    /**
     * Set to false to disable the execution-statistics widget and skip the
     * GET /stats agent call entirely (instant rollback if performance issues arise).
     */
    private const SHOW_EXECUTION_STATS = true;

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Display the main dashboard with aggregated statistics.
     *
     * Fetches:
     *   GET /crons                                                      – all configured jobs
     *   GET /history?limit=10&status=failed&unacknowledged_only=1       – recent unacknowledged failures
     *   GET /tags                                                        – all known tags
     *
     * Computes locally:
     *   - Total job count
     *   - Active vs inactive counts
     *   - Jobs per linux_user (grouped)
     *   - Failed runs in last 24 h
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function index(array $params): void
    {
        $agent = $this->agentClient();

        // JSON polls never write to the session after this point – release the
        // session file lock before the agent I/O so a poll in flight cannot
        // block a concurrently clicked page navigation in the same session.
        if ($this->isJsonRequest()) {
            SessionManager::writeClose();
        }

        // ------------------------------------------------------------------
        // Fetch data from the host agent (three requests in parallel)
        // ------------------------------------------------------------------
        try {
            // Dispatch all GET requests concurrently via Guzzle promises,
            // reducing wall-clock time from ~sum(latencies) to ~max(latency).
            $batch = [
                'crons'   => ['path' => '/crons'],
                // The agent filters acknowledged and applies the limit server-side;
                // the response total reflects all unacknowledged failures.
                'history' => ['path' => '/history', 'query' => [
                    'limit'              => 10,
                    'status'             => 'failed',
                    'unacknowledged_only' => 1,
                ]],
                'tags'    => ['path' => '/tags'],
            ];
            if (self::SHOW_EXECUTION_STATS) {
                $batch['execstats'] = ['path' => '/stats'];
            }
            $results = $agent->getMultiple($batch);
            $jobs                       = $results['crons']['data']   ?? [];
            $recentFailures             = $results['history']['data'] ?? [];
            $totalUnacknowledgedFailures = (int) ($results['history']['total'] ?? 0);
            $tags                       = $results['tags']['data']    ?? [];
            $executionStats = self::SHOW_EXECUTION_STATS ? ($results['execstats'] ?? []) : [];
        } catch (\RuntimeException $e) {
            $this->logger->error('DashboardController: agent request failed', [
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/dashboard');
            return;
        }

        // ------------------------------------------------------------------
        // Compute local statistics
        // ------------------------------------------------------------------
        $totalJobs  = count($jobs);
        $activeJobs = 0;
        $byUser     = [];
        $now        = time();
        $oneDayAgo  = $now - 86400;

        foreach ($jobs as $job) {
            if (!empty($job['active'])) {
                $activeJobs++;
            }

            $user = (string) ($job['linux_user'] ?? 'unknown');
            $byUser[$user] = ($byUser[$user] ?? 0) + 1;
        }

        $inactiveJobs = $totalJobs - $activeJobs;

        // Exclude maintenance-skipped executions (exit_code -4); acknowledged
        // failures are already filtered by the agent (unacknowledged_only=1).
        $recentFailures = array_values(array_filter(
            $recentFailures,
            static fn(array $e): bool => (int) ($e['exit_code'] ?? 0) !== -4
        ));

        // Count failures within last 24 hours (from the displayed entries)
        $failedLast24h = 0;
        foreach ($recentFailures as $entry) {
            $startedAt = strtotime((string) ($entry['started_at'] ?? '')) ?: 0;
            if ($startedAt >= $oneDayAgo) {
                $failedLast24h++;
            }
        }

        $stats = [
            'total'               => $totalJobs,
            'active'              => $activeJobs,
            'inactive'            => $inactiveJobs,
            'byUser'              => $byUser,
            'failedLast24h'       => $failedLast24h,
            'tagsCount'           => count($tags),
            'totalUnacknowledged' => $totalUnacknowledgedFailures,
        ];

        // ------------------------------------------------------------------
        // JSON mode: return data for AJAX polling
        // ------------------------------------------------------------------
        if ($this->isJsonRequest()) {
            $this->jsonResponse([
                'stats'          => $stats,
                'recentFailures' => $recentFailures,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // Render
        // ------------------------------------------------------------------
        $this->render('dashboard.php', $this->translator()->t('dashboard_title'), [
            'jobs'                       => $jobs,
            'recentFailures'             => $recentFailures,
            'totalUnacknowledgedFailures' => $totalUnacknowledgedFailures,
            'tags'                       => $tags,
            'stats'                      => $stats,
            'multiUser'                  => count($byUser) > 1,
            'executionStats'             => $executionStats,
            'showExecutionStats'         => self::SHOW_EXECUTION_STATS,
            'isOperator'                 => SessionManager::hasRole('operator'),
        ], '/dashboard');
    }
}
