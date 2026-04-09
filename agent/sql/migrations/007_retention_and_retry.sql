-- =============================================================================
-- Migration 007 – Retention policy and auto-retry support
--
-- Adds per-job log retention (retention_days) and auto-retry on failure
-- (retry_count, retry_delay_minutes) to the cronjobs table.
-- Adds retry tracking columns (retry_attempt, retry_root_execution_id) to
-- execution_log, and creates the transient job_retry_state table used to
-- carry retry metadata from the finish endpoint to the next start endpoint.
--
-- @author  Christian Schulz <technik@meinetechnikwelt.rocks>
-- @license GNU General Public License version 3 or later
-- =============================================================================

-- -----------------------------------------------------------------------------
-- cronjobs: retention and retry columns
-- -----------------------------------------------------------------------------

ALTER TABLE cronjobs
    ADD COLUMN retention_days        SMALLINT UNSIGNED NULL         COMMENT 'Keep execution logs for this many days; NULL = keep forever',
    ADD COLUMN retry_count           TINYINT UNSIGNED  NOT NULL DEFAULT 0
                                                                    COMMENT 'Max number of automatic retry attempts on failure; 0 = no retry',
    ADD COLUMN retry_delay_minutes   SMALLINT UNSIGNED NOT NULL DEFAULT 1
                                                                    COMMENT 'Minutes to wait between retry attempts (minimum 1)';

-- -----------------------------------------------------------------------------
-- execution_log: retry tracking columns
-- -----------------------------------------------------------------------------

ALTER TABLE execution_log
    ADD COLUMN retry_attempt           TINYINT UNSIGNED NOT NULL DEFAULT 0
                                                                    COMMENT '0 = original execution, 1 = first retry, etc.',
    ADD COLUMN retry_root_execution_id INT NULL                     COMMENT 'Links all retries back to the first (attempt 0) execution; NULL for originals';

-- -----------------------------------------------------------------------------
-- job_retry_state – transient table for pending retries
--
-- A row is written by ExecutionFinishEndpoint when a retry is scheduled and
-- deleted by ExecutionStartEndpoint when the retry actually starts.
-- Stale rows (missed fires) are pruned by prune-logs.php (nightly) and by
-- the manual /maintenance/logs/prune endpoint.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS job_retry_state (
    job_id              INT          NOT NULL COMMENT 'References the cron job',
    target              VARCHAR(255) NOT NULL COMMENT '"local" or SSH host alias',
    next_retry_attempt  TINYINT UNSIGNED NOT NULL DEFAULT 1
                                          COMMENT 'retry_attempt value to set on the next execution_log row',
    root_execution_id   INT          NOT NULL COMMENT 'execution_log.id of the original (attempt 0) execution',
    retry_delay_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 1
                                          COMMENT 'Copy of job retry_delay_minutes at scheduling time, used for stale-cleanup threshold',
    scheduled_at        DATETIME     NOT NULL COMMENT 'When the retry crontab entry was written; used to detect stale rows',
    PRIMARY KEY (job_id, target),
    CONSTRAINT fk_jrs_job FOREIGN KEY (job_id)
        REFERENCES cronjobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
