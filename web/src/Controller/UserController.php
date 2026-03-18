<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – User Management Controller
 *
 * Admin-only controller for managing UI user accounts.
 *
 * Routes handled:
 *   GET  /users              – list all users
 *   POST /users/{id}/role    – change a user's role
 *   POST /users/{id}/delete  – delete a user
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

use Cronmanager\Web\Database\Connection;
use Cronmanager\Web\Http\Response;
use Cronmanager\Web\Session\SessionManager;
use PDO;
use PDOException;

/**
 * Class UserController
 *
 * Provides a simple admin interface for listing users, changing roles,
 * and deleting accounts.  The currently logged-in user cannot delete
 * or demote themselves.
 */
class UserController extends BaseController
{
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
                'SELECT id, username, role, oauth_sub, created_at
                   FROM users
                  ORDER BY username'
            );
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error('UserController::index: database error', [
                'message' => $e->getMessage(),
            ]);
            $this->renderError(500, 'error_500', '/users');
            return;
        }

        $this->render('users/list.php', $this->translator()->t('nav_users'), [
            'users'         => $users,
            'currentUserId' => SessionManager::getUserId(),
            'isAdmin'       => SessionManager::hasRole('admin'),
        ], '/users');
    }

    /**
     * Change a user's role.
     *
     * Accepts POST field: role ('admin' or 'view').
     * A user cannot change their own role.
     *
     * @param array<string,string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function updateRole(array $params): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $role = trim((string) ($_POST['role'] ?? ''));

        // Guard: valid role values only
        if (!in_array($role, ['admin', 'view'], strict: true)) {
            $this->renderError(422, 'error_500', '/users');
            return;
        }

        // Guard: cannot change own role
        if ($id === SessionManager::getUserId()) {
            $this->logger->warning('UserController::updateRole: attempt to change own role', [
                'user_id' => $id,
            ]);
            (new Response())->redirect('/users');
            return;
        }

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
            $stmt->execute([':role' => $role, ':id' => $id]);

            $this->logger->info('UserController::updateRole: role updated', [
                'user_id' => $id,
                'role'    => $role,
            ]);
        } catch (PDOException $e) {
            $this->logger->error('UserController::updateRole: database error', [
                'user_id' => $id,
                'message' => $e->getMessage(),
            ]);
            $this->renderError(500, 'error_500', '/users');
            return;
        }

        (new Response())->redirect('/users');
    }

    /**
     * Delete a user account.
     *
     * A user cannot delete their own account.
     *
     * @param array<string,string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function destroy(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);

        // Guard: cannot delete own account
        if ($id === SessionManager::getUserId()) {
            $this->logger->warning('UserController::destroy: attempt to delete own account', [
                'user_id' => $id,
            ]);
            (new Response())->redirect('/users');
            return;
        }

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute([':id' => $id]);

            $this->logger->info('UserController::destroy: user deleted', ['user_id' => $id]);
        } catch (PDOException $e) {
            $this->logger->error('UserController::destroy: database error', [
                'user_id' => $id,
                'message' => $e->getMessage(),
            ]);
            $this->renderError(500, 'error_500', '/users');
            return;
        }

        (new Response())->redirect('/users');
    }
}
