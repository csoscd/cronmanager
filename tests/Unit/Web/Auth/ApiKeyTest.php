<?php

declare(strict_types=1);

/**
 * Cronmanager – Unit Tests: ApiKey
 *
 * Tests the ApiKey value object in isolation, covering scope checking,
 * expiry logic, IP whitelist matching (bare IP, CIDR /32, /24, /0, IPv6),
 * and agent-scope restriction.
 *
 * No database or HTTP dependency – all state is passed through the constructor.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Unit\Web\Auth;

use Cronmanager\Web\Auth\ApiKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Factory helper
    // -------------------------------------------------------------------------

    /**
     * Build an ApiKey with sensible defaults, allowing individual fields to be
     * overridden for each test scenario.
     *
     * @param array<string, mixed> $overrides
     */
    private function makeKey(array $overrides = []): ApiKey
    {
        return new ApiKey(
            id:          $overrides['id']          ?? 1,
            userId:      $overrides['userId']      ?? 42,
            name:        $overrides['name']        ?? 'Test Key',
            scopes:      $overrides['scopes']      ?? ['jobs:read'],
            agentIds:    $overrides['agentIds']    ?? null,
            expiresAt:   $overrides['expiresAt']   ?? null,
            ipWhitelist: $overrides['ipWhitelist'] ?? null,
            lastUsedAt:  $overrides['lastUsedAt']  ?? null,
            createdAt:   $overrides['createdAt']   ?? new \DateTimeImmutable('2026-01-01'),
        );
    }

    // =========================================================================
    // hasScope
    // =========================================================================

    #[Test]
    public function hasScopeReturnsTrueForGrantedScope(): void
    {
        $key = $this->makeKey(['scopes' => ['jobs:read', 'jobs:write']]);

        $this->assertTrue($key->hasScope('jobs:read'));
        $this->assertTrue($key->hasScope('jobs:write'));
    }

    #[Test]
    public function hasScopeReturnsFalseForMissingScope(): void
    {
        $key = $this->makeKey(['scopes' => ['jobs:read']]);

        $this->assertFalse($key->hasScope('jobs:write'));
        $this->assertFalse($key->hasScope('settings:write'));
    }

    #[Test]
    public function hasScopeReturnsFalseForEmptyScopeList(): void
    {
        $key = $this->makeKey(['scopes' => []]);

        $this->assertFalse($key->hasScope('jobs:read'));
    }

    // =========================================================================
    // isExpired
    // =========================================================================

    #[Test]
    public function isExpiredReturnsFalseWhenNoExpirySet(): void
    {
        $key = $this->makeKey(['expiresAt' => null]);

        $this->assertFalse($key->isExpired());
    }

    #[Test]
    public function isExpiredReturnsTrueForPastExpiry(): void
    {
        $key = $this->makeKey([
            'expiresAt' => new \DateTimeImmutable('2020-01-01 00:00:00'),
        ]);

        $this->assertTrue($key->isExpired());
    }

    #[Test]
    public function isExpiredReturnsFalseForFutureExpiry(): void
    {
        $key = $this->makeKey([
            'expiresAt' => new \DateTimeImmutable('+1 year'),
        ]);

        $this->assertFalse($key->isExpired());
    }

    // =========================================================================
    // isIpAllowed
    // =========================================================================

    #[Test]
    public function isIpAllowedReturnsTrueWhenNoWhitelistConfigured(): void
    {
        $key = $this->makeKey(['ipWhitelist' => null]);

        $this->assertTrue($key->isIpAllowed('1.2.3.4'));
        $this->assertTrue($key->isIpAllowed('::1'));
    }

    #[Test]
    public function isIpAllowedReturnsTrueWhenEmptyWhitelistConfigured(): void
    {
        $key = $this->makeKey(['ipWhitelist' => []]);

        $this->assertTrue($key->isIpAllowed('10.0.0.1'));
    }

    #[Test]
    public function isIpAllowedMatchesBareIpExactly(): void
    {
        $key = $this->makeKey(['ipWhitelist' => ['192.168.1.100']]);

        $this->assertTrue($key->isIpAllowed('192.168.1.100'));
        $this->assertFalse($key->isIpAllowed('192.168.1.101'));
    }

    #[Test]
    public function isIpAllowedMatchesSlash32(): void
    {
        $key = $this->makeKey(['ipWhitelist' => ['10.10.10.5/32']]);

        $this->assertTrue($key->isIpAllowed('10.10.10.5'));
        $this->assertFalse($key->isIpAllowed('10.10.10.6'));
    }

    #[Test]
    public function isIpAllowedMatchesSlash24Network(): void
    {
        $key = $this->makeKey(['ipWhitelist' => ['192.168.1.0/24']]);

        $this->assertTrue($key->isIpAllowed('192.168.1.1'));
        $this->assertTrue($key->isIpAllowed('192.168.1.254'));
        $this->assertFalse($key->isIpAllowed('192.168.2.1'));
        $this->assertFalse($key->isIpAllowed('10.0.0.1'));
    }

    #[Test]
    public function isIpAllowedMatchesSlash16Network(): void
    {
        $key = $this->makeKey(['ipWhitelist' => ['10.0.0.0/16']]);

        $this->assertTrue($key->isIpAllowed('10.0.0.1'));
        $this->assertTrue($key->isIpAllowed('10.0.255.254'));
        $this->assertFalse($key->isIpAllowed('10.1.0.1'));
    }

    #[Test]
    public function isIpAllowedMatchesFirstEntryInMultipleEntries(): void
    {
        $key = $this->makeKey(['ipWhitelist' => ['10.0.0.0/8', '192.168.1.0/24']]);

        $this->assertTrue($key->isIpAllowed('10.1.2.3'));
        $this->assertTrue($key->isIpAllowed('192.168.1.50'));
        $this->assertFalse($key->isIpAllowed('172.16.0.1'));
    }

    #[Test]
    public function isIpAllowedReturnsFalseForMalformedCidr(): void
    {
        $key = $this->makeKey(['ipWhitelist' => ['not-an-ip/24']]);

        $this->assertFalse($key->isIpAllowed('10.0.0.1'));
    }

    #[Test]
    public function isIpAllowedMatchesIpv6Loopback(): void
    {
        $key = $this->makeKey(['ipWhitelist' => ['::1/128']]);

        $this->assertTrue($key->isIpAllowed('::1'));
        $this->assertFalse($key->isIpAllowed('::2'));
    }

    // =========================================================================
    // isAgentAllowed
    // =========================================================================

    #[Test]
    public function isAgentAllowedReturnsTrueWhenNoRestriction(): void
    {
        $key = $this->makeKey(['agentIds' => null]);

        $this->assertTrue($key->isAgentAllowed(1));
        $this->assertTrue($key->isAgentAllowed(99));
    }

    #[Test]
    public function isAgentAllowedReturnsTrueForPermittedAgent(): void
    {
        $key = $this->makeKey(['agentIds' => [1, 3, 7]]);

        $this->assertTrue($key->isAgentAllowed(1));
        $this->assertTrue($key->isAgentAllowed(3));
        $this->assertTrue($key->isAgentAllowed(7));
    }

    #[Test]
    public function isAgentAllowedReturnsFalseForNonPermittedAgent(): void
    {
        $key = $this->makeKey(['agentIds' => [1, 3]]);

        $this->assertFalse($key->isAgentAllowed(2));
        $this->assertFalse($key->isAgentAllowed(99));
    }
}
