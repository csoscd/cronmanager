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
     *   ?status=  – filter by execution status (success | failed | running)
     *   ?from=    – filter from date (YYYY-MM-DD)
     *   ?to=      – filter to date (YYYY-MM-DD)
     *   ?limit=   – number of results per page (default: 50)
     *   ?offset=  – pagination offset (default: 0)
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function index(array $params): void
    {
        $agent = $this->agentClient();

        // ------------------------------------------------------------------
        // Read and sanitise filter / pagination parameters from the request
        // ------------------------------------------------------------------
        $filterTag    = trim((string) ($_GET['tag']    ?? ''));
        $filterUser   = trim((string) ($_GET['user']   ?? ''));
        $filterStatus = trim((string) ($_GET['status'] ?? ''));
        $filterFrom   = trim((string) ($_GET['from']   ?? ''));
        $filterTo     = trim((string) ($_GET['to']     ?? ''));
        $limit        = max(1, (int) ($_GET['limit']  ?? 50));
        $offset       = max(0, (int) ($_GET['offset'] ?? 0));

        // Build agent query params – only include non-empty filters
        $query = ['limit' => $limit, 'offset' => $offset];
        if ($filterTag    !== '') { $query['tag']    = $filterTag; }
        if ($filterUser   !== '') { $query['user']   = $filterUser; }
        if ($filterStatus !== '') { $query['status'] = $filterStatus; }
        if ($filterFrom   !== '') { $query['from']   = $filterFrom; }
        if ($filterTo     !== '') { $query['to']     = $filterTo; }

        // ------------------------------------------------------------------
        // Fetch data from the host agent
        // ------------------------------------------------------------------
        try {
            $historyResponse = $agent->get('/history', $query);
            $tags            = $agent->get('/tags')['data']  ?? [];
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
        // Build unique user list from all jobs (for filter dropdown)
        // ------------------------------------------------------------------
        $users = [];
        foreach ($allJobs as $job) {
            $u = (string) ($job['linux_user'] ?? '');
            if ($u !== '' && !in_array($u, $users, strict: true)) {
                $users[] = $u;
            }
        }
        sort($users);

        // Active filter values passed to the template for pre-filling the form
        $filters = [
            'tag'    => $filterTag,
            'user'   => $filterUser,
            'status' => $filterStatus,
            'from'   => $filterFrom,
            'to'     => $filterTo,
        ];

        // ------------------------------------------------------------------
        // Render
        // ------------------------------------------------------------------
        $this->render('timeline.php', $this->translator()->t('timeline_title'), [
            'history' => $history,
            'tags'    => $tags,
            'users'   => $users,
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'filters' => $filters,
        ], '/timeline');
    }
}
