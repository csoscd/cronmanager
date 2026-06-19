<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – DbConfig
 *
 * Wraps a Noodlehaus\Config (loaded from config.json) and overlays
 * notification / integration settings stored in the agent_settings MariaDB
 * table. This allows persistent configuration that survives Docker container
 * restarts even though entrypoint.sh regenerates config.json from environment
 * variables on every start.
 *
 * Resolution order for get():
 *   1. Key prefix matches a DB-managed section AND a row exists in DB → DB value.
 *   2. Otherwise → delegate to the wrapped Noodlehaus\Config (config.json).
 *
 * DB-managed sections: mail, telegram, influxdb, notifications.
 * Infrastructure keys (agent.*, database.*, logging.*, cron.*) are always
 * read from config.json because they are needed before the DB is available.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Config;

use Noodlehaus\Config;
use Noodlehaus\ConfigInterface;
use PDO;

/**
 * Class DbConfig
 *
 * ConfigInterface implementation that overlays a DB-backed configuration layer
 * on top of a file-based Noodlehaus\Config instance.
 */
final class DbConfig implements ConfigInterface
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** Sections whose values are stored in and read from the database. */
    private const DB_SECTIONS = ['mail', 'telegram', 'influxdb', 'notifications'];

    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    /** @var array<string, array<string, mixed>> Section-keyed settings loaded from DB. */
    private array $dbSettings = [];

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param Config $base File-based config (config.json via Noodlehaus).
     * @param PDO    $pdo  Active database connection for settings persistence.
     */
    public function __construct(
        private readonly Config $base,
        private readonly PDO    $pdo,
    ) {
        $this->loadFromDb();
    }

    // -------------------------------------------------------------------------
    // ConfigInterface implementation
    // -------------------------------------------------------------------------

    /**
     * Get a configuration value by dot-notation key.
     *
     * If the top-level key (before the first dot) is a DB-managed section and
     * a row exists in agent_settings for that section, the DB value is returned.
     * For absent sub-keys within an existing section, $default is returned.
     * All other keys fall through to the wrapped Noodlehaus\Config.
     *
     * @param  string $key     Dot-notation key (e.g. 'mail.host', 'agent.port').
     * @param  mixed  $default Returned when the key is not found.
     *
     * @return mixed
     */
    public function get($key, $default = null): mixed
    {
        $key = (string) $key;
        $dot = strpos($key, '.');

        if ($dot !== false) {
            $section = substr($key, 0, $dot);
            $subkey  = substr($key, $dot + 1);

            if (
                in_array($section, self::DB_SECTIONS, true)
                && array_key_exists($section, $this->dbSettings)
            ) {
                return $this->dbSettings[$section][$subkey] ?? $default;
            }
        }

        return $this->base->get($key, $default);
    }

    /**
     * Set a configuration value (delegates to the wrapped file config).
     *
     * Note: changes made via set() are not persisted to the database.
     * Use setSection() to persist values.
     *
     * @param string $key
     * @param mixed  $value
     */
    public function set($key, $value): void
    {
        $this->base->set($key, $value);
    }

    /**
     * Check whether a key exists.
     *
     * Checks the DB layer first (for DB-managed sections), then the file config.
     *
     * @param  string $key Dot-notation key.
     *
     * @return bool
     */
    public function has($key): bool
    {
        $key = (string) $key;
        $dot = strpos($key, '.');

        if ($dot !== false) {
            $section = substr($key, 0, $dot);
            $subkey  = substr($key, $dot + 1);

            if (
                in_array($section, self::DB_SECTIONS, true)
                && array_key_exists($section, $this->dbSettings)
            ) {
                return array_key_exists($subkey, $this->dbSettings[$section]);
            }
        }

        return $this->base->has($key);
    }

    /**
     * Return all configuration data.
     *
     * Merges the file config with the DB-managed sections (DB values win).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $data = $this->base->all();
        foreach ($this->dbSettings as $section => $values) {
            $data[$section] = $values;
        }
        return $data;
    }

    // -------------------------------------------------------------------------
    // Additional methods for SettingsEndpoint
    // -------------------------------------------------------------------------

    /**
     * Return the effective configuration for a single section.
     *
     * If a DB row exists for the section, returns those values.
     * Otherwise falls back to the corresponding top-level key in config.json.
     *
     * @param  string $section Section name (e.g. 'mail').
     *
     * @return array<string, mixed>
     */
    public function getSection(string $section): array
    {
        if (array_key_exists($section, $this->dbSettings)) {
            return $this->dbSettings[$section];
        }

        $all = $this->base->all();
        return is_array($all[$section] ?? null) ? $all[$section] : [];
    }

    /**
     * Return effective configuration for all DB-managed sections.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllSections(): array
    {
        $result = [];
        foreach (self::DB_SECTIONS as $section) {
            $result[$section] = $this->getSection($section);
        }
        return $result;
    }

    /**
     * Persist a section's configuration to the database.
     *
     * Creates a new row or overwrites the existing one (UPSERT). The in-memory
     * cache is updated immediately so subsequent get() calls reflect the change
     * without a DB round-trip.
     *
     * @param string               $section Section name (e.g. 'mail').
     * @param array<string, mixed> $values  Key/value pairs to store.
     */
    public function setSection(string $section, array $values): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO agent_settings (section, config) VALUES (:section, :config)
             ON DUPLICATE KEY UPDATE config = VALUES(config)'
        );
        $stmt->execute([
            ':section' => $section,
            ':config'  => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $this->dbSettings[$section] = $values;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Load all section rows from agent_settings into $this->dbSettings.
     *
     * Silently swallows exceptions: on first boot before the migration has run
     * the table does not exist yet, and the file config is used as fallback.
     */
    private function loadFromDb(): void
    {
        try {
            $stmt = $this->pdo->query('SELECT section, config FROM agent_settings');
            if ($stmt === false) {
                return;
            }
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $decoded = json_decode((string) ($row['config'] ?? ''), true);
                if (is_array($decoded)) {
                    $this->dbSettings[(string) $row['section']] = $decoded;
                }
            }
        } catch (\Throwable) {
            // Table absent before migration — fall back to config.json.
        }
    }
}
