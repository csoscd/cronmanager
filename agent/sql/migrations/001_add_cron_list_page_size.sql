-- =============================================================================
-- Migration 001 – Add cron_list_page_size preference to users table
--
-- Run once on an existing installation:
--   docker exec -i cronmanager-db mariadb -u cronmanager -pcronmanagerpassword cronmanager \
--       < migrations/001_add_cron_list_page_size.sql
--
-- @author  Christian Schulz <technik@meinetechnikwelt.rocks>
-- @license GNU General Public License version 3 or later
-- =============================================================================

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS cron_list_page_size SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT '0 = show all, NULL = use default (25), other values = page size';
