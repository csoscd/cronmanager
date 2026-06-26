-- Migration 013: audit_log table
-- Stores an entry for every write operation performed via the web UI or REST API.
-- user_id / username are denormalised from the X-User-Id / X-User-Name headers
-- (HMAC-signed, so they can be trusted).

CREATE TABLE IF NOT EXISTS audit_log (
    id             BIGINT        AUTO_INCREMENT PRIMARY KEY,
    user_id        INT           NOT NULL DEFAULT 0,
    username       VARCHAR(128)  NOT NULL,
    action         VARCHAR(64)   NOT NULL,
    resource_type  VARCHAR(32)   NULL,
    resource_id    INT           NULL,
    resource_label VARCHAR(255)  NULL,
    details        TEXT          NULL,
    ip_address     VARCHAR(45)   NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created_at (created_at),
    INDEX idx_audit_user_id    (user_id),
    INDEX idx_audit_action     (action(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
