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

use Cronmanager\Web\Database\Connection;
use Cronmanager\Web\Http\Response;
use Cronmanager\Web\Repository\AgentRepository;

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

        // ── Load agent list for the Agents section ─────────────────────────
        $agents = [];
        try {
            $pdo    = Connection::getInstance()->getPdo();
            $agents = (new AgentRepository($pdo))->findAll();
        } catch (\Throwable $e) {
            $this->logger->warning('MaintenanceController: could not load agents list', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->render('housekeeping/index.php', $this->translator()->t('housekeeping_title'), [
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
            'agents'            => $agents,
        ], '/settings');
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
            (new Response())->redirect('/settings?sync_ok=' . $synced);
        } catch (\RuntimeException $e) {
            $this->logger->error('MaintenanceController: resync failed', ['error' => $e->getMessage()]);
            (new Response())->redirect('/settings?sync_err=1');
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

        (new Response())->redirect("/settings?resolved=1&hours={$hours}");
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

        (new Response())->redirect("/settings?exec_del=1&hours={$hours}");
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
            (new Response())->redirect("/settings?hours={$hours}");
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
        (new Response())->redirect("/settings?{$param}={$count}&hours={$hours}");
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
            (new Response())->redirect('/settings?once_removed=' . $removed);
        } catch (\RuntimeException $e) {
            $this->logger->error('MaintenanceController: onceCleanup failed', [
                'error' => $e->getMessage(),
            ]);
            (new Response())->redirect('/settings');
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

            echo json_encode($result); // nosemgrep: php.lang.security.injection.echoed-request.echoed-request

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
            (new Response())->redirect('/settings?cleaned=' . $deleted);
        } catch (\RuntimeException $e) {
            $this->logger->error('MaintenanceController: cleanHistory failed', [
                'error' => $e->getMessage(),
            ]);
            (new Response())->redirect('/settings');
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
                '/settings?logs_pruned=' . $deletedLogs . '&retry_state_pruned=' . $deletedRetry
            );
        } catch (\RuntimeException $e) {
            $this->logger->error('MaintenanceController: pruneLogs failed', [
                'error' => $e->getMessage(),
            ]);
            (new Response())->redirect('/settings?prune_err=1');
        }
    }

    // -------------------------------------------------------------------------
    // GET /settings/agent-config
    // -------------------------------------------------------------------------

    /**
     * Render the agent notification/integration settings form.
     *
     * Loads the effective settings for all sections from the selected agent.
     * When ?source_id=X is present, the source agent's settings are pre-loaded
     * into the form so the user can review them before saving.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function agentSettings(array $params = []): void
    {
        $t           = $this->translator();
        $sourceId    = isset($_GET['source_id']) ? (int) $_GET['source_id'] : 0;
        $prefilled   = false;
        $settings    = [];

        // Load current agent's settings as the baseline
        try {
            $settings = $this->agentClient()->get('/settings');
        } catch (\Throwable $e) {
            $this->logger->warning('MaintenanceController: cannot load agent settings', [
                'error' => $e->getMessage(),
            ]);
        }

        // Optionally pre-fill with settings from a different source agent
        if ($sourceId > 0) {
            $pdo        = \Cronmanager\Web\Database\Connection::getInstance()->getPdo();
            $repo       = new AgentRepository($pdo);
            $sourceAgent = $repo->findById($sourceId);

            if ($sourceAgent !== null) {
                try {
                    $settings  = $this->buildClientFor($sourceAgent)->get('/settings');
                    $prefilled = true;
                } catch (\Throwable $e) {
                    $this->logger->warning('MaintenanceController: cannot load source agent settings', [
                        'source_id' => $sourceId,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        }

        // Load all enabled agents except the current one for the copy selector
        $pdo          = \Cronmanager\Web\Database\Connection::getInstance()->getPdo();
        $repo         = new AgentRepository($pdo);
        $currentAgent = $this->selectedAgent();
        $otherAgents  = array_values(array_filter(
            $repo->findEnabled(),
            fn(array $a): bool => (int) $a['id'] !== (int) $currentAgent['id'],
        ));

        $this->render('housekeeping/agent-settings.php', $t->t('agent_settings_title'), [
            'settings'     => $settings,
            'otherAgents'  => $otherAgents,
            'prefilled'    => $prefilled,
            'sourceId'     => $sourceId,
        ], '/settings');
    }

    // -------------------------------------------------------------------------
    // POST /settings/agent-config
    // -------------------------------------------------------------------------

    /**
     * Save notification and integration settings to the selected agent's DB.
     *
     * Builds a PUT /settings payload from the POST body and delegates to the
     * agent's SettingsEndpoint.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function saveAgentSettings(array $params = []): void
    {
        $sections = $this->buildSettingsPayload();

        try {
            $this->agentClient()->put('/settings', $sections);
            \Cronmanager\Web\Session\SessionManager::set('_flash_success', $this->translator()->t('agent_settings_saved'));
        } catch (\Throwable $e) {
            $this->logger->error('MaintenanceController: saveAgentSettings failed', [
                'error' => $e->getMessage(),
            ]);
            \Cronmanager\Web\Session\SessionManager::set('_flash_error', $this->translator()->t('agent_settings_error'));
        }

        (new Response())->redirect('/settings/agent-config');
    }

    // -------------------------------------------------------------------------
    // POST /settings/agent-config/copy
    // -------------------------------------------------------------------------

    /**
     * Copy all settings from a source agent directly to the current agent.
     *
     * Expected POST fields:
     *   source_agent_id  (int)  ID of the agent to copy settings from.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function copyAgentSettings(array $params = []): void
    {
        $t        = $this->translator();
        $sourceId = (int) ($_POST['source_agent_id'] ?? 0);

        if ($sourceId <= 0) {
            \Cronmanager\Web\Session\SessionManager::set('_flash_error', $t->t('agent_settings_copy_error'));
            (new Response())->redirect('/settings/agent-config');
            return;
        }

        $pdo        = \Cronmanager\Web\Database\Connection::getInstance()->getPdo();
        $repo       = new AgentRepository($pdo);
        $srcAgent   = $repo->findById($sourceId);

        if ($srcAgent === null) {
            \Cronmanager\Web\Session\SessionManager::set('_flash_error', $t->t('agent_settings_copy_error'));
            (new Response())->redirect('/settings/agent-config');
            return;
        }

        try {
            $sourceSettings = $this->buildClientFor($srcAgent)->get('/settings');
            $this->agentClient()->put('/settings', $sourceSettings);
            \Cronmanager\Web\Session\SessionManager::set('_flash_success', $t->t('agent_settings_copied'));
        } catch (\Throwable $e) {
            $this->logger->error('MaintenanceController: copyAgentSettings failed', [
                'source_id' => $sourceId,
                'error'     => $e->getMessage(),
            ]);
            \Cronmanager\Web\Session\SessionManager::set('_flash_error', $t->t('agent_settings_copy_error'));
        }

        (new Response())->redirect('/settings/agent-config');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the settings payload from POST data for all four sections.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildSettingsPayload(): array
    {
        $boolField = static fn(string $k): bool => isset($_POST[$k]) && $_POST[$k] === '1';
        $strField  = static fn(string $k, string $d = ''): string => trim((string) ($_POST[$k] ?? $d));
        $intField  = static fn(string $k, int $d = 0): int => (int) ($_POST[$k] ?? $d);

        return [
            'mail' => [
                'enabled'      => $boolField('mail_enabled'),
                'host'         => $strField('mail_host'),
                'port'         => $intField('mail_port', 587),
                'username'     => $strField('mail_username'),
                'password'     => $strField('mail_password'),
                'from'         => $strField('mail_from'),
                'from_name'    => $strField('mail_from_name', 'Cronmanager'),
                'to'           => $strField('mail_to'),
                'encryption'   => $strField('mail_encryption', 'tls'),
                'smtp_timeout' => $intField('mail_smtp_timeout', 15),
            ],
            'telegram' => [
                'enabled'   => $boolField('telegram_enabled'),
                'bot_token' => $strField('telegram_bot_token'),
                'chat_id'   => $strField('telegram_chat_id'),
                'timeout'   => $intField('telegram_timeout', 10),
            ],
            'influxdb' => [
                'enabled' => $boolField('influxdb_enabled'),
                'url'     => $strField('influxdb_url'),
                'token'   => $strField('influxdb_token'),
                'org'     => $strField('influxdb_org'),
                'bucket'  => $strField('influxdb_bucket'),
                'timeout' => $intField('influxdb_timeout', 10),
            ],
            'notifications' => [
                'web_url' => $strField('notifications_web_url'),
            ],
            'performance_monitor' => [
                'persist_data'     => $boolField('perf_persist_data'),
                'show_in_frontend' => $boolField('perf_show_in_frontend'),
            ],
        ];
    }
}
