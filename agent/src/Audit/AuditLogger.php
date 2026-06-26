<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – Audit Logger
 *
 * Records every write operation to the audit_log table.
 * User identity (userId, username) and client IP are supplied at construction
 * time from the HMAC-validated request headers so they are trusted.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Audit;

use Monolog\Logger;
use PDO;

/**
 * Class AuditLogger
 *
 * Writes one row to audit_log per write operation.  All failures are caught
 * and logged – a logging failure must never abort the main operation.
 */
final class AuditLogger
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param PDO    $pdo       Agent database connection.
     * @param Logger $logger    Monolog logger (for error reporting).
     * @param int    $userId    Acting user ID (from X-User-Id header, 0 = system).
     * @param string $username  Acting username (from X-User-Name header).
     * @param string $ipAddress Client IP address (from REMOTE_ADDR).
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
     * @param string      $action        Dot-notation action key, e.g. 'cron.create'.
     * @param string|null $resourceType  Resource type, e.g. 'cron', 'tag'.
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
            // Audit failure must never abort the main operation
            $this->logger->error('AuditLogger: failed to write audit entry', [
                'action'  => $action,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Maintenance
    // -------------------------------------------------------------------------

    /**
     * Delete audit entries older than $retentionDays days.
     *
     * @param int $retentionDays Number of days to keep (0 = delete everything).
     */
    public function prune(int $retentionDays): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)'
            );
            $stmt->execute([':days' => max(0, $retentionDays)]);

            $deleted = $stmt->rowCount();

            if ($deleted > 0) {
                $this->logger->info('AuditLogger: pruned old audit entries', [
                    'deleted'        => $deleted,
                    'retention_days' => $retentionDays,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('AuditLogger: pruning failed', ['message' => $e->getMessage()]);
        }
    }
}
