-- =============================================================================
-- Migration 014 – Performance Log
--
-- Creates the performance_log table used by the Performance Monitor feature.
-- Stores per-request timing data (total request duration + aggregated DB time).
-- Populated only when "Performance-Daten persistieren" is enabled in settings.
--
-- @author  Christian Schulz <technik@meinetechnikwelt.rocks>
-- @license GNU General Public License version 3 or later
-- =============================================================================

CREATE TABLE IF NOT EXISTS performance_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    endpoint    VARCHAR(255)  NOT NULL                         COMMENT 'Agent endpoint path (e.g. /crons)',
    request_ms  DECIMAL(10,3) NOT NULL                        COMMENT 'Total request duration in milliseconds',
    db_ms       DECIMAL(10,3) NOT NULL DEFAULT 0              COMMENT 'Accumulated DB query time in milliseconds',
    db_queries  INT UNSIGNED  NOT NULL DEFAULT 0              COMMENT 'Number of timed DB queries in this request',
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_perf_created_at (created_at),
    INDEX idx_perf_endpoint   (endpoint(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
