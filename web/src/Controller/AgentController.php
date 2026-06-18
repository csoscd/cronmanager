<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Agent Controller
 *
 * Manages the remote agent configurations stored in the `agents` table.
 * All mutating actions are admin-only.  The select action is available to
 * all authenticated users so they can switch between agents.
 *
 * Routes handled (all under /settings/agents):
 *   GET  /settings/agents/create      – show create form
 *   POST /settings/agents             – store new agent
 *   GET  /settings/agents/{id}/edit   – show edit form
 *   POST /settings/agents/{id}        – update agent
 *   POST /settings/agents/{id}/delete – delete agent
 *   POST /settings/agents/{id}/test   – test connectivity (JSON)
 *   POST /agent/select                – switch active agent (session)
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

use Cronmanager\Web\Agent\HostAgentClient;
use Cronmanager\Web\Database\Connection;
use Cronmanager\Web\Http\Response;
use Cronmanager\Web\Repository\AgentRepository;
use Cronmanager\Web\Session\SessionManager;

/**
 * Class AgentController
 *
 * CRUD for remote agents + session-based agent switching.
 */
final class AgentController extends BaseController
{
    // -------------------------------------------------------------------------
    // GET /settings/agents/create
    // -------------------------------------------------------------------------

    /**
     * Display the create-agent form.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function create(array $params = []): void
    {
        $error = SessionManager::get('_flash_agent_error');
        SessionManager::remove('_flash_agent_error');

        $this->render('agents/form.php', $this->translator()->t('agent_create_title'), [
            'agent'     => null,
            'error'     => $error,
            'isEdit'    => false,
        ], '/settings');
    }

    // -------------------------------------------------------------------------
    // POST /settings/agents
    // -------------------------------------------------------------------------

    /**
     * Validate and persist a new agent.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function store(array $params = []): void
    {
        $data  = $this->extractPostData();
        $error = $this->validate($data);

        if ($error !== null) {
            SessionManager::set('_flash_agent_error', $error);
            (new Response())->redirect('/settings/agents/create');
            return;
        }

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $repo = new AgentRepository($pdo);
            $repo->create($data);
        } catch (\Throwable $e) {
            $this->logger->error('AgentController::store: failed', ['message' => $e->getMessage()]);
            SessionManager::set('_flash_agent_error', 'error_500');
            (new Response())->redirect('/settings/agents/create');
            return;
        }

        SessionManager::set('_flash_agent_saved', '1');
        (new Response())->redirect('/settings');
    }

    // -------------------------------------------------------------------------
    // GET /settings/agents/{id}/edit
    // -------------------------------------------------------------------------

    /**
     * Display the edit form pre-filled with the agent's current values.
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     *
     * @return void
     */
    public function edit(array $params): void
    {
        $id    = (int) ($params['id'] ?? 0);
        $agent = $this->findOrFail($id);

        if ($agent === null) {
            $this->renderError(404, 'error_404', '/settings');
            return;
        }

        $error = SessionManager::get('_flash_agent_error');
        SessionManager::remove('_flash_agent_error');

        $this->render('agents/form.php', $this->translator()->t('agent_edit_title'), [
            'agent'     => $agent,
            'error'     => $error,
            'isEdit'    => true,
        ], '/settings');
    }

    // -------------------------------------------------------------------------
    // POST /settings/agents/{id}
    // -------------------------------------------------------------------------

    /**
     * Validate and persist changes to an existing agent.
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     *
     * @return void
     */
    public function update(array $params): void
    {
        $id    = (int) ($params['id'] ?? 0);
        $agent = $this->findOrFail($id);

        if ($agent === null) {
            $this->renderError(404, 'error_404', '/settings');
            return;
        }

        $data  = $this->extractPostData();
        $error = $this->validate($data, allowEmptySecret: true);

        if ($error !== null) {
            SessionManager::set('_flash_agent_error', $error);
            (new Response())->redirect("/settings/agents/{$id}/edit");
            return;
        }

        // Keep existing secret when the field is left blank on the edit form
        if ($data['hmac_secret'] === '') {
            $data['hmac_secret'] = (string) $agent['hmac_secret'];
        }

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $repo = new AgentRepository($pdo);
            $repo->update($id, $data);
        } catch (\Throwable $e) {
            $this->logger->error('AgentController::update: failed', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);
            SessionManager::set('_flash_agent_error', 'error_500');
            (new Response())->redirect("/settings/agents/{$id}/edit");
            return;
        }

        // Invalidate the agent-version session cache for the updated agent
        unset($_SESSION['_cm_agent_version_' . $id], $_SESSION['_cm_agent_version_ts_' . $id]);

