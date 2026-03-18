-- =============================================================================
-- Migration 002 – Add job_targets table
--
-- Introduces multi-target execution: each cron job can now be executed on
-- multiple targets (local and/or SSH hosts) in parallel.  One crontab entry
-- is written per target, all sharing the same schedule.
--
-- Steps:
--   1. Create the new job_targets table.
--   2. Migrate existing execution_mode / ssh_host data into job_targets.
--      (The old columns are intentionally kept for backward compatibility with
--       existing crontab wrapper invocations that carry no target argument.)
--
-- Run:
--   docker exec -i cronmanager-db mariadb -u cronmanager -pcronmanagerpassword cronmanager \
--       < /opt/phpscripts/cronmanager/agent/sql/migrations/002_add_job_targets.sql
--
-- @author  Christian Schulz <technik@meinetechnikwelt.rocks>
-- @license GNU General Public License version 3 or later
-- =============================================================================

-- 1. Create job_targets (idempotent)
CREATE TABLE IF NOT EXISTS job_targets (
    id     INT          AUTO_INCREMENT PRIMARY KEY,
    job_id INT          NOT NULL             COMMENT 'References the owning cron job',
    target VARCHAR(255) NOT NULL             COMMENT '"local" or SSH host alias from ~/.ssh/config',
    UNIQUE  KEY uq_job_target (job_id, target),
    CONSTRAINT fk_jt_job FOREIGN KEY (job_id)
        REFERENCES cronjobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Migrate existing jobs: one row per job, deriving target from old columns.
--    "remote" jobs with a non-empty ssh_host become that host alias;
--    everything else becomes "local".
INSERT IGNORE INTO job_targets (job_id, target)
SELECT id,
       CASE
           WHEN execution_mode = 'remote' AND ssh_host IS NOT NULL AND ssh_host <> ''
               THEN ssh_host
           ELSE 'local'
       END
FROM cronjobs;
