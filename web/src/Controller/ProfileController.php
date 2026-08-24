<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Profile Controller
 *
 * Self-service profile management for the authenticated user.
 * Routes handled:
 *   GET  /profile – show profile (email, password change)
 *   POST /profile – save profile changes
 *
 * SSO users may only change their email address (role and username are
 * managed by the IdP). Local users may change email and password.
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
 * Class ProfileController
 *
 * Provides self-service profile updates for the currently authenticated user.
 */
class ProfileController extends BaseController
{
    /**
     * Show the profile form.
     *
     * @param array<string,string> $params Unused.
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

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $stmt = $pdo->prepare(
                'SELECT id, username, email, role, active, oauth_sub FROM users WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error('ProfileController::index: db error', ['message' => $e->getMessage()]);
            $this->renderError(500, 'error_500', '/profile');
            return;
        }

        if ($user === false) {
            (new Response())->redirect('/login');
            return;
        }

        $mailEnabled = trim((string) $this->config->get('mail.host', '')) !== '';

        $this->render('profile/index.php', $this->translator()->t('nav_profile'), [
            'user'        => $user,
            'mailEnabled' => $mailEnabled,
            'success'     => SessionManager::flash('_profile_success'),
            'errors'      => [],
        ], '/profile');
    }

    /**
     * Save profile changes.
     *
     * @param array<string,string> $params Unused.
     *
     * @return void
     */
    public function update(array $params): void
    {
        $userId = SessionManager::getUserId();
        if ($userId === null) {
            (new Response())->redirect('/login');
            return;
        }

        $email           = trim((string) ($_POST['email']            ?? ''));
        $password        = (string) ($_POST['password']              ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm']      ?? '');

        $errors = [];

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = $this->translator()->t('user_email_invalid');
        }

        if ($password !== '') {
            if (strlen($password) < 8) {
                $errors['password'] = $this->translator()->t('user_password_too_short');
            } elseif ($password !== $passwordConfirm) {
                $errors['password_confirm'] = $this->translator()->t('auth_password_mismatch');
            }
        }

        if (!empty($errors)) {
            try {
                $pdo  = Connection::getInstance()->getPdo();
                $stmt = $pdo->prepare('SELECT id, username, email, role, active, oauth_sub FROM users WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException) {
                $user = null;
            }
            $mailEnabled = trim((string) $this->config->get('mail.host', '')) !== '';
            $this->render('profile/index.php', $this->translator()->t('nav_profile'), [
                'user'        => $user,
                'mailEnabled' => $mailEnabled,
                'success'     => null,
                'errors'      => $errors,
            ], '/profile');
            return;
        }

        try {
            $pdo = Connection::getInstance()->getPdo();

            $isSSO = !empty(
                $pdo->prepare('SELECT oauth_sub FROM users WHERE id = :id LIMIT 1')
                    ->execute([':id' => $userId])
            );

            // Update email always (if provided)
            if ($email !== '') {
                $pdo->prepare('UPDATE users SET email = :email WHERE id = :id')
                    ->execute([':email' => $email, ':id' => $userId]);
            }

            // Update password only for local users
            if ($password !== '') {
                $fetchSSO = $pdo->prepare('SELECT oauth_sub FROM users WHERE id = :id LIMIT 1');
                $fetchSSO->execute([':id' => $userId]);
                $row = $fetchSSO->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['oauth_sub'] === null) {
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                        ->execute([':hash' => $hash, ':id' => $userId]);
                }
            }

            $this->auditLogger()->log('user.profile_update', 'user', $userId, SessionManager::getUsername() ?? '');

        } catch (PDOException $e) {
            $this->logger->error('ProfileController::update: db error', ['message' => $e->getMessage()]);
            $this->renderError(500, 'error_500', '/profile');
            return;
        }

        SessionManager::set('_profile_success', 'profile_saved');
        (new Response())->redirect('/profile');
    }
}
