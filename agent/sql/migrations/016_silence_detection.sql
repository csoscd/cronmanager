-- Migration 016: Silence Detection
-- Adds three columns to cronjobs to support per-job "silence" alerting:
--   notify_on_silence     – opt-in flag; when 1 an alert is sent when the job has
--                           not started within its expected schedule window.
--   silence_grace_minutes – per-job override for the global silence.grace_minutes
--                           config value; NULL means "use the global default".
--   last_silence_alert_at – timestamp of the most recent silence alert; reset to
--                           NULL by ExecutionStartEndpoint when the job starts again.
--                           Used to de-duplicate repeated alerts (max once per hour).

ALTER TABLE cronjobs
    ADD COLUMN notify_on_silence     TINYINT(1)   NOT NULL DEFAULT 0   AFTER notify_on_recovery,
    ADD COLUMN silence_grace_minutes INT UNSIGNED NULL     DEFAULT NULL AFTER notify_on_silence,
    ADD COLUMN last_silence_alert_at DATETIME     NULL     DEFAULT NULL AFTER silence_grace_minutes;
