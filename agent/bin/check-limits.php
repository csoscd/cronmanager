#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cronmanager – Execution Limit Checker
 *
 * Runs every minute via the system crontab (installed by simple_debian_setup.sh):
 *
 *   * * * * *  root  /usr/bin/php /opt/phpscripts/cronmanager/agent/bin/check-limits.php
 *
 * Responsibility:
 *   Finds all currently-running executions whose job has an `execution_limit_seconds`
 *   set and whose actual runtime has exceeded that limit.  For each such execution:
 *     1. If `notify_on_failure` is enabled on the job and the limit-exceeded
 *        notification has not been sent yet, dispatch a background notification.
 *     2. If `auto_kill_on_limit` is enabled on the job, kill the running process
 *        (local: SIGTERM to process group; remote: SSH + kill via PID file).
 *     3. Set `notified_limit_exceeded = 1` to prevent duplicate notifications on
 *        the next checker invocation while the job is still running.
 *
 * This dual-check design ensures notifications reach the operator even for jobs
 * that complete before the checker runs (ExecutionFinishEndpoint handles those)
 * and for long-running jobs that stay alive across multiple checker invocations.
 *
 * Exit codes:
 *   0 – completed normally (zero or more executions processed)
 *   1 – fatal error (bootstrap or database failure)
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

// ---------------------------------------------------------------------------
// Shared vendor autoloader (same path as used by agent.php)
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
use Cronmanager\Agent\Database\Connection;
use Cronmanager\Agent\Notification\MailNotifier;
use Cronmanager\Agent\Notification\TelegramNotifier;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

try {
    $bootstrap = Bootstrap::getInstance();
    $logger    = $bootstrap->getLogger();
    $config    = $bootstrap->getConfig();
} catch (\Throwable $e) {
    error_log(sprintf('[check-limits] Bootstrap failed: %s', $e->getMessage()));
    exit(1);
}

$logger->debug('check-limits: starting execution-limit check');

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------

try {
    $pdo = Connection::getInstance()->getPdo();
} catch (\Throwable $e) {
    $logger->error('check-limits: database connection failed', ['message' => $e->getMessage()]);
    exit(1);
}

// ---------------------------------------------------------------------------
// Find executions that have exceeded their job's limit
// ---------------------------------------------------------------------------

try {
    $stmt = $pdo->query(
        'SELECT
             el.id             AS execution_id,
             el.cronjob_id     AS job_id,
             el.started_at,
             el.pid,
             el.pid_file,
             el.target,
             el.notified_limit_exceeded,
             j.description,
             j.command,
             j.linux_user,
             j.schedule,
             j.notify_on_failure,
             j.execution_limit_seconds,
             j.auto_kill_on_limit,
             j.notify_after_limit_exceeded,
             TIMESTAMPDIFF(SECOND, el.started_at, NOW()) AS elapsed_seconds
         FROM execution_log el
         JOIN cronjobs j ON j.id = el.cronjob_id
         WHERE el.finished_at IS NULL
           AND j.execution_limit_seconds IS NOT NULL
           AND TIMESTAMPDIFF(SECOND, el.started_at, NOW()) > j.execution_limit_seconds'
    );
    $exceeded = $stmt->fetchAll();
} catch (\Throwable $e) {
    $logger->error('check-limits: failed to query exceeded executions', ['message' => $e->getMessage()]);
    exit(1);
}

if (empty($exceeded)) {
    $logger->debug('check-limits: no executions exceeding their limit');
    exit(0);
}

$logger->info('check-limits: found executions exceeding limit', ['count' => count($exceeded)]);

$mailNotifier     = new MailNotifier($logger, $config);
$telegramNotifier = new TelegramNotifier($logger, $config);
$notifyScript    = __DIR__ . '/send-notification.php';
$execAvailable   = function_exists('exec')
    && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);

