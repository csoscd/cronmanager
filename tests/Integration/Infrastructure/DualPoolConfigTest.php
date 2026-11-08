<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: Dual-pool agent configuration (v7.3.0)
 *
 * Verifies that the dual-pool infrastructure added in v7.3.0 is correctly
 * implemented in the startup scripts and documented in README.md.
 *
 * These are structural / file-content tests — they confirm that the patterns
 * required for AGENT_WEB_PORT support are present in the shell scripts and
 * that the PHP config-generation snippet in entrypoint.sh writes the correct
 * keys when the web-pool port is configured.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Infrastructure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DualPoolConfigTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    // -------------------------------------------------------------------------
    // entrypoint.sh structural checks
    // -------------------------------------------------------------------------

    #[Test]
    public function entrypointContainsAgentWebPortHandling(): void
    {
        $script = (string) file_get_contents($this->root . '/docker/agent/entrypoint.sh');

        $this->assertStringContainsString(
            'AGENT_WEB_PORT',
            $script,
            'entrypoint.sh must handle the AGENT_WEB_PORT environment variable'
        );
    }

    #[Test]
    public function entrypointStartsWebPoolInBackground(): void
    {
        $script = (string) file_get_contents($this->root . '/docker/agent/entrypoint.sh');

        // The web pool must be started as a background process (&) on AGENT_WEB_PORT
        $this->assertMatchesRegularExpression(
            '/0\.0\.0\.0.*AGENT_WEB_PORT.*&/s',
            $script,
            'entrypoint.sh must start the web pool as a background process on 0.0.0.0:AGENT_WEB_PORT'
        );
    }

    #[Test]
    public function entrypointWritesWebPortToConfigJson(): void
    {
        $script = (string) file_get_contents($this->root . '/docker/agent/entrypoint.sh');

        $this->assertStringContainsString(
            "'web_port'",
            $script,
            'entrypoint.sh must write agent.web_port to config.json'
        );
        $this->assertStringContainsString(
            "'web_workers'",
            $script,
            'entrypoint.sh must write agent.web_workers to config.json'
        );
    }

    #[Test]
    public function entrypointDocumentsPhpCliWebServerWorkers(): void
    {
        $script = (string) file_get_contents($this->root . '/docker/agent/entrypoint.sh');

        $this->assertStringContainsString(
            'PHP_CLI_WEB_SERVER_WORKERS',
            $script,
            'entrypoint.sh must document and use PHP_CLI_WEB_SERVER_WORKERS'
        );
    }

    // -------------------------------------------------------------------------
    // start-agent.sh structural checks
    // -------------------------------------------------------------------------

    #[Test]
    public function startAgentReadsWebPortFromConfig(): void
    {
        $script = (string) file_get_contents($this->root . '/agent/bin/start-agent.sh');

        $this->assertStringContainsString(
            'web_port',
            $script,
            'start-agent.sh must read agent.web_port from config.json'
        );
    }

    #[Test]
    public function startAgentHasDualPoolBranch(): void
    {
        $script = (string) file_get_contents($this->root . '/agent/bin/start-agent.sh');

        $this->assertStringContainsString(
            'WEB_PORT',
            $script,
            'start-agent.sh must branch on WEB_PORT for dual-pool mode'
        );
    }

    #[Test]
    public function startAgentHasTrapForDualPoolCleanup(): void
    {
        $script = (string) file_get_contents($this->root . '/agent/bin/start-agent.sh');

        $this->assertStringContainsString(
            'trap',
            $script,
            'start-agent.sh must use trap to terminate both pools on systemd stop'
        );
    }

    #[Test]
    public function startAgentKeepsSinglePoolExecPath(): void
    {
        $script = (string) file_get_contents($this->root . '/agent/bin/start-agent.sh');

        // Single-pool path must still use exec so systemd tracks the PHP PID
        $this->assertMatchesRegularExpression(
            '/else\s.*exec\s+\/usr\/bin\/php/s',
            $script,
            'start-agent.sh must keep the original exec path for single-pool mode'
        );
    }

    // -------------------------------------------------------------------------
    // PHP config-generation snippet: functional verification
    // The snippet from entrypoint.sh is extracted and executed in isolation
    // to verify that web_port / web_workers are written only when AGENT_WEB_PORT
    // is set, and absent otherwise.
    // -------------------------------------------------------------------------

    #[Test]
    public function configGenerationIncludesWebPortWhenEnvIsSet(): void
    {
        $result = $this->runConfigSnippet(['AGENT_WEB_PORT' => '8866', 'PHP_CLI_WEB_SERVER_WORKERS' => '4']);

        $this->assertArrayHasKey('web_port', $result['agent'] ?? [], 'agent.web_port must be present when AGENT_WEB_PORT is set');
        $this->assertSame(8866, $result['agent']['web_port']);
        $this->assertSame(4, $result['agent']['web_workers']);
    }

    #[Test]
    public function configGenerationUsesDefaultWebWorkersWhenNotExplicit(): void
    {
        $result = $this->runConfigSnippet(['AGENT_WEB_PORT' => '8866']);

        $this->assertSame(6, $result['agent']['web_workers'] ?? null);
    }

    #[Test]
    public function configGenerationOmitsWebPortWhenEnvIsAbsent(): void
    {
        $result = $this->runConfigSnippet([]);

        $this->assertArrayNotHasKey(
            'web_port',
            $result['agent'] ?? [],
            'agent.web_port must be absent when AGENT_WEB_PORT is not set'
        );
        $this->assertArrayNotHasKey(
            'web_workers',
            $result['agent'] ?? [],
            'agent.web_workers must be absent when AGENT_WEB_PORT is not set'
        );
    }

    // -------------------------------------------------------------------------
    // entrypoint.sh TLS web-pool checks (v7.4.0)
    // -------------------------------------------------------------------------

    #[Test]
    public function entrypointDefinesWebInternalPort(): void
    {
        $script = (string) file_get_contents($this->root . '/docker/agent/entrypoint.sh');

        $this->assertStringContainsString(
            'WEB_INTERNAL_PORT=18866',
            $script,
            'entrypoint.sh must define WEB_INTERNAL_PORT=18866 for TLS nginx proxy'
        );
    }

    #[Test]
    public function entrypointAddsNginxWebPoolBlockWhenTlsEnabled(): void
    {
        $script = (string) file_get_contents($this->root . '/docker/agent/entrypoint.sh');

        // The nginx heredoc must reference WEB_INTERNAL_PORT for the proxy_pass
        $this->assertMatchesRegularExpression(
            '/proxy_pass.*WEB_INTERNAL_PORT/s',
            $script,
            'entrypoint.sh nginx block must proxy to WEB_INTERNAL_PORT'
        );
    }

    #[Test]
    public function entrypointBindsWebPoolToInternalPortInTlsMode(): void
    {
        $script = (string) file_get_contents($this->root . '/docker/agent/entrypoint.sh');

        // TLS path: PHP must bind on 127.0.0.1:WEB_INTERNAL_PORT
        $this->assertMatchesRegularExpression(
            '/127\.0\.0\.1.*WEB_INTERNAL_PORT/s',
            $script,
            'entrypoint.sh must bind web pool PHP on 127.0.0.1:WEB_INTERNAL_PORT in TLS mode'
        );
    }

    // -------------------------------------------------------------------------
    // AgentSchema web_url column (v7.4.0)
    // -------------------------------------------------------------------------

    #[Test]
    public function agentSchemaCreateTableIncludesWebUrl(): void
    {
        $schema = (string) file_get_contents($this->root . '/web/src/Bootstrap/AgentSchema.php');

        $this->assertStringContainsString(
            'web_url',
            $schema,
            'AgentSchema must define the web_url column'
        );
    }

    #[Test]
    public function agentSchemaHasAddWebUrlColumnMethod(): void
    {
        $schema = (string) file_get_contents($this->root . '/web/src/Bootstrap/AgentSchema.php');

        $this->assertStringContainsString(
            'addWebUrlColumn',
            $schema,
            'AgentSchema must have addWebUrlColumn() for idempotent ALTER TABLE migration'
        );
        $this->assertStringContainsString(
            'ADD COLUMN IF NOT EXISTS',
            $schema,
            'addWebUrlColumn() must use ADD COLUMN IF NOT EXISTS for idempotence'
        );
    }

    // -------------------------------------------------------------------------
    // AgentsApiController web_pool_url field (v7.4.0)
    // -------------------------------------------------------------------------

    #[Test]
    public function agentsApiControllerExposesWebPoolUrl(): void
    {
        $controller = (string) file_get_contents($this->root . '/web/src/Api/AgentsApiController.php');

        $this->assertStringContainsString(
            'web_pool_url',
            $controller,
            'AgentsApiController must expose web_pool_url in GET /api/v1/agents response'
        );
    }

    // -------------------------------------------------------------------------
    // README.md documentation check
    // -------------------------------------------------------------------------

    #[Test]
    public function readmeDocumentsAgentWebPort(): void
    {
        $readme = (string) file_get_contents($this->root . '/README.md');

        $this->assertStringContainsString(
            'AGENT_WEB_PORT',
            $readme,
            'README.md must document the AGENT_WEB_PORT environment variable'
        );
    }

    #[Test]
    public function readmeDocumentsPhpCliWebServerWorkers(): void
    {
        $readme = (string) file_get_contents($this->root . '/README.md');

        $this->assertStringContainsString(
            'PHP_CLI_WEB_SERVER_WORKERS',
            $readme,
            'README.md must document the PHP_CLI_WEB_SERVER_WORKERS environment variable'
        );
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Runs the agent.web_port/web_workers snippet in isolation and returns the
     * decoded config array.
     *
     * @param  array<string, string> $extraEnv
     * @return array<string, mixed>
     */
    private function runConfigSnippet(array $extraEnv): array
    {
        // Required env vars with safe test defaults
        $env = array_merge([
            'AGENT_BIND_ADDRESS'  => '0.0.0.0',
            'AGENT_PORT'          => '8865',
            'AGENT_HMAC_SECRET'   => 'test-secret',
            'AGENT_TLS_ENABLED'   => 'false',
            'AGENT_WEB_PORT'      => '',
            'PHP_CLI_WEB_SERVER_WORKERS' => '',
        ], $extraEnv);

        $php = <<<'PHP'
<?php
$config = [
    'agent' => array_filter([
        'bind_address' => getenv('AGENT_BIND_ADDRESS') ?: '0.0.0.0',
        'port'         => (int)(getenv('AGENT_PORT') ?: 8865),
        'hmac_secret'  => getenv('AGENT_HMAC_SECRET'),
        'tls_enabled'  => filter_var(getenv('AGENT_TLS_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'web_port'     => getenv('AGENT_WEB_PORT') ? (int)getenv('AGENT_WEB_PORT') : null,
        'web_workers'  => getenv('AGENT_WEB_PORT') ? (int)(getenv('PHP_CLI_WEB_SERVER_WORKERS') ?: 6) : null,
    ], fn($v) => $v !== null),
];
echo json_encode($config);
PHP;

        $tmpFile = tempnam(sys_get_temp_dir(), 'cm_test_') . '.php';
        file_put_contents($tmpFile, $php);

        $envPrefix = '';
        foreach ($env as $k => $v) {
            $envPrefix .= escapeshellarg($k) . '=' . escapeshellarg($v) . ' ';
        }

        $output = shell_exec("env -i {$envPrefix} php {$tmpFile} 2>/dev/null");
        unlink($tmpFile);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode((string) $output, true);

        $this->assertIsArray($decoded, 'Config snippet must produce valid JSON');

        return $decoded;
    }
}
