<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Export Controller
 *
 * Provides a form-based export interface.  The download action streams the
 * agent's response directly to the browser without buffering so that large
 * exports do not consume excessive memory.
 *
 * Routes handled:
 *   GET /export          – show export options form
 *   GET /export/download – trigger the file download
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

/**
 * Class ExportController
 *
 * The download() action bypasses the normal render() pipeline because it must
 * stream raw content (plain-text crontab or JSON) with appropriate download
 * headers instead of wrapping the response in the HTML layout.
 */
class ExportController extends BaseController
{
    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Display the export options form.
     *
     * Fetches tags and unique linux users from the agent so the form can offer
     * pre-populated filter dropdowns.
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function index(array $params): void
    {
        $agent = $this->agentClient();

        try {
            $tags    = $agent->get('/tags')['data']  ?? [];
            $allJobs = $agent->get('/crons')['data'] ?? [];
        } catch (\RuntimeException $e) {
            $this->logger->error('ExportController::index: agent request failed', [
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/export');
            return;
        }

        // Build unique user list from all jobs
        $users = [];
        foreach ($allJobs as $job) {
            $u = (string) ($job['linux_user'] ?? '');
            if ($u !== '' && !in_array($u, $users, strict: true)) {
                $users[] = $u;
            }
        }
        sort($users);

        $this->render('export.php', $this->translator()->t('export_title'), [
            'tags'  => $tags,
            'users' => $users,
        ], '/export');
    }

    /**
     * Stream the export file download directly from the agent to the browser.
     *
     * Supported GET parameters:
     *   ?user=   – filter by linux_user (optional)
     *   ?tag=    – filter by tag name (optional)
     *   ?format= – 'crontab' (default) or 'json'
     *
     * The response is NOT buffered.  Headers are sent before any content is
     * streamed, and ob_end_clean() flushes any previously started output buffer
     * to ensure a clean streaming context.
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function download(array $params): void
    {
        $user   = trim((string) ($_GET['user']   ?? ''));
        $tag    = trim((string) ($_GET['tag']    ?? ''));
        $format = trim((string) ($_GET['format'] ?? 'crontab'));

        // Validate format
        if (!in_array($format, ['crontab', 'json'], strict: true)) {
            $format = 'crontab';
        }

        // Build query parameters for the agent
        $query = ['format' => $format];
        if ($user !== '') { $query['user'] = $user; }
        if ($tag  !== '') { $query['tag']  = $tag; }

        // ------------------------------------------------------------------
        // Call the agent /export endpoint
        // ------------------------------------------------------------------
        try {
            // We use the raw Guzzle client here to access the response body
            // as a stream rather than a decoded JSON array.
            $agentUrl    = rtrim((string) $this->config->get('agent.url', 'http://host.docker.internal:8865'), '/');
            $timeout     = (int) $this->config->get('agent.timeout', 10);
            $secret      = (string) $this->config->get('agent.hmac_secret', '');
            $path        = '/export';
            $queryString = '?' . http_build_query($query);
            $signature   = hash_hmac('sha256', 'GET' . $path . '', $secret);

            $guzzle   = new \GuzzleHttp\Client(['timeout' => $timeout, 'http_errors' => false]);
            $response = $guzzle->request('GET', $agentUrl . $path . $queryString, [
                'headers' => [
                    'X-Agent-Signature' => $signature,
                    'Accept'            => '*/*',
                ],
                'stream' => true,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                $this->logger->warning('ExportController::download: agent error', [
                    'status' => $status,
                ]);
                $this->renderError(503, 'error_agent_unavailable', '/export');
                return;
            }
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $this->logger->error('ExportController::download: connection failed', [
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/export');
            return;
        }

        // ------------------------------------------------------------------
        // Stream the response to the browser
        // ------------------------------------------------------------------
        // Flush any existing output buffer so nothing is sent before headers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $filename    = 'cronmanager-export-' . date('Y-m-d');
        $contentType = $format === 'json' ? 'application/json' : 'text/plain';
        $extension   = $format === 'json' ? 'json' : 'txt';

        header('Content-Type: ' . $contentType . '; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.' . $extension . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $body = $response->getBody();

        // Stream in 8 KB chunks to keep memory usage low
        while (!$body->eof()) {
            echo $body->read(8192);
            // Flush to the client if output buffering is not active
            if (connection_status() !== CONNECTION_NORMAL) {
                break;
            }
        }
    }
}
