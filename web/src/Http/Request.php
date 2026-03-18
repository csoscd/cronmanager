<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – HTTP Request
 *
 * Immutable value object representing the current HTTP request.
 * Built from PHP superglobals via the static factory method fromGlobals().
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Http;

/**
 * Class Request
 *
 * Encapsulates the current HTTP request, normalising method, path, query
 * parameters, POST data, JSON body, cookies and selected request headers
 * into a single, read-only object.
 */
final class Request
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param string $method  Uppercase HTTP method (GET, POST, PUT, …).
     * @param string $path    URL path, always starts with /.
     * @param array  $query   Query string parameters ($_GET).
     * @param array  $post    Form POST parameters ($_POST).
     * @param array  $body    Decoded JSON body (only when Content-Type is application/json).
     * @param array  $cookies Cookie values ($_COOKIE).
     * @param array  $headers Selected request headers.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array  $query,
        public readonly array  $post,
        public readonly array  $body,
        public readonly array  $cookies,
        public readonly array  $headers,
    ) {}

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Build a Request instance from PHP superglobals.
     *
     * JSON body decoding:
     *   If the Content-Type header contains "application/json", the raw
     *   request body is read from php://input and decoded.  An empty or
     *   malformed body results in an empty array (no exception is thrown).
     *
     * @return self
     */
    public static function fromGlobals(): self
    {
        // ------------------------------------------------------------------
        // Method
        // ------------------------------------------------------------------
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // ------------------------------------------------------------------
        // Path — strip query string, ensure leading slash
        // ------------------------------------------------------------------
        $rawUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path   = (string) (parse_url($rawUri, PHP_URL_PATH) ?? '/');

        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        // ------------------------------------------------------------------
        // Headers — collect a subset relevant to the application
        // ------------------------------------------------------------------
        $headers = [
            'Authorization'    => (string) ($_SERVER['HTTP_AUTHORIZATION']      ?? ''),
            'Content-Type'     => (string) ($_SERVER['CONTENT_TYPE']            ?? ''),
            'X-Requested-With' => (string) ($_SERVER['HTTP_X_REQUESTED_WITH']   ?? ''),
        ];

        // ------------------------------------------------------------------
        // JSON body
        // ------------------------------------------------------------------
        $body = [];
        $contentType = strtolower($headers['Content-Type']);

        if (str_contains($contentType, 'application/json')) {
            $raw = (string) file_get_contents('php://input');
            if ($raw !== '') {
                $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }

        return new self(
            method:  $method,
            path:    $path,
            query:   $_GET    ?? [],
            post:    $_POST   ?? [],
            body:    $body,
            cookies: $_COOKIE ?? [],
            headers: $headers,
        );
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * Retrieve a single query-string parameter.
     *
     * @param string $key
     * @param mixed  $default Value returned when the key is absent.
     *
     * @return mixed
     */
    public function getQuery(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Retrieve a single POST parameter.
     *
     * @param string $key
     * @param mixed  $default Value returned when the key is absent.
     *
     * @return mixed
     */
    public function getPost(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Retrieve a single value from the decoded JSON body.
     *
     * @param string $key
     * @param mixed  $default Value returned when the key is absent.
     *
     * @return mixed
     */
    public function getBody(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Return true when the HTTP method is POST.
     *
     * @return bool
     */
    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Return true when the HTTP method is GET.
     *
     * @return bool
     */
    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /**
     * Return true when the request was issued as an XMLHttpRequest (AJAX).
     *
     * Relies on the non-standard X-Requested-With header that all major
     * JavaScript libraries set automatically.
     *
     * @return bool
     */
    public function isAjax(): bool
    {
        return strtolower($this->headers['X-Requested-With']) === 'xmlhttprequest';
    }

    /**
     * Return the URL path of the request (always starts with /).
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Return the HTTP method of the request (uppercase).
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Return the client IP address.
     *
     * Uses REMOTE_ADDR, which is the address of the immediate TCP peer.
     * If your deployment places a reverse proxy in front, you may want to
     * inspect HTTP_X_FORWARDED_FOR; that logic is intentionally omitted
     * here to avoid trivial IP spoofing.
     *
     * @return string
     */
    public function getIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
