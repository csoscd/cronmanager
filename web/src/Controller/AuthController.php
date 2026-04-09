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

use Cronmanager\Web\Auth\LocalAuthProvider;
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

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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
