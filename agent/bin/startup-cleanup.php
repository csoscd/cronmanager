<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – Startup Orphan Cleanup
 *
 * Standalone script executed once by start-agent.sh before the PHP built-in
 * server is launched.  It scans the execution_log table for executions that
 * are still marked as running (finished_at IS NULL) but whose process is no
 * longer alive, and marks them as interrupted (exit_code = -5).
 *
 * This handles two scenarios that arise after an ungraceful agent shutdown
 * (e.g. VM maintenance stop):
 *
 *  1. Jobs that were started just before the stop and whose finish event was
 *     never received.
 *  2. Jobs whose process was killed mid-run when the VM was suspended.
 *
 * A 2-minute grace period (started_at < NOW() - INTERVAL 2 MINUTE) prevents
 * falsely marking executions that legitimately just started at the moment this
 * script runs.
 *
 * PID checking:
 *  - For local targets ("local" or NULL) with a known PID: posix_kill($pid, 0)
 *    is used to test whether the process is still alive.  If the process exists
 *    the execution is left untouched (the agent restart did not affect it).
 *  - For remote SSH targets or executions without a stored PID: the process
 *    cannot be verified locally, so any execution older than the grace period
 *    is assumed to be orphaned.
 *
 * Exit codes used:
 *  -5  Interrupted by system restart (new sentinel, set by this script)
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

require_once '/opt/phplib/vendor/autoload.php';

// PSR-4 autoloader for Cronmanager\Agent\* (same as agent.php)
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

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap: config + logger + database
// ─────────────────────────────────────────────────────────────────────────────

try {
    $bootstrap = Bootstrap::getInstance();
    $logger    = $bootstrap->getLogger();
    $pdo       = Connection::getInstance()->getPdo();
} catch (\Throwable $e) {
    // If bootstrap or DB fails, log to stderr and exit gracefully so that the
    // agent still starts even when the database is briefly unavailable.
    fwrite(STDERR, sprintf(
        "[startup-cleanup] Bootstrap failed: %s\n",
        $e->getMessage()
    ));
    exit(0);
}

$logger->info('startup-cleanup: scanning for orphaned running executions');

// ─────────────────────────────────────────────────────────────────────────────
// Find orphaned executions
// ─────────────────────────────────────────────────────────────────────────────

try {
    $stmt = $pdo->prepare(
        'SELECT id, pid, target, output
           FROM execution_log
          WHERE finished_at IS NULL
            AND started_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)'
    );
    $stmt->execute();
    $orphans = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $logger->error('startup-cleanup: database query failed', ['error' => $e->getMessage()]);
    exit(0);
}

if (empty($orphans)) {
    $logger->info('startup-cleanup: no orphaned executions found');
    exit(0);
}

$logger->info('startup-cleanup: found orphaned executions', ['count' => count($orphans)]);

// ─────────────────────────────────────────────────────────────────────────────
// Process each orphan
// ─────────────────────────────────────────────────────────────────────────────

$nowUtc       = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$interruptMsg = 'Interrupted: agent restarted while job was running.';
$cleaned      = 0;

foreach ($orphans as $row) {
    $id     = (int)    $row['id'];
    $pid    = $row['pid'] !== null ? (int) $row['pid'] : null;
    $target = (string) ($row['target'] ?? 'local');

    // For local targets with a known PID, verify whether the process is alive.
    $isLocal = ($target === 'local' || $target === '');
    if ($isLocal && $pid !== null) {
        // posix_kill with signal 0 tests process existence without sending a signal.
        // Returns true if the process exists (and we have permission to signal it).
        if (\posix_kill($pid, 0)) {
            $logger->info('startup-cleanup: process still alive, skipping', [
                'execution_id' => $id,
                'pid'          => $pid,
            ]);
            continue;
        }
    }

    // Append the interrupt message to any existing output.
    $existingOutput = (string) ($row['output'] ?? '');
    $newOutput = $existingOutput !== ''
        ? $existingOutput . "\n" . $interruptMsg
        : $interruptMsg;

    try {
        $update = $pdo->prepare(
            'UPDATE execution_log
                SET exit_code   = -5,
                    finished_at = :finished_at,
                    output      = :output
              WHERE id = :id'
        );
        $update->execute([
            ':finished_at' => $nowUtc,
            ':output'      => $newOutput,
            ':id'          => $id,
        ]);

        $logger->info('startup-cleanup: marked execution as interrupted', [
            'execution_id' => $id,
            'pid'          => $pid,
            'target'       => $target,
        ]);

        $cleaned++;

    } catch (\Throwable $e) {
        $logger->error('startup-cleanup: failed to update execution', [
            'execution_id' => $id,
            'error'        => $e->getMessage(),
        ]);
    }
}

$logger->info('startup-cleanup: complete', ['cleaned' => $cleaned, 'total_orphans' => count($orphans)]);
exit(0);
