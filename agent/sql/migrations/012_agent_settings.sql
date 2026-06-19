-- 012_agent_settings.sql
--
-- Persistent notification / integration settings per agent.
-- Stores one complete section configuration as a JSON blob per row.
-- Managed sections: mail, telegram, influxdb, notifications.
--
-- An absent row means the section falls back to the values written to
-- config.json by entrypoint.sh (environment-variable defaults). Once a
-- section has been saved via the web UI the DB row becomes authoritative
-- and the config.json values are ignored for that section.

CREATE TABLE IF NOT EXISTS agent_settings (
    section    VARCHAR(50)  NOT NULL,
    config     JSON         NOT NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
