-- =============================================================================
-- Migration 010 – notify_on_recovery
--
-- Adds the notify_on_recovery column to cronjobs.
-- When enabled, a recovery notification is sent the first time a job succeeds
-- after a failure streak that triggered a failure alert.
--
-- DEFAULT 0: existing jobs keep recovery notifications disabled after upgrade.
-- Users who want recovery alerts must enable the option explicitly per job.
-- New jobs created after v2.10.0 default to enabled when notify_on_failure is on.
--
-- @author  Christian Schulz <technik@meinetechnikwelt.rocks>
-- @license GNU General Public License version 3 or later
-- =============================================================================

ALTER TABLE cronjobs
    ADD COLUMN notify_on_recovery TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = send recovery notification when job succeeds after a notified failure streak'
        AFTER notify_on_failure;
