<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – ImportSshTargetsEndpoint
 *
 * Handles GET /import/ssh-targets requests.
 *
 * Returns a deduplicated, sorted list of all SSH host aliases found across
 * every candidate Linux user's ~/.ssh/config file (root + UID >= 1000).
 * This list is used by the web UI import page to populate the target
 * selector, allowing crontab entries to be imported from remote hosts.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "data":  ["homeserver", "webhost"],
 *   "count": 2
 * }
 * ```
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Ssh\SshConfigParser;
use Monolog\Logger;

/**
 * Class ImportSshTargetsEndpoint
 *
 * Aggregates SSH host aliases from all candidate users' ~/.ssh/config files
 * and returns a unique sorted list.
 */
final class ImportSshTargetsEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * ImportSshTargetsEndpoint constructor.
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
     * Handle an incoming GET /import/ssh-targets request.
     *
     * Reads /etc/passwd to find all candidate users (root + UID >= 1000),
     * parses each user's ~/.ssh/config (silently skipping missing configs),
     * and returns a deduplicated sorted list of all SSH Host aliases found.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $this->logger->debug('ImportSshTargetsEndpoint: handling GET /import/ssh-targets');

        $parser   = new SshConfigParser();
        $allHosts = [];

        // ------------------------------------------------------------------
        // Read candidate users from /etc/passwd
        // ------------------------------------------------------------------
        $passwdFh = fopen('/etc/passwd', 'r');

        if ($passwdFh === false) {
            $this->logger->warning('ImportSshTargetsEndpoint: cannot open /etc/passwd');
            jsonResponse(200, ['data' => [], 'count' => 0]);
            return;
        }

        while (($line = fgets($passwdFh)) !== false) {
            $fields = explode(':', rtrim($line));

            if (count($fields) < 4) {
                continue;
            }

            $username = $fields[0];
            $uid      = (int) $fields[2];

            // Only root (UID 0) and normal users (UID >= 1000)
            if ($uid !== 0 && $uid < 1000) {
                continue;
            }

            // Skip malformed or unsafe usernames
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
                continue;
            }

            try {
                $hosts = $parser->getHosts($username);
                foreach ($hosts as $host) {
                    $allHosts[$host] = true;
                }
            } catch (\Throwable $e) {
                // Non-fatal: user may not have ~/.ssh/config
                $this->logger->debug('ImportSshTargetsEndpoint: skipping user (no SSH config)', [
                    'user'  => $username,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        fclose($passwdFh);

        // ------------------------------------------------------------------
        // Return deduplicated sorted list
        // ------------------------------------------------------------------
        $hosts = array_keys($allHosts);
        sort($hosts);

        $this->logger->debug('ImportSshTargetsEndpoint: found SSH targets', [
            'count' => count($hosts),
        ]);

        jsonResponse(200, [
            'data'  => $hosts,
            'count' => count($hosts),
        ]);
    }
}
