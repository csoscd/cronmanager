<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Abstract Base Controller
 *
 * Provides shared rendering helpers and lazy-loaded collaborator instances
 * (HostAgentClient, Translator) to all feature controllers that extend it.
 *
 * The render() method captures the sub-template output first via output
 * buffering, then passes the captured HTML as $content to layout.php.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

use Cronmanager\Web\Agent\HostAgentClient;
use Cronmanager\Web\I18n\Translator;
use Cronmanager\Web\Session\SessionManager;
use Monolog\Logger;
use Noodlehaus\Config;

/**
 * Class BaseController
 *
 * Abstract base for all feature controllers.  Concrete controllers only need
 * to implement their action methods – all template rendering and shared
 * dependency access is handled here.
 */
abstract class BaseController
{
    // -------------------------------------------------------------------------
    // Lazy-loaded collaborator cache
    // -------------------------------------------------------------------------

    /** @var HostAgentClient|null Cached agent client instance */
    private ?HostAgentClient $agentClientInstance = null;

    /** @var Translator|null Cached translator instance */
    private ?Translator $translatorInstance = null;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param Config $config Noodlehaus configuration instance.
     * @param Logger $logger Monolog logger.
     */
    public function __construct(
        protected readonly Config $config,
        protected readonly Logger $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Protected helpers
    // -------------------------------------------------------------------------

    /**
     * Render a page template inside the shared layout.
     *
     * Execution order:
     *   1. ob_start() – capture the sub-template
     *   2. extract($data) – make variables available to the template
     *   3. require templates/$template – execute the template
     *   4. $content = ob_get_clean() – collect captured HTML
     *   5. require templates/layout.php – wrap in the chrome
     *
     * @param string               $template    Relative path under templates/ (e.g. 'dashboard.php').
     * @param string               $title       Page title shown in <title> and layout header.
     * @param array<string,mixed>  $data        Variables extracted into the template scope.
     * @param string               $currentPath Current request path for nav highlighting.
     *
     * @return void
     */
    protected function render(
        string $template,
        string $title,
        array  $data = [],
        string $currentPath = '/',
    ): void {
        $templateFile = $this->templatePath($template);

        if (!file_exists($templateFile)) {
            $this->logger->error('Template not found', ['path' => $templateFile]);
            http_response_code(500);
            echo '<h1>500 – Template not found</h1>';
            return;
        }

        $translator = $this->translator();

        // Always inject the CSRF token so every template has access to it
        // without needing to call SessionManager directly.
        $data['csrf_token'] = SessionManager::getCsrfToken();

        // Inject version strings for the footer.
        // App version is read from the VERSION file deployed alongside the web
        // source (works for both direct deployments and Docker).
        // Container version is read from the APP_VERSION environment variable
        // baked into the Docker image at build time (unknown outside Docker).
        // Agent version is fetched from the agent's /health endpoint and cached
        // in the session for 5 minutes to avoid an extra HTTP call on every page.
        $versionFile        = dirname(__DIR__, 2) . '/VERSION';
        $data['webVersion'] = is_readable($versionFile)
            ? trim((string) file_get_contents($versionFile))
            : 'unknown';
        $data['webContainerVersion']   = getenv('APP_VERSION') ?: 'unknown';
        [$data['agentVersion'], $data['agentContainerVersion']] = $this->resolveAgentVersion();

        // ------------------------------------------------------------------
        // Step 1-4: capture the sub-template output
        // ------------------------------------------------------------------
        ob_start();

        // Extract data variables into local scope (EXTR_SKIP avoids overwriting
        // any variables already set, such as $template, $title, $translator)
        extract($data, EXTR_SKIP);

        require $templateFile;

        $content = ob_get_clean();

        // ------------------------------------------------------------------
        // Step 5: render layout with $content
        // ------------------------------------------------------------------
        $layoutFile = $this->templatePath('layout.php');

        if (!file_exists($layoutFile)) {
            $this->logger->error('Layout template not found', ['path' => $layoutFile]);
            echo $content;
            return;
        }

        require $layoutFile;
    }

    /**
     * Return a shared HostAgentClient instance (created on first call).
     *
     * @return HostAgentClient
     */
    protected function agentClient(): HostAgentClient
    {
        if ($this->agentClientInstance === null) {
            $this->agentClientInstance = new HostAgentClient($this->config, $this->logger);
        }

        return $this->agentClientInstance;
    }

    /**
     * Return a Translator instance (created on first call).
     *
     * @return Translator
     */
    protected function translator(): Translator
    {
        if ($this->translatorInstance === null) {
            $this->translatorInstance = new Translator($this->config);
        }

        return $this->translatorInstance;
    }

    /**
     * Render an error page inside the standard layout.
     *
     * @param int    $code        HTTP status code to set (e.g. 500).
     * @param string $messageKey  Translation key for the user-facing error message.
     * @param string $currentPath Current request path for nav highlighting.
     *
     * @return void
     */
    protected function renderError(int $code, string $messageKey, string $currentPath = '/'): void
    {
        http_response_code($code);

        $translator = $this->translator();
        $message    = $translator->t($messageKey);
        $title      = (string) $code;

        $this->render('error.php', $title, [
            'errorCode'    => $code,
            'errorMessage' => $message,
        ], $currentPath);
    }

    /**
     * Resolve a filter parameter from GET, falling back to a persistent cookie.
     *
     * Resolution order:
     *   1. If ?_reset is present in the request, expire the cookie and return $default.
     *   2. If the named GET key is present, use its value (even empty) and save to cookie.
     *   3. Otherwise fall back to the stored cookie value, or $default when absent.
     *
     * setcookie() silently fails when the browser blocks cookies, degrading
     * gracefully to the previous stateless behaviour.
     *
     * @param string $get     Name of the GET query parameter.
     * @param string $cookie  Cookie name to read from / write to.
     * @param string $default Value returned when absent from both GET and cookie.
     *
     * @return string Resolved, trimmed filter value.
     */
    protected function filterParam(string $get, string $cookie, string $default = ''): string
    {
        // User clicked "reset filters" – clear the cookie and return the default.
        if (isset($_GET['_reset'])) {
            setcookie($cookie, '', [
                'expires'  => time() - 1,
                'path'     => '/',
                'samesite' => 'Lax',
                'httponly' => true,
            ]);
            return $default;
        }

        // Explicit GET value (including empty string) takes precedence over cookie.
        if (array_key_exists($get, $_GET)) {
            $value = trim((string) $_GET[$get]);
        } else {
            $value = isset($_COOKIE[$cookie]) ? (string) $_COOKIE[$cookie] : $default;
        }

        // Persist the resolved value for the next page load.
        setcookie($cookie, $value, [
            'expires'  => time() + 30 * 24 * 3600,
            'path'     => '/',
            'samesite' => 'Lax',
            'httponly' => true,
        ]);

        return $value;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the agent version strings (app version + container version).
     *
     * The values are fetched once from the agent's /health endpoint and cached
     * in the session for 5 minutes to avoid a redundant HTTP call on every page.
     *
     * Returns ['unknown', 'unknown'] when the agent is unreachable.
     *
     * @return array{0: string, 1: string} [appVersion, containerVersion]
     */
    private function resolveAgentVersion(): array
    {
        $cacheKey = '_cm_agent_version';
        $ttlKey   = '_cm_agent_version_ts';
        $ttl      = 300; // 5 minutes

        // Return cached value if it is still fresh
        if (
            isset($_SESSION[$cacheKey], $_SESSION[$ttlKey])
            && (time() - (int) $_SESSION[$ttlKey]) < $ttl
        ) {
            return (array) $_SESSION[$cacheKey];
        }

        // Fetch from the agent /health endpoint
        try {
            $health           = $this->agentClient()->get('/health');
            $appVersion       = isset($health['version'])           ? (string) $health['version']           : 'unknown';
            $containerVersion = isset($health['container_version']) ? (string) $health['container_version'] : 'unknown';
        } catch (\Throwable) {
            $appVersion       = 'unknown';
            $containerVersion = 'unknown';
        }

        $result = [$appVersion, $containerVersion];

        $_SESSION[$cacheKey] = $result;
        $_SESSION[$ttlKey]   = time();

        return $result;
    }

    /**
     * Build an absolute path to a template file.
     *
     * @param string $relative Relative path under templates/ (e.g. 'cron/list.php').
     *
     * @return string Absolute file-system path.
     */
    private function templatePath(string $relative): string
    {
        // __DIR__ is web/src/Controller – go up two levels to reach web root
        return dirname(__DIR__, 2) . '/templates/' . $relative;
    }
}
