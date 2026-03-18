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

/**
 * Class DashboardController
 *
 * Fetches all cron jobs, recent failures and tag data from the host agent,
 * computes local statistics (total, active, inactive, by-user counts) and
 * hands everything to the dashboard template for rendering.
 */
class DashboardController extends BaseController
{
    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Display the main dashboard with aggregated statistics.
     *
     * Fetches:
     *   GET /crons              – all configured jobs
     *   GET /history?limit=10&status=failed – recent failures
     *   GET /tags               – all known tags
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

        // ------------------------------------------------------------------
        // Fetch data from the host agent
        // ------------------------------------------------------------------
        try {
            $jobs           = $agent->get('/crons')['data']                                    ?? [];
            $recentFailures = $agent->get('/history', ['limit' => 10, 'status' => 'failed'])['data'] ?? [];
            $tags           = $agent->get('/tags')['data']                                    ?? [];
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

        // Filter failures within the last 24 hours from the already-fetched list
        $failedLast24h = 0;
        foreach ($recentFailures as $entry) {
            $startedAt = strtotime((string) ($entry['started_at'] ?? '')) ?: 0;
            if ($startedAt >= $oneDayAgo) {
                $failedLast24h++;
            }
        }

        $stats = [
            'total'        => $totalJobs,
            'active'       => $activeJobs,
            'inactive'     => $inactiveJobs,
            'byUser'       => $byUser,
            'failedLast24h'=> $failedLast24h,
            'tagsCount'    => count($tags),
        ];

        // ------------------------------------------------------------------
        // Render
        // ------------------------------------------------------------------
        $this->render('dashboard.php', $this->translator()->t('dashboard_title'), [
            'jobs'           => $jobs,
            'recentFailures' => $recentFailures,
            'tags'           => $tags,
            'stats'          => $stats,
        ], '/dashboard');
    }
}
