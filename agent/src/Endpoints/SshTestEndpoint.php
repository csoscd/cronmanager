<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – SshTestEndpoint
 *
 * Handles POST /ssh/test requests.
 *
 * Verifies that the specified SSH host alias is reachable from the agent
 * container by running a non-interactive SSH probe command.  The host must
 * exist in at least one candidate user's ~/.ssh/config (root or UID ≥ 1000)
 * to prevent unrestricted probing of arbitrary hostnames.
 *
 * Request body (JSON):
 * ```json
 * { "host": "myserver" }
 * ```
 *
 * Response on success (HTTP 200):
 * ```json
 * { "success": true,  "output": "ok" }
 * { "success": false, "output": "ssh: connect to host myserver port 22: Connection refused" }
 * ```
 *
 * Response on validation failure (HTTP 422):
 * ```json
 * { "error": "Invalid host name", "message": "..." }
 * ```
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Ssh\SshConfigParser;
use Cronmanager\Agent\Util\AnsiStripper;
use Monolog\Logger;

/**
 * Class SshTestEndpoint
 *
 * Tests SSH connectivity to a named host alias.
 */
final class SshTestEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * SshTestEndpoint constructor.
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
     * Handle an incoming POST /ssh/test request.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        // ------------------------------------------------------------------
        // 1. Parse and validate the request body
        // ------------------------------------------------------------------

        $body = (string) file_get_contents('php://input');
        $data = json_decode($body, true);
        $host = isset($data['host']) ? trim((string) $data['host']) : '';

        if ($host === '') {
            jsonResponse(422, [
                'error'   => 'Missing required parameter: host',
                'message' => 'The "host" field must be a non-empty SSH host alias.',
            ]);
            return;
        }

        // Restrict to safe hostname characters (SSH config Host aliases).
        // Wildcards and arbitrary hostnames are not permitted.
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $host)) {
            $this->logger->warning('SshTestEndpoint: invalid host name', ['host' => $host]);
            jsonResponse(422, [
                'error'   => 'Invalid host name',
                'message' => 'Host must contain only letters, digits, dots, hyphens, and underscores.',
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 2. Verify the host exists in a known SSH config (whitelist check)
        // ------------------------------------------------------------------

        if (!$this->isKnownSshHost($host)) {
            $this->logger->warning('SshTestEndpoint: host not found in any SSH config', ['host' => $host]);
            jsonResponse(422, [
                'error'   => 'Unknown SSH host',
                'message' => 'The specified host alias is not configured in any user\'s ~/.ssh/config.',
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 3. Run the SSH probe
        // ------------------------------------------------------------------

        $this->logger->info('SshTestEndpoint: testing SSH connectivity', ['host' => $host]);

        $safeHost = escapeshellarg($host);
        $command  = 'ssh -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new '
                  . $safeHost . ' echo ok 2>&1';

        $output   = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        $outputText = AnsiStripper::strip(implode("\n", $output));
        $success    = ($exitCode === 0 && trim($outputText) === 'ok');

        $this->logger->info('SshTestEndpoint: SSH probe completed', [
            'host'      => $host,
            'exit_code' => $exitCode,
            'success'   => $success,
        ]);

        jsonResponse(200, [
            'success' => $success,
            'output'  => $outputText,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return true if the given host alias appears in at least one candidate
     * user's ~/.ssh/config (root + UID ≥ 1000).
     *
     * @param string $host SSH host alias to look up.
     *
     * @return bool
     */
    private function isKnownSshHost(string $host): bool
    {
        $parser  = new SshConfigParser();
        $passwdFh = fopen('/etc/passwd', 'r');

        if ($passwdFh === false) {
            // Cannot verify — allow the probe to avoid false negatives when
            // /etc/passwd is temporarily unavailable.
            $this->logger->warning('SshTestEndpoint: cannot open /etc/passwd; skipping whitelist check');
            return true;
        }

        while (($line = fgets($passwdFh)) !== false) {
            $fields = explode(':', rtrim($line));

            if (count($fields) < 4) {
                continue;
            }

            $username = $fields[0];
            $uid      = (int) $fields[2];

            if ($uid !== 0 && $uid < 1000) {
                continue;
            }

            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
                continue;
            }

            try {
                $hosts = $parser->getHosts($username);

                if (in_array($host, $hosts, true)) {
                    fclose($passwdFh);
                    return true;
                }
            } catch (\Throwable) {
                // User has no ~/.ssh/config – skip silently
            }
        }

        fclose($passwdFh);
        return false;
    }
}
