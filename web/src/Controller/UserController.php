<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – User Management Controller
 *
 * Admin-only controller for managing UI user accounts.
 *
 * Routes handled:
 *   GET  /users                   – list all users
 *   GET  /users/new               – create user form
 *   POST /users/new               – create user
 *   GET  /users/{id}/edit         – edit user form
 *   POST /users/{id}/edit         – update user
 *   POST /users/{id}/role         – change role (legacy, kept for backward compat)
 *   POST /users/{id}/deactivate   – deactivate user (soft delete)
 *   POST /users/{id}/activate     – re-activate user
 *   POST /users/{id}/delete       – hard delete user
 *   POST /users/{id}/invite       – (re-)send invite email
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

use Cronmanager\Web\Auth\AuthTokenRepository;
use Cronmanager\Web\Auth\Mailer;
use Cronmanager\Web\Database\Connection;
use Cronmanager\Web\Http\Response;
use Cronmanager\Web\Session\SessionManager;
use PDO;
use PDOException;
use Throwable;

/**
 * Class UserController
 *
 * Provides an admin interface for listing, creating, editing, deactivating,
 * and deleting user accounts. The currently logged-in user cannot delete
 * or deactivate themselves.
 */
class UserController extends BaseController
{
    /** @var list<string> Valid role values */
    private const VALID_ROLES = ['viewer', 'operator', 'admin', 'api-only'];

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * List all users.
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function index(array $params): void
    {
        try {
            $pdo   = Connection::getInstance()->getPdo();
            $stmt  = $pdo->query(
                'SELECT id, username, role, active, email, agent_ids, oauth_sub, created_at
                   FROM users
                  ORDER BY username'
            );
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode agent_ids JSON for each user
            foreach ($users as &$u) {
                if (isset($u['agent_ids']) && is_string($u['agent_ids'])) {
                    $u['agent_ids'] = json_decode($u['agent_ids'], true) ?? [];
                }
            }
            unset($u);

            // Load agents for agent_ids display
            $agents = $pdo->query('SELECT id, name FROM agents ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error('UserController::index: database error', [
                'message' => $e->getMessage(),
            ]);
            $this->renderError(500, 'error_500', '/users');
            return;
        }

        $mailEnabled = $this->isMailEnabled();

