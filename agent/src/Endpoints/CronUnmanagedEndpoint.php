<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – CronUnmanagedEndpoint
 *
 * Handles GET /crons/unmanaged requests.
 *
 * Returns all crontab entries for a given Linux user that are NOT managed by
 * Cronmanager (i.e. lack a "# cronmanager:{id}" marker comment). This enables
 * the web UI to offer an import workflow that converts existing plain crontab
 * entries into Cronmanager-managed jobs.
 *
 * Query parameters:
 *   user   (string, required) – Linux user name whose crontab to inspect.
 *   target (string, optional) – SSH host alias. When present and not "local",
 *                               the crontab is read from the remote host.
 *
 * Response on success (HTTP 200):
 * ```json
 * {
 *   "user":  "deploy",
 *   "data":  [
 *     {"schedule": "* /5 * * * *", "command": "/opt/scripts/backup.sh"},
 *     {"schedule": "0 3 * * *",    "command": "/opt/scripts/clean.sh"}
 *   ],
 *   "count": 2
 * }
 * ```
 *
 * Error responses:
 *   400 – missing `user` query parameter
 *   422 – invalid user name (rejected by CrontabManager validation)
 *   500 – unexpected server error
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Cron\CrontabManager;
use InvalidArgumentException;
use Monolog\Logger;

/**
 * Class CronUnmanagedEndpoint
 *
 * Delegates to {@see CrontabManager::getUnmanagedEntries()} and wraps the
 * result in the standard agent JSON envelope.
 */
final class CronUnmanagedEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * CronUnmanagedEndpoint constructor.
     *
     * @param Logger         $logger         Monolog logger instance.
     * @param CrontabManager $crontabManager CrontabManager for reading crontabs.
     */
    public function __construct(
        private readonly Logger         $logger,
        private readonly CrontabManager $crontabManager,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming GET /crons/unmanaged request.
     *
     * Reads the required `user` query parameter, delegates to
     * {@see CrontabManager::getUnmanagedEntries()}, and emits a JSON response
     * via the global jsonResponse() function defined in agent.php.
     *
     * @param array<string, string> $params Path parameters extracted by the Router
     *                                      (unused for this endpoint).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        // ------------------------------------------------------------------
        // Require the `user` query parameter
        // ------------------------------------------------------------------
        $user = isset($_GET['user']) && $_GET['user'] !== '' ? (string) $_GET['user'] : null;

        if ($user === null) {
            $this->logger->warning('CronUnmanagedEndpoint: missing required query parameter "user"');
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Query parameter "user" is required.',
                'code'    => 400,
            ]);
            return;
        }

        // Resolve optional target; treat absent / "local" as local execution
        $target = (isset($_GET['target']) && $_GET['target'] !== '' && $_GET['target'] !== 'local')
            ? (string) $_GET['target']
            : null;

        $this->logger->debug('CronUnmanagedEndpoint: handling GET /crons/unmanaged', [
            'user'   => $user,
            'target' => $target ?? 'local',
        ]);

        // ------------------------------------------------------------------
        // Delegate to CrontabManager (local or remote)
        // ------------------------------------------------------------------
        try {
            $entries = ($target !== null)
                ? $this->crontabManager->getRemoteUnmanagedEntries($target, $user)
                : $this->crontabManager->getUnmanagedEntries($user);
        } catch (InvalidArgumentException $e) {
            $this->logger->warning('CronUnmanagedEndpoint: invalid user name', [
                'user'    => $user,
                'message' => $e->getMessage(),
            ]);
            jsonResponse(422, [
                'error'   => 'Unprocessable Entity',
                'message' => $e->getMessage(),
                'code'    => 422,
            ]);
            return;
        } catch (\Throwable $e) {
            $this->logger->error('CronUnmanagedEndpoint: unexpected error', [
                'user'      => $user,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to read crontab for the specified user.',
                'code'    => 500,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // Emit successful response
        // ------------------------------------------------------------------
        jsonResponse(200, [
            'user'  => $user,
            'data'  => $entries,
            'count' => count($entries),
        ]);
    }
}
