<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Timeline Controller
 *
 * Renders a paginated, filterable view of cron job execution history
 * across all jobs and users.
 *
 * Routes handled:
 *   GET /timeline
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

/**
 * Class TimelineController
 *
 * Forwards filter and pagination parameters to the host agent's /history
 * endpoint, collects supplementary data for the filter dropdowns and
 * renders the timeline template.
 */
class TimelineController extends BaseController
{
    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Display a paginated, filterable execution history timeline.
     *
     * Supported GET parameters:
     *   ?tag=     – filter by tag name
     *   ?user=    – filter by linux_user
     *   ?target=  – filter by execution target
     *   ?status=  – filter by execution status (success | failed | running)
     *   ?from=    – filter from date (YYYY-MM-DD)
     *   ?to=      – filter to date (YYYY-MM-DD)
     *   ?limit=   – number of results per page (10 | 25 | 50 | 100 | 500, default: 50)
     *   ?offset=  – pagination offset (default: 0)
     *   ?_reset=1 – clear all stored filter cookies and reset to defaults
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function index(array $params): void
    {
        $agent = $this->agentClient();

        // ------------------------------------------------------------------
        // Read and sanitise filter / pagination parameters.
        // filterParam() reads from GET first, falls back to a persistent cookie,
        // and saves the resolved value back to the cookie for next time.
        // ------------------------------------------------------------------
        $filterTag    = $this->filterParam('tag',    'cronmgr_tl_tag');
        $filterUser   = $this->filterParam('user',   'cronmgr_tl_user');
        $filterTarget = $this->filterParam('target', 'cronmgr_tl_target');
        $filterStatus = $this->filterParam('status', 'cronmgr_tl_status');
        $filterFrom   = $this->filterParam('from',   'cronmgr_tl_from');
        $filterTo     = $this->filterParam('to',     'cronmgr_tl_to');

        // Page size: validate against the allowed set; persist via cookie.
        $allowedLimits = [10, 25, 50, 100, 500];
        $limitRaw      = (int) $this->filterParam('limit', 'cronmgr_tl_limit', '50');
        $limit         = in_array($limitRaw, $allowedLimits, strict: true) ? $limitRaw : 50;

        // Offset is pure pagination state – never stored in a cookie.
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        // Build agent query params – only include non-empty filters
        $query = ['limit' => $limit, 'offset' => $offset];
        if ($filterTag    !== '') { $query['tag']    = $filterTag; }
        if ($filterUser   !== '') { $query['user']   = $filterUser; }
        if ($filterTarget !== '') { $query['target'] = $filterTarget; }
        if ($filterStatus !== '') { $query['status'] = $filterStatus; }
        if ($filterFrom   !== '') { $query['from']   = $filterFrom; }
        if ($filterTo     !== '') { $query['to']     = $filterTo; }

        // ------------------------------------------------------------------
        // Fetch data from the host agent
        // ------------------------------------------------------------------
        try {
            $historyResponse = $agent->get('/history', $query);
            $allTags         = $agent->get('/tags')['data']  ?? [];
            $allJobs         = $agent->get('/crons')['data'] ?? [];
        } catch (\RuntimeException $e) {
            $this->logger->error('TimelineController::index: agent request failed', [
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/timeline');
            return;
        }

        // ------------------------------------------------------------------
        // Normalise the history response
        // The agent may return either a plain array of entries or an object
        // with 'data' and 'total' keys for pagination metadata.
        // ------------------------------------------------------------------
        if (isset($historyResponse['data']) && is_array($historyResponse['data'])) {
            $history = $historyResponse['data'];
            $total   = (int) ($historyResponse['total'] ?? count($history));
        } else {
            $history = $historyResponse;
            $total   = count($history);
        }

        // ------------------------------------------------------------------
        // Build unique user and target lists from all jobs (for filter dropdowns)
        // ------------------------------------------------------------------
        $users      = [];
        $allTargets = [];
        foreach ($allJobs as $job) {
            $u = (string) ($job['linux_user'] ?? '');
            if ($u !== '' && !in_array($u, $users, strict: true)) {
                $users[] = $u;
            }
            foreach ((array) ($job['targets'] ?? []) as $tgt) {
                $tgt = (string) $tgt;
                if ($tgt !== '' && !in_array($tgt, $allTargets, strict: true)) {
                    $allTargets[] = $tgt;
                }
            }
        }
        sort($users);
        sort($allTargets);

        // Only show tags that are in use (job_count > 0)
        $tags = array_values(array_filter(
            $allTags,
            static fn(array $t): bool => ($t['job_count'] ?? 0) > 0
        ));

        // Active filter values passed to the template for pre-filling the form
        $filters = [
            'tag'    => $filterTag,
            'user'   => $filterUser,
            'target' => $filterTarget,
            'status' => $filterStatus,
            'from'   => $filterFrom,
            'to'     => $filterTo,
        ];

        // ------------------------------------------------------------------
        // Render
        // ------------------------------------------------------------------
        $this->render('timeline.php', $this->translator()->t('timeline_title'), [
            'history'    => $history,
            'tags'       => $tags,
            'users'      => $users,
            'allTargets' => $allTargets,
            'total'      => $total,
            'limit'      => $limit,
            'offset'     => $offset,
            'filters'    => $filters,
        ], '/timeline');
    }
}
