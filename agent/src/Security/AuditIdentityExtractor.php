<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – Audit Identity Extractor
 *
 * Extracts the user-context fields (userId, username) from an incoming
 * request's server headers and returns them in a canonical form suitable
 * for HMAC validation and audit logging.
 *
 * Why a dedicated class?
 * ----------------------
 * The v4.4.4 incident was caused by agent.php using 'system' as the default
 * for a missing X-User-Name header, while the cron-wrapper signs with
 * username="" (empty string).  Keeping the extraction logic inline made it
 * hard to regression-test: any copy of the logic in a test would not catch
 * a future change to the default in agent.php itself.
 *
 * By extracting the logic here, both agent.php AND the integration test call
 * the identical code path.  A change of the default from '' to anything else
 * will immediately break the regression guard in HmacRejectionTest.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Security;

/**
 * Class AuditIdentityExtractor
 *
 * Pure-function wrapper around the X-User-Id / X-User-Name header extraction.
 * Stateless and side-effect-free; safe to call from any context.
 */
final class AuditIdentityExtractor
{
    /**
     * Extract user identity from a server superglobal or equivalent array.
     *
     * Rules:
     *   - X-User-Id  header → non-negative integer, defaults to 0 when absent.
     *   - X-User-Name header → trimmed string, truncated to 128 chars,
     *                          defaults to '' (empty string) when absent.
     *
     * The empty-string default for username is load-bearing: the cron-wrapper
     * has no user context and signs requests with username="".  Any non-empty
     * default would cause an HMAC mismatch and reject all cron-wrapper
     * requests (root cause of the v4.4.4 incident).
     *
     * @param array<string, mixed> $server  Typically $_SERVER; may be a
     *                                      fabricated array in tests.
     *
     * @return array{userId: int, username: string}
     */
    public static function fromServer(array $server): array
    {
        $userId   = max(0, (int) ($server['HTTP_X_USER_ID']   ?? 0));
        $username = substr(trim((string) ($server['HTTP_X_USER_NAME'] ?? '')), 0, 128);

        return ['userId' => $userId, 'username' => $username];
    }
}