        $this->render('users/list.php', $this->translator()->t('nav_users'), [
            'users'         => $users,
            'agents'        => $agents,
            'currentUserId' => SessionManager::getUserId(),
            'isAdmin'       => SessionManager::hasRole('admin'),
            'mailEnabled'   => $mailEnabled,
        ], '/users');
    }

    /**
     * Show the create-user form.
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function create(array $params): void
    {
        $agents = $this->loadAgents();
        $mailEnabled = $this->isMailEnabled();

        $this->render('users/form.php', $this->translator()->t('user_create'), [
            'user'        => null,
            'agents'      => $agents,
            'mailEnabled' => $mailEnabled,
            'errors'      => [],
        ], '/users');
    }

    /**
     * Process the create-user form submission.
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function store(array $params): void
    {
        $username  = trim((string) ($_POST['username']  ?? ''));
        $email     = trim((string) ($_POST['email']     ?? ''));
        $role      = trim((string) ($_POST['role']      ?? 'viewer'));
        $agentIds  = $this->parseAgentIds($_POST['agent_ids'] ?? []);
        $sendInvite = isset($_POST['send_invite']) && $this->isMailEnabled();
        $password  = (string) ($_POST['password'] ?? '');

        $errors = $this->validateUserInput($username, $email, $role, $password, $sendInvite);

        if (!empty($errors)) {
            $agents = $this->loadAgents();
            $this->render('users/form.php', $this->translator()->t('user_create'), [
                'user'        => ['username' => $username, 'email' => $email, 'role' => $role,
                                  'agent_ids' => $agentIds, 'active' => 1],
                'agents'      => $agents,
                'mailEnabled' => $this->isMailEnabled(),
                'errors'      => $errors,
            ], '/users');
            return;
        }

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $hash = $role === 'api-only' || $sendInvite
                ? null
                : password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            $agentJson = !empty($agentIds) ? json_encode(array_values($agentIds)) : null;

            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password_hash, role, active, email, agent_ids)
                 VALUES (:username, :hash, :role, 1, :email, :agent_ids)'
            );
            $stmt->execute([
                ':username'  => $username,
                ':hash'      => $hash,
                ':role'      => $role,
                ':email'     => $email !== '' ? $email : null,
                ':agent_ids' => $agentJson,
            ]);
            $newId = (int) $pdo->lastInsertId();

            $this->logger->info('UserController::store: user created', [
                'user_id'  => $newId,
                'username' => $username,
                'role'     => $role,
            ]);

            $this->auditLogger()->log('user.create', 'user', $newId, $username, [
                'role' => $role,
            ]);

            // Send invite email if requested
            if ($sendInvite && $email !== '') {
                $this->sendInviteEmail($pdo, $newId, $username, $email);
            }

        } catch (PDOException $e) {
            $this->logger->error('UserController::store: database error', [
                'message' => $e->getMessage(),
            ]);
            $agents = $this->loadAgents();
            $this->render('users/form.php', $this->translator()->t('user_create'), [
                'user'        => ['username' => $username, 'email' => $email, 'role' => $role,
                                  'agent_ids' => $agentIds, 'active' => 1],
                'agents'      => $agents,
                'mailEnabled' => $this->isMailEnabled(),
                'errors'      => ['db' => $this->translator()->t('error_500')],
            ], '/users');
            return;
        }

        (new Response())->redirect('/users');
    }

    /**
     * Show the edit-user form.
     *
     * @param array<string,string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function edit(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $stmt = $pdo->prepare(
                'SELECT id, username, role, active, email, agent_ids, oauth_sub, password_hash
                   FROM users WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error('UserController::edit: database error', ['message' => $e->getMessage()]);
            $this->renderError(500, 'error_500', '/users');
            return;
        }

        if ($user === false) {
            $this->renderError(404, 'error_404', '/users');
            return;
        }

        if (isset($user['agent_ids']) && is_string($user['agent_ids'])) {
            $user['agent_ids'] = json_decode($user['agent_ids'], true) ?? [];
        }

        $agents = $this->loadAgents();

        $this->render('users/form.php', $this->translator()->t('user_edit'), [
            'user'        => $user,
            'agents'      => $agents,
            'mailEnabled' => $this->isMailEnabled(),
            'errors'      => [],
        ], '/users');
    }

    /**
     * Process the edit-user form submission.
     *
     * @param array<string,string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function update(array $params): void
    {
        $id       = (int) ($params['id'] ?? 0);
        $email    = trim((string) ($_POST['email']    ?? ''));
        $role     = trim((string) ($_POST['role']     ?? ''));
        $agentIds = $this->parseAgentIds($_POST['agent_ids'] ?? []);
        $password = (string) ($_POST['password'] ?? '');

        if (!in_array($role, self::VALID_ROLES, strict: true)) {
            $this->renderError(422, 'error_500', '/users');
            return;
        }

        // Cannot change own role
        $isSelf = $id === SessionManager::getUserId();
        if ($isSelf && $role !== SessionManager::getRole()) {
            (new Response())->redirect('/users/' . $id . '/edit');
            return;
        }

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $stmt = $pdo->prepare(
                'SELECT id, username, role, active, email, agent_ids, password_hash
                   FROM users WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user === false) {
                (new Response())->redirect('/users');
                return;
            }

            $agentJson  = !empty($agentIds) ? json_encode(array_values($agentIds)) : null;
            $newHash    = $password !== ''
                ? password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])
                : null;

            if ($newHash !== null) {
                $pdo->prepare(
                    'UPDATE users SET role = :role, email = :email, agent_ids = :agent_ids,
                                     password_hash = :hash WHERE id = :id'
                )->execute([
                    ':role'      => $role,
                    ':email'     => $email !== '' ? $email : null,
                    ':agent_ids' => $agentJson,
                    ':hash'      => $newHash,
                    ':id'        => $id,
                ]);
            } else {
                $pdo->prepare(
                    'UPDATE users SET role = :role, email = :email, agent_ids = :agent_ids WHERE id = :id'
                )->execute([
                    ':role'      => $role,
                    ':email'     => $email !== '' ? $email : null,
                    ':agent_ids' => $agentJson,
                    ':id'        => $id,
                ]);
            }

            $this->logger->info('UserController::update: user updated', [
                'user_id' => $id,
                'role'    => $role,
            ]);

            $details = ['role' => $role];
            if ($newHash !== null) {
                $details['password_changed'] = true;
            }
            $this->auditLogger()->log('user.update', 'user', $id, (string) $user['username'], $details);

        } catch (PDOException $e) {
            $this->logger->error('UserController::update: database error', [
                'user_id' => $id,
                'message' => $e->getMessage(),
            ]);
            $this->renderError(500, 'error_500', '/users');
            return;
        }

        (new Response())->redirect('/users');
    }

    /**
     * Change a user's role (legacy route, kept for backward compat).
     *
     * @param array<string,string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function updateRole(array $params): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $role = trim((string) ($_POST['role'] ?? ''));

        if (!in_array($role, self::VALID_ROLES, strict: true)) {
            $this->renderError(422, 'error_500', '/users');
            return;
        }

        if ($id === SessionManager::getUserId()) {
            (new Response())->redirect('/users');
            return;
        }

        try {
            $pdo = Connection::getInstance()->getPdo();

            $fetch = $pdo->prepare('SELECT id, username, role FROM users WHERE id = :id');
            $fetch->execute([':id' => $id]);
            $user = $fetch->fetch(PDO::FETCH_ASSOC);

            if ($user === false) {
                (new Response())->redirect('/users');
                return;
            }

            $pdo->prepare('UPDATE users SET role = :role WHERE id = :id')
                ->execute([':role' => $role, ':id' => $id]);

            $this->logger->info('UserController::updateRole: role updated', [
                'user_id' => $id, 'role' => $role,
            ]);

            $this->auditLogger()->log(
                'user.update_role', 'user', $id, (string) $user['username'],
                ['from' => (string) $user['role'], 'to' => $role],
            );
        } catch (PDOException $e) {
            $this->logger->error('UserController::updateRole: database error', [
                'user_id' => $id, 'message' => $e->getMessage(),
            ]);
            $this->renderError(500, 'error_500', '/users');
            return;
        }

        (new Response())->redirect('/users');
    }

    /**
     * Deactivate a user account (soft delete – login blocked, data retained).
     *
     * @param array<string,string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function deactivate(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);

        if ($id === SessionManager::getUserId()) {
            $this->logger->warning('UserController::deactivate: attempt to deactivate own account', [
                'user_id' => $id,
            ]);
            (new Response())->redirect('/users');
            return;
        }

        try {
            $pdo = Connection::getInstance()->getPdo();

            $fetch = $pdo->prepare('SELECT id, username FROM users WHERE id = :id');
            $fetch->execute([':id' => $id]);
            $user = $fetch->fetch(PDO::FETCH_ASSOC);

            if ($user === false) {
                (new Response())->redirect('/users');
                return;
            }

            $pdo->prepare('UPDATE users SET active = 0 WHERE id = :id')->execute([':id' => $id]);

            $this->logger->info('UserController::deactivate: user deactivated', ['user_id' => $id]);
            $this->auditLogger()->log('user.deactivate', 'user', $id, (string) $user['username']);

        } catch (PDOException $e) {
            $this->logger->error('UserController::deactivate: database error', [
                'user_id' => $id, 'message' => $e->getMessage(),
            ]);
            $this->renderError(500, 'error_500', '/users');
            return;
        }

        (new Response())->redirect('/users');
    }

    /**
     * Re-activate a previously deactivated user account.
     *
     * @param array<string,string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function activate(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);

        try {
            $pdo = Connection::getInstance()->getPdo();

            $fetch = $pdo->prepare('SELECT id, username FROM users WHERE id = :id');
            $fetch->execute([':id' => $id]);
            $user = $fetch->fetch(PDO::FETCH_ASSOC);

            if ($user === false) {
                (new Response())->redirect('/users');
                return;
            }

            $pdo->prepare('UPDATE users SET active = 1 WHERE id = :id')->execute([':id' => $id]);

            $this->logger->info('UserController::activate: user activated', ['user_id' => $id]);
            $this->auditLogger()->log('user.activate', 'user', $id, (string) $user['username']);

        } catch (PDOException $e) {
            $this->logger->error('UserController::activate: database error', [
                'user_id' => $id, 'message' => $e->getMessage(),
            ]);
            $this->renderError(500, 'error_500', '/users');
            return;
        }

        (new Response())->redirect('/users');
    }

    /**
     * Delete a user account (hard delete).
     *
     * @param array<string,string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function destroy(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);

        if ($id === SessionManager::getUserId()) {
            $this->logger->warning('UserController::destroy: attempt to delete own account', [
                'user_id' => $id,
            ]);
            (new Response())->redirect('/users');
            return;
        }

        try {
            $pdo = Connection::getInstance()->getPdo();

            $fetch = $pdo->prepare('SELECT id, username FROM users WHERE id = :id');
            $fetch->execute([':id' => $id]);
            $user = $fetch->fetch(PDO::FETCH_ASSOC);

            if ($user === false) {
                (new Response())->redirect('/users');
                return;
            }

            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);

            $this->logger->info('UserController::destroy: user deleted', ['user_id' => $id]);
            $this->auditLogger()->log('user.delete', 'user', $id, (string) $user['username']);

        } catch (PDOException $e) {
            $this->logger->error('UserController::destroy: database error', [
                'user_id' => $id, 'message' => $e->getMessage(),
            ]);
            $this->renderError(500, 'error_500', '/users');
            return;
        }

        (new Response())->redirect('/users');
    }

    /**
     * (Re-)send an invite email to a user.
     *
     * @param array<string,string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function resendInvite(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);

        if (!$this->isMailEnabled()) {
            (new Response())->redirect('/users');
            return;
        }

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user === false || (string) ($user['email'] ?? '') === '') {
                (new Response())->redirect('/users');
                return;
            }

            $this->sendInviteEmail($pdo, $id, (string) $user['username'], (string) $user['email']);

        } catch (PDOException $e) {
            $this->logger->error('UserController::resendInvite: database error', [
                'user_id' => $id, 'message' => $e->getMessage(),
            ]);
        }

        (new Response())->redirect('/users');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether mail is configured (SMTP host set).
     */
    private function isMailEnabled(): bool
    {
        return trim((string) $this->config->get('mail.host', '')) !== '';
    }

    /**
     * Load all agents for the agent_ids multi-select.
     *
     * @return list<array<string,mixed>>
     */
    private function loadAgents(): array
    {
        try {
            $pdo = Connection::getInstance()->getPdo();
            return $pdo->query('SELECT id, name FROM agents ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Parse the agent_ids POST field into a sorted int array.
     *
     * @param mixed $raw
     *
     * @return list<int>
     */
    private function parseAgentIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = array_map('intval', $raw);
        $ids = array_filter($ids, static fn(int $v) => $v > 0);
        sort($ids);
        return array_values($ids);
    }

    /**
     * Validate user creation / update input.
     *
     * @param string $username
     * @param string $email
     * @param string $role
     * @param string $password     Empty = skip password validation (edit mode)
     * @param bool   $sendInvite   True = password not required
     *
     * @return array<string,string> Field → error message map
     */
    private function validateUserInput(
        string $username,
        string $email,
        string $role,
        string $password,
        bool   $sendInvite,
    ): array {
        $errors = [];
        $t      = $this->translator();

        if ($username === '') {
            $errors['username'] = $t->t('validation_required');
        } elseif (!preg_match('/^[a-zA-Z0-9@._+\-]{2,128}$/', $username)) {
            $errors['username'] = $t->t('user_username_invalid');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = $t->t('user_email_invalid');
        }

        if ($sendInvite && $email === '') {
            $errors['email'] = $t->t('user_email_required_for_invite');
        }

        if (!in_array($role, self::VALID_ROLES, strict: true)) {
            $errors['role'] = $t->t('validation_required');
        }

        // Password required only when not sending invite and not api-only
        if (!$sendInvite && $role !== 'api-only' && $password === '') {
            $errors['password'] = $t->t('user_password_required');
        }

        if ($password !== '' && strlen($password) < 8) {
            $errors['password'] = $t->t('user_password_too_short');
        }

        return $errors;
    }

    /**
     * Generate an invite token and dispatch the invite email.
     *
     * @param \PDO   $pdo
     * @param int    $userId
     * @param string $username
     * @param string $email
     *
     * @return void
     */
    private function sendInviteEmail(\PDO $pdo, int $userId, string $username, string $email): void
    {
        try {
            $repo      = new AuthTokenRepository($pdo);
            $plainToken = $repo->create($userId, 'invite', 72); // 72 h

            $baseUrl = rtrim((string) $this->config->get('app.web_url', ''), '/');
            $link    = $baseUrl . '/auth/invite/' . urlencode($plainToken);

            $mailer = new Mailer($this->config, $this->logger);
            $mailer->sendInvite($email, $username, $link);

            $this->logger->info('UserController: invite email sent', [
                'user_id'  => $userId,
                'username' => $username,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('UserController: failed to send invite email', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
