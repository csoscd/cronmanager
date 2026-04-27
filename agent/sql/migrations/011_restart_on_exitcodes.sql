-- =============================================================================
-- Migration 011 – restart_on_exitcodes
--
-- Adds per-job exit-code filter for automatic restart triggering.
-- When NULL (default), any non-zero exit code triggers a retry — preserving
-- the original behaviour for all existing jobs.
-- When set to a comma-separated expression such as "1-5,10,255", only those
-- exit codes will cause the retry logic to fire.
--
-- @author  Christian Schulz <technik@meinetechnikwelt.rocks>
-- @license GNU General Public License version 3 or later
-- =============================================================================

ALTER TABLE cronjobs
    ADD COLUMN restart_on_exitcodes VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Exit-code expression that triggers auto-retry, e.g. "1-5,10,255". NULL = any non-zero.'
        AFTER retry_delay_minutes;
