<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – TransferController
 *
 * Transfers one or more cron jobs from the currently selected agent to a
 * different destination agent using the existing agent REST API.
 *
 * Routes:
 *   GET  /crons/transfer          – preview / confirmation page
 *   POST /crons/transfer/validate – JSON: target compatibility check (AJAX)
 *   POST /crons/transfer          – execute the transfer
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

use Cronmanager\Web\Agent\HostAgentClient;
use Cronmanager\Web\Database\Connection;
use Cronmanager\Web\Http\Response;
use Cronmanager\Web\Repository\AgentRepository;
use Cronmanager\Web\Session\SessionManager;

/**
 * Class TransferController
 *
 * Implements the job-transfer flow: preview with target-compatibility warnings,
 * AJAX revalidation on destination-agent change, and final execution.
 */
final class TransferController extends BaseController
{
    // -------------------------------------------------------------------------
    // GET /crons/transfer
    // -------------------------------------------------------------------------

    /**
     * Render the transfer preview page.
     *
     * Loads the selected jobs from the source agent, fetches the SSH targets
     * of the first available destination agent, and pre-computes compatibility
     * warnings per job.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function prepare(array $params = []): void
    {
        $t = $this->translator();

        // Parse job IDs from query string
        $ids = $this->parseIds($_GET['ids'] ?? []);

        if ($ids === []) {
            SessionManager::set('_flash_error', $t->t('transfer_error_no_ids'));
            (new Response())->redirect('/crons');
            return;
        }

        // Need at least one other enabled agent as destination
        $pdo        = Connection::getInstance()->getPdo();
        $repo       = new AgentRepository($pdo);
        $sourceAgent = $this->selectedAgent();
        $destAgents  = array_values(array_filter(
            $repo->findEnabled(),
            fn(array $a): bool => (int) $a['id'] !== (int) $sourceAgent['id'],
        ));

        if ($destAgents === []) {
            SessionManager::set('_flash_error', $t->t('transfer_error_no_destination'));
            (new Response())->redirect('/crons');
            return;
        }

        // Load job details from source agent
        $jobs     = $this->loadJobs($ids);
        $destTargets = $this->fetchDestTargets($destAgents[0]);
        $warnings    = $this->computeWarnings($jobs, $destTargets);

        $this->render('cron/transfer.php', $t->t('transfer_title'), [
            'jobs'        => $jobs,
            'destAgents'  => $destAgents,
            'warnings'    => $warnings,
            'sourceAgent' => $sourceAgent,
        ], '/crons');
    }

    // -------------------------------------------------------------------------
    // POST /crons/transfer/validate   → JSON
    // -------------------------------------------------------------------------

    /**
     * AJAX endpoint: return target-compatibility warnings for a given
     * destination agent and a set of job IDs.
     *
     * Request: form-encoded POST with fields destination_agent_id and job_ids[].
     * CSRF validation is performed by the router (same as every protected POST).
     *
     * Response: { "warnings": { "1": ["prod"], "3": [] } }
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function validate(array $params = []): void
    {
        $destId = (int) ($_POST['destination_agent_id'] ?? 0);
        $ids    = $this->parseIds($_POST['job_ids'] ?? []);

        if ($destId <= 0) {
            $this->jsonResponse(['error' => 'invalid destination'], 400);
            return;
        }

        $pdo  = Connection::getInstance()->getPdo();
        $repo = new AgentRepository($pdo);
        $dest = $repo->findById($destId);

        if ($dest === null) {
            $this->jsonResponse(['error' => 'agent not found'], 404);
            return;
        }

        $jobs        = $this->loadJobs($ids);
        $destTargets = $this->fetchDestTargets($dest);
        $warnings    = $this->computeWarnings($jobs, $destTargets);

        $this->jsonResponse(['warnings' => $warnings]);
    }

    // -------------------------------------------------------------------------
    // POST /crons/transfer
    // -------------------------------------------------------------------------

    /**
     * Execute the job transfer.
     *
     * For each job ID: create the job on the destination agent, then apply the
     * chosen post-transfer action (keep / deactivate / delete) on the source.
     * Partial failures are tolerated; a summary flash message is always shown.
     *
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return void
     */
    public function execute(array $params = []): void
    {
        $t = $this->translator();

        $ids          = $this->parseIds($_POST['ids'] ?? []);
        $destId       = (int) ($_POST['destination_agent_id'] ?? 0);
        $sourceAction = trim((string) ($_POST['source_action'] ?? 'keep'));

        if ($ids === []) {
            SessionManager::set('_flash_error', $t->t('transfer_error_no_ids'));
            (new Response())->redirect('/crons');
            return;
        }

        $pdo  = Connection::getInstance()->getPdo();
        $repo = new AgentRepository($pdo);
        $dest = $repo->findById($destId);

        if ($dest === null) {
            SessionManager::set('_flash_error', $t->t('transfer_error_no_destination'));
            (new Response())->redirect('/crons');
            return;
        }

        $sourceAgent = $this->selectedAgent();

        if ((int) $dest['id'] === (int) $sourceAgent['id']) {
            SessionManager::set('_flash_error', $t->t('transfer_error_same_agent'));
            (new Response())->redirect('/crons');
            return;
        }

        $destClient  = $this->buildClientFor($dest);
        $srcClient   = $this->agentClient();

        $transferred = 0;
        $errors      = [];

        foreach ($ids as $id) {
            try {
                $job = $srcClient->get("/crons/{$id}");
            } catch (\Throwable $e) {
                $errors[] = $id;
                $this->logger->warning('TransferController: cannot read source job', [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $payload = $this->buildTransferPayload($job);

            try {
                $destClient->post('/crons', $payload);
                $transferred++;
            } catch (\Throwable $e) {
                $errors[] = $id;
                $this->logger->warning('TransferController: failed to create job on destination', [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            // Apply source action only when creation on destination succeeded
            try {
                match ($sourceAction) {
                    'deactivate' => $srcClient->put("/crons/{$id}", ['active' => false]),
                    'delete'     => $srcClient->delete("/crons/{$id}"),
                    default      => null,
                };
            } catch (\Throwable $e) {
                // Non-fatal: transfer succeeded, source action failed
                $this->logger->warning('TransferController: source action failed', [
                    'id'     => $id,
                    'action' => $sourceAction,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        // Build flash message
        $m = count($errors);
        if ($m === 0) {
            $msg = str_replace('{n}', (string) $transferred, $t->t('transfer_flash_done'));
            SessionManager::set('_flash_success', $msg);
        } elseif ($transferred > 0) {
            $msg = str_replace(['{n}', '{m}'], [(string) $transferred, (string) $m], $t->t('transfer_flash_partial'));
            SessionManager::set('_flash_error', $msg);
        } else {
            SessionManager::set('_flash_error', $t->t('transfer_flash_error'));
        }

        (new Response())->redirect('/crons');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Parse a raw ID list (strings or ints) into a filtered array of ints.
     *
     * @param mixed $raw Value from $_GET / $_POST / JSON body.
     *
     * @return int[]
     */
    private function parseIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', $raw),
            static fn(int $v): bool => $v > 0,
        ));
    }

    /**
     * Load job details for a list of IDs from the currently selected agent.
     *
     * Jobs that cannot be fetched (e.g. not found) are silently skipped.
     *
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>  Associative list keyed by job ID.
     */
    private function loadJobs(array $ids): array
    {
        $jobs   = [];
        $client = $this->agentClient();

        foreach ($ids as $id) {
            try {
                $job = $client->get("/crons/{$id}");
                if (is_array($job) && isset($job['id'])) {
                    $jobs[(int) $job['id']] = $job;
                }
            } catch (\Throwable $e) {
                $this->logger->debug('TransferController: skipping unreadable job', [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $jobs;
    }

    /**
     * Fetch the list of available SSH targets from a destination agent.
     *
     * Returns an empty array when the agent is unreachable.
     *
     * @param array<string, mixed> $dest Agent DB row.
     *
     * @return string[]
     */
    private function fetchDestTargets(array $dest): array
    {
        try {
            $client = $this->buildClientFor($dest);
            $result = $client->get('/import/ssh-targets');
            return is_array($result['data'] ?? null) ? (array) $result['data'] : [];
        } catch (\Throwable $e) {
            $this->logger->warning('TransferController: cannot fetch dest SSH targets', [
                'agent' => $dest['name'] ?? '',
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Compute missing-target warnings for each job against the destination
     * agent's known SSH targets.
     *
     * "local" and "_agent_" are always considered compatible.
     *
     * @param array<int, array<string, mixed>> $jobs        Jobs keyed by ID.
     * @param string[]                          $destTargets Available targets on destination.
     *
     * @return array<string, string[]>  Map of job-ID string → missing target names.
     */
    private function computeWarnings(array $jobs, array $destTargets): array
    {
        $warnings = [];

        foreach ($jobs as $id => $job) {
            $jobTargets = is_array($job['targets'] ?? null) ? (array) $job['targets'] : [];
            $missing    = [];

            foreach ($jobTargets as $target) {
                $t = (string) $target;
                if ($t === 'local' || $t === '_agent_') {
                    continue;
                }
                if (!in_array($t, $destTargets, true)) {
                    $missing[] = $t;
                }
            }

            $warnings[(string) $id] = $missing;
        }

        return $warnings;
    }

    /**
     * Build an HostAgentClient for an arbitrary agent DB row.
     *
     * @param array<string, mixed> $agent Agent DB row.
     *
     * @return HostAgentClient
     */
    private function buildClientFor(array $agent): HostAgentClient
    {
        return new HostAgentClient(
            logger:      $this->logger,
            agentUrl:    (string) $agent['url'],
            hmacSecret:  (string) $agent['hmac_secret'],
            timeout:     (int)    ($agent['timeout']      ?? 10),
            sslVerify:   (bool)   ($agent['ssl_verify']   ?? true),
            sslCaBundle: (string) ($agent['ssl_ca_bundle'] ?? ''),
        );
    }

    /**
     * Build the POST /crons payload from a source job.
     *
     * Strips fields that must not be copied (id, created_at, legacy fields).
     *
     * @param array<string, mixed> $job Source job array from GET /crons/{id}.
     *
     * @return array<string, mixed>
     */
    private function buildTransferPayload(array $job): array
    {
        $keep = [
            'linux_user', 'schedule', 'command', 'description',
            'active', 'notify_on_failure', 'notify_on_recovery',
            'execution_limit_seconds', 'auto_kill_on_limit',
            'singleton', 'run_in_maintenance', 'retention_days',
            'retry_count', 'retry_delay_minutes', 'restart_on_exitcodes',
            'notify_after_failures', 'notify_after_limit_exceeded',
            'targets', 'tags',
        ];

        $payload = [];
        foreach ($keep as $field) {
            if (array_key_exists($field, $job)) {
                $payload[$field] = $job[$field];
            }
        }

        return $payload;
    }
}
