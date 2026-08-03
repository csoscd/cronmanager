-- Migration 017: Performance indexes + schema.sql repair (v4.7.0)
--
-- Part 1 – repair: schema.sql was missing the objects added by migrations
-- 011 (restart_on_exitcodes), 012 (agent_settings) and 016 (silence columns).
-- Fresh installs apply schema.sql and only *seed* schema_migrations without
-- executing the bundled migrations, so installations created after v4.5.0
-- lack these objects. All statements use IF NOT EXISTS (MariaDB) so they are
-- no-ops on correctly migrated installations.
--
-- Part 2 – new secondary indexes for the hot query paths:
--   cronjobs.idx_cj_linux_user        – /crons?user=… and /history?user=… filters
--                                       previously required a full table scan.
--   cronjobs.idx_cj_active_silence    – silence detection (check-limits.php) and
--                                       the /health silent_jobs counter select on
--                                       (active, notify_on_silence).
--   job_targets.idx_jt_target         – /crons?target=… filter; only the composite
--                                       uq_job_target(job_id, target) existed, which
--                                       cannot serve a lookup by target alone.
--   execution_log.idx_el_cj_started   – MonitorEndpoint stats/execution queries
--                                       filter cronjob_id + started_at range and
--                                       sort by started_at; no existing index
--                                       covered that combination.

-- Part 1: repair objects missing from pre-4.7.0 schema.sql fresh installs

ALTER TABLE cronjobs
    ADD COLUMN IF NOT EXISTS restart_on_exitcodes VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Exit-code expression that triggers auto-retry, e.g. "1-5,10,255". NULL = any non-zero.'
        AFTER retry_delay_minutes;

ALTER TABLE cronjobs
    ADD COLUMN IF NOT EXISTS notify_on_silence TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = alert when the job has not started within its expected schedule window'
        AFTER notify_on_recovery,
    ADD COLUMN IF NOT EXISTS silence_grace_minutes INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Per-job override for global silence.grace_minutes; NULL = use global default'
        AFTER notify_on_silence,
    ADD COLUMN IF NOT EXISTS last_silence_alert_at DATETIME NULL DEFAULT NULL
        COMMENT 'Timestamp of last silence alert; reset on job start; dedup max once per hour'
        AFTER silence_grace_minutes;

CREATE TABLE IF NOT EXISTS agent_settings (
    section    VARCHAR(50)  NOT NULL,
    config     JSON         NOT NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Part 2: new performance indexes

ALTER TABLE cronjobs
    ADD INDEX IF NOT EXISTS idx_cj_linux_user     (linux_user),
    ADD INDEX IF NOT EXISTS idx_cj_active_silence (active, notify_on_silence);

ALTER TABLE job_targets
    ADD INDEX IF NOT EXISTS idx_jt_target (target);

ALTER TABLE execution_log
    ADD INDEX IF NOT EXISTS idx_el_cj_started (cronjob_id, started_at);