        SessionManager::set('_flash_agent_saved', '1');
        (new Response())->redirect('/settings');
    }

    // -------------------------------------------------------------------------
    // POST /settings/agents/{id}/delete
    // -------------------------------------------------------------------------

    /**
     * Delete an agent.
     *
     * Refuses to delete the last remaining agent to prevent a locked-out state.
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     *
     * @return void
     */
    public function destroy(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $repo = new AgentRepository($pdo);

            if ($repo->count() <= 1) {
                SessionManager::set('_flash_agent_error', 'agent_delete_last');
                (new Response())->redirect('/settings');
                return;
            }

            $repo->delete($id);

            // If the deleted agent was the one currently selected, clear session
            if ((int) SessionManager::get('selected_agent_id', 0) === $id) {
                SessionManager::remove('selected_agent_id');
            }
        } catch (\Throwable $e) {
            $this->logger->error('AgentController::destroy: failed', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);
        }

        (new Response())->redirect('/settings');
    }

    // -------------------------------------------------------------------------
    // POST /settings/agents/{id}/test   → JSON
    // -------------------------------------------------------------------------

    /**
     * Test connectivity to the given agent by calling its /health endpoint.
     *
     * Returns a JSON object: { "success": bool, "version": "...", "message": "..." }
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     *
     * @return void
     */
    public function test(array $params): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $id    = (int) ($params['id'] ?? 0);
        $agent = $this->findOrFail($id);

        if ($agent === null) {
            echo json_encode(['success' => false, 'message' => 'Agent not found']);
            return;
        }

        try {
            $client = new HostAgentClient(
                logger:      $this->logger,
                agentUrl:    (string)  $agent['url'],
                hmacSecret:  (string)  $agent['hmac_secret'],
                timeout:     (int)     ($agent['timeout']     ?? 10),
                sslVerify:   (bool)    ($agent['ssl_verify']  ?? true),
                sslCaBundle: (string)  ($agent['ssl_ca_bundle'] ?? ''),
            );

            $health  = $client->get('/health');
            $version = (string) ($health['version'] ?? 'unknown');

            echo json_encode([
                'success' => true,
                'version' => $version,
                'message' => '',
            ]);
        } catch (\Throwable $e) {
            echo json_encode([
                'success' => false,
                'version' => '',
                'message' => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // POST /agent/select
    // -------------------------------------------------------------------------

    /**
     * Switch the active agent for the current session.
     *
     * POST fields:
     *   agent_id (int)  ID of the agent to activate.
     *
     * Redirects to the Referer header URL, falling back to /dashboard.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function select(array $params = []): void
    {
        $agentId = (int) ($_POST['agent_id'] ?? 0);

        if ($agentId > 0) {
            try {
                $pdo   = Connection::getInstance()->getPdo();
                $agent = (new AgentRepository($pdo))->findById($agentId);

                if ($agent !== null && (bool) $agent['enabled']) {
                    SessionManager::set('selected_agent_id', $agentId);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('AgentController::select: lookup failed', [
                    'agent_id' => $agentId,
                    'message'  => $e->getMessage(),
                ]);
            }
        }

        // Redirect to Referer or dashboard (stay on-site)
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $path    = parse_url($referer, PHP_URL_PATH) ?? '';
        $query   = parse_url($referer, PHP_URL_QUERY) ?? '';
        $back    = $path !== '' ? $path . ($query !== '' ? '?' . $query : '') : '/dashboard';

        (new Response())->redirect($back);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Load an agent by ID or return null when not found.
     *
     * @param int $id Agent primary key.
     *
     * @return array<string, mixed>|null
     */
    private function findOrFail(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $pdo = Connection::getInstance()->getPdo();
            return (new AgentRepository($pdo))->findById($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extract and sanitise agent fields from $_POST.
     *
     * @return array<string, mixed>
     */
    private function extractPostData(): array
    {
        return [
            'name'          => trim((string) ($_POST['name']          ?? '')),
            'description'   => trim((string) ($_POST['description']   ?? '')),
            'url'           => rtrim(trim((string) ($_POST['url']     ?? '')), '/'),
            'hmac_secret'   => trim((string) ($_POST['hmac_secret']   ?? '')),
            'timeout'       => max(1, (int) ($_POST['timeout']        ?? 10)),
            'ssl_verify'    => isset($_POST['ssl_verify']) ? 1 : 0,
            'ssl_ca_bundle' => trim((string) ($_POST['ssl_ca_bundle'] ?? '')),
            'enabled'       => isset($_POST['enabled']) ? 1 : 0,
            'sort_order'    => (int) ($_POST['sort_order']            ?? 0),
        ];
    }

    /**
     * Validate agent data and return a translation key for the first error
     * found, or null when the data is valid.
     *
     * @param array<string, mixed> $data             Extracted post data.
     * @param bool                 $allowEmptySecret Allow an empty hmac_secret (edit mode keeps existing).
     *
     * @return string|null Translation key or null when valid.
     */
    private function validate(array $data, bool $allowEmptySecret = false): ?string
    {
        if ((string) $data['name'] === '') {
            return 'agent_error_name_required';
        }

        if (mb_strlen((string) $data['name']) > 100) {
            return 'agent_error_name_too_long';
        }

        if ((string) $data['url'] === '') {
            return 'agent_error_url_required';
        }

        if (!str_starts_with((string) $data['url'], 'http://') && !str_starts_with((string) $data['url'], 'https://')) {
            return 'agent_error_url_invalid';
        }

        if (!$allowEmptySecret && (string) $data['hmac_secret'] === '') {
            return 'agent_error_secret_required';
        }

        return null;
    }
}
