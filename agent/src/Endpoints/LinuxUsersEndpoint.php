<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – LinuxUsersEndpoint
 *
 * Handles GET /linux-users requests.
 *
 * Returns a deduplicated, sorted list of all valid Linux users found in
 * /etc/passwd (root plus users with UID >= 1000), together with a flag
 * indicating whether the agent is running inside a Docker container.
 *
 * The docker_mode flag lets the web UI decide how to present the linux_user
 * field: hidden with a fixed "root" value in Docker mode, or a dropdown list
 * in host mode.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "docker_mode": false,
 *   "data":  ["bob", "deploy", "root"],
 *   "count": 3
 * }
 * ```
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Monolog\Logger;

/**
 * Class LinuxUsersEndpoint
 *
 * Returns the list of valid crontab users available on the agent host and a
 * docker_mode flag for the web UI to adapt its presentation accordingly.
 */
final class LinuxUsersEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * LinuxUsersEndpoint constructor.
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
     * Handle an incoming GET /linux-users request.
     *
     * Reads /etc/passwd to find all candidate users (root + UID >= 1000) and
     * detects Docker mode by checking for the presence of /.dockerenv.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $this->logger->debug('LinuxUsersEndpoint: handling GET /linux-users');

        // ------------------------------------------------------------------
        // Detect Docker mode – Docker creates /.dockerenv in every container
        // ------------------------------------------------------------------
        $dockerMode = file_exists('/.dockerenv');

        // ------------------------------------------------------------------
        // Read candidate users from /etc/passwd
        // ------------------------------------------------------------------
        $passwdFh = fopen('/etc/passwd', 'r');

        if ($passwdFh === false) {
            $this->logger->warning('LinuxUsersEndpoint: cannot open /etc/passwd');
            jsonResponse(200, ['docker_mode' => $dockerMode, 'data' => [], 'count' => 0]);
            return;
        }

        $users = [];

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

            $users[$username] = true;
        }

        fclose($passwdFh);

        $users = array_keys($users);
        sort($users);

        $this->logger->debug('LinuxUsersEndpoint: found users', [
            'count'       => count($users),
            'docker_mode' => $dockerMode,
        ]);

        jsonResponse(200, [
            'docker_mode' => $dockerMode,
            'data'        => $users,
            'count'       => count($users),
        ]);
    }
}
