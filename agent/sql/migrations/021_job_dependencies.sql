-- Migration 021: Job Dependencies
--
-- Introduces two dependency types:
--   'requires'     – job keeps its schedule but is skipped (exit -7) if the
--                    predecessor's most recent execution did not finish with one
--                    of the configured exit codes within max_age_minutes.
--   'triggered_by' – job has no schedule; it is triggered automatically when
--                    the predecessor finishes with one of the configured exit
--                    codes.
--
-- Adds trigger_type and predecessor_execution_id to execution_log for
-- full audit traceability.
--
-- Makes cronjobs.schedule nullable so that 'triggered_by' jobs can omit it.

-- Allow 'triggered_by' jobs to have no cron schedule
ALTER TABLE cronjobs
    MODIFY COLUMN schedule VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'Standard cron schedule expression; NULL for dependency-triggered jobs';

-- New dependency relation table
CREATE TABLE IF NOT EXISTS job_dependencies (
    id                INT          AUTO_INCREMENT PRIMARY KEY,
    job_id            INT          NOT NULL
                                   COMMENT 'The dependent job (runs after / depends on predecessor)',
    predecessor_id    INT          NOT NULL
                                   COMMENT 'The job that must run first',
    type              ENUM('requires','triggered_by') NOT NULL
                                   COMMENT 'requires = runtime check with time window; triggered_by = event-driven, no schedule',
    exit_codes        VARCHAR(255) NOT NULL DEFAULT '0'
                                   COMMENT 'Comma-separated list of exit codes that satisfy the dependency, e.g. "0" or "0,2"',
    max_age_minutes   INT UNSIGNED NULL DEFAULT NULL
                                   COMMENT 'Only for type=requires: predecessor must have finished within this many minutes; NULL = 60',
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_jd_job (job_id),
    CONSTRAINT fk_jd_job         FOREIGN KEY (job_id)         REFERENCES cronjobs(id) ON DELETE RESTRICT,
    CONSTRAINT fk_jd_predecessor FOREIGN KEY (predecessor_id) REFERENCES cronjobs(id) ON DELETE RESTRICT,
    INDEX idx_jd_predecessor (predecessor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extend execution_log with trigger provenance
ALTER TABLE execution_log
    ADD COLUMN trigger_type              ENUM('scheduled','manual','dependency') NOT NULL DEFAULT 'scheduled'
        COMMENT 'How this execution was initiated'
        AFTER retry_root_execution_id,
    ADD COLUMN predecessor_execution_id  INT NULL DEFAULT NULL
        COMMENT 'execution_log.id of the predecessor execution that triggered this run; NULL for scheduled/manual'
        AFTER trigger_type;

-- Stores pending dependency-triggered executions (consumed by ExecutionStartEndpoint,
-- mirrors the job_retry_state pattern).
CREATE TABLE IF NOT EXISTS job_dependency_trigger_state (
    job_id                    INT          NOT NULL
                                           COMMENT 'The job that will be triggered',
    target                    VARCHAR(255) NOT NULL DEFAULT 'local'
                                           COMMENT 'Execution target for the triggered run',
    predecessor_execution_id  INT          NULL
                                           COMMENT 'execution_log.id of the predecessor that caused this trigger',
    created_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (job_id, target),
    CONSTRAINT fk_jdts_job FOREIGN KEY (job_id) REFERENCES cronjobs(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
