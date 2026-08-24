-- =============================================================================
-- Migration 019: User management v2.0
--
-- Changes:
--   1. Add `active`, `email`, `agent_ids` columns to `users`.
--   2. Expand and rename the `role` ENUM:
--        'view'  → 'viewer'   (read-only, was the old default)
--        'admin' → 'admin'    (unchanged)
--        NEW: 'operator'      (execute + maintenance, no admin)
--        NEW: 'api-only'      (no WebUI login; API keys only)
--   3. Create `auth_tokens` table for invite / password-reset flows.
-- =============================================================================

-- ── 1a. Add new columns ───────────────────────────────────────────────────────
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS active    TINYINT(1)   NOT NULL DEFAULT 1
        COMMENT '0 = deactivated (login blocked); 1 = active' AFTER role,
    ADD COLUMN IF NOT EXISTS email     VARCHAR(255) NULL     DEFAULT NULL
        COMMENT 'Contact / invite address; may differ from username' AFTER active,
    ADD COLUMN IF NOT EXISTS agent_ids JSON         NULL     DEFAULT NULL
        COMMENT 'NULL = all agents; [1,3] = restricted to these agent IDs' AFTER email;

-- ── 1b. Expand ENUM to include both old and new values ───────────────────────
--  Must widen before migrating data so existing rows remain valid.
ALTER TABLE users
    MODIFY COLUMN role ENUM('view','admin','viewer','operator','api-only')
        NOT NULL DEFAULT 'viewer'
        COMMENT 'viewer = read-only, operator = execute+maintenance, admin = full, api-only = no UI login';

-- ── 1c. Migrate existing role data ───────────────────────────────────────────
UPDATE users SET role = 'viewer' WHERE role = 'view';

-- ── 1d. Remove the now-unused 'view' value ────────────────────────────────────
ALTER TABLE users
    MODIFY COLUMN role ENUM('viewer','operator','admin','api-only')
        NOT NULL DEFAULT 'viewer'
        COMMENT 'viewer = read-only, operator = execute+maintenance, admin = full, api-only = no UI login';

-- ── 2. auth_tokens – invite and password-reset tokens ────────────────────────
CREATE TABLE IF NOT EXISTS auth_tokens (
    id         INT          AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    token_hash VARCHAR(64)  NOT NULL UNIQUE
                   COMMENT 'sha256(plain_token) – plain text is never stored',
    type       ENUM('invite','reset') NOT NULL,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL DEFAULT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_auth_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_auth_tokens_user_type (user_id, type),
    INDEX idx_auth_tokens_expires   (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
