-- =============================================================================
-- Cronmanager – Migration 004: Kill Running Jobs + Execution Limits
--
-- Adds columns needed for the "Kill Running Jobs" and "Execution Limit"
-- features introduced in v2.3.0.
--
-- execution_log changes:
--   pid                  – local OS PID of the running job process (NULL = not tracked)
--   pid_file             – path on the remote host to a PID file (NULL = local job)
--   notified_limit_exceeded – prevents sending duplicate limit-exceeded notifications
--
-- cronjobs changes:
--   execution_limit_seconds – maximum allowed runtime in seconds (NULL = no limit)
--   auto_kill_on_limit      – automatically kill the process when the limit is exceeded
--
-- Safe to run on an already-migrated database (ALTER IGNORE / column guards not
-- available in all MariaDB versions, so check via information_schema first).
--
-- @author  Christian Schulz <technik@meinetechnikwelt.rocks>
-- @license GNU General Public License version 3 or later
-- =============================================================================

-- execution_log: PID tracking columns for the kill feature
ALTER TABLE execution_log
    ADD COLUMN IF NOT EXISTS pid                     INT UNSIGNED NULL
        COMMENT 'OS PID of the running job process on the local host; NULL when not tracked'
        AFTER target,
    ADD COLUMN IF NOT EXISTS pid_file                VARCHAR(255) NULL
        COMMENT 'Path to the PID file on the remote host (SSH targets only); NULL for local jobs'
        AFTER pid,
    ADD COLUMN IF NOT EXISTS notified_limit_exceeded TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = limit-exceeded notification already sent for this execution; prevents duplicate alerts'
        AFTER pid_file;

-- cronjobs: execution limit columns
ALTER TABLE cronjobs
    ADD COLUMN IF NOT EXISTS execution_limit_seconds INT UNSIGNED NULL
        COMMENT 'Maximum allowed runtime in seconds; NULL means no limit is enforced'
        AFTER notify_on_failure,
    ADD COLUMN IF NOT EXISTS auto_kill_on_limit       TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = automatically kill the process when execution_limit_seconds is exceeded'
        AFTER execution_limit_seconds;
