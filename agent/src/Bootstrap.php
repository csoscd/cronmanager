<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – Application Bootstrap
 *
 * Singleton that initialises and exposes the Noodlehaus\Config instance and
 * the Monolog\Logger for the entire agent process.
 *
 * Config resolution order (first found wins):
 *   1. Local development config: <agent-root>/config/config.json
 *   2. Production config:        /opt/phpscripts/cronmanager/agent/config/config.json
 *
 * Usage:
 *   $bootstrap = Bootstrap::getInstance();
 *   $config    = $bootstrap->getConfig();
 *   $logger    = $bootstrap->getLogger();
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Noodlehaus\Config;
use RuntimeException;

/**
 * Class Bootstrap
 *
 * Central initialisation point for configuration and logging.
 * Implemented as a singleton to avoid redundant file I/O and to ensure that
 * the same logger instance is reused throughout the request lifecycle.
 */
final class Bootstrap
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /**
     * Path to the production configuration file on the host.
     *
     * @var string
     */
    private const CONFIG_PATH_PRODUCTION = '/opt/cronmanager/agent/config/config.json';

    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    /** @var Bootstrap|null Single class instance */
    private static ?Bootstrap $instance = null;

    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    /** @var Config Noodlehaus configuration object */
    private Config $config;

    /** @var Logger Monolog logger */
    private Logger $logger;

    // -------------------------------------------------------------------------
    // Constructor (private – use getInstance())
    // -------------------------------------------------------------------------

    /**
     * Initialise configuration and logging.
     *
     * @throws RuntimeException When no readable configuration file can be found.
     */
    private function __construct()
    {
        $this->loadConfig();
        $this->initLogger();
    }

    // -------------------------------------------------------------------------
    // Singleton access
    // -------------------------------------------------------------------------

    /**
     * Return (or create) the singleton Bootstrap instance.
     *
     * @return self
     * @throws RuntimeException On configuration or logging setup errors.
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
     * Return the Noodlehaus\Config instance.
     *
     * @return Config
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Return the Monolog\Logger instance.
     *
     * @return Logger
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve and load the JSON configuration file.
     *
     * Checks for a local development config first so that developers can work
     * without the production path being present on the machine.
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
     *   1. Local dev config: <agent-src-parent>/config/config.json
     *   2. Production config: /opt/phpscripts/cronmanager/agent/config/config.json
     *
     * @return string Absolute path to the configuration file to use.
     *
     * @throws RuntimeException When no configuration file can be found at either location.
     */
    private function resolveConfigPath(): string
    {
        // __DIR__ is agent/src – go up two levels to reach the agent root,
        // then look for config/config.json
        $localConfig = dirname(__DIR__) . '/config/config.json';

        if (file_exists($localConfig)) {
            return $localConfig;
        }

        if (file_exists(self::CONFIG_PATH_PRODUCTION)) {
            return self::CONFIG_PATH_PRODUCTION;
        }

        throw new RuntimeException(
            sprintf(
                'No configuration file found. Checked: %s and %s',
                $localConfig,
                self::CONFIG_PATH_PRODUCTION
            )
        );
    }

    /**
     * Initialise Monolog with a RotatingFileHandler.
     *
     * Configuration keys used:
     *   logging.path     – absolute path to the log file (string)
     *   logging.level    – PSR-3 level name, case-insensitive (string, default: 'info')
     *   logging.max_days – number of daily log files to retain (int, default: 30)
     */
    private function initLogger(): void
    {
        $logPath = (string)  $this->config->get('logging.path',     '/opt/phpscripts/log/cronmanager-agent.log');
        $logLevel = (string) $this->config->get('logging.level',    'info');
        $maxDays  = (int)    $this->config->get('logging.max_days', 30);

        // Ensure the log directory exists; fall back gracefully if creation fails
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true) && !is_dir($logDir)) {
                error_log(sprintf('[cronmanager] Cannot create log directory: %s', $logDir));
            }
        }

        $handler = new RotatingFileHandler(
            filename: $logPath,
            maxFiles: $maxDays,
            level:    $this->resolveLogLevel($logLevel),
        );

        $this->logger = new Logger('cronmanager-agent');
        $this->logger->pushHandler($handler);
    }

    /**
     * Map a PSR-3 level name string to a Monolog\Level enum case.
     *
     * Unrecognised strings default to Level::Info.
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
    // Prevent cloning / unserialization
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
