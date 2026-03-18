<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – TagCreateEndpoint
 *
 * Handles POST /tags requests.
 *
 * Creates a new tag after validating the request body and checking for name
 * uniqueness. Tag names are restricted to alphanumeric characters, hyphens,
 * and underscores to keep them safe for use as URL segments and crontab
 * comments without further escaping.
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
 * Class TagCreateEndpoint
 *
 * Handles POST /tags API requests.
 *
 * Expected request body (JSON):
 * ```json
 * {"name": "backup"}
 * ```
 *
 * Validation rules:
 *   - `name`: required, non-empty string, max 64 characters, pattern [a-zA-Z0-9_-]
 *
 * Response on success (HTTP 201):
 * ```json
 * {"id": 5, "name": "backup", "job_count": 0}
 * ```
 *
 * Response on conflict (HTTP 409):
 * ```json
 * {"error": "Tag already exists", "name": "backup"}
 * ```
 */
final class TagCreateEndpoint
{
    /** Maximum allowed length for a tag name. */
    private const MAX_NAME_LENGTH = 64;

    /** Pattern that every character in a tag name must satisfy. */
    private const NAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * TagCreateEndpoint constructor.
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
     * Handle an incoming POST /tags request.
     *
     * Parses and validates the JSON body, checks for duplicate names, inserts
     * the new tag, and emits the created record as a JSON response.
     *
     * @param array<string, string> $params Path parameters extracted by the Router
     *                                      (unused for this endpoint).
     *
     * @return void
     */
    public function handle(array $params): void
    {
        $this->logger->debug('TagCreateEndpoint: handling POST /tags');

        // ------------------------------------------------------------------
        // 1. Parse request body
        // ------------------------------------------------------------------

        $rawBody = (string) file_get_contents('php://input');
        $body    = json_decode($rawBody, true);

        if (!is_array($body)) {
            $this->logger->warning('TagCreateEndpoint: invalid JSON body');
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Request body must be a valid JSON object.',
                'code'    => 400,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 2. Validate the `name` field
        // ------------------------------------------------------------------

        $name = isset($body['name']) ? trim((string) $body['name']) : '';

        if ($name === '') {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Field "name" is required and must not be empty.',
                'code'    => 400,
            ]);
            return;
        }

        if (strlen($name) > self::MAX_NAME_LENGTH) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => sprintf('Field "name" must not exceed %d characters.', self::MAX_NAME_LENGTH),
                'code'    => 400,
            ]);
            return;
        }

        if (!preg_match(self::NAME_PATTERN, $name)) {
            jsonResponse(400, [
                'error'   => 'Bad Request',
                'message' => 'Field "name" may only contain letters, digits, hyphens (-) and underscores (_).',
                'code'    => 400,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 3. Check for duplicate name and insert
        // ------------------------------------------------------------------

        try {
            $result = $this->createTag($name);
        } catch (PDOException $e) {
            $this->logger->error('TagCreateEndpoint: database error', [
                'message' => $e->getMessage(),
            ]);

            jsonResponse(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Failed to create tag.',
                'code'    => 500,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 4. Emit response
        // ------------------------------------------------------------------

        if ($result === null) {
            // Tag with this name already exists
            $this->logger->info('TagCreateEndpoint: duplicate tag name', ['name' => $name]);
            jsonResponse(409, [
                'error' => 'Tag already exists',
                'name'  => $name,
            ]);
            return;
        }

        $this->logger->info('TagCreateEndpoint: tag created', ['id' => $result['id'], 'name' => $name]);
        jsonResponse(201, $result);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Check for an existing tag with the given name and insert a new one if
     * none exists.
     *
     * Returns the newly created tag record on success, or null if the name is
     * already taken.
     *
     * @param string $name Validated tag name.
     *
     * @return array<string, mixed>|null Created tag record, or null on conflict.
     *
     * @throws PDOException On database errors.
     */
    private function createTag(string $name): ?array
    {
        // Check for duplicate
        $checkStmt = $this->pdo->prepare(
            'SELECT id FROM tags WHERE name = :name LIMIT 1'
        );
        $checkStmt->execute([':name' => $name]);

        if ($checkStmt->fetch() !== false) {
            return null; // Conflict
        }

        // Insert the new tag
        $insertStmt = $this->pdo->prepare(
            'INSERT INTO tags (name) VALUES (:name)'
        );
        $insertStmt->execute([':name' => $name]);

        $newId = (int) $this->pdo->lastInsertId();

        return [
            'id'        => $newId,
            'name'      => $name,
            'job_count' => 0,
        ];
    }
}
