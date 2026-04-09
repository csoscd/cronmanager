<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – HTTP Router
 *
 * Minimal front-controller router that supports:
 *   - Public routes  (no authentication required)
 *   - Protected routes (require an authenticated session and an optional role)
 *   - Named path parameters using {param} syntax (e.g. /crons/{id})
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Http;

use Cronmanager\Web\Session\SessionManager;
use Monolog\Logger;
use Noodlehaus\Config;

/**
 * Class Router
 *
 * Dispatches an incoming Request to the appropriate handler callable.
 * Route handlers receive a single array argument containing any named path
 * parameters extracted from the URL (empty array when no params are defined).
 */
class Router
{
    // -------------------------------------------------------------------------
    // Route storage
    // -------------------------------------------------------------------------

    /**
     * Public routes: no authentication check.
     *
     * Shape: [['method' => string, 'pattern' => string, 'regex' => string,
     *           'params' => string[], 'handler' => callable], ...]
     *
     * @var array<int, array<string, mixed>>
     */
    private array $publicRoutes = [];

    /**
     * Protected routes: session + role check.
     *
     * Same shape as $publicRoutes plus 'role' => string.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $protectedRoutes = [];

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param Config $config Noodlehaus config (reserved for future use, e.g. base-path).
     * @param Logger $logger Monolog logger for dispatch events.
     */
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    /**
     * Register a public route (no authentication required).
     *
     * @param string   $method  HTTP method (GET, POST, …).
     * @param string   $path    URL path pattern, may contain {param} placeholders.
     * @param callable $handler Handler callable; receives array of path params.
     *
     * @return void
     */
    public function addPublicRoute(string $method, string $path, callable $handler): void
    {
        ['regex' => $regex, 'params' => $params] = $this->compilePath($path);

        $this->publicRoutes[] = [
            'method'  => strtoupper($method),
            'pattern' => $path,
            'regex'   => $regex,
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    /**
     * Register a protected route.
     *
     * Unauthenticated visitors are redirected to /login.
     * Authenticated visitors without the required role receive a 403 response.
     *
     * @param string   $method       HTTP method (GET, POST, …).
     * @param string   $path         URL path pattern, may contain {param} placeholders.
     * @param callable $handler      Handler callable; receives array of path params.
     * @param string   $requiredRole Minimum role required ('view' or 'admin').
     *
     * @return void
     */
    public function addProtectedRoute(
        string   $method,
        string   $path,
        callable $handler,
        string   $requiredRole = 'view',
    ): void {
        ['regex' => $regex, 'params' => $params] = $this->compilePath($path);

        $this->protectedRoutes[] = [
            'method'       => strtoupper($method),
            'pattern'      => $path,
            'regex'        => $regex,
            'params'       => $params,
            'handler'      => $handler,
            'requiredRole' => $requiredRole,
        ];
    }

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    /**
     * Match the incoming request against registered routes and call the handler.
     *
     * Resolution order:
     *   1. Public routes
     *   2. Protected routes (with auth/role checks)
     *   3. 404
     *
     * @param Request $request The current HTTP request.
     *
     * @return void
     */
    public function dispatch(Request $request): void
    {
        $method = $request->method;
        $path   = $request->path;

        $this->logger->debug('Router dispatch', ['method' => $method, 'path' => $path]);

        // ------------------------------------------------------------------
        // 1. Public routes
        // ------------------------------------------------------------------
        foreach ($this->publicRoutes as $route) {
            $params = $this->matchRoute($route, $method, $path);

            if ($params !== null) {
                $this->logger->debug('Matched public route', ['pattern' => $route['pattern']]);
                ($route['handler'])($params);
                return;
            }
        }

        // ------------------------------------------------------------------
        // 2. Protected routes
        // ------------------------------------------------------------------
        foreach ($this->protectedRoutes as $route) {
            $params = $this->matchRoute($route, $method, $path);

            if ($params !== null) {
                $this->logger->debug('Matched protected route', ['pattern' => $route['pattern']]);

                // Authentication check
                if (!SessionManager::isAuthenticated()) {
                    $this->logger->info('Unauthenticated access, redirecting to /login', ['path' => $path]);

                    // Preserve the originally requested URL so the user is
                    // returned there after a successful re-login.  Only GET
                    // requests are saved – POST would re-submit a form, which
                    // is never safe to replay after a login redirect.
                    // /logout is excluded to avoid a redirect loop.
                    if ($method === 'GET' && $path !== '/logout') {
                        $uri = $path;
                        if (!empty($_SERVER['QUERY_STRING'])) {
                            $uri .= '?' . $_SERVER['QUERY_STRING'];
                        }
                        SessionManager::set('_login_redirect', $uri);
                    }

                    (new Response())->redirect('/login');
                    return;
                }

                // Authorisation check
                $requiredRole = (string) $route['requiredRole'];
                if (!SessionManager::hasRole($requiredRole)) {
                    $this->logger->warning('Insufficient role', [
                        'required' => $requiredRole,
                        'user'     => SessionManager::getUsername(),
                        'path'     => $path,
                    ]);
                    $this->render403();
                    return;
                }

                // CSRF validation for all state-changing methods on protected routes
                if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH' || $method === 'DELETE') {
                    $submitted = (string) ($_POST['_csrf'] ?? '');
                    if (!SessionManager::validateCsrfToken($submitted)) {
                        $this->logger->warning('CSRF token validation failed', [
                            'path'   => $path,
                            'method' => $method,
                            'user'   => SessionManager::getUsername(),
                        ]);
                        $this->render403();
                        return;
                    }
                }

                ($route['handler'])($params);
                return;
            }
        }

        // ------------------------------------------------------------------
        // 3. No match — 404
        // ------------------------------------------------------------------
        $this->logger->info('No route matched', ['method' => $method, 'path' => $path]);
        $this->render404();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Compile a path pattern with {param} placeholders into a named-capture regex.
     *
     * Example: '/crons/{id}' becomes '#^/crons/(?P<id>[^/]+)$#'
     *
     * @param string $path URL path pattern.
     *
     * @return array{regex: string, params: string[]} Compiled regex and list of param names.
     */
    private function compilePath(string $path): array
    {
        $params = [];

        // Replace each {name} placeholder with a named capture group
        $regex = preg_replace_callback(
            '/{([a-zA-Z_][a-zA-Z0-9_]*)}/',
            static function (array $matches) use (&$params): string {
                $params[] = $matches[1];
                return sprintf('(?P<%s>[^/]+)', $matches[1]);
            },
            $path
        );

        return [
            'regex'  => '#^' . $regex . '$#',
            'params' => $params,
        ];
    }

    /**
     * Attempt to match a route definition against a given method and path.
     *
     * @param array<string, mixed> $route  Route definition.
     * @param string               $method HTTP method of the incoming request.
     * @param string               $path   URL path of the incoming request.
     *
     * @return array<string, string>|null Extracted path parameters on match, null otherwise.
     */
    private function matchRoute(array $route, string $method, string $path): ?array
    {
        if ($route['method'] !== $method) {
            return null;
        }

        if (!preg_match((string) $route['regex'], $path, $matches)) {
            return null;
        }

        // Extract only named captures (filter out numeric indices)
        $params = [];
        foreach ((array) $route['params'] as $name) {
            $params[$name] = $matches[$name] ?? '';
        }

        return $params;
    }

    /**
     * Render a styled 403 Forbidden page and terminate.
     *
     * @return void
     */
    private function render403(): void
    {
        http_response_code(403);
        echo <<<'HTML'
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>403 – Access Denied</title>
                <script src="/assets/js/tailwind.min.js"></script>
            </head>
            <body class="bg-gray-100 min-h-screen flex items-center justify-center">
                <div class="bg-white rounded-lg shadow-md p-10 max-w-md text-center">
                    <div class="text-red-500 text-6xl font-bold mb-4">403</div>
                    <h1 class="text-2xl font-semibold text-gray-800 mb-2">Access Denied</h1>
                    <p class="text-gray-500 mb-6">You do not have permission to view this page.</p>
                    <a href="/dashboard" class="inline-block bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 transition">
                        Back to Dashboard
                    </a>
                </div>
            </body>
            </html>
            HTML;
        exit();
    }

    /**
     * Render a styled 404 Not Found page and terminate.
     *
     * @return void
     */
    private function render404(): void
    {
        http_response_code(404);
        echo <<<'HTML'
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>404 – Page Not Found</title>
                <script src="/assets/js/tailwind.min.js"></script>
            </head>
            <body class="bg-gray-100 min-h-screen flex items-center justify-center">
                <div class="bg-white rounded-lg shadow-md p-10 max-w-md text-center">
                    <div class="text-gray-400 text-6xl font-bold mb-4">404</div>
                    <h1 class="text-2xl font-semibold text-gray-800 mb-2">Page Not Found</h1>
                    <p class="text-gray-500 mb-6">The page you are looking for does not exist.</p>
                    <a href="/dashboard" class="inline-block bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 transition">
                        Back to Dashboard
                    </a>
                </div>
            </body>
            </html>
            HTML;
        exit();
    }
}
