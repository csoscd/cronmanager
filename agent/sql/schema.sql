-- =============================================================================
-- Cronmanager – Database Schema
--
-- Creates all tables required by the Cronmanager application.
-- Safe to run multiple times (CREATE TABLE IF NOT EXISTS).
--
-- @author  Christian Schulz <technik@meinetechnikwelt.rocks>
-- @license GNU General Public License version 3 or later
-- =============================================================================

-- Use the cronmanager database (created by MariaDB env vars in docker-compose)
-- Run this script after the container has started:
--   docker exec -i cronmanager-db mariadb -u cronmanager -pcronmanagerpassword cronmanager < schema.sql

-- -----------------------------------------------------------------------------
-- users – application users (local auth or OIDC)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT          AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(128) UNIQUE NOT NULL       COMMENT 'Login name or OIDC subject',
    password_hash VARCHAR(255)                       COMMENT 'bcrypt hash; NULL for OIDC-only accounts',
    role          ENUM('view','admin') NOT NULL DEFAULT 'view'
                                                    COMMENT 'view = read-only, admin = full access',
    oauth_sub            VARCHAR(255)                       COMMENT 'OIDC subject identifier',
    created_at           DATETIME     DEFAULT CURRENT_TIMESTAMP,
    cron_list_page_size  SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT '0 = show all, NULL = use default (25), other values = page size'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- cronjobs – managed cron entries
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cronjobs (
    id                INT          AUTO_INCREMENT PRIMARY KEY,
    linux_user        VARCHAR(64)  NOT NULL           COMMENT 'OS user the cron job runs as',
    schedule          VARCHAR(100) NOT NULL           COMMENT 'Standard cron schedule expression',
    command           TEXT         NOT NULL           COMMENT 'Full command string to execute',
    description       VARCHAR(255)                   COMMENT 'Human-readable job description',
    active            TINYINT(1)   DEFAULT 1          COMMENT '1 = enabled, 0 = disabled',
    notify_on_failure        TINYINT(1)   DEFAULT 1          COMMENT '1 = send alert mail on non-zero exit',
    execution_limit_seconds  INT UNSIGNED NULL              COMMENT 'Maximum allowed runtime in seconds; NULL = no limit',
    auto_kill_on_limit       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = auto-kill when execution_limit_seconds is exceeded',
    singleton                TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = skip new execution if a previous instance is still running',
    run_in_maintenance       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = execute during maintenance window (failures suppressed); 0 = skip (exit -4)',
    execution_mode    ENUM('local','remote') NOT NULL DEFAULT 'local'
                                               COMMENT 'local = run on this host, remote = execute via SSH',
    ssh_host          VARCHAR(255)            NULL     COMMENT 'SSH config host alias (from ~/.ssh/config); required when execution_mode=remote',
    created_at        DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- tags – labels for grouping / filtering cron jobs
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tags (
    id   INT         AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) UNIQUE NOT NULL COMMENT 'Tag label (e.g. "backup", "maintenance")'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- cronjob_tags – many-to-many relation between cronjobs and tags
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cronjob_tags (
    cronjob_id INT NOT NULL,
    tag_id     INT NOT NULL,
    PRIMARY KEY (cronjob_id, tag_id),
    CONSTRAINT fk_ct_cronjob FOREIGN KEY (cronjob_id)
        REFERENCES cronjobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_ct_tag     FOREIGN KEY (tag_id)
        REFERENCES tags(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- job_targets – execution targets for each cron job (local or SSH host alias)
--
-- A job may have one or more targets. Each target produces one crontab entry.
-- All entries for the same job run in parallel (separate cron lines, same schedule).
--
-- Special value "local" means: execute directly on the cron host.
-- Any other value is treated as an SSH config alias from ~/.ssh/config.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_targets (
    id     INT          AUTO_INCREMENT PRIMARY KEY,
    job_id INT          NOT NULL             COMMENT 'References the owning cron job',
    target VARCHAR(255) NOT NULL             COMMENT '"local" or SSH host alias from ~/.ssh/config',
    UNIQUE  KEY uq_job_target (job_id, target),
    CONSTRAINT fk_jt_job FOREIGN KEY (job_id)
        REFERENCES cronjobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- execution_log – runtime history for each cron job
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS execution_log (
    id          INT      AUTO_INCREMENT PRIMARY KEY,
    cronjob_id  INT      NOT NULL                   COMMENT 'References the job that was executed',
    started_at  DATETIME NOT NULL                   COMMENT 'Timestamp when the job started',
    finished_at DATETIME                            COMMENT 'Timestamp when the job finished (NULL if still running)',
    exit_code                INT                     COMMENT 'Process exit code (0 = success)',
    output                   TEXT                    COMMENT 'Combined stdout/stderr output',
    target                   VARCHAR(255)            COMMENT '"local" or SSH host alias from ~/.ssh/config',
    pid                      INT UNSIGNED NULL       COMMENT 'OS PID of the running job process; NULL when not tracked',
    pid_file                 VARCHAR(255) NULL       COMMENT 'Path to PID file on remote host (SSH targets); NULL for local jobs',
    notified_limit_exceeded  TINYINT(1) NOT NULL DEFAULT 0
                                                    COMMENT '1 = limit-exceeded notification already sent; prevents duplicate alerts',
    during_maintenance       TINYINT(1) NOT NULL DEFAULT 0
                                                    COMMENT '1 = this execution occurred during a maintenance window',
    CONSTRAINT fk_el_cronjob FOREIGN KEY (cronjob_id)
        REFERENCES cronjobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- maintenance_windows – scheduled maintenance periods per target
--
-- When the current time falls within an active maintenance window for a target
-- the agent will either skip the job (exit_code = -4) or execute it silently
-- depending on the per-job run_in_maintenance flag.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS maintenance_windows (
    id               INT          AUTO_INCREMENT PRIMARY KEY,
    target           VARCHAR(255) NOT NULL
                                  COMMENT '"local" or SSH host alias from ~/.ssh/config',
    cron_schedule    VARCHAR(100) NOT NULL
                                  COMMENT 'Standard 5-field cron expression for window start',
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60
                                  COMMENT 'Length of the maintenance window in minutes',
    description      VARCHAR(255)
                                  COMMENT 'Human-readable description of this window',
    active           TINYINT(1)   NOT NULL DEFAULT 1
                                  COMMENT '1 = window is evaluated, 0 = disabled',
    created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- schema_migrations – tracks which incremental migration files have been applied
--
-- Populated automatically by the Docker entrypoint and simple_debian_setup.sh.
-- Fresh installs seed this table with all bundled migration filenames so that
-- already-applied changes are never re-run on first-boot.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
    filename   VARCHAR(255) NOT NULL                    COMMENT 'Base filename of the migration SQL file (e.g. 004_kill_and_limits.sql)',
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                        COMMENT 'Timestamp when this migration was applied',
    PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
