-- Migration 020: Acknowledge failed executions
--
-- Adds acknowledge tracking columns to execution_log.
-- A NULL acknowledged_at means the execution is unacknowledged (default state).
-- When set, the failure is suppressed from dashboard error indicators.

ALTER TABLE execution_log
    ADD COLUMN acknowledged_at         DATETIME      NULL DEFAULT NULL AFTER output,
    ADD COLUMN acknowledged_by_user_id INT           NULL DEFAULT NULL AFTER acknowledged_at;