foreach ($exceeded as $row) {
    $executionId    = (int) $row['execution_id'];
    $jobId          = (int) $row['job_id'];
    $elapsedSeconds = (int) $row['elapsed_seconds'];
    $limitSeconds   = (int) $row['execution_limit_seconds'];
    $autoKill       = (bool) $row['auto_kill_on_limit'];
    $notifyEnabled  = (bool) $row['notify_on_failure'];
    $alreadyNotified = (bool) $row['notified_limit_exceeded'];
    $pid            = $row['pid']     !== null ? (int) $row['pid']          : null;
    $pidFile        = $row['pid_file'] !== null ? (string) $row['pid_file'] : null;
    $target         = $row['target']   !== null ? (string) $row['target']   : 'local';
    $startedAt      = (string) $row['started_at'];
    $label          = ($row['description'] !== null && $row['description'] !== '')
        ? (string) $row['description']
        : (string) $row['command'];

    $logger->info('check-limits: execution exceeds limit', [
        'execution_id'    => $executionId,
        'job_id'          => $jobId,
        'elapsed_seconds' => $elapsedSeconds,
        'limit_seconds'   => $limitSeconds,
        'auto_kill'       => $autoKill,
        'notify_enabled'  => $notifyEnabled,
    ]);

    // -----------------------------------------------------------------------
    // 1. Send limit-exceeded notification (once per execution)
    // -----------------------------------------------------------------------

    if ($notifyEnabled && !$alreadyNotified) {
        $notifyAfterLimitExceeded = max(1, (int) ($row['notify_after_limit_exceeded'] ?? 1));

        // threshold=1: notify on every occurrence (default).
        // threshold>1: count previous consecutive limit-exceeded finished executions
        // for this job/target and add 1 for the current still-running execution.
        // Only notify on exactly the Nth consecutive exceeding.
        $shouldNotify = true;
        if ($notifyAfterLimitExceeded > 1) {
            $prevConsecutive  = countConsecutiveLimitExceeded($pdo, $jobId, $target);
            $totalConsecutive = $prevConsecutive + 1;
            $shouldNotify     = ($totalConsecutive === $notifyAfterLimitExceeded);

            if (!$shouldNotify) {
                $logger->info('check-limits: limit-exceeded notification suppressed (threshold not reached or already notified)', [
                    'execution_id'               => $executionId,
                    'consecutive_limit_exceeded' => $totalConsecutive,
                    'threshold'                  => $notifyAfterLimitExceeded,
                ]);
            }
        }

        // Mark notified first so duplicate alerts are prevented on the next checker
        // invocation regardless of whether we actually dispatch a notification.
        try {
            $pdo->prepare(
                'UPDATE execution_log SET notified_limit_exceeded = 1 WHERE id = :id'
            )->execute([':id' => $executionId]);
        } catch (\Throwable $e) {
            $logger->error('check-limits: failed to mark notified_limit_exceeded', [
                'execution_id' => $executionId,
                'message'      => $e->getMessage(),
            ]);
        }

        if ($shouldNotify) {
            $payload = json_encode([
                'job_id'      => $jobId,
                'description' => $label,
                'linux_user'  => (string) $row['linux_user'],
                'schedule'    => (string) $row['schedule'],
                'exit_code'   => -3,   // sentinel: -3 = limit exceeded (still running)
                'output'      => sprintf(
                    'Execution limit exceeded: job has been running for %d seconds (limit: %d seconds).',
                    $elapsedSeconds,
                    $limitSeconds,
                ),
                'started_at'  => $startedAt,
                'finished_at' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE);

            $dispatched = false;

            if ($payload !== false && file_exists($notifyScript) && $execAvailable) {
                $tempFile = tempnam(sys_get_temp_dir(), 'cronmgr_limit_');
                if ($tempFile !== false && file_put_contents($tempFile, $payload) !== false) {
                    $cmd = sprintf(
                        'timeout 30 php %s %s > /dev/null 2>&1 &',
                        escapeshellarg($notifyScript),
                        escapeshellarg($tempFile),
                    );
                    exec($cmd);
                    $dispatched = true;
                    $logger->info('check-limits: limit-exceeded notification dispatched', [
                        'execution_id' => $executionId,
                        'job_id'       => $jobId,
                    ]);
                }
            }

            if (!$dispatched) {
                // Synchronous fallback
                $limitOutput = sprintf(
                    'Execution limit exceeded: job has been running for %d seconds (limit: %d seconds).',
                    $elapsedSeconds,
                    $limitSeconds,
                );

                try {
                    $mailNotifier->sendFailureAlert(
                        jobId:       $jobId,
                        description: $label,
                        linuxUser:   (string) $row['linux_user'],
                        schedule:    (string) $row['schedule'],
                        exitCode:    -3,
                        output:      $limitOutput,
                        startedAt:   $startedAt,
                        finishedAt:  date('Y-m-d H:i:s'),
                    );
                    $logger->info('check-limits: limit-exceeded mail notification sent synchronously', [
                        'execution_id' => $executionId,
                    ]);
                } catch (\Throwable $e) {
                    $logger->error('check-limits: failed to send limit-exceeded mail notification', [
                        'execution_id' => $executionId,
                        'message'      => $e->getMessage(),
                    ]);
                }

                try {
                    $telegramNotifier->sendFailureAlert(
                        jobId:       $jobId,
                        description: $label,
                        linuxUser:   (string) $row['linux_user'],
                        schedule:    (string) $row['schedule'],
                        exitCode:    -3,
                        output:      $limitOutput,
                        startedAt:   $startedAt,
                        finishedAt:  date('Y-m-d H:i:s'),
                    );
                    $logger->info('check-limits: limit-exceeded Telegram notification sent synchronously', [
                        'execution_id' => $executionId,
                    ]);
                } catch (\Throwable $e) {
                    $logger->error('check-limits: failed to send limit-exceeded Telegram notification', [
                        'execution_id' => $executionId,
                        'message'      => $e->getMessage(),
                    ]);
                }
            }
        } // end if ($shouldNotify)
    }

    // -----------------------------------------------------------------------
    // 2. Auto-kill (if enabled)
    // -----------------------------------------------------------------------

    if ($autoKill) {
        $killed    = false;
        $killError = '';

        if ($target !== 'local' && $pidFile !== null) {
            $killed = killRemote(sshHost: $target, pidFile: $pidFile, error: $killError, logger: $logger);
        } elseif ($pid !== null) {
            $killed = killLocal(pid: $pid, error: $killError, logger: $logger);
        } else {
            $killError = 'No PID or PID file stored; cannot auto-kill.';
        }

        if ($killed) {
            $logger->info('check-limits: auto-killed execution', [
                'execution_id' => $executionId,
                'target'       => $target,
            ]);
            // Mark as finished with exit_code = -2 (killed)
            try {
                $pdo->prepare(
                    'UPDATE execution_log
                        SET finished_at = :finished_at,
                            exit_code   = -2,
                            pid         = NULL,
                            pid_file    = NULL,
                            output      = CONCAT(COALESCE(output, \'\'), \'\\n[Job auto-killed: execution limit exceeded]\')
                      WHERE id = :id
                        AND finished_at IS NULL'
                )->execute([
                    ':finished_at' => date('Y-m-d H:i:s'),
                    ':id'          => $executionId,
                ]);
            } catch (\Throwable $e) {
                $logger->error('check-limits: failed to mark auto-killed execution as finished', [
                    'execution_id' => $executionId,
                    'message'      => $e->getMessage(),
                ]);
            }
        } else {
            $logger->warning('check-limits: auto-kill did not succeed', [
                'execution_id' => $executionId,
                'error'        => $killError,
            ]);
        }
    }
}

$logger->debug('check-limits: finished');
exit(0);

// ---------------------------------------------------------------------------
// Helper functions
// ---------------------------------------------------------------------------

/**
 * Count consecutive limit-exceeded finished executions for a job/target.
 *
 * The current execution is still running (finished_at IS NULL), so it is
 * naturally excluded from the query.  The caller adds 1 for the current
 * execution to obtain the full consecutive streak.
 *
 * @param \PDO        $pdo    Database connection.
 * @param int         $jobId  Cron job ID.
 * @param string|null $target Execution target ("local", SSH alias, or NULL for legacy rows).
 *
 * @return int Number of previous consecutive limit-exceeded finished executions.
 */
function countConsecutiveLimitExceeded(\PDO $pdo, int $jobId, ?string $target): int
{
    // Most recent finished execution that did NOT exceed the limit –
    // this resets the consecutive streak.
    $lastOkStmt = $pdo->prepare(
        'SELECT MAX(started_at)
           FROM execution_log
          WHERE cronjob_id = :job_id
            AND (:target1 IS NULL AND target IS NULL OR target = :target2)
            AND finished_at IS NOT NULL
            AND notified_limit_exceeded = 0
            AND exit_code != -2'
    );
    $lastOkStmt->execute([':job_id' => $jobId, ':target1' => $target, ':target2' => $target]);
    $lastOk = $lastOkStmt->fetchColumn();

    // Count finished executions after $lastOk that DID exceed the limit.
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*)
           FROM execution_log
          WHERE cronjob_id = :job_id
            AND (:target1 IS NULL AND target IS NULL OR target = :target2)
            AND finished_at IS NOT NULL
            AND (notified_limit_exceeded = 1 OR exit_code = -2)
            AND started_at > COALESCE(:last_ok, \'1970-01-01 00:00:00\')'
    );
    $countStmt->execute([
        ':job_id'  => $jobId,
        ':target1' => $target,
        ':target2' => $target,
        ':last_ok' => ($lastOk !== false && $lastOk !== null) ? (string) $lastOk : null,
    ]);

    return (int) $countStmt->fetchColumn();
}

/**
 * Send SIGTERM to the process group of a local job.
 *
 * @param int                    $pid    OS process ID.
 * @param string                 $error  Populated on failure.
 * @param \Monolog\Logger        $logger Logger instance.
 *
 * @return bool True when signal was delivered.
 */
function killLocal(int $pid, string &$error, \Monolog\Logger $logger): bool
{
    $logger->debug('check-limits: killing local process group', ['pid' => $pid]);

    if (function_exists('posix_kill')) {
        if (posix_kill(-$pid, SIGTERM)) {
            return true;
        }
    }

    $outputArr = [];
    $exit      = 0;
    exec('kill -TERM -' . (int) $pid . ' 2>&1', $outputArr, $exit);

    if ($exit !== 0) {
        $error = sprintf('kill -TERM -%d failed (exit %d): %s', $pid, $exit, implode("\n", $outputArr));
        return false;
    }

    return true;
}

/**
 * Kill a remotely-running job via SSH.
 *
 * @param string          $sshHost SSH config host alias.
 * @param string          $pidFile Path to PID-file on the remote host.
 * @param string          $error   Populated on failure.
 * @param \Monolog\Logger $logger  Logger instance.
 *
 * @return bool True when the remote kill succeeded.
 */
function killRemote(string $sshHost, string $pidFile, string &$error, \Monolog\Logger $logger): bool
{
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $sshHost)) {
        $error = sprintf('Invalid SSH host alias: %s', $sshHost);
        return false;
    }

    if (!preg_match('#^/tmp/\.cmgr_\d+$#', $pidFile)) {
        $error = sprintf('Invalid PID file path: %s', $pidFile);
        $logger->warning('check-limits: rejected unsafe PID file path', ['pid_file' => $pidFile]);
        return false;
    }

    $logger->debug('check-limits: killing remote process', [
        'ssh_host' => $sshHost,
        'pid_file' => $pidFile,
    ]);

    $remoteCmd = sprintf(
        'if [ -f %s ]; then PID=$(cat %s); kill -TERM -$PID 2>/dev/null; rm -f %s; fi',
        escapeshellarg($pidFile),
        escapeshellarg($pidFile),
        escapeshellarg($pidFile),
    );

    $sshCmd    = sprintf(
        'ssh -o BatchMode=yes -o ConnectTimeout=10 %s -- %s 2>&1',
        escapeshellarg($sshHost),
        escapeshellarg($remoteCmd),
    );

    $outputArr = [];
    $exit      = 0;
    exec($sshCmd, $outputArr, $exit);

    if ($exit !== 0) {
        $error = sprintf('SSH kill failed (exit %d): %s', $exit, implode("\n", $outputArr));
        return false;
    }

    return true;
}
