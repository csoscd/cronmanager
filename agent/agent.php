<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – Entry Point
 *
 * This file is the PHP built-in server router script. It is invoked for every
 * incoming HTTP request when the agent is started via:
 *
 *   php -S 0.0.0.0:8865 /opt/phpscripts/cronmanager/agent/agent.php
 *
 * Responsibilities:
 *   - Bootstrap configuration and logging (Bootstrap singleton)
 *   - Validate HMAC-SHA256 request signatures (HmacValidator)
 *   - Register all API routes (Router)
 *   - Dispatch the request to the matching handler
 *   - Return a top-level 500 JSON response on any unhandled exception
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

require_once '/opt/phplib/vendor/autoload.php';

// PSR-4 autoloader for Cronmanager\Agent\* classes (not in shared vendor)
spl_autoload_register(function (string $class): void {
    $prefix  = 'Cronmanager\\Agent\\';
    $baseDir = __DIR__ . '/src/';
    if (str_starts_with($class, $prefix)) {
        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use Cronmanager\Agent\Bootstrap;
use Cronmanager\Agent\Router;
use Cronmanager\Agent\Security\HmacValidator;

// =============================================================================
// Helper: JSON response
// =============================================================================

/**
 * Emit a JSON HTTP response and terminate the script.
 *
 * @param int   $statusCode HTTP status code.
 * @param array $data       Associative array to encode as JSON.
 *
 * @return void  (exits)
 */
function jsonResponse(int $statusCode, array $data): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// =============================================================================
// Top-level exception boundary
// =============================================================================

try {

    // -------------------------------------------------------------------------
    // Static file pass-through (PHP built-in server convention)
    // -------------------------------------------------------------------------

    // If the requested path maps to a real file on disk, let the built-in
    // server serve it directly (e.g. favicon.ico).
    $rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $rawPath = '/' . ltrim((string) $rawPath, '/');

    if ($rawPath !== '/' && file_exists(__DIR__ . $rawPath)) {
        return false;
    }

    // -------------------------------------------------------------------------
    // Bootstrap: config + logger
    // -------------------------------------------------------------------------

    $bootstrap = Bootstrap::getInstance();
    $config    = $bootstrap->getConfig();
    $logger    = $bootstrap->getLogger();

    // -------------------------------------------------------------------------
    // Request parsing
    // -------------------------------------------------------------------------

    $method    = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path      = $rawPath;
    $rawBody   = (string) file_get_contents('php://input');
    $clientIp  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Retrieve the HMAC signature header (PHP converts hyphens to underscores
    // and prepends HTTP_ for non-standard headers)
    $signatureHeader = $_SERVER['HTTP_X_AGENT_SIGNATURE'] ?? '';

    // -------------------------------------------------------------------------
    // Skip HMAC validation for the /health endpoint
    // Health checks are used by monitoring tools and do not carry a signature.
    // -------------------------------------------------------------------------

    if ($path !== '/health') {
        $hmacSecret = (string) $config->get('agent.hmac_secret', '');

        // Warn loudly if the secret is still set to the shipped example value
        if ($hmacSecret === 'change-me-to-a-secure-random-string') {
            $logger->critical('SECURITY: agent.hmac_secret is set to the default example value. ' .
                'Any party that knows the default can forge valid requests. ' .
                'Generate a new secret immediately: openssl rand -hex 32');
        } elseif (strlen($hmacSecret) < 32) {
            $logger->warning('SECURITY: agent.hmac_secret is shorter than 32 characters. ' .
                'Use at least 32 random bytes for adequate security.');
        }

        try {
            $validator = new HmacValidator($hmacSecret);
        } catch (\InvalidArgumentException $e) {
            $logger->critical('HMAC secret is not configured', ['error' => $e->getMessage()]);
            jsonResponse(500, ['error' => 'Internal Server Error', 'message' => 'Agent misconfigured: HMAC secret missing.', 'code' => 500]);
        }

        if (!$validator->validate($method, $path, $rawBody, $signatureHeader)) {
            $logger->warning('HMAC validation failed – request rejected', [
                'method'    => $method,
                'path'      => $path,
                'client_ip' => $clientIp,
            ]);
            jsonResponse(401, ['error' => 'Unauthorized', 'message' => 'Invalid or missing request signature.', 'code' => 401]);
        }
    }

    // -------------------------------------------------------------------------
    // Log every incoming request at DEBUG level
    // -------------------------------------------------------------------------

    $logger->debug('Incoming request', [
        'method'    => $method,
        'path'      => $path,
        'client_ip' => $clientIp,
    ]);

    // -------------------------------------------------------------------------
    // Router setup
    // -------------------------------------------------------------------------

    $router = new Router();

    // -- Health (fully functional, HMAC-exempt) --------------------------------

    $router->addRoute('GET', '/health', function (array $params) use ($logger): void {
        $logger->info('Health check requested');
        $versionFile = __DIR__ . '/VERSION';
        jsonResponse(200, [
            'status'            => 'ok',
            'version'           => is_readable($versionFile) ? trim((string) file_get_contents($versionFile)) : 'unknown',
            'container_version' => getenv('APP_VERSION') ?: 'unknown',
            'timestamp'         => date('c'),
        ]);
    });

    // -- Crons ----------------------------------------------------------------

    // Initialise shared dependencies for all cron endpoints
    $pdo           = \Cronmanager\Agent\Database\Connection::getInstance()->getPdo();
    $wrapperScript = (string) $config->get('cron.wrapper_script', '/opt/phpscripts/cronmanager/agent/bin/cron-wrapper.sh');
    $crontabManager = new \Cronmanager\Agent\Cron\CrontabManager($logger, $wrapperScript);

    $cronList    = new \Cronmanager\Agent\Endpoints\CronListEndpoint($pdo, $logger, $crontabManager);
    $cronGet     = new \Cronmanager\Agent\Endpoints\CronGetEndpoint($pdo, $logger);
    $cronCreate  = new \Cronmanager\Agent\Endpoints\CronCreateEndpoint($pdo, $logger, $crontabManager, $wrapperScript);
    $cronUpdate  = new \Cronmanager\Agent\Endpoints\CronUpdateEndpoint($pdo, $logger, $crontabManager, $wrapperScript);
    $cronDelete  = new \Cronmanager\Agent\Endpoints\CronDeleteEndpoint($pdo, $logger, $crontabManager);
    $cronMonitor = new \Cronmanager\Agent\Endpoints\MonitorEndpoint($pdo, $logger);

    $cronUnmanaged = new \Cronmanager\Agent\Endpoints\CronUnmanagedEndpoint($logger, $crontabManager);
    $cronUsers     = new \Cronmanager\Agent\Endpoints\CronUsersEndpoint($logger, $crontabManager);

    $router->addRoute('GET',    '/crons',                [$cronList,      'handle']);
    $router->addRoute('GET',    '/crons/users',          [$cronUsers,     'handle']);
    $router->addRoute('GET',    '/crons/unmanaged',      [$cronUnmanaged, 'handle']);
    // /crons/{id}/monitor must be registered before /crons/{id} so the router
    // tries the more specific pattern first and does not mis-route the request.
    $executeNow     = new \Cronmanager\Agent\Endpoints\ExecuteNowEndpoint($pdo, $logger, $crontabManager, $wrapperScript);
    $executeCleanup = new \Cronmanager\Agent\Endpoints\ExecuteCleanupEndpoint($pdo, $logger, $crontabManager);

    $router->addRoute('GET',    '/crons/{id}/monitor',          [$cronMonitor,   'handle']);
    // /crons/{id}/execute/cleanup and /crons/{id}/execute must be registered
    // before /crons/{id} so the more-specific patterns are tried first.
    $router->addRoute('POST',   '/crons/{id}/execute/cleanup',  [$executeCleanup, 'handle']);
    $router->addRoute('POST',   '/crons/{id}/execute',          [$executeNow,     'handle']);
    $router->addRoute('GET',    '/crons/{id}',                  [$cronGet,       'handle']);
    $router->addRoute('POST',   '/crons',                       [$cronCreate,    'handle']);
    $router->addRoute('PUT',    '/crons/{id}',                  [$cronUpdate,    'handle']);
    $router->addRoute('DELETE', '/crons/{id}',                  [$cronDelete,    'handle']);

    // -- Execution lifecycle --------------------------------------------------

    $mailNotifier     = new \Cronmanager\Agent\Notification\MailNotifier($logger, $config);
    $telegramNotifier = new \Cronmanager\Agent\Notification\TelegramNotifier($logger, $config);

    $maintenanceWindowRepo = new \Cronmanager\Agent\Repository\MaintenanceWindowRepository($pdo, $logger);

    $execStart     = new \Cronmanager\Agent\Endpoints\ExecutionStartEndpoint($pdo, $logger, $maintenanceWindowRepo);
    $execFinish    = new \Cronmanager\Agent\Endpoints\ExecutionFinishEndpoint($pdo, $logger, $mailNotifier, $telegramNotifier);
    $execUpdatePid = new \Cronmanager\Agent\Endpoints\ExecutionUpdatePidEndpoint($pdo, $logger);
    $execKill      = new \Cronmanager\Agent\Endpoints\ExecutionKillEndpoint($pdo, $logger);

    $router->addRoute('POST', '/execution/start',       [$execStart,     'handle']);
    $router->addRoute('POST', '/execution/finish',      [$execFinish,    'handle']);
    // /execution/{id}/pid and /execution/{id}/kill – more specific than /execution/start|finish
    $router->addRoute('POST', '/execution/{id}/pid',    [$execUpdatePid, 'handle']);
    $router->addRoute('POST', '/execution/{id}/kill',   [$execKill,      'handle']);

    // -- Tags -----------------------------------------------------------------

    $tagList   = new \Cronmanager\Agent\Endpoints\TagListEndpoint($pdo, $logger);
    $tagCreate = new \Cronmanager\Agent\Endpoints\TagCreateEndpoint($pdo, $logger);
    $tagDelete = new \Cronmanager\Agent\Endpoints\TagDeleteEndpoint($pdo, $logger);

    $router->addRoute('GET',    '/tags',        [$tagList,   'handle']);
    $router->addRoute('POST',   '/tags',        [$tagCreate, 'handle']);
    $router->addRoute('DELETE', '/tags/{id}',   [$tagDelete, 'handle']);

    // -- SSH Hosts ------------------------------------------------------------

    $sshHosts        = new \Cronmanager\Agent\Endpoints\SshHostsEndpoint($logger);
    $importSshTargets = new \Cronmanager\Agent\Endpoints\ImportSshTargetsEndpoint($logger);
    $router->addRoute('GET', '/ssh-hosts',            [$sshHosts,         'handle']);
    $router->addRoute('GET', '/import/ssh-targets',   [$importSshTargets, 'handle']);

    // -- History --------------------------------------------------------------

    $history = new \Cronmanager\Agent\Endpoints\HistoryEndpoint($pdo, $logger);
    $router->addRoute('GET', '/history', [$history, 'handle']);

    $maintenanceCleanup = new \Cronmanager\Agent\Endpoints\MaintenanceHistoryCleanupEndpoint($pdo, $logger);
    $router->addRoute('POST', '/maintenance/history/cleanup', [$maintenanceCleanup, 'handle']);

    // -- Export ---------------------------------------------------------------

    $export = new \Cronmanager\Agent\Endpoints\ExportEndpoint($pdo, $logger);
    $router->addRoute('GET', '/export', [$export, 'handle']);

    // -- Notification test ----------------------------------------------------

    $notificationTest = new \Cronmanager\Agent\Endpoints\NotificationTestEndpoint($mailNotifier, $telegramNotifier, $logger, $config);
    $router->addRoute('POST', '/maintenance/notification/test', [$notificationTest, 'handle']);

    // -- Maintenance ----------------------------------------------------------
    // More-specific paths (/maintenance/crontab/resync, /maintenance/executions/…)
    // must be registered before /maintenance/executions/{id} so the router
    // tries the longer patterns first.

    $maintenanceResync      = new \Cronmanager\Agent\Endpoints\MaintenanceCrontabResyncEndpoint($pdo, $logger, $crontabManager, $wrapperScript);
    $maintenanceStuck       = new \Cronmanager\Agent\Endpoints\MaintenanceStuckEndpoint($pdo, $logger);
    $maintenanceResolve     = new \Cronmanager\Agent\Endpoints\MaintenanceResolveEndpoint($pdo, $logger);
    $maintenanceExecDel     = new \Cronmanager\Agent\Endpoints\MaintenanceDeleteExecutionEndpoint($pdo, $logger);
    $maintenanceOnceCleanup = new \Cronmanager\Agent\Endpoints\MaintenanceOnceCleanupEndpoint($logger, $crontabManager);

    $router->addRoute('POST',   '/maintenance/crontab/resync',          [$maintenanceResync,      'handle']);
    $router->addRoute('POST',   '/maintenance/once/cleanup',            [$maintenanceOnceCleanup, 'handle']);
    $router->addRoute('GET',    '/maintenance/executions/stuck',        [$maintenanceStuck,       'handle']);
    $router->addRoute('POST',   '/maintenance/executions/{id}/finish',  [$maintenanceResolve,     'handle']);
    $router->addRoute('DELETE', '/maintenance/executions/{id}',         [$maintenanceExecDel,     'handle']);

    // -- Maintenance windows --------------------------------------------------
    // /maintenance/windows/conflict must be registered before /maintenance/windows/{id}
    // so the more-specific static segment is tried first.

    $mwList     = new \Cronmanager\Agent\Endpoints\MaintenanceWindowListEndpoint($maintenanceWindowRepo, $logger);
    $mwGet      = new \Cronmanager\Agent\Endpoints\MaintenanceWindowGetEndpoint($maintenanceWindowRepo, $logger);
    $mwCreate   = new \Cronmanager\Agent\Endpoints\MaintenanceWindowCreateEndpoint($maintenanceWindowRepo, $logger);
    $mwUpdate   = new \Cronmanager\Agent\Endpoints\MaintenanceWindowUpdateEndpoint($maintenanceWindowRepo, $logger);
    $mwDelete   = new \Cronmanager\Agent\Endpoints\MaintenanceWindowDeleteEndpoint($maintenanceWindowRepo, $logger);
    $mwConflict = new \Cronmanager\Agent\Endpoints\MaintenanceWindowConflictEndpoint($maintenanceWindowRepo, $logger);

    $router->addRoute('GET',    '/maintenance/windows/conflict', [$mwConflict, 'handle']);
    $router->addRoute('GET',    '/maintenance/windows',          [$mwList,     'handle']);
    $router->addRoute('POST',   '/maintenance/windows',          [$mwCreate,   'handle']);
    $router->addRoute('GET',    '/maintenance/windows/{id}',     [$mwGet,      'handle']);
    $router->addRoute('PUT',    '/maintenance/windows/{id}',     [$mwUpdate,   'handle']);
    $router->addRoute('DELETE', '/maintenance/windows/{id}',     [$mwDelete,   'handle']);

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    $router->dispatch($method, $path);

} catch (\Throwable $e) {
    // Top-level exception guard: log and return a generic 500 so the server
    // never exposes an unhandled PHP error to the network.

    // Attempt to log via the already-initialised logger if available;
    // otherwise fall back to PHP's error_log().
    if (isset($logger)) {
        $logger->error('Unhandled exception in agent', [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);
    } else {
        error_log(sprintf(
            '[cronmanager-agent] Unhandled %s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    jsonResponse(500, [
        'error'   => 'Internal Server Error',
        'message' => 'An unexpected error occurred. Check the agent log for details.',
        'code'    => 500,
    ]);
}
