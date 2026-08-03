-- Migration 018: Last-execution reference columns (v4.7.0)
--
-- GET /crons previously resolved "last run" and "last exit code" through a
-- derived-table aggregate (MAX(id) GROUP BY cronjob_id) over the complete
-- execution_log on every request. That cost scales with the total history
-- size (hundreds of thousands of rows), not with the number of jobs, so the
-- job list became slower over time.
--
-- These two denormalised reference columns are maintained on the write path
-- instead (ExecutionStartEndpoint / ExecutionFinishEndpoint) and turn the
-- aggregate into two primary-key lookups per job:
--
--   last_execution_id          – id of the most recent execution_log row
--   last_finished_execution_id – id of the most recent row with finished_at set
--
-- The UPDATE below backfills both columns once from the existing history.
-- Endpoints that delete execution_log rows re-derive the columns for the
-- affected jobs so the references never dangle.

ALTER TABLE cronjobs
    ADD COLUMN IF NOT EXISTS last_execution_id INT NULL DEFAULT NULL
        COMMENT 'id of the most recent execution_log row for this job; maintained by ExecutionStartEndpoint'
        AFTER last_silence_alert_at,
    ADD COLUMN IF NOT EXISTS last_finished_execution_id INT NULL DEFAULT NULL
        COMMENT 'id of the most recent finished execution_log row; maintained by ExecutionFinishEndpoint'
        AFTER last_execution_id;

UPDATE cronjobs c
LEFT JOIN (
    SELECT
        cronjob_id,
        MAX(id)                                                      AS max_id,
        MAX(CASE WHEN finished_at IS NOT NULL THEN id ELSE NULL END) AS max_finished_id
    FROM execution_log
    GROUP BY cronjob_id
) el ON el.cronjob_id = c.id
SET c.last_execution_id          = el.max_id,
    c.last_finished_execution_id = el.max_finished_id;
