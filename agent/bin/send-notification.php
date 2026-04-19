#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cronmanager – Background Failure Notification Dispatcher
 *
 * Spawned as a detached background process by ExecutionFinishEndpoint when a
 * cron job fails and notify_on_failure is enabled. Running the SMTP operation
 * in a separate process prevents the single-threaded PHP built-in agent server
 * from blocking on slow or unreachable SMTP servers.
 *
 * Usage (called internally by ExecutionFinishEndpoint – do not invoke manually):
 *   php send-notification.php <json-temp-file>
 *
 *   <json-temp-file>  Absolute path to a temporary JSON file containing the
 *                     notification payload. The file is deleted by this script
 *                     immediately after reading to avoid leaving sensitive data
 *                     on disk.
 *
 * Expected JSON payload keys:
 *   job_id, description, linux_user, schedule, exit_code, output,
 *   started_at, finished_at
 *
 * Exit codes:
 *   0 – mail dispatched successfully (or disabled in config)
 *   1 – error (missing/unreadable file, bad JSON, or SMTP failure)
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
// dirname(__DIR__) resolves to agent/ from agent/bin/, matching agent.php
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
use Cronmanager\Agent\Notification\MailNotifier;
use Cronmanager\Agent\Notification\TelegramNotifier;

// ---------------------------------------------------------------------------
// Read and immediately delete the temp file
// ---------------------------------------------------------------------------

if (empty($argv[1])) {
    error_log('[send-notification] No data file argument provided');
    exit(1);
}

$tempFile = $argv[1];

if (!is_readable($tempFile)) {
    error_log(sprintf('[send-notification] Data file not readable: %s', $tempFile));
    exit(1);
}

$raw = (string) file_get_contents($tempFile);
@unlink($tempFile);   // delete immediately – file contains job output

$data = json_decode($raw, true);

if (!is_array($data)) {
    error_log('[send-notification] Failed to decode notification JSON payload');
    exit(1);
}

// ---------------------------------------------------------------------------
// Bootstrap (config + logger) and send the failure alert
// ---------------------------------------------------------------------------

try {
    $bootstrap = Bootstrap::getInstance();
    $logger    = $bootstrap->getLogger();
    $config    = $bootstrap->getConfig();

    $jobId               = (int)    ($data['job_id']               ?? 0);
    $description         = (string) ($data['description']         ?? '');
    $linuxUser           = (string) ($data['linux_user']          ?? '');
    $schedule            = (string) ($data['schedule']            ?? '');
    $exitCode            = (int)    ($data['exit_code']           ?? 1);
    $output              = (string) ($data['output']              ?? '');
    $startedAt           = (string) ($data['started_at']          ?? '');
    $finishedAt          = (string) ($data['finished_at']         ?? '');
    $notifyAfterFailures = max(1, (int) ($data['notify_after_failures'] ?? 1));
    $target              = (string) ($data['target']              ?? '');
    $stillRunning        = (bool)   ($data['still_running']       ?? false);

    $mailNotifier     = new MailNotifier($logger, $config);
    $telegramNotifier = new TelegramNotifier($logger, $config);

    $mailNotifier->sendFailureAlert(
        jobId:               $jobId,
        description:         $description,
        linuxUser:           $linuxUser,
        schedule:            $schedule,
        exitCode:            $exitCode,
        output:              $output,
        startedAt:           $startedAt,
        finishedAt:          $finishedAt,
        notifyAfterFailures: $notifyAfterFailures,
        target:              $target,
        stillRunning:        $stillRunning,
    );

    $telegramNotifier->sendFailureAlert(
        jobId:               $jobId,
        description:         $description,
        linuxUser:           $linuxUser,
        schedule:            $schedule,
        exitCode:            $exitCode,
        output:              $output,
        startedAt:           $startedAt,
        finishedAt:          $finishedAt,
        notifyAfterFailures: $notifyAfterFailures,
        target:              $target,
        stillRunning:        $stillRunning,
    );

} catch (\Throwable $e) {
    error_log(sprintf(
        '[send-notification] %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    exit(1);
}

exit(0);
