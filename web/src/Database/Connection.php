<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Database Connection
 *
 * Singleton PDO wrapper that reads its configuration via Noodlehaus\Config
 * and logs all connection events through Monolog.
 *
 * Config resolution order (first found wins):
 *   1. Production config: /var/www/conf/config.json
 *   2. Local development config: <web-root>/config/config.json
 *
 * Usage:
 *   $pdo = Connection::getInstance()->getPdo();
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Database;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Noodlehaus\Config;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Class Connection
 *
 * Provides a singleton PDO instance configured from the web application's
 * config.json.  All connection lifecycle events are written to a rotating log.
 */
final class Connection
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /**
     * Path to the production configuration file (Docker volume mount).
     *
     * @var string
     */
    private const CONFIG_PATH_PRODUCTION = '/var/www/conf/config.json';

    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    /** @var Connection|null The single class instance */
    private static ?Connection $instance = null;

    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    /** @var PDO Active PDO connection */
    private PDO $pdo;

    /** @var Logger Monolog logger */
    private Logger $logger;

    /** @var Config Noodlehaus configuration */
    private Config $config;

    // -------------------------------------------------------------------------
    // Constructor (private – use getInstance())
    // -------------------------------------------------------------------------

    /**
     * Initialise configuration, logging, and the PDO connection.
     *
     * @throws RuntimeException When the configuration file cannot be read.
     * @throws PDOException     When the database connection cannot be established.
     */
    private function __construct()
    {
        $this->loadConfig();
        $this->initLogger();
        $this->connect();
    }

    // -------------------------------------------------------------------------
    // Singleton access
    // -------------------------------------------------------------------------

    /**
     * Return (or create) the singleton Connection instance.
     *
     * @return self
     * @throws RuntimeException On configuration errors.
     * @throws PDOException     On database connection errors.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the active PDO instance.
     *
     * @return PDO
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve and load the JSON configuration file.
     *
     * @throws RuntimeException When no configuration file can be found or read.
     */
    private function loadConfig(): void
    {
        $configPath = $this->resolveConfigPath();

        if (!is_readable($configPath)) {
            throw new RuntimeException(
                sprintf('Configuration file is not readable: %s', $configPath)
            );
        }

        $this->config = new Config($configPath);
    }

    /**
     * Determine which configuration file to load.
     *
     * Resolution order:
     *   1. Production config: /var/www/conf/config.json
     *   2. Local dev config:  <web-root>/config/config.json
     *
     * @return string Absolute path to the configuration file to use.
     *
     * @throws RuntimeException When no configuration file can be found.
     */
    private function resolveConfigPath(): string
    {
        if (file_exists(self::CONFIG_PATH_PRODUCTION)) {
            return self::CONFIG_PATH_PRODUCTION;
        }

        // __DIR__ is web/src/Database – go up three levels to reach the web root
        $devConfig = dirname(__DIR__, 2) . '/config/config.json';

        if (file_exists($devConfig)) {
            return $devConfig;
        }

        throw new RuntimeException(
            sprintf(
                'No configuration file found. Checked: %s and %s',
                self::CONFIG_PATH_PRODUCTION,
                $devConfig
            )
        );
    }

    /**
     * Set up Monolog with a RotatingFileHandler.
     *
     * Configuration keys used:
     *   logging.path     – absolute path to the log file
     *   logging.level    – PSR-3 level string
     *   logging.max_days – number of daily log files to retain
     */
    private function initLogger(): void
    {
        $logPath  = (string) $this->config->get('logging.path',     '/var/www/log/cronmanager-web.log');
        $logLevel = (string) $this->config->get('logging.level',    'info');
        $maxDays  = (int)    $this->config->get('logging.max_days', 30);

        // Ensure the log directory exists
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true) && !is_dir($logDir)) {
                error_log(sprintf('[cronmanager-web] Cannot create log directory: %s', $logDir));
            }
        }

        $handler = new RotatingFileHandler(
            filename: $logPath,
            maxFiles: $maxDays,
            level:    $this->resolveLogLevel($logLevel),
        );

        $this->logger = new Logger('cronmanager-web');
        $this->logger->pushHandler($handler);
    }

    /**
     * Establish the PDO connection to MariaDB.
     *
     * Configuration keys used:
     *   database.host     – hostname or Docker service name (default: cronmanager-db)
     *   database.port     – TCP port (default: 3306)
     *   database.name     – database / schema name
     *   database.user     – database user
     *   database.password – database password
     *
     * PDO options set:
     *   - Error mode: ERRMODE_EXCEPTION  (throws PDOException on errors)
     *   - Default fetch mode: FETCH_ASSOC
     *   - Emulated prepares: disabled    (use native prepared statements)
     *   - Character set: utf8mb4
     *
     * @throws PDOException On connection failure.
     */
    private function connect(): void
    {
        $host     = (string)  $this->config->get('database.host',     'cronmanager-db');
        $port     = (int)     $this->config->get('database.port',     3306);
        $dbName   = (string)  $this->config->get('database.name',     'cronmanager');
        $user     = (string)  $this->config->get('database.user',     'cronmanager');
        $password = (string)  $this->config->get('database.password', '');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $dbName
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $password, $options);

            $this->logger->info('Database connection established', [
                'host'   => $host,
                'port'   => $port,
                'dbname' => $dbName,
                'user'   => $user,
            ]);
        } catch (PDOException $e) {
            $this->logger->error('Database connection failed', [
                'host'    => $host,
                'port'    => $port,
                'dbname'  => $dbName,
                'user'    => $user,
                'message' => $e->getMessage(),
            ]);

            // Re-throw so the caller can decide how to handle startup failure
            throw $e;
        }
    }

    /**
     * Resolve a PSR-3 level string to a Monolog\Level enum case.
     *
     * Unknown strings default to Level::Info.
     *
     * @param string $level Case-insensitive level name.
     *
     * @return Level
     */
    private function resolveLogLevel(string $level): Level
    {
        return match (strtolower($level)) {
            'debug'     => Level::Debug,
            'notice'    => Level::Notice,
            'warning'   => Level::Warning,
            'error'     => Level::Error,
            'critical'  => Level::Critical,
            'alert'     => Level::Alert,
            'emergency' => Level::Emergency,
            default     => Level::Info,
        };
    }

    // -------------------------------------------------------------------------
    // Prevent cloning / unserialization of the singleton
    // -------------------------------------------------------------------------

    /** @codeCoverageIgnore */
    private function __clone() {}

    /**
     * Prevent unserialization of the singleton.
     *
     * @throws RuntimeException Always.
     */
    public function __wakeup(): never
    {
        throw new RuntimeException('Cannot unserialize a singleton.');
    }
}
