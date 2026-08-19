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

use Cronmanager\Web\Api\AgentsApiController;
use Cronmanager\Web\Api\AuditApiController;
use Cronmanager\Web\Api\ExportApiController;
use Cronmanager\Web\Api\JobsApiController;
use Cronmanager\Web\Api\MaintenanceApiController;
use Cronmanager\Web\Api\SettingsApiController;
use Cronmanager\Web\Auth\OidcAuthProvider;
use Cronmanager\Web\Bootstrap;
use Cronmanager\Web\Bootstrap\AgentSchema;
use Cronmanager\Web\Bootstrap\ApiKeySchema;
use Cronmanager\Web\Controller\AgentController;
use Cronmanager\Web\Controller\ApiKeyController;
use Cronmanager\Web\Controller\AuthController;
use Cronmanager\Web\Controller\CronController;
use Cronmanager\Web\Controller\DashboardController;
use Cronmanager\Web\Controller\ExportController;
use Cronmanager\Web\Controller\SetupController;
use Cronmanager\Web\Controller\SwimlaneController;
use Cronmanager\Web\Controller\TimelineController;
use Cronmanager\Web\Controller\MaintenanceController;
use Cronmanager\Web\Controller\TargetController;
use Cronmanager\Web\Controller\TransferController;
use Cronmanager\Web\Controller\AuditController;
use Cronmanager\Web\Controller\UserController;
use Cronmanager\Web\Database\Connection;
use Cronmanager\Web\Http\Request;
use Cronmanager\Web\Service\AgentIdentityPusher;
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
    // Schema bootstrap – ensure agents / api_keys tables exist
    //
    // The CREATE TABLE IF NOT EXISTS statements are idempotent but still cost
    // a DB roundtrip and metadata locks on every request. With APCu available
    // they run at most once per hour per FPM pool; without APCu they fall back
    // to running on every request (correct, just slower).
    // -------------------------------------------------------------------------
    $schemaCheckNeeded = !(function_exists('apcu_enabled') && apcu_enabled())
        || apcu_add('cm_schema_ensured', 1, 3600);
    if ($schemaCheckNeeded) {
        try {
            AgentSchema::ensure(Connection::getInstance()->getPdo(), $config, $logger);
        } catch (\Throwable $e) {
            $logger->error('AgentSchema::ensure failed', ['message' => $e->getMessage()]);
        }

        try {
            ApiKeySchema::ensure(Connection::getInstance()->getPdo(), $logger);
        } catch (\Throwable $e) {
            $logger->error('ApiKeySchema::ensure failed', ['message' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // Agent identity push – rate-limited via APCu
    // Pushes this web container's public URL and each agent's own DB ID so
    // that notification links include ?agent_id=X for direct agent selection.
    //
    // A plain `static` guard does NOT survive across PHP-FPM requests (each
    // request re-executes this script from scratch), so without APCu the push
    // would run its N synchronous HTTP PUTs on every single page load. The
    // APCu key acts as a cross-request flag with a TTL, so the identity is
    // re-pushed at most once per hour per FPM pool. Without APCu the periodic
    // push is skipped entirely – agents still receive their identity on every
    // agent create/update/select (AgentController).
    // -------------------------------------------------------------------------
    if (function_exists('apcu_enabled') && apcu_enabled()
        && apcu_add('cm_agent_identity_pushed', 1, 3600)) {
        try {
            (new AgentIdentityPusher(
                $logger,
                Connection::getInstance()->getPdo(),
                rtrim((string) $config->get('app.web_url', ''), '/'),
            ))->pushToAllAgents();
        } catch (\Throwable $e) {
            $logger->warning('AgentIdentityPusher periodic push failed', ['message' => $e->getMessage()]);
        }
    }

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
    // External REST API routes (/api/v1/*)
    //
    // These routes use Bearer-token authentication (ApiKeyMiddleware) instead
    // of sessions and CSRF.  They are registered as public routes so the
    // Router does not apply session/CSRF checks; auth is handled inside each
    // controller action.
    // -------------------------------------------------------------------------
    if (str_starts_with($request->path, '/api/v1/')) {
        $pdo            = Connection::getInstance()->getPdo();
        $agentsApi      = new AgentsApiController($config, $logger, $pdo);
        $auditApi       = new AuditApiController($config, $logger, $pdo);
        $jobsApi        = new JobsApiController($config, $logger, $pdo);
        $exportApi      = new ExportApiController($config, $logger, $pdo);
        $maintenanceApi = new MaintenanceApiController($config, $logger, $pdo);
        $settingsApi    = new SettingsApiController($config, $logger, $pdo);

        $apiRouter = new Router($config, $logger);

        // Agents
        $apiRouter->addPublicRoute('GET',    '/api/v1/agents',                 [$agentsApi, 'index']);

        // Audit log
        $apiRouter->addPublicRoute('GET',    '/api/v1/audit',                  [$auditApi, 'index']);

        // Jobs – read
        $apiRouter->addPublicRoute('GET',    '/api/v1/jobs',                   [$jobsApi, 'index']);
        $apiRouter->addPublicRoute('GET',    '/api/v1/jobs/{id}',              [$jobsApi, 'show']);
        $apiRouter->addPublicRoute('GET',    '/api/v1/jobs/{id}/history',      [$jobsApi, 'history']);
        $apiRouter->addPublicRoute('GET',    '/api/v1/tags',                   [$jobsApi, 'tags']);
        $apiRouter->addPublicRoute('GET',    '/api/v1/targets',                [$jobsApi, 'targets']);
        $apiRouter->addPublicRoute('GET',    '/api/v1/timeline',               [$jobsApi, 'timeline']);
        // Jobs – write
        $apiRouter->addPublicRoute('POST',   '/api/v1/jobs',                   [$jobsApi, 'store']);
        $apiRouter->addPublicRoute('PUT',    '/api/v1/jobs/{id}',              [$jobsApi, 'update']);
        $apiRouter->addPublicRoute('DELETE', '/api/v1/jobs/{id}',              [$jobsApi, 'destroy']);
        // Jobs – execute
        $apiRouter->addPublicRoute('POST',   '/api/v1/jobs/{id}/execute',      [$jobsApi, 'execute']);
        $apiRouter->addPublicRoute('POST',   '/api/v1/executions/{id}/kill',   [$jobsApi, 'kill']);

        // Export
        $apiRouter->addPublicRoute('GET',    '/api/v1/export',                 [$exportApi, 'download']);

        // Maintenance windows – resync must be registered before /{section}
        $apiRouter->addPublicRoute('POST',   '/api/v1/settings/resync',        [$settingsApi, 'resync']);
        // Maintenance windows
        $apiRouter->addPublicRoute('GET',    '/api/v1/maintenance/windows',     [$maintenanceApi, 'index']);
        $apiRouter->addPublicRoute('GET',    '/api/v1/maintenance/windows/{id}',[$maintenanceApi, 'show']);
        $apiRouter->addPublicRoute('POST',   '/api/v1/maintenance/windows',     [$maintenanceApi, 'store']);
        $apiRouter->addPublicRoute('PUT',    '/api/v1/maintenance/windows/{id}',[$maintenanceApi, 'update']);
        $apiRouter->addPublicRoute('DELETE', '/api/v1/maintenance/windows/{id}',[$maintenanceApi, 'destroy']);

        // Settings – {section} must come after /settings/resync
        $apiRouter->addPublicRoute('GET',    '/api/v1/settings',               [$settingsApi, 'index']);
        $apiRouter->addPublicRoute('GET',    '/api/v1/settings/{section}',     [$settingsApi, 'show']);
        $apiRouter->addPublicRoute('PUT',    '/api/v1/settings/{section}',     [$settingsApi, 'update']);

        $apiRouter->dispatch($request);
        exit;
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
    $auditCtrl        = new AuditController($config, $logger);
    $maintenanceCtrl  = new MaintenanceController($config, $logger);
    $targetCtrl       = new TargetController($config, $logger);
    $agentCtrl        = new AgentController($config, $logger);
    $transferCtrl     = new TransferController($config, $logger);

    $router->addProtectedRoute('GET',  '/',                    fn(array $p) => (new Response())->redirect('/dashboard'));
    $router->addProtectedRoute('GET',  '/dashboard',           [$dashboardCtrl, 'index']);

    $router->addProtectedRoute('GET',  '/crons',               [$cronCtrl, 'index']);
    $router->addProtectedRoute('POST', '/crons/bulk',          [$cronCtrl, 'bulkAction'],  'admin');
    // Transfer routes must come before /crons/{id} to avoid matching 'transfer' as an ID
    $router->addProtectedRoute('GET',  '/crons/transfer',          [$transferCtrl, 'prepare'],  'admin');
    $router->addProtectedRoute('POST', '/crons/transfer/validate', [$transferCtrl, 'validate'], 'admin');
    $router->addProtectedRoute('POST', '/crons/transfer',          [$transferCtrl, 'execute'],  'admin');
    $router->addProtectedRoute('GET',  '/crons/import',        [$cronCtrl, 'importList'],  'admin');
    $router->addProtectedRoute('POST', '/crons/import',        [$cronCtrl, 'importStore'], 'admin');
    $router->addProtectedRoute('GET',  '/crons/new',           [$cronCtrl, 'create'],  'admin');
    $router->addProtectedRoute('POST', '/crons',               [$cronCtrl, 'store'],   'admin');
    // /crons/translate and /crons/{id}/monitor must come before /crons/{id} so
    // that the router does not accidentally match these paths as job IDs.
    $router->addProtectedRoute('GET',  '/crons/translate',     [$cronCtrl, 'translateExpr']);
    $router->addProtectedRoute('GET',  '/crons/{id}/monitor',  [$cronCtrl, 'monitor']);
    $router->addProtectedRoute('GET',  '/crons/{id}',          [$cronCtrl, 'show']);
    $router->addProtectedRoute('GET',  '/crons/{id}/edit',     [$cronCtrl, 'edit'],    'admin');
    $router->addProtectedRoute('POST', '/crons/{id}/edit',     [$cronCtrl, 'update'],  'admin');
    $router->addProtectedRoute('POST', '/crons/{id}/delete',   [$cronCtrl, 'destroy'],    'admin');
    $router->addProtectedRoute('POST', '/crons/{id}/execute',  [$cronCtrl, 'executeNow'],    'admin');
    $router->addProtectedRoute('POST', '/execution/{id}/kill', [$cronCtrl, 'killExecution'], 'admin');

    $router->addProtectedRoute('GET',  '/timeline',            [$timelineCtrl,  'index']);
    $router->addProtectedRoute('GET',  '/swimlane',            [$swimlaneCtrl,  'index']);

    $router->addProtectedRoute('GET',  '/export',              [$exportCtrl, 'index']);
    $router->addProtectedRoute('GET',  '/export/download',     [$exportCtrl, 'download']);

    $router->addProtectedRoute('GET',  '/users',               [$userCtrl,  'index'],      'admin');
    $router->addProtectedRoute('POST', '/users/{id}/role',     [$userCtrl,  'updateRole'], 'admin');
    $router->addProtectedRoute('POST', '/users/{id}/delete',   [$userCtrl,  'destroy'],    'admin');

    $router->addProtectedRoute('GET',  '/audit',               [$auditCtrl, 'index'],      'admin');

    // Maintenance / Maintenance Windows (formerly "Targets") – admin only
    // Conflict check uses GET with query params (view role sufficient).
    // /maintenance/windows/{id}/edit and /maintenance/windows/{id}/delete must be
    // registered before /maintenance/{target}/windows (static vs dynamic segment).
    // /maintenance/windows/conflict must be registered before /maintenance/windows/{id}.
    $router->addProtectedRoute('GET',  '/maintenance/windows/conflict',              [$targetCtrl, 'conflictCheck'], 'view');
    $router->addProtectedRoute('GET',  '/maintenance/windows/{id}/edit',             [$targetCtrl, 'editWindow'],   'admin');
    $router->addProtectedRoute('POST', '/maintenance/windows/{id}/edit',             [$targetCtrl, 'updateWindow'], 'admin');
    $router->addProtectedRoute('POST', '/maintenance/windows/{id}/delete',           [$targetCtrl, 'deleteWindow'], 'admin');
    $router->addProtectedRoute('GET',  '/maintenance/{target}/windows/new',          [$targetCtrl, 'newWindow'],    'admin');
    $router->addProtectedRoute('POST', '/maintenance/{target}/windows',              [$targetCtrl, 'storeWindow'],  'admin');
    $router->addProtectedRoute('POST', '/maintenance/ssh/test',                      [$targetCtrl, 'testSsh'],      'admin');
    $router->addProtectedRoute('GET',  '/maintenance',                               [$targetCtrl, 'index'],        'admin');

    // Settings (formerly "Housekeeping") – admin only; more-specific paths first.
    // Agent-config routes must be registered before /settings/agents/* so that
    // "agent-config" is not accidentally matched as an agent ID.
    $router->addProtectedRoute('GET',  '/settings/agent-config',                     [$maintenanceCtrl, 'agentSettings'],     'admin');
    $router->addProtectedRoute('POST', '/settings/agent-config',                     [$maintenanceCtrl, 'saveAgentSettings'], 'admin');
    $router->addProtectedRoute('POST', '/settings/agent-config/copy',                [$maintenanceCtrl, 'copyAgentSettings'], 'admin');
    // Agent CRUD routes must be registered before the generic /settings/{id} patterns.
    $router->addProtectedRoute('GET',  '/settings/agents/create',                    [$agentCtrl, 'create'],               'admin');
    $router->addProtectedRoute('POST', '/settings/agents',                           [$agentCtrl, 'store'],                'admin');
    $router->addProtectedRoute('GET',  '/settings/agents/{id}/edit',                 [$agentCtrl, 'edit'],                 'admin');
    $router->addProtectedRoute('POST', '/settings/agents/{id}',                      [$agentCtrl, 'update'],               'admin');
    $router->addProtectedRoute('POST', '/settings/agents/{id}/delete',               [$agentCtrl, 'destroy'],              'admin');
    $router->addProtectedRoute('POST', '/settings/agents/{id}/test',                 [$agentCtrl, 'test'],                 'admin');
    $router->addProtectedRoute('GET',  '/settings',                                  [$maintenanceCtrl, 'index'],          'admin');
    $router->addProtectedRoute('POST', '/settings/resync',                           [$maintenanceCtrl, 'resyncCrontab'],  'admin');
    $router->addProtectedRoute('POST', '/settings/executions/bulk',                  [$maintenanceCtrl, 'bulkAction'],     'admin');
    $router->addProtectedRoute('POST', '/settings/executions/{id}/finish',           [$maintenanceCtrl, 'resolveExecution'],'admin');
    $router->addProtectedRoute('POST', '/settings/executions/{id}/delete',           [$maintenanceCtrl, 'deleteExecution'],'admin');
    $router->addProtectedRoute('POST', '/settings/history/cleanup',                  [$maintenanceCtrl, 'cleanHistory'],   'admin');
    $router->addProtectedRoute('POST', '/settings/once/cleanup',                     [$maintenanceCtrl, 'onceCleanup'],    'admin');
    $router->addProtectedRoute('POST', '/settings/logs/prune',                       [$maintenanceCtrl, 'pruneLogs'],      'admin');
    $router->addProtectedRoute('POST', '/settings/notification/test',                [$maintenanceCtrl, 'testNotification'],'admin');

    // Agent switcher – available to all authenticated users
    $router->addProtectedRoute('POST', '/agent/select',                              [$agentCtrl, 'select'],              'view');

    // API Key management – available to every authenticated user (own keys only)
    $apiKeyCtrl = new ApiKeyController($config, $logger);
    // /api-keys/created and /api-keys/create must be registered before /api-keys/{id}
    $router->addProtectedRoute('GET',  '/api-keys',                 [$apiKeyCtrl, 'index'],   'view');
    $router->addProtectedRoute('GET',  '/api-keys/create',          [$apiKeyCtrl, 'create'],  'view');
    $router->addProtectedRoute('POST', '/api-keys',                 [$apiKeyCtrl, 'store'],   'view');
    $router->addProtectedRoute('GET',  '/api-keys/created',         [$apiKeyCtrl, 'created'], 'view');
    $router->addProtectedRoute('POST', '/api-keys/{id}/delete',     [$apiKeyCtrl, 'destroy'], 'view');

    // 301 redirect: legacy /housekeeping bookmark → /settings
    $router->addPublicRoute('GET',  '/housekeeping',  fn() => (new Response())->redirect('/settings', 301));

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

