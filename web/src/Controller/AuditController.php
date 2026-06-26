<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Audit Log Controller
 *
 * Admin-only controller that displays the audit log by reading entries
 * from the agent via GET /audit/logs.
 *
 * Routes handled:
 *   GET /audit   – display paginated, filtered audit log
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

use Cronmanager\Web\Http\Response;

/**
 * Class AuditController
 *
 * Fetches audit log entries from the agent and renders the audit list view.
 * Accessible to admin users only (enforced by the router).
 */
class AuditController extends BaseController
{
    /** Number of entries per page. */
    private const PAGE_SIZE = 25;

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Display the audit log list.
     *
     * Reads optional filter parameters from $_GET, queries the agent, and
     * renders the audit/list.php template.
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function index(array $params): void
    {
        $t = $this->translator();

        // ------------------------------------------------------------------
        // Pagination and filter parameters from query string
        // ------------------------------------------------------------------

        $page         = max(1, (int) ($_GET['page']          ?? 1));
        $username     = trim((string) ($_GET['username']      ?? ''));
        $actionPrefix = trim((string) ($_GET['action_prefix'] ?? ''));
        $dateFrom     = trim((string) ($_GET['date_from']     ?? ''));
        $dateTo       = trim((string) ($_GET['date_to']       ?? ''));

        $offset = ($page - 1) * self::PAGE_SIZE;

        // ------------------------------------------------------------------
        // Build agent query string
        // ------------------------------------------------------------------

        $agentParams = array_filter([
            'limit'         => self::PAGE_SIZE,
            'offset'        => $offset,
            'username'      => $username,
            'action_prefix' => $actionPrefix,
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
        ], static fn ($v): bool => $v !== '' && $v !== 0);

        // ------------------------------------------------------------------
        // Fetch from agent
        // ------------------------------------------------------------------

        try {
            $response = $this->agentClient()->get('/audit/logs', $agentParams);
        } catch (\Throwable $e) {
            $this->logger->error('AuditController::index: agent request failed', [
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/audit');
            return;
        }

        if (!isset($response['data']) || !is_array($response['data'])) {
            $this->logger->error('AuditController::index: unexpected agent response', [
                'response' => $response,
            ]);
            $this->renderError(500, 'error_500', '/audit');
            return;
        }

        $entries  = $response['data'];
        $total    = (int) ($response['total'] ?? 0);
        $lastPage = (int) ceil($total / self::PAGE_SIZE);

        $this->render('audit/list.php', $t->t('nav_audit_log'), [
            'entries'      => $entries,
            'total'        => $total,
            'page'         => $page,
            'lastPage'     => max(1, $lastPage),
            'pageSize'     => self::PAGE_SIZE,
            'username'     => $username,
            'actionPrefix' => $actionPrefix,
            'dateFrom'     => $dateFrom,
            'dateTo'       => $dateTo,
        ], '/audit');
    }
}
