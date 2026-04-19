#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cronmanager – Background InfluxDB Metrics Dispatcher
 *
 * Spawned as a detached background process by ExecutionFinishEndpoint after
 * every completed execution when InfluxDB integration is enabled. Running the
 * HTTP write in a separate process prevents the single-threaded PHP built-in
 * agent server from blocking on slow or unreachable InfluxDB instances.
 *
 * Usage (called internally by ExecutionFinishEndpoint – do not invoke manually):
 *   php send-influx.php <json-temp-file>
 *
 *   <json-temp-file>  Absolute path to a temporary JSON file containing the
 *                     metrics payload. The file is deleted by this script
 *                     immediately after reading.
 *
 * Expected JSON payload keys:
 *   job_id, description, linux_user, target, exit_code, output_length,
 *   duration_seconds, during_maintenance, job_tags, finished_at_ns
 *
 * Exit codes:
 *   0 – data point written successfully (or InfluxDB disabled in config)
 *   1 – error (missing/unreadable file, bad JSON, or write failure)
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
use Cronmanager\Agent\Influx\InfluxWriter;

// ---------------------------------------------------------------------------
// Read and immediately delete the temp file
// ---------------------------------------------------------------------------

if (empty($argv[1])) {
    error_log('[send-influx] No data file argument provided');
    exit(1);
}

$tempFile = $argv[1];

if (!is_readable($tempFile)) {
    error_log(sprintf('[send-influx] Data file not readable: %s', $tempFile));
    exit(1);
}

$raw = (string) file_get_contents($tempFile);
@unlink($tempFile);

$data = json_decode($raw, true);

if (!is_array($data)) {
    error_log('[send-influx] Failed to decode metrics JSON payload');
    exit(1);
}

// ---------------------------------------------------------------------------
// Bootstrap and write to InfluxDB
// ---------------------------------------------------------------------------

try {
    $bootstrap = Bootstrap::getInstance();
    $logger    = $bootstrap->getLogger();
    $config    = $bootstrap->getConfig();

    $writer = new InfluxWriter($logger, $config);
    $writer->writeExecution($data);

} catch (\Throwable $e) {
    error_log(sprintf(
        '[send-influx] %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    exit(1);
}

exit(0);
