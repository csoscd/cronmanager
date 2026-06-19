<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – CronBulkTagEndpoint
 *
 * Handles POST /crons/bulk/tag requests to add or remove a tag on
 * multiple cron jobs in a single operation.
 *
 * Request body:
 *   {"ids": [1, 4, 7], "tag": "backup", "action": "add"}
 *   {"ids": [1, 4, 7], "tag": "backup", "action": "remove"}
 *
 * Response on success (HTTP 200):
 *   {"updated": 3}
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Monolog\Logger;
use PDO;
use PDOException;

/**
 * Class CronBulkTagEndpoint
 *
 * Adds or removes a single named tag from all selected jobs.
 * When adding, the tag row is created via INSERT IGNORE if it does not exist.
 * When removing, the cronjob_tags link is deleted; orphaned tag rows are
 * left in place (they are cleaned up by the housekeeping endpoint).
 */
final class CronBulkTagEndpoint
{
    /**
     * @param PDO    $pdo    Active PDO connection.
     * @param Logger $logger Monolog logger.
     */
    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $logger,
    ) {}

    /**
     * Handle POST /crons/bulk/tag.
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $body = json_decode((string) file_get_contents('php://input'), associative: true) ?? [];

        // ── Validate ──────────────────────────────────────────────────────────
        $ids    = isset($body['ids'])    && is_array($body['ids'])    ? $body['ids']           : [];
        $tag    = isset($body['tag'])    && is_string($body['tag'])   ? trim($body['tag'])      : '';
        $action = isset($body['action']) && is_string($body['action']) ? $body['action']         : '';

        if ($ids === []) {
            jsonResponse(400, ['error' => 'Bad Request', 'message' => 'ids must be a non-empty array.']);
            return;
        }

        if ($tag === '') {
            jsonResponse(400, ['error' => 'Bad Request', 'message' => 'tag must be a non-empty string.']);
            return;
        }

        if (!in_array($action, ['add', 'remove'], true)) {
            jsonResponse(400, ['error' => 'Bad Request', 'message' => 'action must be "add" or "remove".']);
            return;
        }

        $intIds = array_values(array_unique(array_map('intval', $ids)));
        foreach ($intIds as $id) {
            if ($id <= 0) {
                jsonResponse(400, ['error' => 'Bad Request', 'message' => 'All IDs must be positive integers.']);
                return;
            }
        }

        $placeholders = implode(',', array_fill(0, count($intIds), '?'));

        // Verify all IDs exist — $placeholders contains only literal '?' characters; values bound via execute().
        // nosemgrep: php.lang.security.injection.tainted-callable.tainted-callable
        $existsStmt = $this->pdo->prepare("SELECT COUNT(*) FROM cronjobs WHERE id IN ({$placeholders})");
        $existsStmt->execute($intIds);
        if ((int) $existsStmt->fetchColumn() !== count($intIds)) {
            jsonResponse(404, ['error' => 'Not Found', 'message' => 'One or more job IDs do not exist.']);
            return;
        }

        // ── Apply tag operation ───────────────────────────────────────────────
        try {
            $this->pdo->beginTransaction();

            if ($action === 'add') {
                // Ensure the tag row exists
                $this->pdo->prepare('INSERT IGNORE INTO tags (name) VALUES (:name)')
                    ->execute([':name' => $tag]);

                $tagIdStmt = $this->pdo->prepare('SELECT id FROM tags WHERE name = :name');
                $tagIdStmt->execute([':name' => $tag]);
                $tagId = (int) $tagIdStmt->fetchColumn();

                if ($tagId <= 0) {
                    throw new \RuntimeException('Failed to resolve tag ID after insert.');
                }

                $linkStmt = $this->pdo->prepare(
                    'INSERT IGNORE INTO cronjob_tags (cronjob_id, tag_id) VALUES (:job_id, :tag_id)'
                );
                foreach ($intIds as $jobId) {
                    $linkStmt->execute([':job_id' => $jobId, ':tag_id' => $tagId]);
                }
            } else {
                // Remove the link; leave orphaned tag rows for housekeeping
                $tagIdStmt = $this->pdo->prepare('SELECT id FROM tags WHERE name = :name');
                $tagIdStmt->execute([':name' => $tag]);
                $tagId = (int) $tagIdStmt->fetchColumn();

                if ($tagId > 0) {
                    $unlinkParams = array_merge([$tagId], $intIds);
                    $this->pdo->prepare(
                        // nosemgrep: php.lang.security.injection.tainted-callable.tainted-callable
                        "DELETE FROM cronjob_tags WHERE tag_id = ? AND cronjob_id IN ({$placeholders})"
                    )->execute($unlinkParams);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->logger->error('CronBulkTagEndpoint: transaction failed', ['message' => $e->getMessage()]);
            jsonResponse(500, ['error' => 'Internal Server Error', 'message' => 'Bulk tag update failed.']);
            return;
        }

        $this->logger->info('CronBulkTagEndpoint: updated', ['ids' => $intIds, 'tag' => $tag, 'action' => $action]);

        jsonResponse(200, ['updated' => count($intIds)]);
    }
}
