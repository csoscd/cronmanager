-- =============================================================================
-- Migration 009 – notify_after_limit_exceeded
--
-- Adds the notify_after_limit_exceeded column to cronjobs.
-- Controls how many consecutive limit-exceedings must occur before a
-- limit-exceeded notification is dispatched.  Default 1 preserves existing
-- behaviour (notify on every limit exceeding).
--
-- @author  Christian Schulz <technik@meinetechnikwelt.rocks>
-- @license GNU General Public License version 3 or later
-- =============================================================================

ALTER TABLE cronjobs
    ADD COLUMN notify_after_limit_exceeded TINYINT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'Send limit-exceeded notification only after this many consecutive limit exceedings; 1 = every time (default)';
