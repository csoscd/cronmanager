-- =============================================================================
-- Migration 015: Add performance indexes to execution_log
--
-- execution_log wächst kontinuierlich und hat über 600k Zeilen ohne geeignete
-- Indizes. Folgende Endpoints werden dadurch gebremst:
--
-- /crons        (~213 ms): Zwei Derived-Table-Subqueries laufen als Full Table Scan.
-- /history      (~2 Sekunden, Peaks bis 6 Sekunden): GROUP BY + FileSort ohne Index
--               auf started_at / finished_at.
--
-- Neue Indizes:
--   idx_el_cronjob_cover     – Covering Index für MAX(id) GROUP BY cronjob_id
--                              (Subquery 1 in CronListEndpoint; Index-Only-Scan)
--   idx_el_cj_finished_cover – Covering Index für MAX(id) WHERE finished_at IS NOT NULL
--                              GROUP BY cronjob_id (Subquery 2 in CronListEndpoint)
--   idx_el_started_at        – ORDER BY el.started_at DESC in HistoryEndpoint
--                              und Datumsbereichs-Filter (:from / :to)
--   idx_el_finished_exit     – Status-Filter in HistoryEndpoint
--                              (finished_at IS NULL / IS NOT NULL, exit_code = 0 / != 0)
--   idx_el_target            – Target-Filter in HistoryEndpoint (el.target = :target)
--
-- @author  Christian Schulz <technik@meinetechnikwelt.rocks>
-- @license GNU General Public License version 3 or later
-- =============================================================================

ALTER TABLE execution_log
    ADD INDEX idx_el_cronjob_cover     (cronjob_id, id),
    ADD INDEX idx_el_cj_finished_cover (cronjob_id, finished_at, id),
    ADD INDEX idx_el_started_at        (started_at),
    ADD INDEX idx_el_finished_exit     (finished_at, exit_code),
    ADD INDEX idx_el_target            (target);
