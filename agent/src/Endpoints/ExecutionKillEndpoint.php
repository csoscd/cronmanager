<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – ExecutionKillEndpoint
 *
 * Handles POST /execution/{id}/kill requests.
 *
 * Terminates a currently-running cron job execution identified by its
 * execution_log ID.  Works for both local and remote (SSH) execution targets:
 *
 *   Local target  – sends SIGTERM to the process group of the stored PID.
 *                   The OS delivers the signal to the bash process and all its
 *                   children, giving them a chance to clean up.
 *
 *   Remote target – SSHes to the target host, reads the stored PID-file
 *                   (/tmp/.cmgr_<execution_id>), sends SIGTERM to the remote
 *                   process group, and removes the PID-file.
 *
 * The execution_log row is updated with exit_code = −2 and the current
 * timestamp as finished_at so the job no longer appears as "running" in the UI.
 *
 * Request body: empty / no body required.
 *
 * Response on success (HTTP 200):
 * ```json
 * { "execution_id": 123, "killed": true }
 * ```
 *
 * Response when no PID is stored (HTTP 422):
 * ```json
 * { "error": "Unprocessable Entity", "message": "No PID stored for this execution." }
 * ```
 *
 * This class relies on the global `jsonResponse()` function being available
 * in the calling scope (defined in agent.php).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class ExecutionKillEndpoint
 *
 * Kills a running cron job execution by its execution_log ID.
 *
 * The endpoint is admin-protected at the web UI router level.
 */
