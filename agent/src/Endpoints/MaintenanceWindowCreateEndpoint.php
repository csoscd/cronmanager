<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MaintenanceWindowCreateEndpoint
 *
 * Handles POST /maintenance/windows requests.
 *
 * Request body (JSON):
 * ```json
 * {
 *   "target":           "iom",
 *   "cron_schedule":    "0 2 * * *",
 *   "duration_minutes": 60,
 *   "description":      "Nightly backup window",
 *   "active":           true
 * }
 * ```
 *
 * Response on success (HTTP 201):
 * ```json
 * { "id": 1, "target": "iom", ... }
 * ```
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cron\CronExpression;
use Cronmanager\Agent\Repository\MaintenanceWindowRepository;
use Monolog\Logger;
use PDOException;

/**
 * Class MaintenanceWindowCreateEndpoint
 */
final class MaintenanceWindowCreateEndpoint
{
    /**
     * @param MaintenanceWindowRepository $repo   Window repository.
     * @param Logger                      $logger Monolog logger instance.
     */
    public function __construct(
        private readonly MaintenanceWindowRepository $repo,
        private readonly Logger                      $logger,
    ) {}

    /**
     * @param array<string, string> $params Path parameters (unused).
     */
    public function handle(array $params): void
    {
        $this->logger->debug('MaintenanceWindowCreateEndpoint: handling POST /maintenance/windows');

        $rawBody = (string) file_get_contents('php://input');
        $body    = json_decode($rawBody, true);

        if (!is_array($body)) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Request body must be valid JSON.',
                'code'    => 400,
            ]);
            return;
        }

        $errors = $this->validate($body);

        if ($errors !== []) {
            jsonResponse(422, [
                'error'  => 'Validation failed',
                'fields' => $errors,
            ]);
            return;
        }

        $target          = trim((string) $body['target']);
        $cronSchedule    = trim((string) $body['cron_schedule']);
        $durationMinutes = (int) $body['duration_minutes'];
        $description     = isset($body['description']) && $body['description'] !== ''
            ? (string) $body['description']
            : null;
        $active = !isset($body['active']) || (bool) $body['active'];

        try {
            $id  = $this->repo->create($target, $cronSchedule, $durationMinutes, $description, $active);
            $row = $this->repo->findById($id);
        } catch (PDOException $e) {
            $this->logger->error('MaintenanceWindowCreateEndpoint: database error', [
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to create maintenance window.',
                'code'    => 500,
            ]);
            return;
        }

        $this->logger->info('MaintenanceWindowCreateEndpoint: window created', [
            'id'     => $id,
            'target' => $target,
        ]);

        jsonResponse(201, [
            'id'               => (int) $row['id'],
            'target'           => (string) $row['target'],
            'cron_schedule'    => (string) $row['cron_schedule'],
            'duration_minutes' => (int) $row['duration_minutes'],
            'description'      => $row['description'],
            'active'           => (bool) $row['active'],
            'created_at'       => (string) $row['created_at'],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, string>
     */
    private function validate(array $body): array
    {
        $errors = [];

        if (!isset($body['target']) || !is_string($body['target']) || trim($body['target']) === '') {
            $errors['target'] = 'Required non-empty string.';
        }

        if (!isset($body['cron_schedule']) || !is_string($body['cron_schedule']) || trim($body['cron_schedule']) === '') {
            $errors['cron_schedule'] = 'Required non-empty string.';
        } elseif (!CronExpression::isValidExpression(trim($body['cron_schedule']))) {
            $errors['cron_schedule'] = 'Invalid cron expression.';
        }

        if (!isset($body['duration_minutes'])) {
            $errors['duration_minutes'] = 'Required integer > 0.';
        } elseif (!is_int($body['duration_minutes']) || $body['duration_minutes'] <= 0) {
            $errors['duration_minutes'] = 'Must be a positive integer.';
        }

        return $errors;
    }
}
