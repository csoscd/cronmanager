<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: Version and documentation consistency
 *
 * Verifies that the release-critical files (VERSION, CHANGELOG.md, README.md,
 * API.md) are consistent with each other.  These tests act as a gate that
 * prevents a release where the version string is updated in one place but
 * forgotten in another.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Web;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class VersionConsistencyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        // Walk up from tests/Integration/Web to the project root
        $this->root = dirname(__DIR__, 3);
    }

    // -------------------------------------------------------------------------
    // 1. VERSION files exist and are non-empty
    // -------------------------------------------------------------------------

    #[Test]
    public function webVersionFileExists(): void
    {
        $path = $this->root . '/web/VERSION';
        $this->assertFileExists($path, 'web/VERSION must exist');
        $this->assertNotEmpty(trim((string) file_get_contents($path)), 'web/VERSION must not be empty');
    }

    #[Test]
    public function agentVersionFileExists(): void
    {
        $path = $this->root . '/agent/VERSION';
        $this->assertFileExists($path, 'agent/VERSION must exist');
        $this->assertNotEmpty(trim((string) file_get_contents($path)), 'agent/VERSION must not be empty');
    }

    // -------------------------------------------------------------------------
    // 2. Web and agent VERSION files are in sync
    // -------------------------------------------------------------------------

    #[Test]
    public function webAndAgentVersionFilesMatch(): void
    {
        $webVersion   = trim((string) file_get_contents($this->root . '/web/VERSION'));
        $agentVersion = trim((string) file_get_contents($this->root . '/agent/VERSION'));

        $this->assertSame(
            $webVersion,
            $agentVersion,
            'web/VERSION and agent/VERSION must contain the same version string'
        );
    }

    // -------------------------------------------------------------------------
    // 3. CHANGELOG.md contains a section for the current version
    // -------------------------------------------------------------------------

    #[Test]
    public function changelogContainsCurrentVersionSection(): void
    {
        $version   = trim((string) file_get_contents($this->root . '/web/VERSION'));
        $changelog = (string) file_get_contents($this->root . '/CHANGELOG.md');

        $this->assertStringContainsString(
            '[' . $version . ']',
            $changelog,
            "CHANGELOG.md must contain a section for version $version"
        );
    }

    // -------------------------------------------------------------------------
    // 4. README.md references the current version
    // -------------------------------------------------------------------------

    #[Test]
    public function readmeReferencesCurrentVersion(): void
    {
        $version = trim((string) file_get_contents($this->root . '/web/VERSION'));
        $readme  = (string) file_get_contents($this->root . '/README.md');

        $this->assertStringContainsString(
            $version,
            $readme,
            "README.md must reference version $version"
        );
    }

    // -------------------------------------------------------------------------
    // 5. API.md contains a changelog entry for the current version
    // -------------------------------------------------------------------------

    #[Test]
    public function apiMdContainsCurrentVersionEntry(): void
    {
        $version = trim((string) file_get_contents($this->root . '/web/VERSION'));
        $apiMd   = (string) file_get_contents($this->root . '/API.md');

        $this->assertStringContainsString(
            $version,
            $apiMd,
            "API.md must contain an entry for version $version"
        );
    }

    // -------------------------------------------------------------------------
    // 6. CHANGELOG.md is non-trivially long (guards against accidental truncation)
    // -------------------------------------------------------------------------

    #[Test]
    public function changelogIsNonTriviallyLong(): void
    {
        $changelog = (string) file_get_contents($this->root . '/CHANGELOG.md');

        $this->assertGreaterThan(
            500,
            strlen($changelog),
            'CHANGELOG.md appears to have been accidentally truncated (< 500 bytes)'
        );
    }

    // -------------------------------------------------------------------------
    // 7. Version string follows semantic versioning (X.Y.Z)
    // -------------------------------------------------------------------------

    #[Test]
    public function versionStringFollowsSemanticVersioning(): void
    {
        $version = trim((string) file_get_contents($this->root . '/web/VERSION'));

        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $version,
            "web/VERSION must follow semantic versioning (X.Y.Z), got: $version"
        );
    }

    // -------------------------------------------------------------------------
    // 8. CHANGELOG.md exists and is readable
    // -------------------------------------------------------------------------

    #[Test]
    public function changelogFileExists(): void
    {
        $this->assertFileExists($this->root . '/CHANGELOG.md', 'CHANGELOG.md must exist');
    }

    // -------------------------------------------------------------------------
    // 9. README.md exists and is readable
    // -------------------------------------------------------------------------

    #[Test]
    public function readmeFileExists(): void
    {
        $this->assertFileExists($this->root . '/README.md', 'README.md must exist');
    }

    // -------------------------------------------------------------------------
    // 10. API.md exists and is readable
    // -------------------------------------------------------------------------

    #[Test]
    public function apiMdFileExists(): void
    {
        $this->assertFileExists($this->root . '/API.md', 'API.md must exist');
    }

    // -------------------------------------------------------------------------
    // 11. CHANGELOG.md mentions the branch
    // -------------------------------------------------------------------------

    #[Test]
    public function changelogMentionsBranch(): void
    {
        $changelog = (string) file_get_contents($this->root . '/CHANGELOG.md');
        $this->assertStringContainsString('feature/v', $changelog, 'CHANGELOG.md must reference the feature branch');
    }

    // -------------------------------------------------------------------------
    // 12. README.md contains a features table
    // -------------------------------------------------------------------------

    #[Test]
    public function readmeContainsFeaturesTable(): void
    {
        $readme = (string) file_get_contents($this->root . '/README.md');
        $this->assertStringContainsString('## Features', $readme, 'README.md must contain a Features section');
    }

    // -------------------------------------------------------------------------
    // 13. API.md contains authentication section
    // -------------------------------------------------------------------------

    #[Test]
    public function apiMdContainsAuthSection(): void
    {
        $apiMd = (string) file_get_contents($this->root . '/API.md');
        $this->assertStringContainsString('Authentication', $apiMd, 'API.md must contain an Authentication section');
    }

    // -------------------------------------------------------------------------
    // 14. web/VERSION major component is numeric
    // -------------------------------------------------------------------------

    #[Test]
    public function webVersionMajorIsNumeric(): void
    {
        $version = trim((string) file_get_contents($this->root . '/web/VERSION'));
        [$major] = explode('.', $version);
        $this->assertIsNumeric($major, "Major version component must be numeric, got: $major");
    }

    // -------------------------------------------------------------------------
    // 15. Both VERSION files contain the same major version
    // -------------------------------------------------------------------------

    #[Test]
    public function webAndAgentVersionShareSameMajor(): void
    {
        $webVersion   = trim((string) file_get_contents($this->root . '/web/VERSION'));
        $agentVersion = trim((string) file_get_contents($this->root . '/agent/VERSION'));
        [$webMajor]   = explode('.', $webVersion);
        [$agentMajor] = explode('.', $agentVersion);
        $this->assertSame($webMajor, $agentMajor, 'Major version must match between web and agent VERSION files');
    }
}
