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

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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
