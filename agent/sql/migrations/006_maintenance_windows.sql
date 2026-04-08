-- =============================================================================
-- Cronmanager – Migration 006: Maintenance Windows
--
-- Adds a maintenance_windows table for per-target scheduled maintenance
-- windows.  During an active window the agent either skips or silently
-- executes jobs depending on the per-job run_in_maintenance flag.
--
-- New sentinel exit code:  -4 = skipped due to maintenance window.
--
-- @author  Christian Schulz <technik@meinetechnikwelt.rocks>
-- @license GNU General Public License version 3 or later
-- =============================================================================

-- -----------------------------------------------------------------------------
-- maintenance_windows – scheduled maintenance windows per target
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS maintenance_windows (
    id               INT          AUTO_INCREMENT PRIMARY KEY,
    target           VARCHAR(255) NOT NULL
                                  COMMENT '"local" or SSH host alias from ~/.ssh/config',
    cron_schedule    VARCHAR(100) NOT NULL
                                  COMMENT 'Standard 5-field cron expression for window start',
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60
                                  COMMENT 'Length of the maintenance window in minutes',
    description      VARCHAR(255)
                                  COMMENT 'Human-readable description of this window',
    active           TINYINT(1)   NOT NULL DEFAULT 1
                                  COMMENT '1 = window is evaluated, 0 = disabled',
    created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- cronjobs: add run_in_maintenance flag
-- -----------------------------------------------------------------------------
ALTER TABLE cronjobs
    ADD COLUMN IF NOT EXISTS run_in_maintenance TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = execute during maintenance window (failures suppressed); 0 = skip (exit -4)'
        AFTER singleton;

-- -----------------------------------------------------------------------------
-- execution_log: add during_maintenance flag
-- -----------------------------------------------------------------------------
ALTER TABLE execution_log
    ADD COLUMN IF NOT EXISTS during_maintenance TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = this execution occurred during a maintenance window'
        AFTER notified_limit_exceeded;
