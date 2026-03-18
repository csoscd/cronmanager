<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – SshHostsEndpoint
 *
 * Handles GET /ssh-hosts?user={linux_user} requests.
 *
 * Returns the list of SSH Host aliases found in the given Linux user's
 * ~/.ssh/config file.  This list is used by the web UI to populate the
 * SSH host selector when configuring remote cron job execution.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "user":  "deploy",
 *   "data":  ["myserver", "webhost"],
 *   "count": 2
 * }
 * ```
 *
 * Response on missing parameter (HTTP 400):
 * ```json
 * { "error": "Missing required parameter: user" }
 * ```
 *
 * Response on invalid username (HTTP 422):
 * ```json
 * { "error": "Invalid value for parameter: user", "message": "..." }
 * ```
 *
 * This class relies on the global `jsonResponse()` function being available
 * in the calling scope (defined in agent.php).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Ssh\SshConfigParser;
use Monolog\Logger;

/**
 * Class SshHostsEndpoint
 *
 * Handles GET /ssh-hosts API requests.
 *
 * Required query parameters:
 *   - user (string): The Linux username whose ~/.ssh/config to read.
 */
final class SshHostsEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * SshHostsEndpoint constructor.
     *
     * @param Logger $logger Monolog logger instance.
     */
    public function __construct(
        private readonly Logger $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming GET /ssh-hosts request.
     *
     * @param array<string, string> $params Path parameters (unused for this endpoint).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        // ------------------------------------------------------------------
        // 1. Read and validate the required ?user= query parameter
        // ------------------------------------------------------------------

        $user = isset($_GET['user']) ? trim((string) $_GET['user']) : null;

        if ($user === null || $user === '') {
            $this->logger->warning('SshHostsEndpoint: missing required parameter "user"');
            jsonResponse(400, [
                'error' => 'Missing required parameter: user',
            ]);
            return;
        }

        $this->logger->debug('SshHostsEndpoint: handling GET /ssh-hosts', ['user' => $user]);

        // ------------------------------------------------------------------
        // 2. Parse the SSH config file
        // ------------------------------------------------------------------

        $parser = new SshConfigParser();

        try {
            $hosts = $parser->getHosts($user);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('SshHostsEndpoint: invalid username', [
                'user'    => $user,
                'message' => $e->getMessage(),
            ]);
            jsonResponse(422, [
                'error'   => 'Invalid value for parameter: user',
                'message' => $e->getMessage(),
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 3. Return the list of SSH hosts
        // ------------------------------------------------------------------

        $this->logger->debug('SshHostsEndpoint: found SSH hosts', [
            'user'  => $user,
            'count' => count($hosts),
        ]);

        jsonResponse(200, [
            'user'  => $user,
            'data'  => $hosts,
            'count' => count($hosts),
        ]);
    }
}
