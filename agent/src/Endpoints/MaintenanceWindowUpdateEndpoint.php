<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MaintenanceWindowUpdateEndpoint
 *
 * Handles PUT /maintenance/windows/{id} requests.
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
 * Class MaintenanceWindowUpdateEndpoint
 */
final class MaintenanceWindowUpdateEndpoint
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
     * @param array<string, string> $params Path parameters – expects 'id'.
     */
    public function handle(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;

        if ($id <= 0) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Invalid window ID.',
                'code'    => 400,
            ]);
            return;
        }

        $this->logger->debug('MaintenanceWindowUpdateEndpoint: handling PUT /maintenance/windows/{id}', [
            'id' => $id,
        ]);

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
            $updated = $this->repo->update($id, $target, $cronSchedule, $durationMinutes, $description, $active);
        } catch (PDOException $e) {
            $this->logger->error('MaintenanceWindowUpdateEndpoint: database error', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);
            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to update maintenance window.',
                'code'    => 500,
            ]);
            return;
        }

        if (!$updated) {
            jsonResponse(404, [
                'error'   => 'Not Found',
                'message' => sprintf('Maintenance window %d does not exist.', $id),
                'code'    => 404,
            ]);
            return;
        }

        $this->logger->info('MaintenanceWindowUpdateEndpoint: window updated', ['id' => $id]);

        $row = $this->repo->findById($id);

        jsonResponse(200, [
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
