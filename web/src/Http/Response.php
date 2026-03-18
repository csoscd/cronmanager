<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – HTTP Response
 *
 * Helper class for sending HTTP responses (redirects, HTML pages, JSON payloads).
 * Methods that terminate the request call exit() after sending headers/body.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Http;

/**
 * Class Response
 *
 * Provides convenience methods for the most common HTTP response patterns
 * used in the Cronmanager Web UI.  Each method is intentionally simple and
 * calls exit() where appropriate so that no further application code is
 * executed after a response has been sent.
 */
class Response
{
    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Send a redirect response and terminate the current request.
     *
     * @param string $url  Target URL (absolute path or full URL).
     * @param int    $code HTTP redirect status code (301, 302, 303, 307, 308).
     *
     * @return void
     */
    public function redirect(string $url, int $code = 302): void
    {
        $this->setStatusCode($code);
        $this->setHeader('Location', $url);
        exit();
    }

    /**
     * Send an HTML response.
     *
     * Unlike redirect() and json(), this method does NOT call exit() so that
     * callers can append additional output if needed (e.g. template rendering).
     *
     * @param string $content HTML body content.
     * @param int    $code    HTTP status code (default: 200).
     *
     * @return void
     */
    public function html(string $content, int $code = 200): void
    {
        $this->setStatusCode($code);
        $this->setHeader('Content-Type', 'text/html; charset=UTF-8');
        echo $content;
    }

    /**
     * Encode $data as JSON, send it with the appropriate Content-Type header
     * and terminate the request.
     *
     * @param array $data  Associative array to serialise.
     * @param int   $code  HTTP status code (default: 200).
     *
     * @return void
     */
    public function json(array $data, int $code = 200): void
    {
        $this->setStatusCode($code);
        $this->setHeader('Content-Type', 'application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    /**
     * Set a single response header.
     *
     * Must be called before any output is sent.
     *
     * @param string $name  Header name.
     * @param string $value Header value.
     *
     * @return void
     */
    public function setHeader(string $name, string $value): void
    {
        header(sprintf('%s: %s', $name, $value));
    }

    /**
     * Set the HTTP response status code.
     *
     * Must be called before any output is sent.
     *
     * @param int $code HTTP status code (e.g. 200, 404, 500).
     *
     * @return void
     */
    public function setStatusCode(int $code): void
    {
        http_response_code($code);
    }
}
