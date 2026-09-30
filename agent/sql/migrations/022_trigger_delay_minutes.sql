-- Migration 022: Trigger delay for 'triggered_by' dependencies
--
-- Adds trigger_delay_minutes to job_dependencies so that 'triggered_by' jobs
-- can be scheduled N minutes after the predecessor finishes instead of at the
-- next cron-minute.  Default 0 = existing behaviour (fire at the next minute).

ALTER TABLE job_dependencies
    ADD COLUMN trigger_delay_minutes INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Minutes to wait before scheduling the triggered job; 0 = next cron-minute (default)'
        AFTER max_age_minutes;
