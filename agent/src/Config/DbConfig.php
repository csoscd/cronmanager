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

    /**
     * Sensitive fields that are encrypted at rest when AGENT_SETTINGS_KEY is set.
     * All other fields are always stored as plaintext.
     */
    private const SENSITIVE_FIELDS = [
        'mail'     => ['password'],
        'telegram' => ['bot_token'],
        'influxdb' => ['token'],
    ];

    /** Prefix that marks a DB value as AES-256-CBC encrypted. */
    private const ENC_PREFIX = 'enc:';

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
     * cache is always kept as plaintext; sensitive fields are encrypted only in
     * the JSON written to the database (when AGENT_SETTINGS_KEY is set).
     *
     * @param string               $section Section name (e.g. 'mail').
     * @param array<string, mixed> $values  Key/value pairs to store (plaintext).
     */
    public function setSection(string $section, array $values): void
    {
        $toStore = $this->applyEncryption($section, $values);

        $stmt = $this->pdo->prepare(
            'INSERT INTO agent_settings (section, config) VALUES (:section, :config)
             ON DUPLICATE KEY UPDATE config = VALUES(config)'
        );
        $stmt->execute([
            ':section' => $section,
            ':config'  => json_encode($toStore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        // Keep plaintext in memory so subsequent get() calls work without a re-decrypt.
        $this->dbSettings[$section] = $values;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Load all section rows from agent_settings into $this->dbSettings.
     *
     * Sensitive fields are decrypted on load so the rest of the application
     * always works with plaintext values. Silently swallows exceptions: on first
     * boot before the migration has run the table does not exist yet, and the
     * file config is used as fallback.
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
                    $section = (string) $row['section'];
                    $this->dbSettings[$section] = $this->applyDecryption($section, $decoded);
                }
            }
        } catch (\Throwable) {
            // Table absent before migration — fall back to config.json.
        }
    }

    // -------------------------------------------------------------------------
    // Encryption helpers
    // -------------------------------------------------------------------------

    /**
     * Encrypt sensitive fields in a section's value array before storing to DB.
     *
     * Only applies when AGENT_SETTINGS_KEY is set. Fields not listed in
     * SENSITIVE_FIELDS are always passed through unchanged.
     *
     * @param  string               $section
     * @param  array<string, mixed> $values  Plaintext values.
     *
     * @return array<string, mixed> Values with sensitive fields encrypted.
     */
    private function applyEncryption(string $section, array $values): array
    {
        foreach (self::SENSITIVE_FIELDS[$section] ?? [] as $field) {
            if (isset($values[$field])) {
                $values[$field] = $this->encryptField((string) $values[$field]);
            }
        }
        return $values;
    }

    /**
     * Decrypt sensitive fields in a section's value array after reading from DB.
     *
     * Plaintext values (no enc: prefix) are returned unchanged, so existing
     * plaintext rows continue to work without any migration step.
     *
     * @param  string               $section
     * @param  array<string, mixed> $values  Values as stored in DB.
     *
     * @return array<string, mixed> Values with sensitive fields decrypted.
     */
    private function applyDecryption(string $section, array $values): array
    {
        foreach (self::SENSITIVE_FIELDS[$section] ?? [] as $field) {
            if (isset($values[$field])) {
                $values[$field] = $this->decryptField((string) $values[$field]);
            }
        }
        return $values;
    }

    /**
     * Encrypt a single field value with AES-256-CBC.
     *
     * Returns the value unchanged when AGENT_SETTINGS_KEY is not set or the
     * value is empty. The returned string is prefixed with enc: so decryptField()
     * can distinguish encrypted from plaintext values.
     */
    private function encryptField(string $value): string
    {
        $key = (string) getenv('AGENT_SETTINGS_KEY');
        if ($key === '' || $value === '') {
            return $value;
        }

        $iv     = random_bytes(16);
        $cipher = openssl_encrypt(
            $value,
            'AES-256-CBC',
            hash('sha256', $key, true),
            OPENSSL_RAW_DATA,
            $iv,
        );

        return self::ENC_PREFIX . base64_encode($iv . $cipher);
    }

    /**
     * Decrypt a single field value encrypted by encryptField().
     *
     * Returns the value unchanged when it has no enc: prefix (plaintext
     * backward-compatibility). Returns an empty string when the enc: prefix is
     * present but AGENT_SETTINGS_KEY is absent — this prevents garbled values
     * from reaching SMTP/Telegram/InfluxDB clients while avoiding a hard crash.
     */
    private function decryptField(string $value): string
    {
        if (!str_starts_with($value, self::ENC_PREFIX)) {
            return $value;
        }

        $key = (string) getenv('AGENT_SETTINGS_KEY');
        if ($key === '') {
            // Encrypted value encountered but no key configured.
            // Return empty string so the notifier fails cleanly instead of
            // forwarding a garbled ciphertext as a credential.
            return '';
        }

        $raw    = (string) base64_decode(substr($value, strlen(self::ENC_PREFIX)));
        $iv     = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain  = openssl_decrypt(
            $cipher,
            'AES-256-CBC',
            hash('sha256', $key, true),
            OPENSSL_RAW_DATA,
            $iv,
        );

        return ($plain !== false) ? $plain : '';
    }
}
