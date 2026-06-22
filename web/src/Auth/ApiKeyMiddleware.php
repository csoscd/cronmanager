<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – API Key Authentication Middleware
 *
 * Validates the Bearer token in the `Authorization` header of incoming
 * requests to the external REST API (/api/v1/*).
 *
 * Checks (in order):
 *   1. Authorization header present and well-formed
 *   2. Key exists in the database
 *   3. Key is not expired
 *   4. Caller IP is in the whitelist (if configured)
 *
 * Scope and agent checks are the responsibility of each API controller, since
 * they require knowledge of the requested resource.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Auth;

use Monolog\Logger;
use PDO;

/**
 * Class ApiKeyMiddleware
 *
 * Stateless auth gate for Bearer-token API requests.
 */
final class ApiKeyMiddleware
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param PDO    $pdo    Active database connection.
     * @param Logger $logger Monolog logger.
     */
    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Authenticate the current request via its Bearer token.
     *
     * On success, registers a shutdown function that updates `last_used_at`
     * for the matched key without blocking the response.
     *
     * On failure, emits a JSON 401/403 response and returns null; the caller
     * must call exit() after receiving null.
     *
     * @param string $requiredScope Scope that must be present on the key.
     *
     * @return ApiKey|null Authenticated key on success, null on failure.
     */
    public function authenticate(string $requiredScope): ?ApiKey
    {
        // ------------------------------------------------------------------
        // 1. Extract Bearer token from Authorization header
        // ------------------------------------------------------------------
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if ($header === '') {
            $this->jsonError(401, 'Missing API key', 'Provide a Bearer token in the Authorization header.');
            return null;
        }

        if (!str_starts_with($header, 'Bearer ')) {
            $this->jsonError(401, 'Invalid Authorization header', 'Expected format: Authorization: Bearer <key>');
            return null;
        }

        $plainText = substr($header, 7);

        if ($plainText === '' || $plainText === false) {
            $this->jsonError(401, 'Missing API key', 'Bearer token is empty.');
            return null;
        }

        // ------------------------------------------------------------------
        // 2. Look up key in database
        // ------------------------------------------------------------------
        $repo   = new ApiKeyRepository($this->pdo);
        $apiKey = $repo->findByPlainText($plainText);

        if ($apiKey === null) {
            $this->logger->info('API key not found', [
                'prefix' => substr($plainText, 0, 6),
                'ip'     => $this->callerIp(),
            ]);
            $this->jsonError(401, 'Invalid API key', 'The provided API key does not exist.');
            return null;
        }

        // ------------------------------------------------------------------
        // 3. Expiry check
        // ------------------------------------------------------------------
        if ($apiKey->isExpired()) {
            $this->logger->info('API key expired', [
                'key_id'     => $apiKey->id,
                'expires_at' => $apiKey->expiresAt?->format('c'),
                'ip'         => $this->callerIp(),
            ]);
            $this->jsonError(401, 'API key expired', 'This API key has expired. Please generate a new one.');
            return null;
        }

        // ------------------------------------------------------------------
        // 4. IP whitelist check
        // ------------------------------------------------------------------
        $ip = $this->callerIp();

        if (!$apiKey->isIpAllowed($ip)) {
            $this->logger->warning('API key IP not whitelisted', [
                'key_id' => $apiKey->id,
                'ip'     => $ip,
            ]);
            $this->jsonError(403, 'IP address not allowed', 'Your IP address is not permitted to use this API key.');
            return null;
        }

        // ------------------------------------------------------------------
        // 5. Scope check
        // ------------------------------------------------------------------
        if (!$apiKey->hasScope($requiredScope)) {
            $this->logger->info('API key missing scope', [
                'key_id'   => $apiKey->id,
                'required' => $requiredScope,
                'granted'  => $apiKey->scopes,
            ]);
            $this->jsonError(403, 'Insufficient scope', "This key does not have the '{$requiredScope}' scope.");
            return null;
        }

        // ------------------------------------------------------------------
        // 6. Schedule non-blocking last_used_at update
        // ------------------------------------------------------------------
        $keyId = $apiKey->id;
        $pdo   = $this->pdo;

        register_shutdown_function(static function () use ($keyId, $pdo): void {
            (new ApiKeyRepository($pdo))->touchLastUsed($keyId);
        });

        $this->logger->debug('API key authenticated', [
            'key_id' => $apiKey->id,
            'scope'  => $requiredScope,
            'ip'     => $ip,
        ]);

        return $apiKey;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Send a JSON error response and set the HTTP status code.
     *
     * @param int    $status  HTTP status code.
     * @param string $error   Short error type string.
     * @param string $message Human-readable explanation.
     *
     * @return void
     */
    private function jsonError(int $status, string $error, string $message): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error'   => $error,
            'message' => $message,
            'code'    => $status,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Return the caller's IP address, respecting trusted proxies.
     *
     * Uses REMOTE_ADDR as the authoritative source.  X-Forwarded-For is
     * intentionally ignored to prevent IP spoofing by untrusted clients.
     *
     * @return string
     */
    private function callerIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
