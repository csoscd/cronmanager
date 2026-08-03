<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: WebIdentityEndpoint
 *
 * Tests the PUT /settings/web-identity handler against a real MariaDB database.
 * Verifies that valid pushes are persisted to agent_settings and that invalid
 * requests return appropriate 4xx responses.
 *
 * Prerequisites:
 *   docker compose -f tests/docker-compose.test.yml up -d
 *   ./vendor/bin/phpunit --testsuite integration
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Config\DbConfig;
use Cronmanager\Agent\Endpoints\Settings\WebIdentityEndpoint;
use Noodlehaus\Config;
use Noodlehaus\Parser\Json as JsonParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;
use Tests\Support\PhpInputStream;

final class WebIdentityEndpointTest extends AgentEndpointTestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeEndpoint(): WebIdentityEndpoint
    {
        $baseConfig = new Config('{}', new JsonParser(), true);
        $dbConfig   = new DbConfig($baseConfig, $this->pdo);

        return new WebIdentityEndpoint($dbConfig, $this->createNullLogger());
    }

    /**
     * Read a section from agent_settings directly.
     *
     * @return array<string, mixed>|null
     */
    private function readSettingsSection(string $section): ?array
    {
        $stmt = $this->pdo->prepare('SELECT config FROM agent_settings WHERE section = :section');
        $stmt->execute(['section' => $section]);
        $row = $stmt->fetchColumn();

        if ($row === false) {
            return null;
        }

        $decoded = json_decode((string) $row, true);

        return is_array($decoded) ? $decoded : null;
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    #[Test]
    public function validPushStoresWebIdentityInDatabase(): void
    {
        $endpoint = $this->makeEndpoint();
        $this->callHandle($endpoint, [
            'web_agent_id' => 3,
            'web_url'      => 'https://cronmanager.example.com',
        ]);

        $this->assertStatus(200);

        $stored = $this->readSettingsSection('web');
        $this->assertNotNull($stored);
        $this->assertSame(3,                                    $stored['web_agent_id']);
        $this->assertSame('https://cronmanager.example.com',    $stored['web_url']);
    }

    #[Test]
    public function responseContainsOkTrue(): void
    {
        $endpoint = $this->makeEndpoint();
        $this->callHandle($endpoint, [
            'web_agent_id' => 1,
            'web_url'      => 'https://cm.example.com',
        ]);

        $this->assertStatus(200);
        $this->assertBodyHas('ok', true);
    }

    #[Test]
    public function trailingSlashIsStrippedFromUrl(): void
    {
        $endpoint = $this->makeEndpoint();
        $this->callHandle($endpoint, [
            'web_agent_id' => 1,
            'web_url'      => 'https://cronmanager.example.com/',
        ]);

        $this->assertStatus(200);

        $stored = $this->readSettingsSection('web');
        $this->assertSame('https://cronmanager.example.com', $stored['web_url']);
    }

    #[Test]
    public function subsequentPushOverwritesPreviousIdentity(): void
    {
        $endpoint = $this->makeEndpoint();

        $this->callHandle($endpoint, [
            'web_agent_id' => 1,
            'web_url'      => 'https://old.example.com',
        ]);

        $this->callHandle($endpoint, [
            'web_agent_id' => 5,
            'web_url'      => 'https://new.example.com',
        ]);

        $stored = $this->readSettingsSection('web');
        $this->assertSame(5,                           $stored['web_agent_id']);
        $this->assertSame('https://new.example.com',   $stored['web_url']);
    }

    // =========================================================================
    // Validation errors
    // =========================================================================

    #[Test]
    public function missingBodyReturns400(): void
    {
        $endpoint = $this->makeEndpoint();
        $this->callHandle($endpoint, []);

        // Empty JSON object {} is valid JSON but has no required fields → 400
        $this->assertStatus(400);
    }

    #[Test]
    public function missingWebAgentIdReturns400(): void
    {
        $endpoint = $this->makeEndpoint();
        $this->callHandle($endpoint, ['web_url' => 'https://cronmanager.example.com']);

        $this->assertStatus(400);
        $this->assertBodyHasKey('error');
    }

    #[Test]
    public function zeroWebAgentIdReturns400(): void
    {
        $endpoint = $this->makeEndpoint();
        $this->callHandle($endpoint, [
            'web_agent_id' => 0,
            'web_url'      => 'https://cronmanager.example.com',
        ]);

        $this->assertStatus(400);
    }

    #[Test]
    public function negativeWebAgentIdReturns400(): void
    {
        $endpoint = $this->makeEndpoint();
        $this->callHandle($endpoint, [
            'web_agent_id' => -1,
            'web_url'      => 'https://cronmanager.example.com',
        ]);

        $this->assertStatus(400);
    }

    #[Test]
    public function emptyWebUrlReturns400(): void
    {
        $endpoint = $this->makeEndpoint();
        $this->callHandle($endpoint, [
            'web_agent_id' => 1,
            'web_url'      => '',
        ]);

        $this->assertStatus(400);
        $this->assertBodyHasKey('error');
    }

    #[Test]
    public function missingWebUrlReturns400(): void
    {
        $endpoint = $this->makeEndpoint();
        $this->callHandle($endpoint, ['web_agent_id' => 1]);

        $this->assertStatus(400);
    }

    #[Test]
    public function invalidJsonBodyReturns400(): void
    {
        PhpInputStream::set('not-valid-json');

        $baseConfig = new Config('{}', new JsonParser(), true);
        $dbConfig   = new DbConfig($baseConfig, $this->pdo);
        $endpoint   = new WebIdentityEndpoint($dbConfig, $this->createNullLogger());
        $endpoint->handle([]);

        PhpInputStream::restore();

        $this->assertStatus(400);
    }
}
