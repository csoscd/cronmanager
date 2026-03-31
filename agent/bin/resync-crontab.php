#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cronmanager – Crontab Resync CLI
 *
 * Rebuilds all crontab entries from the database.  Intended to be called by
 * the Docker agent entrypoint on every container start so that crontab entries
 * are never lost after a container recreation.
 *
 * Logic mirrors MaintenanceCrontabResyncEndpoint:
 *   • Active jobs   → syncEntries()  (remove stale lines, write fresh ones)
 *   • Inactive jobs → removeAllEntries() (purge any lingering lines)
 *
 * Exit codes:
 *   0 – completed normally (zero or more jobs processed)
 *   1 – fatal error (bootstrap or database failure)
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

// ---------------------------------------------------------------------------
// Shared vendor autoloader (same path used by agent.php)
// ---------------------------------------------------------------------------

require_once '/opt/phplib/vendor/autoload.php';

// ---------------------------------------------------------------------------
// PSR-4 autoloader for Cronmanager\Agent\* classes
// dirname(__DIR__) resolves to agent/ from agent/bin/
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
use Cronmanager\Agent\Cron\CrontabManager;
use Cronmanager\Agent\Database\Connection;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

try {
    $bootstrap = Bootstrap::getInstance();
    $logger    = $bootstrap->getLogger();
    $config    = $bootstrap->getConfig();
} catch (\Throwable $e) {
    error_log(sprintf('[resync-crontab] Bootstrap failed: %s', $e->getMessage()));
    exit(1);
}

$logger->info('resync-crontab: starting crontab resync');

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------

try {
    $pdo = Connection::getInstance()->getPdo();
} catch (\Throwable $e) {
    $logger->error('resync-crontab: database connection failed', ['message' => $e->getMessage()]);
    exit(1);
}

// ---------------------------------------------------------------------------
// Resync
// ---------------------------------------------------------------------------

$wrapperScript = (string) $config->get(
    'cron.wrapper_script',
    '/opt/cronmanager/agent/bin/cron-wrapper.sh'
);

$crontabManager = new CrontabManager($logger, $wrapperScript);

try {
    $stmt = $pdo->query('SELECT id, linux_user, schedule, active FROM cronjobs ORDER BY id');
    $jobs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $logger->error('resync-crontab: failed to fetch jobs', ['message' => $e->getMessage()]);
    exit(1);
}

$synced  = 0;
$removed = 0;
$errors  = [];

foreach ($jobs as $job) {
    $jobId     = (int)    $job['id'];
    $linuxUser = (string) $job['linux_user'];
    $schedule  = (string) $job['schedule'];
    $active    = (bool)   $job['active'];

    try {
        if ($active) {
            // Fetch configured targets; fall back to 'local' for legacy jobs
            $tStmt = $pdo->prepare(
                'SELECT target FROM job_targets WHERE job_id = :id ORDER BY target'
            );
            $tStmt->execute([':id' => $jobId]);
            $targets = $tStmt->fetchAll(\PDO::FETCH_COLUMN) ?: ['local'];

            $crontabManager->syncEntries($linuxUser, $jobId, $schedule, $wrapperScript, $targets);
            $synced++;

            $logger->debug('resync-crontab: synced active job', [
                'job_id'     => $jobId,
                'linux_user' => $linuxUser,
                'targets'    => $targets,
            ]);
        } else {
            $crontabManager->removeAllEntries($linuxUser, $jobId);
            $removed++;

            $logger->debug('resync-crontab: removed inactive job entries', [
                'job_id'     => $jobId,
                'linux_user' => $linuxUser,
            ]);
        }
    } catch (\Throwable $e) {
        $msg      = sprintf('Job #%d (%s): %s', $jobId, $linuxUser, $e->getMessage());
        $errors[] = $msg;
        $logger->warning('resync-crontab: error processing job', [
            'job_id'  => $jobId,
            'message' => $e->getMessage(),
        ]);
    }
}

$total = count($jobs);

$logger->info('resync-crontab: resync complete', [
    'total'   => $total,
    'synced'  => $synced,
    'removed' => $removed,
    'errors'  => count($errors),
]);

if (!empty($errors)) {
    foreach ($errors as $err) {
        $logger->warning('resync-crontab: job error detail', ['error' => $err]);
    }
    // Partial success is still exit 0 – the agent should still start
}

echo sprintf(
    "[resync-crontab] Done: %d active synced, %d inactive removed, %d errors (of %d total jobs)\n",
    $synced,
    $removed,
    count($errors),
    $total,
);

exit(0);
