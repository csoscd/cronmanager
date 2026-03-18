-- =============================================================================
-- Migration 003 – Add target column to execution_log
--
-- Records which execution target (local or SSH host alias) was used for each
-- run, enabling per-target history display in the web UI.
--
-- Safe to re-run: uses IF NOT EXISTS / IGNORE patterns.
-- =============================================================================

ALTER TABLE execution_log
    ADD COLUMN IF NOT EXISTS target VARCHAR(255) NULL
        COMMENT '"local" or SSH host alias; NULL for rows created before this migration'
        AFTER output;
