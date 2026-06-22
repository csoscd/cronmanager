<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – API Key Value Object
 *
 * Immutable representation of an API key record as returned from the database.
 * The plain-text key is never stored here; only the hashed version exists in
 * the database and this object carries only the properties needed for
 * authorization decisions.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Auth;

/**
 * Class ApiKey
 *
 * Represents one row from the `api_keys` table, hydrated from a PDO fetch.
 */
final class ApiKey
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param int           $id          Primary key.
     * @param int           $userId      FK to users.id.
     * @param string        $name        Human-readable label.
     * @param string[]      $scopes      Granted scope strings.
     * @param int[]|null    $agentIds    NULL = all agents; array = restricted set.
     * @param \DateTimeImmutable|null $expiresAt NULL = never expires.
     * @param string[]|null $ipWhitelist NULL = no restriction; CIDR strings.
     * @param \DateTimeImmutable|null $lastUsedAt Last successful API call.
     * @param \DateTimeImmutable $createdAt  Creation timestamp.
     */
    public function __construct(
        public readonly int                  $id,
        public readonly int                  $userId,
        public readonly string               $name,
        public readonly array                $scopes,
        public readonly ?array               $agentIds,
        public readonly ?\DateTimeImmutable  $expiresAt,
        public readonly ?array               $ipWhitelist,
        public readonly ?\DateTimeImmutable  $lastUsedAt,
        public readonly \DateTimeImmutable   $createdAt,
    ) {}

    // -------------------------------------------------------------------------
    // Authorization helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether this key grants the given scope.
     *
     * @param string $scope Scope identifier to check.
     *
     * @return bool
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, strict: true);
    }

    /**
     * Check whether this key is expired.
     *
     * @return bool True when the key has an expiry date that is in the past.
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable('now');
    }

    /**
     * Check whether the given IP address is permitted by the whitelist.
     *
     * Returns true when:
     *   - No whitelist is configured (ipWhitelist === null), OR
     *   - The IP matches at least one CIDR entry in the whitelist.
     *
     * @param string $ip IPv4 or IPv6 address string.
     *
     * @return bool
     */
    public function isIpAllowed(string $ip): bool
    {
        if ($this->ipWhitelist === null || $this->ipWhitelist === []) {
            return true;
        }

        foreach ($this->ipWhitelist as $cidr) {
            if ($this->ipMatchesCidr($ip, trim($cidr))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a given agent ID is permitted by this key's agent restriction.
     *
     * Returns true when agentIds is null (all agents permitted) or the given ID
     * is contained in the allowed list.
     *
     * @param int $agentId Agent ID to check.
     *
     * @return bool
     */
    public function isAgentAllowed(int $agentId): bool
    {
        if ($this->agentIds === null) {
            return true;
        }

        return in_array($agentId, $this->agentIds, strict: true);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Test whether an IPv4 or IPv6 address falls within a CIDR block.
     *
     * Supports both IPv4 (e.g. 192.168.1.0/24) and IPv6 (e.g. ::1/128) CIDR
     * notation.  A bare IP address without prefix is treated as /32 (IPv4) or
     * /128 (IPv6).
     *
     * @param string $ip   The address to test.
     * @param string $cidr The CIDR block to test against.
     *
     * @return bool
     */
    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            // Bare IP – compare directly
            return $ip === $cidr;
        }

        [$network, $prefixLen] = explode('/', $cidr, 2);

        $ipBin      = @inet_pton($ip);
        $networkBin = @inet_pton($network);

        if ($ipBin === false || $networkBin === false) {
            return false;
        }

        // Both addresses must be the same family (v4 or v6)
        if (strlen($ipBin) !== strlen($networkBin)) {
            return false;
        }

        $prefix = (int) $prefixLen;
        $bits   = strlen($ipBin) * 8;

        if ($prefix < 0 || $prefix > $bits) {
            return false;
        }

        // Compare the first $prefix bits
        $fullBytes    = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if (substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask      = 0xFF & (0xFF << (8 - $remainingBits));
        $ipByte    = ord($ipBin[$fullBytes]);
        $netByte   = ord($networkBin[$fullBytes]);

        return ($ipByte & $mask) === ($netByte & $mask);
    }
}
