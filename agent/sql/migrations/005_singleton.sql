-- =============================================================================
-- Cronmanager – Migration 005: Singleton Job Mode
--
-- Adds the singleton flag to the cronjobs table.  When singleton = 1 the
-- agent will reject a new execution start request (HTTP 409) if a previous
-- instance of the same job is still running (finished_at IS NULL).
--
-- @author  Christian Schulz <technik@meinetechnikwelt.rocks>
-- @license GNU General Public License version 3 or later
-- =============================================================================

ALTER TABLE cronjobs
    ADD COLUMN IF NOT EXISTS singleton TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = only one instance may run at a time; new executions are skipped while one is already running'
        AFTER auto_kill_on_limit;
