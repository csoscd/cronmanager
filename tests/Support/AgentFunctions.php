<?php

declare(strict_types=1);

/**
 * Cronmanager – Test stub for the global jsonResponse() agent function.
 *
 * In production, jsonResponse() is defined in agent/agent.php and calls exit()
 * after emitting a JSON HTTP response. Tests never load agent.php, so this file
 * provides a compatible stub that captures the response in AgentResponse instead
 * of terminating the process.
 *
 * Loaded unconditionally from tests/bootstrap.php.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

// ---------------------------------------------------------------------------
// AgentResponse capture class (in Tests\Support namespace)
// ---------------------------------------------------------------------------

namespace Tests\Support {

    /**
     * Captures the status code and body of the last jsonResponse() call.
     *
     * Reset between tests via AgentResponse::reset().
     */
    final class AgentResponse
    {
        public static ?int $statusCode = null;

        /** @var array<string, mixed>|null */
        public static ?array $body = null;

        public static function reset(): void
        {
            self::$statusCode = null;
            self::$body       = null;
        }
    }
}

// ---------------------------------------------------------------------------
// Global jsonResponse() stub (root namespace)
//
// Endpoints call jsonResponse() without a namespace qualifier, so the stub
// must live in the global namespace. Using bracketed namespace {} syntax
// allows mixing the two namespace declarations in one file.
// ---------------------------------------------------------------------------

namespace {

    if (!function_exists('jsonResponse')) {
        /**
         * Test stub: capture response without calling exit().
         *
         * Endpoints always call `return;` immediately after jsonResponse(), so
         * not calling exit() here is safe – execution returns to the caller
         * (the test) and the next assertion can inspect AgentResponse.
         *
         * @param int                  $statusCode HTTP status code.
         * @param array<string, mixed> $data       Response body.
         */
        function jsonResponse(int $statusCode, array $data): void
        {
            http_response_code($statusCode);
            \Tests\Support\AgentResponse::$statusCode = $statusCode;
            \Tests\Support\AgentResponse::$body       = $data;
        }
    }
}
