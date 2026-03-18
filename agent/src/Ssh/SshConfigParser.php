<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – SshConfigParser
 *
 * Parses an SSH client configuration file and returns the list of
 * non-wildcard Host aliases defined in it.  This is used by the agent
 * to let administrators select a valid SSH target when configuring
 * remote cron job execution.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Ssh;

/**
 * Class SshConfigParser
 *
 * Reads ~/.ssh/config for a given Linux user and extracts all Host alias
 * names that are not wildcards (i.e. do not contain * or ?).
 *
 * Example SSH config snippet:
 * ```
 * Host webserver production
 *     HostName 10.0.0.1
 *     User deploy
 *
 * Host *
 *     ServerAliveInterval 60
 * ```
 * Returns: ['production', 'webserver']
 */
final class SshConfigParser
{
    /**
     * Parse the SSH config file for the given Linux user and return all
     * non-wildcard Host aliases defined in it.
     *
     * @param  string  $linuxUser  The Linux system user whose ~/.ssh/config to read.
     * @return string[]            Sorted list of Host alias names.
     *
     * @throws \InvalidArgumentException If the username contains invalid characters.
     */
    public function getHosts(string $linuxUser): array
    {
        // ------------------------------------------------------------------
        // 1. Validate username to prevent path traversal / injection
        // ------------------------------------------------------------------

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $linuxUser)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid linux username "%s": only [a-zA-Z0-9_-] characters are allowed.',
                    $linuxUser
                )
            );
        }

        // ------------------------------------------------------------------
        // 2. Resolve the home directory via the POSIX user database
        // ------------------------------------------------------------------

        $pwEntry = posix_getpwnam($linuxUser);

        if ($pwEntry === false) {
            // User does not exist on this system
            return [];
        }

        $homeDir = (string) ($pwEntry['dir'] ?? '');

        if ($homeDir === '') {
            return [];
        }

        // ------------------------------------------------------------------
        // 3. Locate and validate the SSH config file
        // ------------------------------------------------------------------

        $configPath = $homeDir . '/.ssh/config';

        if (!file_exists($configPath) || !is_readable($configPath)) {
            return [];
        }

        // ------------------------------------------------------------------
        // 4. Parse Host directives line by line
        // ------------------------------------------------------------------

        $fileContent = file($configPath, FILE_IGNORE_NEW_LINES);

        if ($fileContent === false) {
            return [];
        }

        $aliases = [];

        foreach ($fileContent as $line) {
            // Check for a Host directive (case-insensitive)
            if (!preg_match('/^\s*Host\s+(.+)$/i', $line, $matches)) {
                continue;
            }

            // A single Host directive may list multiple space-separated aliases
            $parts = preg_split('/\s+/', trim($matches[1]));

            if ($parts === false) {
                continue;
            }

            foreach ($parts as $alias) {
                $alias = trim($alias);

                if ($alias === '') {
                    continue;
                }

                // Skip wildcards – they are global defaults, not usable targets
                if (str_contains($alias, '*') || str_contains($alias, '?')) {
                    continue;
                }

                $aliases[] = $alias;
            }
        }

        // ------------------------------------------------------------------
        // 5. Return sorted unique aliases
        // ------------------------------------------------------------------

        $aliases = array_values(array_unique($aliases));
        sort($aliases);

        return $aliases;
    }
}
