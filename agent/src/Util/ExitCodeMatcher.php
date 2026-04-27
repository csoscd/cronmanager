<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – ExitCodeMatcher
 *
 * Parses and evaluates the per-job exit-code expression that controls which
 * exit codes trigger an automatic retry.
 *
 * Expression syntax
 * -----------------
 * - Null or empty string : any non-zero exit code matches (default behaviour,
 *                          fully backward-compatible with pre-v3.0.0 jobs).
 * - Comma-separated list : each token is either a single integer (0–255) or a
 *                          range "N-M" where N < M, both in [0, 255].
 *                          Whitespace around tokens is trimmed.
 *
 * Examples
 * --------
 *   null       → matches any exit code != 0
 *   ""         → same as null
 *   "1-5,10,255" → matches 1, 2, 3, 4, 5, 10, 255
 *   "1"        → matches only exit code 1
 *   "1-127"    → matches any code from 1 to 127 inclusive
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Util;

/**
 * Class ExitCodeMatcher
 *
 * Stateless utility: all methods are static.
 */
final class ExitCodeMatcher
{
    /**
     * Return true when the given exit code should trigger a retry.
     *
     * @param string|null $expression The configured restart_on_exitcodes value.
     * @param int         $exitCode   The actual exit code of the finished execution.
     *
     * @return bool
     */
    public static function matches(?string $expression, int $exitCode): bool
    {
        if ($expression === null || trim($expression) === '') {
            return $exitCode !== 0;
        }

        foreach (self::parseTokens($expression) as [$min, $max]) {
            if ($exitCode >= $min && $exitCode <= $max) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate an exit-code expression string.
     *
     * Returns null when the expression is valid (including the empty string),
     * or a human-readable error message when it is not.
     *
     * @param string $expression The expression to validate.
     *
     * @return string|null Null on success, error message on failure.
     */
    public static function validate(string $expression): ?string
    {
        $expression = trim($expression);

        if ($expression === '') {
            return null;
        }

        foreach (explode(',', $expression) as $token) {
            $token = trim($token);

            if ($token === '') {
                return 'Expression contains an empty token (check for trailing or consecutive commas).';
            }

            if (str_contains($token, '-')) {
                $parts = explode('-', $token, 2);

                if (!ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
                    return sprintf('Invalid range "%s": both parts must be non-negative integers.', $token);
                }

                $n = (int) $parts[0];
                $m = (int) $parts[1];

                if ($n > 255 || $m > 255) {
                    return sprintf('Range "%s": values must be between 0 and 255.', $token);
                }

                if ($n >= $m) {
                    return sprintf('Range "%s": the first value must be less than the second.', $token);
                }
            } else {
                if (!ctype_digit($token)) {
                    return sprintf('Invalid token "%s": must be an integer (0–255) or a range such as "1-5".', $token);
                }

                $val = (int) $token;

                if ($val > 255) {
                    return sprintf('Value %d is out of range (must be 0–255).', $val);
                }
            }
        }

        return null;
    }

    /**
     * Parse a non-empty, pre-validated expression into [min, max] pairs.
     *
     * @param string $expression Non-empty expression string.
     *
     * @return array<int, array{int, int}> List of [min, max] integer pairs.
     */
    private static function parseTokens(string $expression): array
    {
        $pairs = [];

        foreach (explode(',', $expression) as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            if (str_contains($token, '-')) {
                $parts  = explode('-', $token, 2);
                $pairs[] = [(int) $parts[0], (int) $parts[1]];
            } else {
                $val     = (int) $token;
                $pairs[] = [$val, $val];
            }
        }

        return $pairs;
    }
}
