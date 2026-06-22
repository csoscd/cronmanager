<?php

declare(strict_types=1);

/**
 * Cronmanager – Unit Tests: ScopeHelper
 *
 * Tests the ScopeHelper constants, role-to-scope mapping, and the
 * filterScopes() validation/intersection logic.
 *
 * No external dependencies – all assertions operate on the static class.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Unit\Web\Auth;

use Cronmanager\Web\Auth\ScopeHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScopeHelperTest extends TestCase
{
    // =========================================================================
    // Constants
    // =========================================================================

    #[Test]
    public function allScopesContainsEightEntries(): void
    {
        $this->assertCount(8, ScopeHelper::ALL_SCOPES);
    }

    #[Test]
    public function allScopesContainsExpectedValues(): void
    {
        $expected = [
            'jobs:read',
            'jobs:write',
            'jobs:execute',
            'export:read',
            'maintenance:read',
            'maintenance:write',
            'settings:read',
            'settings:write',
        ];

        foreach ($expected as $scope) {
            $this->assertContains($scope, ScopeHelper::ALL_SCOPES, "Expected scope '{$scope}' in ALL_SCOPES");
        }
    }

    #[Test]
    public function allScopesHasNoDuplicates(): void
    {
        $unique = array_unique(ScopeHelper::ALL_SCOPES);
        $this->assertCount(count(ScopeHelper::ALL_SCOPES), $unique, 'ALL_SCOPES must not contain duplicates');
    }

    // =========================================================================
    // PROFILES
    // =========================================================================

    #[Test]
    public function profilesContainsFourNamedProfiles(): void
    {
        $this->assertCount(4, ScopeHelper::PROFILES);
        $this->assertArrayHasKey('read-only',  ScopeHelper::PROFILES);
        $this->assertArrayHasKey('operator',   ScopeHelper::PROFILES);
        $this->assertArrayHasKey('developer',  ScopeHelper::PROFILES);
        $this->assertArrayHasKey('full-admin', ScopeHelper::PROFILES);
    }

    #[Test]
    public function fullAdminProfileContainsAllScopes(): void
    {
        $profile = ScopeHelper::PROFILES['full-admin'];
        $this->assertEqualsCanonicalizing(ScopeHelper::ALL_SCOPES, $profile);
    }

    #[Test]
    public function readOnlyProfileContainsOnlyReadScopes(): void
    {
        $profile = ScopeHelper::PROFILES['read-only'];

        foreach ($profile as $scope) {
            $this->assertStringEndsWith(':read', $scope, "read-only profile must only contain :read scopes, found: {$scope}");
        }
    }

    #[Test]
    public function operatorProfileIncludesExecuteScope(): void
    {
        $this->assertContains('jobs:execute', ScopeHelper::PROFILES['operator']);
    }

    #[Test]
    public function operatorProfileDoesNotIncludeWriteScopes(): void
    {
        foreach (ScopeHelper::PROFILES['operator'] as $scope) {
            $this->assertNotSame('jobs:write', $scope, 'operator profile must not include jobs:write');
            $this->assertNotSame('maintenance:write', $scope, 'operator profile must not include maintenance:write');
            $this->assertNotSame('settings:write', $scope, 'operator profile must not include settings:write');
        }
    }

    #[Test]
    public function allProfileScopesAreKnown(): void
    {
        foreach (ScopeHelper::PROFILES as $name => $scopes) {
            foreach ($scopes as $scope) {
                $this->assertContains(
                    $scope,
                    ScopeHelper::ALL_SCOPES,
                    "Profile '{$name}' references unknown scope '{$scope}'"
                );
            }
        }
    }

    // =========================================================================
    // allowedScopesForRole
    // =========================================================================

    #[Test]
    public function adminRoleGrantsAllScopes(): void
    {
        $allowed = ScopeHelper::allowedScopesForRole('admin');
        $this->assertEqualsCanonicalizing(ScopeHelper::ALL_SCOPES, $allowed);
    }

    #[Test]
    public function viewRoleGrantsOnlyReadScopes(): void
    {
        $allowed = ScopeHelper::allowedScopesForRole('view');

        $this->assertNotEmpty($allowed);

        foreach ($allowed as $scope) {
            $this->assertStringEndsWith(':read', $scope, "view role must not grant '{$scope}'");
        }
    }

    #[Test]
    public function viewRoleDoesNotGrantWriteOrExecuteScopes(): void
    {
        $allowed = ScopeHelper::allowedScopesForRole('view');

        $this->assertNotContains('jobs:write',        $allowed);
        $this->assertNotContains('jobs:execute',       $allowed);
        $this->assertNotContains('maintenance:write',  $allowed);
        $this->assertNotContains('settings:write',     $allowed);
    }

    #[Test]
    public function unknownRoleFallsBackToViewScopes(): void
    {
        $viewAllowed    = ScopeHelper::allowedScopesForRole('view');
        $unknownAllowed = ScopeHelper::allowedScopesForRole('superuser');

        $this->assertEqualsCanonicalizing($viewAllowed, $unknownAllowed);
    }

    // =========================================================================
    // filterScopes
    // =========================================================================

    #[Test]
    public function filterScopesRemovesUnknownScopes(): void
    {
        $result = ScopeHelper::filterScopes(
            ['jobs:read', 'not-a-real-scope', 'jobs:write'],
            ScopeHelper::ALL_SCOPES
        );

        $this->assertContains('jobs:read', $result);
        $this->assertContains('jobs:write', $result);
        $this->assertNotContains('not-a-real-scope', $result);
    }

    #[Test]
    public function filterScopesEnforcesAllowedList(): void
    {
        $allowed = ['jobs:read', 'export:read'];
        $result  = ScopeHelper::filterScopes(
            ['jobs:read', 'jobs:write', 'export:read'],
            $allowed
        );

        $this->assertContains('jobs:read',   $result);
        $this->assertContains('export:read', $result);
        $this->assertNotContains('jobs:write', $result);
    }

    #[Test]
    public function filterScopesDeduplicates(): void
    {
        $result = ScopeHelper::filterScopes(
            ['jobs:read', 'jobs:read', 'jobs:read'],
            ScopeHelper::ALL_SCOPES
        );

        $this->assertCount(1, $result);
        $this->assertSame('jobs:read', $result[0]);
    }

    #[Test]
    public function filterScopesReturnsEmptyArrayWhenNothingMatches(): void
    {
        $result = ScopeHelper::filterScopes(['unknown:scope'], ScopeHelper::ALL_SCOPES);

        $this->assertSame([], $result);
    }

    #[Test]
    public function filterScopesReturnsEmptyArrayForEmptyInput(): void
    {
        $result = ScopeHelper::filterScopes([], ScopeHelper::ALL_SCOPES);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // Display helpers (smoke tests)
    // =========================================================================

    #[Test]
    public function labelReturnsNonEmptyStringForEveryKnownScope(): void
    {
        foreach (ScopeHelper::ALL_SCOPES as $scope) {
            $label = ScopeHelper::label($scope);
            $this->assertNotEmpty($label, "label() returned empty string for scope '{$scope}'");
        }
    }

    #[Test]
    public function badgeColorReturnsNonEmptyStringForEveryKnownScope(): void
    {
        foreach (ScopeHelper::ALL_SCOPES as $scope) {
            $color = ScopeHelper::badgeColor($scope);
            $this->assertNotEmpty($color, "badgeColor() returned empty string for scope '{$scope}'");
        }
    }
}
