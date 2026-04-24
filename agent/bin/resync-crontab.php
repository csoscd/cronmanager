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

// ---------------------------------------------------------------------------
// Ghost-entry cleanup.
//
// The DB-driven loop above only processes job IDs that exist in the cronjobs
// table.  If a job was deleted from the DB but its crontab entry was not
// removed (e.g. due to a crash or mid-delete container restart), that entry
// becomes a "ghost": it fires on schedule, gets 404 from the agent, and
// produces no execution_log row — completely silent.
//
// Fix: read all cronmanager-managed entries from the live crontab and remove
// any whose job_id is absent from the set of known DB job IDs.
// ---------------------------------------------------------------------------

$dbJobIds   = array_map('intval', array_column($jobs, 'id'));
$ghostCount = 0;

try {
    foreach ($crontabManager->getManagedUsers() as $user) {
        foreach ($crontabManager->getManagedEntries($user) as $jobId => $targets) {
            if (!in_array($jobId, $dbJobIds, true)) {
                $crontabManager->removeAllEntries($user, $jobId);
                $ghostCount++;

                $logger->warning('resync-crontab: removed ghost crontab entry for deleted job', [
                    'job_id' => $jobId,
                    'user'   => $user,
                ]);
            }
        }
    }
} catch (\Throwable $e) {
    $logger->warning('resync-crontab: ghost-entry cleanup failed', [
        'message' => $e->getMessage(),
    ]);
}

// ---------------------------------------------------------------------------
// Restore once-only retry crontab entries lost during container restart.
//
// Once-only entries written by ExecutionFinishEndpoint are not stored in the
// database – they exist only in the live crontab.  On restart the crontab is
// rebuilt from scratch, silently dropping any pending retry entries.
// The job_retry_state table is the authoritative record of pending retries;
// this pass re-adds the corresponding once-only crontab entries.
// ---------------------------------------------------------------------------

$restoredRetries = 0;

$systemTz = 'UTC';
$envTz    = getenv('TZ');
if ($envTz !== false && $envTz !== '') {
    $systemTz = $envTz;
} elseif (is_link('/etc/localtime')) {
    $link = @readlink('/etc/localtime');
    if ($link !== false) {
        $pos = strpos($link, 'zoneinfo/');
        if ($pos !== false) {
            $candidate = substr($link, $pos + strlen('zoneinfo/'));
            if ($candidate !== '') {
                $systemTz = $candidate;
            }
        }
    }
} elseif (is_readable('/etc/timezone')) {
    $candidate = trim((string) file_get_contents('/etc/timezone'));
    if ($candidate !== '') {
        $systemTz = $candidate;
    }
}

try {
    $retryStmt = $pdo->query(
        'SELECT jrs.job_id, jrs.target, jrs.retry_delay_minutes, jrs.scheduled_at,
                c.linux_user
           FROM job_retry_state jrs
           JOIN cronjobs c ON c.id = jrs.job_id
          WHERE c.active = 1'
    );
    $retryRows = $retryStmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $logger->warning('resync-crontab: could not query job_retry_state', [
        'message' => $e->getMessage(),
    ]);
    $retryRows = [];
}

foreach ($retryRows as $row) {
    $jobId     = (int)    $row['job_id'];
    $linuxUser = (string) $row['linux_user'];
    $target    = (string) $row['target'];

    try {
        if ($crontabManager->hasOnceEntry($linuxUser, $jobId, $target)) {
            $logger->debug('resync-crontab: once-entry already present, skipping restore', [
                'job_id' => $jobId,
                'target' => $target,
            ]);
            continue;
        }

        $timezone    = new \DateTimeZone($systemTz);
        $scheduledAt = new \DateTime($row['scheduled_at'], $timezone);
        $fireAt      = (clone $scheduledAt)->modify('+' . (int) $row['retry_delay_minutes'] . ' minutes');

        // If the original fire time has already passed, schedule for next minute
        if ($fireAt <= new \DateTime('now', $timezone)) {
            $fireAt = new \DateTime('+1 minute', $timezone);
        }

        $schedule = sprintf(
            '%d %d %d %d *',
            (int) $fireAt->format('i'),
            (int) $fireAt->format('G'),
            (int) $fireAt->format('j'),
            (int) $fireAt->format('n'),
        );

        $crontabManager->addOnceEntry($linuxUser, $jobId, $schedule, $wrapperScript, $target);
        $restoredRetries++;

        $logger->info('resync-crontab: restored pending retry once-entry', [
            'job_id'   => $jobId,
            'target'   => $target,
            'schedule' => $schedule,
        ]);
    } catch (\Throwable $e) {
        $logger->warning('resync-crontab: failed to restore pending retry once-entry', [
            'job_id'  => $jobId,
            'target'  => $target,
            'message' => $e->getMessage(),
        ]);
    }
}

echo sprintf(
    "[resync-crontab] Done: %d active synced, %d inactive removed, %d ghost(s) removed, %d errors, %d retries restored (of %d total jobs)\n",
    $synced,
    $removed,
    $ghostCount,
    count($errors),
    $restoredRetries,
    $total,
);

exit(0);