final class ExecutionKillEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * ExecutionKillEndpoint constructor.
     *
     * @param PDO    $pdo    Active PDO database connection.
     * @param Logger $logger Monolog logger instance.
     */
    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming POST /execution/{id}/kill request.
     *
     * @param array<string, string> $params Path parameters. Expected key: 'id'.
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $rawId = $params['id'] ?? '';

        if ($rawId === '' || !ctype_digit($rawId) || (int) $rawId <= 0) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Path parameter {id} must be a positive integer.',
                'code'    => 400,
            ]);
            return;
        }

        $executionId = (int) $rawId;

        $this->logger->info('ExecutionKillEndpoint: kill requested', [
            'execution_id' => $executionId,
        ]);

        // ------------------------------------------------------------------
        // 1. Fetch the execution log row
        // ------------------------------------------------------------------

        try {
            $row = $this->fetchExecution($executionId);
        } catch (PDOException $e) {
            $this->logger->error('ExecutionKillEndpoint: database error fetching execution', [
                'execution_id' => $executionId,
                'message'      => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to retrieve execution record.',
                'code'    => 500,
            ]);
            return;
        }

        if ($row === null) {
            jsonResponse(404, [
                'error'   => 'Not Found',
                'message' => sprintf('Execution with ID %d does not exist.', $executionId),
                'code'    => 404,
            ]);
            return;
        }

        // Guard: do not kill already-finished executions
        if ($row['finished_at'] !== null) {
            jsonResponse(409, [
                'error'   => 'Conflict',
                'message' => 'Execution has already finished.',
                'code'    => 409,
            ]);
            return;
        }

        $pid     = $row['pid']     !== null ? (int) $row['pid']          : null;
        $pidFile = $row['pid_file'] !== null ? (string) $row['pid_file'] : null;
        $target  = $row['target']   !== null ? (string) $row['target']   : 'local';

        if ($pid === null && $pidFile === null) {
            jsonResponse(422, [
                'error'   => 'Unprocessable Entity',
                'message' => 'No PID is stored for this execution. The job may have started before the kill feature was deployed.',
                'code'    => 422,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 2. Kill the process
        // ------------------------------------------------------------------

        $killed = false;
        $killError = '';

        if ($target !== 'local' && $pidFile !== null) {
            // Remote (SSH) kill: SSH to target, read PID file, kill process group
            $killed = $this->killRemote($target, $pidFile, $killError);
        } elseif ($pid !== null) {
            // Local kill: send SIGTERM to the process group
            $killed = $this->killLocal($pid, $killError);
        }

        // ------------------------------------------------------------------
        // 3. Mark the execution as finished (exit_code = -2 = killed)
        // ------------------------------------------------------------------

        try {
            $this->markKilled($executionId);
        } catch (PDOException $e) {
            $this->logger->error('ExecutionKillEndpoint: failed to mark execution as killed', [
                'execution_id' => $executionId,
                'message'      => $e->getMessage(),
            ]);
            // Non-fatal: the kill signal was already sent; report partial success
        }

        if (!$killed) {
            $this->logger->warning('ExecutionKillEndpoint: kill signal may not have been delivered', [
                'execution_id' => $executionId,
                'target'       => $target,
                'error'        => $killError,
            ]);
        } else {
            $this->logger->info('ExecutionKillEndpoint: job killed successfully', [
                'execution_id' => $executionId,
                'target'       => $target,
            ]);
        }

        jsonResponse(200, [
            'execution_id' => $executionId,
            'killed'       => $killed,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch a single execution_log row.
     *
     * @param int $executionId Execution log ID.
     *
     * @return array<string, mixed>|null Row or null when not found.
     *
     * @throws PDOException On database errors.
     */
    private function fetchExecution(int $executionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, cronjob_id, started_at, finished_at, target, pid, pid_file
               FROM execution_log
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $executionId]);
        $row = $stmt->fetch();

        return $row !== false ? (array) $row : null;
    }

    /**
     * Send SIGTERM to the process group of a locally-running job.
     *
     * Using a negative PID sends the signal to the entire process group,
     * which kills the bash wrapper and all child processes it spawned.
     *
     * @param int    $pid       OS process ID of the job's bash wrapper.
     * @param string $error     Populated with an error description on failure.
     *
     * @return bool True when the signal was delivered without error.
     */
    private function killLocal(int $pid, string &$error): bool
    {
        $this->logger->debug('ExecutionKillEndpoint: killing local process group', [
            'pid' => $pid,
        ]);

        // posix_kill(-$pid, SIGTERM) sends SIGTERM to the process group
        if (function_exists('posix_kill')) {
            $result = posix_kill(-$pid, SIGTERM);
            if (!$result) {
                // Fall through to exec-based fallback
                $this->logger->debug('ExecutionKillEndpoint: posix_kill failed, trying kill command');
            } else {
                return true;
            }
        }

        // Fallback: shell kill command (works even when posix extension is unavailable)
        $cmd    = 'kill -TERM -' . (int) $pid . ' 2>&1';
        $output = '';
        $exit   = 0;
        exec($cmd, $outputArr, $exit);
        $output = implode("\n", $outputArr);

        if ($exit !== 0) {
            $error = sprintf('kill -TERM -%d failed (exit %d): %s', $pid, $exit, $output);
            return false;
        }

        return true;
    }

    /**
     * Kill a remotely-running job by SSHing to the target host.
     *
     * Reads the PID from the stored PID-file, sends SIGTERM to the remote
     * process group, and removes the PID-file.
     *
     * @param string $sshHost  SSH config host alias from ~/.ssh/config.
     * @param string $pidFile  Absolute path to the PID-file on the remote host.
     * @param string $error    Populated with an error description on failure.
     *
     * @return bool True when the remote kill succeeded.
     */
    private function killRemote(string $sshHost, string $pidFile, string &$error): bool
    {
        // Sanitise the SSH host alias (letters, digits, dots, hyphens, underscores only)
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $sshHost)) {
            $error = sprintf('Invalid SSH host alias: %s', $sshHost);
            return false;
        }

        // Sanitise the PID file path (must start with /tmp/.cmgr_ followed by digits)
        if (!preg_match('#^/tmp/\.cmgr_\d+$#', $pidFile)) {
            $error = sprintf('Invalid PID file path (rejected for safety): %s', $pidFile);
            $this->logger->warning('ExecutionKillEndpoint: rejected unsafe PID file path', [
                'pid_file' => $pidFile,
            ]);
            return false;
        }

        $this->logger->debug('ExecutionKillEndpoint: killing remote process', [
            'ssh_host' => $sshHost,
            'pid_file' => $pidFile,
        ]);

        // Remote command: read PID, kill process group, remove PID file
        // The entire command is a single-quoted string; the PID file path has been
        // sanitised above so it is safe to interpolate directly.
        $remoteCmd = sprintf(
            'if [ -f %s ]; then PID=$(cat %s); kill -TERM -$PID 2>/dev/null; rm -f %s; fi',
            escapeshellarg($pidFile),
            escapeshellarg($pidFile),
            escapeshellarg($pidFile),
        );

        $sshCmd = sprintf(
            'ssh -o BatchMode=yes -o ConnectTimeout=10 %s -- %s 2>&1',
            escapeshellarg($sshHost),
            escapeshellarg($remoteCmd),
        );

        $outputArr = [];
        $exit      = 0;
        exec($sshCmd, $outputArr, $exit);

        if ($exit !== 0) {
            $error = sprintf(
                'SSH kill failed (exit %d): %s',
                $exit,
                implode("\n", $outputArr),
            );
            return false;
        }

        return true;
    }

    /**
     * Mark an execution_log row as killed.
     *
     * Sets finished_at to the current UTC timestamp and exit_code to −2
     * (sentinel value for "killed by operator") and clears the PID columns.
     *
     * @param int $executionId Execution log ID.
     *
     * @return void
     *
     * @throws PDOException On database errors.
     */
    private function markKilled(int $executionId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE execution_log
                SET finished_at = :finished_at,
                    exit_code   = -2,
                    pid         = NULL,
                    pid_file    = NULL,
                    output      = CONCAT(COALESCE(output, \'\'), \'\\n[Job was killed by operator]\')
              WHERE id = :id
                AND finished_at IS NULL'
        );
        $stmt->execute([
            ':finished_at' => date('Y-m-d H:i:s'),
            ':id'          => $executionId,
        ]);
    }
}
