<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – TagDeleteEndpoint
 *
 * Handles DELETE /tags/{id} requests.
 *
 * Deletes a tag by its numeric ID. Because the `cronjob_tags` junction table
 * has a CASCADE DELETE foreign key constraint referencing `tags.id`, all
 * associations between the tag and individual cron jobs are automatically
 * removed by the database when the parent row is deleted.
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
 * Class TagDeleteEndpoint
 *
 * Handles DELETE /tags/{id} API requests.
 *
 * Response on success (HTTP 200):
 * ```json
 * {"message": "Tag deleted", "id": 3, "name": "backup"}
 * ```
 *
 * Response on not found (HTTP 404):
 * ```json
 * {"error": "Not Found", "message": "Tag with ID 3 does not exist.", "code": 404}
 * ```
 */
final class TagDeleteEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * TagDeleteEndpoint constructor.
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
     * Handle an incoming DELETE /tags/{id} request.
     *
     * Validates the path parameter, looks up the tag, deletes it (with
     * cascading removal of junction rows), and emits the appropriate JSON
     * response.
     *
     * @param array<string, string> $params Path parameters extracted by the Router.
     *                                      Expected key: 'id' (string representation of an int).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        // ------------------------------------------------------------------
        // 1. Validate path parameter
        // ------------------------------------------------------------------

        $rawId = $params['id'] ?? '';

        if ($rawId === '' || !ctype_digit($rawId) || (int) $rawId <= 0) {
            $this->logger->warning('TagDeleteEndpoint: invalid or missing id parameter', [
                'raw_id' => $rawId,
            ]);
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Path parameter {id} must be a positive integer.',
                'code'    => 400,
            ]);
            return;
        }

        $tagId = (int) $rawId;

        $this->logger->debug('TagDeleteEndpoint: handling DELETE /tags/{id}', [
            'tag_id' => $tagId,
        ]);

        // ------------------------------------------------------------------
        // 2. Fetch tag and delete
        // ------------------------------------------------------------------

        try {
            $result = $this->deleteTag($tagId);
        } catch (PDOException $e) {
            $this->logger->error('TagDeleteEndpoint: database error', [
                'tag_id'  => $tagId,
                'message' => $e->getMessage(),
            ]);

            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to delete tag.',
                'code'    => 500,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 3. Handle not-found case
        // ------------------------------------------------------------------

        if ($result === null) {
            $this->logger->info('TagDeleteEndpoint: tag not found', ['tag_id' => $tagId]);
            jsonResponse(404, [
                'error'   => 'Not Found',
                'message' => sprintf('Tag with ID %d does not exist.', $tagId),
                'code'    => 404,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 4. Return success
        // ------------------------------------------------------------------

        $this->logger->info('TagDeleteEndpoint: tag deleted', [
            'id'   => $result['id'],
            'name' => $result['name'],
        ]);

        jsonResponse(200, [
            'message' => 'Tag deleted',
            'id'      => $result['id'],
            'name'    => $result['name'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Look up the tag by ID, delete it if found, and return its data.
     *
     * The cascade DELETE on the `cronjob_tags` foreign key takes care of
     * removing all junction rows automatically.
     *
     * Returns null when no tag with the given ID is found.
     *
     * @param int $tagId The numeric tag ID to delete.
     *
     * @return array<string, mixed>|null Deleted tag data, or null if not found.
     *
     * @throws PDOException On database errors.
     */
    private function deleteTag(int $tagId): ?array
    {
        // Fetch the tag first so we can include its name in the response
        $fetchStmt = $this->pdo->prepare(
            'SELECT id, name FROM tags WHERE id = :id LIMIT 1'
        );
        $fetchStmt->execute([':id' => $tagId]);
        $row = $fetchStmt->fetch();

        if ($row === false) {
            return null; // Not found
        }

        $tag = [
            'id'   => (int)    $row['id'],
            'name' => (string) $row['name'],
        ];

        // Delete the tag; cascade handles cronjob_tags rows
        $deleteStmt = $this->pdo->prepare(
            'DELETE FROM tags WHERE id = :id'
        );
        $deleteStmt->execute([':id' => $tagId]);

        return $tag;
    }
}
