<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Export API Controller
 *
 * Handles GET /api/v1/export.
 *
 * Supported formats: json (default), csv, cron
 *
 * The agent only supports format=json for its /export endpoint. csv and cron
 * output is therefore generated in this controller from the JSON job list so
 * that no agent changes are required.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Api;

use Cronmanager\Web\Auth\ApiKeyMiddleware;
use Cronmanager\Web\Auth\ScopeHelper;
use Cronmanager\Web\Database\Connection;

/**
 * Class ExportApiController
 */
final class ExportApiController extends BaseApiController
{
    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/export
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function download(array $params): void
    {
        $pdo    = Connection::getInstance()->getPdo();
        $apiKey = (new ApiKeyMiddleware($pdo, $this->logger))->authenticate(ScopeHelper::SCOPE_EXPORT_READ);

        if ($apiKey === null) {
            return;
        }

        $format = strtolower((string) ($_GET['format'] ?? 'json'));

        if (!in_array($format, ['json', 'csv', 'cron'], strict: true)) {
            $this->jsonError(400, 'Invalid format', 'Supported formats: json, csv, cron.');
            return;
        }

        $agent = $this->agentClient($apiKey);
        if ($agent === null) {
            return;
        }

        // Always fetch JSON from the agent; csv/cron are rendered here.
        $query = array_filter([
            'format' => 'json',
            'user'   => $_GET['user'] ?? null,
            'tag'    => $_GET['tag']  ?? null,
        ], fn($v) => $v !== null);

        $response = $this->agentGet($agent, '/export', $query);
        if ($response === null) {
            return;
        }

        if ($format === 'json') {
            $this->jsonOk($response);
            return;
        }

        /** @var array<int, array<string, mixed>> $jobs */
        $jobs = is_array($response['data'] ?? null) ? $response['data'] : [];

        if ($format === 'csv') {
            $this->emitCsv($jobs);
            return;
        }

        // format === 'cron'
        $this->emitCrontab($jobs);
    }

    // -------------------------------------------------------------------------
    // Private helpers – output formatters
    // -------------------------------------------------------------------------

    /**
     * Emit a CSV export and terminate.
     *
     * @param array<int, array<string, mixed>> $jobs
     *
     * @return void
     */
    private function emitCsv(array $jobs): void
    {
        $filename = 'cronmanager-export-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            return;
        }

        fputcsv($out, [
            'id', 'linux_user', 'schedule', 'command', 'description',
            'active', 'tags', 'targets', 'notify_on_failure',
            'execution_limit_seconds', 'retry_count', 'retry_delay_minutes',
            'singleton', 'run_in_maintenance', 'retention_days', 'created_at',
        ]);

        foreach ($jobs as $job) {
            fputcsv($out, [
                $job['id']                       ?? '',
                $job['linux_user']               ?? '',
                $job['schedule']                 ?? '',
                $job['command']                  ?? '',
                $job['description']              ?? '',
                isset($job['active']) ? ((bool) $job['active'] ? '1' : '0') : '',
                implode('|', (array) ($job['tags']    ?? [])),
                implode('|', (array) ($job['targets'] ?? [])),
                isset($job['notify_on_failure']) ? ((bool) $job['notify_on_failure'] ? '1' : '0') : '',
                $job['execution_limit_seconds']  ?? '',
                $job['retry_count']              ?? '',
                $job['retry_delay_minutes']      ?? '',
                isset($job['singleton'])          ? ((bool) $job['singleton']          ? '1' : '0') : '',
                isset($job['run_in_maintenance']) ? ((bool) $job['run_in_maintenance'] ? '1' : '0') : '',
                $job['retention_days']           ?? '',
                $job['created_at']               ?? '',
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * Emit a crontab-formatted plain-text export and terminate.
     *
     * Jobs are grouped by linux_user. SSH-target commands are wrapped with
     * escapeshellarg so that shell metacharacters are forwarded correctly to
     * the remote shell.
     *
     * @param array<int, array<string, mixed>> $jobs
     *
     * @return void
     */
    private function emitCrontab(array $jobs): void
    {
        $now      = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
        $filename = 'cronmanager-export-' . date('Y-m-d') . '.crontab';

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo "# Cronmanager Export\n";
        echo sprintf("# Generated: %s\n", $now);
        echo "#\n";

        if ($jobs === []) {
            echo "# (no jobs match the current filter)\n";
            exit;
        }

        $currentUser = null;

        foreach ($jobs as $job) {
            $jobUser  = (string) ($job['linux_user'] ?? '');
            $schedule = (string) ($job['schedule']   ?? '');
            $command  = (string) ($job['command']    ?? '');
            $targets  = is_array($job['targets']) && $job['targets'] !== [] ? (array) $job['targets'] : ['local'];
            $active   = (bool) ($job['active'] ?? true);

            if ($jobUser !== $currentUser) {
                echo sprintf("\n# --- %s ---\n", $jobUser);
                $currentUser = $jobUser;
            }

            $tagList     = implode(',', (array) ($job['tags'] ?? []));
            $description = isset($job['description']) && (string) $job['description'] !== ''
                ? (string) $job['description']
                : '(no description)';

            $commentParts = [sprintf('[%s]', $description)];
            if ($tagList !== '') {
                $commentParts[] = sprintf('tags:%s', $tagList);
            }

            echo sprintf("# %s\n", implode(' ', $commentParts));

            foreach ($targets as $target) {
                $target = (string) $target;

                if (count($targets) > 1) {
                    echo sprintf("# target: %s\n", $target);
                }

                $effectiveCommand = $target !== 'local' && $target !== '_agent_'
                    ? sprintf('ssh -o BatchMode=yes %s %s', $target, escapeshellarg($command))
                    : $command;

                if (!$active) {
                    echo '# DISABLED: ';
                }

                echo sprintf("%s %s\n", $schedule, $effectiveCommand);
            }
        }

        echo "\n";
        exit;
    }
}
