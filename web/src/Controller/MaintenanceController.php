<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – MaintenanceController
 *
 * Handles the /maintenance section: administrative housekeeping actions that
 * do not fit into the regular cron-job workflow.
 *
 * Actions:
 *   GET  /maintenance                          – render dashboard with stuck list
 *   POST /maintenance/resync                   – trigger full crontab resync
 *   POST /maintenance/executions/{id}/finish   – mark stuck execution as finished
 *   POST /maintenance/executions/{id}/delete   – delete execution record
 *   POST /maintenance/history/cleanup          – delete old history records
 *   POST /maintenance/once/cleanup             – remove stale Run Now crontab entries
 *   POST /maintenance/notification/test        – send a test notification (mail or telegram)
 *
 * All mutating actions redirect back to GET /maintenance with a result
 * indicator in the query string so the page can show a one-shot banner
 * without needing session-based flash storage.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

use Cronmanager\Web\Http\Response;

/**
 * Class MaintenanceController
 *
 * Administrative maintenance actions.
 */
final class MaintenanceController extends BaseController
{
    // -------------------------------------------------------------------------
    // GET /maintenance
    // -------------------------------------------------------------------------

    /**
     * Render the maintenance dashboard.
     *
     * Query parameters (all optional):
     *   hours      (int)  Stuck-execution threshold in hours (default 2).
     *   sync_ok    (int)  Number of active jobs synced (flash from resync).
     *   sync_err   (1)    Set when a resync error occurred (flash).
     *   resolved   (1)    Set after a stuck execution was marked finished.
     *   exec_del   (1)    Set after an execution record was deleted.
     *   cleaned    (int)  Number of history records deleted (flash from cleanup).
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function index(array $params = []): void
    {
        $hours = max(1, (int) ($_GET['hours'] ?? 2));

        // ── Fetch stuck executions from agent ──────────────────────────────
        $stuckExecutions = [];
        $stuckError      = null;

        try {
            $result          = $this->agentClient()->get('/maintenance/executions/stuck', ['hours' => $hours]);
            $stuckExecutions = $result['data'] ?? [];
        } catch (\RuntimeException $e) {
            $stuckError = $e->getMessage();
            $this->logger->warning('MaintenanceController: could not fetch stuck executions', [
                'error' => $e->getMessage(),
            ]);
        }

        // ── Flash messages from previous actions ───────────────────────────
        $flashSyncOk        = isset($_GET['sync_ok'])        ? (int) $_GET['sync_ok']        : null;
        $flashSyncErr       = isset($_GET['sync_err']);
        $flashResolved      = isset($_GET['resolved']);
        $flashExecDel       = isset($_GET['exec_del']);
        $flashCleaned       = isset($_GET['cleaned'])        ? (int) $_GET['cleaned']        : null;
        $flashBulkResolved  = isset($_GET['bulk_resolved'])  ? (int) $_GET['bulk_resolved']  : null;
        $flashBulkDeleted   = isset($_GET['bulk_deleted'])   ? (int) $_GET['bulk_deleted']   : null;
        $flashOnceRemoved   = isset($_GET['once_removed'])   ? (int) $_GET['once_removed']   : null;
        $flashLogsPruned    = isset($_GET['logs_pruned'])
            ? ['logs' => (int) ($_GET['logs_pruned'] ?? 0), 'retry_state' => (int) ($_GET['retry_state_pruned'] ?? 0)]
            : null;
        $flashLogsPruneErr  = isset($_GET['prune_err']);

        $this->render('maintenance/index.php', $this->translator()->t('maintenance_title'), [
            'hours'             => $hours,
            'stuckExecutions'   => $stuckExecutions,
            'stuckError'        => $stuckError,
            'flashSyncOk'       => $flashSyncOk,
            'flashSyncErr'      => $flashSyncErr,
            'flashResolved'     => $flashResolved,
            'flashExecDel'      => $flashExecDel,
            'flashCleaned'      => $flashCleaned,
            'flashBulkResolved' => $flashBulkResolved,
            'flashBulkDeleted'  => $flashBulkDeleted,
            'flashOnceRemoved'  => $flashOnceRemoved,
            'flashLogsPruned'   => $flashLogsPruned,
            'flashLogsPruneErr' => $flashLogsPruneErr,
        ], '/maintenance');
    }

    // -------------------------------------------------------------------------
    // POST /maintenance/resync
    // -------------------------------------------------------------------------

    /**
     * Trigger a full crontab resync via the agent.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function resyncCrontab(array $params = []): void
    {
        try {
            $result = $this->agentClient()->post('/maintenance/crontab/resync');
            $synced = (int) ($result['synced'] ?? 0);
            (new Response())->redirect('/maintenance?sync_ok=' . $synced);
        } catch (\RuntimeException $e) {
            $this->logger->error('MaintenanceController: resync failed', ['error' => $e->getMessage()]);
            (new Response())->redirect('/maintenance?sync_err=1');
        }
    }

    // -------------------------------------------------------------------------
    // POST /maintenance/executions/{id}/finish
    // -------------------------------------------------------------------------

    /**
     * Mark a stuck execution as finished.
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     */
    public function resolveExecution(array $params): void
    {
        $id    = isset($params['id']) ? (int) $params['id'] : 0;
        $hours = max(1, (int) ($_POST['hours'] ?? 2));

        try {
            $this->agentClient()->post("/maintenance/executions/{$id}/finish");
        } catch (\RuntimeException $e) {
            $this->logger->error('MaintenanceController: resolveExecution failed', [
                'id'    => $id,
                'error' => $e->getMessage(),
            ]);
        }

        (new Response())->redirect("/maintenance?resolved=1&hours={$hours}");
    }

