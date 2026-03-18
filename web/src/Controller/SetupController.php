<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Setup Controller
 *
 * Handles the first-run initial setup flow. When no user accounts exist in
 * the local database AND OIDC authentication is disabled, the application
 * redirects every unauthenticated request to the /setup page so an admin
 * account can be created before the normal login page is accessible.
 *
 * Routes handled:
 *   GET  /setup – display the initial admin account creation form
 *   POST /setup – validate and create the first admin user, then redirect to /login
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

use Cronmanager\Web\Auth\LocalAuthProvider;
use Cronmanager\Web\Database\Connection;
use Cronmanager\Web\Http\Response;
use Cronmanager\Web\I18n\Translator;
use Cronmanager\Web\Session\SessionManager;
use Noodlehaus\Config;

/**
 * Class SetupController
 *
 * Thin controller responsible solely for the first-run setup flow.
 * Extends BaseController to inherit the render() helper and shared
 * collaborator access (translator, logger).
 */
class SetupController extends BaseController
{
    // -------------------------------------------------------------------------
    // Static helper
    // -------------------------------------------------------------------------

    /**
     * Check whether the initial setup is needed.
     *
     * Returns true when ALL of the following conditions are met:
     *   - OIDC authentication is disabled in configuration.
     *   - No rows exist in the `users` table of the local database.
     *
     * This is intentionally a static method so it can be called from
     * index.php before a controller instance is constructed.
     *
     * @param Config $config Noodlehaus configuration instance.
     *
     * @return bool True if the setup page should be shown, false otherwise.
     */
    public static function isSetupNeeded(Config $config): bool
    {
        // If OIDC is enabled the provider handles user management; no local
        // setup page is needed or meaningful.
        if ((bool) $config->get('auth.oidc_enabled', false)) {
            return false;
        }

        try {
            $pdo  = Connection::getInstance()->getPdo();
            $stmt = $pdo->query('SELECT COUNT(*) FROM users');

            if ($stmt === false) {
                return false;
            }

            $count = (int) $stmt->fetchColumn();

            return $count === 0;
        } catch (\Throwable) {
            // A database error must not break the application bootstrap.
            // Treat it as "setup not needed" so a DB error page can be shown
            // via the normal routing path instead of an infinite redirect loop.
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * GET /setup – display the initial admin account creation form.
     *
     * Redirects to /login when setup is not needed (either because users
     * already exist or OIDC is enabled).
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function show(array $params): void
    {
        if (!self::isSetupNeeded($this->config)) {
            (new Response())->redirect('/login');
            return;
        }

        // Consume single-use flash error message stored by store()
        $error = SessionManager::get('_flash_setup_error');
        SessionManager::remove('_flash_setup_error');

        $translator = $this->translator();

        $this->renderSetup([
            'error'      => $error,
            'translator' => $translator,
        ]);
    }

    /**
     * POST /setup – process the setup form and create the first admin user.
     *
     * Validates the submitted fields, creates the user via LocalAuthProvider,
     * and redirects to /login on success.
     *
     * Validation rules:
     *   - username: required, 3–128 characters, [a-zA-Z0-9._-] only
     *   - password: required, minimum 8 characters
     *   - password_confirm: must match password
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function store(array $params): void
    {
        $response = new Response();

        if (!self::isSetupNeeded($this->config)) {
            $response->redirect('/login');
            return;
        }

        $username        = trim((string) ($_POST['username']         ?? ''));
        $password        = (string) ($_POST['password']              ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm']      ?? '');

        // ------------------------------------------------------------------
        // Validation
        // ------------------------------------------------------------------
        $errorKey = null;

        if ($username === ''
            || mb_strlen($username) < 3
            || mb_strlen($username) > 128
            || !preg_match('/^[a-zA-Z0-9._-]+$/', $username)
        ) {
            $errorKey = 'setup_error_username';
        } elseif ($password === '' || mb_strlen($password) < 8) {
            $errorKey = 'setup_error_password';
        } elseif ($password !== $passwordConfirm) {
            $errorKey = 'setup_error_mismatch';
        }

        if ($errorKey !== null) {
            SessionManager::set('_flash_setup_error', $errorKey);
            $response->redirect('/setup');
            return;
        }

        // ------------------------------------------------------------------
        // Create the admin user
        // ------------------------------------------------------------------
        try {
            $pdo      = Connection::getInstance()->getPdo();
            $provider = new LocalAuthProvider($pdo, $this->logger);
            $provider->createUser($username, $password, 'admin');
        } catch (\Throwable $e) {
            $this->logger->error('SetupController::store: failed to create admin user', [
                'username'  => $username,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
            SessionManager::set('_flash_setup_error', 'error_500');
            $response->redirect('/setup');
            return;
        }

        $this->logger->info('SetupController::store: initial admin account created', [
            'username' => $username,
        ]);

        // Signal login page to show a success message
        SessionManager::set('_flash_error', 'setup_success');
        $response->redirect('/login');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Include and render the standalone setup template.
     *
     * The setup page does not use the shared layout (no navigation bar).
     *
     * @param array<string, mixed> $data Template variables.
     *
     * @return void
     */
    private function renderSetup(array $data): void
    {
        extract($data, EXTR_SKIP);

        $templateFile = dirname(__DIR__, 2) . '/templates/setup.php';

        if (!file_exists($templateFile)) {
            $this->logger->error('Setup template not found', ['path' => $templateFile]);
            http_response_code(500);
            echo '<h1>500 – Template not found</h1>';
            return;
        }

        require $templateFile;
    }
}
