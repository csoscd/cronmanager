#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cronmanager – Execution Log Pruner
 *
 * Runs nightly via the system crontab (installed by simple_debian_setup.sh):
 *
 *   0 2 * * *  root  /usr/bin/php /opt/phpscripts/cronmanager/agent/bin/prune-logs.php
 *
 * Responsibility:
 *   1. Delete finished execution_log rows that exceed their job's configured
 *      retention_days.  Only rows with a non-NULL finished_at are eligible;
 *      currently running executions are never pruned.
 *   2. Remove stale job_retry_state rows whose scheduled once-entry never
 *      fired.  A row is considered stale when it is older than
 *      (retry_delay_minutes + 60) minutes after its scheduled_at timestamp.
 *
 * Exit codes:
 *   0 – completed normally (zero or more records removed)
 *   1 – fatal error (bootstrap or database failure)
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

// ---------------------------------------------------------------------------
// Shared vendor autoloader
// ---------------------------------------------------------------------------

require_once '/opt/phplib/vendor/autoload.php';

// ---------------------------------------------------------------------------
// PSR-4 autoloader for Cronmanager\Agent\* classes
// ---------------------------------------------------------------------------

spl_autoload_register(function (string $class): void {
    $prefix  = 'Cronmanager\\Agent\\';
    $baseDir = dirname(__DIR__) . '/src/';
    if (str_starts_with($class, $prefix)) {
        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use Cronmanager\Agent\Bootstrap;
use Cronmanager\Agent\Database\Connection;
use Cronmanager\Agent\Endpoints\MaintenanceLogsPruneEndpoint;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

try {
    $bootstrap = Bootstrap::getInstance();
    $logger    = $bootstrap->getLogger();
} catch (\Throwable $e) {
    error_log(sprintf('[prune-logs] Bootstrap failed: %s', $e->getMessage()));
    exit(1);
}

$logger->debug('prune-logs: starting');

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------

try {
    $pdo = Connection::getInstance()->getPdo();
} catch (\Throwable $e) {
    $logger->error('prune-logs: database connection failed', ['message' => $e->getMessage()]);
    exit(1);
}

// ---------------------------------------------------------------------------
// Prune via shared endpoint logic
// ---------------------------------------------------------------------------

try {
    $pruner = new MaintenanceLogsPruneEndpoint($pdo, $logger);
    [$deletedLogs, $deletedRetryState] = $pruner->prune();
} catch (\Throwable $e) {
    $logger->error('prune-logs: pruning failed', ['message' => $e->getMessage()]);
    exit(1);
}

$logger->info('prune-logs: completed', [
    'deleted_logs'        => $deletedLogs,
    'deleted_retry_state' => $deletedRetryState,
]);

exit(0);
