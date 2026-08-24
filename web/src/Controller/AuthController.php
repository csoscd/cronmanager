<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Authentication Controller
 *
 * Handles all authentication-related routes:
 *   GET  /login         – display the login form
 *   POST /login         – process local username/password login
 *   GET  /auth/callback – handle the OIDC provider callback
 *   GET  /logout        – destroy the session and redirect to login
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

use Cronmanager\Web\Auth\AuthTokenRepository;
use Cronmanager\Web\Auth\LocalAuthProvider;
use Cronmanager\Web\Auth\Mailer;
use Cronmanager\Web\Auth\OidcAuthProvider;
use Cronmanager\Web\Database\Connection;
use Cronmanager\Web\Http\Response;
use Cronmanager\Web\I18n\Translator;
use Cronmanager\Web\Session\SessionManager;
use Monolog\Logger;
use Noodlehaus\Config;
use Throwable;

/**
 * Class AuthController
 *
 * Thin controller that delegates business logic to the auth providers.
 * Rendering is done via plain PHP template files included from the
 * templates/ directory.
 */
class AuthController
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param Config $config Noodlehaus configuration instance.
     * @param Logger $logger Monolog logger.
     */
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Display the login page.
     *
     * Redirects to /dashboard if the user is already authenticated.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function showLogin(array $params): void
    {
        if (SessionManager::isAuthenticated()) {
            (new Response())->redirect('/dashboard');
            return;
        }

        $translator  = new Translator($this->config);
        $oidcEnabled = (bool) $this->config->get('auth.oidc_enabled', false);

        // Consume single-use flash messages
        $error          = SessionManager::get('_flash_error');
        $lockoutMinutes = SessionManager::get('_flash_lockout_minutes');
        SessionManager::remove('_flash_error');
        SessionManager::remove('_flash_lockout_minutes');

        $this->renderLogin([
            'oidcEnabled'    => $oidcEnabled,
            'error'          => $error,
            'lockoutMinutes' => $lockoutMinutes,
            'translator'     => $translator,
            'config'         => $this->config,
            'csrf_token'     => SessionManager::getCsrfToken(),
        ]);
    }

    /**
     * Process a local username/password login form submission.
     *
     * On success:  stores the user in the session, redirects to /dashboard.
     * On failure:  stores an error flash message, redirects back to /login.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function handleLogin(array $params): void
    {
        $response = new Response();
        $ip       = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        // ------------------------------------------------------------------
        // Rate limiting: reject locked IPs before any credential processing
        // ------------------------------------------------------------------
        if (!SessionManager::isLoginAllowed($ip)) {
            $remaining = (int) ceil(SessionManager::getLockoutRemaining($ip) / 60);
            $this->logger->warning('Login blocked: rate limit exceeded', ['ip' => $ip]);
            SessionManager::set('_flash_error', 'login_error_locked');
            SessionManager::set('_flash_lockout_minutes', $remaining);
            $response->redirect('/login');
            return;
        }

        // ------------------------------------------------------------------
        // Validation
        // ------------------------------------------------------------------
        if ($username === '' || $password === '') {
            $this->logger->debug('Login attempt with empty credentials', ['ip' => $ip]);
            SessionManager::set('_flash_error', 'login_error_required');
            $response->redirect('/login');
            return;
        }

        // ------------------------------------------------------------------
        // Authentication
        // ------------------------------------------------------------------
        try {
            $pdo      = Connection::getInstance()->getPdo();
            $provider = new LocalAuthProvider($pdo, $this->logger);
            $user     = $provider->authenticate($username, $password);
        } catch (Throwable $e) {
            $this->logger->error('Login: exception during authentication', [
                'message' => $e->getMessage(),
            ]);
            SessionManager::set('_flash_error', 'error_500');
            $response->redirect('/login');
            return;
        }

        if ($user === null) {
            // Record failure against this IP; do NOT reveal whether the
            // username exists (uniform error message for both cases).
            SessionManager::recordLoginFailure($ip);
            $this->logger->info('Login failed', [
                'username_hash' => hash('sha256', $username),
                'ip'            => $ip,
            ]);
            SessionManager::set('_flash_error', 'login_error_credentials');
            $response->redirect('/login');
            return;
        }

        // ------------------------------------------------------------------
        // Success
        // ------------------------------------------------------------------
        SessionManager::clearLoginFailures($ip);
        SessionManager::login($user);
        SessionManager::remove('_flash_error');

        $this->logger->info('Login successful', [
            'username' => $user['username'] ?? '',
            'ip'       => $ip,
        ]);

        // Redirect to the page the user originally requested (if any),
        // falling back to the dashboard.  Accept only on-site paths
        // (must start with '/') to prevent open-redirect attacks.
        $redirect = SessionManager::flash('_login_redirect');
        $target   = (is_string($redirect) && str_starts_with($redirect, '/'))
            ? $redirect
            : '/dashboard';

        $response->redirect($target);
    }

    /**
     * Handle the OAuth 2.0 / OIDC callback redirect.
     *
     * Validates the state, exchanges the code for tokens, resolves the local
     * user and establishes the session.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function handleOidcCallback(array $params): void
    {
        $response = new Response();

        // ------------------------------------------------------------------
        // Guard: OIDC must be enabled
        // ------------------------------------------------------------------
        if (!(bool) $this->config->get('auth.oidc_enabled', false)) {
            $response->redirect('/login');
            return;
        }

        // ------------------------------------------------------------------
        // Provider error response
        // ------------------------------------------------------------------
        $oauthError = (string) ($_GET['error'] ?? '');
        if ($oauthError !== '') {
            $oauthErrorDesc = (string) ($_GET['error_description'] ?? $oauthError);
            $this->logger->warning('OIDC callback: provider returned error', [
                'error'       => $oauthError,
                'description' => $oauthErrorDesc,
            ]);
            SessionManager::set('_flash_error', 'login_error_credentials');
            $response->redirect('/login');
            return;
        }

        // ------------------------------------------------------------------
        // Required callback parameters
        // ------------------------------------------------------------------
        $code  = (string) ($_GET['code']  ?? '');
        $state = (string) ($_GET['state'] ?? '');

        if ($code === '' || $state === '') {
            $this->logger->warning('OIDC callback: missing code or state');
            SessionManager::set('_flash_error', 'login_error_credentials');
            $response->redirect('/login');
            return;
        }

        // ------------------------------------------------------------------
        // Token exchange + user resolution
        // ------------------------------------------------------------------
        try {
            $pdo      = Connection::getInstance()->getPdo();
            $provider = new OidcAuthProvider($this->config, $pdo, $this->logger);
            $user     = $provider->handleCallback($code, $state);
        } catch (Throwable $e) {
            $this->logger->error('OIDC callback: exception', [
                'message' => $e->getMessage(),
            ]);
            SessionManager::set('_flash_error', 'error_500');
            $response->redirect('/login');
            return;
        }

        if ($user === null) {
            $this->logger->warning('OIDC callback: user resolution returned null');
            SessionManager::set('_flash_error', 'login_error_credentials');
            $response->redirect('/login');
            return;
        }

        // ------------------------------------------------------------------
        // Success
        // ------------------------------------------------------------------
        SessionManager::login($user);
        SessionManager::remove('_flash_error');

        $this->logger->info('OIDC login successful', [
            'username' => $user['username'] ?? '',
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        // Redirect to the page the user originally requested (if any).
        $redirect = SessionManager::flash('_login_redirect');
        $target   = (is_string($redirect) && str_starts_with($redirect, '/'))
            ? $redirect
            : '/dashboard';

        $response->redirect($target);
    }

    /**
     * Destroy the current session and redirect to the login page.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function logout(array $params): void
    {
        $username = SessionManager::getUsername() ?? 'unknown';

        SessionManager::logout();

        $this->logger->info('User logged out', ['username' => $username]);

        (new Response())->redirect('/login');
    }

    /**
     * Show the forgot-password form (GET /auth/forgot-password).
     *
     * Hidden when SMTP is not configured.
     *
     * @param array<string,string> $params Unused.
     *
     * @return void
     */
    public function showForgotPassword(array $params): void
    {
        if (!$this->isMailEnabled()) {
            (new Response())->redirect('/login');
            return;
        }

        $this->renderStandalone('auth/forgot_password.php', $this->t('auth_forgot_password'), [
            'error'   => null,
            'success' => null,
        ]);
    }

    /**
     * Process the forgot-password form (POST /auth/forgot-password).
     *
     * Always shows a success message regardless of whether the email was found,
     * to prevent user enumeration.
     *
     * @param array<string,string> $params Unused.
     *
     * @return void
     */
    public function handleForgotPassword(array $params): void
    {
        if (!$this->isMailEnabled()) {
            (new Response())->redirect('/login');
            return;
        }

        $email = trim((string) ($_POST['email'] ?? ''));

        if ($email !== '') {
            try {
                $pdo  = Connection::getInstance()->getPdo();
                $stmt = $pdo->prepare(
                    'SELECT id, username, active FROM users WHERE email = :email AND oauth_sub IS NULL LIMIT 1'
                );
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($user && (int) $user['active'] === 1) {
                    $repo  = new AuthTokenRepository($pdo);
                    $token = $repo->create((int) $user['id'], 'reset', 2);

                    $baseUrl  = rtrim((string) $this->config->get('app.web_url', ''), '/');
                    $link     = $baseUrl . '/auth/reset/' . urlencode($token);

                    $mailer = new Mailer($this->config, $this->logger);
                    $mailer->sendPasswordReset($email, (string) $user['username'], $link);
                }
            } catch (Throwable $e) {
                $this->logger->error('ForgotPassword: error', ['message' => $e->getMessage()]);
            }
        }

        // Always show success to prevent enumeration
        $this->renderStandalone('auth/forgot_password.php', $this->t('auth_forgot_password'), [
            'error'   => null,
            'success' => 'auth_reset_sent',
        ]);
    }

    /**
     * Show the invite acceptance page (GET /auth/invite/{token}).
     *
     * @param array<string,string> $params Path parameters: ['token' => string].
     *
     * @return void
     */
    public function showInvite(array $params): void
    {
        $token = (string) ($params['token'] ?? '');

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $repo = new AuthTokenRepository($pdo);
            $row  = $repo->find($token, 'invite');
        } catch (Throwable $e) {
            $this->logger->error('showInvite: error', ['message' => $e->getMessage()]);
            $row = null;
        }

        if ($row === null) {
            $this->renderStandalone('auth/accept_invite.php', $this->t('auth_accept_invite'), [
                'token'    => '',
                'username' => '',
                'error'    => 'auth_token_invalid',
            ]);
            return;
        }

        $this->renderStandalone('auth/accept_invite.php', $this->t('auth_accept_invite'), [
            'token'    => $token,
            'username' => (string) ($row['username'] ?? ''),
            'error'    => null,
        ]);
    }

    /**
     * Process the invite form (POST /auth/invite).
     *
     * Sets the initial password and logs the user in.
     *
     * @param array<string,string> $params Unused.
     *
     * @return void
     */
    public function handleInvite(array $params): void
    {
        $token           = (string) ($_POST['token']            ?? '');
        $password        = (string) ($_POST['password']         ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        $error = null;

        if ($password === '' || strlen($password) < 8) {
            $error = 'user_password_too_short';
        } elseif ($password !== $passwordConfirm) {
            $error = 'auth_password_mismatch';
        }

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $repo = new AuthTokenRepository($pdo);
            $row  = $repo->find($token, 'invite');
        } catch (Throwable $e) {
            $this->logger->error('handleInvite: error', ['message' => $e->getMessage()]);
            $row = null;
        }

        if ($row === null) {
            $this->renderStandalone('auth/accept_invite.php', $this->t('auth_accept_invite'), [
                'token'    => $token,
                'username' => '',
                'error'    => 'auth_token_invalid',
            ]);
            return;
        }

        if ($error !== null) {
            $this->renderStandalone('auth/accept_invite.php', $this->t('auth_accept_invite'), [
                'token'    => $token,
                'username' => (string) ($row['username'] ?? ''),
                'error'    => $error,
            ]);
            return;
        }

        try {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                ->execute([':hash' => $hash, ':id' => (int) $row['user_id']]);
            $repo->consume((int) $row['id']);
        } catch (Throwable $e) {
            $this->logger->error('handleInvite: save error', ['message' => $e->getMessage()]);
            $this->renderStandalone('auth/accept_invite.php', $this->t('auth_accept_invite'), [
                'token'    => $token,
                'username' => (string) ($row['username'] ?? ''),
                'error'    => 'error_500',
            ]);
            return;
        }

        // Reload full user and log them in
        try {
            $stmt = $pdo->prepare(
                'SELECT id, username, password_hash, role, active, email, agent_ids, oauth_sub
                   FROM users WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => (int) $row['user_id']]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $user = null;
        }

        if ($user) {
            SessionManager::login($user);
        }

        (new Response())->redirect('/dashboard');
    }

    /**
     * Show the password-reset form (GET /auth/reset/{token}).
     *
     * @param array<string,string> $params Path parameters: ['token' => string].
     *
     * @return void
     */
    public function showReset(array $params): void
    {
        if (!$this->isMailEnabled()) {
            (new Response())->redirect('/login');
            return;
        }

        $token = (string) ($params['token'] ?? '');

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $repo = new AuthTokenRepository($pdo);
            $row  = $repo->find($token, 'reset');
        } catch (Throwable $e) {
            $this->logger->error('showReset: error', ['message' => $e->getMessage()]);
            $row = null;
        }

        $this->renderStandalone('auth/reset_password.php', $this->t('auth_reset_password'), [
            'token' => $token,
            'error' => $row === null ? 'auth_token_invalid' : null,
        ]);
    }

    /**
     * Process the password-reset form (POST /auth/reset).
     *
     * @param array<string,string> $params Unused.
     *
     * @return void
     */
    public function handleReset(array $params): void
    {
        if (!$this->isMailEnabled()) {
            (new Response())->redirect('/login');
            return;
        }

        $token           = (string) ($_POST['token']            ?? '');
        $password        = (string) ($_POST['password']         ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        $error = null;

        if ($password === '' || strlen($password) < 8) {
            $error = 'user_password_too_short';
        } elseif ($password !== $passwordConfirm) {
            $error = 'auth_password_mismatch';
        }

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $repo = new AuthTokenRepository($pdo);
            $row  = $repo->find($token, 'reset');
        } catch (Throwable $e) {
            $this->logger->error('handleReset: error', ['message' => $e->getMessage()]);
            $row = null;
        }

        if ($row === null) {
            $this->renderStandalone('auth/reset_password.php', $this->t('auth_reset_password'), [
                'token' => $token,
                'error' => 'auth_token_invalid',
            ]);
            return;
        }

        if ($error !== null) {
            $this->renderStandalone('auth/reset_password.php', $this->t('auth_reset_password'), [
                'token' => $token,
                'error' => $error,
            ]);
            return;
        }

        try {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                ->execute([':hash' => $hash, ':id' => (int) $row['user_id']]);
            $repo->consume((int) $row['id']);
        } catch (Throwable $e) {
            $this->logger->error('handleReset: save error', ['message' => $e->getMessage()]);
            $this->renderStandalone('auth/reset_password.php', $this->t('auth_reset_password'), [
                'token' => $token,
                'error' => 'error_500',
            ]);
            return;
        }

        SessionManager::set('_flash_success', 'auth_reset_success');
        (new Response())->redirect('/login');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether mail is configured.
     */
    private function isMailEnabled(): bool
    {
        return trim((string) $this->config->get('mail.host', '')) !== '';
    }

    /**
     * Shorthand translator (without the translator object needing a separate class).
     */
    private function t(string $key): string
    {
        return (new Translator($this->config))->t($key);
    }

    /**
     * Render a standalone (no layout) template for auth pages.
     *
     * @param string               $template Relative path under templates/.
     * @param string               $title    Page title.
     * @param array<string,mixed>  $data     Template variables.
     *
     * @return void
     */
    private function renderStandalone(string $template, string $title, array $data): void
    {
        $file = dirname(__DIR__, 2) . '/templates/' . $template;
        if (!file_exists($file)) {
            $this->logger->error('Standalone template not found', ['path' => $file]);
            http_response_code(500);
            echo '<h1>500</h1>';
            return;
        }

        $translator = new Translator($this->config);
        $data['translator'] = $translator;
        $data['csrf_token'] = SessionManager::getCsrfToken();

        extract($data, EXTR_SKIP);
        require $file;
    }

    /**
     * Include and render the login template.
     *
     * Variables are extracted into the template scope so that the template
     * can reference them directly (e.g. $oidcEnabled, $error).
     *
     * @param array<string, mixed> $data Template variables.
     *
     * @return void
     */
    private function renderLogin(array $data): void
    {
        extract($data, EXTR_SKIP);

        $templateFile = dirname(__DIR__, 2) . '/templates/login.php';

        if (!file_exists($templateFile)) {
            $this->logger->error('Login template not found', ['path' => $templateFile]);
            http_response_code(500);
            echo '<h1>500 – Template not found</h1>';
            return;
        }

        require $templateFile;
    }
}
