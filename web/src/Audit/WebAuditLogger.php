<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Web Audit Logger
 *
 * Writes audit entries directly to the shared audit_log table for actions
 * that are handled entirely in the web layer (e.g. user role changes,
 * user deletion) and therefore have no agent endpoint to carry the entry.
 *
 * The INSERT logic mirrors agent/src/Audit/AuditLogger.php; both write to
 * the same MariaDB table.  Failures are caught and logged so that an audit
 * write never aborts the main operation.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Audit;

use Monolog\Logger;
use PDO;

/**
 * Class WebAuditLogger
 *
 * Writes one row to audit_log per write operation performed in the web layer.
 */
final class WebAuditLogger
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param PDO    $pdo       Database connection (same MariaDB as the agent).
     * @param Logger $logger    Monolog logger (for error reporting only).
     * @param int    $userId    Acting user ID (from session).
     * @param string $username  Acting username (from session).
     * @param string $ipAddress Client IP address.
     */
    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $logger,
        private readonly int    $userId,
        private readonly string $username,
        private readonly string $ipAddress,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Record a write operation in the audit log.
     *
     * @param string      $action        Dot-notation action key, e.g. 'user.update_role'.
     * @param string|null $resourceType  Resource type, e.g. 'user'.
     * @param int|null    $resourceId    Primary key of the affected resource.
     * @param string|null $resourceLabel Human-readable label at the time of the action.
     * @param array|null  $details       Optional key/value context (JSON-encoded).
     */
    public function log(
        string  $action,
        ?string $resourceType  = null,
        ?int    $resourceId    = null,
        ?string $resourceLabel = null,
        ?array  $details       = null,
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO audit_log
                    (user_id, username, action, resource_type, resource_id, resource_label, details, ip_address)
                 VALUES
                    (:user_id, :username, :action, :resource_type, :resource_id, :resource_label, :details, :ip_address)'
            );

            $stmt->execute([
                ':user_id'        => $this->userId,
                ':username'       => $this->username,
                ':action'         => $action,
                ':resource_type'  => $resourceType,
                ':resource_id'    => $resourceId,
                ':resource_label' => $resourceLabel,
                ':details'        => $details !== null
                    ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                ':ip_address'     => $this->ipAddress,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('WebAuditLogger: failed to write audit entry', [
                'action'  => $action,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
