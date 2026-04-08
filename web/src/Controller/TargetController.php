<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Target / Maintenance Window Controller
 *
 * Admin-only controller for managing per-target maintenance windows.
 *
 * Routes handled:
 *   GET  /targets                      – list all targets with their windows
 *   GET  /targets/{target}/windows/new – show create form for a target
 *   POST /targets/{target}/windows     – process create form
 *   GET  /targets/windows/{id}/edit    – show edit form
 *   POST /targets/windows/{id}/edit    – process edit form
 *   POST /targets/windows/{id}/delete  – delete a window
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

use Cronmanager\Web\Http\Response;

/**
 * Class TargetController
 *
 * Manages maintenance windows per target.
 */
class TargetController extends BaseController
{
    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * List all maintenance windows grouped by target.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function index(array $params): void
    {
        $agent = $this->agentClient();

        try {
            $windows  = $agent->getMaintenanceWindows();
            // /import/ssh-targets aggregates hosts from all users without requiring
            // a specific ?user= parameter (unlike /ssh-hosts).
            $sshHosts = $agent->get('/import/ssh-targets')['data'] ?? [];
        } catch (\RuntimeException $e) {
            $this->logger->error('TargetController::index: agent request failed', [
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/targets');
            return;
        }

        // Group windows by target
        $byTarget = [];

        foreach ($windows as $window) {
            $t = (string) ($window['target'] ?? 'local');

            if (!isset($byTarget[$t])) {
                $byTarget[$t] = [];
            }

            $byTarget[$t][] = $window;
        }

        // Add targets that have SSH hosts but no windows yet
        foreach ($sshHosts as $host) {
            $hostName = (string) $host;

            if ($hostName !== '' && !isset($byTarget[$hostName])) {
                $byTarget[$hostName] = [];
            }
        }

        if (!isset($byTarget['local'])) {
            $byTarget['local'] = [];
        }

        ksort($byTarget);

        $this->render('targets/list.php', $this->translator()->t('nav_targets'), [
            'byTarget' => $byTarget,
            'isAdmin'  => true,
        ], '/targets');
    }

    /**
     * Show the create-window form for a specific target.
     *
     * @param array<string, string> $params Path parameters: ['target' => string].
     *
     * @return void
     */
    public function newWindow(array $params): void
    {
        $target = urldecode((string) ($params['target'] ?? ''));

        if ($target === '') {
            $this->renderError(400, 'error_404', '/targets');
            return;
        }

        $this->render('targets/form.php', $this->translator()->t('target_window_new'), [
            'window'    => ['target' => $target, 'duration_minutes' => 60, 'active' => true],
            'isEdit'    => false,
            'error'     => null,
            'formAction' => '/targets/' . rawurlencode($target) . '/windows',
        ], '/targets');
    }

    /**
     * Process the create-window form submission.
     *
     * @param array<string, string> $params Path parameters: ['target' => string].
     *
     * @return void
     */
    public function storeWindow(array $params): void
    {
        $target = urldecode((string) ($params['target'] ?? ''));

        $data = [
            'target'           => $target,
            'cron_schedule'    => trim((string) ($_POST['cron_schedule']    ?? '')),
            'duration_minutes' => (int) ($_POST['duration_minutes'] ?? 60),
            'description'      => trim((string) ($_POST['description']      ?? '')),
            'active'           => isset($_POST['active']) && $_POST['active'] === '1',
        ];

        if ($data['description'] === '') {
            $data['description'] = null;
        }

        try {
            $this->agentClient()->createMaintenanceWindow($data);
        } catch (\RuntimeException $e) {
            $this->logger->warning('TargetController::storeWindow: failed', [
                'message' => $e->getMessage(),
            ]);

            $this->render('targets/form.php', $this->translator()->t('target_window_new'), [
                'window'     => array_merge($data, $_POST),
                'isEdit'     => false,
                'error'      => $e->getMessage(),
                'formAction' => '/targets/' . rawurlencode($target) . '/windows',
            ], '/targets');
            return;
        }

        $this->logger->info('TargetController::storeWindow: window created', [
            'target' => $target,
        ]);

        (new Response())->redirect('/targets');
    }

    /**
     * Show the edit form for an existing maintenance window.
     *
     * @param array<string, string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function editWindow(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->renderError(404, 'error_404', '/targets');
            return;
        }

        try {
            $window = $this->agentClient()->getMaintenanceWindow($id);
        } catch (\RuntimeException $e) {
            $this->logger->error('TargetController::editWindow: agent request failed', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/targets');
            return;
        }

        if (empty($window)) {
            $this->renderError(404, 'error_404', '/targets');
            return;
        }

        $this->render('targets/form.php', $this->translator()->t('target_window_edit'), [
            'window'     => $window,
            'isEdit'     => true,
            'error'      => null,
            'formAction' => '/targets/windows/' . $id . '/edit',
        ], '/targets');
    }

    /**
     * Process the edit-window form submission.
     *
     * @param array<string, string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function updateWindow(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->renderError(404, 'error_404', '/targets');
            return;
        }

        $data = [
            'target'           => trim((string) ($_POST['target']           ?? '')),
            'cron_schedule'    => trim((string) ($_POST['cron_schedule']    ?? '')),
            'duration_minutes' => (int) ($_POST['duration_minutes'] ?? 60),
            'description'      => trim((string) ($_POST['description']      ?? '')),
            'active'           => isset($_POST['active']) && $_POST['active'] === '1',
        ];

        if ($data['description'] === '') {
            $data['description'] = null;
        }

        try {
            $this->agentClient()->updateMaintenanceWindow($id, $data);
        } catch (\RuntimeException $e) {
            $this->logger->warning('TargetController::updateWindow: failed', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);

            $this->render('targets/form.php', $this->translator()->t('target_window_edit'), [
                'window'     => array_merge($data, ['id' => $id]),
                'isEdit'     => true,
                'error'      => $e->getMessage(),
                'formAction' => '/targets/windows/' . $id . '/edit',
            ], '/targets');
            return;
        }

        $this->logger->info('TargetController::updateWindow: window updated', ['id' => $id]);

        (new Response())->redirect('/targets');
    }

    /**
     * Proxy GET /maintenance/windows/conflict to the agent.
     *
     * Returns the agent's JSON response directly so the browser JS can use it
     * without CORS issues.
     *
     * Query parameters forwarded as-is: schedule, target, look_ahead.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function conflictCheck(array $params): void
    {
        $schedule  = trim((string) ($_GET['schedule']    ?? ''));
        $target    = trim((string) ($_GET['target']      ?? ''));
        $lookAhead = max(1, (int) ($_GET['look_ahead']   ?? 10));

        if ($schedule === '' || $target === '') {
            header('Content-Type: application/json');
            echo json_encode(['has_conflict' => false, 'conflicts' => []], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $result = $this->agentClient()->checkMaintenanceConflict($schedule, $target, $lookAhead);
        } catch (\RuntimeException $e) {
            $this->logger->warning('TargetController::conflictCheck: agent request failed', [
                'message' => $e->getMessage(),
            ]);
            header('Content-Type: application/json');
            echo json_encode(['has_conflict' => false, 'conflicts' => []], JSON_UNESCAPED_UNICODE);
            return;
        }

        header('Content-Type: application/json');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Delete a maintenance window.
     *
     * @param array<string, string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function deleteWindow(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->renderError(404, 'error_404', '/targets');
            return;
        }

        try {
            $this->agentClient()->deleteMaintenanceWindow($id);
        } catch (\RuntimeException $e) {
            $this->logger->error('TargetController::deleteWindow: failed', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/targets');
            return;
        }

        $this->logger->info('TargetController::deleteWindow: window deleted', ['id' => $id]);

        (new Response())->redirect('/targets');
    }
}