    // -------------------------------------------------------------------------
    // POST /maintenance/executions/{id}/delete
    // -------------------------------------------------------------------------

    /**
     * Permanently delete an execution record.
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     */
    public function deleteExecution(array $params): void
    {
        $id    = isset($params['id']) ? (int) $params['id'] : 0;
        $hours = max(1, (int) ($_POST['hours'] ?? 2));

        try {
            $this->agentClient()->delete("/maintenance/executions/{$id}");
        } catch (\RuntimeException $e) {
            $this->logger->error('MaintenanceController: deleteExecution failed', [
                'id'    => $id,
                'error' => $e->getMessage(),
            ]);
        }

        (new Response())->redirect("/maintenance?exec_del=1&hours={$hours}");
    }

    // -------------------------------------------------------------------------
    // POST /maintenance/executions/bulk
    // -------------------------------------------------------------------------

    /**
     * Apply a bulk action (finish or delete) to multiple execution records.
     *
     * Expected POST fields:
     *   _action  (string)  'finish' or 'delete'
     *   ids[]    (int[])   Execution IDs to act on
     *   hours    (int)     Current hours filter (preserved in redirect)
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function bulkAction(array $params = []): void
    {
        $action = $_POST['_action'] ?? '';
        $ids    = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $hours  = max(1, (int) ($_POST['hours'] ?? 2));

        if (empty($ids) || !in_array($action, ['finish', 'delete'], true)) {
            (new Response())->redirect("/maintenance?hours={$hours}");
            return;
        }

        $count = 0;

        foreach ($ids as $id) {
            try {
                if ($action === 'finish') {
                    $this->agentClient()->post("/maintenance/executions/{$id}/finish");
                } else {
                    $this->agentClient()->delete("/maintenance/executions/{$id}");
                }
                $count++;
            } catch (\RuntimeException $e) {
                $this->logger->error('MaintenanceController: bulkAction failed for execution', [
                    'id'     => $id,
                    'action' => $action,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        $param = $action === 'finish' ? 'bulk_resolved' : 'bulk_deleted';
        (new Response())->redirect("/maintenance?{$param}={$count}&hours={$hours}");
    }

    // -------------------------------------------------------------------------
    // POST /maintenance/once/cleanup
    // -------------------------------------------------------------------------

    /**
     * Remove all stale Run Now (once-only) crontab entries.
     *
     * These entries are normally self-removed by cron-wrapper.sh after execution,
     * but can remain if the agent was unreachable during the cleanup call.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function onceCleanup(array $params = []): void
    {
        try {
            $result  = $this->agentClient()->post('/maintenance/once/cleanup');
            $removed = (int) ($result['removed'] ?? 0);
            (new Response())->redirect('/maintenance?once_removed=' . $removed);
        } catch (\RuntimeException $e) {
            $this->logger->error('MaintenanceController: onceCleanup failed', [
                'error' => $e->getMessage(),
            ]);
            (new Response())->redirect('/maintenance');
        }
    }

    // -------------------------------------------------------------------------
    // POST /maintenance/notification/test
    // -------------------------------------------------------------------------

    /**
     * Send a test notification through the specified channel.
     *
     * Expected POST fields:
     *   channel  (string)  'mail' or 'telegram'
     *
     * Redirects back to GET /maintenance with a query parameter indicating
     * the outcome:
     *   notify_test=ok          – message sent successfully
     *   notify_test=disabled    – channel is disabled in agent config
     *   notify_test=error       – channel enabled but send attempt failed
     *   notify_test=agent_err   – agent could not be reached
     *
     * The channel name is preserved as notify_channel=mail|telegram so the
     * flash banner can mention which channel was tested.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function testNotification(array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $channel = strtolower(trim((string) ($_POST['channel'] ?? '')));

        if (!in_array($channel, ['mail', 'telegram'], true)) {
            echo json_encode(['success' => false, 'reason' => 'invalid_channel']);
            return;
        }

        try {
            $result = $this->agentClient()->post('/maintenance/notification/test', [
                'channel' => $channel,
            ]);

            echo json_encode($result);

        } catch (\RuntimeException $e) {
            $this->logger->error('MaintenanceController: testNotification agent error', [
                'channel' => $channel,
                'error'   => $e->getMessage(),
            ]);
            echo json_encode([
                'success' => false,
                'reason'  => 'agent_err',
                'message' => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // POST /maintenance/history/cleanup
    // -------------------------------------------------------------------------

    /**
     * Delete old history records.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function cleanHistory(array $params = []): void
    {
        $days = max(1, (int) ($_POST['older_than_days'] ?? 90));

        try {
            $result  = $this->agentClient()->post('/maintenance/history/cleanup', [
                'older_than_days' => $days,
            ]);
            $deleted = (int) ($result['deleted'] ?? 0);
            (new Response())->redirect('/maintenance?cleaned=' . $deleted);
        } catch (\RuntimeException $e) {
            $this->logger->error('MaintenanceController: cleanHistory failed', [
                'error' => $e->getMessage(),
            ]);
            (new Response())->redirect('/maintenance');
        }
    }

    // -------------------------------------------------------------------------
    // POST /maintenance/logs/prune
    // -------------------------------------------------------------------------

    /**
     * Apply per-job log retention policies and remove stale retry-state entries.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function pruneLogs(array $params = []): void
    {
        try {
            $result       = $this->agentClient()->post('/maintenance/logs/prune');
            $deletedLogs  = (int) ($result['deleted_logs']        ?? 0);
            $deletedRetry = (int) ($result['deleted_retry_state'] ?? 0);
            (new Response())->redirect(
                '/maintenance?logs_pruned=' . $deletedLogs . '&retry_state_pruned=' . $deletedRetry
            );
        } catch (\RuntimeException $e) {
            $this->logger->error('MaintenanceController: pruneLogs failed', [
                'error' => $e->getMessage(),
            ]);
            (new Response())->redirect('/maintenance?prune_err=1');
        }
    }
}
