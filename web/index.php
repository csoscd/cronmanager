<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Front Controller
 *
 * Single entry point for all HTTP requests.
 * Bootstraps the application, starts the session, builds the request object,
 * registers all routes and dispatches to the appropriate handler.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

require_once '/var/www/libs/vendor/autoload.php';

// PSR-4 autoloader for Cronmanager\Web\* classes (not in shared vendor)
spl_autoload_register(function (string $class): void {
    $prefix  = 'Cronmanager\\Web\\';
    $baseDir = __DIR__ . '/src/';
    if (str_starts_with($class, $prefix)) {
        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use Cronmanager\Web\Auth\OidcAuthProvider;
use Cronmanager\Web\Bootstrap;
use Cronmanager\Web\Controller\AuthController;
use Cronmanager\Web\Controller\CronController;
use Cronmanager\Web\Controller\DashboardController;
use Cronmanager\Web\Controller\ExportController;
use Cronmanager\Web\Controller\SetupController;
use Cronmanager\Web\Controller\SwimlaneController;
use Cronmanager\Web\Controller\TimelineController;
use Cronmanager\Web\Controller\MaintenanceController;
use Cronmanager\Web\Controller\UserController;
use Cronmanager\Web\Database\Connection;
use Cronmanager\Web\Http\Request;
use Cronmanager\Web\Http\Response;
use Cronmanager\Web\Http\Router;
use Cronmanager\Web\Session\SessionManager;

try {
    // -------------------------------------------------------------------------
    // Bootstrap: config + logger
    // -------------------------------------------------------------------------
    $bootstrap = Bootstrap::getInstance();
    $config    = $bootstrap->getConfig();
    $logger    = $bootstrap->getLogger();

    // -------------------------------------------------------------------------
    // Startup config validation – warn early about insecure defaults
    // -------------------------------------------------------------------------
    $hmacSecret = (string) $config->get('agent.hmac_secret', '');
    if ($hmacSecret === '' || $hmacSecret === 'change-me-to-a-secure-random-string') {
        $logger->critical('SECURITY: agent.hmac_secret is empty or set to the default value. ' .
            'All requests to the host agent are unauthenticated. ' .
            'Generate a secure secret with: openssl rand -hex 32');
    } elseif (strlen($hmacSecret) < 32) {
        $logger->warning('SECURITY: agent.hmac_secret is shorter than 32 characters. ' .
            'A minimum of 32 random bytes is recommended.');
    }

    // -------------------------------------------------------------------------
    // Security response headers
    // Send before any output so headers arrive with every response.
    // -------------------------------------------------------------------------
    // Prevent browsers from sniffing MIME types (guards against content-type attacks)
    header('X-Content-Type-Options: nosniff');
    // Deny framing entirely to block clickjacking
    header('X-Frame-Options: DENY');
    // Restrict referrer information to same-origin only
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Disable access to sensitive browser features not used by this application
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    // Content-Security-Policy:
    //   - default-src 'self'   : all resource types default to same-origin
    //   - script-src 'unsafe-inline': required for the Tailwind dark-mode
    //     detection snippet and inline tailwind.config in layout.php
    //   - style-src 'unsafe-inline' : required for Tailwind's runtime utility classes
    //   - img-src data:              : allows inline SVG data URIs used by the UI
    //   - frame-ancestors 'none'     : redundant with X-Frame-Options, defence-in-depth
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'");

    // -------------------------------------------------------------------------
    // Session
    // -------------------------------------------------------------------------
    SessionManager::start($config);

    // -------------------------------------------------------------------------
    // Request
    // -------------------------------------------------------------------------
    $request = Request::fromGlobals();

    // -------------------------------------------------------------------------
    // First-run setup: redirect to /setup if no users exist and OIDC is disabled
    // -------------------------------------------------------------------------
    if (!SessionManager::isAuthenticated() && $request->getPath() !== '/setup') {
        if (SetupController::isSetupNeeded($config)) {
            (new Response())->redirect('/setup');
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // Router
    // -------------------------------------------------------------------------
    $router = new Router($config, $logger);

    // -------------------------------------------------------------------------
    // Setup controller – must be registered BEFORE all other routes
    // -------------------------------------------------------------------------
    $setupController = new SetupController($config, $logger);
    $router->addPublicRoute('GET',  '/setup', [$setupController, 'show']);
    $router->addPublicRoute('POST', '/setup', [$setupController, 'store']);

    // -------------------------------------------------------------------------
    // Auth controller (shared instance for all auth routes)
    // -------------------------------------------------------------------------
    $authController = new AuthController($config, $logger);

    // -------------------------------------------------------------------------
    // Public routes (no authentication required)
    // -------------------------------------------------------------------------
    $router->addPublicRoute('GET',  '/login',         [$authController, 'showLogin']);
    $router->addPublicRoute('POST', '/login',         [$authController, 'handleLogin']);
    $router->addPublicRoute('GET',  '/auth/callback', [$authController, 'handleOidcCallback']);
    $router->addPublicRoute('GET',  '/logout',        [$authController, 'logout']);

    // Language switcher – sets session lang and redirects back to the referer
    $router->addPublicRoute('GET', '/lang/{code}', static function (array $params) use ($config): void {
        $code      = preg_replace('/[^a-z]/', '', strtolower((string) ($params['code'] ?? '')));
        $available = array_map('strval', (array) $config->get('i18n.available', ['en', 'de']));

        if (in_array($code, $available, strict: true)) {
            SessionManager::set('lang', $code);
        }

        // Redirect back to the page the user was on; extract path only to stay on-site
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $path    = parse_url($referer, PHP_URL_PATH) ?? '';
        $query   = parse_url($referer, PHP_URL_QUERY) ?? '';
        $back    = ($path !== '' && $path !== '/lang/' . $code)
            ? $path . ($query !== '' ? '?' . $query : '')
            : '/dashboard';
        (new Response())->redirect($back);
    });

    // OIDC initiation: build the authorization URL and redirect the user
    $router->addPublicRoute('GET', '/auth/oidc', static function (array $params) use ($config, $logger): void {
        $pdo      = Connection::getInstance()->getPdo();
        $provider = new OidcAuthProvider($config, $pdo, $logger);

        if (!$provider->isEnabled()) {
            (new Response())->redirect('/login');
            return;
        }

        $url = $provider->getAuthorizationUrl();
        (new Response())->redirect($url);
    });

    // -------------------------------------------------------------------------
    // Protected routes (authenticated + role check)
    // -------------------------------------------------------------------------
    $dashboardCtrl  = new DashboardController($config, $logger);
    $cronCtrl       = new CronController($config, $logger);
    $timelineCtrl   = new TimelineController($config, $logger);
    $swimlaneCtrl   = new SwimlaneController($config, $logger);
    $exportCtrl       = new ExportController($config, $logger);
    $userCtrl         = new UserController($config, $logger);
    $maintenanceCtrl  = new MaintenanceController($config, $logger);

    $router->addProtectedRoute('GET',  '/',                    fn(array $p) => (new Response())->redirect('/dashboard'));
    $router->addProtectedRoute('GET',  '/dashboard',           [$dashboardCtrl, 'index']);

    $router->addProtectedRoute('GET',  '/crons',               [$cronCtrl, 'index']);
    $router->addProtectedRoute('GET',  '/crons/import',        [$cronCtrl, 'importList'],  'admin');
    $router->addProtectedRoute('POST', '/crons/import',        [$cronCtrl, 'importStore'], 'admin');
    $router->addProtectedRoute('GET',  '/crons/new',           [$cronCtrl, 'create'],  'admin');
    $router->addProtectedRoute('POST', '/crons',               [$cronCtrl, 'store'],   'admin');
    // /crons/{id}/monitor must come before /crons/{id} so that the router does
    // not accidentally match the "monitor" sub-path as a job ID.
    $router->addProtectedRoute('GET',  '/crons/{id}/monitor',  [$cronCtrl, 'monitor']);
    $router->addProtectedRoute('GET',  '/crons/{id}',          [$cronCtrl, 'show']);
    $router->addProtectedRoute('GET',  '/crons/{id}/edit',     [$cronCtrl, 'edit'],    'admin');
    $router->addProtectedRoute('POST', '/crons/{id}/edit',     [$cronCtrl, 'update'],  'admin');
    $router->addProtectedRoute('POST', '/crons/{id}/delete',   [$cronCtrl, 'destroy'],    'admin');
    $router->addProtectedRoute('POST', '/crons/{id}/execute',  [$cronCtrl, 'executeNow'], 'admin');

    $router->addProtectedRoute('GET',  '/timeline',            [$timelineCtrl,  'index']);
    $router->addProtectedRoute('GET',  '/swimlane',            [$swimlaneCtrl,  'index']);

    $router->addProtectedRoute('GET',  '/export',              [$exportCtrl, 'index']);
    $router->addProtectedRoute('GET',  '/export/download',     [$exportCtrl, 'download']);

    $router->addProtectedRoute('GET',  '/users',               [$userCtrl, 'index'],      'admin');
    $router->addProtectedRoute('POST', '/users/{id}/role',     [$userCtrl, 'updateRole'], 'admin');
    $router->addProtectedRoute('POST', '/users/{id}/delete',   [$userCtrl, 'destroy'],    'admin');

    // Maintenance – admin only; more-specific paths registered before /{id} sub-routes
    $router->addProtectedRoute('GET',  '/maintenance',                              [$maintenanceCtrl, 'index'],           'admin');
    $router->addProtectedRoute('POST', '/maintenance/resync',                       [$maintenanceCtrl, 'resyncCrontab'],   'admin');
    $router->addProtectedRoute('POST', '/maintenance/executions/{id}/finish',       [$maintenanceCtrl, 'resolveExecution'],'admin');
    $router->addProtectedRoute('POST', '/maintenance/executions/{id}/delete',       [$maintenanceCtrl, 'deleteExecution'], 'admin');
    $router->addProtectedRoute('POST', '/maintenance/history/cleanup',              [$maintenanceCtrl, 'cleanHistory'],    'admin');

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------
    $router->dispatch($request);

} catch (\Throwable $e) {
    // Top-level safety net: log and display a generic error page
    if (isset($logger)) {
        $logger->error('Unhandled exception in front controller', [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);
    } else {
        // Logger not yet available – fall back to PHP's error log
        error_log(sprintf(
            '[cronmanager-web] Unhandled %s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    http_response_code(500);
    echo <<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>500 – Internal Server Error</title>
            <style>
                body { font-family: sans-serif; background: #f3f4f6; display:flex;
                       align-items:center; justify-content:center; min-height:100vh; margin:0; }
                .card { background:#fff; border-radius:.75rem; padding:2.5rem;
                        max-width:26rem; text-align:center; box-shadow:0 4px 6px rgba(0,0,0,.07); }
                h1 { color:#dc2626; font-size:2rem; margin-bottom:.5rem; }
                p  { color:#6b7280; }
            </style>
        </head>
        <body>
            <div class="card">
                <h1>500</h1>
                <p>An unexpected error occurred. Please try again later.</p>
            </div>
        </body>
        </html>
        HTML;
}

