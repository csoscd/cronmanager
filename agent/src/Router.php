<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – HTTP Router
 *
 * Minimal HTTP router that matches incoming requests against registered routes
 * and dispatches them to the appropriate handler callable.
 *
 * Supports simple path parameters in the form {name}, e.g. /crons/{id}.
 * Extracted parameters are passed as an associative array to the handler.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent;

/**
 * Class Router
 *
 * Registers routes and dispatches requests to matching handler callables.
 *
 * Route patterns may contain named parameters wrapped in curly braces:
 *   /crons/{id}      → extracts 'id' from the URI segment
 *   /tags/{tagId}    → extracts 'tagId' from the URI segment
 *
 * Handler signature: callable(array $params): void
 *
 * Usage:
 *   $router = new Router();
 *   $router->addRoute('GET', '/crons/{id}', function (array $params): void {
 *       echo json_encode(['id' => $params['id']]);
 *   });
 *   $router->dispatch('GET', '/crons/42');
 */
final class Router
{
    // -------------------------------------------------------------------------
    // Internal route storage
    // -------------------------------------------------------------------------

    /**
     * Registered routes.
     *
     * Structure:
     *   [
     *     ['method' => 'GET', 'pattern' => '/crons/{id}', 'regex' => '...', 'params' => ['id'], 'handler' => callable],
     *     ...
     *   ]
     *
     * @var array<int, array{method: string, pattern: string, regex: string, params: list<string>, handler: callable}>
     */
    private array $routes = [];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Register a route.
     *
     * @param string   $method  HTTP method in uppercase (e.g. 'GET', 'POST').
     * @param string   $path    Path pattern, optionally with {paramName} placeholders.
     * @param callable $handler Handler callable with signature (array $params): void.
     *
     * @return void
     */
    public function addRoute(string $method, string $path, callable $handler): void
    {
        [$regex, $paramNames] = $this->compilePattern($path);

        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $path,
            'regex'   => $regex,
            'params'  => $paramNames,
            'handler' => $handler,
        ];
    }

    /**
     * Dispatch the given request to the first matching route.
     *
     * Sends a JSON 404 if no route matches the path at all.
     * Sends a JSON 405 if the path matches but the HTTP method does not.
     * Calls the handler with extracted path parameters on a full match.
     *
     * @param string $method Incoming HTTP method (will be uppercased internally).
     * @param string $path   Incoming request path (must start with '/').
     *
     * @return void
     */
    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);

        // Track whether any route matched the path (for 404 vs 405 distinction)
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                // Path does not match this route – try the next one
                continue;
            }

            // At least one route handles this path
            $pathMatched = true;

            if ($route['method'] !== $method) {
                // Path matched but method did not – keep looking for a method match
                continue;
            }

            // Full match: extract named path parameters
            $params = [];
            foreach ($route['params'] as $paramName) {
                $params[$paramName] = $matches[$paramName] ?? '';
            }

            // Invoke the handler; any exceptions propagate to the caller (agent.php)
            ($route['handler'])($params);

            return;
        }

        // No route matched at all → 404
        if (!$pathMatched) {
            $this->sendError(404, 'Not Found', sprintf('No route found for path: %s', $path));
            return;
        }

        // Path matched but no route accepted the method → 405
        $this->sendError(405, 'Method Not Allowed', sprintf('Method %s is not allowed for path: %s', $method, $path));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Compile a route pattern string into a named-capture regex and a list of
     * parameter names.
     *
     * Example:
     *   Input:  '/crons/{id}'
     *   Output: ['#^/crons/(?P<id>[^/]+)$#', ['id']]
     *
     * @param string $pattern Route path pattern.
     *
     * @return array{0: string, 1: list<string>} [regex string, list of param names]
     */
    private function compilePattern(string $pattern): array
    {
        $paramNames = [];

        // Replace every {name} placeholder with a named regex capture group
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$paramNames): string {
                $paramNames[] = $m[1];

                // Matches any non-slash segment
                return sprintf('(?P<%s>[^/]+)', $m[1]);
            },
            $pattern
        );

        // Anchor the pattern to the full path; make trailing slash optional
        $regex = '#^' . $regex . '/?$#';

        return [$regex, $paramNames];
    }

    /**
     * Emit a JSON error response with the given HTTP status code.
     *
     * @param int    $code    HTTP status code.
     * @param string $status  Short status text.
     * @param string $message Descriptive error message.
     *
     * @return void
     */
    private function sendError(int $code, string $status, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(
            ['error' => $status, 'message' => $message, 'code' => $code],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
