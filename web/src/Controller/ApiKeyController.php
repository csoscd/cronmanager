<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – API Key Controller
 *
 * Allows every authenticated user to manage their own API keys.
 *
 * Routes handled:
 *   GET  /api-keys            – list own keys
 *   GET  /api-keys/create     – show create form
 *   POST /api-keys            – create a new key (shows plain text once)
 *   POST /api-keys/{id}/delete – delete a key
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

use Cronmanager\Web\Auth\ApiKeyRepository;
use Cronmanager\Web\Auth\ScopeHelper;
use Cronmanager\Web\Database\Connection;
use Cronmanager\Web\Http\Response;
use Cronmanager\Web\Repository\AgentRepository;
use Cronmanager\Web\Session\SessionManager;

/**
 * Class ApiKeyController
 *
 * Session-authenticated controller; no CSRF skip needed because the Router
 * already validates the CSRF token for all POST requests on protected routes.
 */
class ApiKeyController extends BaseController
{
    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * List all API keys owned by the current user.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function index(array $params): void
    {
        $userId = SessionManager::getUserId();

        if ($userId === null) {
            (new Response())->redirect('/login');
            return;
        }

        $pdo  = Connection::getInstance()->getPdo();
        $repo = new ApiKeyRepository($pdo);
        $keys = $repo->listForUser($userId);

        $this->render('api_keys/index.php', $this->translator()->t('nav_api_keys'), [
            'keys'       => $keys,
            'scopeHelper' => ScopeHelper::class,
        ], '/api-keys');
    }

    /**
     * Show the create-key form.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function create(array $params): void
    {
        $userId = SessionManager::getUserId();
        $role   = SessionManager::getRole() ?? 'viewer';

        $pdo        = Connection::getInstance()->getPdo();
        $agentRepo  = new AgentRepository($pdo);
        $agents     = $agentRepo->findAll();

        $allowedScopes = ScopeHelper::allowedScopesForRole($role);

        $this->render('api_keys/create.php', $this->translator()->t('nav_api_keys_create'), [
            'allowedScopes' => $allowedScopes,
            'profiles'      => ScopeHelper::PROFILES,
            'allScopes'     => ScopeHelper::ALL_SCOPES,
            'scopeHelper'   => ScopeHelper::class,
            'agents'        => $agents,
        ], '/api-keys');
    }

    /**
     * Create a new API key.
     *
     * Validates POST fields, creates the key, and redirects to a one-time
     * display page that shows the plain-text key.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function store(array $params): void
    {
        $userId = SessionManager::getUserId();
        $role   = SessionManager::getRole() ?? 'viewer';

        if ($userId === null) {
            (new Response())->redirect('/login');
            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '') {
            (new Response())->redirect('/api-keys/create');
            return;
        }

        // Scopes: filter to only those the user's role permits
        $rawScopes     = (array) ($_POST['scopes'] ?? []);
        $allowedScopes = ScopeHelper::allowedScopesForRole($role);
        $scopes        = ScopeHelper::filterScopes(
            array_map('strval', $rawScopes),
            $allowedScopes
        );

        if ($scopes === []) {
            (new Response())->redirect('/api-keys/create');
            return;
        }

        // Agent IDs: null = all agents
        $rawAgentIds = $_POST['agent_ids'] ?? null;
        $agentIds    = null;

        if (is_array($rawAgentIds) && $rawAgentIds !== []) {
            $agentIds = array_map('intval', $rawAgentIds);
        }

        // Expiry date
        $rawExpires = trim((string) ($_POST['expires_at'] ?? ''));
        $expiresAt  = null;

        if ($rawExpires !== '') {
            try {
                $expiresAt = new \DateTimeImmutable($rawExpires . ' 23:59:59');
            } catch (\Exception) {
                $expiresAt = null;
            }
        }

        // IP whitelist
        $rawIp = trim((string) ($_POST['ip_whitelist'] ?? ''));
        $ipWhitelist = null;

        if ($rawIp !== '') {
            $ipWhitelist = array_filter(
                array_map('trim', explode(',', $rawIp)),
                fn($v) => $v !== ''
            );
            $ipWhitelist = $ipWhitelist !== [] ? array_values($ipWhitelist) : null;
        }

        try {
            $pdo    = Connection::getInstance()->getPdo();
            $repo   = new ApiKeyRepository($pdo);
            $result = $repo->create($userId, $name, $scopes, $agentIds, $expiresAt, $ipWhitelist);

            $this->logger->info('ApiKeyController: API key created', [
                'user_id' => $userId,
                'key_id'  => $result['key']->id,
                'scopes'  => $scopes,
            ]);

            // Store plain text in session for one-time display
            SessionManager::set('_api_key_plain', $result['plainText']);
            SessionManager::set('_api_key_name',  $result['key']->name);

            (new Response())->redirect('/api-keys/created');
        } catch (\Throwable $e) {
            $this->logger->error('ApiKeyController: failed to create key', [
                'message' => $e->getMessage(),
            ]);
            $this->renderError(500, 'error_500', '/api-keys');
        }
    }

    /**
     * Show the newly created key one time.
     *
     * Reads the plain-text key from the session and clears it immediately so
     * subsequent page loads cannot show it again.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function created(array $params): void
    {
        $plainText = SessionManager::get('_api_key_plain');
        $keyName   = SessionManager::get('_api_key_name');

        // Clear immediately
        SessionManager::set('_api_key_plain', null);
        SessionManager::set('_api_key_name',  null);

        if ($plainText === null) {
            (new Response())->redirect('/api-keys');
            return;
        }

        $this->render('api_keys/created.php', $this->translator()->t('nav_api_keys'), [
            'plainText' => $plainText,
            'keyName'   => $keyName,
        ], '/api-keys');
    }

    /**
     * Delete an API key.
     *
     * Only the owning user may delete their own keys.
     *
     * @param array<string, string> $params Path parameters: id.
     *
     * @return void
     */
    public function destroy(array $params): void
    {
        $userId = SessionManager::getUserId();

        if ($userId === null) {
            (new Response())->redirect('/login');
            return;
        }

        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            (new Response())->redirect('/api-keys');
            return;
        }

        try {
            $pdo     = Connection::getInstance()->getPdo();
            $repo    = new ApiKeyRepository($pdo);
            $deleted = $repo->deleteForUser($id, $userId);

            if ($deleted) {
                $this->logger->info('ApiKeyController: API key deleted', [
                    'key_id'  => $id,
                    'user_id' => $userId,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('ApiKeyController: failed to delete key', [
                'message' => $e->getMessage(),
            ]);
        }

        (new Response())->redirect('/api-keys');
    }
}
