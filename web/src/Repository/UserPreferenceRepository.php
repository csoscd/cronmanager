<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – User Preference Repository
 *
 * Reads and writes per-user UI preferences that are persisted in the `users`
 * table of the MariaDB database.  Currently manages:
 *   - cron_list_page_size: number of jobs shown per page on the cron list (0 = all)
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Repository;

use PDO;
use PDOException;

/**
 * Class UserPreferenceRepository
 *
 * All methods operate on a single user identified by their integer primary key.
 * The repository is intentionally narrow – add additional preference methods
 * here as the application grows rather than scattering DB calls across controllers.
 */
final class UserPreferenceRepository
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /**
     * Allowed values for the cron list page size.
     * 0 is the sentinel meaning "show all".
     *
     * @var int[]
     */
    public const ALLOWED_PAGE_SIZES = [0, 10, 25, 50];

    /**
     * Default page size used when no preference is stored.
     *
     * @var int
     */
    public const DEFAULT_PAGE_SIZE = 25;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param PDO $pdo Active PDO database connection.
     */
    public function __construct(private readonly PDO $pdo) {}

    // -------------------------------------------------------------------------
    // Cron list page size
    // -------------------------------------------------------------------------

    /**
     * Return the stored cron list page size for a user.
     *
     * Returns $default when no preference has been saved yet (NULL in DB).
     *
     * @param int $userId  User primary key.
     * @param int $default Fallback value when no preference is stored.
     *
     * @return int Page size (0 means "show all").
     *
     * @throws PDOException On database errors.
     */
    public function getCronListPageSize(int $userId, int $default = self::DEFAULT_PAGE_SIZE): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT cron_list_page_size FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $val = $stmt->fetchColumn();

        // NULL (never set) or missing row → return the default
        if ($val === false || $val === null) {
            return $default;
        }

        return (int) $val;
    }

    /**
     * Persist the cron list page size for a user.
     *
     * Silently ignores values not in ALLOWED_PAGE_SIZES to prevent storing
     * arbitrary integers from query-string manipulation.
     *
     * @param int $userId   User primary key.
     * @param int $pageSize Desired page size (must be in ALLOWED_PAGE_SIZES).
     *
     * @return void
     *
     * @throws PDOException On database errors.
     */
    public function setCronListPageSize(int $userId, int $pageSize): void
    {
        if (!in_array($pageSize, self::ALLOWED_PAGE_SIZES, strict: true)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users SET cron_list_page_size = :size WHERE id = :id'
        );
        $stmt->execute([':size' => $pageSize, ':id' => $userId]);
    }
}
