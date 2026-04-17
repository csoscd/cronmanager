-- =============================================================================
-- Migration 008 – notify_after_failures
--
-- Adds the notify_after_failures column to cronjobs.
-- Controls how many consecutive failures must occur before a failure
-- notification is dispatched.  Default 1 preserves existing behaviour.
--
-- @author  Christian Schulz <technik@meinetechnikwelt.rocks>
-- @license GNU General Public License version 3 or later
-- =============================================================================

ALTER TABLE cronjobs
    ADD COLUMN notify_after_failures TINYINT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'Send failure notification only after this many consecutive real failures; 1 = every failure (default)';
