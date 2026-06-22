<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – API Scope Helper
 *
 * Defines all valid API scopes, pre-defined profiles, and the mapping of
 * user roles to the maximum set of scopes they are allowed to grant.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Auth;

/**
 * Class ScopeHelper
 *
 * All scope constants and helper methods for the API key permission system.
 */
final class ScopeHelper
{
    // -------------------------------------------------------------------------
    // Scope constants
    // -------------------------------------------------------------------------

    public const SCOPE_JOBS_READ         = 'jobs:read';
    public const SCOPE_JOBS_WRITE        = 'jobs:write';
    public const SCOPE_JOBS_EXECUTE      = 'jobs:execute';
    public const SCOPE_EXPORT_READ       = 'export:read';
    public const SCOPE_MAINTENANCE_READ  = 'maintenance:read';
    public const SCOPE_MAINTENANCE_WRITE = 'maintenance:write';
    public const SCOPE_SETTINGS_READ     = 'settings:read';
    public const SCOPE_SETTINGS_WRITE    = 'settings:write';

    /** @var string[] All defined scopes in display order */
    public const ALL_SCOPES = [
        self::SCOPE_JOBS_READ,
        self::SCOPE_JOBS_WRITE,
        self::SCOPE_JOBS_EXECUTE,
        self::SCOPE_EXPORT_READ,
        self::SCOPE_MAINTENANCE_READ,
        self::SCOPE_MAINTENANCE_WRITE,
        self::SCOPE_SETTINGS_READ,
        self::SCOPE_SETTINGS_WRITE,
    ];

    // -------------------------------------------------------------------------
    // Pre-defined profiles
    // -------------------------------------------------------------------------

    /**
     * Named profiles that map to a fixed set of scopes.
     * Used by the create-key form for quick selection.
     *
     * @var array<string, string[]>
     */
    public const PROFILES = [
        'read-only'  => [
            self::SCOPE_JOBS_READ,
            self::SCOPE_MAINTENANCE_READ,
            self::SCOPE_EXPORT_READ,
        ],
        'operator'   => [
            self::SCOPE_JOBS_READ,
            self::SCOPE_JOBS_EXECUTE,
            self::SCOPE_MAINTENANCE_READ,
        ],
        'developer'  => [
            self::SCOPE_JOBS_READ,
            self::SCOPE_JOBS_WRITE,
            self::SCOPE_JOBS_EXECUTE,
            self::SCOPE_EXPORT_READ,
        ],
        'full-admin' => self::ALL_SCOPES,
    ];

    // -------------------------------------------------------------------------
    // Role → allowed scopes
    // -------------------------------------------------------------------------

    /**
     * Return the set of scopes that a user with the given role may grant to
     * their API keys.  A key can never have more privileges than its creator.
     *
     * @param string $role User role ('view' or 'admin').
     *
     * @return string[]
     */
    public static function allowedScopesForRole(string $role): array
    {
        return match ($role) {
            'admin' => self::ALL_SCOPES,
            default => [
                self::SCOPE_JOBS_READ,
                self::SCOPE_MAINTENANCE_READ,
                self::SCOPE_EXPORT_READ,
                self::SCOPE_SETTINGS_READ,
            ],
        };
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * Filter a raw scope array to only contain valid, known scopes.
     *
     * @param string[] $raw        Unvalidated scope strings from user input.
     * @param string[] $allowed    Scopes the caller is permitted to grant.
     *
     * @return string[] Filtered, deduplicated scope list.
     */
    public static function filterScopes(array $raw, array $allowed): array
    {
        $valid = array_intersect($raw, self::ALL_SCOPES);
        $valid = array_intersect($valid, $allowed);
        return array_values(array_unique($valid));
    }

    // -------------------------------------------------------------------------
    // Display helpers
    // -------------------------------------------------------------------------

    /**
     * Return a human-readable label for a scope string.
     *
     * @param string $scope Scope identifier.
     *
     * @return string
     */
    public static function label(string $scope): string
    {
        return match ($scope) {
            self::SCOPE_JOBS_READ         => 'Jobs lesen',
            self::SCOPE_JOBS_WRITE        => 'Jobs schreiben',
            self::SCOPE_JOBS_EXECUTE      => 'Jobs ausführen',
            self::SCOPE_EXPORT_READ       => 'Export',
            self::SCOPE_MAINTENANCE_READ  => 'Wartungsfenster lesen',
            self::SCOPE_MAINTENANCE_WRITE => 'Wartungsfenster schreiben',
            self::SCOPE_SETTINGS_READ     => 'Einstellungen lesen',
            self::SCOPE_SETTINGS_WRITE    => 'Einstellungen schreiben',
            default                       => $scope,
        };
    }

    /**
     * Return a Tailwind CSS color class for a scope badge.
     *
     * @param string $scope Scope identifier.
     *
     * @return string
     */
    public static function badgeColor(string $scope): string
    {
        return match (true) {
            str_ends_with($scope, ':write') || $scope === self::SCOPE_JOBS_EXECUTE
                => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
            default
                => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
        };
    }
}
