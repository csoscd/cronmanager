-- Migration 023: Free-text notes field on cronjobs
--
-- Adds an optional plain-text notes column so users can attach any free-form
-- remarks to a job (responsible person, context, run conditions, etc.).
-- The field is intentionally TEXT (no length limit) and NULL by default.
-- It is excluded from the list endpoint for payload efficiency and is only
-- returned by the detail endpoint (GET /crons/{id}).

ALTER TABLE cronjobs
    ADD COLUMN notes TEXT NULL DEFAULT NULL
        COMMENT 'Optional free-text notes for this job; plain text, not shown in list responses'
        AFTER description;
