<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – HMAC Request Validator
 *
 * Validates the X-Agent-Signature header on every incoming request to ensure
 * that all requests originate from the authorised web container.
 *
 * Signature algorithm:
 *   hmac_sha256(SECRET, STRTOUPPER(METHOD) + PATH + RAW_BODY + NUL + USER_ID + NUL + USERNAME)
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Security;

use InvalidArgumentException;

/**
 * Class HmacValidator
 *
 * Validates the HMAC-SHA256 signature carried in the X-Agent-Signature header.
 * Uses hash_equals() for comparison to prevent timing-based side-channel attacks.
 *
 * Usage:
 *   $validator = new HmacValidator($secret);
 *   if (!$validator->validate('POST', '/crons', $rawBody, $signatureHeader)) {
 *       // reject request
 *   }
 */
final class HmacValidator
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** @var string Optional prefix that callers may include in the header value */
    private const SIGNATURE_PREFIX = 'sha256=';

    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    /** @var string HMAC shared secret */
    private string $secret;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param string $secret The shared HMAC secret. Must not be empty.
     *
     * @throws InvalidArgumentException When an empty secret is provided.
     */
    public function __construct(string $secret)
    {
        if ($secret === '') {
            throw new InvalidArgumentException('HMAC secret must not be empty.');
        }

        $this->secret = $secret;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Validate the HMAC-SHA256 signature of an incoming request.
     *
     * The expected signature is computed as:
     *   hmac_sha256(secret, strtoupper(method) + path + rawBody + NUL + userId + NUL + username)
     *
     * Comparison is done via hash_equals() to prevent timing attacks.
     *
     * @param string $method          HTTP method (e.g. 'GET', 'POST').
     * @param string $path            Request path including leading slash (e.g. '/crons').
     * @param string $body            Raw request body (may be empty string for GET requests).
     * @param string $signatureHeader Value of the X-Agent-Signature header.
     * @param int    $userId          Value of the X-User-Id header (0 when absent).
     * @param string $username        Value of the X-User-Name header (empty when absent).
     *
     * @return bool True when the signature is valid, false otherwise.
     */
    public function validate(
        string $method,
        string $path,
        string $body,
        string $signatureHeader,
        int    $userId   = 0,
        string $username = '',
    ): bool {
        // Reject immediately if the header is absent or blank
        if ($signatureHeader === '') {
            return false;
        }

        // Strip optional "sha256=" prefix so both formats are accepted
        $providedHash = $this->stripPrefix($signatureHeader);

        if ($providedHash === '') {
            return false;
        }

        // Build the message that was signed: METHOD + PATH + BODY + NUL + USER_ID + NUL + USERNAME
        $message = strtoupper($method) . $path . $body . "\0" . $userId . "\0" . $username;

        // Compute the expected HMAC
        $expectedHash = hash_hmac('sha256', $message, $this->secret);

        // Constant-time comparison prevents timing attacks
        return hash_equals($expectedHash, $providedHash);
    }

    /**
     * Compute the expected HMAC signature for the given request parameters.
     *
     * Useful for generating test signatures and for debugging.
     *
     * @param string $method   HTTP method (will be uppercased).
     * @param string $path     Request path.
     * @param string $body     Raw request body.
     * @param int    $userId   Acting user ID (0 when absent).
     * @param string $username Acting username (empty when absent).
     *
     * @return string Lowercase hex HMAC-SHA256 string (64 characters).
     */
    public function compute(
        string $method,
        string $path,
        string $body,
        int    $userId   = 0,
        string $username = '',
    ): string {
        $message = strtoupper($method) . $path . $body . "\0" . $userId . "\0" . $username;

        return hash_hmac('sha256', $message, $this->secret);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Remove the "sha256=" prefix from a signature header value if present.
     *
     * @param string $header Raw header value.
     *
     * @return string Header value without the prefix.
     */
    private function stripPrefix(string $header): string
    {
        if (str_starts_with($header, self::SIGNATURE_PREFIX)) {
            return substr($header, strlen(self::SIGNATURE_PREFIX));
        }

        return $header;
    }
}
