#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cronmanager – Audit Log Pruner
 *
 * Deletes audit_log rows older than the configured retention period.
 *
 * Runs nightly via crontab, e.g.:
 *
 *   15 2 * * *  root  /usr/bin/php /opt/phpscripts/cronmanager/agent/bin/prune-audit.php
 *
 * Retention is controlled by config key:
 *   audit_log.retention_days  (default: 90)
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

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

try {
    $bootstrap = Bootstrap::getInstance();
    $config    = $bootstrap->getConfig();
    $logger    = $bootstrap->getLogger();
} catch (\Throwable $e) {
    error_log(sprintf('[prune-audit] Bootstrap failed: %s', $e->getMessage()));
    exit(1);
}

$logger->debug('prune-audit: starting');

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------

try {
    $pdo = Connection::getInstance()->getPdo();
} catch (\Throwable $e) {
    $logger->error('prune-audit: database connection failed', ['message' => $e->getMessage()]);
    exit(1);
}

// ---------------------------------------------------------------------------
// Prune
// ---------------------------------------------------------------------------

$retentionDays = max(1, (int) $config->get('audit_log.retention_days', 90));

try {
    $auditLogger = new \Cronmanager\Agent\Audit\AuditLogger($pdo, $logger, 0, 'system', 'cli');
    $deleted     = $auditLogger->prune($retentionDays);
} catch (\Throwable $e) {
    $logger->error('prune-audit: pruning failed', ['message' => $e->getMessage()]);
    exit(1);
}

$logger->info('prune-audit: completed', [
    'deleted'        => $deleted,
    'retention_days' => $retentionDays,
]);

exit(0);
