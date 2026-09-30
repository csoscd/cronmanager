<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – AgentHttpException
 *
 * Thrown by HostAgentClient when the host agent returns an HTTP error status
 * (4xx or 5xx).  Carries the HTTP status code so callers can distinguish
 * between different failure modes (e.g. 404 Not Found vs 422 Unprocessable
 * Entity) without parsing the exception message string.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Agent;

/**
 * Class AgentHttpException
 *
 * Extends \RuntimeException with an HTTP status code property.
 */
class AgentHttpException extends \RuntimeException
{
    /**
     * @param int                  $statusCode   HTTP status code returned by the agent.
     * @param string               $message      Human-readable error message.
     * @param array<string, mixed> $responseBody Decoded JSON response body (empty when not JSON).
     */
    public function __construct(
        private readonly int   $statusCode,
        string                 $message = '',
        private readonly array $responseBody = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Return the HTTP status code returned by the agent.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Return the decoded JSON response body, or an empty array when unavailable.
     *
     * @return array<string, mixed>
     */
    public function getResponseBody(): array
    {
        return $this->responseBody;
    }
}
